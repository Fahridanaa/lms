<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Grade>
 */
class GradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $score = fake()->randomFloat(2, 0, 100);
        $maxScore = 100;
        $percentage = ($score / $maxScore) * 100;

        return [
            'user_id' => \App\Models\User::factory(),
            'course_id' => \App\Models\Course::factory(),
            'gradeable_type' => fake()->randomElement([\App\Models\Quiz::class, \App\Models\Assignment::class]),
            'gradeable_id' => 1,
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
        ];
    }
}
