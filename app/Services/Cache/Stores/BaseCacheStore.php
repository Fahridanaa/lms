<?php

namespace App\Services\Cache\Stores;

use App\Contracts\CacheStoreInterface;

/**
 * Base Cache Store dengan helper methods untuk parsing cache keys
 *
 * Extends CacheLoaderInterface dengan write capabilities.
 * Semua operasi store/erase HARUS idempotent.
 */
abstract class BaseCacheStore implements CacheStoreInterface
{
    /**
     * Prefix yang di-support oleh store ini
     */
    protected string $prefix = '';

    /**
     * Check apakah key di-support berdasarkan prefix
     */
    public function supports(string $key): bool
    {
        return str_starts_with($key, $this->prefix . ':');
    }

    /**
     * Extract ID dari cache key
     */
    protected function extractId(string $key): int
    {
        $parts = explode(':', $key);
        return (int) ($parts[1] ?? 0);
    }

    /**
     * Extract subkey (bagian setelah entity:id)
     */
    protected function extractSubkey(string $key): ?string
    {
        $parts = explode(':', $key);
        if (count($parts) < 3) {
            return null;
        }
        return implode(':', array_slice($parts, 2));
    }

    /**
     * Parse key menjadi array of parts
     */
    protected function parseKey(string $key): array
    {
        return explode(':', $key);
    }
}
