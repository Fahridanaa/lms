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
     */
    protected function loadGradebook(int $courseId): array
    {
        $course = Course::with(['students'])->findOrFail($courseId);

        $allGrades = Grade::with(['gradeable'])
            ->where('course_id', $courseId)
            ->whereIn('user_id', $course->students->pluck('id'))
            ->get()
            ->groupBy('user_id');

        return $course->students->map(function (User $student) use ($allGrades) {
            $grades = $allGrades->get($student->id, collect());

            return [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                ],
                'grades' => $grades,
                'average_percentage' => $grades->avg('percentage'),
                'total_grades' => $grades->count(),
            ];
        })->values()->all();
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
