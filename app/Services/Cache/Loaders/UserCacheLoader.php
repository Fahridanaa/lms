<?php

namespace App\Services\Cache\Loaders;

use App\Repositories\GradeRepository;
use App\Repositories\QuizAttemptRepository;

/**
 * Cache Loader untuk User-related cache keys
 *
 * Handles keys:
 *   - user:{id}:all-attempts → getUserAttempts()
 *   - user:{id}:quiz:{quizId}:attempts → getUserAttempts(userId, quizId)
 *   - user:{id}:performance:summary → computed performance summary
 *   - user:{id}:grades:all → getUserGrades()
 */
class UserCacheLoader extends BaseCacheLoader
{
    protected string $prefix = 'user';

    public function __construct(
        protected QuizAttemptRepository $quizAttemptRepository,
        protected GradeRepository $gradeRepository
    ) {}

    public function load(string $key): mixed
    {
        $parts = $this->parseKey($key);
        $userId = (int) ($parts[1] ?? 0);
        $subkey = $parts[2] ?? null;

        // Handle "user:{id}:quiz:{quizId}:attempts"
        if ($subkey === 'quiz' && isset($parts[4]) && $parts[4] === 'attempts') {
            $quizId = (int) $parts[3];
            return $this->quizAttemptRepository->getUserAttempts($userId, $quizId);
        }

        // Handle "user:{id}:performance:summary"
        if ($subkey === 'performance' && isset($parts[3]) && $parts[3] === 'summary') {
            return $this->loadPerformanceSummary($userId);
        }

        // Handle "user:{id}:grades:all"
        if ($subkey === 'grades' && isset($parts[3]) && $parts[3] === 'all') {
            return $this->gradeRepository->getUserGrades($userId);
        }

        return match ($subkey) {
            'all-attempts' => $this->quizAttemptRepository->getUserAttempts($userId),
            default => null,
        };
    }

    /**
     * Load user's overall performance summary
     */
    protected function loadPerformanceSummary(int $userId): array
    {
        $grades = $this->gradeRepository->getUserGrades($userId);

        return [
            'total_courses' => $grades->pluck('course_id')->unique()->count(),
            'total_grades' => $grades->count(),
            'overall_average' => $grades->avg('percentage'),
            'quiz_average' => $grades->where('gradeable_type', 'quiz_attempt')->avg('percentage'),
            'assignment_average' => $grades->where('gradeable_type', 'submission')->avg('percentage'),
            'courses_performance' => $grades->groupBy('course_id')->map(function ($courseGrades) {
                return [
                    'course' => $courseGrades->first()->course,
                    'average' => $courseGrades->avg('percentage'),
                    'count' => $courseGrades->count(),
                ];
            })->values(),
        ];
    }
}
