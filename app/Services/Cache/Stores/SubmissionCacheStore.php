<?php

namespace App\Services\Cache\Stores;

use App\Models\Submission;
use App\Repositories\SubmissionRepository;

/**
 * Cache Store untuk Submission entity
 *
 * Handles keys:
 *   - submission:{id} → findWithAssignment()
 */
class SubmissionCacheStore extends BaseCacheStore
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

    public function store(string $key, mixed $value): void
    {
        if ($value instanceof Submission) {
            if ($value->isDirty()) {
                $value->save();
            }

            return;
        }

        $id = $this->extractId($key);
        if ($id > 0 && is_array($value)) {
            Submission::updateOrCreate(['id' => $id], $value);
        }
    }

    public function erase(string $key): void
    {
        $id = $this->extractId($key);
        if ($id > 0) {
            Submission::destroy($id);
        }
    }
}
