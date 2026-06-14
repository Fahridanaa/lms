<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\CourseGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AssignmentOverride>
 */
class AssignmentOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'user_id' => null,
            'course_group_id' => null,
            'available_from' => null,
            'due_date' => null,
            'cutoff_date' => null,
            'max_attempts' => null,
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
     * Extend the due date by the given number of days.
     */
    public function extendDueDate(int $days = 7): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_date' => now()->addDays($days),
        ]);
    }

    /**
     * Extend the cutoff date by the given number of days.
     */
    public function extendCutoffDate(int $days = 14): static
    {
        return $this->state(fn (array $attributes): array => [
            'cutoff_date' => now()->addDays($days),
        ]);
    }
}
