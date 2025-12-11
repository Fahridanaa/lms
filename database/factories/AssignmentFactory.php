<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Assignment>
 */
class AssignmentFactory extends Factory
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
            'title' => 'Assignment: ' . fake()->words(3, true),
            'description' => fake()->paragraphs(2, true),
            'due_date' => fake()->dateTimeBetween('now', '+3 months'),
            'max_score' => fake()->randomElement([50, 100]),
        ];
    }
}
