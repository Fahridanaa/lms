<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\Question;

class QuizScoringService
{
    public function calculate(Quiz $quiz, array $answers): float
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

        return $totalPoints > 0
            ? ($earnedPoints / $totalPoints) * 100
            : 0;
    }

    private function isCorrect(Question $question, $userAnswer): bool
    {
        return trim($userAnswer) === trim($question->correct_answer);
    }
}
