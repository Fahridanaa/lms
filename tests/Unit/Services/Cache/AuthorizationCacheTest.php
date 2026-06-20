<?php

namespace Tests\Unit\Services\Cache;

use App\Models\Context;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Cache\NoCacheStrategy;
use App\Services\ContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationCacheTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $authService;

    private ContextService $contextService;

    private User $student;

    private User $instructor;

    private Context $courseContext;

    private Context $moduleContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBaseRolesAndContext();

        $this->contextService = new ContextService;
        $this->authService = new AuthorizationService($this->contextService);

        $system = $this->contextService->find(Context::LEVEL_SYSTEM, 0);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->courseContext = $this->contextService->resolveOrCreate(
            Context::LEVEL_COURSE, 1, $system->id
        );
        $this->moduleContext = $this->contextService->resolveOrCreate(
            Context::LEVEL_MODULE, 1, $this->courseContext->id
        );
    }

    private function seedBaseRolesAndContext(): void
    {
        if (Role::count() === 0) {
            Role::query()->create(['name' => 'Manager', 'shortname' => 'manager', 'archetype' => 'manager']);
            Role::query()->create(['name' => 'Instructor', 'shortname' => 'instructor', 'archetype' => 'teacher']);
            Role::query()->create(['name' => 'Student', 'shortname' => 'student', 'archetype' => 'student']);
        }

        if (Context::count() === 0) {
            Context::query()->create([
                'contextlevel' => Context::LEVEL_SYSTEM,
                'instance_id' => 0,
                'path' => '/1',
                'depth' => 0,
            ]);
        }
    }

    public function test_role_check_is_cached_after_first_lookup(): void
    {
        $studentRole = Role::where('shortname', 'student')->first();
        $this->authService->assignRole($this->student, $studentRole, $this->courseContext);

        // First call — should query the DB
        $queriesBefore = DB::table('role_assignments')->count();

        $result1 = $this->authService->userHasRoleAtContext(
            $this->student, 'student', $this->courseContext
        );

        $this->assertTrue($result1);

        // Second call — should return from cache, no additional queries
        DB::enableQueryLog();
        $result2 = $this->authService->userHasRoleAtContext(
            $this->student, 'student', $this->courseContext
        );

        $this->assertTrue($result2);

        $queries = DB::getQueryLog();
        $roleAssignmentQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'role_assignments');
        });

        $this->assertCount(0, $roleAssignmentQueries,
            'Second role check should not query role_assignments (cached)'
        );

        DB::disableQueryLog();
    }

    public function test_assigning_role_invalidates_cache(): void
    {
        $studentRole = Role::where('shortname', 'student')->first();

        // Warm cache
        $this->authService->userHasRoleAtContext($this->student, 'student', $this->courseContext);
        $this->assertFalse(
            $this->authService->userHasRoleAtContext($this->student, 'student', $this->courseContext)
        );

        // Assign role
        $this->authService->assignRole($this->student, $studentRole, $this->courseContext);

        // Should now return true (cache should have been invalidated)
        $this->assertTrue(
            $this->authService->userHasRoleAtContext($this->student, 'student', $this->courseContext)
        );
    }

    public function test_removing_role_invalidates_cache(): void
    {
        $studentRole = Role::where('shortname', 'student')->first();
        $this->authService->assignRole($this->student, $studentRole, $this->courseContext);

        // Warm cache
        $this->assertTrue(
            $this->authService->userHasRoleAtContext($this->student, 'student', $this->courseContext)
        );

        // Remove role
        $this->authService->removeRole($this->student, $studentRole, $this->courseContext);

        // Should now return false (cache should have been invalidated)
        $this->assertFalse(
            $this->authService->userHasRoleAtContext($this->student, 'student', $this->courseContext)
        );
    }

    public function test_inherited_role_check_is_cached(): void
    {
        $studentRole = Role::where('shortname', 'student')->first();
        $this->authService->assignRole($this->student, $studentRole, $this->courseContext);

        // First call checks module context with ancestor walk
        $result1 = $this->authService->userHasRoleAt($this->student, 'student', $this->moduleContext);
        $this->assertTrue($result1);

        // Enable query log for second call
        DB::enableQueryLog();
        $result2 = $this->authService->userHasRoleAt($this->student, 'student', $this->moduleContext);
        $this->assertTrue($result2);

        $queries = DB::getQueryLog();
        $roleAssignmentQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'role_assignments');
        });

        $this->assertCount(0, $roleAssignmentQueries,
            'Second inherited role check should not query role_assignments (cached)'
        );

        DB::disableQueryLog();
    }

    public function test_no_cache_strategy_does_not_write_role_check_to_laravel_cache(): void
    {
        Cache::flush();

        $studentRole = Role::where('shortname', 'student')->first();
        $service = new AuthorizationService($this->contextService, new NoCacheStrategy);

        $service->assignRole($this->student, $studentRole, $this->courseContext);

        $this->assertTrue(
            $service->userHasRoleAtContext($this->student, 'student', $this->courseContext)
        );
        $this->assertFalse(Cache::has("auth:role_check_exact:student:{$this->courseContext->id}:{$this->student->id}"));
        $this->assertFalse(Cache::has("lms:auth:role_check_exact:student:{$this->courseContext->id}:{$this->student->id}"));
    }
}
