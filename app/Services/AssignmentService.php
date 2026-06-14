<?php

namespace App\Services;

use App\Constants\Messages\AssignmentMessage;
use App\Constants\Messages\GradeMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\Assignment;
use App\Models\AssignmentAllocatedMarker;
use App\Models\AssignmentMark;
use App\Models\AssignmentOverride;
use App\Models\Course;
use App\Models\CourseGroupMember;
use App\Models\FileRecord;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\Submission;
use App\Models\User;
use App\Repositories\AssignmentRepository;
use App\Repositories\SubmissionRepository;
use Illuminate\Support\Facades\App;

class AssignmentService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected AssignmentRepository $assignmentRepository,
        protected SubmissionRepository $submissionRepository,
        protected CourseAccessService $courseAccessService,
        protected ModuleCompletionService $moduleCompletionService
    ) {}

    /**
     * Get all assignments for a course, filtered by what the actor can read (cached).
     *
     * The actor parameter makes the cache key actor-specific so that
     * group-restricted, hidden, or unavailable modules are filtered per user.
     */
    public function getCourseAssignments(int $courseId, ?User $actor = null)
    {
        return $this->cacheStrategy
            ->tags(['assignments', "course:{$courseId}"])
            ->get(
                $actor !== null
                    ? "course:{$courseId}:assignments:actor:{$actor->id}"
                    : "course:{$courseId}:assignments",
                function () use ($courseId, $actor) {
                    $assignments = $this->assignmentRepository->getByCourse($courseId);

                    if ($actor === null) {
                        return $assignments;
                    }

                    return collect($assignments)->filter(function ($assignment) use ($actor) {
                        if (! $assignment->learningModule) {
                            return false;
                        }

                        // Use canReadActivity (course + module visibility + group restriction)
                        return $this->courseAccessService->canReadActivity($actor, $assignment->learningModule);
                    })->values();
                }
            );
    }

    /**
     * Get assignment by ID (cached)
     *
     * Uses loadAssignmentLearningModule (read-only) — no structural rows created during reads.
     */
    public function getAssignmentById(int $assignmentId): mixed
    {
        return $this->cacheStrategy
            ->tags(['assignments', "assignment:{$assignmentId}"])
            ->get(
                "assignment:{$assignmentId}",
                fn () => tap($this->assignmentRepository->findWithCourse($assignmentId), fn ($assignment) => $this->loadAssignmentLearningModule($assignment))
            );
    }

    /**
     * Get the effective due_date and cutoff_date for a user, considering overrides.
     */
    protected function effectiveAssignmentDeadlines(Assignment $assignment, User $actor): array
    {
        // Check user-specific override first
        $override = AssignmentOverride::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $override) {
            // Check group-based override
            $groupIds = CourseGroupMember::query()
                ->where('user_id', $actor->id)
                ->pluck('course_group_id');

            if ($groupIds->isNotEmpty()) {
                $override = AssignmentOverride::query()
                    ->where('assignment_id', $assignment->id)
                    ->whereIn('course_group_id', $groupIds)
                    ->first();
            }
        }

        return [
            'due_date' => $override?->due_date ?? $assignment->due_date,
            'cutoff_date' => $override?->cutoff_date ?? $assignment->cutoff_date,
            'max_attempts' => $override?->max_attempts ?? $assignment->max_attempts,
            'available_from' => $override?->available_from ?? $assignment->available_from,
        ];
    }

    /**
     * Submit assignment
     */
    public function submitAssignment(int $assignmentId, User $actor, string $data): Submission
    {
        $assignment = $this->assignmentRepository->findOrFail($assignmentId);
        $assignment->loadMissing(['course', 'learningModule']);
        $this->resolveAssignmentLearningModule($assignment);

        // Basic existence checks only — timing is checked after override resolution
        if (! $assignment->is_active || ! $assignment->course?->is_active) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }

        // Check full module availability rules for actionability
        if ($assignment->learningModule) {
            $moduleAvailabilityService = App::make(ModuleAvailabilityService::class);
            $availability = $moduleAvailabilityService->availabilityFor($actor, $assignment->learningModule);
            if (! $availability['available']) {
                throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
            }
        }

        if (! $this->courseAccessService->canSubmitAssignment($actor, $assignment)) {
            throw new BusinessException('You do not have permission to submit to this assignment', 403);
        }

        $effectiveDeadlines = $this->effectiveAssignmentDeadlines($assignment, $actor);

        // Check available_from (after override resolution)
        if ($effectiveDeadlines['available_from'] !== null && $effectiveDeadlines['available_from']->gt(now())) {
            throw new BusinessException(AssignmentMessage::DEADLINE_PASSED, 400);
        }

        if (! $assignment->allow_late_submission && $effectiveDeadlines['due_date'] !== null && $effectiveDeadlines['due_date'] < now()) {
            throw new BusinessException(AssignmentMessage::DEADLINE_PASSED, 400);
        }

        if ($effectiveDeadlines['cutoff_date'] !== null && $effectiveDeadlines['cutoff_date'] < now()) {
            throw new BusinessException(AssignmentMessage::DEADLINE_PASSED, 400);
        }

        $userId = $actor->id;

        $attemptCount = Submission::query()
            ->where('assignment_id', $assignmentId)
            ->where('user_id', $userId)
            ->count();

        if ($attemptCount >= $effectiveDeadlines['max_attempts']) {
            throw new BusinessException(AssignmentMessage::ALREADY_SUBMITTED, 400);
        }

        $isLate = $effectiveDeadlines['due_date'] !== null && now()->gt($effectiveDeadlines['due_date']);

        try {
            Submission::query()
                ->where('assignment_id', $assignmentId)
                ->where('user_id', $userId)
                ->update(['is_latest' => false]);

            $submission = $this->submissionRepository->create([
                'assignment_id' => $assignmentId,
                'user_id' => $userId,
                'file_path' => $data,
                'status' => 'submitted',
                'attempt_number' => $attemptCount + 1,
                'is_latest' => true,
                'submitted_at' => now(),
                'late' => $isLate,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new BusinessException(AssignmentMessage::ALREADY_SUBMITTED, 400);
        }

        // Create file record for the submission
        FileRecord::create([
            'owner_type' => 'submission',
            'owner_id' => $submission->id,
            'uploader_id' => $actor->id,
            'component' => 'assignment_submission',
            'file_path' => $data,
            'mime_type' => 'application/pdf',
            'file_size' => 0,
            'revision' => 1,
            'visible' => true,
        ]);

        // Mark completion if module is configured for submit-based completion
        $this->moduleCompletionService->completeForAssignmentSubmission($assignment, $submission, $actor);

        // Warm cache untuk entity baru, lalu flush list caches
        $this->cacheStrategy->put(
            "submission:{$submission->id}",
            $submission->load(['user', 'assignment'])
        );

        $this->cacheStrategy->flushTags([
            "assignment:{$assignmentId}:submissions",
            "user:{$userId}:submissions",
        ]);

        return $submission;
    }

    /**
     * Get submissions for an assignment (cached)
     */
    public function getAssignmentSubmissions(int $assignmentId)
    {
        return $this->cacheStrategy
            ->tags(["assignment:{$assignmentId}:submissions"])
            ->get(
                "assignment:{$assignmentId}:submissions:all",
                fn () => $this->submissionRepository->getByAssignment($assignmentId)
            );
    }

    /**
     * Allocate a marker to a submission for marker workflow.
     */
    public function allocateMarker(int $submissionId, int $markerId, User $actor): AssignmentAllocatedMarker
    {
        $submission = $this->submissionRepository->findWithAssignment($submissionId);
        $assignment = $submission->assignment;

        if (! $assignment || ! $assignment->marking_allocation_enabled) {
            throw new BusinessException('Marker allocation is not enabled for this assignment', 400);
        }

        return AssignmentAllocatedMarker::query()->firstOrCreate([
            'assignment_id' => $assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $submission->user_id,
            'marker_id' => $markerId,
        ]);
    }

    /**
     * Record a marker's mark for a submission.
     */
    public function recordMarkerMark(int $submissionId, int $markerId, float $score, ?string $feedback = null, ?User $actor = null): AssignmentMark
    {
        $submission = $this->submissionRepository->findWithAssignment($submissionId);
        $assignment = $submission->assignment;

        if (! $assignment) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }

        if ($assignment->marking_allocation_enabled) {
            $isAllocated = AssignmentAllocatedMarker::query()
                ->where('submission_id', $submission->id)
                ->where('marker_id', $markerId)
                ->exists();

            if (! $isAllocated) {
                throw new BusinessException('Marker is not allocated to this submission', 403);
            }
        }

        if ($score > $assignment->max_score) {
            throw new BusinessException(GradeMessage::EXCEEDS_MAX, 400);
        }

        return AssignmentMark::query()->updateOrCreate(
            [
                'submission_id' => $submission->id,
                'marker_id' => $markerId,
            ],
            [
                'assignment_id' => $assignment->id,
                'score' => $score,
                'feedback' => $feedback,
                'workflow_state' => 'completed',
            ]
        );
    }

    /**
     * Record a marker mark and optionally finalize the submission.
     *
     * After recording the marker mark, this method checks whether the
     * submission should be finalized (grade, submission status, completion)
     * using the assignment's multi_mark_method. For benchmark validity,
     * finalization happens after each completed marker mark.
     *
     * @return array{mark: AssignmentMark, submission?: Submission, grade?: Grade}
     */
    public function markerGrade(int $submissionId, int $markerId, float $score, ?string $feedback = null, ?User $actor = null): array
    {
        $mark = $this->recordMarkerMark($submissionId, $markerId, $score, $feedback, $actor);

        $submission = $this->submissionRepository->findWithAssignment($submissionId);
        $assignment = $submission->assignment;

        $result = ['mark' => $mark];

        // Finalize: calculate final score from marker marks and update submission + grade
        $finalScore = $this->calculateFinalMarkerScore($assignment, $submission);

        if ($finalScore !== null) {
            $updatedSubmission = $this->submissionRepository->update($submissionId, [
                'score' => $finalScore,
                'status' => 'graded',
                'grader_id' => $markerId,
                'graded_at' => now(),
            ]);

            // Update or create grade
            $gradeItem = GradeItem::firstOrCreate([
                'course_id' => $assignment->course_id,
                'item_type' => 'assignment',
                'item_id' => $submission->assignment_id,
            ], [
                'name' => $assignment->title ?? "Assignment {$submission->assignment_id}",
                'max_score' => $assignment->max_score ?? 100,
                'source' => 'assignment',
            ]);

            $gradePercentage = $assignment->max_score > 0
                ? ($finalScore / $assignment->max_score) * 100
                : 0;

            $grade = Grade::query()->updateOrCreate([
                'user_id' => $submission->user_id,
                'course_id' => $assignment->course_id,
                'gradeable_type' => 'submission',
                'gradeable_id' => $submission->id,
            ], [
                'grade_item_id' => $gradeItem->id,
                'score' => $finalScore,
                'max_score' => $assignment->max_score,
                'percentage' => $gradePercentage,
                'grader_id' => $markerId,
                'feedback' => $feedback,
                'status' => 'final',
                'source' => 'assignment',
            ]);

            $result['submission'] = $updatedSubmission;
            $result['grade'] = $grade;

            // Mark completion
            $updatedSubmission->loadMissing(['assignment.course', 'assignment.learningModule']);
            $this->moduleCompletionService->completeForAssignmentSubmission(
                $assignment,
                $updatedSubmission,
                User::query()->findOrFail($submission->user_id)
            );

            $this->cacheStrategy->flushTags([
                "assignment:{$submission->assignment_id}:submissions",
                "user:{$submission->user_id}:submissions",
                'gradebook',
                "course:{$assignment->course_id}",
                "user:{$submission->user_id}:grades",
                "grade_item:{$gradeItem->id}",
                "submission:{$submissionId}:marks",
            ]);
        }

        return $result;
    }

    /**
     * Calculate final score from marker marks based on the assignment's multi_mark_method.
     */
    private function calculateFinalMarkerScore(Assignment $assignment, Submission $submission): ?float
    {
        $marks = AssignmentMark::query()
            ->where('submission_id', $submission->id)
            ->where('workflow_state', 'completed')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->pluck('score');

        if ($marks->isEmpty()) {
            return null;
        }

        return match ($assignment->multi_mark_method) {
            'average' => $marks->avg(),
            'highest' => $marks->max(),
            'latest' => $marks->first(),
            default => $marks->first(),
        };
    }

    /**
     * Grade a submission
     */
    public function gradeSubmission(int $submissionId, float $score, ?string $feedback = null, ?User $grader = null): Submission
    {
        $submission = $this->submissionRepository->findWithAssignment($submissionId);

        if (! $submission->assignment) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }

        $assignment = $submission->assignment;

        if ($score > $assignment->max_score) {
            throw new BusinessException(GradeMessage::EXCEEDS_MAX, 400);
        }

        // If marker allocation is enabled, require the grader to be an allocated marker
        // or a course instructor/admin
        if ($assignment->marking_allocation_enabled && $grader !== null) {
            $isAllocated = AssignmentAllocatedMarker::query()
                ->where('submission_id', $submission->id)
                ->where('marker_id', $grader->id)
                ->exists();

            $isInstructor = $this->courseAccessService->isInstructorForCourse(
                $grader,
                Course::query()->findOrFail($assignment->course_id)
            );

            if (! $isAllocated && ! $isInstructor) {
                throw new BusinessException('You are not allocated as a marker for this submission', 403);
            }
        }

        if ($grader !== null && ! $this->courseAccessService->canGradeSubmission($grader, $submission)) {
            throw new BusinessException('You do not have permission to grade this submission', 403);
        }

        $updatedSubmission = $this->submissionRepository->update($submissionId, [
            'score' => $score,
            'feedback' => $feedback,
            'status' => 'graded',
            'grader_id' => $grader?->id,
            'graded_at' => now(),
        ]);

        // Resolve or create a grade item for this assignment
        $gradeItem = GradeItem::firstOrCreate([
            'course_id' => $assignment->course_id,
            'item_type' => 'assignment',
            'item_id' => $submission->assignment_id,
        ], [
            'name' => $assignment->title ?? "Assignment {$submission->assignment_id}",
            'max_score' => $assignment->max_score ?? 100,
            'source' => 'assignment',
        ]);

        $gradePercentage = $assignment->max_score > 0 ? ($score / $assignment->max_score) * 100 : 0;

        Grade::query()->updateOrCreate([
            'user_id' => $submission->user_id,
            'course_id' => $assignment->course_id,
            'gradeable_type' => 'submission',
            'gradeable_id' => $submission->id,
        ], [
            'grade_item_id' => $gradeItem->id,
            'score' => $score,
            'max_score' => $assignment->max_score,
            'percentage' => $gradePercentage,
            'grader_id' => $grader?->id,
            'feedback' => $feedback,
            'status' => 'final',
            'source' => 'assignment',
        ]);

        // Mark completion for pass-grade after grading
        $assignment = $submission->assignment;
        $updatedSubmission->loadMissing(['assignment.course', 'assignment.learningModule']);
        $this->moduleCompletionService->completeForAssignmentSubmission(
            $assignment,
            $updatedSubmission,
            User::query()->findOrFail($submission->user_id)
        );

        // Warm cache dengan entity terbaru (Write-Through: cache + DB sync)
        $this->cacheStrategy->put(
            "submission:{$submissionId}",
            $updatedSubmission->load(['user', 'assignment'])
        );

        $this->cacheStrategy->flushTags([
            "assignment:{$submission->assignment_id}:submissions",
            "user:{$submission->user_id}:submissions",
            'gradebook',
            "course:{$assignment->course_id}",
            "user:{$submission->user_id}:grades",
            "grade_item:{$gradeItem->id}",
            "submission:{$submissionId}:marks",
        ]);

        return $updatedSubmission;
    }

    /**
     * Get pending submissions (ungraded) for an assignment (cached)
     */
    public function getPendingSubmissions(int $assignmentId)
    {
        return $this->cacheStrategy
            ->tags(["assignment:{$assignmentId}:submissions"])
            ->get(
                "assignment:{$assignmentId}:submissions:pending",
                fn () => $this->submissionRepository->getPendingByAssignment($assignmentId)
            );
    }

    /**
     * Get assignment statistics (cached)
     */
    public function getAssignmentStatistics(int $assignmentId)
    {
        return $this->cacheStrategy
            ->tags(["assignment:{$assignmentId}:submissions"])
            ->get(
                "assignment:{$assignmentId}:statistics",
                fn () => $this->submissionRepository->getStatistics($assignmentId)
            );
    }

    /**
     * Load the LearningModule for an assignment and set the relation.
     * Does NOT create — for read paths, modules must already exist (factories/seeders).
     * Does NOT throw — visibility/availability are enforced by the access layer.
     */
    private function loadAssignmentLearningModule($assignment): void
    {
        $assignment->loadMissing(['course']);

        $learningModule = LearningModule::query()
            ->where('module_type', LearningModule::TYPE_ASSIGNMENT)
            ->where('module_id', $assignment->id)
            ->first();

        $assignment->setRelation('learningModule', $learningModule);
    }

    /**
     * Resolve or create the LearningModule for an assignment and set the relation.
     * Used in write paths (submission) where missing modules may need
     * repair for legacy data. Read paths must use loadAssignmentLearningModule().
     */
    private function resolveAssignmentLearningModule($assignment): void
    {
        $assignment->loadMissing(['course']);

        $learningModule = LearningModule::query()
            ->where('module_type', LearningModule::TYPE_ASSIGNMENT)
            ->where('module_id', $assignment->id)
            ->first();

        if ($learningModule === null) {
            $learningModule = $assignment->learningModule()->create([
                'course_id' => $assignment->course_id,
                'module_type' => LearningModule::TYPE_ASSIGNMENT,
                'visible' => true,
                'sort_order' => $assignment->id,
            ]);
        }

        $assignment->setRelation('learningModule', $learningModule);
    }

    /**
     * Ensure an assignment is visible and available (throws if not).
     * Used for write paths (submissions) where availability must be enforced
     * for all actors before the action proceeds.
     */
    private function ensureAssignmentVisible($assignment): void
    {
        $this->resolveAssignmentLearningModule($assignment);

        if (! $assignment->is_active || ! $assignment->course?->is_active || ! $assignment->learningModule->isAvailable()) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }

        if ($assignment->available_from !== null && $assignment->available_from->gt(now())) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }
    }
}
