<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\QuizQuestionSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizAttemptQuestion>
 */
class QuizAttemptQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_attempt_id' => QuizAttempt::factory(),
            'quiz_question_slot_id' => QuizQuestionSlot::factory(),
            'question_id' => Question::factory(),
            'slot' => $this->faker->numberBetween(1, 10),
            'max_points' => $this->faker->randomFloat(2, 1, 10),
            'score' => null,
            'state' => 'not_answered',
        ];
    }
}
