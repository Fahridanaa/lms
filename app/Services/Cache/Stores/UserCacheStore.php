<?php

namespace App\Services\Cache\Stores;

use App\Models\User;
use App\Repositories\GradeRepository;
use App\Repositories\QuizAttemptRepository;

/**
 * Cache Store untuk User entity dan turunannya
 *
 * Handles keys:
 *   - user:{id} → find user
 *   - user:{id}:all-attempts → all quiz attempts
 *   - user:{id}:quiz:{quizId}:attempts → attempts for specific quiz
 *   - user:{id}:grades:all → all grades
 *   - user:{id}:performance:summary → performance summary
 *
 * Store/erase hanya berlaku untuk key sederhana (user:{id}).
 */
class UserCacheStore extends BaseCacheStore
{
    protected string $prefix = 'user';

    public function __construct(
        protected QuizAttemptRepository $quizAttemptRepository,
        protected GradeRepository $gradeRepository
    ) {}

    public function load(string $key): mixed
    {
        $userId = $this->extractId($key);
        $subkey = $this->extractSubkey($key);

        return match (true) {
            $subkey === 'all-attempts' => $this->quizAttemptRepository->getUserAttempts($userId),
            str_starts_with((string) $subkey, 'quiz:') => $this->loadAttemptsForQuiz($key, $userId),
            $subkey === 'grades:all' => $this->gradeRepository->getUserGrades($userId),
            $subkey === 'performance:summary' => $this->loadPerformanceSummary($userId),
            $subkey === null => User::find($userId),
            default => User::find($userId),
        };
    }

    /**
     * Load attempts for a specific quiz
     */
    protected function loadAttemptsForQuiz(string $key, int $userId): mixed
    {
        $ids = $this->extractIds($key);
        $quizId = $ids['quiz'] ?? null;

        return $this->quizAttemptRepository->getUserAttempts($userId, $quizId);
    }

    /**
     * Load user performance summary
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

    public function store(string $key, mixed $value): void
    {
        if ($value instanceof User) {
            if ($value->isDirty()) {
                $value->save();
            }

            return;
        }

        $id = $this->extractId($key);
        if ($id > 0 && is_array($value)) {
            User::updateOrCreate(['id' => $id], $value);
        }
    }

    public function erase(string $key): void
    {
        $id = $this->extractId($key);
        if ($id > 0) {
            User::destroy($id);
        }
    }
}
