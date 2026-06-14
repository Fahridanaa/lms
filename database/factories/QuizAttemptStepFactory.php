<?php

namespace Database\Factories;

use App\Models\QuizAttemptQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizAttemptStep>
 */
class QuizAttemptStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_attempt_question_id' => QuizAttemptQuestion::factory(),
            'sequence_number' => 0,
            'state' => 'not_answered',
            'score' => null,
            'user_id' => User::factory(),
        ];
    }
}
