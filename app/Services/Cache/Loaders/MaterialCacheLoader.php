<?php

namespace App\Services\Cache\Loaders;

use App\Repositories\MaterialRepository;

/**
 * Cache Loader untuk Material entity
 *
 * Handles keys:
 *   - material:{id} → findWithCourse()
 *   - material:{id}:metadata → find() with metadata transformation
 */
class MaterialCacheLoader extends BaseCacheLoader
{
    protected string $prefix = 'material';

    public function __construct(
        protected MaterialRepository $materialRepository
    ) {}

    public function load(string $key): mixed
    {
        $id = $this->extractId($key);
        $subkey = $this->extractSubkey($key);

        return match ($subkey) {
            'metadata' => $this->loadMetadata($id),
            default => $this->materialRepository->findWithCourse($id),
        };
    }

    protected function loadMetadata(int $id): ?array
    {
        $material = $this->materialRepository->find($id);

        if (!$material) {
            return null;
        }

        return [
            'id' => $material->id,
            'title' => $material->title,
            'file_path' => $material->file_path,
            'file_size' => $material->file_size,
            'type' => $material->type,
            'course_id' => $material->course_id,
        ];
    }
}
