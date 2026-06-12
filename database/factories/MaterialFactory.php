<?php

namespace Database\Factories;

use App\Models\LearningModule;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Material>
 */
class MaterialFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Material $material): void {
            $material->learningModule()->firstOrCreate([
                'module_type' => LearningModule::TYPE_MATERIAL,
                'module_id' => $material->id,
            ], [
                'course_id' => $material->course_id,
                'visible' => true,
                'sort_order' => $material->id,
            ]);
        });
    }

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
            'file_path' => 'materials/'.fake()->uuid().'.'.($type === 'pdf' ? 'pdf' : 'txt'),
            'file_size' => fake()->numberBetween(100000, 10000000),
            'type' => $type,
            'mime_type' => $type === 'pdf' ? 'application/pdf' : 'text/plain',
            'revision' => 1,
            'checksum' => null,
            'is_active' => true,
        ];
    }

    public function revised(int $revision = 2): static
    {
        return $this->state(fn (array $attributes): array => [
            'revision' => $revision,
        ]);
    }
}
