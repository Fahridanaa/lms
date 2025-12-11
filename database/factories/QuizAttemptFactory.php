<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-3 months', 'now');
        $completedAt = fake()->optional(0.9)->dateTimeBetween($startedAt, 'now');

        return [
            'quiz_id' => \App\Models\Quiz::factory(),
            'user_id' => \App\Models\User::factory(),
            'answers' => [],
            'score' => $completedAt ? fake()->randomFloat(2, 0, 100) : null,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ];
    }
}
