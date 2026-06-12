<?php

namespace Database\Factories;

use App\Models\LearningModule;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quiz>
 */
class QuizFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Quiz $quiz): void {
            $quiz->learningModule()->firstOrCreate([
                'module_type' => LearningModule::TYPE_QUIZ,
                'module_id' => $quiz->id,
            ], [
                'course_id' => $quiz->course_id,
                'visible' => true,
                'sort_order' => $quiz->id,
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => \App\Models\Course::factory(),
            'title' => 'Quiz: '.fake()->words(3, true),
            'description' => fake()->sentence(),
            'time_limit' => fake()->numberBetween(15, 120),
            'passing_score' => fake()->randomFloat(2, 60, 80),
            'is_active' => true,
            'available_from' => null,
            'available_until' => null,
            'max_attempts' => 0,
            'grading_method' => 'highest',
            'shuffle_questions' => false,
            'shuffle_answers' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_from' => now()->addDay(),
        ]);
    }

    public function limitedAttempts(int $maxAttempts = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_attempts' => $maxAttempts,
        ]);
    }
}
