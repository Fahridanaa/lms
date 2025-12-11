<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $options = [
            'A' => fake()->sentence(4),
            'B' => fake()->sentence(4),
            'C' => fake()->sentence(4),
            'D' => fake()->sentence(4),
        ];

        $correctAnswer = fake()->randomElement(['A', 'B', 'C', 'D']);

        return [
            'quiz_id' => \App\Models\Quiz::factory(),
            'question_text' => fake()->sentence() . '?',
            'options' => $options,
            'correct_answer' => $correctAnswer,
            'points' => fake()->numberBetween(1, 5),
        ];
    }
}
