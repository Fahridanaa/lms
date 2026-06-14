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
            ->where('status', 'final')
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
            ->where('status', 'final')
            ->get();
    }

    /**
     * Get all grades for a course
     */
    public function getCourseGrades(int $courseId): Collection
    {
        return $this->model->newQuery()
            ->with(['gradeable', 'user'])
            ->where('course_id', $courseId)
            ->where('status', 'final')
            ->get();
    }

    /**
     * Get grades by type (quiz or assignment)
     */
    public function getGradesByType(int $userId, string $gradeableType): Collection
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('gradeable_type', $gradeableType)
            ->where('status', 'final')
            ->with(['gradeable', 'course'])
            ->get();
    }

    /**
     * Get top performers in a course (uses weighted average via grade_items.weight).
     */
    /**
     * Get top performers in a course (uses weighted average via grade_items.weight).
     * Active student IDs are passed in and applied BEFORE ordering/limiting so
     * that non-active students (suspended, expired) cannot consume top slots.
     */
    public function getTopPerformers(int $courseId, int $limit = 10, array $activeStudentIds = []): Collection
    {
        $q = $this->model->newQuery()
            ->from('grades', 'g')
            ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->leftJoin('grade_items', 'g.grade_item_id', '=', 'grade_items.id')
            ->whereNull('g.deleted_at')
            ->selectRaw('
                g.user_id,
                SUM(g.percentage * COALESCE(grade_items.weight, 1.0)) / NULLIF(SUM(COALESCE(grade_items.weight, 1.0)), 0) as average_percentage
            ')
            ->where('g.course_id', $courseId)
            ->where('g.status', 'final')
            ->groupBy('g.user_id')
            ->orderByDesc('average_percentage')
            ->limit($limit);

        // Filter to active students BEFORE limit so non-active users cannot
        // push valid students out of the top-N slots.
        if (! empty($activeStudentIds)) {
            $q->whereIn('g.user_id', $activeStudentIds);
        }

        return $q->get();
    }

    /**
     * Get course statistics (uses weighted average via grade_items.weight).
     */
    public function getCourseStatistics(int $courseId): array
    {
        $stats = $this->model->newQuery()
            ->from('grades', 'g')
            ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->leftJoin('grade_items', 'g.grade_item_id', '=', 'grade_items.id')
            ->where('g.course_id', $courseId)
            ->whereNull('g.deleted_at')
            ->where('g.status', 'final')
            ->selectRaw('
                COUNT(*) as total_grades,
                SUM(g.percentage * COALESCE(grade_items.weight, 1.0)) / NULLIF(SUM(COALESCE(grade_items.weight, 1.0)), 0) as average_percentage,
                MAX(g.percentage) as highest_percentage,
                MIN(g.percentage) as lowest_percentage,
                SUM(CASE WHEN g.percentage >= 60 THEN 1 ELSE 0 END) as passing_count
            ')
            ->first();

        $total = (int) ($stats->total_grades ?? 0);

        return [
            'total_grades' => $total,
            'average_percentage' => $stats->average_percentage ?? 0,
            'highest_percentage' => $stats->highest_percentage ?? 0,
            'lowest_percentage' => $stats->lowest_percentage ?? 0,
            'passing_rate' => $total > 0 ? ($stats->passing_count / $total) * 100 : 0,
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
            ->where('status', 'final')
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
