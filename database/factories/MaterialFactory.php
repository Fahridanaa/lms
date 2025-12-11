<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Material>
 */
class MaterialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['pdf', 'video', 'document', 'image', 'other']);

        return [
            'course_id' => \App\Models\Course::factory(),
            'title' => fake()->words(3, true),
            'file_path' => 'materials/' . fake()->uuid() . '.' . ($type === 'pdf' ? 'pdf' : 'txt'),
            'file_size' => fake()->numberBetween(100000, 10000000),
            'type' => $type,
        ];
    }
}
