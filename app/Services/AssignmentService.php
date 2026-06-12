<?php

namespace App\Services;

use App\Constants\Messages\AssignmentMessage;
use App\Constants\Messages\GradeMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\CourseEnrollment;
use App\Models\Grade;
use App\Models\LearningModule;
use App\Models\Submission;
use App\Repositories\AssignmentRepository;
use App\Repositories\SubmissionRepository;

class AssignmentService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected AssignmentRepository $assignmentRepository,
        protected SubmissionRepository $submissionRepository
    ) {}

    /**
     * Get all assignments for a course (cached)
     */
    public function getCourseAssignments(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['assignments', "course:{$courseId}"])
            ->get(
                "course:{$courseId}:assignments",
                fn () => $this->assignmentRepository->getByCourse($courseId)
            );
    }

    /**
     * Get assignment by ID (cached)
     */
    public function getAssignmentById(int $assignmentId): mixed
    {
        return $this->cacheStrategy
            ->tags(['assignments', "assignment:{$assignmentId}"])
            ->get(
                "assignment:{$assignmentId}",
                fn () => tap($this->assignmentRepository->findWithCourse($assignmentId), fn ($assignment) => $this->ensureAssignmentVisible($assignment))
            );
    }

    /**
     * Submit assignment
     */
    public function submitAssignment(int $assignmentId, int $userId, string $data): Submission
    {
        $assignment = $this->assignmentRepository->findOrFail($assignmentId);
        $assignment->loadMissing(['course', 'learningModule']);
        $this->ensureAssignmentVisible($assignment);
        $this->ensureActiveEnrollment($assignment->course_id, $userId);

        if (! $assignment->allow_late_submission && $assignment->due_date < now()) {
            throw new BusinessException(AssignmentMessage::DEADLINE_PASSED, 400);
        }

        if ($assignment->cutoff_date !== null && $assignment->cutoff_date < now()) {
            throw new BusinessException(AssignmentMessage::DEADLINE_PASSED, 400);
        }

        $attemptCount = Submission::query()
            ->where('assignment_id', $assignmentId)
            ->where('user_id', $userId)
            ->count();

        if ($attemptCount >= $assignment->max_attempts) {
            throw new BusinessException(AssignmentMessage::ALREADY_SUBMITTED, 400);
        }

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
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new BusinessException(AssignmentMessage::ALREADY_SUBMITTED, 400);
        }

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
     * Grade a submission
     */
    public function gradeSubmission(int $submissionId, float $score, ?string $feedback = null, ?int $graderId = null): Submission
    {
        $submission = $this->submissionRepository->findWithAssignment($submissionId);

        if (! $submission->assignment) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }

        if ($score > $submission->assignment->max_score) {
            throw new BusinessException(GradeMessage::EXCEEDS_MAX, 400);
        }

        if ($graderId !== null && ! $this->canGradeAssignment($submission->assignment->course_id, $graderId)) {
            throw new BusinessException('Pengguna tidak memiliki akses untuk memberi nilai pada kursus ini', 403);
        }

        $updatedSubmission = $this->submissionRepository->update($submissionId, [
            'score' => $score,
            'feedback' => $feedback,
            'status' => 'graded',
            'grader_id' => $graderId,
            'graded_at' => now(),
        ]);

        Grade::query()->updateOrCreate([
            'user_id' => $submission->user_id,
            'course_id' => $submission->assignment->course_id,
            'gradeable_type' => 'submission',
            'gradeable_id' => $submission->id,
        ], [
            'score' => $score,
            'max_score' => $submission->assignment->max_score,
            'percentage' => ($score / $submission->assignment->max_score) * 100,
            'grader_id' => $graderId,
            'feedback' => $feedback,
            'status' => 'final',
            'source' => 'assignment',
        ]);

        // Warm cache dengan entity terbaru (Write-Through: cache + DB sync)
        $this->cacheStrategy->put(
            "submission:{$submissionId}",
            $updatedSubmission->load(['user', 'assignment'])
        );

        $this->cacheStrategy->flushTags([
            "assignment:{$submission->assignment_id}:submissions",
            "user:{$submission->user_id}:submissions",
            'gradebook',
            "course:{$submission->assignment->course_id}",
            "user:{$submission->user_id}:grades",
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

    private function ensureAssignmentVisible($assignment): void
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

        if (! $assignment->is_active || ! $assignment->course?->is_active || ! $learningModule->isAvailable()) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }

        if ($assignment->available_from !== null && $assignment->available_from->gt(now())) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }
    }

    private function ensureActiveEnrollment(int $courseId, int $userId): void
    {
        $enrollment = CourseEnrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->first();

        if (! $enrollment?->isActive()) {
            throw new BusinessException('Pengguna tidak memiliki enrolment aktif pada kursus ini', 403);
        }
    }

    private function canGradeAssignment(int $courseId, int $graderId): bool
    {
        return \App\Models\Course::query()
            ->where('id', $courseId)
            ->where('instructor_id', $graderId)
            ->exists()
            || CourseEnrollment::query()
                ->where('course_id', $courseId)
                ->where('user_id', $graderId)
                ->where('role', 'instructor')
                ->where('status', 'active')
                ->exists();
    }
}
