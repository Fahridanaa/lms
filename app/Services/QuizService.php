<?php

namespace App\Services;

use App\Constants\Messages\QuizMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\CourseEnrollment;
use App\Models\Grade;
use App\Models\LearningModule;
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
    ) {}

    /**
     * Get all quizzes (cached)
     */
    public function getAllQuizzes()
    {
        return $this->cacheStrategy
            ->tags(['quizzes'])
            ->get(
                'quizzes:all',
                fn () => $this->quizRepository->getAllWithCourse()
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
                fn () => tap($this->quizRepository->findWithQuestionsAndCourse($quizId), fn ($quiz) => $this->ensureQuizVisible($quiz))
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
                fn () => tap($this->quizRepository->getQuestions($quizId), function () use ($quizId): void {
                    $quiz = $this->quizRepository->findWithQuestionsAndCourse($quizId);
                    $this->ensureQuizVisible($quiz);
                })
            );
    }

    /**
     * Start a quiz attempt
     */
    public function startQuizAttempt(int $quizId, int $userId): QuizAttempt
    {
        $quiz = $this->quizRepository->findWithQuestionsAndCourse($quizId);
        $this->ensureQuizVisible($quiz);
        $this->ensureActiveEnrollment($quiz->course_id, $userId);

        $attemptCount = QuizAttempt::query()
            ->where('quiz_id', $quizId)
            ->where('user_id', $userId)
            ->count();

        if ($quiz->max_attempts > 0 && $attemptCount >= $quiz->max_attempts) {
            throw new BusinessException(QuizMessage::ALREADY_ATTEMPTED, 400);
        }

        $startedAt = now();

        try {
            $attempt = $this->quizAttemptRepository->create([
                'quiz_id' => $quizId,
                'user_id' => $userId,
                'answers' => [],
                'status' => 'in_progress',
                'attempt_number' => $attemptCount + 1,
                'started_at' => $startedAt,
                'expires_at' => $quiz->time_limit ? $startedAt->copy()->addMinutes($quiz->time_limit) : null,
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

        $this->ensureQuizVisible($attempt->quiz);
        $this->ensureActiveEnrollment($attempt->quiz->course_id, $attempt->user_id);

        if ($attempt->completed_at !== null || $attempt->status !== 'in_progress') {
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
            'status' => 'finished',
            'completed_at' => now(),
            'submitted_at' => now(),
        ]);

        $maxScore = (float) $attempt->quiz->questions->sum('points');

        Grade::query()->updateOrCreate([
            'user_id' => $attempt->user_id,
            'course_id' => $attempt->quiz->course_id,
            'gradeable_type' => 'quiz_attempt',
            'gradeable_id' => $attempt->id,
        ], [
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $maxScore > 0 ? ($score / $maxScore) * 100 : 0,
            'status' => 'final',
            'source' => 'quiz',
        ]);

        $this->cacheStrategy->flushTags([
            "user:{$attempt->user_id}:attempts",
            "quiz:{$attempt->quiz_id}:attempts",
            'gradebook',
            "course:{$attempt->quiz->course_id}",
            "user:{$attempt->user_id}:grades",
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
                fn () => $this->quizAttemptRepository->findWithFullDetails($attemptId)
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
            ->get($cacheKey, fn () => $this->quizAttemptRepository->getUserAttempts($userId, $quizId));
    }

    private function ensureQuizVisible($quiz): void
    {
        $quiz->loadMissing(['course']);

        $learningModule = LearningModule::query()
            ->where('module_type', LearningModule::TYPE_QUIZ)
            ->where('module_id', $quiz->id)
            ->first();

        if ($learningModule === null) {
            $learningModule = $quiz->learningModule()->create([
                'course_id' => $quiz->course_id,
                'module_type' => LearningModule::TYPE_QUIZ,
                'visible' => true,
                'sort_order' => $quiz->id,
            ]);
        }

        $quiz->setRelation('learningModule', $learningModule);

        if (! $quiz->is_active || ! $quiz->course?->is_active || ! $quiz->isOpen() || ! $learningModule->isAvailable()) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }
    }

    private function ensureActiveEnrollment(int $courseId, int $userId): void
    {
        $enrollment = CourseEnrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->first();

        if (! $enrollment?->isActive()) {
            throw new BusinessException('Pengguna tidak memiliki enrolment aktif pada kursus ini', 403);
        }
    }
}
