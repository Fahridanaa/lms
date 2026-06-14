<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\Question;

class QuizScoringService
{
    /**
     * Calculate the overall score for a quiz submission.
     *
     * @return array{earned_points: float, max_points: float, percentage: float}
     */
    public function calculate(Quiz $quiz, array $answers): array
    {
        $totalPoints = 0;
        $earnedPoints = 0;

        foreach ($quiz->questions as $question) {
            $totalPoints += $question->points;

            $userAnswer = $answers[$question->id] ?? null;
            if ($this->isCorrect($question, $userAnswer)) {
                $earnedPoints += $question->points;
            }
        }

        return [
            'earned_points' => (float) $earnedPoints,
            'max_points' => (float) $totalPoints,
            'percentage' => $totalPoints > 0
                ? ($earnedPoints / $totalPoints) * 100
                : 0,
        ];
    }

    /**
     * Score a single question against a user answer.
     *
     * @return array{score: float, max: float, is_correct: bool}
     */
    public function scoreQuestion(Question $question, mixed $userAnswer): array
    {
        $isCorrect = $this->isCorrect($question, $userAnswer);

        return [
            'score' => $isCorrect ? (float) $question->points : 0.0,
            'max' => (float) $question->points,
            'is_correct' => $isCorrect,
        ];
    }

    private function isCorrect(Question $question, $userAnswer): bool
    {
        return trim((string) $userAnswer) === trim((string) $question->correct_answer);
    }
}
