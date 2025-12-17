<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Models\Course;
use App\Models\User;
use App\Repositories\GradeRepository;

class GradebookService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected GradeRepository $gradeRepository
    ) {
    }

    /**
     * Get full gradebook for a course (cached)
     * Returns all students with their grades
     */
    public function getCourseGradebook(int $courseId): array
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:gradebook", function () use ($courseId) {
                $course = Course::with(['students'])->findOrFail($courseId);

                $gradebook = [];

                foreach ($course->students as $student) {
                    $grades = $this->gradeRepository->getUserCourseGrades($student->id, $courseId);

                    $gradebook[] = [
                        'student' => [
                            'id' => $student->id,
                            'name' => $student->name,
                            'email' => $student->email,
                        ],
                        'grades' => $grades,
                        'average_percentage' => $grades->avg('percentage'),
                        'total_grades' => $grades->count(),
                    ];
                }

                return $gradebook;
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
                fn() => $this->gradeRepository->getUserGrades($userId)
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
                    'quiz_grades' => $grades->where('gradeable_type', 'App\Models\QuizAttempt'),
                    'assignment_grades' => $grades->where('gradeable_type', 'App\Models\Submission'),
                ];
            });
    }

    /**
     * Update or create a grade
     */
    public function updateGrade(int $gradeId, array $data)
    {
        $grade = $this->gradeRepository->findOrFail($gradeId);
        $updatedGrade = $this->gradeRepository->update($gradeId, $data);

        // Invalidate related caches
        $this->cacheStrategy->flushTags([
            'gradebook',
            "course:{$grade->course_id}",
            "user:{$grade->user_id}:grades",
        ]);

        return $updatedGrade;
    }

    /**
     * Create a new grade entry
     */
    public function createGrade(array $data)
    {
        $grade = $this->gradeRepository->createWithPercentage($data);

        // Invalidate related caches
        $this->cacheStrategy->flushTags([
            'gradebook',
            "course:{$grade->course_id}",
            "user:{$grade->user_id}:grades",
        ]);

        return $grade;
    }

    /**
     * Get course statistics (cached)
     */
    public function getCourseStatistics(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:statistics", fn() => $this->gradeRepository->getCourseStatistics($courseId));
    }

    /**
     * Get user's overall performance summary (cached)
     */
    public function getUserPerformanceSummary(int $userId): array
    {
        return $this->cacheStrategy
            ->tags(["user:{$userId}:grades"])
            ->get("user:{$userId}:performance:summary", function () use ($userId) {
                $grades = $this->gradeRepository->getUserGrades($userId);

                return [
                    'total_courses' => $grades->pluck('course_id')->unique()->count(),
                    'total_grades' => $grades->count(),
                    'overall_average' => $grades->avg('percentage'),
                    'quiz_average' => $grades->where('gradeable_type', 'App\Models\QuizAttempt')->avg('percentage'),
                    'assignment_average' => $grades->where('gradeable_type', 'App\Models\Submission')->avg('percentage'),
                    'courses_performance' => $grades->groupBy('course_id')->map(function ($courseGrades) {
                        return [
                            'course' => $courseGrades->first()->course,
                            'average' => $courseGrades->avg('percentage'),
                            'count' => $courseGrades->count(),
                        ];
                    })->values(),
                ];
            });
    }

    /**
     * Get top performers in a course (cached)
     */
    public function getTopPerformers(int $courseId, int $limit = 10)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:top-performers:{$limit}", function () use ($courseId, $limit) {
                $studentAverages = $this->gradeRepository->getTopPerformers($courseId, $limit);

                return $studentAverages->map(function ($item) {
                    $user = User::find($item->user_id);
                    return [
                        'user' => $user,
                        'average_percentage' => $item->average_percentage,
                    ];
                });
            });
    }
}
