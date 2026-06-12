<?php

namespace Database\Factories;

use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (QuizAttempt $attempt): void {
            if ($attempt->quiz_id === null || $attempt->user_id === null) {
                return;
            }

            if ($attempt->completed_at === null) {
                $attempt->status = 'in_progress';
                $attempt->submitted_at = null;
                $attempt->score = null;
            }

            $attemptNumberExists = QuizAttempt::query()
                ->where('quiz_id', $attempt->quiz_id)
                ->where('user_id', $attempt->user_id)
                ->where('attempt_number', $attempt->attempt_number)
                ->exists();

            if ($attemptNumberExists) {
                $attempt->attempt_number = QuizAttempt::query()
                    ->where('quiz_id', $attempt->quiz_id)
                    ->where('user_id', $attempt->user_id)
                    ->max('attempt_number') + 1;
            }
        });
    }

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
            'status' => $completedAt ? 'finished' : 'in_progress',
            'attempt_number' => 1,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'submitted_at' => $completedAt,
            'expires_at' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => null,
            'status' => 'in_progress',
            'completed_at' => null,
            'submitted_at' => null,
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'finished',
            'completed_at' => now(),
            'submitted_at' => now(),
        ]);
    }
}
