<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseEnrollment>
 */
class CourseEnrollmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'course_id' => \App\Models\Course::factory(),
            'role' => 'student',
            'status' => 'active',
            'enrolled_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'starts_at' => now()->subMonth(),
            'ends_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'suspended',
        ]);
    }

    public function instructor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => 'instructor',
        ]);
    }
}
