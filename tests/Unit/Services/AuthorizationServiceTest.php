<?php

namespace Tests\Unit\Services;

use App\Models\Context;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\ContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $authService;

    private ContextService $contextService;

    private User $student;

    private User $instructor;

    private User $manager;

    private Context $courseContext;

    private Context $moduleContext;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed base data (roles + system context)
        $this->seedBaseData();

        $this->contextService = new ContextService;
        $this->authService = new AuthorizationService($this->contextService);

        // Create users
        $this->student = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->manager = User::factory()->create(['role' => 'instructor']);

        // Create context hierarchy
        $system = $this->contextService->resolveOrCreate(Context::LEVEL_SYSTEM, 0);
        $this->courseContext = $this->contextService->resolveOrCreate(
            Context::LEVEL_COURSE, 1, $system->id
        );
        $this->moduleContext = $this->contextService->resolveOrCreate(
            Context::LEVEL_MODULE, 1, $this->courseContext->id
        );

        // Assign roles
        $studentRole = Role::where('shortname', 'student')->first();
        $instructorRole = Role::where('shortname', 'instructor')->first();
        $managerRole = Role::where('shortname', 'manager')->first();

        $this->authService->assignRole($this->student, $studentRole, $this->courseContext);
        $this->authService->assignRole($this->instructor, $instructorRole, $this->courseContext);
        $this->authService->assignRole($this->manager, $managerRole, $system);
    }

    /**
     * Seed the base roles and system context if not already present.
     * This is needed because RefreshDatabase with MySQL uses transactions,
     * so migration data is available but we need to handle fresh DB state.
     */
    private function seedBaseData(): void
    {
        if (Role::query()->count() === 0) {
            Role::query()->create([
                'name' => 'Manager',
                'shortname' => 'manager',
                'archetype' => 'manager',
            ]);
            Role::query()->create([
                'name' => 'Instructor',
                'shortname' => 'instructor',
                'archetype' => 'teacher',
            ]);
            Role::query()->create([
                'name' => 'Student',
                'shortname' => 'student',
                'archetype' => 'student',
            ]);
        }

        if (Context::query()->count() === 0) {
            Context::query()->create([
                'contextlevel' => Context::LEVEL_SYSTEM,
                'instance_id' => 0,
                'path' => '/1',
                'depth' => 0,
            ]);
        }
    }

    public function test_user_has_role_at_exact_context(): void
    {
        $this->assertTrue(
            $this->authService->userHasRoleAtContext($this->student, 'student', $this->courseContext)
        );
    }

    public function test_user_has_not_role_at_unrelated_context(): void
    {
        $otherCourse = $this->contextService->resolveOrCreate(Context::LEVEL_COURSE, 999);

        $this->assertFalse(
            $this->authService->userHasRoleAtContext($this->student, 'student', $otherCourse)
        );
    }

    public function test_role_inherited_from_parent_context(): void
    {
        $this->assertTrue(
            $this->authService->userHasRoleAt($this->student, 'student', $this->moduleContext)
        );
    }

    public function test_role_not_inherited_from_sibling_context(): void
    {
        $otherCourse = $this->contextService->resolveOrCreate(Context::LEVEL_COURSE, 888);

        $this->assertFalse(
            $this->authService->userHasRoleAt($this->student, 'student', $otherCourse)
        );
    }

    public function test_role_not_inherited_from_child_context(): void
    {
        $system = $this->contextService->find(Context::LEVEL_SYSTEM, 0);

        $this->assertFalse(
            $this->authService->userHasRoleAtContext($this->student, 'student', $system)
        );
    }

    public function test_instructor_role_at_course_context(): void
    {
        $this->assertTrue(
            $this->authService->userHasRoleAt($this->instructor, 'instructor', $this->courseContext)
        );
    }

    public function test_manager_role_inherited_to_all_descendants(): void
    {
        $this->assertTrue(
            $this->authService->userHasRoleAt($this->manager, 'manager', $this->courseContext)
        );

        $this->assertTrue(
            $this->authService->userHasRoleAt($this->manager, 'manager', $this->moduleContext)
        );
    }

    public function test_assign_role_creates_assignment(): void
    {
        $newUser = User::factory()->create(['role' => 'student']);
        $studentRole = Role::where('shortname', 'student')->first();

        $assignment = $this->authService->assignRole(
            $newUser, $studentRole, $this->courseContext
        );

        $this->assertDatabaseHas('role_assignments', [
            'id' => $assignment->id,
            'role_id' => $studentRole->id,
            'context_id' => $this->courseContext->id,
            'user_id' => $newUser->id,
        ]);
    }

    public function test_remove_role_deletes_assignment(): void
    {
        $studentRole = Role::where('shortname', 'student')->first();

        $this->authService->removeRole($this->student, $studentRole, $this->courseContext);

        $this->assertDatabaseMissing('role_assignments', [
            'role_id' => $studentRole->id,
            'context_id' => $this->courseContext->id,
            'user_id' => $this->student->id,
        ]);
    }

    public function test_users_with_role_returns_correct_users(): void
    {
        $students = $this->authService->usersWithRole('student', $this->courseContext);

        $this->assertCount(1, $students);
        $this->assertEquals($this->student->id, $students->first()->id);
    }

    public function test_users_with_role_returns_all_users_without_context_filter(): void
    {
        $system = $this->contextService->find(Context::LEVEL_SYSTEM, 0);
        $anotherCourse = $this->contextService->resolveOrCreate(Context::LEVEL_COURSE, 777, $system->id);
        $anotherStudent = User::factory()->create(['role' => 'student']);
        $studentRole = Role::where('shortname', 'student')->first();
        $this->authService->assignRole($anotherStudent, $studentRole, $anotherCourse);

        $allStudents = $this->authService->usersWithRole('student');

        $this->assertCount(2, $allStudents);
    }
}
