<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseGroup;
use App\Models\CourseGroupMember;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\ModuleAvailabilityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MaterialControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected User $instructor;

    protected Course $course;

    protected Material $material;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create test data
        $this->user = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
        ]);
        $this->material = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);

        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_list_course_materials(): void
    {
        // Create additional materials for the course
        Material::factory()->count(3)->create([
            'course_id' => $this->course->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'course_id',
                        'title',
                        'file_path',
                        'file_size',
                        'type',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_can_show_material_detail(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/materials/{$this->material->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'course_id',
                    'title',
                    'file_path',
                    'file_size',
                    'type',
                    'created_at',
                    'updated_at',
                ],
            ]);
    }

    public function test_can_download_material(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/materials/{$this->material->id}/download");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'course_id',
                    'title',
                    'file_path',
                    'file_size',
                    'type',
                ],
            ]);
    }

    public function test_can_create_material(): void
    {
        $materialData = [
            'course_id' => $this->course->id,
            'title' => 'New Test Material',
            'file_path' => '/storage/materials/test.pdf',
            'file_size' => 1024000,
            'type' => 'pdf',
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->postJson('/api/materials', $materialData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'course_id',
                    'title',
                    'file_path',
                    'file_size',
                    'type',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('materials', [
            'title' => 'New Test Material',
            'course_id' => $this->course->id,
        ]);
    }

    public function test_can_update_material(): void
    {
        $updateData = [
            'title' => 'Updated Material Title',
            'type' => 'document',
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/materials/{$this->material->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'course_id',
                    'title',
                    'file_path',
                    'file_size',
                    'type',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('materials', [
            'id' => $this->material->id,
            'title' => 'Updated Material Title',
        ]);
    }

    public function test_can_delete_material(): void
    {
        $materialId = $this->material->id;

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->deleteJson("/api/materials/{$materialId}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Since using soft deletes, check deleted_at is set
        $this->assertSoftDeleted('materials', [
            'id' => $materialId,
        ]);
    }

    public function test_hidden_material_cannot_be_viewed(): void
    {
        $this->material->learningModule()->firstOrCreate([], [
            'course_id' => $this->material->course_id,
            'module_type' => 'material',
            'visible' => true,
            'sort_order' => $this->material->id,
        ])->update(['visible' => false]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/materials/{$this->material->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_create_material_validation_fails_without_required_fields(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->postJson('/api/materials', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['course_id', 'title', 'file_path', 'file_size', 'type']);
    }

    public function test_create_material_validation_fails_with_invalid_type(): void
    {
        $materialData = [
            'course_id' => $this->course->id,
            'title' => 'Test Material',
            'file_path' => '/storage/materials/test.pdf',
            'file_size' => 1024000,
            'type' => 'invalid_type',
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->postJson('/api/materials', $materialData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_create_material_validation_fails_with_file_size_exceeding_limit(): void
    {
        $materialData = [
            'course_id' => $this->course->id,
            'title' => 'Test Material',
            'file_path' => '/storage/materials/test.pdf',
            'file_size' => 104857601, // 100MB + 1 byte
            'type' => 'pdf',
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->postJson('/api/materials', $materialData);

        // Business logic validation may return 400 instead of 422
        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_material_not_found_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson('/api/materials/99999');

        $response->assertStatus(404);
    }

    public function test_course_not_found_for_materials_list_returns_empty_or_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson('/api/courses/99999/materials');

        // May return 200 with empty array or 404
        $this->assertContains($response->status(), [200, 404]);
    }

    #[Test]
    public function cross_actor_cache_does_not_leak_material_detail_to_non_group_member(): void
    {
        // Arrange: create a group-restricted material
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Alpha Team',
            'active' => true,
        ]);

        $module = $this->material->learningModule;
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Group member student
        $memberStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'user_id' => $memberStudent->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $memberStudent->id,
        ]);

        // Non-group student
        $nonMemberStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'user_id' => $nonMemberStudent->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Act 1: group member warms the cache
        $this->withHeader('X-Benchmark-Actor-Id', $memberStudent->id)
            ->getJson("/api/materials/{$this->material->id}")
            ->assertOk();

        // Act 2: non-group member requests the same material after cache warmup
        $response = $this->withHeader('X-Benchmark-Actor-Id', $nonMemberStudent->id)
            ->getJson("/api/materials/{$this->material->id}");

        // Assert: access is still denied (group restriction is checked after cache)
        $response->assertStatus(404);
    }

    #[Test]
    public function cross_actor_cache_does_not_leak_material_download_to_non_group_member(): void
    {
        // Arrange: create a group-restricted material
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Beta Team',
            'active' => true,
        ]);

        $module = $this->material->learningModule;
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Group member student
        $memberStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'user_id' => $memberStudent->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $memberStudent->id,
        ]);

        // Non-group student
        $nonMemberStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'user_id' => $nonMemberStudent->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Act 1: group member warms cache via download
        $this->withHeader('X-Benchmark-Actor-Id', $memberStudent->id)
            ->getJson("/api/materials/{$this->material->id}/download")
            ->assertOk();

        // Act 2: non-group member tries downloading after cache warmup
        $response = $this->withHeader('X-Benchmark-Actor-Id', $nonMemberStudent->id)
            ->getJson("/api/materials/{$this->material->id}/download");

        // Assert: access is still denied
        $response->assertStatus(404);
    }

    #[Test]
    public function instructor_can_see_hidden_material_in_list(): void
    {
        // Arrange: hide the learning module
        $this->material->learningModule()->update(['visible' => false]);

        // Act: instructor lists course materials
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        // Assert: instructor sees the material despite being hidden
        $response->assertOk()
            ->assertJsonFragment(['id' => $this->material->id]);
    }

    #[Test]
    public function instructor_can_see_hidden_material_detail(): void
    {
        // Arrange: hide the learning module
        $this->material->learningModule()->update(['visible' => false]);

        // Act: instructor requests material detail
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/materials/{$this->material->id}");

        // Assert: instructor can view hidden material
        $response->assertOk()
            ->assertJsonFragment(['id' => $this->material->id]);
    }

    #[Test]
    public function instructor_can_see_future_material_in_list_and_detail(): void
    {
        // Arrange: create a future-availability material
        $futureMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $futureMaterial->learningModule()->update([
            'available_from' => now()->addDays(7),
        ]);

        // Act 1: instructor lists course materials
        $listResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        $listResponse->assertOk()
            ->assertJsonFragment(['id' => $futureMaterial->id]);

        // Act 2: instructor requests material detail
        $detailResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/materials/{$futureMaterial->id}");

        $detailResponse->assertOk()
            ->assertJsonFragment(['id' => $futureMaterial->id]);
    }

    #[Test]
    public function instructor_can_see_expired_material(): void
    {
        // Arrange: create an expired material
        $expiredMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $expiredMaterial->learningModule()->update([
            'available_until' => now()->subDays(1),
        ]);

        // Act 1: instructor lists course materials
        $listResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        $listResponse->assertOk()
            ->assertJsonFragment(['id' => $expiredMaterial->id]);

        // Act 2: instructor requests material detail
        $detailResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/materials/{$expiredMaterial->id}");

        $detailResponse->assertOk()
            ->assertJsonFragment(['id' => $expiredMaterial->id]);
    }

    #[Test]
    public function instructor_can_see_group_restricted_material_without_being_member(): void
    {
        // Arrange: create a group-restricted material
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Restricted Team',
            'active' => true,
        ]);

        $groupMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $groupMaterial->learningModule;
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Act 1: instructor lists course materials
        $listResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        $listResponse->assertOk()
            ->assertJsonFragment(['id' => $groupMaterial->id]);

        // Act 2: instructor requests material detail (should see despite group restriction)
        $detailResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/materials/{$groupMaterial->id}");

        $detailResponse->assertOk()
            ->assertJsonFragment(['id' => $groupMaterial->id]);
    }

    #[Test]
    public function student_still_cannot_see_hidden_material(): void
    {
        // Arrange: hide the learning module
        $this->material->learningModule()->update(['visible' => false]);

        // Act: student requests material detail
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/materials/{$this->material->id}");

        // Assert: student still blocked
        $response->assertStatus(404);
    }

    #[Test]
    public function student_still_cannot_see_group_restricted_material(): void
    {
        // Arrange: create a group-restricted material
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Secret Team',
            'active' => true,
        ]);

        $groupMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        $module = $groupMaterial->learningModule;
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $module->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Act: non-group student requests detail
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/materials/{$groupMaterial->id}");

        // Assert: student blocked (404 from doAssertModuleReadable group check)
        $response->assertStatus(404);
    }

    #[Test]
    public function instructor_download_does_not_record_completion(): void
    {
        // Arrange: material with view-based completion enabled
        $module = $this->material->learningModule;
        $module->update([
            'completion_enabled' => true,
            'completion_rule' => 'view',
        ]);

        // Act: instructor downloads material
        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/materials/{$this->material->id}/download")
            ->assertOk();

        // Assert: no completion row created for instructor
        $this->assertDatabaseMissing('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $this->instructor->id,
        ]);
    }

    #[Test]
    public function get_material_does_not_create_learning_module(): void
    {
        // Arrange: create material without a learning module
        $orphanMaterial = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
        // Delete the learning module that the factory created
        $orphanMaterial->learningModule()->delete();
        // Force a fresh load to verify no module exists
        $orphanMaterial->load('learningModule');
        $this->assertNull($orphanMaterial->learningModule);

        // Act: attempt to read the material (should fail since no module)
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/materials/{$orphanMaterial->id}")
            ->assertStatus(404);

        // Assert: no learning module was created by the read path
        $this->assertNull(
            LearningModule::where('module_type', 'material')
                ->where('module_id', $orphanMaterial->id)
                ->first()
        );
    }

    #[Test]
    public function cross_actor_cache_records_completion_for_each_actor(): void
    {
        // Arrange: material with view-based completion enabled
        $module = $this->material->learningModule;
        $module->update([
            'completion_enabled' => true,
            'completion_rule' => 'view',
        ]);

        // Two enrolled students
        $studentA = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'user_id' => $studentA->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        $studentB = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'user_id' => $studentB->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Act 1: student A downloads material, warms cache, gets completion
        $this->withHeader('X-Benchmark-Actor-Id', $studentA->id)
            ->getJson("/api/materials/{$this->material->id}/download")
            ->assertOk();

        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $studentA->id,
            'source' => 'view',
        ]);

        // Act 2: student B downloads material (cache already warm from student A)
        $this->withHeader('X-Benchmark-Actor-Id', $studentB->id)
            ->getJson("/api/materials/{$this->material->id}/download")
            ->assertOk();

        // Assert: both students have their own completion rows
        $this->assertDatabaseHas('module_completions', [
            'learning_module_id' => $module->id,
            'user_id' => $studentB->id,
            'source' => 'view',
        ]);

        // Scoped count: only count rows for this module and these users
        // Avoids brittle global table counts that break when seed data exists
        $this->assertEquals(2, \App\Models\ModuleCompletion::query()
            ->where('learning_module_id', $module->id)
            ->whereIn('user_id', [$studentA->id, $studentB->id])
            ->count());
    }
}
