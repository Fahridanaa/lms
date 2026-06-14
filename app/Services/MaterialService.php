<?php

namespace App\Services;

use App\Constants\Messages\MaterialMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\Course;
use App\Models\FileRecord;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\User;
use App\Repositories\MaterialRepository;

class MaterialService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected MaterialRepository $materialRepository,
        protected CourseAccessService $courseAccessService,
        protected ModuleAvailabilityService $moduleAvailabilityService,
        protected ModuleCompletionService $moduleCompletionService
    ) {}

    /**
     * Get all materials for a course (cached)
     *
     * Instructors see all materials in their course.
     * Students only see materials that satisfy availability rules.
     */
    public function getCourseMaterials(int $courseId, User $actor)
    {
        $course = Course::query()->findOrFail($courseId);
        if (! $this->courseAccessService->canReadCourse($actor, $course)) {
            throw new BusinessException('Anda tidak memiliki akses ke materi ini', 403);
        }

        return $this->cacheStrategy
            ->tags(['materials', "course:{$courseId}"])
            ->get(
                "course:{$courseId}:materials:actor:{$actor->id}",
                function () use ($courseId, $actor) {
                    $materials = $this->materialRepository->getByCourse($courseId);

                    return collect($materials)->filter(function ($material) use ($actor) {
                        if (! $material->learningModule) {
                            return false;
                        }

                        // Instructors see all materials in their course
                        if ($this->courseAccessService->isInstructorForCourse($actor, $material->course)) {
                            return true;
                        }

                        // Students filtered by full availability rules
                        $availability = $this->moduleAvailabilityService->availabilityFor($actor, $material->learningModule);

                        return $availability['available'];
                    })->values();
                }
            );
    }

    /**
     * Get material by ID (cached)
     *
     * Caches only the shared immutable material model.
     * Actor-specific access checks run on EVERY request, outside the cache callback.
     */
    public function getMaterialById(int $materialId, User $actor)
    {
        $material = $this->cacheStrategy
            ->tags(['materials', "material:{$materialId}"])
            ->get(
                "material:{$materialId}",
                function () use ($materialId) {
                    $material = $this->materialRepository->findWithCourse($materialId);

                    if (! $material->is_active) {
                        throw new BusinessException(MaterialMessage::NOT_FOUND, 404);
                    }

                    return $material;
                }
            );

        // Actor-specific access check — instructors can read all modules in their course,
        // students must satisfy visibility + availability rules.
        $this->courseAccessService->assertActivityAvailableForRead($actor, $material);

        return $material;
    }

    /**
     * Get material metadata for download.
     *
     * Caches only the shared immutable material model.
     * Actor-specific access checks, file record creation, and completion marking
     * run on EVERY request to ensure correctness across cache states.
     */
    public function getMaterialMetadata(int $materialId, User $actor)
    {
        $material = $this->cacheStrategy
            ->tags(['materials', "material:{$materialId}"])
            ->get("material:{$materialId}", function () use ($materialId) {
                $material = $this->materialRepository->findOrFail($materialId);
                $material->loadMissing(['course', 'learningModule']);

                if (! $material->is_active) {
                    throw new BusinessException(MaterialMessage::NOT_FOUND, 404);
                }

                return $material;
            });

        // Actor-specific access check — instructors can read all modules in their course,
        // students must satisfy visibility + availability rules.
        $this->courseAccessService->assertActivityAvailableForRead($actor, $material);

        // Create file record on first download/access (idempotent, shared)
        FileRecord::firstOrCreate([
            'owner_type' => 'material',
            'owner_id' => $material->id,
        ], [
            'uploader_id' => $material->course->instructor_id,
            'component' => 'material',
            'file_path' => $material->file_path,
            'mime_type' => $material->mime_type,
            'file_size' => $material->file_size ?? 0,
            'revision' => $material->revision ?? 1,
            'visible' => true,
        ]);

        // Only record completion for students (instructors download for inspection)
        if ($this->courseAccessService->isActiveEnrollee($actor, $material->course)) {
            $this->moduleCompletionService->completeForMaterial($material, $actor);
        }

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

        // Update cache with fresh material data
        $this->cacheStrategy->put(
            "material:{$materialId}",
            $updatedMaterial->load(['course'])
        );

        // Invalidate actor-specific material list caches
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


}
