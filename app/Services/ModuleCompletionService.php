<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Models\Assignment;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\ModuleCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Collection;

class ModuleCompletionService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy
    ) {}

    /**
     * Lazy-loaded CourseCompletionService for cascade trigger.
     */
    private ?CourseCompletionService $courseCompletionService = null;

    private function getCourseCompletionService(): CourseCompletionService
    {
        if ($this->courseCompletionService === null) {
            $this->courseCompletionService = app(CourseCompletionService::class);
        }

        return $this->courseCompletionService;
    }

    /**
     * Resolve the LearningModule for an activity.
     */
    protected function resolveModule(string $moduleType, int $moduleId): ?LearningModule
    {
        return LearningModule::query()
            ->where('module_type', $moduleType)
            ->where('module_id', $moduleId)
            ->first();
    }

    /**
     * Mark completion triggered by material view/download.
     */
    public function completeForMaterial(Material $material, User $actor): ?ModuleCompletion
    {
        $module = $this->resolveModule(LearningModule::TYPE_MATERIAL, $material->id);

        if (! $module || ! $module->completion_enabled || $module->completion_rule !== 'view') {
            return null;
        }

        return $this->markComplete($module, $actor, 'view');
    }

    /**
     * Mark completion triggered by assignment submission.
     */
    public function completeForAssignmentSubmission(Assignment $assignment, Submission $submission, User $actor): ?ModuleCompletion
    {
        $module = $this->resolveModule(LearningModule::TYPE_ASSIGNMENT, $assignment->id);

        if (! $module || ! $module->completion_enabled) {
            return null;
        }

        $source = match ($module->completion_rule) {
            'submit' => 'submit',
            'pass_grade' => $this->isPassingGrade($submission, $assignment) ? 'pass_grade' : null,
            default => null,
        };

        if (! $source) {
            return null;
        }

        $state = $source === 'pass_grade' ? 'complete_passed' : 'complete';

        $completion = ModuleCompletion::query()->updateOrCreate(
            ['learning_module_id' => $module->id, 'user_id' => $actor->id],
            ['state' => $state, 'completed_at' => now(), 'source' => $source],
        );

        // Trigger course completion cascade
        $this->getCourseCompletionService()->onModuleCompletion($module, $actor);

        $this->invalidateCaches($module, $actor);

        return $completion;
    }

    /**
     * Mark completion triggered by quiz attempt submission.
     */
    public function completeForQuizAttempt(Quiz $quiz, QuizAttempt $attempt, User $actor): ?ModuleCompletion
    {
        $module = $this->resolveModule(LearningModule::TYPE_QUIZ, $quiz->id);

        if (! $module || ! $module->completion_enabled) {
            return null;
        }

        $source = match ($module->completion_rule) {
            'finish' => 'finish',
            'pass_grade' => $this->isQuizPassingGrade($attempt, $quiz) ? 'pass_grade' : null,
            default => null,
        };

        if (! $source) {
            return null;
        }

        $state = $source === 'pass_grade' ? 'complete_passed' : 'complete';

        $completion = ModuleCompletion::query()->updateOrCreate(
            ['learning_module_id' => $module->id, 'user_id' => $actor->id],
            ['state' => $state, 'completed_at' => now(), 'source' => $source],
        );

        // Trigger course completion cascade
        $this->getCourseCompletionService()->onModuleCompletion($module, $actor);

        $this->invalidateCaches($module, $actor);

        return $completion;
    }

    /**
     * Check if a submission score meets or exceeds the assignment's pass threshold.
     *
     * Compares raw score against raw pass threshold.
     * Prefers GradeItem.pass_score when available; falls back to assignment-level
     * pass_score; then to 60% of max_score as a last resort.
     */
    protected function isPassingGrade(Submission $submission, Assignment $assignment): bool
    {
        if (! $submission->score || ! $assignment->max_score) {
            return false;
        }

        // Prefer GradeItem.pass_score when available
        $gradeItem = GradeItem::query()
            ->where('course_id', $assignment->course_id)
            ->where('item_type', 'assignment')
            ->where('item_id', $assignment->id)
            ->first();

        $passScore = $gradeItem?->pass_score
            ?? $assignment->pass_score
            ?? $assignment->max_score * 0.6;

        // Compare raw score against raw pass threshold
        return (float) $submission->score >= (float) $passScore;
    }

    /**
     * Check if a quiz attempt score meets or exceeds the quiz's passing score.
     *
     * quiz_attempts.score is already stored as a percentage 0-100 by
     * QuizService::submitQuizAnswers(), so we compare it directly against
     * the pass threshold without rescaling by quiz max points.
     *
     * Prefers GradeItem.pass_score when available; falls back to quiz-level
     * passing_score; then to 60 percent as a last resort.
     */
    protected function isQuizPassingGrade(QuizAttempt $attempt, Quiz $quiz): bool
    {
        if ($attempt->score === null) {
            return false;
        }

        // Prefer GradeItem.pass_score when available (stored as percentage 0-100)
        $gradeItem = GradeItem::query()
            ->where('course_id', $quiz->course_id)
            ->where('item_type', 'quiz')
            ->where('item_id', $quiz->id)
            ->first();

        $passPercentage = (float) ($gradeItem?->pass_score
            ?? $quiz->passing_score
            ?? 60);

        // quiz_attempts.score is already a percentage 0-100
        return (float) $attempt->score >= $passPercentage;
    }

    /**
     * Mark a learning module as complete for a user.
     */
    public function markComplete(LearningModule $module, User $actor, string $source): ModuleCompletion
    {
        $completion = ModuleCompletion::query()->updateOrCreate(
            ['learning_module_id' => $module->id, 'user_id' => $actor->id],
            ['state' => 'complete', 'completed_at' => now(), 'source' => $source],
        );

        // Trigger course completion cascade
        $this->getCourseCompletionService()->onModuleCompletion($module, $actor);

        $this->invalidateCaches($module, $actor);

        return $completion;
    }

    /**
     * Get completion states for a user across multiple modules (batch).
     *
     * @param Collection<int, LearningModule> $modules
     * @return Collection<string, array{completed: bool, state: string, completed_at: ?\Illuminate\Support\Carbon}> keyed by learning_module_id
     */
    public function completionsForUser(User $actor, Collection $modules): Collection
    {
        $moduleIds = $modules->pluck('id');

        $completions = ModuleCompletion::query()
            ->whereIn('learning_module_id', $moduleIds)
            ->where('user_id', $actor->id)
            ->get()
            ->keyBy('learning_module_id');

        return $modules->mapWithKeys(function (LearningModule $module) use ($completions) {
            $completion = $completions->get($module->id);

            return [
                $module->id => $completion
                    ? [
                        'completed' => $completion->state !== 'incomplete',
                        'state' => $completion->state,
                        'completed_at' => $completion->completed_at,
                    ]
                    : ['completed' => false, 'state' => 'incomplete'],
            ];
        });
    }

    /**
     * Get completion state for a user on a module.
     *
     * @return array{completed: bool, state: string, completed_at: ?\Illuminate\Support\Carbon}
     */
    public function completionFor(User $actor, LearningModule $module): array
    {
        $completion = ModuleCompletion::query()
            ->where('learning_module_id', $module->id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $completion) {
            return ['completed' => false, 'state' => 'incomplete'];
        }

        return [
            'completed' => $completion->state !== 'incomplete',
            'state' => $completion->state,
            'completed_at' => $completion->completed_at,
        ];
    }

    protected function invalidateCaches(LearningModule $module, User $actor): void
    {
        $this->cacheStrategy->flushTags([
            "course:{$module->course_id}",
            "course:{$module->course_id}:structure:{$actor->id}",
            "user:{$actor->id}:completions",
        ]);
    }
}
