<?php

namespace App\Services\Cache\Stores;

use App\Repositories\AssignmentRepository;
use App\Repositories\SubmissionRepository;

/**
 * Cache Store untuk Assignment entity
 *
 * Handles keys:
 *   - assignment:{id} → findWithCourse()
 *   - assignment:{id}:submissions:all → getByAssignment()
 *   - assignment:{id}:submissions:pending → getPendingByAssignment()
 *   - assignment:{id}:statistics → getStatistics()
 */
class AssignmentCacheStore extends BaseCacheStore
{
    protected string $prefix = 'assignment';

    public function __construct(
        protected AssignmentRepository $assignmentRepository,
        protected SubmissionRepository $submissionRepository
    ) {}

    public function load(string $key): mixed
    {
        $id = $this->extractId($key);
        $subkey = $this->extractSubkey($key);

        return match ($subkey) {
            'submissions:all' => $this->submissionRepository->getByAssignment($id),
            'submissions:pending' => $this->submissionRepository->getPendingByAssignment($id),
            'statistics' => $this->submissionRepository->getStatistics($id),
            default => $this->assignmentRepository->findWithCourse($id),
        };
    }

    public function store(string $key, mixed $value): void
    {
        $subkey = $this->extractSubkey($key);

        // Handle submission store
        if ($subkey !== null && str_starts_with($subkey, 'submissions')) {
            if ($value instanceof \App\Models\Submission) {
                $value->save();
            }
            return;
        }

        // Handle assignment store
        if ($value instanceof \App\Models\Assignment) {
            $value->save();
            return;
        }

        $id = $this->extractId($key);
        if ($id > 0 && is_array($value)) {
            $this->assignmentRepository->update($id, $value);
        }
    }

    public function erase(string $key): void
    {
        $id = $this->extractId($key);
        if ($id > 0) {
            $this->assignmentRepository->delete($id);
        }
    }
}
