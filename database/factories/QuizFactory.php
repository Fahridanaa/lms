<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => \App\Models\Course::factory(),
            'title' => 'Quiz: ' . fake()->words(3, true),
            'description' => fake()->sentence(),
            'time_limit' => fake()->numberBetween(15, 120),
            'passing_score' => fake()->randomFloat(2, 60, 80),
        ];
    }
}
