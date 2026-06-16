<?php

namespace App\Services;

use App\Constants\Messages\QuizMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\CourseGroupMember;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\QuizGrade;
use App\Models\QuizAttemptStep;
use App\Models\QuizAttemptStepData;
use App\Models\QuizOverride;
use App\Models\User;
use App\Repositories\QuizAttemptRepository;
use App\Repositories\QuizRepository;
use Illuminate\Support\Facades\App;

class QuizService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected QuizScoringService $quizScoringService,
        protected QuizRepository $quizRepository,
        protected QuizAttemptRepository $quizAttemptRepository,
        protected CourseAccessService $courseAccessService,
        protected ModuleCompletionService $moduleCompletionService
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
     *
     * Uses loadQuizLearningModule (read-only) — no structural rows created during reads.
     */
    public function getQuizWithQuestions(int $quizId)
    {
        return $this->cacheStrategy
            ->tags(['quizzes', "quiz:{$quizId}"])
            ->get(
                "quiz:{$quizId}:with-questions",
                fn () => tap($this->quizRepository->findWithQuestionsAndCourse($quizId), fn ($quiz) => $this->loadQuizLearningModule($quiz))
            );
    }

    /**
     * Get questions for a quiz (cached)
     *
     * Uses loadQuizLearningModule (read-only) — no structural rows created during reads.
     * Actor-specific visibility and availability are enforced by the
     * controller's assertActivityAvailableForRead().
     */
    public function getQuizQuestions(int $quizId)
    {
        return $this->cacheStrategy
            ->tags(['quizzes', "quiz:{$quizId}"])
            ->get(
                "quiz:{$quizId}:questions",
                fn () => tap($this->quizRepository->getQuestions($quizId), function () use ($quizId): void {
                    $quiz = $this->quizRepository->findWithQuestionsAndCourse($quizId);
                    $this->loadQuizLearningModule($quiz);
                })
            );
    }

    /**
     * Get the effective override values for a quiz for a user, considering overrides.
     */
    protected function effectiveQuizOverrides(Quiz $quiz, User $actor): array
    {
        $override = QuizOverride::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $override) {
            $groupIds = CourseGroupMember::query()
                ->where('user_id', $actor->id)
                ->pluck('course_group_id');

            if ($groupIds->isNotEmpty()) {
                $override = QuizOverride::query()
                    ->where('quiz_id', $quiz->id)
                    ->whereIn('course_group_id', $groupIds)
                    ->first();
            }
        }

        return [
            'max_attempts' => $override?->max_attempts ?? $quiz->max_attempts,
            'time_limit' => $override?->time_limit ?? $quiz->time_limit,
            'available_from' => $override?->available_from ?? $quiz->available_from,
            'available_until' => $override?->available_until ?? $quiz->available_until,
            'grace_period' => $override?->grace_period ?? $quiz->grace_period,
        ];
    }

    /**
     * Start a quiz attempt
     */
    public function startQuizAttempt(int $quizId, User $actor): QuizAttempt
    {
        $quiz = $this->quizRepository->findWithQuestionsAndCourse($quizId);
        $this->resolveQuizLearningModule($quiz);

        // Basic existence checks only — timing is checked after override resolution
        if (! $quiz->is_active || ! $quiz->course?->is_active) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }

        // Check full module availability rules for actionability
        if ($quiz->learningModule) {
            $moduleAvailabilityService = App::make(ModuleAvailabilityService::class);
            $availability = $moduleAvailabilityService->availabilityFor($actor, $quiz->learningModule);
            if (! $availability['available']) {
                throw new BusinessException(QuizMessage::NOT_FOUND, 404);
            }
        }

        if (! $this->courseAccessService->canAttemptQuiz($actor, $quiz)) {
            throw new BusinessException('You cannot attempt this quiz', 403);
        }

        $effectiveOverrides = $this->effectiveQuizOverrides($quiz, $actor);

        // Check effective available_from/available_until (after override resolution)
        if ($effectiveOverrides['available_from'] !== null && now()->lt($effectiveOverrides['available_from'])) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }
        if ($effectiveOverrides['available_until'] !== null && now()->gt($effectiveOverrides['available_until'])) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }

        $attemptCount = QuizAttempt::query()
            ->where('quiz_id', $quizId)
            ->where('user_id', $actor->id)
            ->count();

        if ($effectiveOverrides['max_attempts'] > 0 && $attemptCount >= $effectiveOverrides['max_attempts']) {
            throw new BusinessException(QuizMessage::ALREADY_ATTEMPTED, 400);
        }

        $startedAt = now();

        try {
            $attempt = $this->quizAttemptRepository->create([
                'quiz_id' => $quizId,
                'user_id' => $actor->id,
                'answers' => [],
                'status' => 'in_progress',
                'attempt_number' => $attemptCount + 1,
                'started_at' => $startedAt,
                'expires_at' => $effectiveOverrides['time_limit'] ? $startedAt->copy()->addMinutes($effectiveOverrides['time_limit']) : null,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new BusinessException(QuizMessage::ONGOING_ATTEMPT, 400);
        }

        // Create attempt-question rows for each slot (Plan 001: normalized detail)
        $quiz->load(['questionSlots.question']);
        foreach ($quiz->questionSlots as $slot) {
            $attemptQuestion = QuizAttemptQuestion::query()->create([
                'quiz_attempt_id' => $attempt->id,
                'quiz_question_slot_id' => $slot->id,
                'question_id' => $slot->question_id,
                'slot' => $slot->slot,
                'max_points' => $slot->max_points ?? (float) $slot->question?->points ?? 0,
                'state' => 'not_answered',
            ]);

            // Initial step row for review-path realism
            QuizAttemptStep::query()->create([
                'quiz_attempt_question_id' => $attemptQuestion->id,
                'sequence_number' => 0,
                'state' => 'not_answered',
                'user_id' => $actor->id,
            ]);
        }

        $this->cacheStrategy->flushTags(["user:{$actor->id}:attempts"]);

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

        $this->resolveQuizLearningModule($attempt->quiz);

        if (! $attempt->quiz->is_active || ! $attempt->quiz->course?->is_active) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }

        $actor = User::query()->findOrFail($attempt->user_id);
        if (! $this->courseAccessService->isActiveEnrollee($actor, $attempt->quiz->course)) {
            throw new BusinessException('Pengguna tidak memiliki enrolment aktif pada kursus ini', 403);
        }

        if ($attempt->completed_at !== null || $attempt->status !== 'in_progress') {
            throw new BusinessException(QuizMessage::ALREADY_ATTEMPTED, 400);
        }

        $effectiveOverrides = $this->effectiveQuizOverrides($attempt->quiz, $actor);

        // Check time limit with grace period
        if ($attempt->expires_at && now()->gt($attempt->expires_at)) {
            $gracePeriod = $effectiveOverrides['grace_period'];
            if ($gracePeriod > 0 && now()->gt($attempt->expires_at->copy()->addMinutes($gracePeriod))) {
                throw new BusinessException(QuizMessage::TIME_EXPIRED, 400);
            }
            // Within grace period: allow submission
        } elseif ($attempt->quiz->time_limit) {
            // Fallback for attempts without expires_at but with time_limit
            $elapsedMinutes = now()->diffInMinutes($attempt->started_at);
            $effectiveTimeLimit = $effectiveOverrides['time_limit'];
            if ($effectiveTimeLimit > 0 && $elapsedMinutes > $effectiveTimeLimit) {
                $gracePeriod = $effectiveOverrides['grace_period'];
                if ($gracePeriod > 0 && $elapsedMinutes > $effectiveTimeLimit + $gracePeriod) {
                    throw new BusinessException(QuizMessage::TIME_EXPIRED, 400);
                }
            }
        }

        // Load attempt questions for normalized detail writes (Plan 001)
        $attempt->load(['attemptQuestions' => function ($q) {
            $q->with(['question', 'steps']);
        }]);

        $scoringResult = $this->quizScoringService->calculate($attempt->quiz, $answers);

        $updatedAttempt = $this->quizAttemptRepository->update($attemptId, [
            'answers' => $answers,
            'score' => $scoringResult['percentage'],
            'status' => 'finished',
            'completed_at' => now(),
            'submitted_at' => now(),
        ]);

        // Write per-question attempt detail (Plan 001: write fan-out)
        $answeredQuestionIds = array_keys($answers);
        foreach ($attempt->attemptQuestions as $attemptQuestion) {
            $questionId = $attemptQuestion->question_id;

            if (in_array($questionId, $answeredQuestionIds)) {
                $userAnswer = $answers[$questionId];
                $result = $this->quizScoringService->scoreQuestion($attemptQuestion->question, $userAnswer);

                // Update the attempt-question row
                $attemptQuestion->update([
                    'score' => $result['score'],
                    'state' => 'graded',
                ]);

                // Insert a submit step
                $step = QuizAttemptStep::query()->create([
                    'quiz_attempt_question_id' => $attemptQuestion->id,
                    'sequence_number' => $attemptQuestion->steps->count(),
                    'state' => 'graded',
                    'score' => $result['score'],
                    'user_id' => $actor->id,
                ]);

                // Insert step data rows
                QuizAttemptStepData::query()->create([
                    'quiz_attempt_step_id' => $step->id,
                    'name' => 'answer',
                    'value' => is_array($userAnswer) ? json_encode($userAnswer) : (string) $userAnswer,
                ]);
                QuizAttemptStepData::query()->create([
                    'quiz_attempt_step_id' => $step->id,
                    'name' => 'is_correct',
                    'value' => $result['is_correct'] ? '1' : '0',
                ]);
                QuizAttemptStepData::query()->create([
                    'quiz_attempt_step_id' => $step->id,
                    'name' => 'raw_score',
                    'value' => (string) $result['score'],
                ]);
                QuizAttemptStepData::query()->create([
                    'quiz_attempt_step_id' => $step->id,
                    'name' => 'max_points',
                    'value' => (string) $result['max'],
                ]);
            }
            // Unanswered slots stay 'not_answered' — no duplicate step inserted
        }

        $maxScore = (float) $attempt->quiz->questions->sum('points');

        // Resolve or create a grade item for this quiz
        $gradeItem = GradeItem::firstOrCreate([
            'course_id' => $attempt->quiz->course_id,
            'item_type' => 'quiz',
            'item_id' => $attempt->quiz_id,
        ], [
            'name' => $attempt->quiz->title ?? "Quiz {$attempt->quiz_id}",
            'max_score' => $maxScore,
            'source' => 'quiz',
        ]);

        Grade::query()->updateOrCreate([
            'user_id' => $attempt->user_id,
            'course_id' => $attempt->quiz->course_id,
            'gradeable_type' => 'quiz_attempt',
            'gradeable_id' => $attempt->id,
        ], [
            'grade_item_id' => $gradeItem->id,
            'score' => $scoringResult['earned_points'],
            'max_score' => $scoringResult['max_points'],
            'percentage' => $scoringResult['percentage'],
            'status' => 'final',
            'source' => 'quiz',
        ]);

        // Update quiz aggregate grade (Plan 002)
        $this->updateQuizAggregateGrade($attempt->quiz, $actor, $updatedAttempt);

        // Mark completion if module is configured for finish/pass_grade-based completion
        // Pass the updated attempt so pass-grade checks see the actual score
        $this->moduleCompletionService->completeForQuizAttempt($attempt->quiz, $updatedAttempt, $actor);

        // Mark gradebook stale after quiz submission grade update
        app(\App\Services\GradebookRecalculationService::class)
            ->markCourseStale($attempt->quiz->course_id, 'quiz_submission', 'quiz_attempt', $attempt->id);

        $this->cacheStrategy->flushTags([
            "user:{$attempt->user_id}:attempts",
            "quiz:{$attempt->quiz_id}:attempts",
            "attempt:{$attempt->id}:detail",
            "quiz_grade:{$attempt->quiz_id}:{$attempt->user_id}",
            'gradebook',
            "course:{$attempt->quiz->course_id}",
            "user:{$attempt->user_id}:grades",
            "grade_item:{$gradeItem->id}",
            'quiz-attempts',
        ]);

        return $updatedAttempt;
    }

    /**
     * Get quiz attempt result (cached).
     *
     * Returns a domain-shaped array with both legacy flat fields and
     * normalized attempt-question detail. Review visibility rules are
     * applied to both legacy answers and normalized step-data values.
     */
    public function getAttemptResult(int $attemptId)
    {
        return $this->cacheStrategy
            ->tags(['quiz-attempts'])
            ->get(
                "attempt:{$attemptId}:result",
                function () use ($attemptId) {
                    $attempt = $this->quizAttemptRepository->findWithFullDetails($attemptId);

                    $reviewVisibility = $attempt->quiz->review_visibility;
                    $showAnswers = $this->shouldShowAnswers($reviewVisibility, $attempt->quiz);

                    // Build the response array preserving legacy flat fields
                    $result = $attempt->toArray();

                    // Build normalized attempt-questions detail
                    $result['attempt_questions'] = $this->buildAttemptQuestionsDetail($attempt, $showAnswers);

                    // Legacy answers JSON handling
                    if (! $showAnswers) {
                        unset($result['answers']);
                    }

                    // If the attempt has no normalized detail, unset empty collection
                    if (empty($result['attempt_questions'])) {
                        unset($result['attempt_questions']);
                    }

                    return $result;
                }
            );
    }

    /**
     * Determine whether answers should be revealed based on review visibility.
     */
    private function shouldShowAnswers(string $reviewVisibility, $quiz): bool
    {
        if ($reviewVisibility === 'never') {
            return false;
        }

        if ($reviewVisibility === 'after_close'
            && $quiz->available_until
            && now()->lt($quiz->available_until)) {
            return false;
        }

        // 'after_submission' and 'always' show everything
        return true;
    }

    /**
     * Build normalized attempt-question detail from the eager-loaded tree.
     *
     * @return array<int, array>
     */
    private function buildAttemptQuestionsDetail($attempt, bool $showAnswers): array
    {
        if ($attempt->relationLoaded('attemptQuestions') && $attempt->attemptQuestions->isNotEmpty()) {
            return $attempt->attemptQuestions->map(function ($aq) use ($showAnswers) {
                $item = [
                    'slot' => $aq->slot,
                    'question_id' => $aq->question_id,
                    'max_points' => $aq->max_points,
                    'state' => $aq->state,
                    'score' => $aq->score,
                ];

                // Safe question fields (never expose correct_answer)
                if ($aq->relationLoaded('question') && $aq->question) {
                    $item['question_text'] = $aq->question->question_text;
                    $item['question_type'] = $aq->question->question_type ?? 'shortanswer';
                }

                // Ordered steps with step-data filtering
                if ($aq->relationLoaded('steps')) {
                    $item['steps'] = $aq->steps->map(function ($step) use ($showAnswers) {
                        $stepItem = [
                            'sequence_number' => $step->sequence_number,
                            'state' => $step->state,
                        ];

                        if ($step->score !== null) {
                            $stepItem['score'] = $step->score;
                        }

                        if ($step->relationLoaded('stepData')) {
                            if ($showAnswers) {
                                $stepItem['step_data'] = $step->stepData->map(fn ($sd) => [
                                    'name' => $sd->name,
                                    'value' => $sd->value,
                                ])->values()->all();
                            } else {
                                // When answers hidden, only show non-revealing metadata
                                $stepItem['step_data'] = $step->stepData
                                    ->filter(fn ($sd) => ! in_array($sd->name, ['answer', 'is_correct', 'raw_score']))
                                    ->map(fn ($sd) => [
                                        'name' => $sd->name,
                                        'value' => $sd->value,
                                    ])->values()->all();
                            }
                        }

                        return $stepItem;
                    })->values()->all();
                }

                return $item;
            })->values()->all();
        }

        // Legacy fallback: attempt has no normalized detail rows
        return [];
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

    /**
     * Update or create the quiz aggregate grade after an attempt finishes.
     * Uses the quiz's grading_method: highest, latest, average, or first.
     */
    private function updateQuizAggregateGrade(Quiz $quiz, User $actor, QuizAttempt $submittedAttempt): void
    {
        $finishedAttempts = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $actor->id)
            ->where('status', 'finished')
            ->orderBy('completed_at')
            ->get();

        $maxScore = (float) $quiz->questions->sum('points');
        $gradingMethod = $quiz->grading_method ?? 'highest';

        $grade = match ($gradingMethod) {
            'highest' => $finishedAttempts->max('score'),
            'latest' => $submittedAttempt->score,
            'average' => $finishedAttempts->count() > 0
                ? $finishedAttempts->avg('score')
                : $submittedAttempt->score,
            'first' => $finishedAttempts->first()?->score ?? $submittedAttempt->score,
            default => $submittedAttempt->score,
        };

        // quiz_grades.grade is a percentage (0-100) matching the aggregate-grade intent.
        // For raw score semantics, see grades.score (raw) vs grades.percentage.
        $percentage = $maxScore > 0 ? $grade : 0;

        QuizGrade::query()->updateOrCreate(
            [
                'quiz_id' => $quiz->id,
                'user_id' => $actor->id,
            ],
            [
                'grade' => $grade,
                'max_score' => $maxScore,
                'percentage' => $percentage,
                'grading_method' => $gradingMethod,
                'attempt_count' => $finishedAttempts->count(),
                'last_attempt_id' => $submittedAttempt->id,
                'graded_at' => now(),
            ]
        );
    }

    /**
     * Load the LearningModule for a quiz and set the relation.
     * Does NOT create — for read paths, modules must already exist (factories/seeders).
     * Does NOT throw — visibility/availability are enforced by the access layer.
     */
    private function loadQuizLearningModule($quiz): void
    {
        $quiz->loadMissing(['course']);

        $learningModule = LearningModule::query()
            ->where('module_type', LearningModule::TYPE_QUIZ)
            ->where('module_id', $quiz->id)
            ->first();

        $quiz->setRelation('learningModule', $learningModule);
    }

    /**
     * Resolve or create the LearningModule for a quiz and set the relation.
     * Used in write paths (attempt creation) where missing modules may need
     * repair for legacy data. Read paths must use loadQuizLearningModule().
     */
    private function resolveQuizLearningModule($quiz): void
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
    }

    /**
     * Ensure a quiz is visible and available (throws if not).
     * Used for write paths (attempts) where availability must be enforced
     * for all actors before the action proceeds.
     */
    private function ensureQuizVisible($quiz): void
    {
        $this->resolveQuizLearningModule($quiz);

        if (! $quiz->is_active || ! $quiz->course?->is_active || ! $quiz->isOpen() || ! $quiz->learningModule->isAvailable()) {
            throw new BusinessException(QuizMessage::NOT_FOUND, 404);
        }
    }
}
