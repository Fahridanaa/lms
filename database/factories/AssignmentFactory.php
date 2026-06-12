<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\LearningModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Assignment>
 */
class AssignmentFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Assignment $assignment): void {
            $assignment->learningModule()->firstOrCreate([
                'module_type' => LearningModule::TYPE_ASSIGNMENT,
                'module_id' => $assignment->id,
            ], [
                'course_id' => $assignment->course_id,
                'visible' => true,
                'sort_order' => $assignment->id,
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
            'title' => 'Assignment: '.fake()->words(3, true),
            'description' => fake()->paragraphs(2, true),
            'due_date' => fake()->dateTimeBetween('now', '+3 months'),
            'max_score' => 100,
            'is_active' => true,
            'available_from' => null,
            'cutoff_date' => null,
            'max_attempts' => 1,
            'allow_late_submission' => false,
            'submission_type' => 'file',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cutoff_date' => now()->subDay(),
        ]);
    }

    public function multipleAttempts(int $maxAttempts = 3): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_attempts' => $maxAttempts,
        ]);
    }
}
