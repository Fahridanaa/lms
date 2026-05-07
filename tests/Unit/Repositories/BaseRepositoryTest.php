<?php

namespace Tests\Unit\Repositories;

use App\Models\User;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BaseRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    protected BaseRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a concrete repository instance for testing
        // We test BaseRepository methods via a concrete implementation
        $this->repository = new class extends BaseRepository {
            protected string $modelClass = User::class;

            public function __construct()
            {
                $this->model = new User();
            }
        };
    }

    public function test_all_returns_collection(): void
    {
        User::factory()->count(3)->create();

        $results = $this->repository->all();

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertCount(3, $results);
    }

    public function test_find_returns_model(): void
    {
        $user = User::factory()->create();

        $found = $this->repository->find($user->id);

        $this->assertNotNull($found);
        $this->assertEquals($user->id, $found->id);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $found = $this->repository->find(99999);

        $this->assertNull($found);
    }

    public function test_find_or_fail_throws_exception_for_missing(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->repository->findOrFail(99999);
    }

    public function test_create_returns_model(): void
    {
        $user = $this->repository->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->assertNotNull($user);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_update_modifies_model(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $updated = $this->repository->update($user->id, ['name' => 'New Name']);

        $this->assertEquals('New Name', $updated->name);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_delete_removes_model(): void
    {
        $user = User::factory()->create();

        $result = $this->repository->delete($user->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_find_by_column(): void
    {
        $user = User::factory()->create(['email' => 'unique@example.com']);

        $found = $this->repository->findBy('email', 'unique@example.com');

        $this->assertNotNull($found);
        $this->assertEquals($user->id, $found->id);
    }

    public function test_where_returns_matching_records(): void
    {
        User::factory()->create(['role' => 'student']);
        User::factory()->count(2)->create(['role' => 'instructor']);

        $students = $this->repository->where('role', 'student');
        $instructors = $this->repository->where('role', 'instructor');

        $this->assertCount(1, $students);
        $this->assertCount(2, $instructors);
    }

    public function test_count_returns_correct_number(): void
    {
        User::factory()->count(5)->create();

        $count = $this->repository->count();

        $this->assertEquals(5, $count);
    }

    public function test_paginate_returns_paginated_results(): void
    {
        User::factory()->count(20)->create();

        $result = $this->repository->paginate(10);

        $this->assertCount(10, $result->items());
        $this->assertEquals(20, $result->total());
    }

    public function test_all_with_relations(): void
    {
        // User has no required relations, but we can test the method works
        $user = User::factory()->create();
        $results = $this->repository->all([]);

        $this->assertInstanceOf(Collection::class, $results);
    }

    public function test_force_delete_permanently_removes(): void
    {
        $user = User::factory()->create();

        $result = $this->repository->forceDelete($user->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_restore_throws_exception_for_non_soft_deleted_model(): void
    {
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not use SoftDeletes');
        $this->repository->restore($user->id);
    }
}
