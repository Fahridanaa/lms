<?php

namespace App\Repositories;

use App\Models\Grade;
use Illuminate\Database\Eloquent\Collection;

class GradeRepository extends BaseRepository
{
    public function __construct(Grade $model)
    {
        $this->model = $model;
    }

    /**
     * Get user's all grades
     */
    public function getUserGrades(int $userId): Collection
    {
        return $this->model->newQuery()
            ->with(['course', 'gradeable'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get user's grades for a specific course
     */
    public function getUserCourseGrades(int $userId, int $courseId): Collection
    {
        return $this->model->newQuery()
            ->with(['gradeable'])
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->get();
    }

    /**
     * Get all grades for a course
     */
    public function getCourseGrades(int $courseId): Collection
    {
        return $this->where('course_id', $courseId, ['gradeable', 'user']);
    }

    /**
     * Get grades by type (quiz or assignment)
     */
    public function getGradesByType(int $userId, string $gradeableType): Collection
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('gradeable_type', $gradeableType)
            ->with(['gradeable', 'course'])
            ->get();
    }

    /**
     * Get top performers in a course
     */
    public function getTopPerformers(int $courseId, int $limit = 10): Collection
    {
        return $this->model->newQuery()
            ->selectRaw('user_id, AVG(percentage) as average_percentage')
            ->where('course_id', $courseId)
            ->groupBy('user_id')
            ->orderByDesc('average_percentage')
            ->limit($limit)
            ->get();
    }

    /**
     * Get course statistics
     */
    public function getCourseStatistics(int $courseId): array
    {
        $grades = $this->getCourseGrades($courseId);

        return [
            'total_grades' => $grades->count(),
            'average_percentage' => $grades->avg('percentage'),
            'highest_percentage' => $grades->max('percentage'),
            'lowest_percentage' => $grades->min('percentage'),
            'passing_rate' => $grades->count() > 0
                ? ($grades->where('percentage', '>=', 60)->count() / $grades->count()) * 100
                : 0,
        ];
    }

    /**
     * Calculate user's average in a course
     */
    public function getUserCourseAverage(int $userId, int $courseId): float
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->avg('percentage') ?? 0.0;
    }

    /**
     * Count user's grades
     */
    public function countUserGrades(int $userId, ?int $courseId = null): int
    {
        $conditions = ['user_id' => $userId];

        if ($courseId !== null) {
            $conditions['course_id'] = $courseId;
        }

        return $this->count($conditions);
    }

    /**
     * Create grade with calculated percentage
     */
    public function createWithPercentage(array $data): \Illuminate\Database\Eloquent\Model
    {
        $percentage = ($data['score'] / $data['max_score']) * 100;
        $data['percentage'] = $percentage;

        return $this->create($data);
    }
}
