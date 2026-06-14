<?php

namespace Database\Factories;

use App\Models\QuizAttemptStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizAttemptStepData>
 */
class QuizAttemptStepDataFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_attempt_step_id' => QuizAttemptStep::factory(),
            'name' => $this->faker->word(),
            'value' => $this->faker->sentence(),
        ];
    }
}
