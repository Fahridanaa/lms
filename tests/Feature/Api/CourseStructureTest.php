<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\CourseGroup;
use App\Models\CourseGrouping;
use App\Models\CourseGroupingGroup;
use App\Models\CourseGroupMember;
use App\Models\CourseSection;
use App\Models\Material;
use App\Models\ModuleAvailabilityRule;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CourseStructureTest extends TestCase
{
    use DatabaseTransactions;

    private User $instructor;

    private User $student;

    private User $otherUser;

    private Course $course;

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

        /** @var User $otherUser */
        $otherUser = User::factory()->create(['role' => 'student']);
        $this->otherUser = $otherUser;

        /** @var Course $course */
        $course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);
        $this->course = $course;

        // Enroll the student
        CourseEnrollment::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function student_sees_ordered_sections_with_modules(): void
    {
        // Create 3 sections with explicit sort_order
        $section1 = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Section One',
            'summary' => 'Summary one',
            'sort_order' => 0,
            'visible' => true,
        ]);

        $section2 = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Section Two',
            'summary' => 'Summary two',
            'sort_order' => 1,
            'visible' => true,
        ]);

        $section3 = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Section Three',
            'summary' => 'Summary three',
            'sort_order' => 2,
            'visible' => true,
        ]);

        // Section 1: 1 material module
        $material1 = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Intro Material',
        ]);
        $material1->learningModule()->update([
            'course_section_id' => $section1->id,
            'sort_order' => 0,
        ]);

        // Section 2: 2 modules (material, quiz)
        $material2 = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Section Two Material',
        ]);
        $material2->learningModule()->update([
            'course_section_id' => $section2->id,
            'sort_order' => 0,
        ]);

        $quiz1 = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Section Two Quiz',
        ]);
        $quiz1->learningModule()->update([
            'course_section_id' => $section2->id,
            'sort_order' => 1,
        ]);

        // Section 3: 1 assignment module
        $assignment1 = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Final Assignment',
        ]);
        $assignment1->learningModule()->update([
            'course_section_id' => $section3->id,
            'sort_order' => 0,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $sections = $response->json('data.sections');

        // Assert 3 sections in order
        $this->assertCount(3, $sections);

        $this->assertEquals('Section One', $sections[0]['title']);
        $this->assertEquals(0, $sections[0]['sort_order']);
        $this->assertCount(1, $sections[0]['modules']);
        $this->assertEquals('Intro Material', $sections[0]['modules'][0]['activity']['title']);

        $this->assertEquals('Section Two', $sections[1]['title']);
        $this->assertEquals(1, $sections[1]['sort_order']);
        $this->assertCount(2, $sections[1]['modules']);
        $this->assertEquals('Section Two Material', $sections[1]['modules'][0]['activity']['title']);
        $this->assertEquals('material', $sections[1]['modules'][0]['module_type']);
        $this->assertEquals('Section Two Quiz', $sections[1]['modules'][1]['activity']['title']);
        $this->assertEquals('quiz', $sections[1]['modules'][1]['module_type']);

        $this->assertEquals('Section Three', $sections[2]['title']);
        $this->assertEquals(2, $sections[2]['sort_order']);
        $this->assertCount(1, $sections[2]['modules']);
        $this->assertEquals('Final Assignment', $sections[2]['modules'][0]['activity']['title']);
        $this->assertEquals('assignment', $sections[2]['modules'][0]['module_type']);
    }

    #[Test]
    public function hidden_section_is_omitted_for_student(): void
    {
        $visibleSection = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Visible Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        $hiddenSection = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Hidden Section',
            'sort_order' => 1,
            'visible' => false,
        ]);

        // Add a module to each section
        $materialVisible = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Visible Material',
        ]);
        $materialVisible->learningModule()->update([
            'course_section_id' => $visibleSection->id,
            'sort_order' => 0,
        ]);

        $materialHidden = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Hidden Section Material',
        ]);
        $materialHidden->learningModule()->update([
            'course_section_id' => $hiddenSection->id,
            'sort_order' => 0,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $sections = $response->json('data.sections');

        $this->assertCount(1, $sections);
        $this->assertEquals('Visible Section', $sections[0]['title']);
        $this->assertCount(1, $sections[0]['modules']);
        $this->assertEquals('Visible Material', $sections[0]['modules'][0]['activity']['title']);
    }

    #[Test]
    public function hidden_module_is_omitted_for_student(): void
    {
        $section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Test Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Visible module
        $visibleMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Visible Module',
        ]);
        $visibleModule = $visibleMaterial->learningModule;
        $visibleModule->update([
            'course_section_id' => $section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Hidden module - create a material then make its learning module hidden
        $hiddenMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Hidden Module Content',
        ]);
        $hiddenModule = $hiddenMaterial->learningModule;
        $hiddenModule->update([
            'course_section_id' => $section->id,
            'sort_order' => 1,
            'visible' => false,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $sections = $response->json('data.sections');

        $this->assertCount(1, $sections);
        $this->assertCount(1, $sections[0]['modules']);
        $this->assertEquals('Visible Module', $sections[0]['modules'][0]['activity']['title']);
    }

    #[Test]
    public function non_enrolled_user_gets_403(): void
    {
        // Create a section + module so the course has content
        $section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Some Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        $material = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $material->learningModule()->update([
            'course_section_id' => $section->id,
        ]);

        // otherUser is not enrolled
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->otherUser->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    #[Test]
    public function instructor_sees_all_contents_including_hidden(): void
    {
        // Visible section with visible module
        $visibleSection = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Visible Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        $visibleMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Visible Material',
        ]);
        $visibleMaterial->learningModule()->update([
            'course_section_id' => $visibleSection->id,
            'sort_order' => 0,
        ]);

        // Hidden section with visible module (section hidden, module visible)
        $hiddenSection = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Hidden Section',
            'sort_order' => 1,
            'visible' => false,
        ]);

        $moduleInHiddenSection = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Module in Hidden Section',
        ]);
        $moduleInHiddenSection->learningModule()->update([
            'course_section_id' => $hiddenSection->id,
            'sort_order' => 0,
        ]);

        // Visible section with hidden module
        $sectionWithHiddenModule = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Section With Hidden Module',
            'sort_order' => 2,
            'visible' => true,
        ]);

        $regularModule = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Regular Module',
        ]);
        $regularModule->learningModule()->update([
            'course_section_id' => $sectionWithHiddenModule->id,
            'sort_order' => 0,
        ]);

        $hiddenModule = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Hidden Module',
        ]);
        $hiddenModule->learningModule()->update([
            'course_section_id' => $sectionWithHiddenModule->id,
            'sort_order' => 1,
            'visible' => false,
        ]);

        // Request as instructor
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $sections = $response->json('data.sections');

        // Instructor sees all 3 sections
        $this->assertCount(3, $sections);

        // Section 0: Visible Section - should have 1 module
        $this->assertEquals('Visible Section', $sections[0]['title']);
        $this->assertCount(1, $sections[0]['modules']);

        // Section 1: Hidden Section - instructor sees it
        $this->assertEquals('Hidden Section', $sections[1]['title']);
        $this->assertCount(1, $sections[1]['modules']);
        $this->assertEquals('Module in Hidden Section', $sections[1]['modules'][0]['activity']['title']);

        // Section 2: Section With Hidden Module - should have 2 modules (including hidden)
        $this->assertEquals('Section With Hidden Module', $sections[2]['title']);
        $this->assertCount(2, $sections[2]['modules']);
        $this->assertEquals('Regular Module', $sections[2]['modules'][0]['activity']['title']);
        $this->assertEquals('Hidden Module', $sections[2]['modules'][1]['activity']['title']);
        $this->assertFalse($sections[2]['modules'][1]['visible']);
    }

    #[Test]
    public function course_structure_returns_proper_activity_summaries(): void
    {
        $section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'All Activities',
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Material
        $material = Material::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'PDF Material',
            'file_size' => 2048,
            'mime_type' => 'application/pdf',
            'revision' => 2,
        ]);
        $material->learningModule()->update([
            'course_section_id' => $section->id,
            'sort_order' => 0,
        ]);

        // Quiz
        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Knowledge Check',
            'time_limit' => 30,
            'max_attempts' => 3,
            'passing_score' => 70.0,
            'available_from' => null,
            'available_until' => null,
        ]);
        $quiz->learningModule()->update([
            'course_section_id' => $section->id,
            'sort_order' => 1,
        ]);

        // Assignment
        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Report Submission',
            'due_date' => now()->addDays(7),
            'cutoff_date' => now()->addDays(14),
            'max_score' => 100,
            'max_attempts' => 1,
        ]);
        $assignment->learningModule()->update([
            'course_section_id' => $section->id,
            'sort_order' => 2,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $sections = $response->json('data.sections');

        $this->assertCount(1, $sections);
        $modules = $sections[0]['modules'];
        $this->assertCount(3, $modules);

        // Material summary
        $materialSummary = $modules[0]['activity'];
        $this->assertEquals('material', $materialSummary['type']);
        $this->assertEquals($material->id, $materialSummary['id']);
        $this->assertEquals('PDF Material', $materialSummary['title']);
        $this->assertEquals(2048, $materialSummary['file_size']);
        $this->assertEquals('application/pdf', $materialSummary['mime_type']);
        $this->assertEquals(2, $materialSummary['revision']);

        // Quiz summary
        $quizSummary = $modules[1]['activity'];
        $this->assertEquals('quiz', $quizSummary['type']);
        $this->assertEquals($quiz->id, $quizSummary['id']);
        $this->assertEquals('Knowledge Check', $quizSummary['title']);
        $this->assertEquals(30, $quizSummary['time_limit']);
        $this->assertEquals(3, $quizSummary['max_attempts']);
        $this->assertEquals(70.0, $quizSummary['passing_score']);
        $this->assertArrayHasKey('attempt_count', $quizSummary);
        $this->assertEquals(0, $quizSummary['attempt_count']);

        // Assignment summary
        $assignmentSummary = $modules[2]['activity'];
        $this->assertEquals('assignment', $assignmentSummary['type']);
        $this->assertEquals($assignment->id, $assignmentSummary['id']);
        $this->assertEquals('Report Submission', $assignmentSummary['title']);
        $this->assertEquals($assignment->due_date->toISOString(), $assignmentSummary['due_date']);
        $this->assertEquals($assignment->cutoff_date->toISOString(), $assignmentSummary['cutoff_date']);
        $this->assertEquals(100, $assignmentSummary['max_score']);
        $this->assertEquals(1, $assignmentSummary['max_attempts']);
        $this->assertArrayHasKey('submission_status', $assignmentSummary);
        $this->assertNull($assignmentSummary['submission_status']);
    }

    #[Test]
    public function nonexistent_course_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson('/api/courses/99999/structure');

        $response->assertStatus(404);
    }

    #[Test]
    public function student_sees_attempt_count_for_quiz(): void
    {
        $section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Quiz Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Scored Quiz',
            'max_attempts' => 5,
        ]);
        $quiz->learningModule()->update([
            'course_section_id' => $section->id,
            'sort_order' => 0,
        ]);

        // Student makes 2 attempts with explicit attempt numbers to avoid unique constraint
        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->student->id,
            'attempt_number' => 1,
            'completed_at' => now(),
            'status' => 'finished',
        ]);

        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->student->id,
            'attempt_number' => 2,
            'completed_at' => now(),
            'status' => 'finished',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');

        $this->assertCount(1, $modules);
        $this->assertEquals('quiz', $modules[0]['module_type']);
        $this->assertArrayHasKey('attempt_count', $modules[0]['activity']);
        $this->assertEquals(2, $modules[0]['activity']['attempt_count']);
    }

    #[Test]
    public function instructor_does_not_get_attempt_count_for_quiz(): void
    {
        $section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Quiz Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        $quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Instructor Quiz',
        ]);
        $quiz->learningModule()->update([
            'course_section_id' => $section->id,
            'sort_order' => 0,
        ]);

        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->student->id,
            'attempt_number' => 1,
            'completed_at' => now(),
            'status' => 'finished',
        ]);

        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->student->id,
            'attempt_number' => 2,
            'completed_at' => now(),
            'status' => 'finished',
        ]);

        // Request as instructor
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertOk();
        $modules = $response->json('data.sections.0.modules');

        $this->assertCount(1, $modules);
        $this->assertEquals('quiz', $modules[0]['module_type']);
        // Instructor should not have attempt_count in their response
        $this->assertArrayNotHasKey('attempt_count', $modules[0]['activity']);
    }

    #[Test]
    public function missing_actor_header_returns_401(): void
    {
        // Create a section so the route exists
        CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Test',
            'sort_order' => 0,
            'visible' => true,
        ]);

        $response = $this->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    #[Test]
    public function invalid_actor_id_returns_401(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', 999999)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    // ─── Course Category Tests (Plan 005) ─────────────────────────────────────

    #[Test]
    public function course_category_path_and_depth_are_maintained(): void
    {
        $root = CourseCategory::factory()->create([
            'name' => 'Root Category',
            'depth' => 0,
            'path' => null,
        ]);

        $child = CourseCategory::factory()->create([
            'name' => 'Child Category',
            'parent_id' => $root->id,
            'depth' => 1,
            'path' => (string) $root->id,
        ]);

        $grandchild = CourseCategory::factory()->create([
            'name' => 'Grandchild Category',
            'parent_id' => $child->id,
            'depth' => 2,
            'path' => $root->id.'/'.$child->id,
        ]);

        $this->assertDatabaseHas('course_categories', [
            'id' => $root->id,
            'depth' => 0,
            'path' => null,
        ]);
        $this->assertDatabaseHas('course_categories', [
            'id' => $child->id,
            'depth' => 1,
            'path' => (string) $root->id,
        ]);
        $this->assertDatabaseHas('course_categories', [
            'id' => $grandchild->id,
            'depth' => 2,
            'path' => $root->id.'/'.$child->id,
        ]);

        // Parent relationship works
        $this->assertEquals($root->id, $child->parent->id);
        $this->assertEquals($child->id, $grandchild->parent->id);
    }

    #[Test]
    public function course_category_model_relationships(): void
    {
        $root = CourseCategory::factory()->create(['name' => 'Root', 'visible' => true]);
        $child = CourseCategory::factory()->create([
            'name' => 'Child',
            'parent_id' => $root->id,
            'depth' => 1,
        ]);

        $course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
            'course_category_id' => $child->id,
        ]);

        // Category → courses
        $child->refresh();
        $this->assertTrue($child->courses->contains('id', $course->id));

        // Course → category
        $course->refresh();
        $this->assertEquals($child->id, $course->category->id);

        // Parent → children
        $root->refresh();
        $this->assertTrue($root->children->contains('id', $child->id));

        // Child → parent
        $this->assertEquals($root->id, $child->parent->id);
    }

    // ─── Course Grouping Tests (Plan 005) ──────────────────────────────────────

    #[Test]
    public function grouping_membership_grants_access_to_grouping_restricted_module(): void
    {
        $groupA = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Group A',
        ]);
        $groupB = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Group B',
        ]);

        $grouping = CourseGrouping::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Lab Sections',
        ]);

        // Only group A is in the grouping
        CourseGroupingGroup::factory()->create([
            'course_grouping_id' => $grouping->id,
            'course_group_id' => $groupA->id,
        ]);

        // Module restricted by grouping membership (uses course_grouping_id)
        $section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Test Section',
            'sort_order' => 0,
            'visible' => true,
        ]);
        $material = Material::factory()->create(['course_id' => $this->course->id]);
        $module = $material->learningModule;
        $module->update(['course_section_id' => $section->id, 'sort_order' => 0]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_grouping_id' => $grouping->id,
        ]);

        // Student not in any group → unavailable
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}");
        $response->assertStatus(404);

        // Add student to group A (which is inside the grouping) → available
        CourseGroupMember::factory()->create([
            'course_group_id' => $groupA->id,
            'user_id' => $this->student->id,
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}");
        $response->assertStatus(200);

        // Switch student to group B → unavailable (group B not in grouping)
        CourseGroupMember::where('user_id', $this->student->id)->delete();
        CourseGroupMember::factory()->create([
            'course_group_id' => $groupB->id,
            'user_id' => $this->student->id,
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}");
        $response->assertStatus(404);
    }

    #[Test]
    public function grouping_rule_grants_access_to_any_group_in_grouping(): void
    {
        $groupA = CourseGroup::factory()->create(['course_id' => $this->course->id]);
        $groupB = CourseGroup::factory()->create(['course_id' => $this->course->id]);

        $grouping = CourseGrouping::factory()->create([
            'course_id' => $this->course->id,
            'active' => true,
        ]);

        // Both groups are in the grouping
        CourseGroupingGroup::factory()->create([
            'course_grouping_id' => $grouping->id,
            'course_group_id' => $groupA->id,
        ]);
        CourseGroupingGroup::factory()->create([
            'course_grouping_id' => $grouping->id,
            'course_group_id' => $groupB->id,
        ]);

        $material = Material::factory()->create(['course_id' => $this->course->id]);
        $module = $material->learningModule;

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_grouping_id' => $grouping->id,
        ]);

        // Student in group A → available
        CourseGroupMember::factory()->create([
            'course_group_id' => $groupA->id,
            'user_id' => $this->student->id,
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}");
        $response->assertStatus(200);

        // Move student to group B → still available (group B also in grouping)
        CourseGroupMember::where('user_id', $this->student->id)->delete();
        CourseGroupMember::factory()->create([
            'course_group_id' => $groupB->id,
            'user_id' => $this->student->id,
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}");
        $response->assertStatus(200);
    }

    #[Test]
    public function inactive_grouping_does_not_grant_access(): void
    {
        $groupA = CourseGroup::factory()->create(['course_id' => $this->course->id]);

        $grouping = CourseGrouping::factory()->create([
            'course_id' => $this->course->id,
            'active' => false,  // Inactive
        ]);

        CourseGroupingGroup::factory()->create([
            'course_grouping_id' => $grouping->id,
            'course_group_id' => $groupA->id,
        ]);

        $material = Material::factory()->create(['course_id' => $this->course->id]);
        $module = $material->learningModule;

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_grouping_id' => $grouping->id,
        ]);

        // Student in group A (which is in the grouping, but grouping is inactive)
        CourseGroupMember::factory()->create([
            'course_group_id' => $groupA->id,
            'user_id' => $this->student->id,
        ]);
        Cache::flush();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/materials/{$material->id}");
        $response->assertStatus(404);
    }

    #[Test]
    public function grouping_model_relationships(): void
    {
        $group1 = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $group2 = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
        ]);

        $grouping = CourseGrouping::factory()->create([
            'course_id' => $this->course->id,
        ]);

        CourseGroupingGroup::factory()->create([
            'course_grouping_id' => $grouping->id,
            'course_group_id' => $group1->id,
        ]);
        CourseGroupingGroup::factory()->create([
            'course_grouping_id' => $grouping->id,
            'course_group_id' => $group2->id,
        ]);

        // Reload with relationships
        $grouping->load('groups');
        $this->assertCount(2, $grouping->groups);
        $this->assertTrue($grouping->groups->contains('id', $group1->id));
        $this->assertTrue($grouping->groups->contains('id', $group2->id));

        // Course relationship
        $this->assertEquals($this->course->id, $grouping->course_id);
    }
}
