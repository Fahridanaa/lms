<?php

namespace App\Repositories;

use App\Models\Quiz;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class QuizRepository extends BaseRepository
{
    public function __construct(Quiz $model)
    {
        $this->model = $model;
    }

    /**
     * Get all quizzes with course relationship
     */
    public function getAllWithCourse(): Collection
    {
        return $this->all(['course']);
    }

    /**
     * Get quiz with questions and course
     */
    public function findWithQuestionsAndCourse(int $id): Model
    {
        return $this->findOrFail($id, ['questions', 'course']);
    }

    /**
     * Get quiz questions
     */
    public function getQuestions(int $quizId): Collection
    {
        $quiz = $this->findOrFail($quizId);
        return $quiz->questions;
    }

    /**
     * Get quizzes by course ID
     */
    public function getByCourse(int $courseId): Collection
    {
        return $this->where('course_id', $courseId, ['questions']);
    }

    /**
     * Get quizzes with minimum passing score
     */
    public function getByMinimumPassingScore(float $minScore): Collection
    {
        return $this->model->newQuery()
            ->where('passing_score', '>=', $minScore)
            ->with(['course'])
            ->get();
    }

    /**
     * Count quizzes by course
     */
    public function countByCourse(int $courseId): int
    {
        return $this->count(['course_id' => $courseId]);
    }
}
