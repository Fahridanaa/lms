<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizQuestionSlot>
 */
class QuizQuestionSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_id' => \App\Models\Quiz::factory(),
            'question_id' => \App\Models\Question::factory(),
            'slot' => fake()->unique()->numberBetween(1, 100),
            'page' => 1,
            'max_points' => fake()->numberBetween(1, 5),
            'require_previous' => false,
        ];
    }
}
