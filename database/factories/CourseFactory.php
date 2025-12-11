<?php

namespace Database\Factories;

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
            'name' => fake()->randomElement($subjects) . ' ' . fake()->randomElement($levels),
            'description' => fake()->paragraphs(3, true),
            'instructor_id' => \App\Models\User::factory(),
        ];
    }
}
