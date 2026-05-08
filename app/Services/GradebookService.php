<?php

namespace App\Services;

use App\Constants\Messages\GradeMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\Course;
use App\Models\Grade;
use App\Models\User;
use App\Repositories\GradeRepository;

class GradebookService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected GradeRepository $gradeRepository
    ) {}

    /**
     * Get full gradebook for a course (cached)
     * Returns all students with their aggregated grade stats
     *
     * Optimized: Uses SQL aggregation instead of loading all Grade models
     * with polymorphic gradeable relations into memory.
     */
    public function getCourseGradebook(int $courseId): array
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:gradebook", function () use ($courseId) {
                // Aggregate grades per student in a single SQL query
                // instead of loading all Grade models + polymorphic gradeable relations
                $studentAverages = Grade::where('course_id', $courseId)
                    ->whereNull('deleted_at')
                    ->selectRaw('
                        user_id,
                        AVG(percentage) as average_percentage,
                        COUNT(*) as total_grades,
                        SUM(CASE WHEN gradeable_type = ? THEN 1 ELSE 0 END) as quiz_count,
                        SUM(CASE WHEN gradeable_type = ? THEN 1 ELSE 0 END) as assignment_count
                    ', ['quiz_attempt', 'submission'])
                    ->groupBy('user_id')
                    ->get();

                if ($studentAverages->isEmpty()) {
                    return [];
                }

                // Batch-load student info in one query instead of per-student
                $students = User::whereIn('id', $studentAverages->pluck('user_id'))
                    ->get()
                    ->keyBy('id');

                return $studentAverages->map(function ($row) use ($students) {
                    $student = $students->get($row->user_id);
                    if (! $student) {
                        return null;
                    }

                    return [
                        'student' => [
                            'id' => $student->id,
                            'name' => $student->name,
                            'email' => $student->email,
                        ],
                        'average_percentage' => round($row->average_percentage, 2),
                        'total_grades' => (int) $row->total_grades,
                        'quiz_count' => (int) $row->quiz_count,
                        'assignment_count' => (int) $row->assignment_count,
                    ];
                })->filter()->values()->all();
            });
    }

    /**
     * Get all grades for a user (cached)
     */
    public function getUserGrades(int $userId)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "user:{$userId}:grades"])
            ->get(
                "user:{$userId}:grades:all",
                fn () => $this->gradeRepository->getUserGrades($userId)
            );
    }

    /**
     * Get user's grades in a specific course (cached)
     */
    public function getUserCourseGrades(int $courseId, int $userId)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}", "user:{$userId}:grades"])
            ->get("course:{$courseId}:user:{$userId}:grades", function () use ($courseId, $userId) {
                $grades = $this->gradeRepository->getUserCourseGrades($userId, $courseId);

                return [
                    'grades' => $grades,
                    'average_percentage' => $grades->avg('percentage'),
                    'total_grades' => $grades->count(),
                    'quiz_grades' => $grades->where('gradeable_type', 'quiz_attempt'),
                    'assignment_grades' => $grades->where('gradeable_type', 'submission'),
                ];
            });
    }

    /**
     * Update or create a grade
     */
    public function updateGrade(int $gradeId, array $data)
    {
        $grade = $this->gradeRepository->findOrFail($gradeId);

        if (isset($data['score']) && isset($data['max_score'])) {
            if ($data['score'] > $data['max_score']) {
                throw new BusinessException(GradeMessage::EXCEEDS_MAX, 400);
            }
            if ($data['score'] < 0 || $data['max_score'] < 0) {
                throw new BusinessException(GradeMessage::NEGATIVE, 400);
            }
        }

        $updatedGrade = $this->gradeRepository->update($gradeId, $data);

        $this->cacheStrategy->flushTags([
            'gradebook',
            "course:{$grade->course_id}",
            "user:{$grade->user_id}:grades",
        ]);

        return $updatedGrade;
    }

    /**
     * Get course statistics (cached)
     */
    public function getCourseStatistics(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:statistics", fn () => $this->gradeRepository->getCourseStatistics($courseId));
    }

    /**
     * Get user's overall performance summary (cached)
     *
     * Optimized: Uses SQL aggregation for averages instead of loading
     * all Grade models with polymorphic gradeable relations.
     */
    public function getUserPerformanceSummary(int $userId): array
    {
        return $this->cacheStrategy
            ->tags(["user:{$userId}:grades"])
            ->get("user:{$userId}:performance:summary", function () use ($userId) {
                // Per-course aggregated stats in a single SQL query
                $courseStats = Grade::where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->selectRaw('
                        course_id,
                        COUNT(*) as total_grades,
                        AVG(percentage) as average_percentage,
                        AVG(CASE WHEN gradeable_type = ? THEN percentage ELSE NULL END) as quiz_average,
                        AVG(CASE WHEN gradeable_type = ? THEN percentage ELSE NULL END) as assignment_average
                    ', ['quiz_attempt', 'submission'])
                    ->groupBy('course_id')
                    ->get();

                if ($courseStats->isEmpty()) {
                    return [
                        'total_courses' => 0,
                        'total_grades' => 0,
                        'overall_average' => 0,
                        'quiz_average' => 0,
                        'assignment_average' => 0,
                        'courses_performance' => [],
                    ];
                }

                // Batch-load course info in one query instead of N+1
                $courses = Course::whereIn('id', $courseStats->pluck('course_id'))
                    ->get()
                    ->keyBy('id');

                $totalGrades = $courseStats->sum('total_grades');

                // Weighted overall averages
                $overallAvg = $courseStats->sum(fn ($s) => $s->average_percentage * $s->total_grades) / $totalGrades;

                // Weighted quiz/assignment averages (only from courses that have them)
                $quizCourses = $courseStats->filter(fn ($s) => $s->quiz_average !== null);
                $quizWeight = $quizCourses->sum('total_grades');
                $quizAvg = $quizWeight > 0
                    ? $quizCourses->sum(fn ($s) => $s->quiz_average * $s->total_grades) / $quizWeight
                    : 0;

                $assignmentCourses = $courseStats->filter(fn ($s) => $s->assignment_average !== null);
                $assignmentWeight = $assignmentCourses->sum('total_grades');
                $assignmentAvg = $assignmentWeight > 0
                    ? $assignmentCourses->sum(fn ($s) => $s->assignment_average * $s->total_grades) / $assignmentWeight
                    : 0;

                return [
                    'total_courses' => $courseStats->count(),
                    'total_grades' => (int) $totalGrades,
                    'overall_average' => round($overallAvg, 2),
                    'quiz_average' => round($quizAvg, 2),
                    'assignment_average' => round($assignmentAvg, 2),
                    'courses_performance' => $courseStats->map(function ($stat) use ($courses) {
                        $course = $courses->get($stat->course_id);
                        if (! $course) {
                            return null;
                        }

                        return [
                            'course' => $course,
                            'average' => round($stat->average_percentage, 2),
                            'count' => (int) $stat->total_grades,
                        ];
                    })->filter()->values(),
                ];
            });
    }

    /**
     * Get top performers in a course (cached)
     */
    public function getTopPerformers(int $courseId, int $limit = 10): mixed
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:top-performers:{$limit}", function () use ($courseId, $limit) {
                $studentAverages = $this->gradeRepository->getTopPerformers($courseId, $limit);

                $users = User::whereIn('id', $studentAverages->pluck('user_id'))
                    ->get()
                    ->keyBy('id');

                return $studentAverages->map(fn ($item) => [
                    'user' => $users->get($item->user_id),
                    'average_percentage' => $item->average_percentage,
                ]);
            });
    }
}
