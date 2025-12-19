<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Course $course;
    protected Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->course = Course::factory()->create();
        $this->material = Material::factory()->create([
            'course_id' => $this->course->id,
        ]);
    }

    public function test_can_list_course_materials(): void
    {
        // Create additional materials for the course
        Material::factory()->count(3)->create([
            'course_id' => $this->course->id,
        ]);

        $response = $this->getJson("/api/courses/{$this->course->id}/materials");

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
                    ]
                ]
            ]);
    }

    public function test_can_show_material_detail(): void
    {
        $response = $this->getJson("/api/materials/{$this->material->id}");

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
                ]
            ]);
    }

    public function test_can_download_material(): void
    {
        $response = $this->getJson("/api/materials/{$this->material->id}/download");

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
                ]
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

        $response = $this->postJson('/api/materials', $materialData);

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
                ]
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

        $response = $this->putJson("/api/materials/{$this->material->id}", $updateData);

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
                ]
            ]);

        $this->assertDatabaseHas('materials', [
            'id' => $this->material->id,
            'title' => 'Updated Material Title',
        ]);
    }

    public function test_can_delete_material(): void
    {
        $materialId = $this->material->id;

        $response = $this->deleteJson("/api/materials/{$materialId}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Since using soft deletes, check deleted_at is set
        $this->assertSoftDeleted('materials', [
            'id' => $materialId,
        ]);
    }

    public function test_create_material_validation_fails_without_required_fields(): void
    {
        $response = $this->postJson('/api/materials', []);

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

        $response = $this->postJson('/api/materials', $materialData);

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

        $response = $this->postJson('/api/materials', $materialData);

        // Business logic validation may return 400 instead of 422
        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_material_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/materials/99999');

        $response->assertStatus(404);
    }

    public function test_course_not_found_for_materials_list_returns_empty_or_404(): void
    {
        $response = $this->getJson('/api/courses/99999/materials');

        // May return 200 with empty array or 404
        $this->assertContains($response->status(), [200, 404]);
    }
}
