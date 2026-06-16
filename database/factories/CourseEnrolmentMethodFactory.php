<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseEnrolmentMethod>
 */
class CourseEnrolmentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'method' => 'manual',
            'status' => 'active',
            'default_role' => 'student',
            'starts_at' => now()->subYear(),
            'ends_at' => null,
        ];
    }

    /**
     * Set the method to inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Set the method to self-enrolment.
     */
    public function selfEnrolment(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'self',
        ]);
    }

    /**
     * Set the method to cohort-based.
     */
    public function cohort(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'cohort',
        ]);
    }

    /**
     * Set an expiry date in the past.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'ends_at' => now()->subDay(),
        ]);
    }
}
