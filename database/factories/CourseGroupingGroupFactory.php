<?php

namespace Database\Factories;

use App\Models\CourseGroup;
use App\Models\CourseGrouping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseGroupingGroup>
 */
class CourseGroupingGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_grouping_id' => CourseGrouping::factory(),
            'course_group_id' => CourseGroup::factory(),
        ];
    }
}
