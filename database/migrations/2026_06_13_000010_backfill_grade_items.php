<?php

use App\Models\Grade;
use App\Models\GradeItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill grade_items from existing grades records.
     * Idempotent: skips grades that already have a grade_item_id set.
     */
    public function up(): void
    {
        $createdCount = 0;
        $updatedCount = 0;

        // Backfill quiz-attempt based grade items
        $createdCount += $this->backfillForGradeableType(
            ['quiz_attempt', '%QuizAttempt%'],
            'quiz',
        );

        // Backfill submission based grade items
        $createdCount += $this->backfillForGradeableType(
            ['submission', '%Submission%'],
            'assignment',
        );

        // Not using \$this->command as anonymous migrations don't have it
    }

    /**
     * Create GradeItem records for a given gradeable type pattern.
     *
     * @param  array<int, string>  $typePatterns  LIKE patterns to match gradeable_type
     * @param  string  $itemType  value for grade_items.item_type
     * @return int  number of grade items created
     */
    private function backfillForGradeableType(array $typePatterns, string $itemType): int
    {
        $createdCount = 0;

        // Build WHERE conditions for gradeable_type matching
        $whereRaw = collect($typePatterns)->map(function (string $pattern, int $i) {
            if (str_starts_with($pattern, '%')) {
                return "gradeable_type LIKE ?";
            }

            return "gradeable_type = ?";
        })->implode(' OR ');

        $bindings = collect($typePatterns)->map(function (string $pattern) {
            return $pattern;
        })->toArray();

        // Get unique (course_id, gradeable_id) pairs that don't yet have a grade_item_id
        $groups = DB::table('grades')
            ->whereRaw($whereRaw, $bindings)
            ->whereNull('grade_item_id')
            ->whereNull('deleted_at')
            ->select('course_id', 'gradeable_id')
            ->distinct()
            ->get()
            ->groupBy('course_id');

        foreach ($groups as $courseId => $items) {
            foreach ($items as $item) {
                $gradeItem = GradeItem::create([
                    'course_id' => $courseId,
                    'item_type' => $itemType,
                    'item_id' => $item->gradeable_id,
                    'name' => match ($itemType) {
                        'quiz' => "Quiz {$item->gradeable_id}",
                        'assignment' => "Assignment {$item->gradeable_id}",
                        default => "Grade Item {$item->gradeable_id}",
                    },
                    'max_score' => 100,
                    'source' => $itemType,
                ]);

                $updated = Grade::where('course_id', $courseId)
                    ->whereNull('grade_item_id')
                    ->where(function ($q) use ($typePatterns) {
                        foreach ($typePatterns as $pattern) {
                            if (str_starts_with($pattern, '%')) {
                                $q->orWhere('gradeable_type', 'LIKE', $pattern);
                            } else {
                                $q->orWhere('gradeable_type', $pattern);
                            }
                        }
                    })
                    ->where('gradeable_id', $item->gradeable_id)
                    ->update(['grade_item_id' => $gradeItem->id]);

                $createdCount++;
            }
        }

        return $createdCount;
    }

    /**
     * Reverse the backfill by removing grade items that were created by this migration.
     * We target grade items that have no associated grades (since we removed the link below).
     */
    public function down(): void
    {
        // Unlink grades that were linked during the up() pass
        Grade::whereNotNull('grade_item_id')->update(['grade_item_id' => null]);

        // Remove orphan grade items (those not referenced by any grade or availability rule)
        GradeItem::query()->delete();
    }
};
