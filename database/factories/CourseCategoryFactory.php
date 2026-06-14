<?php

namespace Database\Factories;

use App\Models\CourseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseCategory>
 */
class CourseCategoryFactory extends Factory
{
    protected $model = CourseCategory::class;

    private static array $categoryNames = [
        'Computer Science',
        'Engineering',
        'Business & Management',
        'Arts & Humanities',
        'Health & Medicine',
        'Data Science',
        'Design',
        'Mathematics',
    ];

    private static int $nameIndex = 0;

    public function definition(): array
    {
        $name = self::$categoryNames[self::$nameIndex % count(self::$categoryNames)];
        self::$nameIndex++;

        return [
            'name' => $name,
            'description' => $name.' category description.',
            'sort_order' => 0,
            'visible' => true,
            'depth' => 0,
            'path' => null,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visible' => false,
        ]);
    }

    public function childOf(CourseCategory $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_id' => $parent->id,
            'depth' => $parent->depth + 1,
            'path' => ($parent->path ? $parent->path.'/' : '').$parent->id,
        ]);
    }
}
