<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;

class CourseStructureService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected CourseAccessService $courseAccessService,
        protected ModuleAvailabilityService $moduleAvailabilityService,
        protected ModuleCompletionService $moduleCompletionService,
    ) {}

    /**
     * Get the full course structure for an actor.
     * Returns sections -> modules -> activity summary.
     */
    public function getStructure(int $courseId, User $actor): array
    {
        $course = Course::query()->findOrFail($courseId);

        if (! $this->courseAccessService->canReadCourse($actor, $course)) {
            throw new BusinessException('Akses ditolak', 403);
        }

        return $this->cacheStrategy
            ->tags(['course-structure', "course:{$courseId}", "user:{$actor->id}:completions"])
            ->get(
                "course:{$courseId}:structure:{$actor->id}",
                fn () => $this->buildStructure($course, $actor)
            );
    }

    /**
     * Build the structure array with sections, modules, and activity summaries.
     */
    private function buildStructure(Course $course, User $actor): array
    {
        $isInstructor = $this->courseAccessService->isInstructorForCourse($actor, $course);
        $isStudent = $this->courseAccessService->isActiveEnrollee($actor, $course);

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\CourseSection> $sections */
        $sections = $course->sections()
            ->with(['learningModules' => function ($query): void {
                $query->orderBy('sort_order')->with('availabilityRules');
            }])
            ->get();

        // Collect module IDs by type for batch loading activities
        $allModules = $sections->pluck('learningModules')->flatten();

        $materialIds = $allModules
            ->where('module_type', LearningModule::TYPE_MATERIAL)
            ->pluck('module_id');

        $quizIds = $allModules
            ->where('module_type', LearningModule::TYPE_QUIZ)
            ->pluck('module_id');

        $assignmentIds = $allModules
            ->where('module_type', LearningModule::TYPE_ASSIGNMENT)
            ->pluck('module_id');

        /** @var \Illuminate\Support\Collection<int, Material> $materials */
        $materials = Material::whereIn('id', $materialIds)->get()->keyBy('id');

        /** @var \Illuminate\Support\Collection<int, Quiz> $quizzes */
        $quizzes = Quiz::whereIn('id', $quizIds)->get()->keyBy('id');

        /** @var \Illuminate\Support\Collection<int, Assignment> $assignments */
        $assignments = Assignment::whereIn('id', $assignmentIds)->get()->keyBy('id');

        // For student-specific data, batch load attempts and submissions
        $studentQuizAttemptCounts = collect();
        $studentAssignmentSubmissions = collect();

        if ($isStudent) {
            $studentQuizAttemptCounts = QuizAttempt::query()
                ->whereIn('quiz_id', $quizIds)
                ->where('user_id', $actor->id)
                ->groupBy('quiz_id')
                ->selectRaw('quiz_id, COUNT(*) as count')
                ->pluck('count', 'quiz_id');

            $studentAssignmentSubmissions = Submission::query()
                ->whereIn('assignment_id', $assignmentIds)
                ->where('user_id', $actor->id)
                ->where('is_latest', true)
                ->get()
                ->keyBy('assignment_id');
        }

        $data = [
            'course_id' => $course->id,
            'sections' => [],
        ];

        foreach ($sections as $section) {
            // Skip hidden sections for non-instructors
            if (! $section->visible && ! $isInstructor) {
                continue;
            }

            $modules = [];

            foreach ($section->learningModules as $module) {
                // Skip modules that fail access check
                if (! $this->courseAccessService->canReadModule($actor, $module)) {
                    continue;
                }

                $moduleData = [
                    'id' => $module->id,
                    'module_type' => $module->module_type,
                    'sort_order' => $module->sort_order,
                    'visible' => $module->visible,
                    'activity' => $this->activitySummary(
                        $module,
                        $materials,
                        $quizzes,
                        $assignments,
                        $studentQuizAttemptCounts,
                        $studentAssignmentSubmissions,
                        $isStudent,
                    ),
                ];

                // Availability and completion only for students (instructors see everything)
                if ($isStudent) {
                    $structured = $this->moduleAvailabilityService->structuredAvailabilityFor($actor, $module, false);
                    $moduleData['available'] = $structured['available'];
                    $moduleData['reason'] = $structured['reason'];
                    $moduleData['availability'] = $structured['availability'];
                    $moduleData['completion'] = $this->moduleCompletionService->completionFor($actor, $module);
                } elseif ($isInstructor) {
                    // Instructors see structured metadata with bypass info
                    // but no top-level available/reason (backward compatible)
                    $structured = $this->moduleAvailabilityService->structuredAvailabilityFor($actor, $module, true);
                    $moduleData['availability'] = $structured['availability'];
                }

                $modules[] = $moduleData;
            }

            $data['sections'][] = [
                'id' => $section->id,
                'title' => $section->title,
                'summary' => $section->summary,
                'sort_order' => $section->sort_order,
                'modules' => $modules,
            ];
        }

        return $data;
    }

    /**
     * Build activity summary based on module type.
     */
    private function activitySummary(
        LearningModule $module,
        \Illuminate\Support\Collection $materials,
        \Illuminate\Support\Collection $quizzes,
        \Illuminate\Support\Collection $assignments,
        \Illuminate\Support\Collection $studentQuizAttemptCounts,
        \Illuminate\Support\Collection $studentAssignmentSubmissions,
        bool $isStudent,
    ): ?array {
        return match ($module->module_type) {
            LearningModule::TYPE_MATERIAL => $this->materialSummary($module, $materials),
            LearningModule::TYPE_QUIZ => $this->quizSummary($module, $quizzes, $studentQuizAttemptCounts, $isStudent),
            LearningModule::TYPE_ASSIGNMENT => $this->assignmentSummary($module, $assignments, $studentAssignmentSubmissions, $isStudent),
            default => null,
        };
    }

    private function materialSummary(LearningModule $module, \Illuminate\Support\Collection $materials): ?array
    {
        /** @var Material|null $material */
        $material = $materials->get($module->module_id);

        if ($material === null) {
            return null;
        }

        return [
            'type' => 'material',
            'id' => $material->id,
            'title' => $material->title,
            'file_size' => $material->file_size,
            'mime_type' => $material->mime_type,
            'revision' => $material->revision,
        ];
    }

    private function quizSummary(
        LearningModule $module,
        \Illuminate\Support\Collection $quizzes,
        \Illuminate\Support\Collection $studentQuizAttemptCounts,
        bool $isStudent,
    ): ?array {
        /** @var Quiz|null $quiz */
        $quiz = $quizzes->get($module->module_id);

        if ($quiz === null) {
            return null;
        }

        $summary = [
            'type' => 'quiz',
            'id' => $quiz->id,
            'title' => $quiz->title,
            'time_limit' => $quiz->time_limit,
            'max_attempts' => $quiz->max_attempts,
            'open' => $quiz->available_from,
            'close' => $quiz->available_until,
            'passing_score' => $quiz->passing_score,
        ];

        if ($isStudent) {
            $summary['attempt_count'] = $studentQuizAttemptCounts->get($quiz->id, 0);
        }

        return $summary;
    }

    private function assignmentSummary(
        LearningModule $module,
        \Illuminate\Support\Collection $assignments,
        \Illuminate\Support\Collection $studentAssignmentSubmissions,
        bool $isStudent,
    ): ?array {
        /** @var Assignment|null $assignment */
        $assignment = $assignments->get($module->module_id);

        if ($assignment === null) {
            return null;
        }

        $summary = [
            'type' => 'assignment',
            'id' => $assignment->id,
            'title' => $assignment->title,
            'due_date' => $assignment->due_date,
            'cutoff_date' => $assignment->cutoff_date,
            'max_score' => $assignment->max_score,
            'max_attempts' => $assignment->max_attempts,
        ];

        if ($isStudent) {
            $submission = $studentAssignmentSubmissions->get($assignment->id);
            $summary['submission_status'] = $submission?->status;
        }

        return $summary;
    }
}
