<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Models\Material;

class MaterialService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy
    ) {
    }

    /**
     * Get all materials for a course (cached)
     */
    public function getCourseMaterials(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['materials', "course:{$courseId}"])
            ->get(
                "course:{$courseId}:materials",
                fn() => Material::where('course_id', $courseId)
                    ->orderBy('created_at', 'desc')
                    ->get()
            );
    }

    /**
     * Get material by ID (cached)
     */
    public function getMaterialById(int $materialId)
    {
        return $this->cacheStrategy
            ->tags(['materials', "material:{$materialId}"])
            ->get(
                "material:{$materialId}",
                fn() => Material::with('course')->findOrFail($materialId)
            );
    }

    /**
     * Get material metadata for download (cached)
     */
    public function getMaterialMetadata(int $materialId)
    {
        return $this->cacheStrategy
            ->tags(['materials', "material:{$materialId}"])
            ->get("material:{$materialId}:metadata", function () use ($materialId) {
                $material = Material::findOrFail($materialId);

                return [
                    'id' => $material->id,
                    'title' => $material->title,
                    'file_path' => $material->file_path,
                    'file_size' => $material->file_size,
                    'type' => $material->type,
                    'course_id' => $material->course_id,
                ];
            });
    }

    /**
     * Create new material
     */
    public function createMaterial(array $data): Material
    {
        $material = Material::create($data);

        // Invalidate course materials cache
        $this->cacheStrategy->flushTags([
            'materials',
            "course:{$material->course_id}"
        ]);

        return $material;
    }

    /**
     * Update material
     */
    public function updateMaterial(int $materialId, array $data): Material
    {
        $material = Material::findOrFail($materialId);
        $material->update($data);

        // Invalidate material and course caches
        $this->cacheStrategy->flushTags([
            'materials',
            "material:{$materialId}",
            "course:{$material->course_id}"
        ]);

        return $material->fresh();
    }

    /**
     * Delete material
     */
    public function deleteMaterial(int $materialId): bool
    {
        $material = Material::findOrFail($materialId);
        $courseId = $material->course_id;

        $deleted = $material->delete();

        // Invalidate caches
        $this->cacheStrategy->flushTags([
            'materials',
            "material:{$materialId}",
            "course:{$courseId}"
        ]);

        return $deleted;
    }

    /**
     * Get materials by type (cached)
     */
    public function getMaterialsByType(int $courseId, string $type)
    {
        return $this->cacheStrategy
            ->tags(['materials', "course:{$courseId}"])
            ->get(
                "course:{$courseId}:materials:type:{$type}",
                fn() => Material::where('course_id', $courseId)
                    ->where('type', $type)
                    ->orderBy('created_at', 'desc')
                    ->get()
            );
    }

    /**
     * Get total materials count for a course (cached)
     */
    public function getCourseMaterialsCount(int $courseId): int
    {
        return $this->cacheStrategy
            ->tags(["course:{$courseId}"])
            ->get(
                "course:{$courseId}:materials:count",
                fn() => Material::where('course_id', $courseId)->count()
            );
    }
}
