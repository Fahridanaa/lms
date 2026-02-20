<?php

namespace App\Services\Cache\Stores;

use App\Repositories\MaterialRepository;

/**
 * Cache Store untuk Material entity
 *
 * Handles keys:
 *   - material:{id} → findWithCourse()
 *   - material:{id}:metadata → metadata array
 */
class MaterialCacheStore extends BaseCacheStore
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

    public function store(string $key, mixed $value): void
    {
        if ($value instanceof \App\Models\Material) {
            $value->save();
            return;
        }

        $id = $this->extractId($key);
        if ($id > 0 && is_array($value)) {
            $this->materialRepository->update($id, $value);
        }
    }

    public function erase(string $key): void
    {
        $id = $this->extractId($key);
        if ($id > 0) {
            $this->materialRepository->delete($id);
        }
    }
}
