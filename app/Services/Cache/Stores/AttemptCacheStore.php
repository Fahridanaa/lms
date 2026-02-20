<?php

namespace App\Services\Cache\Stores;

use App\Repositories\QuizAttemptRepository;

/**
 * Cache Store untuk QuizAttempt entity
 *
 * Handles keys:
 *   - attempt:{id}:result → findWithFullDetails()
 */
class AttemptCacheStore extends BaseCacheStore
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

    public function store(string $key, mixed $value): void
    {
        if ($value instanceof \App\Models\QuizAttempt) {
            $value->save();
            return;
        }

        $id = $this->extractId($key);
        if ($id > 0 && is_array($value)) {
            $this->quizAttemptRepository->update($id, $value);
        }
    }

    public function erase(string $key): void
    {
        $id = $this->extractId($key);
        if ($id > 0) {
            $this->quizAttemptRepository->delete($id);
        }
    }
}
