<?php

namespace App\Services\Cache\Loaders;

use App\Repositories\AssignmentRepository;
use App\Repositories\SubmissionRepository;

/**
 * Cache Loader untuk Assignment entity
 *
 * Handles keys:
 *   - assignment:{id} → findWithCourse()
 *   - assignment:{id}:submissions:all → getByAssignment()
 *   - assignment:{id}:submissions:pending → getPendingByAssignment()
 *   - assignment:{id}:statistics → getStatistics()
 */
class AssignmentCacheLoader extends BaseCacheLoader
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
}
