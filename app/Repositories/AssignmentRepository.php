<?php

namespace App\Repositories;

use App\Models\Assignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class AssignmentRepository extends BaseRepository
{
    public function __construct(Assignment $model)
    {
        $this->model = $model;
    }

    /**
     * Get assignments by course ID
     */
    public function getByCourse(int $courseId): Collection
    {
        return $this->model->newQuery()
            ->where('course_id', $courseId)
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Get assignment with course relationship
     */
    public function findWithCourse(int $id): Model
    {
        return $this->findOrFail($id, ['course']);
    }

    /**
     * Get upcoming assignments for a course
     */
    public function getUpcomingByCourse(int $courseId): Collection
    {
        return $this->model->newQuery()
            ->where('course_id', $courseId)
            ->where('due_date', '>=', now())
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Get past assignments for a course
     */
    public function getPastByCourse(int $courseId): Collection
    {
        return $this->model->newQuery()
            ->where('course_id', $courseId)
            ->where('due_date', '<', now())
            ->orderBy('due_date', 'desc')
            ->get();
    }

    /**
     * Count assignments by course
     */
    public function countByCourse(int $courseId): int
    {
        return $this->count(['course_id' => $courseId]);
    }
}
