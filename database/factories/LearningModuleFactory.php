<?php

namespace Database\Factories;

use App\Models\LearningModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LearningModule>
 */
class LearningModuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => \App\Models\Course::factory(),
            'module_type' => LearningModule::TYPE_MATERIAL,
            'module_id' => 1,
            'visible' => true,
            'available_from' => null,
            'available_until' => null,
            'sort_order' => fake()->numberBetween(1, 100),
            'completion_enabled' => false,
            'completion_rule' => null,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visible' => false,
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_from' => now()->addDay(),
            'available_until' => now()->addDays(7),
        ]);
    }
}
