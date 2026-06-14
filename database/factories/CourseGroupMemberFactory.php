<?php

namespace Database\Factories;

use App\Models\CourseGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseGroupMember>
 */
class CourseGroupMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_group_id' => CourseGroup::factory(),
            'user_id' => User::factory(),
        ];
    }
}
