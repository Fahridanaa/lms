<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Models\Quiz;
use App\Models\QuizAttempt;

class QuizService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy
    ) {}

    /**
     * Get all quizzes (cached)
     */
    public function getAllQuizzes()
    {
        return $this->cacheStrategy
            ->tags(['quizzes'])
            ->get('quizzes:all', function () {
                return Quiz::with('course')->get();
            });
    }

    /**
     * Get quiz by ID with questions (cached)
     */
    public function getQuizWithQuestions(int $quizId)
    {
        return $this->cacheStrategy
            ->tags(['quizzes', "quiz:{$quizId}"])
            ->get("quiz:{$quizId}:with-questions", function () use ($quizId) {
                return Quiz::with(['questions', 'course'])->findOrFail($quizId);
            });
    }

    /**
     * Get questions for a quiz (cached)
     */
    public function getQuizQuestions(int $quizId)
    {
        return $this->cacheStrategy
            ->tags(['quizzes', "quiz:{$quizId}"])
            ->get("quiz:{$quizId}:questions", function () use ($quizId) {
                $quiz = Quiz::findOrFail($quizId);
                return $quiz->questions;
            });
    }

    /**
     * Start a quiz attempt
     */
    public function startQuizAttempt(int $quizId, int $userId): QuizAttempt
    {
        $attempt = QuizAttempt::create([
            'quiz_id' => $quizId,
            'user_id' => $userId,
            'answers' => [],
            'started_at' => now(),
        ]);

        // Invalidate user's quiz attempts cache
        $this->cacheStrategy->flushTags(["user:{$userId}:attempts"]);

        return $attempt;
    }

    /**
     * Submit quiz answers
     */
    public function submitQuizAnswers(int $attemptId, array $answers): QuizAttempt
    {
        $attempt = QuizAttempt::with('quiz.questions')->findOrFail($attemptId);

        // Calculate score
        $score = $this->calculateScore($attempt->quiz, $answers);

        $attempt->update([
            'answers' => $answers,
            'score' => $score,
            'completed_at' => now(),
        ]);

        // Invalidate related caches
        $this->cacheStrategy->flushTags([
            "user:{$attempt->user_id}:attempts",
            "quiz:{$attempt->quiz_id}:attempts",
        ]);

        return $attempt->fresh();
    }

    /**
     * Get quiz attempt result (cached)
     */
    public function getAttemptResult(int $attemptId)
    {
        return $this->cacheStrategy
            ->tags(['quiz-attempts'])
            ->get("attempt:{$attemptId}:result", function () use ($attemptId) {
                return QuizAttempt::with(['quiz.questions', 'user'])
                    ->findOrFail($attemptId);
            });
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
            ->get($cacheKey, function () use ($userId, $quizId) {
                $query = QuizAttempt::with(['quiz'])
                    ->where('user_id', $userId);

                if ($quizId) {
                    $query->where('quiz_id', $quizId);
                }

                return $query->orderBy('created_at', 'desc')->get();
            });
    }

    /**
     * Calculate quiz score based on answers
     */
    protected function calculateScore(Quiz $quiz, array $answers): float
    {
        $totalPoints = 0;
        $earnedPoints = 0;

        foreach ($quiz->questions as $question) {
            $totalPoints += $question->points;

            $userAnswer = $answers[$question->id] ?? null;
            if ($userAnswer === $question->correct_answer) {
                $earnedPoints += $question->points;
            }
        }

        return $totalPoints > 0
            ? ($earnedPoints / $totalPoints) * 100
            : 0;
    }
}
