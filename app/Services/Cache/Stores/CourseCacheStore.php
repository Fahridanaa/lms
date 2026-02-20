<?php

namespace App\Services\Cache\Stores;

use App\Models\Course;
use App\Models\User;
use App\Repositories\AssignmentRepository;
use App\Repositories\GradeRepository;
use App\Repositories\MaterialRepository;

/**
 * Cache Store untuk Course-related cache keys
 *
 * Handles keys:
 *   - course:{id}:materials → getMaterials()
 *   - course:{id}:assignments → getAssignments()
 *   - course:{id}:gradebook → computed gradebook
 *   - course:{id}:statistics → getStatistics()
 *   - course:{id}:top-performers:{limit} → getTopPerformers()
 *   - course:{id}:user:{userId}:grades → getUserGrades()
 *
 * Note: Most course-related data is read-heavy.
 * Store operations mainly for grade updates.
 */
class CourseCacheStore extends BaseCacheStore
{
    protected string $prefix = 'course';

    public function __construct(
        protected MaterialRepository $materialRepository,
        protected AssignmentRepository $assignmentRepository,
        protected GradeRepository $gradeRepository
    ) {}

    public function load(string $key): mixed
    {
        $parts = $this->parseKey($key);
        $courseId = (int) ($parts[1] ?? 0);
        $subkey = $parts[2] ?? null;

        // Handle "course:{id}:user:{userId}:grades"
        if ($subkey === 'user' && isset($parts[4]) && $parts[4] === 'grades') {
            $userId = (int) $parts[3];
            return $this->loadUserCourseGrades($courseId, $userId);
        }

        // Handle "course:{id}:top-performers:{limit}"
        if ($subkey === 'top-performers' && isset($parts[3])) {
            $limit = (int) $parts[3];
            return $this->loadTopPerformers($courseId, $limit);
        }

        return match ($subkey) {
            'materials' => $this->materialRepository->getByCourse($courseId),
            'assignments' => $this->assignmentRepository->getByCourse($courseId),
            'gradebook' => $this->loadGradebook($courseId),
            'statistics' => $this->gradeRepository->getCourseStatistics($courseId),
            default => null,
        };
    }

    protected function loadGradebook(int $courseId): array
    {
        $course = Course::with(['students'])->findOrFail($courseId);
        $gradebook = [];

        foreach ($course->students as $student) {
            $grades = $this->gradeRepository->getUserCourseGrades($student->id, $courseId);

            $gradebook[] = [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                ],
                'grades' => $grades,
                'average_percentage' => $grades->avg('percentage'),
                'total_grades' => $grades->count(),
            ];
        }

        return $gradebook;
    }

    protected function loadUserCourseGrades(int $courseId, int $userId): array
    {
        $grades = $this->gradeRepository->getUserCourseGrades($userId, $courseId);

        return [
            'grades' => $grades,
            'average_percentage' => $grades->avg('percentage'),
            'total_grades' => $grades->count(),
            'quiz_grades' => $grades->where('gradeable_type', 'quiz_attempt'),
            'assignment_grades' => $grades->where('gradeable_type', 'submission'),
        ];
    }

    protected function loadTopPerformers(int $courseId, int $limit): mixed
    {
        $studentAverages = $this->gradeRepository->getTopPerformers($courseId, $limit);

        return $studentAverages->map(function ($item) {
            $user = User::find($item->user_id);
            return [
                'user' => $user,
                'average_percentage' => $item->average_percentage,
            ];
        });
    }

    public function store(string $key, mixed $value): void
    {
        // Course data is mostly read-only aggregations
        // Direct updates go through specific repositories
        // This is mainly for cache consistency

        $parts = $this->parseKey($key);
        $subkey = $parts[2] ?? null;

        // Handle grade updates for "course:{id}:user:{userId}:grades"
        if ($subkey === 'user' && isset($parts[4]) && $parts[4] === 'grades') {
            if (isset($value['grades']) && is_iterable($value['grades'])) {
                foreach ($value['grades'] as $grade) {
                    if ($grade instanceof \App\Models\Grade) {
                        $grade->save();
                    }
                }
            }
        }
    }

    public function erase(string $key): void
    {
        // Course aggregations are not directly deletable
        // They are derived from other entities
        // Erase is a no-op for safety
    }
}
