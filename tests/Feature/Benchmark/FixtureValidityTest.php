<?php

namespace Tests\Feature\Benchmark;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\Quiz;
use App\Models\QuizAttemptStepData;
use App\Models\User;
use App\Services\CourseAccessService;
use App\Services\ModuleAvailabilityService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Benchmark\Concerns\BenchmarkFixtureSetup;
use Tests\TestCase;

class FixtureValidityTest extends TestCase
{
    use BenchmarkFixtureSetup;

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBenchmarkFixtures(migrateFresh: true);
        $this->fixtures = $this->parseFixtures($this->generatedFixturePath);
    }

    protected function tearDown(): void
    {
        $this->tearDownBenchmarkFixtures();
        $this->fixtures = [];
        parent::tearDown();
    }

    private function parseFixtures(string $path): array
    {
        return $this->parseFixturePools(file_get_contents($path));
    }

    #[Test]
    public function enrolled_and_instructor_relationships_are_valid(): void
    {
        $pairs = $this->fixtures['ENROLLED_PAIRS'] ?? [];
        $this->assertNotEmpty($pairs);

        foreach ($pairs as $pair) {
            $this->assertNotNull(
                CourseEnrollment::where('user_id', $pair['studentId'])
                    ->where('course_id', $pair['courseId'])
                    ->where('role', 'student')
                    ->where('status', 'active')
                    ->first()
            );
        }

        $instructorPairs = $this->fixtures['INSTRUCTOR_COURSE_PAIRS'] ?? [];
        $this->assertNotEmpty($instructorPairs);

        foreach ($instructorPairs as $pair) {
            $course = Course::find($pair['courseId']);
            $this->assertNotNull($course);
            $isOwner = $course->instructor_id === $pair['instructorId'];
            $isEnrolled = CourseEnrollment::where('user_id', $pair['instructorId'])
                ->where('course_id', $pair['courseId'])
                ->where('role', 'instructor')
                ->where('status', 'active')
                ->exists();
            $this->assertTrue($isOwner || $isEnrolled);
        }
    }

    #[Test]
    public function controlled_failure_and_access_targets_are_valid(): void
    {
        // Group restricted
        $targets = $this->fixtures['GROUP_RESTRICTED_MODULE_TARGETS'] ?? [];
        foreach ($targets as $target) {
            $this->assertNotNull(User::find($target['userId']));
            $course = Course::find($target['courseId']);
            $this->assertNotNull($course);
            $this->assertTrue($course->is_active);
            $this->assertNotNull(LearningModule::find($target['moduleId']));
            $this->assertEquals(404, $target['expectedStatus']);
        }

        // Prerequisite locked
        $targets = $this->fixtures['PREREQUISITE_LOCKED_TARGETS'] ?? [];
        foreach ($targets as $target) {
            $this->assertNotNull(User::find($target['userId']));
            $module = LearningModule::find($target['moduleId']);
            $this->assertNotNull($module);
            $this->assertTrue($module->course->is_active);
            $this->assertEquals(404, $target['expectedStatus']);
        }

        // Min grade locked
        $targets = $this->fixtures['MIN_GRADE_LOCKED_TARGETS'] ?? [];
        foreach ($targets as $target) {
            $this->assertNotNull(User::find($target['userId']));
            $module = LearningModule::find($target['moduleId']);
            $this->assertNotNull($module);
            $this->assertTrue($module->course->is_active);
            $this->assertEquals(404, $target['expectedStatus']);
        }

        // Locked grade targets
        $targets = $this->fixtures['LOCKED_GRADE_TARGETS'] ?? [];
        foreach ($targets as $target) {
            $item = GradeItem::find($target['gradeItemId']);
            $this->assertNotNull($item);
            $this->assertTrue($item->locked);
            $this->assertEquals(403, $target['expectedStatus']);
        }

        // Suspended access
        $targets = $this->fixtures['SUSPENDED_ACCESS_TARGETS'] ?? [];
        foreach ($targets as $target) {
            $enrollment = CourseEnrollment::where('user_id', $target['userId'])
                ->where('course_id', $target['courseId'])->first();
            $this->assertNotNull($enrollment);
            $this->assertEquals('suspended', $enrollment->status);
            $this->assertEquals(403, $target['expectedStatus']);
        }

        // Non-enrolled access
        $targets = $this->fixtures['NON_ENROLLED_ACCESS_TARGETS'] ?? [];
        foreach ($targets as $target) {
            $this->assertNull(
                CourseEnrollment::where('user_id', $target['userId'])
                    ->where('course_id', $target['courseId'])
                    ->where('role', 'student')->first()
            );
            $this->assertEquals(403, $target['expectedStatus']);
        }
    }

    #[Test]
    public function readable_targets_are_valid(): void
    {
        $courseAccessService = app(CourseAccessService::class);
        $availabilityService = app(ModuleAvailabilityService::class);

        $validateReadable = function (array $targets, string $activityType, string $label, callable $findActivity) use ($courseAccessService, $availabilityService): void {
            $this->assertNotEmpty($targets, "{$label} targets must be non-empty");

            foreach ($targets as $target) {
                $student = User::find($target['studentId']);
                $this->assertNotNull($student, "{$label}: student {$target['studentId']} not found");

                $activity = $findActivity($target);
                $this->assertNotNull($activity, "{$label}: activity {$target['activityId']} not found");
                $this->assertTrue($activity->is_active, "{$label}: activity {$target['activityId']} is not active");

                $module = LearningModule::where('module_type', $activityType)
                    ->where('module_id', $target['activityId'])
                    ->first();
                $this->assertNotNull($module, "{$label}: module not found for {$activityType} {$target['activityId']}");
                $this->assertTrue($module->visible, "{$label}: module {$target['activityId']} is not visible");

                $this->assertTrue(
                    $courseAccessService->isActiveEnrollee($student, $module->course),
                    "{$label}: student {$target['studentId']} not actively enrolled in course {$target['courseId']}"
                );

                $availability = $availabilityService->availabilityFor($student, $module);
                $this->assertTrue(
                    $availability['available'],
                    "{$label}: {$activityType} {$target['activityId']} not available for student {$target['studentId']}: {$availability['reason']}"
                );
            }
        };

        $validateReadable(
            $this->fixtures['READABLE_MATERIAL_TARGETS'] ?? [],
            'material', 'READABLE_MATERIAL_TARGETS',
            fn ($t) => Material::find($t['activityId'])
        );

        $validateReadable(
            $this->fixtures['READABLE_QUIZ_TARGETS'] ?? [],
            'quiz', 'READABLE_QUIZ_TARGETS',
            fn ($t) => Quiz::find($t['activityId'])
        );

        $validateReadable(
            $this->fixtures['READABLE_ASSIGNMENT_TARGETS'] ?? [],
            'assignment', 'READABLE_ASSIGNMENT_TARGETS',
            fn ($t) => Assignment::find($t['activityId'])
        );
    }

    #[Test]
    public function writable_targets_are_valid(): void
    {
        $courseAccessService = app(CourseAccessService::class);
        $availabilityService = app(ModuleAvailabilityService::class);

        // Material download targets
        $targets = $this->fixtures['WRITABLE_MATERIAL_DOWNLOAD_TARGETS'] ?? [];
        $this->assertNotEmpty($targets, 'WRITABLE_MATERIAL_DOWNLOAD_TARGETS must be non-empty');

        foreach ($targets as $target) {
            $student = User::find($target['studentId']);
            $this->assertNotNull($student);

            $material = Material::find($target['activityId']);
            $this->assertNotNull($material);
            $this->assertTrue($material->is_active);

            $module = LearningModule::where('module_type', 'material')
                ->where('module_id', $target['activityId'])
                ->first();
            $this->assertNotNull($module);
            $this->assertTrue($module->visible);

            $this->assertTrue(
                $courseAccessService->isActiveEnrollee($student, $module->course),
                "Student {$target['studentId']} not actively enrolled in course {$target['courseId']}"
            );

            $availability = $availabilityService->availabilityFor($student, $module);
            $this->assertTrue(
                $availability['available'],
                "Material {$target['activityId']} not available for student {$target['studentId']}: {$availability['reason']}"
            );

            $this->assertEquals(200, $target['expectedStatus']);
        }

        // Assignment submission targets
        $targets = $this->fixtures['WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS'] ?? [];
        $this->assertNotEmpty($targets, 'WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS must be non-empty');

        foreach ($targets as $target) {
            $student = User::find($target['studentId']);
            $this->assertNotNull($student);

            $assignment = Assignment::find($target['activityId']);
            $this->assertNotNull($assignment);
            $this->assertTrue($assignment->is_active);

            $module = LearningModule::where('module_type', 'assignment')
                ->where('module_id', $target['activityId'])
                ->first();
            $this->assertNotNull($module);
            $this->assertTrue($module->visible);

            $this->assertTrue(
                $courseAccessService->isActiveEnrollee($student, $module->course),
                "Student {$target['studentId']} not actively enrolled in course {$target['courseId']}"
            );

            $availability = $availabilityService->availabilityFor($student, $module);
            $this->assertTrue(
                $availability['available'],
                "Assignment {$target['activityId']} not available for student {$target['studentId']}: {$availability['reason']}"
            );

            if ($assignment->cutoff_date) {
                $this->assertTrue(
                    $assignment->cutoff_date->isFuture(),
                    "Assignment {$target['activityId']} cutoff_date is in the past"
                );
            }

            $this->assertEquals(201, $target['expectedStatus']);
        }

        // Quiz attempt targets
        $targets = $this->fixtures['WRITABLE_QUIZ_ATTEMPT_TARGETS'] ?? [];
        $this->assertNotEmpty($targets, 'WRITABLE_QUIZ_ATTEMPT_TARGETS must be non-empty');

        foreach ($targets as $target) {
            $student = User::find($target['studentId']);
            $this->assertNotNull($student);

            $quiz = Quiz::find($target['activityId']);
            $this->assertNotNull($quiz);
            $this->assertTrue($quiz->is_active);
            $this->assertTrue($quiz->isOpen(), "Quiz {$target['activityId']} is not open");

            $module = LearningModule::where('module_type', 'quiz')
                ->where('module_id', $target['activityId'])
                ->first();
            $this->assertNotNull($module);
            $this->assertTrue($module->visible);

            $this->assertTrue(
                $courseAccessService->isActiveEnrollee($student, $module->course),
                "Student {$target['studentId']} not actively enrolled in course {$target['courseId']}"
            );

            $availability = $availabilityService->availabilityFor($student, $module);
            $this->assertTrue(
                $availability['available'],
                "Quiz {$target['activityId']} not available for student {$target['studentId']}: {$availability['reason']}"
            );

            $hasInProgress = $quiz->attempts()
                ->where('user_id', $student->id)
                ->whereIn('status', ['in_progress', 'started'])
                ->exists();
            $this->assertFalse($hasInProgress, "Student {$target['studentId']} has in-progress attempt for quiz {$target['activityId']}");

            if ($quiz->max_attempts !== null) {
                // Must include 'finished' — QuizService::submitQuizAnswers() uses that status
                $completedAttempts = $quiz->attempts()
                    ->where('user_id', $student->id)
                    ->whereIn('status', ['finished', 'completed', 'submitted'])
                    ->count();
                $this->assertLessThan(
                    $quiz->max_attempts,
                    $completedAttempts,
                    "Student {$target['studentId']} exhausted max attempts for quiz {$target['activityId']}"
                );
            }

            $this->assertEquals(201, $target['expectedStatus']);
            $this->assertQuizTargetHasValidAnswers($target);
        }
    }

    #[Test]
    public function writable_quiz_target_submission_creates_step_data(): void
    {
        $targets = $this->fixtures['WRITABLE_QUIZ_ATTEMPT_TARGETS'] ?? [];
        $this->assertNotEmpty($targets);

        $target = $targets[0];
        $this->assertArrayHasKey('answers', $target);
        $this->assertNotEmpty($target['answers']);
        $studentId = $target['studentId'];

        // Start a quiz attempt via the API
        $startRes = $this->withHeader('X-Benchmark-Actor-Id', $studentId)
            ->postJson("/api/quizzes/{$target['activityId']}/attempts");

        $startRes->assertStatus(201);
        $attemptId = $startRes->json('data.id');
        $this->assertNotNull($attemptId);

        // Submit with the generated target answers
        $submitRes = $this->withHeader('X-Benchmark-Actor-Id', $studentId)
            ->putJson("/api/quizzes/{$target['activityId']}/attempts/{$attemptId}", [
                'answers' => $target['answers'],
            ]);

        $submitRes->assertStatus(200);

        // Verify step data rows were created (Plan 001: per-question write fan-out)
        $stepDataCount = QuizAttemptStepData::query()
            ->whereIn('quiz_attempt_step_id', function ($q) use ($attemptId) {
                $q->select('id')
                    ->from('quiz_attempt_steps')
                    ->whereIn('quiz_attempt_question_id', function ($q2) use ($attemptId) {
                        $q2->select('id')
                            ->from('quiz_attempt_questions')
                            ->where('quiz_attempt_id', $attemptId);
                    });
            })
            ->count();

        $this->assertGreaterThanOrEqual(
            count($target['answers']),
            $stepDataCount,
            'Expected at least '.count($target['answers']).' quiz_attempt_step_data rows, got '.$stepDataCount
        );
    }

    /**
     * Plan 001: answers must be present with real question IDs and valid option values.
     */
    private function assertQuizTargetHasValidAnswers(array $target): void
    {
        $this->assertArrayHasKey('answers', $target, "Quiz {$target['activityId']} target missing answers");
        $this->assertNotEmpty($target['answers'], "Quiz {$target['activityId']} target has empty answers");

        $quiz = Quiz::with('questions')->find($target['activityId']);
        $this->assertNotNull($quiz);

        $questions = $quiz->questions->keyBy('id');
        $questionIds = $questions->keys()->toArray();

        foreach ($target['answers'] as $questionId => $answerValue) {
            $this->assertContains(
                $questionId,
                $questionIds,
                "Quiz {$target['activityId']} target answer key {$questionId} is not a question of this quiz"
            );

            $question = $questions->get($questionId);
            $this->assertNotNull($question);

            $this->assertArrayHasKey(
                $answerValue,
                $question->options,
                "Quiz {$target['activityId']} target answer value '{$answerValue}' for question {$questionId} is not a valid option. Valid options: ".implode(', ', array_keys($question->options ?? []))
            );
        }
    }

    #[Test]
    public function module_integrity_and_grade_validity(): void
    {
        // Activity-module integrity checks
        $this->assertActivityModuleIntegrity('material', [
            $this->fixtures['READABLE_MATERIAL_TARGETS'] ?? [],
            $this->fixtures['MATERIAL_BY_COURSE'] ?? [],
        ]);

        $this->assertActivityModuleIntegrity('quiz', [
            $this->fixtures['READABLE_QUIZ_TARGETS'] ?? [],
            $this->fixtures['QUIZ_BY_COURSE'] ?? [],
        ]);

        $this->assertActivityModuleIntegrity('assignment', [
            $this->fixtures['READABLE_ASSIGNMENT_TARGETS'] ?? [],
            $this->fixtures['ASSIGNMENT_BY_COURSE'] ?? [],
        ]);

        // Controlled failure targets all have single module
        $controlledFailurePools = [
            $this->fixtures['GROUP_RESTRICTED_MODULE_TARGETS'] ?? [],
            $this->fixtures['PREREQUISITE_LOCKED_TARGETS'] ?? [],
            $this->fixtures['PREREQUISITE_UNLOCK_TARGETS'] ?? [],
            $this->fixtures['MIN_GRADE_LOCKED_TARGETS'] ?? [],
            $this->fixtures['HIDDEN_MODULE_TARGETS'] ?? [],
        ];

        foreach ($controlledFailurePools as $targets) {
            foreach ($targets as $target) {
                $this->assertSingleModule(
                    $target['activityType'],
                    $target['activityId'],
                    $target['courseId'] ?? null
                );
            }
        }

        // Grading targets module check
        $gradingTargets = $this->fixtures['GRADING_TARGETS'] ?? [];
        foreach ($gradingTargets as $target) {
            $this->assertSingleModule('assignment', $target['assignmentId'], $target['courseId']);
        }

        // Grade validity
        $invalid = Grade::where('status', 'final')
            ->whereRaw('score > max_score')
            ->get();
        $this->assertCount(
            0,
            $invalid,
            'Found '.$invalid->count().' grades where score > max_score. '
            .'This violates gradebook update assumptions and breaks PUT /api/grades/{id}.'
        );

        $negativeGrades = Grade::where('status', 'final')
            ->where(function ($q) {
                $q->where('score', '<', 0)->orWhere('max_score', '<', 0);
            })
            ->get();
        $this->assertCount(0, $negativeGrades, 'Found grades with negative score or max_score');
    }

    private function assertActivityModuleIntegrity(string $activityType, array $pools): void
    {
        $checked = [];
        foreach ($pools as $pool) {
            foreach ($pool as $key => $value) {
                if (! is_array($value)) {
                    continue;
                }

                if (isset($value['activityId']) || isset($value['assignmentId'])) {
                    $id = $value['activityId'] ?? $value['assignmentId'] ?? null;
                    $courseId = $value['courseId'] ?? null;
                    if ($id !== null && ! in_array($id, $checked)) {
                        $checked[] = $id;
                        $this->assertSingleModule($activityType, $id, $courseId);
                    }

                    continue;
                }

                foreach ($value as $item) {
                    if (! is_int($item)) {
                        continue;
                    }
                    $id = $item;
                    if (! in_array($id, $checked)) {
                        $checked[] = $id;
                        $this->assertSingleModule($activityType, $id, null);
                    }
                }
            }
        }
    }

    private function assertSingleModule(string $activityType, int $activityId, ?int $expectedCourseId = null): void
    {
        $modules = LearningModule::where('module_type', $activityType)
            ->where('module_id', $activityId)
            ->get();

        $this->assertCount(
            1,
            $modules,
            "{$activityType} ID {$activityId} should have exactly one learning module, found {$modules->count()}"
        );

        if ($expectedCourseId !== null && $modules->isNotEmpty()) {
            $this->assertEquals(
                $expectedCourseId,
                $modules->first()->course_id,
                "{$activityType} ID {$activityId} module course_id mismatch"
            );
        }
    }
}
