<?php

namespace Tests\Feature\Api;

use App\Models\Capability;
use App\Models\Context;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseEnrolmentMethod;
use App\Models\CourseGroup;
use App\Models\CourseGroupMember;
use App\Models\CourseSection;
use App\Models\LearningModule;
use App\Models\ModuleAvailabilityRule;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleCapability;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationBatchParityTest extends TestCase
{
    use DatabaseTransactions;

    private User $instructor;

    private User $student;

    private Course $course;

    private CourseSection $section;

    private Capability $ignoreAvailabilityCap;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

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

        $this->section = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Test Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Ensure the capability exists
        $this->ignoreAvailabilityCap = Capability::query()
            ->firstOrCreate(['shortname' => 'module:ignore-availability']);
    }

    /**
     * Helper: create a hidden module with availability rules.
     */
    private function createHiddenModule(int $sortOrder = 0): LearningModule
    {
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => $sortOrder,
            'visible' => false,
        ]);

        return $module;
    }

    /**
     * Helper: create a visible module with group availability rule.
     */
    private function createGroupRestrictedModule(CourseGroup $group, int $sortOrder = 0): LearningModule
    {
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => $sortOrder,
            'visible' => true,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        $module->load('availabilityRules');

        return $module;
    }

    /**
     * Helper: grant module:ignore-availability at a specific context.
     */
    private function grantBypassAtContext(User $user, Context $context, string $roleShortname = 'instructor'): void
    {
        $role = Role::query()->firstOrCreate(
            ['shortname' => $roleShortname],
            ['name' => ucfirst($roleShortname)]
        );

        RoleCapability::query()->firstOrCreate([
            'role_id' => $role->id,
            'capability_id' => $this->ignoreAvailabilityCap->id,
        ]);

        RoleAssignment::query()->firstOrCreate([
            'role_id' => $role->id,
            'context_id' => $context->id,
            'user_id' => $user->id,
        ]);

        // Clear auth caches
        $this->clearAuthCache();
    }

    private function clearAuthCache(): void
    {
        Cache::flush();
    }

    /**
     * Parity: Module context grants module:ignore-availability.
     * Both canReadModule() and readableModulesFor() should return true for a hidden module.
     */
    #[Test]
    public function module_context_bypass_parity(): void
    {
        $module = $this->createHiddenModule(0);
        $moduleContext = \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        $this->grantBypassAtContext($this->student, $moduleContext);

        // Reload module to clear any cached relations
        $module->refresh();
        $module->load('availabilityRules', 'course');

        $singleResult = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $batchResult = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should return true with module context bypass');
        $this->assertTrue($batchResult, 'readableModulesFor should return true with module context bypass');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for module context bypass');
    }

    /**
     * Parity: Course context grants module:ignore-availability (inherited by module).
     * Both should return true for a hidden module.
     */
    #[Test]
    public function course_context_inherited_bypass_parity(): void
    {
        $module = $this->createHiddenModule(0);

        // Ensure module context exists (so the batch helper doesn't take the fallback path)
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        $courseContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $this->course->id);

        // Grant bypass at course context, not module context
        $this->grantBypassAtContext($this->student, $courseContext);

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $singleResult = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $batchResult = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should return true with inherited course bypass');
        $this->assertTrue($batchResult, 'readableModulesFor should return true with inherited course bypass');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for course context bypass');
    }

    /**
     * Parity: System context grants module:ignore-availability (inherited down to module).
     * Both should return true for a hidden module.
     */
    #[Test]
    public function system_context_inherited_bypass_parity(): void
    {
        $module = $this->createHiddenModule(0);

        // Ensure module context exists
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        // Ensure course context exists
        $courseContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $this->course->id);

        // Ensure system context exists
        $systemContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_SYSTEM, 1);

        // Grant bypass at system context only
        $this->grantBypassAtContext($this->student, $systemContext);

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $singleResult = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $batchResult = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should return true with inherited system bypass');
        $this->assertTrue($batchResult, 'readableModulesFor should return true with inherited system bypass');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for system context bypass');
    }

    /**
     * Parity: No module context, no course context, legacy instructor fallback.
     * canReadModule() returns true for instructors when both contexts are absent.
     */
    #[Test]
    public function no_module_context_no_course_context_instructor_fallback_parity(): void
    {
        // Create module WITHOUT a module context
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => false,
        ]);

        // Ensure NO module context exists
        \App\Models\Context::query()
            ->where('contextlevel', Context::LEVEL_MODULE)
            ->where('instance_id', $module->id)
            ->delete();

        // Ensure NO course context exists
        \App\Models\Context::query()
            ->where('contextlevel', Context::LEVEL_COURSE)
            ->where('instance_id', $this->course->id)
            ->delete();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        // Test as instructor — legacy fallback should grant bypass
        $singleResult = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->instructor, $module);

        $batchResult = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->instructor, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should return true for instructor fallback');
        $this->assertTrue($batchResult, 'readableModulesFor should return true for instructor fallback');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for instructor fallback');

        // Test as student — no bypass, no instructor role
        $singleStudent = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $batchStudent = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertFalse($singleStudent, 'canReadModule should return false for student without bypass');
        $this->assertFalse($batchStudent, 'readableModulesFor should return false for student without bypass');
        $this->assertEquals($singleStudent, $batchStudent, 'Batch and single must match for student no-bypass');
    }

    /**
     * Parity: Hidden module with inherited bypass from course context.
     * The instructor sees it; a student without the bypass does not.
     */
    #[Test]
    public function hidden_module_with_and_without_bypass_parity(): void
    {
        $module = $this->createHiddenModule(0);

        // Ensure module context exists
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        $courseContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $this->course->id);

        // Grant bypass to instructor at course context
        $this->grantBypassAtContext($this->instructor, $courseContext, 'instructor');

        $module->refresh();
        $module->load('availabilityRules', 'course');

        // Instructor with bypass — should see the hidden module
        $instructorSingle = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->instructor, $module);

        $instructorBatch = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->instructor, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($instructorSingle, 'Instructor canReadModule with bypass');
        $this->assertTrue($instructorBatch, 'Instructor readableModulesFor with bypass');
        $this->assertEquals($instructorSingle, $instructorBatch, 'Batch/single match for instructor');

        // Student without bypass — should NOT see the hidden module
        $studentSingle = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $studentBatch = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertFalse($studentSingle, 'Student canReadModule without bypass');
        $this->assertFalse($studentBatch, 'Student readableModulesFor without bypass');
        $this->assertEquals($studentSingle, $studentBatch, 'Batch/single match for student');
    }

    /**
     * Parity: Group-restricted visible module.
     * Student in the group can see it; student outside the group cannot.
     */
    #[Test]
    public function group_restricted_module_parity(): void
    {
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'active' => true,
        ]);

        $module = $this->createGroupRestrictedModule($group, 0);

        // Student NOT in the group
        $singleNoGroup = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $batchNoGroup = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertFalse($singleNoGroup, 'canReadModule false for non-group member');
        $this->assertFalse($batchNoGroup, 'readableModulesFor false for non-group member');
        $this->assertEquals($singleNoGroup, $batchNoGroup, 'Batch/single match for non-group member');

        // Add student to the group
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->student->id,
        ]);

        // Clear caches so group membership is re-read
        $this->clearAuthCache();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $singleInGroup = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $batchInGroup = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleInGroup, 'canReadModule true for group member');
        $this->assertTrue($batchInGroup, 'readableModulesFor true for group member');
        $this->assertEquals($singleInGroup, $batchInGroup, 'Batch/single match for group member');
    }

    /**
     * Parity: Multiple modules with mixed bypass scenarios.
     * Verifies batch helper returns correct per-module results.
     */
    #[Test]
    public function mixed_modules_batch_parity(): void
    {
        // Do NOT create a course context — keeps canReadCourse on the enrollment path
        // Module 1: hidden, no bypass → should be false
        $module1 = $this->createHiddenModule(0);
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module1->id],
            ['path' => "/1/{$this->course->id}/{$module1->id}", 'depth' => 2]
        );

        // Module 2: hidden, with module-context bypass → should be true
        $module2 = $this->createHiddenModule(1);
        $module2Context = \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module2->id],
            ['path' => "/1/{$this->course->id}/{$module2->id}", 'depth' => 2]
        );
        $this->grantBypassAtContext($this->student, $module2Context);

        // Module 3: visible, no group rule → should be true
        $material3 = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module3 = $material3->learningModule;
        $module3->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 2,
            'visible' => true,
        ]);
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module3->id],
            ['path' => "/1/{$this->course->id}/{$module3->id}", 'depth' => 2]
        );

        // Reload all modules with relations
        foreach ([$module1, $module2, $module3] as $m) {
            $m->refresh();
            $m->load('availabilityRules', 'course');
        }

        $service = app(\App\Services\CourseAccessService::class);

        // Verify individual results match batch
        $modules = collect([$module1, $module2, $module3]);
        $batchResults = $service->readableModulesFor($this->student, $this->course, $modules);

        foreach ($modules as $module) {
            $single = $service->canReadModule($this->student, $module);
            $batch = $batchResults->get($module->id);
            $this->assertEquals(
                $single,
                $batch,
                "Mismatch for module {$module->id}: single={$single}, batch={$batch}"
            );
        }

        // Verify expected outcomes
        $this->assertFalse($batchResults->get($module1->id), 'Hidden module without bypass is not readable');
        $this->assertTrue($batchResults->get($module2->id), 'Hidden module with bypass is readable');
        $this->assertTrue($batchResults->get($module3->id), 'Visible module without rules is readable');
    }

    /**
     * Parity: Instructor role assigned at system context (inherited by course).
     * Direct canReadModule() returns true via userHasRoleAt ancestor walk.
     * Batch readableModulesFor() must agree.
     */
    #[Test]
    public function inherited_instructor_role_at_system_context_parity(): void
    {
        // Create a visible module with both module and course contexts
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);

        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        $courseContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $this->course->id);

        $systemContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_SYSTEM, 1);

        // Assign instructor role at system context only (not course context)
        $instructorRole = Role::query()->firstOrCreate(
            ['shortname' => 'instructor'],
            ['name' => 'Instructor']
        );

        RoleAssignment::query()->firstOrCreate([
            'role_id' => $instructorRole->id,
            'context_id' => $systemContext->id,
            'user_id' => $this->student->id,
        ]);

        $this->clearAuthCache();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        // Direct path: canReadCourse → isInstructorForCourse → userHasRoleAt walks ancestors
        $singleResult = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        // Batch path: deriveInstructorFromAssignments must also check ancestors
        $batchResult = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should return true with inherited instructor role at system context');
        $this->assertTrue($batchResult, 'readableModulesFor should return true with inherited instructor role at system context');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for inherited instructor role at system context');
    }

    /**
     * Parity: course:view capability granted through a role at system context.
     * Direct canReadModule() returns true via userHasCapabilityAt ancestor walk.
     * Batch readableModulesFor() must agree.
     */
    #[Test]
    public function inherited_course_view_at_system_context_parity(): void
    {
        // Create a visible module with both module and course contexts
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);

        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        $courseContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $this->course->id);

        $systemContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_SYSTEM, 1);

        // Create a role with course:view capability, assign at system context
        $courseViewCap = Capability::query()->firstOrCreate(
            ['shortname' => 'course:view'],
            ['name' => 'View Course']
        );

        $viewerRole = Role::query()->firstOrCreate(
            ['shortname' => 'course_viewer'],
            ['name' => 'Course Viewer', 'archetype' => 'course_viewer']
        );

        RoleCapability::query()->firstOrCreate([
            'role_id' => $viewerRole->id,
            'capability_id' => $courseViewCap->id,
        ]);

        RoleAssignment::query()->firstOrCreate([
            'role_id' => $viewerRole->id,
            'context_id' => $systemContext->id,
            'user_id' => $this->student->id,
        ]);

        $this->clearAuthCache();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        // Direct path: canReadCourse → userHasCapabilityAt walks ancestors
        $singleResult = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        // Batch path: deriveCourseReadableFromAssignments must also check ancestors
        $batchResult = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should return true with inherited course:view at system context');
        $this->assertTrue($batchResult, 'readableModulesFor should return true with inherited course:view at system context');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for inherited course:view at system context');
    }

    /**
     * Parity: No contexts exist (no module, course, or system context).
     * Student with active flat enrollment (from setUp) but no active enrolment method.
     *
     * canReadCourse() fallback calls isActiveEnrollee() which requires an
     * active enrolment method. readableModulesFor() fallback must also
     * check for an active enrolment method — not just raw flat enrollment.
     */
    #[Test]
    public function no_context_student_without_enrolment_method_parity(): void
    {
        // Create a visible module WITHOUT any contexts
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Delete ALL contexts AND enrolment methods so isActiveEnrollee() rejects
        \App\Models\Context::query()->delete();
        CourseEnrolmentMethod::query()->delete();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $service = app(\App\Services\CourseAccessService::class);

        $singleResult = $service->canReadModule($this->student, $module);
        $batchResult = $service->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        // Both should be false: isActiveEnrollee() rejects courses without active enrolment method
        $this->assertFalse($singleResult, 'canReadModule should return false for student w/o enrolment method');
        $this->assertFalse($batchResult, 'readableModulesFor should return false for student w/o enrolment method');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for student without enrolment method');
    }

    /**
     * Parity: No contexts exist. Flat-enrolled instructor who is NOT course.instructor_id.
     *
     * canReadModule() fallback calls isInstructorForCourse() which falls back
     * to flatInstructorCheck(). readableModulesFor() fallback must use the
     * same isInstructorForCourse() instead of only checking course.instructor_id.
     */
    #[Test]
    public function no_context_flat_instructor_fallback_parity(): void
    {
        // Create a flat-enrolled instructor who is NOT the course owner
        $flatInstructor = User::factory()->create(['role' => 'instructor']);

        CourseEnrollment::factory()->create([
            'user_id' => $flatInstructor->id,
            'course_id' => $this->course->id,
            'role' => 'instructor',
            'status' => 'active',
        ]);

        // Create a hidden module WITHOUT any contexts
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => false,
        ]);

        // Delete ALL contexts
        \App\Models\Context::query()->delete();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $service = app(\App\Services\CourseAccessService::class);

        // Flat instructor should get bypass via isInstructorForCourse fallback
        $singleResult = $service->canReadModule($flatInstructor, $module);
        $batchResult = $service->readableModulesFor($flatInstructor, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should return true for flat-enrolled instructor');
        $this->assertTrue($batchResult, 'readableModulesFor should return true for flat-enrolled instructor');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for flat instructor');
    }

    /**
     * Parity: No contexts exist. Mixed batch — one hidden module, one visible module.
     * Student with active flat enrollment (from setUp) but no active enrolment method.
     *
     * The hidden module is not bypassable (student, not instructor).
     * The visible module is blocked because canReadCourse returns false
     * (no active enrolment method).
     */
    #[Test]
    public function no_context_mixed_batch_parity(): void
    {
        // Delete ALL contexts AND enrolment methods so isActiveEnrollee() rejects
        \App\Models\Context::query()->delete();
        CourseEnrolmentMethod::query()->delete();

        // Module 1: hidden, no bypass
        $material1 = \App\Models\Material::factory()->create(['course_id' => $this->course->id]);
        $module1 = $material1->learningModule;
        $module1->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => false,
        ]);

        // Module 2: visible, no rules
        $material2 = \App\Models\Material::factory()->create(['course_id' => $this->course->id]);
        $module2 = $material2->learningModule;
        $module2->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 1,
            'visible' => true,
        ]);

        foreach ([$module1, $module2] as $m) {
            $m->refresh();
            $m->load('availabilityRules', 'course');
        }

        $service = app(\App\Services\CourseAccessService::class);
        $modules = collect([$module1, $module2]);
        $batchResults = $service->readableModulesFor($this->student, $this->course, $modules);

        foreach ($modules as $module) {
            $single = $service->canReadModule($this->student, $module);
            $batch = $batchResults->get($module->id);
            $this->assertEquals(
                $single,
                $batch,
                "Mismatch for module {$module->id}: single={$single}, batch={$batch}"
            );
        }

        // Both should be false: no bypass for hidden, and canReadCourse fails
        $this->assertFalse($batchResults->get($module1->id), 'Hidden module false for student w/o bypass');
        $this->assertFalse($batchResults->get($module2->id), 'Visible module false when canReadCourse fails');
    }

    /**
     * Verify that readableModulesFor() does not issue N+1 ancestor queries
     * when module contexts exist. With 10 modules each having a context,
     * the old per-context ancestors() approach would issue 10 queries.
     * The batched approach should use ≤ 3 queries (contexts, capability/roles, assignments).
     */
    #[Test]
    public function readable_modules_does_not_n1_on_ancestor_queries(): void
    {
        // Ensure course context and system context exist
        $courseContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $this->course->id);
        app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_SYSTEM, 1);

        // Create 10 modules, each with a module context
        $modules = collect();
        for ($i = 0; $i < 10; $i++) {
            $material = \App\Models\Material::factory()->create([
                'course_id' => $this->course->id,
            ]);
            $module = $material->learningModule;
            $module->update([
                'course_section_id' => $this->section->id,
                'sort_order' => $i,
                'visible' => true,
            ]);
            \App\Models\Context::query()->firstOrCreate(
                ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
                ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
            );
            $module->refresh();
            $module->load('availabilityRules', 'course');
            $modules->push($module);
        }

        // Warm any caches
        app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, $modules);

        \Illuminate\Support\Facades\Cache::flush();

        $queryCount = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, $modules);

        // With 10 module contexts, ancestor resolution should use batch queries
        // not per-context queries. Threshold: ≤ 8 queries total.
        $this->assertLessThanOrEqual(
            8,
            $queryCount,
            "readableModulesFor with 10 module contexts used {$queryCount} queries, expected ≤ 8 (batched ancestors)"
        );
    }

    /**
     * Parity: Module context exists, course context deleted, active flat student
     * enrollment, no active enrolment method.
     *
     * The batch path enters the "contexts exist" branch (module contexts are
     * present), but deriveCourseReadableFromAssignments() returns raw
     * hasFlatEnrolment when courseContext is null — which doesn't check for
     * an active enrolment method like isActiveEnrollee() does.
     *
     * Both paths must deny because isActiveEnrollee() returns false when
     * no active enrolment method exists.
     */
    #[Test]
    public function partial_context_student_without_enrolment_method_parity(): void
    {
        // Create visible module with module context
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Create module context
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        // Delete course context so isActiveEnrollee() fallback is used
        \App\Models\Context::query()
            ->where('contextlevel', Context::LEVEL_COURSE)
            ->where('instance_id', $this->course->id)
            ->delete();

        // Delete all enrolment methods so isActiveEnrollee() returns false
        CourseEnrolmentMethod::query()->delete();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $service = app(\App\Services\CourseAccessService::class);

        $singleResult = $service->canReadModule($this->student, $module);
        $batchResult = $service->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        // Both must deny: isActiveEnrollee() requires active enrolment method
        $this->assertFalse($singleResult, 'canReadModule should deny student without enrolment method');
        $this->assertFalse($batchResult, 'readableModulesFor should deny student without enrolment method');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for partial-context student without enrolment method');
    }

    /**
     * Parity: Module context exists, course context deleted, flat instructor
     * enrollment, actor is not course.instructor_id.
     *
     * The batch path enters the "contexts exist" branch (module contexts are
     * present), but deriveInstructorFromAssignments() returns false when
     * courseContext is null (except course owner). Meanwhile
     * deriveCourseReadableFromAssignments() also returns false because
     * $hasFlatEnrolment only checks student enrollment.
     *
     * The direct path: isInstructorForCourse() falls back to
     * flatInstructorCheck() when course context is null.
     *
     * Both paths must allow.
     */
    #[Test]
    public function partial_context_flat_instructor_parity(): void
    {
        // Create a flat-enrolled instructor who is NOT the course owner
        $flatInstructor = User::factory()->create(['role' => 'instructor']);

        CourseEnrollment::factory()->create([
            'user_id' => $flatInstructor->id,
            'course_id' => $this->course->id,
            'role' => 'instructor',
            'status' => 'active',
        ]);

        // Create visible module with module context
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Create module context
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        // Delete course context so isInstructorForCourse() fallback is used
        \App\Models\Context::query()
            ->where('contextlevel', Context::LEVEL_COURSE)
            ->where('instance_id', $this->course->id)
            ->delete();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $service = app(\App\Services\CourseAccessService::class);

        $singleResult = $service->canReadModule($flatInstructor, $module);
        $batchResult = $service->readableModulesFor($flatInstructor, $this->course, collect([$module]))
            ->get($module->id);

        // Both must allow: isInstructorForCourse() falls back to flatInstructorCheck()
        $this->assertTrue($singleResult, 'canReadModule should allow flat-enrolled instructor');
        $this->assertTrue($batchResult, 'readableModulesFor should allow flat-enrolled instructor');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for partial-context flat instructor');
    }

    /**
     * Parity: Hidden module, module context exists, course context deleted,
     * flat instructor enrollment.
     *
     * When a module context exists, canReadModule() checks
     * module:ignore-availability at that context first — it does NOT fall
     * back to instructor bypass when a module context is present. The flat
     * instructor enrollment doesn't grant bypass because the module context
     * exists and has no bypass capability assigned.
     *
     * Both paths must deny (no bypass capability).
     */
    #[Test]
    public function partial_context_hidden_module_flat_instructor_parity(): void
    {
        // Create a flat-enrolled instructor who is NOT the course owner
        $flatInstructor = User::factory()->create(['role' => 'instructor']);

        CourseEnrollment::factory()->create([
            'user_id' => $flatInstructor->id,
            'course_id' => $this->course->id,
            'role' => 'instructor',
            'status' => 'active',
        ]);

        // Create hidden module with module context
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => false,
        ]);

        // Create module context
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        // Delete course context
        \App\Models\Context::query()
            ->where('contextlevel', Context::LEVEL_COURSE)
            ->where('instance_id', $this->course->id)
            ->delete();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $service = app(\App\Services\CourseAccessService::class);

        $singleResult = $service->canReadModule($flatInstructor, $module);
        $batchResult = $service->readableModulesFor($flatInstructor, $this->course, collect([$module]))
            ->get($module->id);

        // Both must deny: module context exists, no bypass capability assigned
        $this->assertFalse($singleResult, 'canReadModule should deny hidden module (no bypass cap at module context)');
        $this->assertFalse($batchResult, 'readableModulesFor should deny hidden module (no bypass cap at module context)');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for partial-context hidden module');
    }

    /**
     * Parity: Course owner, course context exists, visible module,
     * no course:view capability assignment (owner has no roles).
     *
     * canReadCourse() with course context checks userHasCapabilityAt('course:view')
     * — it does NOT check course owner. readableModulesFor() must not grant
     * via the $isInstructor shortcut when course context exists.
     *
     * Both paths must deny.
     */
    #[Test]
    public function course_owner_without_course_view_when_context_exists_parity(): void
    {
        // Create a user who is the course owner but has NO role assignments
        $owner = User::factory()->create();
        $course = Course::factory()->create([
            'instructor_id' => $owner->id,
            'is_active' => true,
        ]);

        $section = CourseSection::factory()->create([
            'course_id' => $course->id,
            'title' => 'Test Section',
            'sort_order' => 0,
            'visible' => true,
        ]);

        // Create course context
        app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $course->id);

        // Create visible module with module context
        $material = \App\Models\Material::factory()->create([
            'course_id' => $course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$course->id}/{$module->id}", 'depth' => 2]
        );

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $this->clearAuthCache();

        $service = app(\App\Services\CourseAccessService::class);

        $singleResult = $service->canReadModule($owner, $module);
        $batchResult = $service->readableModulesFor($owner, $course, collect([$module]))
            ->get($module->id);

        // Both must deny: no course:view capability, course context present
        $this->assertFalse($singleResult, 'canReadModule should deny course owner without course:view cap');
        $this->assertFalse($batchResult, 'readableModulesFor should deny course owner without course:view cap');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for course owner without course:view');
    }

    /**
     * Parity: Inherited instructor role at system context, course context exists,
     * role does NOT grant course:view capability.
     *
     * canReadCourse() with course context checks userHasCapabilityAt('course:view')
     * — instructor status alone is insufficient. readableModulesFor() must not
     * grant via the $isInstructor shortcut when course context exists.
     *
     * Both paths must deny.
     */
    #[Test]
    public function inherited_role_without_course_view_when_context_exists_parity(): void
    {
        // Use a unique role that does NOT have course:view capability
        $noViewRole = Role::query()->create([
            'shortname' => 'instructor_no_view',
            'name' => 'Instructor Without View',
            'archetype' => 'teacher',
        ]);

        // Ensure course and system contexts exist
        app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_COURSE, $this->course->id);
        $systemContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_SYSTEM, 1);

        // Assign the no-view role at system context (inherited by course)
        RoleAssignment::query()->create([
            'role_id' => $noViewRole->id,
            'context_id' => $systemContext->id,
            'user_id' => $this->student->id,
        ]);

        // Create a visible module with module context
        $material = \App\Models\Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $material->learningModule;
        $module->update([
            'course_section_id' => $this->section->id,
            'sort_order' => 0,
            'visible' => true,
        ]);
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        $this->clearAuthCache();

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $service = app(\App\Services\CourseAccessService::class);

        $singleResult = $service->canReadModule($this->student, $module);
        $batchResult = $service->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        // Both must deny: no course:view capability
        $this->assertFalse($singleResult, 'canReadModule should deny inherited role without course:view');
        $this->assertFalse($batchResult, 'readableModulesFor should deny inherited role without course:view');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for inherited role without course:view');
    }

    /**
     * Parity: Module context exists, course context is MISSING, bypass granted at
     * system context (module ancestor). This exercises the edge case where
     * deriveBypassModulesFromAssignments() must walk each module context's path
     * ancestors, not just the course context path.
     *
     * Both must return true (bypass inherits through module → course → system chain).
     */
    #[Test]
    public function partial_context_system_inherited_bypass_parity(): void
    {
        $module = $this->createHiddenModule(0);

        // Create module context
        \App\Models\Context::query()->firstOrCreate(
            ['contextlevel' => Context::LEVEL_MODULE, 'instance_id' => $module->id],
            ['path' => "/1/{$this->course->id}/{$module->id}", 'depth' => 2]
        );

        // Create system context (needed for ancestor walking)
        $systemContext = app(\App\Services\ContextService::class)
            ->resolveOrCreate(Context::LEVEL_SYSTEM, 1);

        // Delete course context — this is the crux: $courseContext will be null
        \App\Models\Context::query()
            ->where('contextlevel', Context::LEVEL_COURSE)
            ->where('instance_id', $this->course->id)
            ->delete();

        // Grant bypass at system context only
        $this->grantBypassAtContext($this->student, $systemContext);

        $module->refresh();
        $module->load('availabilityRules', 'course');

        $singleResult = app(\App\Services\CourseAccessService::class)
            ->canReadModule($this->student, $module);

        $batchResult = app(\App\Services\CourseAccessService::class)
            ->readableModulesFor($this->student, $this->course, collect([$module]))
            ->get($module->id);

        $this->assertTrue($singleResult, 'canReadModule should detect system-level bypass through module ancestor walk');
        $this->assertTrue($batchResult, 'readableModulesFor should detect system-level bypass through module ancestor walk');
        $this->assertEquals($singleResult, $batchResult, 'Batch and single must match for partial-context system inherited bypass');
    }
}
