<?php

namespace App\Repositories;

use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class QuizAttemptRepository extends BaseRepository
{
    public function __construct(QuizAttempt $model)
    {
        $this->model = $model;
    }

    /**
     * Find attempt with quiz and questions
     */
    public function findWithQuizAndQuestions(int $id): Model
    {
        return $this->findOrFail($id, ['quiz.questions']);
    }

    /**
     * Find attempt with quiz, questions, and user
     */
    public function findWithFullDetails(int $id): Model
    {
        return $this->findOrFail($id, ['quiz.questions', 'user']);
    }

    /**
     * Get user's quiz attempts
     */
    public function getUserAttempts(int $userId, ?int $quizId = null): Collection
    {
        $query = $this->model->newQuery()
            ->with(['quiz'])
            ->where('user_id', $userId);

        if ($quizId !== null) {
            $query->where('quiz_id', $quizId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get quiz attempts
     */
    public function getQuizAttempts(int $quizId): Collection
    {
        return $this->where('quiz_id', $quizId, ['user']);
    }

    /**
     * Get completed attempts for a quiz
     */
    public function getCompletedAttempts(int $quizId): Collection
    {
        return $this->model->newQuery()
            ->where('quiz_id', $quizId)
            ->whereNotNull('completed_at')
            ->with(['user'])
            ->orderBy('score', 'desc')
            ->get();
    }

    /**
     * Get user's best attempt for a quiz
     */
    public function getUserBestAttempt(int $userId, int $quizId): ?Model
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('quiz_id', $quizId)
            ->whereNotNull('completed_at')
            ->orderBy('score', 'desc')
            ->first();
    }

    /**
     * Get average score for a quiz
     */
    public function getAverageScore(int $quizId): float
    {
        return $this->model->newQuery()
            ->where('quiz_id', $quizId)
            ->whereNotNull('completed_at')
            ->avg('score') ?? 0.0;
    }

    /**
     * Count attempts by user and quiz
     */
    public function countUserAttempts(int $userId, int $quizId): int
    {
        return $this->count([
            'user_id' => $userId,
            'quiz_id' => $quizId,
        ]);
    }
}
