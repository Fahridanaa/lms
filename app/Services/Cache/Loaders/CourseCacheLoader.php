<?php

namespace App\Services\Cache\Loaders;

use App\Models\Course;
use App\Models\Grade;
use App\Models\User;
use App\Repositories\AssignmentRepository;
use App\Repositories\GradeRepository;
use App\Repositories\MaterialRepository;

/**
 * Cache Loader untuk Course entity dan turunannya
 *
 * Handles keys:
 *   - course:{id} → find course
 *   - course:{id}:materials → getByCourse()
 *   - course:{id}:assignments → getByCourse()
 *   - course:{id}:gradebook → aggregated gradebook
 *   - course:{id}:statistics → course statistics
 *   - course:{id}:top-performers:{limit} → top performers
 *   - course:{id}:user:{userId}:grades → user grades in course
 */
class CourseCacheLoader extends BaseCacheLoader
{
    protected string $prefix = 'course';

    public function __construct(
        protected MaterialRepository $materialRepository,
        protected AssignmentRepository $assignmentRepository,
        protected GradeRepository $gradeRepository
    ) {}

    public function supports(string $key): bool
    {
        if (! parent::supports($key)) {
            return false;
        }

        $subkey = $this->extractSubkey($key);

        return $subkey === null
            || in_array($subkey, ['materials', 'assignments', 'gradebook', 'statistics'], true)
            || str_starts_with((string) $subkey, 'top-performers:')
            || (bool) preg_match('/^user:\d+:grades$/', (string) $subkey);
    }

    public function load(string $key): mixed
    {
        $ids = $this->extractIds($key);
        $courseId = $ids['course'] ?? $this->extractId($key);
        $subkey = $this->extractSubkey($key);

        return match (true) {
            $subkey === 'materials' => $this->materialRepository->getByCourse($courseId),
            $subkey === 'assignments' => $this->assignmentRepository->getByCourse($courseId),
            $subkey === 'gradebook' => $this->loadGradebook($courseId),
            $subkey === 'statistics' => $this->gradeRepository->getCourseStatistics($courseId),
            str_starts_with((string) $subkey, 'top-performers:') => $this->loadTopPerformers($courseId, $subkey),
            str_contains((string) $subkey, 'user:') => $this->loadUserCourseGrades($key, $courseId),
            $subkey === null => Course::with(['instructor'])->find($courseId),
            default => Course::with(['instructor'])->find($courseId),
        };
    }

    /**
     * Load aggregated gradebook for a course
     *
     * Uses SQL aggregation instead of loading all Grade models
     * with polymorphic gradeable relations into memory.
     */
    protected function loadGradebook(int $courseId): array
    {
        // Aggregate grades per student in a single SQL query
        // instead of loading all Grade models + polymorphic gradeable relations
        $studentAverages = Grade::where('course_id', $courseId)
            ->whereNull('deleted_at')
            ->selectRaw('
                user_id,
                AVG(percentage) as average_percentage,
                COUNT(*) as total_grades,
                SUM(CASE WHEN gradeable_type = ? THEN 1 ELSE 0 END) as quiz_count,
                SUM(CASE WHEN gradeable_type = ? THEN 1 ELSE 0 END) as assignment_count
            ', ['quiz_attempt', 'submission'])
            ->groupBy('user_id')
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
            ->get([
                'id',
                'user_id',
                'course_id',
                'gradeable_type',
                'gradeable_id',
                'score',
                'max_score',
                'percentage',
                'created_at',
                'updated_at',
            ])
            ->groupBy('user_id');

        return $studentAverages->map(function ($row) use ($gradesByStudent, $students) {
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
            ];
        })->filter()->values()->all();
    }

    /**
     * Load top performers with limit from subkey
     */
    protected function loadTopPerformers(int $courseId, string $subkey): mixed
    {
        $limit = 10;
        if (preg_match('/top-performers:(\d+)$/', $subkey, $matches)) {
            $limit = (int) $matches[1];
        }

        $studentAverages = $this->gradeRepository->getTopPerformers($courseId, $limit);

        $users = User::whereIn('id', $studentAverages->pluck('user_id'))
            ->get()
            ->keyBy('id');

        return $studentAverages->map(fn ($item) => [
            'user' => $users->get($item->user_id),
            'average_percentage' => $item->average_percentage,
        ]);
    }

    /**
     * Extract userId from key like course:{id}:user:{userId}:grades
     */
    protected function loadUserCourseGrades(string $key, int $courseId): mixed
    {
        $ids = $this->extractIds($key);
        $userId = $ids['user'] ?? 0;

        if ($userId <= 0) {
            return null;
        }

        $grades = $this->gradeRepository->getUserCourseGrades($userId, $courseId);

        return [
            'grades' => $grades,
            'average_percentage' => $grades->avg('percentage'),
            'total_grades' => $grades->count(),
            'quiz_grades' => $grades->where('gradeable_type', 'quiz_attempt'),
            'assignment_grades' => $grades->where('gradeable_type', 'submission'),
        ];
    }
}
