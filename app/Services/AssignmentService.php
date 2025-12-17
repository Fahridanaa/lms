<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
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
    public function getAssignmentById(int $assignmentId)
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
    public function submitAssignment(int $assignmentId, int $userId, array $data): Submission
    {
        $submission = $this->submissionRepository->create([
            'assignment_id' => $assignmentId,
            'user_id' => $userId,
            'file_path' => $data,
            'submitted_at' => now(),
        ]);

        // Invalidate related caches
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
            ->get("assignment:{$assignmentId}:submissions:all",
                fn() => $this->submissionRepository->getByAssignment($assignmentId)
            );
    }

    /**
     * Get user's submission for an assignment (cached)
     */
    public function getUserSubmission(int $assignmentId, int $userId)
    {
        return $this->cacheStrategy
            ->tags(["user:{$userId}:submissions", "assignment:{$assignmentId}:submissions"])
            ->get("assignment:{$assignmentId}:user:{$userId}:submission",
                fn() => $this->submissionRepository->getUserSubmission($assignmentId, $userId)
            );
    }

    /**
     * Grade a submission
     */
    public function gradeSubmission(int $submissionId, float $score, ?string $feedback = null): Submission
    {
        $submission = $this->submissionRepository->findWithAssignment($submissionId);

        $updatedSubmission = $this->submissionRepository->update($submissionId, [
            'score' => $score,
            'feedback' => $feedback,
            'graded_at' => now(),
        ]);

        // Invalidate related caches
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
     * Get user's all submissions (cached)
     */
    public function getUserSubmissions(int $userId)
    {
        return $this->cacheStrategy
            ->tags(["user:{$userId}:submissions"])
            ->get(
                "user:{$userId}:submissions:all",
                fn() => $this->submissionRepository->getUserSubmissions($userId)
            );
    }

    /**
     * Get assignment statistics (cached)
     */
    public function getAssignmentStatistics(int $assignmentId)
    {
        return $this->cacheStrategy
            ->tags(["assignment:{$assignmentId}:submissions"])
            ->get("assignment:{$assignmentId}:statistics",
                fn() => $this->submissionRepository->getStatistics($assignmentId)
            );
    }
}
