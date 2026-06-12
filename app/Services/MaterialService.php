<?php

namespace App\Services;

use App\Constants\Messages\MaterialMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\LearningModule;
use App\Models\Material;
use App\Repositories\MaterialRepository;

class MaterialService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected MaterialRepository $materialRepository
    ) {}

    /**
     * Get all materials for a course (cached)
     */
    public function getCourseMaterials(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['materials', "course:{$courseId}"])
            ->get(
                "course:{$courseId}:materials",
                fn () => $this->materialRepository->getByCourse($courseId)
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
                fn () => tap($this->materialRepository->findWithCourse($materialId), fn (Material $material) => $this->ensureMaterialVisible($material))
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
                $material->loadMissing(['course', 'learningModule']);
                $this->ensureMaterialVisible($material);

                return [
                    'id' => $material->id,
                    'title' => $material->title,
                    'file_path' => $material->file_path,
                    'file_size' => $material->file_size,
                    'type' => $material->type,
                    'mime_type' => $material->mime_type,
                    'revision' => $material->revision,
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

        $material->learningModule()->create([
            'course_id' => $material->course_id,
            'module_type' => LearningModule::TYPE_MATERIAL,
            'visible' => true,
            'sort_order' => $material->id,
        ]);

        $this->cacheStrategy->flushTags([
            'materials',
            "course:{$material->course_id}",
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

        // Warm cache dengan entity terbaru
        $this->cacheStrategy->put(
            "material:{$materialId}",
            $updatedMaterial->load(['course'])
        );

        $this->cacheStrategy->flushTags([
            'materials',
            "course:{$material->course_id}",
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
            "course:{$courseId}",
        ]);

        return $deleted;
    }

    private function ensureMaterialVisible(Material $material): void
    {
        $material->loadMissing(['course']);

        $learningModule = LearningModule::query()
            ->where('module_type', LearningModule::TYPE_MATERIAL)
            ->where('module_id', $material->id)
            ->first();

        if ($learningModule === null) {
            $learningModule = $material->learningModule()->create([
                'course_id' => $material->course_id,
                'module_type' => LearningModule::TYPE_MATERIAL,
                'visible' => true,
                'sort_order' => $material->id,
            ]);
        }

        $material->setRelation('learningModule', $learningModule);

        if (! $material->is_active || ! $material->course?->is_active || ! $learningModule->isAvailable()) {
            throw new BusinessException(MaterialMessage::NOT_FOUND, 404);
        }
    }
}
