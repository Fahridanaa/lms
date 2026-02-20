<?php

namespace App\Services\Cache\Loaders;

use App\Contracts\CacheLoaderInterface;

/**
 * Base Cache Loader dengan helper methods untuk parsing cache keys
 *
 * Cache key format: {entity}:{id}:{subkey?}
 * Examples:
 *   - quiz:123
 *   - quiz:123:questions
 *   - course:45:materials
 *   - user:789:all-attempts
 */
abstract class BaseCacheLoader implements CacheLoaderInterface
{
    /**
     * Prefix yang di-support oleh loader ini
     * Override di child class
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
     *
     * Examples:
     *   - "quiz:123" → 123
     *   - "quiz:123:questions" → 123
     *   - "course:45:materials" → 45
     */
    protected function extractId(string $key): int
    {
        $parts = explode(':', $key);
        return (int) ($parts[1] ?? 0);
    }

    /**
     * Extract subkey (bagian setelah entity:id)
     *
     * Examples:
     *   - "quiz:123:questions" → "questions"
     *   - "quiz:123:with-questions" → "with-questions"
     *   - "quiz:123" → null
     */
    protected function extractSubkey(string $key): ?string
    {
        $parts = explode(':', $key);
        if (count($parts) < 3) {
            return null;
        }
        // Join remaining parts (for keys like "course:1:user:2:grades")
        return implode(':', array_slice($parts, 2));
    }

    /**
     * Parse key menjadi array of parts
     *
     * Example: "course:1:user:2:grades" → ['course', '1', 'user', '2', 'grades']
     */
    protected function parseKey(string $key): array
    {
        return explode(':', $key);
    }

    /**
     * Extract multiple IDs dari complex key
     *
     * Example: "course:1:user:2:grades" → ['course' => 1, 'user' => 2]
     */
    protected function extractIds(string $key): array
    {
        $parts = $this->parseKey($key);
        $ids = [];

        for ($i = 0; $i < count($parts) - 1; $i += 2) {
            if (isset($parts[$i + 1]) && is_numeric($parts[$i + 1])) {
                $ids[$parts[$i]] = (int) $parts[$i + 1];
            }
        }

        return $ids;
    }
}
