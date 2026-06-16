<?php

namespace Tests\Unit\Services\Cache;

use App\Models\Capability;
use App\Models\Context;
use App\Models\Role;
use App\Models\RoleCapability;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\ContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CapabilityCacheTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $authService;

    private ContextService $contextService;

    private User $student;

    private User $instructor;

    private User $manager;

    private User $unrelatedUser;

    private Context $courseContext;

    private Context $moduleContext;

    private Role $studentRole;

    private Role $instructorRole;

    private Role $managerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBaseData();

        $this->contextService = new ContextService;
        $this->authService = new AuthorizationService($this->contextService);

        $this->student = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->manager = User::factory()->create(['role' => 'instructor']);
        $this->unrelatedUser = User::factory()->create(['role' => 'student']);

        $systemContext = $this->contextService->find(Context::LEVEL_SYSTEM, 0);
        $this->courseContext = $this->contextService->resolveOrCreate(
            Context::LEVEL_COURSE, 1, $systemContext->id
        );
        $this->moduleContext = $this->contextService->resolveOrCreate(
            Context::LEVEL_MODULE, 1, $this->courseContext->id
        );

        // Assign roles
        $this->authService->assignRole($this->student, $this->studentRole, $this->courseContext);
        $this->authService->assignRole($this->instructor, $this->instructorRole, $this->courseContext);
        // Manager assigned at system level (inherits everywhere)
        $this->authService->assignRole($this->manager, $this->managerRole, $systemContext);
    }

    private function seedBaseData(): void
    {
        Cache::flush();

        // Guard: roles already exist from the main migration
        if (Role::count() === 0) {
            Role::query()->create(['name' => 'Student', 'shortname' => 'student', 'archetype' => 'student']);
            Role::query()->create(['name' => 'Instructor', 'shortname' => 'instructor', 'archetype' => 'teacher']);
            Role::query()->create(['name' => 'Manager', 'shortname' => 'manager', 'archetype' => 'manager']);
        }

        $this->studentRole = Role::where('shortname', 'student')->first();
        $this->instructorRole = Role::where('shortname', 'instructor')->first();
        $this->managerRole = Role::where('shortname', 'manager')->first();

        // Seed capabilities if not already seeded
        if (Capability::count() === 0) {
            $capShortnames = [
                'course:view', 'module:view', 'module:ignore-availability',
                'quiz:view', 'quiz:attempt',
                'assignment:view', 'assignment:submit', 'assignment:grade',
                'gradebook:view', 'grade:update',
                'completion:view',
            ];

            $capabilities = [];
            foreach ($capShortnames as $shortname) {
                $capabilities[$shortname] = Capability::query()->create([
                    'name' => ucfirst(str_replace(':', ' ', $shortname)),
                    'shortname' => $shortname,
                ]);
            }

            // Manager gets all
            foreach ($capabilities as $cap) {
                RoleCapability::query()->create([
                    'role_id' => $this->managerRole->id,
                    'capability_id' => $cap->id,
                ]);
            }

            // Instructor gets all except quiz:attempt, assignment:submit
            $excludedForInstructor = ['quiz:attempt', 'assignment:submit'];
            foreach ($capabilities as $shortname => $cap) {
                if (in_array($shortname, $excludedForInstructor)) {
                    continue;
                }
                RoleCapability::query()->create([
                    'role_id' => $this->instructorRole->id,
                    'capability_id' => $cap->id,
                ]);
            }

            // Student gets limited set
            $studentCaps = [
                'course:view', 'module:view', 'quiz:view', 'quiz:attempt',
                'assignment:view', 'assignment:submit', 'completion:view',
            ];
            foreach ($studentCaps as $shortname) {
                RoleCapability::query()->create([
                    'role_id' => $this->studentRole->id,
                    'capability_id' => $capabilities[$shortname]->id,
                ]);
            }
        }

        // Create system context if not already present
        if (Context::count() === 0) {
            Context::query()->create([
                'contextlevel' => Context::LEVEL_SYSTEM,
                'instance_id' => 0,
                'path' => '/1',
                'depth' => 0,
            ]);
        }
    }

    /* ──────────────────────────────────────────────
     * Capability: student
     * ────────────────────────────────────────────── */

    public function test_student_has_course_view_at_course_context(): void
    {
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->courseContext)
        );
    }

    public function test_student_has_quiz_attempt_at_course_context(): void
    {
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->student, 'quiz:attempt', $this->courseContext)
        );
    }

    public function test_student_does_not_have_gradebook_view(): void
    {
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->student, 'gradebook:view', $this->courseContext)
        );
    }

    public function test_student_does_not_have_assignment_grade(): void
    {
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->student, 'assignment:grade', $this->courseContext)
        );
    }

    /* ──────────────────────────────────────────────
     * Capability: instructor
     * ────────────────────────────────────────────── */

    public function test_instructor_has_course_view_at_course_context(): void
    {
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->instructor, 'course:view', $this->courseContext)
        );
    }

    public function test_instructor_has_gradebook_view(): void
    {
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->instructor, 'gradebook:view', $this->courseContext)
        );
    }

    public function test_instructor_has_assignment_grade(): void
    {
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->instructor, 'assignment:grade', $this->courseContext)
        );
    }

    public function test_instructor_does_not_have_quiz_attempt(): void
    {
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->instructor, 'quiz:attempt', $this->courseContext)
        );
    }

    public function test_instructor_does_not_have_assignment_submit(): void
    {
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->instructor, 'assignment:submit', $this->courseContext)
        );
    }

    public function test_instructor_has_module_ignore_availability(): void
    {
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->instructor, 'module:ignore-availability', $this->courseContext)
        );
    }

    /* ──────────────────────────────────────────────
     * Capability: manager (has all)
     * ────────────────────────────────────────────── */

    public function test_manager_has_all_capabilities(): void
    {
        $allCapShortnames = [
            'course:view', 'module:view', 'module:ignore-availability',
            'quiz:view', 'quiz:attempt',
            'assignment:view', 'assignment:submit', 'assignment:grade',
            'gradebook:view', 'grade:update',
            'completion:view',
        ];

        foreach ($allCapShortnames as $shortname) {
            $this->assertTrue(
                $this->authService->userHasCapabilityAt($this->manager, $shortname, $this->courseContext),
                "Manager should have capability {$shortname} at course context"
            );
        }
    }

    /* ──────────────────────────────────────────────
     * Inherited capability resolution
     * ────────────────────────────────────────────── */

    public function test_inherited_course_role_grants_module_level_capability(): void
    {
        // Student has course:view at course context, should have it at module context too
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->moduleContext)
        );
    }

    public function test_inherited_instructor_capability_at_module_context(): void
    {
        // Instructor has module:ignore-availability at course context,
        // should have it at module context too via ancestor walk
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->instructor, 'module:ignore-availability', $this->moduleContext)
        );
    }

    public function test_student_does_not_inherit_gradebook_view_to_module_context(): void
    {
        // Student doesn't have gradebook:view anywhere
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->student, 'gradebook:view', $this->moduleContext)
        );
    }

    /* ──────────────────────────────────────────────
     * Missing capability / unrelated user
     * ────────────────────────────────────────────── */

    public function test_unrelated_user_has_no_capability_at_course_context(): void
    {
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->unrelatedUser, 'course:view', $this->courseContext)
        );
    }

    public function test_missing_capability_denies_even_if_user_has_unrelated_role(): void
    {
        // Student has a role at this context but lacks assignment:grade
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->student, 'assignment:grade', $this->courseContext)
        );
    }

    /* ──────────────────────────────────────────────
     * Exact context check (no ancestor walk)
     * ────────────────────────────────────────────── */

    public function test_exact_context_check_does_not_inherit(): void
    {
        // Student has no role at module context directly, only inherited from course
        $this->assertFalse(
            $this->authService->userHasCapabilityAtContext($this->student, 'course:view', $this->moduleContext)
        );
    }

    public function test_exact_context_check_works_at_assigned_context(): void
    {
        $this->assertTrue(
            $this->authService->userHasCapabilityAtContext($this->student, 'course:view', $this->courseContext)
        );
    }

    /* ──────────────────────────────────────────────
     * Cache behavior
     * ────────────────────────────────────────────── */

    public function test_capability_check_is_cached_after_first_lookup(): void
    {
        // Warm cache
        $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->courseContext);

        DB::enableQueryLog();

        $result = $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->courseContext);
        $this->assertTrue($result);

        $queries = DB::getQueryLog();
        $capabilityQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'role_capabilities')
                || str_contains($q['query'], 'capabilities')
                || str_contains($q['query'], 'role_assignments');
        });

        $this->assertCount(0, $capabilityQueries,
            'Second capability check should not query capabilities/role_capabilities/role_assignments (cached)'
        );

        DB::disableQueryLog();
    }

    public function test_exact_capability_check_is_cached(): void
    {
        // Warm cache
        $this->authService->userHasCapabilityAtContext($this->student, 'course:view', $this->courseContext);

        DB::enableQueryLog();

        $result = $this->authService->userHasCapabilityAtContext($this->student, 'course:view', $this->courseContext);
        $this->assertTrue($result);

        $queries = DB::getQueryLog();
        $capabilityQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'role_capabilities')
                || str_contains($q['query'], 'capabilities')
                || str_contains($q['query'], 'role_assignments');
        });

        $this->assertCount(0, $capabilityQueries,
            'Second exact capability check should not query (cached)'
        );

        DB::disableQueryLog();
    }

    public function test_role_assignment_invalidates_capability_cache(): void
    {
        // Warm capability cache — student doesn't have assignment:grade
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->student, 'assignment:grade', $this->courseContext)
        );

        // Now assign the instructor role to the student at this context
        $this->authService->assignRole($this->student, $this->instructorRole, $this->courseContext);

        // Capability cache should be invalidated — student now has assignment:grade
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->student, 'assignment:grade', $this->courseContext)
        );
    }

    public function test_role_removal_invalidates_capability_cache(): void
    {
        // Warm cache — student has course:view
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->courseContext)
        );

        // Remove the student role
        $this->authService->removeRole($this->student, $this->studentRole, $this->courseContext);

        // Capability cache should be invalidated — student no longer has course:view
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->courseContext)
        );
    }

    public function test_inherited_capability_cache_invalidated_by_role_change(): void
    {
        // Warm cache for inherited check (student has course:view at module context via inheritance)
        $this->assertTrue(
            $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->moduleContext)
        );

        // Remove the student role at the course context
        $this->authService->removeRole($this->student, $this->studentRole, $this->courseContext);

        // Inherited capability cache should be invalidated
        $this->assertFalse(
            $this->authService->userHasCapabilityAt($this->student, 'course:view', $this->moduleContext)
        );
    }
}
