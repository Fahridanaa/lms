<?php

namespace App\Repositories;

use App\Models\Submission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SubmissionRepository extends BaseRepository
{
    public function __construct(Submission $model)
    {
        $this->model = $model;
    }

    /**
     * Get submissions by assignment ID
     */
    public function getByAssignment(int $assignmentId): Collection
    {
        return $this->model->newQuery()
            ->with(['user'])
            ->where('assignment_id', $assignmentId)
            ->orderBy('submitted_at', 'desc')
            ->get();
    }

    /**
     * Get user's submission for an assignment
     */
    public function getUserSubmission(int $assignmentId, int $userId): ?Model
    {
        return $this->model->newQuery()
            ->where('assignment_id', $assignmentId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Get user's all submissions
     */
    public function getUserSubmissions(int $userId): Collection
    {
        return $this->model->newQuery()
            ->with(['assignment.course'])
            ->where('user_id', $userId)
            ->orderBy('submitted_at', 'desc')
            ->get();
    }

    /**
     * Get pending (ungraded) submissions for an assignment
     */
    public function getPendingByAssignment(int $assignmentId): Collection
    {
        return $this->model->newQuery()
            ->with(['user'])
            ->where('assignment_id', $assignmentId)
            ->whereNull('graded_at')
            ->orderBy('submitted_at', 'asc')
            ->get();
    }

    /**
     * Get graded submissions for an assignment
     */
    public function getGradedByAssignment(int $assignmentId): Collection
    {
        return $this->model->newQuery()
            ->with(['user'])
            ->where('assignment_id', $assignmentId)
            ->whereNotNull('graded_at')
            ->orderBy('graded_at', 'desc')
            ->get();
    }

    /**
     * Find submission with assignment relationship
     */
    public function findWithAssignment(int $id): Model
    {
        return $this->findOrFail($id, ['assignment']);
    }

    /**
     * Get submission statistics for an assignment
     */
    public function getStatistics(int $assignmentId): array
    {
        $stats = $this->model->newQuery()
            ->where('assignment_id', $assignmentId)
            ->selectRaw('
                COUNT(*) as total_submissions,
                SUM(CASE WHEN graded_at IS NOT NULL THEN 1 ELSE 0 END) as graded_submissions,
                SUM(CASE WHEN graded_at IS NULL THEN 1 ELSE 0 END) as pending_submissions,
                AVG(CASE WHEN score IS NOT NULL THEN score END) as average_score
            ')
            ->first();

        return [
            'total_submissions' => (int) ($stats->total_submissions ?? 0),
            'graded_submissions' => (int) ($stats->graded_submissions ?? 0),
            'pending_submissions' => (int) ($stats->pending_submissions ?? 0),
            'average_score' => $stats->average_score ?? 0,
        ];
    }

    /**
     * Count submissions by assignment
     */
    public function countByAssignment(int $assignmentId): int
    {
        return $this->count(['assignment_id' => $assignmentId]);
    }

    /**
     * Count user's submissions
     */
    public function countByUser(int $userId): int
    {
        return $this->count(['user_id' => $userId]);
    }
}
