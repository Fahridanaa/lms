<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseGroup;
use App\Models\CourseGroupMember;
use App\Models\CourseSection;
use App\Models\Grade;
use App\Models\Material;
use App\Models\ModuleAvailabilityRule;
use App\Models\ModuleCompletion;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    private User $instructor;

    private User $student;

    private Course $course;

    private CourseSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        /** @var User $instructor */
        $instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor = $instructor;

        /** @var User $student */
        $student = User::factory()->create(['role' => 'student']);
        $this->student = $student;

        /** @var Course $course */
        $course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);
        $this->course = $course;

        CourseEnrollment::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        /** @var CourseSection $section */
        $section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Test Section',
            'sort_order' => 0,
            'visible' => true,
        ]);
        $this->section = $section;
    }

    #[Test]
    public function module_unavailable_before_start_date(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Future Material',
        ]);
        $material->learningModule()->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'available_from' => now()->addDays(7),
            'available_until' => null,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);
        $this->assertFalse($modules[0]['available']);
        $this->assertEquals('not_yet_available', $modules[0]['reason']);
    }

    #[Test]
    public function module_unavailable_after_end_date(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Expired Material',
        ]);
        $material->learningModule()->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'available_from' => null,
            'available_until' => now()->subDay(),
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);
        $this->assertFalse($modules[0]['available']);
        $this->assertEquals('no_longer_available', $modules[0]['reason']);
    }

    #[Test]
    public function module_available_within_date_window(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Available Material',
        ]);
        $material->learningModule()->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'available_from' => now()->subDays(1),
            'available_until' => now()->addDays(7),
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);
        $this->assertTrue($modules[0]['available']);
        $this->assertNull($modules[0]['reason']);
    }

    #[Test]
    public function module_unavailable_until_prerequisite_completed(): void
    {
        $prerequisite = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Prerequisite Module',
        ]);
        $prerequisiteModule = $prerequisite->learningModule;
        $prerequisiteModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $dependent = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Dependent Module',
        ]);
        $dependentModule = $dependent->learningModule;
        $dependentModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 1,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $dependentModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $prerequisiteModule->id,
            'operator' => '==',
            'value' => 'complete',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(2, $modules);

        // Prerequisite should be available
        $this->assertEquals('Prerequisite Module', $modules[0]['activity']['title']);
        $this->assertTrue($modules[0]['available']);

        // Dependent should be unavailable
        $this->assertEquals('Dependent Module', $modules[1]['activity']['title']);
        $this->assertFalse($modules[1]['available']);
        $this->assertEquals('prerequisite_not_met', $modules[1]['reason']);
    }

    #[Test]
    public function module_becomes_available_after_prerequisite_completed(): void
    {
        $prerequisite = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Prerequisite Module',
        ]);
        $prerequisiteModule = $prerequisite->learningModule;
        $prerequisiteModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $dependent = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Dependent Module',
        ]);
        $dependentModule = $dependent->learningModule;
        $dependentModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 1,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $dependentModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $prerequisiteModule->id,
            'operator' => '==',
            'value' => 'complete',
        ]);

        // Mark prerequisite as complete
        ModuleCompletion::factory()->create([
            'learning_module_id' => $prerequisiteModule->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
            'completed_at' => now(),
            'source' => 'view',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(2, $modules);

        // Both modules should be available
        $this->assertTrue($modules[0]['available']);
        $this->assertTrue($modules[1]['available']);
        $this->assertNull($modules[1]['reason']);
    }

    #[Test]
    public function module_unavailable_for_non_group_members(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Group Restricted Module',
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Alpha Team',
            'active' => true,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Student is NOT in the group —
        // CourseAccessService::canReadModule filters out group-restricted modules entirely

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        // Module is completely hidden (not just marked unavailable) because group access control
        $this->assertCount(0, $modules);
    }

    #[Test]
    public function module_available_for_group_members(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Group Restricted Module',
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Alpha Team',
            'active' => true,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Add student to the group
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->student->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);
        $this->assertTrue($modules[0]['available']);
        $this->assertNull($modules[0]['reason']);
    }

    #[Test]
    public function module_available_when_grade_meets_threshold(): void
    {
        $gradeItem = \App\Models\GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'name' => 'Test Grade Item',
        ]);

        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Grade Gated Module',
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'min_grade',
            'grade_item_id' => $gradeItem->id,
            'operator' => '>=',
            'value' => '70',
        ]);

        // Create a grade that meets the threshold for the specific grade item
        Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $gradeItem->id,
            'gradeable_type' => 'quiz_attempt',
            'score' => 85,
            'max_score' => 100,
            'percentage' => 85.00,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);
        $this->assertTrue($modules[0]['available']);
        $this->assertNull($modules[0]['reason']);
    }

    #[Test]
    public function module_unavailable_when_grade_below_threshold(): void
    {
        $gradeItem = \App\Models\GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'name' => 'Test Grade Item',
        ]);

        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Grade Gated Module',
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'min_grade',
            'grade_item_id' => $gradeItem->id,
            'operator' => '>=',
            'value' => '70',
        ]);

        // Create a grade below the threshold for the specific grade item
        Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $gradeItem->id,
            'gradeable_type' => 'quiz_attempt',
            'score' => 50,
            'max_score' => 100,
            'percentage' => 50.00,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);
        $this->assertFalse($modules[0]['available']);
        $this->assertEquals('minimum_grade_not_met', $modules[0]['reason']);
    }

    #[Test]
    public function course_structure_shows_availability_for_student(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Regular Module',
        ]);
        $material->learningModule()->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);

        // Student should see availability and completion fields
        $this->assertArrayHasKey('available', $modules[0]);
        $this->assertArrayHasKey('reason', $modules[0]);
        $this->assertArrayHasKey('completion', $modules[0]);
        $this->assertArrayHasKey('completed', $modules[0]['completion']);
        $this->assertArrayHasKey('state', $modules[0]['completion']);
        $this->assertFalse($modules[0]['completion']['completed']);
        $this->assertEquals('incomplete', $modules[0]['completion']['state']);
    }

    #[Test]
    public function course_structure_does_not_show_availability_for_instructor(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Regular Module',
        ]);
        $material->learningModule()->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);

        // Instructor should NOT see availability or completion fields
        $this->assertArrayNotHasKey('available', $modules[0]);
        $this->assertArrayNotHasKey('reason', $modules[0]);
        $this->assertArrayNotHasKey('completion', $modules[0]);
    }

    #[Test]
    public function module_completion_can_be_marked(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Completable Module',
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        // Mark completion via service
        $completionService = app(\App\Services\ModuleCompletionService::class);
        $completionService->markComplete($module, $this->student, 'test');

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);

        $this->assertTrue($modules[0]['completion']['completed']);
        $this->assertEquals('complete', $modules[0]['completion']['state']);
        $this->assertNotNull($modules[0]['completion']['completed_at']);
    }

    #[Test]
    public function group_restricted_material_cannot_be_downloaded_by_non_member(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Beta Team',
            'active' => true,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Student is NOT in the group — direct endpoint should deny access
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}/download");

        $response->assertStatus(404);

        // Also verify via course structure endpoint
        $structureResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $structureResponse->assertOk();
        $this->assertCount(0, $structureResponse->json('data.sections.0.modules'));
    }

    #[Test]
    public function prerequisite_locked_assignment_cannot_be_submitted_directly(): void
    {
        $prerequisite = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $prerequisiteModule = $prerequisite->learningModule;
        $prerequisiteModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $assignmentModule = $assignment->learningModule;
        $assignmentModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 1,
            'completion_enabled' => true,
        ]);

        // Add prerequisite: the material module must be completed first
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $assignmentModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $prerequisiteModule->id,
            'operator' => '==',
            'value' => 'complete',
        ]);

        // Student tries to submit the assignment without completing the prerequisite
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);

        $response->assertStatus(404);

        // Now mark prerequisite as complete
        ModuleCompletion::factory()->create([
            'learning_module_id' => $prerequisiteModule->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
            'completed_at' => now(),
            'source' => 'view',
        ]);

        // Now the submission should succeed
        $response2 = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);

        $response2->assertStatus(201);
    }

    #[Test]
    public function min_grade_locked_quiz_cannot_be_started_directly(): void
    {
        $gradeItem = \App\Models\GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'name' => 'Entry Grade Item',
        ]);

        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $quizModule = $quiz->learningModule;
        $quizModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        // Add min-grade requirement
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $quizModule->id,
            'rule_type' => 'min_grade',
            'grade_item_id' => $gradeItem->id,
            'operator' => '>=',
            'value' => '70',
        ]);

        // Student has no grade in the required grade item — should be blocked
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts", []);

        $response->assertStatus(404);

        // Create a grade that meets the threshold
        Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $gradeItem->id,
            'gradeable_type' => 'quiz_attempt',
            'score' => 85,
            'max_score' => 100,
            'percentage' => 85.00,
            'status' => 'final',
        ]);

        // Now the quiz attempt should succeed
        $response2 = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts", []);

        $response2->assertStatus(201);
    }

    #[Test]
    public function hidden_module_behavior_consistent_between_structure_and_direct_endpoint(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => false, // Hidden
        ]);

        // Direct endpoint should return 404
        $directResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}");

        $directResponse->assertStatus(404);

        // Course structure should NOT include the hidden module
        $structureResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $structureResponse->assertOk();
        $this->assertCount(0, $structureResponse->json('data.sections.0.modules'));
    }

    #[Test]
    public function high_grade_in_other_grade_item_does_not_unlock_module(): void
    {
        $requiredGradeItem = \App\Models\GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'name' => 'Required Grade Item',
        ]);

        $otherGradeItem = \App\Models\GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'assignment',
            'name' => 'Other Grade Item',
        ]);

        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Grade Gated Module',
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'min_grade',
            'grade_item_id' => $requiredGradeItem->id,
            'operator' => '>=',
            'value' => '70',
        ]);

        // Create a high grade in the OTHER grade item — should NOT unlock the module
        Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $otherGradeItem->id,
            'gradeable_type' => 'submission',
            'score' => 95,
            'max_score' => 100,
            'percentage' => 95.00,
            'status' => 'final',
        ]);

        // Module should still be unavailable because the required grade item has no grade
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');
        $this->assertCount(1, $modules);
        $this->assertFalse($modules[0]['available']);
        $this->assertEquals('minimum_grade_not_met', $modules[0]['reason']);
    }

    #[Test]
    public function material_download_completes_view_completion_module(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'view',
        ]);

        // Download the material — should trigger completion
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}/download");

        $response->assertStatus(200);

        // Verify completion was recorded
        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
            'source' => 'view',
        ]);

        // Course structure should now show completion
        $structureResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $structureResponse->assertOk();
        $modules = $structureResponse->json('data.sections.0.modules');
        $this->assertTrue($modules[0]['completion']['completed']);
        $this->assertEquals('complete', $modules[0]['completion']['state']);
    }

    #[Test]
    public function assignment_submit_completes_submit_completion_module(): void
    {
        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $assignment->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'submit',
        ]);

        // Submit the assignment — should trigger completion
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => '/test/completion-submission.pdf',
            ]);

        $response->assertStatus(201);

        // Verify completion was recorded
        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
    }

    #[Test]
    public function quiz_submit_completes_finish_completion_module(): void
    {
        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $quiz->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'finish',
        ]);

        \App\Models\Question::factory()->count(2)->create([
            'quiz_id' => $quiz->id,
            'points' => 50,
        ]);

        // Start a quiz attempt
        $attemptResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts", []);

        $attemptResponse->assertStatus(201);
        $attemptId = $attemptResponse->json('data.id');

        // Submit answers
        $answers = [];
        foreach ($quiz->fresh()->questions as $question) {
            $answers[$question->id] = (string) $question->correct_answer;
        }

        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ]);

        $submitResponse->assertStatus(200);

        // Verify completion was recorded for finish-based completion
        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
            'source' => 'finish',
        ]);
    }

    #[Test]
    public function completion_invalidates_course_structure_cache(): void
    {
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Cached Module',
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        // First request — module is incomplete
        $response1 = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response1->assertOk();
        $modules1 = $response1->json('data.sections.0.modules');
        $this->assertFalse($modules1[0]['completion']['completed']);

        // Mark completion via service (this calls invalidateCaches)
        $completionService = app(\App\Services\ModuleCompletionService::class);
        $completionService->markComplete($module, $this->student, 'view');

        // Second request — should reflect the new completion state
        $response2 = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response2->assertOk();
        $modules2 = $response2->json('data.sections.0.modules');
        $this->assertTrue($modules2[0]['completion']['completed']);
        $this->assertEquals('complete', $modules2[0]['completion']['state']);
    }

    #[Test]
    public function passing_grade_via_submission_unlocks_dependent_module(): void
    {
        // Create a prerequisite module with submit-based completion
        $prerequisite = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'max_score' => 100,
        ]);
        $prereqModule = $prerequisite->learningModule;
        $prereqModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'submit',
        ]);

        // Create a dependent module with completion prerequisite
        $dependent = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $dependentModule = $dependent->learningModule;
        $dependentModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 1,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $dependentModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $prereqModule->id,
            'operator' => '==',
            'value' => 'complete',
        ]);

        // Submit the assignment — triggers completion, which should unlock the dependent module
        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$prerequisite->id}/submissions", [
                'file_path' => '/test/prereq-submission.pdf',
            ]);

        $submitResponse->assertStatus(201);

        // Verify dependent module is now available in course structure
        $structureResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $structureResponse->assertOk();
        $modules = $structureResponse->json('data.sections.0.modules');
        $this->assertCount(2, $modules);
        $this->assertTrue($modules[0]['completion']['completed']);
        $this->assertTrue($modules[1]['available']);
        $this->assertNull($modules[1]['reason']);
    }

    #[Test]
    public function completion_write_invalidates_cached_course_structure(): void
    {
        // Create a completable module
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'view',
        ]);

        // Make a request to cache the course structure with incomplete state
        $beforeResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $beforeResponse->assertOk();
        $beforeModules = $beforeResponse->json('data.sections.0.modules');
        $this->assertFalse($beforeModules[0]['completion']['completed']);

        // Download the material — should trigger completion + cache invalidation
        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}/download")
            ->assertStatus(200);

        // The next request should reflect the new state (cache was invalidated)
        $afterResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $afterResponse->assertOk();
        $afterModules = $afterResponse->json('data.sections.0.modules');
        $this->assertTrue($afterModules[0]['completion']['completed']);
    }

    #[Test]
    public function assignment_pass_grade_does_not_complete_on_submit_only(): void
    {
        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'max_score' => 100,
        ]);
        $module = $assignment->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        // Submit the assignment — submission has no score yet
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);

        $response->assertStatus(201);

        // Assert: completion should NOT be recorded (no pass_grade at submit-only)
        $this->assertDatabaseMissing('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
        ]);
    }

    #[Test]
    public function assignment_pass_grade_completes_after_passing_grade(): void
    {
        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'max_score' => 100,
        ]);
        $module = $assignment->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        // Submit the assignment
        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);
        $submitResponse->assertStatus(201);
        $submissionId = $submitResponse->json('data.id');

        // Instructor grades above pass threshold (70/100 >= 60%)
        $gradeResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submissionId}/grade", [
                'score' => 70,
            ]);
        $gradeResponse->assertStatus(200);

        // Assert: completion should now be recorded with pass_grade source
        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
            'state' => 'complete_passed',
            'source' => 'pass_grade',
        ]);
    }

    #[Test]
    public function assignment_pass_grade_does_not_complete_after_below_pass_grade(): void
    {
        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'max_score' => 100,
        ]);
        $module = $assignment->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        // Submit the assignment
        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);
        $submitResponse->assertStatus(201);
        $submissionId = $submitResponse->json('data.id');

        // Instructor grades below pass threshold (50/100)
        $gradeResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submissionId}/grade", [
                'score' => 50,
            ]);
        $gradeResponse->assertStatus(200);

        // Assert: completion should NOT be recorded (below pass threshold)
        $this->assertDatabaseMissing('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
        ]);
    }

    #[Test]
    public function quiz_pass_grade_completes_after_passing_attempt(): void
    {
        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $quiz->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        // Create questions worth 100 points total
        \App\Models\Question::factory()->create([
            'quiz_id' => $quiz->id,
            'points' => 100,
            'correct_answer' => 'correct_ans',
        ]);

        // Start a quiz attempt
        $attemptResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts", []);
        $attemptResponse->assertStatus(201);
        $attemptId = $attemptResponse->json('data.id');

        // Submit answers — correct answer should give 100/100 (passing)
        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}", [
                'answers' => [$quiz->fresh()->questions->first()->id => 'correct_ans'],
            ]);
        $submitResponse->assertStatus(200);

        // Assert: completion recorded with pass_grade
        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
            'state' => 'complete_passed',
            'source' => 'pass_grade',
        ]);
    }

    #[Test]
    public function quiz_pass_grade_does_not_complete_after_failing_attempt(): void
    {
        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $module = $quiz->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        // Create questions worth 100 points total
        \App\Models\Question::factory()->create([
            'quiz_id' => $quiz->id,
            'points' => 100,
            'correct_answer' => 'correct_ans',
        ]);

        // Start a quiz attempt
        $attemptResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts", []);
        $attemptResponse->assertStatus(201);
        $attemptId = $attemptResponse->json('data.id');

        // Submit WRONG answers — should give 0/100 (failing)
        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}", [
                'answers' => [$quiz->fresh()->questions->first()->id => 'wrong_ans'],
            ]);
        $submitResponse->assertStatus(200);

        // Assert: completion NOT recorded (below pass threshold)
        $this->assertDatabaseMissing('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
        ]);
    }

    // ── Plan 002: Regression — quiz pass-grade with non-100 total points ──

    #[Test]
    public function quiz_pass_grade_with_non_100_total_points_passes_correctly(): void
    {
        // Create a quiz where total points != 100 (5 questions × 1pt = 5 max)
        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'passing_score' => 60,
        ]);
        $module = $quiz->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        // 5 questions worth 1 point each (total = 5)
        Question::factory()->count(5)->create([
            'quiz_id' => $quiz->id,
            'points' => 1,
            'correct_answer' => 'A',
        ]);

        // Start attempt
        $attemptResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts");
        $attemptResponse->assertStatus(201);
        $attemptId = $attemptResponse->json('data.id');

        $questions = $quiz->fresh()->questions;
        $answers = [];
        $answeredCorrect = 0;
        foreach ($questions as $i => $q) {
            // Answer 4 out of 5 correctly → 80% score → passes 60% threshold
            $isCorrect = $i < 4;
            $answers[$q->id] = $isCorrect ? 'A' : 'B';
            if ($isCorrect) {
                $answeredCorrect++;
            }
        }
        $this->assertEquals(4, $answeredCorrect, 'Expected 4 correct answers');

        // Submit — 80% (4/5) should pass the 60% threshold
        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ]);
        $submitResponse->assertStatus(200);

        // Assert: completion recorded with pass_grade
        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
            'state' => 'complete_passed',
            'source' => 'pass_grade',
        ]);
    }

    #[Test]
    public function quiz_pass_grade_with_non_100_total_points_fails_correctly(): void
    {
        // Same setup: total points = 5
        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'passing_score' => 60,
        ]);
        $module = $quiz->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        Question::factory()->count(5)->create([
            'quiz_id' => $quiz->id,
            'points' => 1,
            'correct_answer' => 'A',
        ]);

        // Start attempt
        $attemptResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts");
        $attemptResponse->assertStatus(201);
        $attemptId = $attemptResponse->json('data.id');

        $questions = $quiz->fresh()->questions;
        $answers = [];
        foreach ($questions as $i => $q) {
            // Answer only 2 out of 5 correctly → 40% score → fails 60% threshold
            $answers[$q->id] = $i < 2 ? 'A' : 'B';
        }

        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ]);
        $submitResponse->assertStatus(200);

        // Assert: completion NOT recorded (40% < 60%)
        $this->assertDatabaseMissing('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
        ]);
    }

    #[Test]
    public function quiz_pass_grade_respects_grade_item_pass_score_override(): void
    {
        // Create quiz with low passing score, but GradeItem overrides higher
        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
            'passing_score' => 30, // Would pass at 30%
        ]);
        $module = $quiz->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'completion_enabled' => true,
            'completion_rule' => 'pass_grade',
        ]);

        // GradeItem overrides pass_score to 70%
        \App\Models\GradeItem::factory()->create([
            'course_id' => $quiz->course_id,
            'item_type' => 'quiz',
            'item_id' => $quiz->id,
            'pass_score' => 70,
        ]);

        Question::factory()->count(5)->create([
            'quiz_id' => $quiz->id,
            'points' => 1,
            'correct_answer' => 'A',
        ]);

        // Start attempt
        $attemptResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts");
        $attemptResponse->assertStatus(201);
        $attemptId = $attemptResponse->json('data.id');

        $questions = $quiz->fresh()->questions;
        $answers = [];
        foreach ($questions as $i => $q) {
            // 3/5 = 60% — passes quiz-level 30%, fails GradeItem 70%
            $answers[$q->id] = $i < 3 ? 'A' : 'B';
        }

        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ]);
        $submitResponse->assertStatus(200);

        // Assert: completion NOT recorded (60% < GradeItem 70%)
        $this->assertDatabaseMissing('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->student->id,
        ]);
    }

    /* ──────────────────────────────────────────────
     * Plan 001: Direct activity endpoint availability
     * ────────────────────────────────────────────── */

    #[Test]
    public function prerequisite_locked_assignment_detail_returns_404(): void
    {
        $prerequisite = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $prerequisiteModule = $prerequisite->learningModule;
        $prerequisiteModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $assignmentModule = $assignment->learningModule;
        $assignmentModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 1,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $assignmentModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $prerequisiteModule->id,
            'operator' => '==',
            'value' => 'complete',
        ]);

        // Student has NOT completed the prerequisite — detail read should fail
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/assignments/{$assignment->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function prerequisite_locked_quiz_detail_returns_404(): void
    {
        $prerequisite = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $prerequisiteModule = $prerequisite->learningModule;
        $prerequisiteModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $quizModule = $quiz->learningModule;
        $quizModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 1,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $quizModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $prerequisiteModule->id,
            'operator' => '==',
            'value' => 'complete',
        ]);

        // Student has NOT completed the prerequisite — quiz detail read should fail
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$quiz->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function min_grade_locked_assignment_detail_returns_404(): void
    {
        $gradeItem = \App\Models\GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'name' => 'Entry Grade Item',
        ]);

        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $assignmentModule = $assignment->learningModule;
        $assignmentModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $assignmentModule->id,
            'rule_type' => 'min_grade',
            'grade_item_id' => $gradeItem->id,
            'operator' => '>=',
            'value' => '70',
        ]);

        // Student has no grade in the required grade item — detail read should fail
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/assignments/{$assignment->id}");

        $response->assertStatus(404);
    }

    // ─── Nested Availability Tests (Plan 005) ────────────────────────────────

    #[Test]
    public function nested_availability_and_blocks_until_all_conditions_pass(): void
    {
        $materialA = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'Prereq A']);
        $materialAModule = $materialA->learningModule;
        $materialAModule->update(['course_section_id' => $this->section->id, 'sort_order' => 0]);

        $materialB = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'Prereq B']);
        $materialBModule = $materialB->learningModule;
        $materialBModule->update(['course_section_id' => $this->section->id, 'sort_order' => 1]);

        $target = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'AND Target']);
        $targetModule = $target->learningModule;
        $targetModule->update(['course_section_id' => $this->section->id, 'sort_order' => 2]);

        // Both rules in condition_group=1 → must pass both (AND)
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $materialAModule->id,
            'operator' => '==',
            'condition_group' => 1,
        ]);
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $materialBModule->id,
            'operator' => '==',
            'condition_group' => 1,
        ]);

        // Neither completed → unavailable
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(404);

        // Complete A only → still unavailable (B also required)
        ModuleCompletion::factory()->create([
            'learning_module_id' => $materialAModule->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(404);

        // Complete both → unlocked
        ModuleCompletion::factory()->create([
            'learning_module_id' => $materialBModule->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(200);
    }

    #[Test]
    public function nested_availability_or_unlocks_when_either_branch_passes(): void
    {
        $materialA = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'OR Prereq A']);
        $moduleA = $materialA->learningModule;
        $moduleA->update(['course_section_id' => $this->section->id, 'sort_order' => 0]);

        $materialB = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'OR Prereq B']);
        $moduleB = $materialB->learningModule;
        $moduleB->update(['course_section_id' => $this->section->id, 'sort_order' => 1]);

        $target = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'OR Target']);
        $targetModule = $target->learningModule;
        $targetModule->update(['course_section_id' => $this->section->id, 'sort_order' => 2]);

        // Group 1: completion of A
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $moduleA->id,
            'operator' => '==',
            'condition_group' => 1,
        ]);

        // Group 2: completion of B
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $moduleB->id,
            'operator' => '==',
            'condition_group' => 2,
        ]);

        // Neither completed → unavailable
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(404);

        // Complete A only → unlocked (group 1 passes)
        ModuleCompletion::factory()->create([
            'learning_module_id' => $moduleA->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(200);

        // Reset: only complete B → unlocked
        ModuleCompletion::where('learning_module_id', $moduleA->id)->delete();
        ModuleCompletion::factory()->create([
            'learning_module_id' => $moduleB->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(200);
    }

    #[Test]
    public function grouped_availability_mixed_and_or_expression(): void
    {
        // (completion A AND completion B) OR group C
        $materialA = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'Mixed A']);
        $moduleA = $materialA->learningModule;
        $moduleA->update(['course_section_id' => $this->section->id, 'sort_order' => 0]);

        $materialB = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'Mixed B']);
        $moduleB = $materialB->learningModule;
        $moduleB->update(['course_section_id' => $this->section->id, 'sort_order' => 1]);

        $group = CourseGroup::factory()->create(['course_id' => $this->course->id]);

        $target = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'Mixed Target']);
        $targetModule = $target->learningModule;
        $targetModule->update(['course_section_id' => $this->section->id, 'sort_order' => 2]);

        // Group 1: completion A AND completion B
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $moduleA->id,
            'operator' => '==',
            'condition_group' => 1,
        ]);
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $moduleB->id,
            'operator' => '==',
            'condition_group' => 1,
        ]);

        // Group 2: group C
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
            'operator' => '==',
            'condition_group' => 2,
        ]);

        // Neither condition satisfied → unavailable
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(404);

        // Student not member of group but completes both A and B → unlocked via group 1
        ModuleCompletion::factory()->create([
            'learning_module_id' => $moduleA->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
        ModuleCompletion::factory()->create([
            'learning_module_id' => $moduleB->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(200);

        // Delete completions, add student to group → unlocked via group 2
        ModuleCompletion::whereIn('learning_module_id', [$moduleA->id, $moduleB->id])->delete();
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->student->id,
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(200);
    }

    #[Test]
    public function flat_rules_still_work_with_grouped_evaluation(): void
    {
        // Rules without condition_group should behave exactly as before (each rule = own group = AND all)
        $prereq = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'Prereq for Flat']);
        $prereqModule = $prereq->learningModule;
        $prereqModule->update(['course_section_id' => $this->section->id, 'sort_order' => 0]);

        $group = CourseGroup::factory()->create(['course_id' => $this->course->id]);

        $target = Material::factory()->create(['course_id' => $this->course->id, 'title' => 'Flat Rules Target']);
        $targetModule = $target->learningModule;
        $targetModule->update(['course_section_id' => $this->section->id, 'sort_order' => 1]);

        // Two rules without condition_group → still AND behavior (both must pass)
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'completion',
            'required_module_id' => $prereqModule->id,
            'operator' => '==',
            'condition_group' => null,
        ]);
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $targetModule->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
            'operator' => '==',
            'condition_group' => null,
        ]);

        // Neither completed nor member → unavailable
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(404);

        // Complete prereq but not in group → still unavailable (AND with null groups)
        ModuleCompletion::factory()->create([
            'learning_module_id' => $prereqModule->id,
            'user_id' => $this->student->id,
            'state' => 'complete',
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(404);

        // Both satisfied → available
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->student->id,
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$target->id}");
        $response->assertStatus(200);
    }

    #[Test]
    public function quiz_questions_under_locked_availability_returns_404(): void
    {
        $gradeItem = \App\Models\GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'name' => 'Entry Grade Item',
        ]);

        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
        $quizModule = $quiz->learningModule;
        $quizModule->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $quizModule->id,
            'rule_type' => 'min_grade',
            'grade_item_id' => $gradeItem->id,
            'operator' => '>=',
            'value' => '70',
        ]);

        // Student has no grade in the required grade item — questions endpoint should fail
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$quiz->id}/questions");

        $response->assertStatus(404);
    }
}
