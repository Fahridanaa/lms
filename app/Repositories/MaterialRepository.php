<?php

namespace App\Repositories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class MaterialRepository extends BaseRepository
{
    public function __construct(Material $model)
    {
        $this->model = $model;
    }

    /**
     * Get materials by course ID
     */
    public function getByCourse(int $courseId): Collection
    {
        return $this->model->newQuery()
            ->with(['learningModule'])
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn (Material $material): bool => $material->learningModule?->isAvailable())
            ->values();
    }

    /**
     * Get material with course relationship
     */
    public function findWithCourse(int $id): Model
    {
        return $this->findOrFail($id, ['course', 'learningModule']);
    }

    /**
     * Get materials by type for a course
     */
    public function getByTypeAndCourse(int $courseId, string $type): Collection
    {
        return $this->model->newQuery()
            ->where('course_id', $courseId)
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get materials by type (all courses)
     */
    public function getByType(string $type): Collection
    {
        return $this->where('type', $type);
    }

    /**
     * Count materials by course
     */
    public function countByCourse(int $courseId): int
    {
        return $this->count(['course_id' => $courseId]);
    }

    /**
     * Count materials by type
     */
    public function countByType(string $type): int
    {
        return $this->count(['type' => $type]);
    }

    /**
     * Get total file size for a course
     */
    public function getTotalSizeByCourse(int $courseId): int
    {
        return $this->model->newQuery()
            ->where('course_id', $courseId)
            ->sum('file_size');
    }

    /**
     * Get recent materials (all courses)
     */
    public function getRecent(int $limit = 10): Collection
    {
        return $this->model->newQuery()
            ->with(['course'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
