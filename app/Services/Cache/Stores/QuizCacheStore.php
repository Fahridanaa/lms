<?php

namespace App\Services\Cache\Stores;

use App\Repositories\QuizRepository;

/**
 * Cache Store untuk Quiz entity
 *
 * Handles keys:
 *   - quizzes:all → getAllWithCourse()
 *   - quiz:{id}:with-questions → findWithQuestionsAndCourse()
 *   - quiz:{id}:questions → getQuestions()
 *
 * Note: Quiz biasanya read-heavy, store hanya untuk konsistensi.
 * Update quiz biasanya melalui admin panel, bukan frequent operation.
 */
class QuizCacheStore extends BaseCacheStore
{
    protected string $prefix = 'quiz';

    public function __construct(
        protected QuizRepository $quizRepository
    ) {}

    public function supports(string $key): bool
    {
        return str_starts_with($key, 'quiz:') || $key === 'quizzes:all';
    }

    public function load(string $key): mixed
    {
        if ($key === 'quizzes:all') {
            return $this->quizRepository->getAllWithCourse();
        }

        $id = $this->extractId($key);
        $subkey = $this->extractSubkey($key);

        return match ($subkey) {
            'with-questions' => $this->quizRepository->findWithQuestionsAndCourse($id),
            'questions' => $this->quizRepository->getQuestions($id),
            default => $this->quizRepository->find($id),
        };
    }

    public function store(string $key, mixed $value): void
    {
        // Quiz updates biasanya full model save
        if ($value instanceof \App\Models\Quiz) {
            $value->save();
            return;
        }

        // Jika array, update via repository
        $id = $this->extractId($key);
        if ($id > 0 && is_array($value)) {
            $this->quizRepository->update($id, $value);
        }
    }

    public function erase(string $key): void
    {
        $id = $this->extractId($key);
        if ($id > 0) {
            $this->quizRepository->delete($id);
        }
    }
}
