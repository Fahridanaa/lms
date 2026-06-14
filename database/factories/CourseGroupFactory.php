<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseGroup>
 */
class CourseGroupFactory extends Factory
{
    public function definition(): array
    {
        $groupNames = ['Group A', 'Group B', 'Team Alpha', 'Team Beta', 'Workshop 1', 'Workshop 2', 'Lab Section 1', 'Lab Section 2', 'Study Group', 'Project Team'];

        return [
            'course_id' => Course::factory(),
            'name' => fake()->randomElement($groupNames),
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
