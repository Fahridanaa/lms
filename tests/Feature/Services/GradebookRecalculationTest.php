<?php

namespace Tests\Feature\Services;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use App\Services\GradebookRecalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GradebookRecalculationTest extends TestCase
{
    use DatabaseTransactions;

    private GradebookRecalculationService $recalcService;

    private User $instructor;

    private User $student;

    private Course $course;

    private Assignment $assignment;

    private Quiz $quiz;

    private GradeItem $gradeItem;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->recalcService = app(GradebookRecalculationService::class);

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->student = User::factory()->create(['role' => 'student']);

        $this->course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);

        CourseEnrollment::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'max_score' => 100,
        ]);

        $this->quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);

        // Create questions for the quiz (required for quiz attempts).
        // QuestionFactory automatically creates QuizQuestionSlot via afterCreating.
        \App\Models\Question::factory()->count(3)->create([
            'quiz_id' => $this->quiz->id,
            'points' => 1,
        ]);

        $this->gradeItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'item_id' => $this->quiz->id,
            'max_score' => 100,
            'locked' => false,
        ]);
    }

    /* ──────────────────────────────────────────────
     * Service-level tests
     * ────────────────────────────────────────────── */

    #[Test]
    public function mark_course_stale_creates_recalculation_record(): void
    {
        $this->recalcService->markCourseStale(
            $this->course->id, 'test_reason', 'test_source', 1
        );

        $this->assertTrue($this->recalcService->isCourseStale($this->course->id));
    }

    #[Test]
    public function is_course_stale_returns_false_when_no_recalculation_record(): void
    {
        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));
    }

    #[Test]
    public function mark_recalculated_clears_stale_state(): void
    {
        $this->recalcService->markCourseStale(
            $this->course->id, 'test_reason', 'test_source', 1
        );
        $this->assertTrue($this->recalcService->isCourseStale($this->course->id));

        $this->recalcService->markRecalculated($this->course->id);

        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));
    }

    #[Test]
    public function get_recalculation_state_returns_correct_data(): void
    {
        $this->recalcService->markCourseStale(
            $this->course->id, 'assignment_grading', 'submission', 42
        );

        $state = $this->recalcService->getRecalculationState($this->course->id);

        $this->assertNotNull($state);
        $this->assertTrue($state['stale']);
        $this->assertEquals('assignment_grading', $state['reason']);
        $this->assertEquals('submission', $state['source_type']);
        $this->assertEquals(42, $state['source_id']);
        $this->assertNotNull($state['marked_at']);
        $this->assertNull($state['recalculated_at']);
    }

    #[Test]
    public function get_recalculation_state_returns_null_when_no_record(): void
    {
        $this->assertNull(
            $this->recalcService->getRecalculationState($this->course->id)
        );
    }

    #[Test]
    public function repeated_mark_stale_updates_existing_record(): void
    {
        $this->recalcService->markCourseStale(
            $this->course->id, 'first_reason', 'source_a', 1
        );

        $this->recalcService->markCourseStale(
            $this->course->id, 'second_reason', 'source_b', 2
        );

        $state = $this->recalcService->getRecalculationState($this->course->id);
        $this->assertEquals('second_reason', $state['reason']);
        $this->assertEquals('source_b', $state['source_type']);
        $this->assertEquals(2, $state['source_id']);
    }

    #[Test]
    public function get_stale_course_ids_returns_only_stale_courses(): void
    {
        $course2 = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);

        $this->recalcService->markCourseStale($this->course->id, 'reason_a', 'source', 1);
        $this->recalcService->markCourseStale($course2->id, 'reason_b', 'source', 2);
        $this->recalcService->markRecalculated($course2->id);

        $staleIds = $this->recalcService->getStaleCourseIds();

        $this->assertContains($this->course->id, $staleIds);
        $this->assertNotContains($course2->id, $staleIds);
    }

    /* ──────────────────────────────────────────────
     * Integration: Grade-affecting writes mark stale
     * ────────────────────────────────────────────── */

    #[Test]
    public function quiz_submission_marks_course_gradebook_stale(): void
    {
        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));

        // Create a quiz attempt and submit it
        $attempt = QuizAttempt::factory()->inProgress()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->student->id,
            'started_at' => now()->subMinutes(10),
        ]);

        $answers = [];
        foreach ($this->quiz->fresh()->questions as $question) {
            $answers[$question->id] = 'A';
        }

        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attempt->id}", [
                'answers' => $answers,
            ]);

        $this->assertTrue($this->recalcService->isCourseStale($this->course->id));
    }

    #[Test]
    public function assignment_grading_marks_course_gradebook_stale(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->student->id,
            'status' => 'submitted',
        ]);

        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));

        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/grade", [
                'score' => 85,
            ]);

        $this->assertTrue($this->recalcService->isCourseStale($this->course->id));
    }

    #[Test]
    public function marker_grading_marks_course_gradebook_stale(): void
    {
        // Enable marker allocation for the assignment
        $this->assignment->update([
            'marking_allocation_enabled' => true,
            'marker_count' => 1,
            'multi_mark_method' => 'average',
        ]);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->student->id,
            'status' => 'submitted',
        ]);

        // Create an allocated marker for the submission
        $marker = User::factory()->create(['role' => 'instructor']);
        \App\Models\AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->student->id,
            'marker_id' => $marker->id,
        ]);

        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));

        $this->withHeader('X-Benchmark-Actor-Id', $marker->id)
            ->putJson("/api/submissions/{$submission->id}/marker-grade", [
                'score' => 90,
            ]);

        $this->assertTrue($this->recalcService->isCourseStale($this->course->id));
    }

    #[Test]
    public function direct_grade_update_marks_course_gradebook_stale(): void
    {
        $grade = Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $this->gradeItem->id,
            'score' => 50,
            'max_score' => 100,
            'percentage' => 50,
            'status' => 'final',
            'source' => 'quiz',
        ]);

        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));

        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/grades/{$grade->id}", [
                'score' => 75,
                'max_score' => 100,
            ]);

        $this->assertTrue($this->recalcService->isCourseStale($this->course->id));
    }

    #[Test]
    public function locked_grade_item_blocks_update_and_does_not_mark_stale(): void
    {
        $lockedGradeItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'item_id' => $this->quiz->id + 100,
            'max_score' => 100,
            'locked' => true,
        ]);

        $grade = Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $lockedGradeItem->id,
            'score' => 50,
            'max_score' => 100,
            'percentage' => 50,
            'status' => 'final',
        ]);

        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));

        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/grades/{$grade->id}", [
                'score' => 75,
                'max_score' => 100,
            ]);

        // Grade update should fail (locked grade item) and NOT mark stale
        $this->assertFalse($this->recalcService->isCourseStale($this->course->id));
    }

    /* ──────────────────────────────────────────────
     * Integration: Gradebook read clears stale
     * ────────────────────────────────────────────── */

    #[Test]
    public function instructor_gradebook_read_clears_stale_marker(): void
    {
        // First, mark the gradebook stale via quiz submission
        $attempt = QuizAttempt::factory()->inProgress()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->student->id,
            'started_at' => now()->subMinutes(10),
        ]);

        $answers = [];
        foreach ($this->quiz->fresh()->questions as $question) {
            $answers[$question->id] = 'A';
        }

        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attempt->id}", [
                'answers' => $answers,
            ]);

        $this->assertTrue($this->recalcService->isCourseStale($this->course->id));

        // Instructor reads gradebook — should clear stale marker
        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/gradebook");

        $this->assertFalse($this->recalcService->isCourseStale($this->course->id),
            'Gradebook should no longer be stale after instructor read'
        );
    }
}
