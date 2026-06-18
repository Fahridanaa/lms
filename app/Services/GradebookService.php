<?php

namespace App\Services;

use App\Constants\Messages\GradeMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\Course;
use App\Models\Context;
use App\Models\CourseEnrollment;
use App\Models\Grade;
use App\Models\GradeCategory;
use App\Models\GradeHistory;
use App\Models\GradeItem;
use App\Models\User;
use App\Repositories\GradeRepository;
use Illuminate\Support\Facades\DB;

class GradebookService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected GradeRepository $gradeRepository,
        protected CourseAccessService $courseAccessService
    ) {}

    /**
     * Compute weighted average percentage from a collection of grades.
     * Uses grade_items.weight when available; grades without a grade_item_id default to weight 1.0.
     */
    private function computeWeightedAverage(\Illuminate\Support\Collection $grades): float
    {
        if ($grades->isEmpty()) {
            return 0.0;
        }

        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($grades as $grade) {
            $weight = $grade->gradeItem?->weight ?? 1.0;
            $weightedSum += ($grade->percentage ?? 0) * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0.0;
    }

    /**
     * SQL expression for weighted average that respects grade_items.weight.
     * Must be used with: LEFT JOIN grade_items ON grades.grade_item_id = grade_items.id
     */
    private function weightedAvgSql(string $table = 'grades'): string
    {
        return sprintf(
            'SUM(%1$s.percentage * COALESCE(grade_items.weight, 1.0)) / NULLIF(SUM(COALESCE(grade_items.weight, 1.0)), 0)',
            $table
        );
    }

    /**
     * Check if an actor can read a user's grades across all courses.
     *
     * Self-view always returns true. For instructors, uses a single EXISTS
     * query instead of loading all enrolled courses and checking per-course.
     */
    public function canReadUserGrades(int $userId, User $actor): bool
    {
        if ($actor->id === $userId) {
            return true;
        }

        // Single query: does the actor teach any course where the user is enrolled?
        return Course::query()
            ->where('courses.is_active', true)
            ->where(function ($query) use ($actor) {
                $query->where('courses.instructor_id', $actor->id)
                    ->orWhere(function ($q) use ($actor) {
                        $q->whereExists(function ($subQuery) use ($actor) {
                            $subQuery->select(DB::raw(1))
                                ->from('role_assignments')
                                ->join('contexts', 'role_assignments.context_id', '=', 'contexts.id')
                                ->join('roles', 'role_assignments.role_id', '=', 'roles.id')
                                ->where('role_assignments.user_id', $actor->id)
                                ->where('roles.shortname', 'instructor')
                                ->where('contexts.contextlevel', Context::LEVEL_COURSE)
                                ->whereColumn('contexts.instance_id', 'courses.id');
                        });
                    });
            })
            ->whereExists(function ($subQuery) use ($userId) {
                $subQuery->select(DB::raw(1))
                    ->from('course_enrollments')
                    ->whereColumn('course_enrollments.course_id', 'courses.id')
                    ->where('course_enrollments.user_id', $userId)
                    ->where('course_enrollments.role', 'student')
                    ->where('course_enrollments.status', 'active');
            })
            ->exists();
    }

    /**
     * Get full gradebook for a course (cached)
     * Returns all students with their aggregated grade stats.
     *
     * Loads grade items first and uses them to scope the grade aggregation.
     * Hidden grade items are excluded from student view.
     *
     * Optimized: Uses SQL aggregation instead of loading all Grade models
     * with polymorphic gradeable relations into memory.
     */
    public function getCourseGradebook(int $courseId, User $actor): array
    {
        $course = Course::query()->findOrFail($courseId);

        $isStudent = $this->courseAccessService->isActiveEnrollee($actor, $course);
        $visibilityMode = $isStudent ? 'student-visible' : 'instructor';

        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:gradebook:{$visibilityMode}", function () use ($courseId, $actor, $isStudent) {
                // Clear gradebook stale marker on instructor read (acts as implicit recalculation)
                if (! $isStudent) {
                    app(\App\Services\GradebookRecalculationService::class)->markRecalculated($courseId);
                }
                $activeStudentIds = $this->activeStudentIds($courseId);

                if ($activeStudentIds === []) {
                    return [];
                }

                // Load visible grade items for the course.
                // If actor is a student, exclude hidden grade items.
                $gradeItems = GradeItem::query()
                    ->where('course_id', $courseId)
                    ->when($isStudent, fn ($q) => $q->where('hidden', false))
                    ->get();

                $gradeItemIds = $gradeItems->pluck('id');

                // Aggregate grades per student scoped to visible grade items.
                // Also include legacy grades without a grade_item_id for backward compatibility.
                // Uses weighted average based on grade_items.weight.
                // Use table alias 'g' for grades to avoid ambiguity in the join; suppress
                // the SoftDeletes global scope since we handle soft-delete filtering explicitly.
                $studentAverages = Grade::from('grades', 'g')
                    ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
                    ->leftJoin('grade_items', 'g.grade_item_id', '=', 'grade_items.id')
                    ->where('g.course_id', $courseId)
                    ->whereNull('g.deleted_at')
                    ->where('g.status', 'final')
                    ->whereIn('g.user_id', $activeStudentIds)
                    ->where(function ($q) use ($gradeItemIds) {
                        $q->whereIn('g.grade_item_id', $gradeItemIds)
                          ->orWhereNull('g.grade_item_id');
                    })
                    ->selectRaw('
                        g.user_id,
                        ' . $this->weightedAvgSql('g') . ' as average_percentage,
                        COUNT(*) as total_grades,
                        SUM(CASE WHEN g.gradeable_type = ? THEN 1 ELSE 0 END) as quiz_count,
                        SUM(CASE WHEN g.gradeable_type = ? THEN 1 ELSE 0 END) as assignment_count
                    ', ['quiz_attempt', 'submission'])
                    ->groupBy('g.user_id')
                    ->get();

                if ($studentAverages->isEmpty()) {
                    return [];
                }

                // Batch-load student info in one query instead of per-student
                $students = User::whereIn('id', $studentAverages->pluck('user_id'))
                    ->get()
                    ->keyBy('id');

                $gradesByStudent = Grade::where('course_id', $courseId)
                    ->whereNull('deleted_at')
                    ->where('status', 'final')
                    ->whereIn('user_id', $studentAverages->pluck('user_id'))
                    ->where(function ($q) use ($gradeItemIds) {
                        $q->whereIn('grade_item_id', $gradeItemIds)
                          ->orWhereNull('grade_item_id');
                    })
                    ->with('gradeItem')
                    ->get([
                        'id',
                        'user_id',
                        'course_id',
                        'grade_item_id',
                        'gradeable_type',
                        'gradeable_id',
                        'score',
                        'max_score',
                        'percentage',
                        'feedback',
                        'status',
                        'source',
                        'created_at',
                        'updated_at',
                    ])
                    ->groupBy('user_id');

                // Load grade categories for the course (Plan 003: category hierarchy)
                $categories = GradeCategory::query()
                    ->where('course_id', $courseId)
                    ->with(['gradeItems' => function ($q) use ($isStudent) {
                        if ($isStudent) {
                            $q->where('hidden', false);
                        }
                    }])
                    ->orderBy('depth')
                    ->orderBy('id')
                    ->get();

                $categoryTree = $this->buildCategoryTree($categories);

                return $studentAverages->map(function ($row) use ($gradesByStudent, $students, $categoryTree) {
                    $student = $students->get($row->user_id);
                    if (! $student) {
                        return null;
                    }

                    return [
                        'student' => [
                            'id' => $student->id,
                            'name' => $student->name,
                            'email' => $student->email,
                        ],
                        'grades' => $gradesByStudent->get($row->user_id, collect())->values(),
                        'average_percentage' => round($row->average_percentage, 2),
                        'total_grades' => (int) $row->total_grades,
                        'quiz_count' => (int) $row->quiz_count,
                        'assignment_count' => (int) $row->assignment_count,
                        'categories' => $categoryTree,
                    ];
                })->filter()->values()->all();
            });
    }

    /**
     * Get all grades for a user (cached, actor-aware)
     *
     * - Student self-view: returns grades from all enrolled courses,
     *   excludes hidden grade items.
     * - Instructor view: returns grades only from courses the instructor
     *   teaches. Cache key includes instructor identity to prevent
     *   cross-instructor cache sharing.
     */
    public function getUserGrades(int $userId, User $actor)
    {
        $isSelf = $actor->id === $userId;

        if ($isSelf) {
            return $this->cacheStrategy
                ->tags(['gradebook', "user:{$userId}:grades"])
                ->get("user:{$userId}:grades:student-visible", function () use ($userId) {
                    $grades = $this->gradeRepository->getUserGrades($userId);

                    $hiddenItemIds = GradeItem::query()
                        ->where('hidden', true)
                        ->pluck('id');

                    return $grades->filter(fn ($grade) =>
                        $grade->grade_item_id === null
                        || ! $hiddenItemIds->contains($grade->grade_item_id)
                    )->values();
                });
        }

        // Instructor view: scope to taught courses, include instructor id in cache key
        // Uses a single JOIN query instead of per-course authorization checks (was N+1 query)
        $taughtCourseIds = Course::query()
            ->from('courses')
            ->where('courses.is_active', true)
            ->where(function ($query) use ($actor) {
                // Course owner
                $query->where('courses.instructor_id', $actor->id)
                    // OR has instructor role via role_assignments + contexts
                    ->orWhere(function ($q) use ($actor) {
                        $q->whereExists(function ($subQuery) use ($actor) {
                            $subQuery->select(\Illuminate\Support\Facades\DB::raw(1))
                                ->from('role_assignments')
                                ->join('contexts', 'role_assignments.context_id', '=', 'contexts.id')
                                ->join('roles', 'role_assignments.role_id', '=', 'roles.id')
                                ->where('role_assignments.user_id', $actor->id)
                                ->where('roles.shortname', 'instructor')
                                ->where('contexts.contextlevel', Context::LEVEL_COURSE)
                                ->whereColumn('contexts.instance_id', 'courses.id');
                        });
                    });
            })
            ->pluck('courses.id');

        if ($taughtCourseIds->isEmpty()) {
            return collect();
        }

        return $this->cacheStrategy
            ->tags(['gradebook', "user:{$userId}:grades", "instructor:{$actor->id}"])
            ->get("user:{$userId}:grades:instructor:{$actor->id}", function () use ($userId, $actor, $taughtCourseIds) {
                return $this->gradeRepository->getUserGrades($userId)
                    ->filter(fn ($grade) => $taughtCourseIds->contains($grade->course_id))
                    ->values();
            });
    }

    /**
     * Get user's grades in a specific course (cached, actor-aware)
     *
     * Filters out hidden grade items for student self-views.
     * Instructors see all grades for the courses they teach.
     */
    public function getUserCourseGrades(int $courseId, int $userId, User $actor)
    {
        $isSelf = $actor->id === $userId;
        $visibilityMode = $isSelf ? 'student-visible' : 'instructor';

        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}", "user:{$userId}:grades"])
            ->get("course:{$courseId}:user:{$userId}:grades:{$visibilityMode}", function () use ($courseId, $userId, $isSelf) {
                $grades = $this->gradeRepository->getUserCourseGrades($userId, $courseId);

                if ($isSelf) {
                    // Student self-view: exclude hidden grade items
                    $hiddenItemIds = GradeItem::query()
                        ->where('course_id', $courseId)
                        ->where('hidden', true)
                        ->pluck('id');

                    $grades = $grades->filter(fn ($grade) =>
                        $grade->grade_item_id === null
                        || ! $hiddenItemIds->contains($grade->grade_item_id)
                    )->values();
                }

                $grades->load('gradeItem');

                // Load grade categories for the course (Plan 003)
                $categories = GradeCategory::query()
                    ->where('course_id', $courseId)
                    ->when($isSelf, fn ($q) => $q->where('hidden', false))
                    ->with(['gradeItems' => function ($q) use ($isSelf) {
                        if ($isSelf) {
                            $q->where('hidden', false);
                        }
                    }])
                    ->orderBy('depth')
                    ->orderBy('id')
                    ->get();

                return [
                    'grades' => $grades,
                    'categories' => $this->buildCategoryTree($categories),
                    'average_percentage' => $this->computeWeightedAverage($grades),
                    'total_grades' => $grades->count(),
                    'quiz_grades' => $grades->where('gradeable_type', 'quiz_attempt'),
                    'assignment_grades' => $grades->where('gradeable_type', 'submission'),
                ];
            });
    }

    /**
     * Update or create a grade
     *
     * @throws BusinessException Jika grade item terkunci atau validasi lainnya gagal
     */
    public function updateGrade(int $gradeId, array $data, User $actor)
    {
        $grade = $this->gradeRepository->findOrFail($gradeId);

        // Check if the associated grade item is locked
        $gradeItem = $grade->gradeItem;
        if ($gradeItem && $gradeItem->locked) {
            throw new BusinessException('Grade item is locked and cannot be updated', 403);
        }

        $score = $data['score'] ?? $grade->score;
        $maxScore = $data['max_score'] ?? $grade->max_score;

        if ($score > $maxScore) {
            throw new BusinessException(GradeMessage::EXCEEDS_MAX, 400);
        }

        if ($score < 0 || $maxScore < 0) {
            throw new BusinessException(GradeMessage::NEGATIVE, 400);
        }

        $course = Course::query()->findOrFail($grade->course_id);
        if (! $this->courseAccessService->isInstructorForCourse($actor, $course)) {
            throw new BusinessException('Pengguna tidak memiliki akses untuk memberi nilai pada kursus ini', 403);
        }

        $data['percentage'] = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
        $data['status'] = $data['status'] ?? $grade->status ?? 'final';

        // Record grade history before updating (Plan 003)
        $this->recordGradeHistory($grade, 'updated', $actor, $data);

        $updatedGrade = $this->gradeRepository->update($gradeId, $data);

        // Mark gradebook stale after direct grade update
        app(\App\Services\GradebookRecalculationService::class)
            ->markCourseStale($grade->course_id, 'direct_grade_update', 'grade', $gradeId);

        $flushTags = [
            'gradebook',
            "course:{$grade->course_id}",
            "user:{$grade->user_id}:grades",
        ];

        if ($gradeItem) {
            $flushTags[] = "grade_item:{$gradeItem->id}";
        }

        // Trigger course completion cascade (Plan 02)
        if ($gradeItem) {
            app(\App\Services\CourseCompletionService::class)
                ->onGradeUpdate($gradeItem->id, $grade->user_id);
        }

        $this->cacheStrategy->flushTags($flushTags);

        return $updatedGrade;
    }

    /**
     * Get course statistics (cached)
     */
    public function getCourseStatistics(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:statistics", fn () => $this->gradeRepository->getCourseStatistics($courseId));
    }

    /**
     * Get user's overall performance summary (cached)
     *
     * Excludes hidden grade items from averages and counts.
     * Optimized: Uses SQL aggregation for averages instead of loading
     * all Grade models with polymorphic gradeable relations.
     */
    public function getUserPerformanceSummary(int $userId): array
    {
        return $this->cacheStrategy
            ->tags(["user:{$userId}:grades"])
            ->get("user:{$userId}:performance:summary", function () use ($userId) {
                // Get hidden grade item IDs to exclude
                $hiddenItemIds = GradeItem::query()
                    ->where('hidden', true)
                    ->pluck('id');

                // Per-course aggregated stats in a single SQL query, excluding hidden items
                // Uses weighted average based on grade_items.weight.
                // Use table alias 'g' for grades; suppress the SoftDeletes global scope
                // since we handle soft-delete filtering explicitly with g.deleted_at.
                $courseStats = Grade::from('grades', 'g')
                    ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
                    ->leftJoin('grade_items', 'g.grade_item_id', '=', 'grade_items.id')
                    ->where('g.user_id', $userId)
                    ->whereNull('g.deleted_at')
                    ->where('g.status', 'final')
                    ->where(function ($q) use ($hiddenItemIds) {
                        $q->whereNull('g.grade_item_id')
                          ->orWhereNotIn('g.grade_item_id', $hiddenItemIds);
                    })
                    ->selectRaw('
                        g.course_id,
                        COUNT(*) as total_grades,
                        SUM(g.percentage * COALESCE(grade_items.weight, 1.0)) as raw_weighted_sum,
                        SUM(COALESCE(grade_items.weight, 1.0)) as raw_total_weight,
                        SUM(CASE WHEN g.gradeable_type = ? THEN g.percentage * COALESCE(grade_items.weight, 1.0) ELSE 0 END) as quiz_weighted_sum,
                        SUM(CASE WHEN g.gradeable_type = ? THEN COALESCE(grade_items.weight, 1.0) ELSE 0 END) as quiz_total_weight,
                        SUM(CASE WHEN g.gradeable_type = ? THEN g.percentage * COALESCE(grade_items.weight, 1.0) ELSE 0 END) as assignment_weighted_sum,
                        SUM(CASE WHEN g.gradeable_type = ? THEN COALESCE(grade_items.weight, 1.0) ELSE 0 END) as assignment_total_weight
                    ', ['quiz_attempt', 'quiz_attempt', 'submission', 'submission'])
                    ->groupBy('g.course_id')
                    ->get();

                if ($courseStats->isEmpty()) {
                    return [
                        'total_courses' => 0,
                        'total_grades' => 0,
                        'overall_average' => 0,
                        'quiz_average' => 0,
                        'assignment_average' => 0,
                        'courses_performance' => [],
                    ];
                }

                // Batch-load course info in one query instead of N+1
                $courses = Course::whereIn('id', $courseStats->pluck('course_id'))
                    ->get()
                    ->keyBy('id');

                $totalWeightedSum = (float) $courseStats->sum('raw_weighted_sum');
                $totalWeight = (float) $courseStats->sum('raw_total_weight');

                $totalQuizSum = (float) $courseStats->sum('quiz_weighted_sum');
                $totalQuizWeight = (float) $courseStats->sum('quiz_total_weight');

                $totalAssignmentSum = (float) $courseStats->sum('assignment_weighted_sum');
                $totalAssignmentWeight = (float) $courseStats->sum('assignment_total_weight');

                return [
                    'total_courses' => $courseStats->count(),
                    'total_grades' => (int) $courseStats->sum('total_grades'),
                    'overall_average' => $totalWeight > 0 ? round($totalWeightedSum / $totalWeight, 2) : 0,
                    'quiz_average' => $totalQuizWeight > 0 ? round($totalQuizSum / $totalQuizWeight, 2) : 0,
                    'assignment_average' => $totalAssignmentWeight > 0 ? round($totalAssignmentSum / $totalAssignmentWeight, 2) : 0,
                    'courses_performance' => $courseStats->map(function ($stat) use ($courses) {
                        $course = $courses->get($stat->course_id);
                        if (! $course) {
                            return null;
                        }

                        $courseWeight = (float) ($stat->raw_total_weight ?: 1);

                        return [
                            'course' => $course,
                            'average' => $courseWeight > 0
                                ? round((float) $stat->raw_weighted_sum / $courseWeight, 2)
                                : 0,
                            'count' => (int) $stat->total_grades,
                        ];
                    })->filter()->values(),
                ];
            });
    }

    /**
     * Get top performers in a course (cached)
     */
    public function getTopPerformers(int $courseId, int $limit = 10): mixed
    {
        return $this->cacheStrategy
            ->tags(['gradebook', "course:{$courseId}"])
            ->get("course:{$courseId}:top-performers:{$limit}", function () use ($courseId, $limit) {
                $activeStudentIds = $this->activeStudentIds($courseId);

                // Short-circuit: no active students means no top performers
                if (empty($activeStudentIds)) {
                    return collect();
                }

                // Active-student IDs passed into the query so suspended/expired
                // users cannot consume top-N slots.
                $studentAverages = $this->gradeRepository->getTopPerformers(
                    $courseId, $limit, $activeStudentIds
                );

                $users = User::whereIn('id', $studentAverages->pluck('user_id'))
                    ->get()
                    ->keyBy('id');

                return $studentAverages->map(fn ($item) => [
                    'user' => $users->get($item->user_id),
                    'average_percentage' => $item->average_percentage,
                ]);
            });
    }

    /**
     * Find or create a grade item for a given course and activity.
     */
    protected function resolveGradeItem(int $courseId, string $itemType, ?int $itemId, string $name): GradeItem
    {
        return GradeItem::query()->firstOrCreate(
            ['course_id' => $courseId, 'item_type' => $itemType, 'item_id' => $itemId],
            ['name' => $name, 'max_score' => 100, 'source' => $itemType]
        );
    }

    /**
     * Build a nested category tree from a flat list of categories.
     */
    private function buildCategoryTree($categories): array
    {
        $grouped = $categories->groupBy('parent_id');

        $build = function ($parentId) use ($grouped, &$build): array {
            $children = $grouped->get($parentId, collect());
            return $children->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'weight' => $cat->weight,
                'hidden' => $cat->hidden,
                'aggregation_method' => $cat->aggregation_method,
                'items' => $cat->relationLoaded('gradeItems')
                    ? $cat->gradeItems->map(fn ($item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'weight' => $item->weight,
                        'hidden' => $item->hidden,
                        'max_score' => $item->max_score,
                    ])->values()->all()
                    : [],
                'children' => $build($cat->id),
            ])->values()->all();
        };

        return $build(null);
    }

    /**
     * Record a grade history entry before a grade change.
     */
    public function recordGradeHistory(Grade $grade, string $action, ?User $actor, array $newData = []): void
    {
        GradeHistory::query()->create([
            'grade_id' => $grade->id,
            'action' => $action,
            'old_score' => $grade->score,
            'new_score' => $newData['score'] ?? $grade->score,
            'old_percentage' => $grade->percentage,
            'new_percentage' => $newData['percentage'] ?? ($newData['max_score'] ?? $grade->max_score) > 0
                ? (($newData['score'] ?? $grade->score) / ($newData['max_score'] ?? $grade->max_score)) * 100
                : 0,
            'old_status' => $grade->status,
            'new_status' => $newData['status'] ?? $grade->status,
            'old_feedback' => $grade->feedback,
            'new_feedback' => $newData['feedback'] ?? $grade->feedback,
            'changed_by' => $actor?->id,
        ]);
    }

    private function activeStudentIds(int $courseId): array
    {
        return CourseEnrollment::query()
            ->where('course_id', $courseId)
            ->where('role', 'student')
            ->get()
            ->filter(fn (CourseEnrollment $enrollment): bool => $enrollment->isActive())
            ->pluck('user_id')
            ->all();
    }
}
