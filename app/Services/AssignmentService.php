<?php

namespace App\Services;

use App\Constants\Messages\AssignmentMessage;
use App\Constants\Messages\GradeMessage;
use App\Contracts\CacheStrategyInterface;
use App\Exceptions\BusinessException;
use App\Models\Submission;
use App\Repositories\AssignmentRepository;
use App\Repositories\SubmissionRepository;

class AssignmentService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy,
        protected AssignmentRepository $assignmentRepository,
        protected SubmissionRepository $submissionRepository
    ) {
    }

    /**
     * Get all assignments for a course (cached)
     */
    public function getCourseAssignments(int $courseId)
    {
        return $this->cacheStrategy
            ->tags(['assignments', "course:{$courseId}"])
            ->get(
                "course:{$courseId}:assignments",
                fn() => $this->assignmentRepository->getByCourse($courseId)
            );
    }

    /**
     * Get assignment by ID (cached)
     */
    public function getAssignmentById(int $assignmentId): array
    {
        return $this->cacheStrategy
            ->tags(['assignments', "assignment:{$assignmentId}"])
            ->get(
                "assignment:{$assignmentId}",
                fn() => $this->assignmentRepository->findWithCourse($assignmentId)
            );
    }

    /**
     * Submit assignment
     */
    public function submitAssignment(int $assignmentId, int $userId, string $data): Submission
    {
        $assignment = $this->assignmentRepository->findOrFail($assignmentId);

        if ($assignment->due_date < now()) {
            throw new BusinessException(AssignmentMessage::DEADLINE_PASSED, 400);
        }

        $existing = $this->submissionRepository->getUserSubmission($assignmentId, $userId);
        if ($existing) {
            throw new BusinessException(AssignmentMessage::ALREADY_SUBMITTED, 400);
        }

        $submission = $this->submissionRepository->create([
            'assignment_id' => $assignmentId,
            'user_id' => $userId,
            'file_path' => $data,
            'submitted_at' => now(),
        ]);

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
                fn() => $this->submissionRepository->getByAssignment($assignmentId)
            );
    }

    /**
     * Grade a submission
     */
    public function gradeSubmission(int $submissionId, float $score, ?string $feedback = null): Submission
    {
        $submission = $this->submissionRepository->findWithAssignment($submissionId);

        if (!$submission->assignment) {
            throw new BusinessException(AssignmentMessage::NOT_FOUND, 404);
        }

        if ($score > $submission->assignment->max_score) {
            throw new BusinessException(GradeMessage::EXCEEDS_MAX, 400);
        }

        $updatedSubmission = $this->submissionRepository->update($submissionId, [
            'score' => $score,
            'feedback' => $feedback,
            'graded_at' => now(),
        ]);

        $this->cacheStrategy->flushTags([
            "assignment:{$submission->assignment_id}:submissions",
            "user:{$submission->user_id}:submissions",
            "submission:{$submissionId}",
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
                fn() => $this->submissionRepository->getPendingByAssignment($assignmentId)
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
                fn() => $this->submissionRepository->getStatistics($assignmentId)
            );
    }
}
