<?php

namespace Tests\Unit\Services;

use App\Models\Course;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseCompletionCriterionCompletion;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\User;
use App\Services\CourseCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CourseCompletionService $service;

    private Course $course;

    private User $student;

    private User $instructor;

    private LearningModule $module;

    private GradeItem $gradeItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CourseCompletionService;

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);
        $this->module = LearningModule::factory()->create([
            'course_id' => $this->course->id,
            'module_type' => 'material',
            'visible' => true,
        ]);
        $this->gradeItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Test Quiz',
            'max_score' => 100,
        ]);
    }

    public function test_get_criteria_returns_empty_for_no_criteria(): void
    {
        $criteria = $this->service->getCriteria($this->course->id);

        $this->assertCount(0, $criteria);
    }

    public function test_get_user_progress_with_no_criteria(): void
    {
        $progress = $this->service->getUserProgress($this->course->id, $this->student->id);

        $this->assertSame(0, $progress['criteria_total']);
        $this->assertFalse($progress['completed']);
    }

    public function test_evaluate_module_criterion_returns_true_when_completed(): void
    {
        $criterion = CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => $this->module->id,
        ]);

        // Mark criterion completion
        CourseCompletionCriterionCompletion::query()->create([
            'course_completion_criterion_id' => $criterion->id,
            'user_id' => $this->student->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $this->assertTrue(
            $this->service->evaluateCriterion($criterion->id, $this->student->id)
        );
    }

    public function test_evaluate_module_criterion_returns_false_when_not_completed(): void
    {
        $criterion = CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => $this->module->id,
        ]);

        $this->assertFalse(
            $this->service->evaluateCriterion($criterion->id, $this->student->id)
        );
    }

    public function test_evaluate_grade_criterion_returns_true_when_meets_threshold(): void
    {
        $criterion = CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'grade',
            'grade_item_id' => $this->gradeItem->id,
            'pass_threshold' => 60,
        ]);

        Grade::query()->create([
            'grade_item_id' => $this->gradeItem->id,
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'gradeable_type' => 'quiz',
            'gradeable_id' => 1,
            'score' => 85,
            'max_score' => 100,
            'percentage' => 85,
        ]);

        $this->assertTrue(
            $this->service->evaluateCriterion($criterion->id, $this->student->id)
        );
    }

    public function test_evaluate_grade_criterion_returns_false_when_below_threshold(): void
    {
        $criterion = CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'grade',
            'grade_item_id' => $this->gradeItem->id,
            'pass_threshold' => 60,
        ]);

        Grade::query()->create([
            'grade_item_id' => $this->gradeItem->id,
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'gradeable_type' => 'quiz',
            'gradeable_id' => 1,
            'score' => 45,
            'max_score' => 100,
            'percentage' => 45,
        ]);

        $this->assertFalse(
            $this->service->evaluateCriterion($criterion->id, $this->student->id)
        );
    }

    public function test_evaluate_all_marks_course_complete(): void
    {
        // Create a single module-type criterion
        $criterion = CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => $this->module->id,
        ]);

        // Mark criterion as completed
        CourseCompletionCriterionCompletion::query()->create([
            'course_completion_criterion_id' => $criterion->id,
            'user_id' => $this->student->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $result = $this->service->evaluateAll($this->course->id, $this->student->id);

        $this->assertTrue($result);

        // Verify course completion was marked
        $this->assertDatabaseHas('course_completions', [
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);

        $completion = \App\Models\CourseCompletion::query()
            ->where('course_id', $this->course->id)
            ->where('user_id', $this->student->id)
            ->first();

        $this->assertNotNull($completion->timecompleted);
        $this->assertTrue($completion->reaggregate);
    }

    public function test_evaluate_all_returns_false_when_not_all_criteria_met(): void
    {
        $module2 = LearningModule::factory()->create([
            'course_id' => $this->course->id,
            'module_type' => 'material',
            'visible' => true,
            'module_id' => 2,
        ]);

        // Create two criteria
        CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => $this->module->id,
        ]);
        CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => $module2->id,
        ]);

        // Only complete one criterion
        $criterion1 = CourseCompletionCriterion::query()
            ->where('module_instance_id', $this->module->id)
            ->first();

        CourseCompletionCriterionCompletion::query()->create([
            'course_completion_criterion_id' => $criterion1->id,
            'user_id' => $this->student->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $result = $this->service->evaluateAll($this->course->id, $this->student->id);

        $this->assertFalse($result);
    }

    public function test_on_module_completion_triggers_cascade(): void
    {
        $criterion = CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => $this->module->id,
        ]);

        $this->service->onModuleCompletion($this->module, $this->student);

        // Verify criterion completion record was created
        $this->assertDatabaseHas('course_completion_criterion_completions', [
            'course_completion_criterion_id' => $criterion->id,
            'user_id' => $this->student->id,
            'completed' => true,
        ]);

        // Since this is the only criterion, course should be complete
        $this->assertDatabaseHas('course_completions', [
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);
    }

    public function test_on_grade_update_triggers_cascade(): void
    {
        $criterion = CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'grade',
            'grade_item_id' => $this->gradeItem->id,
            'pass_threshold' => 60,
        ]);

        Grade::query()->create([
            'grade_item_id' => $this->gradeItem->id,
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'gradeable_type' => 'quiz',
            'gradeable_id' => 1,
            'score' => 85,
            'max_score' => 100,
            'percentage' => 85,
        ]);

        $this->service->onGradeUpdate($this->gradeItem->id, $this->student->id);

        // Verify criterion completion was marked
        $this->assertDatabaseHas('course_completion_criterion_completions', [
            'course_completion_criterion_id' => $criterion->id,
            'user_id' => $this->student->id,
            'completed' => true,
        ]);

        // Since this is the only criterion, course should be complete
        $this->assertDatabaseHas('course_completions', [
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);
    }
}
