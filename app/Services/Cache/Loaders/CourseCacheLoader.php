<?php

namespace App\Services\Cache\Loaders;

use App\Models\Course;
use App\Models\User;
use App\Repositories\AssignmentRepository;
use App\Repositories\GradeRepository;
use App\Repositories\MaterialRepository;

/**
 * Cache Loader untuk Course-related cache keys
 *
 * Handles keys:
 *   - course:{id}:materials → getMaterials()
 *   - course:{id}:assignments → getAssignments()
 *   - course:{id}:gradebook → computed gradebook
 *   - course:{id}:statistics → getStatistics()
 *   - course:{id}:top-performers:{limit} → getTopPerformers() with user data
 *   - course:{id}:user:{userId}:grades → getUserGrades with computed fields
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

    /**
     * Load full gradebook for a course
     */
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

    /**
     * Load user's grades in a course with computed fields
     */
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

    /**
     * Load top performers with user data
     */
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
}
