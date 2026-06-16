<?php

namespace Database\Factories;

use App\Models\CourseEnrolmentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subjects = ['Web Development', 'Data Science', 'Mobile Development', 'Database Design', 'Machine Learning', 'Cloud Computing', 'Cybersecurity', 'UI/UX Design', 'DevOps', 'Artificial Intelligence'];
        $levels = ['Beginner', 'Intermediate', 'Advanced'];

        return [
            'name' => fake()->randomElement($subjects).' '.fake()->randomElement($levels),
            'description' => fake()->paragraphs(3, true),
            'instructor_id' => \App\Models\User::factory(),
            'is_active' => true,
        ];
    }

    /**
     * Configure the factory to auto-create a default enrolment method.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (\App\Models\Course $course) {
            // Create a default manual enrolment method for new courses
            // so that enrolment checks (Plan 05) work correctly.
            CourseEnrolmentMethod::query()->firstOrCreate(
                ['course_id' => $course->id, 'method' => 'manual'],
                [
                    'status' => 'active',
                    'default_role' => 'student',
                    'starts_at' => now()->subYear(),
                    'ends_at' => null,
                ]
            );
        });
    }
}
