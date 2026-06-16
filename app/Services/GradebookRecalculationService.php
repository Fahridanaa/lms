<?php

namespace App\Services;

use App\Models\GradebookRecalculation;

class GradebookRecalculationService
{
    /**
     * Mark a course gradebook as stale, meaning it needs recomputation.
     * Creates or updates the recalculation marker for the course.
     */
    public function markCourseStale(int $courseId, string $reason, string $sourceType, int $sourceId): void
    {
        GradebookRecalculation::query()->updateOrCreate(
            ['course_id' => $courseId],
            [
                'reason' => $reason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'marked_at' => now(),
                'recalculated_at' => null,
            ]
        );
    }

    /**
     * Check if a course gradebook is currently stale (needs recomputation).
     */
    public function isCourseStale(int $courseId): bool
    {
        /** @var GradebookRecalculation|null $recalc */
        $recalc = GradebookRecalculation::query()
            ->where('course_id', $courseId)
            ->first();

        if ($recalc === null) {
            return false;
        }

        return $recalc->marked_at !== null
            && ($recalc->recalculated_at === null
                || $recalc->marked_at->gt($recalc->recalculated_at));
    }

    /**
     * Get the recalculation state for a course, including reason and timing.
     * Returns null if never marked stale.
     *
     * @return array{stale: bool, reason: string|null, source_type: string|null, source_id: int|null, marked_at: string|null, recalculated_at: string|null}|null
     */
    public function getRecalculationState(int $courseId): ?array
    {
        /** @var GradebookRecalculation|null $recalc */
        $recalc = GradebookRecalculation::query()
            ->where('course_id', $courseId)
            ->first();

        if ($recalc === null) {
            return null;
        }

        return [
            'stale' => $recalc->marked_at !== null
                && ($recalc->recalculated_at === null
                    || $recalc->marked_at->gt($recalc->recalculated_at)),
            'reason' => $recalc->reason,
            'source_type' => $recalc->source_type,
            'source_id' => $recalc->source_id,
            'marked_at' => $recalc->marked_at?->toIso8601String(),
            'recalculated_at' => $recalc->recalculated_at?->toIso8601String(),
        ];
    }

    /**
     * Mark a course gradebook as recalculated (clears the stale state).
     */
    public function markRecalculated(int $courseId): void
    {
        GradebookRecalculation::query()
            ->where('course_id', $courseId)
            ->update(['recalculated_at' => now()]);

        // If no row exists, nothing to clear
    }

    /**
     * Get all stale course IDs.
     *
     * @return array<int>
     */
    public function getStaleCourseIds(): array
    {
        return GradebookRecalculation::query()
            ->whereNotNull('marked_at')
            ->where(function ($q) {
                $q->whereNull('recalculated_at')
                  ->orWhereColumn('marked_at', '>', 'recalculated_at');
            })
            ->pluck('course_id')
            ->all();
    }
}
