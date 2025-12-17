<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Models\Material;
use App\Repositories\MaterialRepository;

class MaterialService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected MaterialRepository $materialRepository
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
                fn() => $this->materialRepository->getByCourse($courseId)
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
                fn() => $this->materialRepository->findWithCourse($materialId)
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
                $material = $this->materialRepository->findOrFail($materialId);

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
        $material = $this->materialRepository->create($data);

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
        $material = $this->materialRepository->findOrFail($materialId);
        $updatedMaterial = $this->materialRepository->update($materialId, $data);

        // Invalidate material and course caches
        $this->cacheStrategy->flushTags([
            'materials',
            "material:{$materialId}",
            "course:{$material->course_id}"
        ]);

        return $updatedMaterial;
    }

    /**
     * Delete material
     */
    public function deleteMaterial(int $materialId): bool
    {
        $material = $this->materialRepository->findOrFail($materialId);
        $courseId = $material->course_id;

        $deleted = $this->materialRepository->delete($materialId);

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
                fn() => $this->materialRepository->getByTypeAndCourse($courseId, $type)
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
                fn() => $this->materialRepository->countByCourse($courseId)
            );
    }
}
