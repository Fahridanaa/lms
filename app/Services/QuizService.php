<?php

namespace App\Services;

use App\Constants\Messages\QuizMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\QuizAttempt;
use App\Repositories\QuizAttemptRepository;
use App\Repositories\QuizRepository;

class QuizService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected QuizScoringService $quizScoringService,
        protected QuizRepository $quizRepository,
        protected QuizAttemptRepository $quizAttemptRepository
    ) {
    }

    /**
     * Get all quizzes (cached)
     */
    public function getAllQuizzes()
    {
        return $this->cacheStrategy
            ->tags(['quizzes'])
            ->get(
                'quizzes:all',
                fn() => $this->quizRepository->getAllWithCourse()
            );
    }

    /**
     * Get quiz by ID with questions (cached)
     */
    public function getQuizWithQuestions(int $quizId)
    {
        return $this->cacheStrategy
            ->tags(['quizzes', "quiz:{$quizId}"])
            ->get(
                "quiz:{$quizId}:with-questions",
                fn() => $this->quizRepository->findWithQuestionsAndCourse($quizId)
            );
    }

    /**
     * Get questions for a quiz (cached)
     */
    public function getQuizQuestions(int $quizId)
    {
        return $this->cacheStrategy
            ->tags(['quizzes', "quiz:{$quizId}"])
            ->get(
                "quiz:{$quizId}:questions",
                fn() => $this->quizRepository->getQuestions($quizId)
            );
    }

    /**
     * Start a quiz attempt
     */
    public function startQuizAttempt(int $quizId, int $userId): QuizAttempt
    {
        $this->quizRepository->findOrFail($quizId);

        try {
            $attempt = $this->quizAttemptRepository->create([
                'quiz_id' => $quizId,
                'user_id' => $userId,
                'answers' => [],
                'started_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new BusinessException(QuizMessage::ONGOING_ATTEMPT, 400);
        }

        $this->cacheStrategy->flushTags(["user:{$userId}:attempts"]);

        return $attempt;
    }

    /**
     * Submit quiz answers
     *
     * @throws BusinessException Jika attempt tidak ditemukan atau quizId mismatch
     */
    public function submitQuizAnswers(int $attemptId, array $answers, ?int $expectedQuizId = null): QuizAttempt
    {
        $attempt = $this->quizAttemptRepository->findWithQuizAndQuestions($attemptId);

        // Validasi quizId jika diberikan
        if ($expectedQuizId !== null && $attempt->quiz_id !== $expectedQuizId) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }

        if ($attempt->completed_at !== null) {
            throw new BusinessException(QuizMessage::ALREADY_ATTEMPTED, 400);
        }

        if ($attempt->quiz->time_limit) {
            $elapsedMinutes = now()->diffInMinutes($attempt->started_at);
            if ($elapsedMinutes > $attempt->quiz->time_limit) {
                throw new BusinessException(QuizMessage::TIME_EXPIRED, 400);
            }
        }

        $score = $this->quizScoringService->calculate($attempt->quiz, $answers);

        $updatedAttempt = $this->quizAttemptRepository->update($attemptId, [
            'answers' => $answers,
            'score' => $score,
            'completed_at' => now(),
        ]);

        $this->cacheStrategy->flushTags([
            "user:{$attempt->user_id}:attempts",
            "quiz:{$attempt->quiz_id}:attempts",
        ]);

        return $updatedAttempt;
    }

    /**
     * Get quiz attempt result (cached)
     */
    public function getAttemptResult(int $attemptId)
    {
        return $this->cacheStrategy
            ->tags(['quiz-attempts'])
            ->get(
                "attempt:{$attemptId}:result",
                fn() => $this->quizAttemptRepository->findWithFullDetails($attemptId)
            );
    }

    /**
     * Get user's quiz attempts (cached)
     */
    public function getUserQuizAttempts(int $userId, ?int $quizId = null)
    {
        $cacheKey = $quizId
            ? "user:{$userId}:quiz:{$quizId}:attempts"
            : "user:{$userId}:all-attempts";

        return $this->cacheStrategy
            ->tags(["user:{$userId}:attempts"])
            ->get($cacheKey, fn() => $this->quizAttemptRepository->getUserAttempts($userId, $quizId));
    }
}
