<?php

namespace App\Services\Cache\Loaders;

use App\Repositories\SubmissionRepository;

/**
 * Cache Loader untuk Submission entity
 *
 * Handles keys:
 *   - submission:{id} → findWithAssignment()
 */
class SubmissionCacheLoader extends BaseCacheLoader
{
    protected string $prefix = 'submission';

    public function __construct(
        protected SubmissionRepository $submissionRepository
    ) {}

    public function load(string $key): mixed
    {
        $id = $this->extractId($key);

        return $this->submissionRepository->findWithAssignment($id);
    }
}
