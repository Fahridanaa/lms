<?php

namespace App\Services;

use App\Constants\Messages\MaterialMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
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
        $maxFileSize = 104857600; // 100MB in bytes
        if (isset($data['file_size']) && $data['file_size'] > $maxFileSize) {
            throw new BusinessException(MaterialMessage::MAX_SIZE_EXCEEDED, 400);
        }

        if (isset($data['file_size']) && $data['file_size'] <= 0) {
            throw new BusinessException(MaterialMessage::INVALID_SIZE, 400);
        }

        $material = $this->materialRepository->create($data);

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

        if (isset($data['file_size'])) {
            $maxFileSize = 104857600; // 100MB
            if ($data['file_size'] > $maxFileSize) {
                throw new BusinessException(MaterialMessage::MAX_SIZE_EXCEEDED, 400);
            }
            if ($data['file_size'] <= 0) {
                throw new BusinessException(MaterialMessage::INVALID_SIZE, 400);
            }
        }

        $updatedMaterial = $this->materialRepository->update($materialId, $data);

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
}
