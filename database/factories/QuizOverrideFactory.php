<?php

namespace Database\Factories;

use App\Models\CourseGroup;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizOverride>
 */
class QuizOverrideFactory extends Factory
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
            'user_id' => null,
            'course_group_id' => null,
            'available_from' => null,
            'available_until' => null,
            'time_limit' => null,
            'max_attempts' => null,
            'grace_period' => null,
            'reason' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Apply this override to a specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->id,
            'course_group_id' => null,
        ]);
    }

    /**
     * Apply this override to a specific course group.
     */
    public function forGroup(CourseGroup $group): static
    {
        return $this->state(fn (array $attributes): array => [
            'course_group_id' => $group->id,
            'user_id' => null,
        ]);
    }

    /**
     * Grant extra time by the given number of minutes.
     */
    public function extraTime(int $minutes = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'time_limit' => $minutes,
        ]);
    }
}
