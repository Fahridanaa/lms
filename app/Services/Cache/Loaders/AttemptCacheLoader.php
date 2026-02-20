<?php

namespace App\Services\Cache\Loaders;

use App\Repositories\QuizAttemptRepository;

/**
 * Cache Loader untuk QuizAttempt entity
 *
 * Handles keys:
 *   - attempt:{id}:result → findWithFullDetails()
 */
class AttemptCacheLoader extends BaseCacheLoader
{
    protected string $prefix = 'attempt';

    public function __construct(
        protected QuizAttemptRepository $quizAttemptRepository
    ) {}

    public function load(string $key): mixed
    {
        $id = $this->extractId($key);
        $subkey = $this->extractSubkey($key);

        return match ($subkey) {
            'result' => $this->quizAttemptRepository->findWithFullDetails($id),
            default => $this->quizAttemptRepository->find($id),
        };
    }
}
