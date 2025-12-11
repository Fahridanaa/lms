<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Models\Course;
use App\Models\Grade;
use App\Models\User;

class GradebookService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy
    ) {}

    /**
     * Get full gradebook for a course (cached)
     * Returns all students with their grades
     */
    public function getCourseGradebook(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:gradebook", function () use ($courseId) {
                $course = Course::with(['students'])->findOrFail($courseId);

                $gradebook = [];

                foreach ($course->students as $student) {
                    $grades = Grade::with(['gradeable'])
                        ->where('course_id', $courseId)
                        ->where('user_id', $student->id)
                        ->get();

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
            ->get("user:{$userId}:grades:all", function () use ($userId) {
                return Grade::with(['course', 'gradeable'])
                    ->where('user_id', $userId)
                    ->orderBy('created_at', 'desc')
                    ->get();
            });
    }

    /**
     * Get user's grades in a specific course (cached)
     */
    public function getUserCourseGrades(int $courseId, int $userId)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}", "user:{$userId}:grades"])
            ->get("course:{$courseId}:user:{$userId}:grades", function () use ($courseId, $userId) {
                $grades = Grade::with(['gradeable'])
                    ->where('course_id', $courseId)
                    ->where('user_id', $userId)
                    ->get();

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
    public function updateGrade(int $gradeId, array $data): Grade
    {
        $grade = Grade::findOrFail($gradeId);
        $grade->update($data);

        // Invalidate related caches
        $this->cacheStrategy->flushTags([
            'gradebook',
            "course:{$grade->course_id}",
            "user:{$grade->user_id}:grades",
        ]);

        return $grade->fresh();
    }

    /**
     * Create a new grade entry
     */
    public function createGrade(array $data): Grade
    {
        // Calculate percentage
        $percentage = ($data['score'] / $data['max_score']) * 100;
        $data['percentage'] = $percentage;

        $grade = Grade::create($data);

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
            ->get("course:{$courseId}:statistics", function () use ($courseId) {
                $grades = Grade::where('course_id', $courseId)->get();

                return [
                    'total_grades' => $grades->count(),
                    'average_percentage' => $grades->avg('percentage'),
                    'highest_percentage' => $grades->max('percentage'),
                    'lowest_percentage' => $grades->min('percentage'),
                    'passing_rate' => $grades->where('percentage', '>=', 60)->count() / max($grades->count(), 1) * 100,
                ];
            });
    }

    /**
     * Get user's overall performance summary (cached)
     */
    public function getUserPerformanceSummary(int $userId)
    {
        return $this->cacheStrategy
            ->tags(["user:{$userId}:grades"])
            ->get("user:{$userId}:performance:summary", function () use ($userId) {
                $grades = Grade::with(['course'])
                    ->where('user_id', $userId)
                    ->get();

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
                $studentAverages = Grade::where('course_id', $courseId)
                    ->selectRaw('user_id, AVG(percentage) as average_percentage')
                    ->groupBy('user_id')
                    ->orderByDesc('average_percentage')
                    ->limit($limit)
                    ->get();

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
