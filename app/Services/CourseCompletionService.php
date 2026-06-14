<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseCompletion;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseCompletionCriterionCompletion;
use App\Models\Grade;
use App\Models\LearningModule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CourseCompletionService
{
    /**
     * Get all criteria for a course.
     *
     * @return Collection<int, CourseCompletionCriterion>
     */
    public function getCriteria(int $courseId): Collection
    {
        return CourseCompletionCriterion::query()
            ->where('course_id', $courseId)
            ->get();
    }

    /**
     * Get user progress toward course completion.
     *
     * @return array{criteria_met: int, criteria_total: int, completed: bool, completed_at: ?\Illuminate\Support\Carbon, criteria: array}
     */
    public function getUserProgress(int $courseId, int $userId): array
    {
        $cacheKey = "course_completion:progress:{$courseId}:{$userId}";

        return Cache::remember($cacheKey, 3600, function () use ($courseId, $userId) {
            $criteria = $this->getCriteria($courseId);
            $criteriaTotal = $criteria->count();

            if ($criteriaTotal === 0) {
                return [
                    'criteria_met' => 0,
                    'criteria_total' => 0,
                    'completed' => false,
                    'completed_at' => null,
                    'criteria' => [],
                ];
            }

            $criteriaList = [];
            $met = 0;

            foreach ($criteria as $criterion) {
                $completion = CourseCompletionCriterionCompletion::query()
                    ->where('course_completion_criterion_id', $criterion->id)
                    ->where('user_id', $userId)
                    ->first();

                $isMet = $completion !== null && $completion->completed;
                if ($isMet) {
                    $met++;
                }

                $criteriaList[] = [
                    'type' => $criterion->criteriatype,
                    'title' => $this->getCriterionTitle($criterion),
                    'met' => $isMet,
                ];
            }

            $courseCompletion = CourseCompletion::query()
                ->where('course_id', $courseId)
                ->where('user_id', $userId)
                ->first();

            return [
                'criteria_met' => $met,
                'criteria_total' => $criteriaTotal,
                'completed' => $courseCompletion?->timecompleted !== null ?? false,
                'completed_at' => $courseCompletion?->timecompleted,
                'criteria' => $criteriaList,
            ];
        });
    }

    /**
     * Evaluate a single criterion for a user.
     */
    public function evaluateCriterion(int $criterionId, int $userId): bool
    {
        $criterion = CourseCompletionCriterion::findOrFail($criterionId);

        return match ($criterion->criteriatype) {
            'module' => $this->evaluateModuleCriterion($criterion, $userId),
            'grade' => $this->evaluateGradeCriterion($criterion, $userId),
            'date' => $this->evaluateDateCriterion($criterion, $userId),
            default => false,
        };
    }

    /**
     * Evaluate all criteria for a course+user. If ALL are met, mark course complete.
     */
    public function evaluateAll(int $courseId, int $userId): bool
    {
        $criteria = CourseCompletionCriterion::query()
            ->where('course_id', $courseId)
            ->get();

        if ($criteria->isEmpty()) {
            return false;
        }

        foreach ($criteria as $criterion) {
            if (! $this->evaluateCriterion($criterion->id, $userId)) {
                return false; // ALL mode — first failure stops
            }
        }

        // All criteria met — mark course complete
        $this->markCourseComplete($courseId, $userId);

        return true;
    }

    /**
     * Called when a module is completed. Checks if this module is a criterion.
     */
    public function onModuleCompletion(LearningModule $module, User $user): void
    {
        $moduleCriteria = CourseCompletionCriterion::query()
            ->where('course_id', $module->course_id)
            ->where('criteriatype', 'module')
            ->where('module_instance_id', $module->id)
            ->get();

        foreach ($moduleCriteria as $criterion) {
            // Mark criterion completion
            CourseCompletionCriterionCompletion::query()->updateOrCreate(
                [
                    'course_completion_criterion_id' => $criterion->id,
                    'user_id' => $user->id,
                ],
                [
                    'completed' => true,
                    'completed_at' => now(),
                ]
            );

            // Check if this triggers course completion
            $this->evaluateAll($criterion->course_id, $user->id);
        }

        $this->invalidateProgressCache($module->course_id, $user->id);
    }

    /**
     * Called when a grade is updated. Checks if the grade item is a criterion.
     */
    public function onGradeUpdate(int $gradeItemId, int $userId): void
    {
        $gradeCriteria = CourseCompletionCriterion::query()
            ->where('grade_item_id', $gradeItemId)
            ->get();

        foreach ($gradeCriteria as $criterion) {
            if ($this->evaluateGradeCriterion($criterion, $userId)) {
                CourseCompletionCriterionCompletion::query()->updateOrCreate(
                    [
                        'course_completion_criterion_id' => $criterion->id,
                        'user_id' => $userId,
                    ],
                    [
                        'completed' => true,
                        'completed_at' => now(),
                    ]
                );

                // Check if this triggers course completion
                $this->evaluateAll($criterion->course_id, $userId);
            }

            $this->invalidateProgressCache($criterion->course_id, $userId);
        }
    }

    /**
     * Mark a course as complete for a user.
     */
    public function markCourseComplete(int $courseId, int $userId): void
    {
        CourseCompletion::query()->updateOrCreate(
            [
                'course_id' => $courseId,
                'user_id' => $userId,
            ],
            [
                'timecompleted' => now(),
                'reaggregate' => true,
            ]
        );

        $this->invalidateProgressCache($courseId, $userId);
    }

    /**
     * Get the title/description for a criterion.
     */
    private function getCriterionTitle(CourseCompletionCriterion $criterion): string
    {
        return match ($criterion->criteriatype) {
            'module' => $criterion->module?->material?->title
                ?? $criterion->module?->quiz?->title
                ?? $criterion->module?->assignment?->title
                ?? 'Complete module #'.$criterion->module_instance_id,
            'grade' => 'Achieve minimum grade in '.($criterion->gradeItem?->name ?? 'grade item #'.$criterion->grade_item_id),
            'date' => 'Course completed by '.($criterion->time_end?->toDateString() ?? 'specified date'),
            default => $criterion->criteriatype,
        };
    }

    /**
     * Evaluate a module-type criterion.
     */
    private function evaluateModuleCriterion(CourseCompletionCriterion $criterion, int $userId): bool
    {
        if ($criterion->module_instance_id === null) {
            return false;
        }

        $completion = CourseCompletionCriterionCompletion::query()
            ->where('course_completion_criterion_id', $criterion->id)
            ->where('user_id', $userId)
            ->first();

        return $completion !== null && $completion->completed;
    }

    /**
     * Evaluate a grade-type criterion.
     */
    private function evaluateGradeCriterion(CourseCompletionCriterion $criterion, int $userId): bool
    {
        if ($criterion->grade_item_id === null) {
            return false;
        }

        $grade = Grade::query()
            ->where('grade_item_id', $criterion->grade_item_id)
            ->where('user_id', $userId)
            ->first();

        if ($grade === null || $grade->score === null) {
            return false;
        }

        $threshold = $criterion->pass_threshold ?? 0;

        return $grade->score >= $threshold;
    }

    /**
     * Evaluate a date-type criterion.
     */
    private function evaluateDateCriterion(CourseCompletionCriterion $criterion, int $userId): bool
    {
        if ($criterion->time_end === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo($criterion->time_end);
    }

    /**
     * Invalidate cached progress for a course+user.
     */
    private function invalidateProgressCache(int $courseId, int $userId): void
    {
        Cache::forget("course_completion:progress:{$courseId}:{$userId}");
        Cache::forget("course_completion:state:{$courseId}:{$userId}");
    }
}
