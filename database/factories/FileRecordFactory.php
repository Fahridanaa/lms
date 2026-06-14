<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FileRecord>
 */
class FileRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mimeTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/png',
            'image/jpeg',
            'text/plain',
            'application/zip',
        ];

        $mimeType = fake()->randomElement($mimeTypes);

        return [
            'owner_type' => 'material',
            'owner_id' => \App\Models\Material::factory(),
            'uploader_id' => User::factory(),
            'component' => fake()->randomElement(['material', 'assignment_submission']),
            'file_path' => 'files/'.fake()->uuid().'.'.fake()->fileExtension(),
            'mime_type' => $mimeType,
            'file_size' => fake()->numberBetween(1024, 10485760),
            'checksum' => fake()->sha1(),
            'revision' => 1,
            'visible' => true,
        ];
    }

    public function invisible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visible' => false,
        ]);
    }

    public function newRevision(int $revision = 2): static
    {
        return $this->state(fn (array $attributes): array => [
            'revision' => $revision,
        ]);
    }
}
