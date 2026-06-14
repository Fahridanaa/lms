<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizGrade>
 */
class QuizGradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'user_id' => User::factory(),
            'grade' => $this->faker->randomFloat(2, 0, 100),
            'max_score' => 100,
            'percentage' => $this->faker->randomFloat(2, 0, 100),
            'grading_method' => 'highest',
            'attempt_count' => 1,
            'last_attempt_id' => null,
            'graded_at' => now(),
        ];
    }
}
