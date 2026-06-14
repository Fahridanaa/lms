<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseGrouping>
 */
class CourseGroupingFactory extends Factory
{
    public function definition(): array
    {
        $groupingNames = ['Lab Sections', 'Project Teams', 'Workshop Groups', 'Study Groups', 'Cohorts'];

        return [
            'course_id' => Course::factory(),
            'name' => fake()->randomElement($groupingNames),
            'sort_order' => fake()->numberBetween(1, 100),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
