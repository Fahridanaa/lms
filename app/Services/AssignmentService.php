<?php

namespace App\Services;

use App\Contracts\CacheStrategyInterface;
use App\Models\Assignment;
use App\Models\Submission;

class AssignmentService
{
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy
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
                fn() => Assignment::where('course_id', $courseId)
                    ->orderBy('due_date', 'asc')
                    ->get()
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
                fn() =>
                Assignment::with('course')->findOrFail($assignmentId)
            );
    }

    /**
     * Submit assignment
     */
    public function submitAssignment(int $assignmentId, int $userId, array $data): Submission
    {
        $submission = Submission::create([
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
            ->get("assignment:{$assignmentId}:submissions:all", fn() => Submission::with(['user'])
                ->where('assignment_id', $assignmentId)
                ->orderBy('submitted_at', 'desc')
                ->get());
    }

    /**
     * Get user's submission for an assignment (cached)
     */
    public function getUserSubmission(int $assignmentId, int $userId)
    {
        return $this->cacheStrategy
            ->tags(["user:{$userId}:submissions", "assignment:{$assignmentId}:submissions"])
            ->get("assignment:{$assignmentId}:user:{$userId}:submission", fn() => Submission::where('assignment_id', $assignmentId)
                ->where('user_id', $userId)
                ->first());
    }

    /**
     * Grade a submission
     */
    public function gradeSubmission(int $submissionId, float $score, ?string $feedback = null): Submission
    {
        $submission = Submission::with('assignment')->findOrFail($submissionId);

        $submission->update([
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

        return $submission->fresh();
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
                fn() => Submission::with(['user'])
                    ->where('assignment_id', $assignmentId)
                    ->whereNull('graded_at')
                    ->orderBy('submitted_at', 'asc')
                    ->get()
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
                fn() => Submission::with(['assignment.course'])
                    ->where('user_id', $userId)
                    ->orderBy('submitted_at', 'desc')
                    ->get()
            );
    }

    /**
     * Get assignment statistics (cached)
     */
    public function getAssignmentStatistics(int $assignmentId)
    {
        return $this->cacheStrategy
            ->tags(["assignment:{$assignmentId}:submissions"])
            ->get("assignment:{$assignmentId}:statistics", function () use ($assignmentId) {
                $submissions = Submission::where('assignment_id', $assignmentId)->get();

                return [
                    'total_submissions' => $submissions->count(),
                    'graded_submissions' => $submissions->whereNotNull('graded_at')->count(),
                    'pending_submissions' => $submissions->whereNull('graded_at')->count(),
                    'average_score' => $submissions->whereNotNull('score')->avg('score'),
                ];
            });
    }
}
