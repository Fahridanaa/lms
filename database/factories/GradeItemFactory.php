<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GradeItem>
 */
class GradeItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'item_type' => 'quiz',
            'item_id' => null,
            'name' => fake()->words(3, true),
            'max_score' => 100,
            'pass_score' => 60,
            'weight' => 1.0,
            'hidden' => false,
            'locked' => false,
            'source' => 'quiz',
        ];
    }

    /**
     * Mark the grade item as hidden.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attrs): array => ['hidden' => true]);
    }

    /**
     * Mark the grade item as locked.
     */
    public function locked(): static
    {
        return $this->state(fn (array $attrs): array => ['locked' => true]);
    }
}
