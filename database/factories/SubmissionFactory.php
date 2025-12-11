<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Submission>
 */
class SubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $submittedAt = fake()->dateTimeBetween('-2 months', 'now');
        $graded = fake()->boolean(70);
        $gradedAt = $graded ? fake()->dateTimeBetween($submittedAt, 'now') : null;

        return [
            'assignment_id' => \App\Models\Assignment::factory(),
            'user_id' => \App\Models\User::factory(),
            'file_path' => 'submissions/' . fake()->uuid() . '.pdf',
            'score' => $graded ? fake()->randomFloat(2, 0, 100) : null,
            'feedback' => $graded ? fake()->optional(0.8)->sentence() : null,
            'submitted_at' => $submittedAt,
            'graded_at' => $gradedAt,
        ];
    }
}
