<?php

namespace Database\Factories;

use App\Models\LearningModule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ModuleCompletion>
 */
class ModuleCompletionFactory extends Factory
{
    public function definition(): array
    {
        $states = ['incomplete', 'complete', 'complete_passed', 'complete_failed'];

        return [
            'learning_module_id' => LearningModule::factory(),
            'user_id' => User::factory(),
            'state' => fake()->randomElement($states),
            'completed_at' => null,
            'source' => null,
            'override_by' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'complete',
            'completed_at' => now(),
            'source' => fake()->randomElement(['view', 'assignment_submission', 'quiz_attempt', 'grade']),
        ]);
    }

    public function passed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'complete_passed',
            'completed_at' => now(),
            'source' => 'quiz_attempt',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'complete_failed',
            'completed_at' => now(),
            'source' => 'quiz_attempt',
        ]);
    }
}
