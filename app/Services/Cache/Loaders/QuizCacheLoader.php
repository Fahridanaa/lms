<?php

namespace App\Services\Cache\Loaders;

use App\Repositories\QuizRepository;

/**
 * Cache Loader untuk Quiz entity
 *
 * Handles keys:
 *   - quizzes:all → getAllWithCourse()
 *   - quiz:{id}:with-questions → findWithQuestionsAndCourse()
 *   - quiz:{id}:questions → getQuestions()
 */
class QuizCacheLoader extends BaseCacheLoader
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
        // Handle "quizzes:all"
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
}
