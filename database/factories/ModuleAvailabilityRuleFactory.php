<?php

namespace Database\Factories;

use App\Models\LearningModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ModuleAvailabilityRule>
 */
class ModuleAvailabilityRuleFactory extends Factory
{
    public function definition(): array
    {
        $ruleTypes = ['date', 'completion', 'min_grade', 'group'];

        return [
            'learning_module_id' => LearningModule::factory(),
            'rule_type' => fake()->randomElement($ruleTypes),
            'required_module_id' => null,
            'grade_item_id' => null,
            'course_group_id' => null,
            'operator' => null,
            'value' => null,
        ];
    }

    public function dateRule(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rule_type' => 'date',
            'operator' => fake()->randomElement(['before', 'after']),
            'value' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d H:i:s'),
        ]);
    }

    public function completionRule(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rule_type' => 'completion',
            'required_module_id' => LearningModule::factory(),
            'operator' => '==',
            'value' => 'complete',
        ]);
    }

    public function groupRule(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rule_type' => 'group',
            'course_group_id' => \App\Models\CourseGroup::factory(),
        ]);
    }
}
