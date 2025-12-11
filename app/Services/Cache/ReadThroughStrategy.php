<?php

namespace App\Services\Cache;

use App\Contracts\CacheStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Read-Through Caching Strategy
 *
 * The cache layer automatically handles DB reads.
 * On cache miss, the cache itself fetches from the database.
 *
 * READ: Cache intercepts → if miss, cache fetches from DB → returns data
 * WRITE: Update DB → invalidate cache
 */
class ReadThroughStrategy implements CacheStrategyInterface
{
    protected array $cacheTags = [];
    protected int $ttl;
    protected string $prefix;

    public function __construct()
    {
        $this->ttl = config('caching-strategy.ttl', 3600);
        $this->prefix = config('caching-strategy.prefix', 'lms');
    }

    /**
     * Get data from cache, or fetch from data source
     * Cache layer transparently handles the read-through
     */
    public function get(string $key, callable $callback): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // Read-Through: Laravel's remember() method handles this pattern perfectly
        // It checks cache, and if miss, executes callback and stores result
        if (!empty($this->cacheTags)) {
            return Cache::tags($this->cacheTags)->remember($prefixedKey, $this->ttl, $callback);
        }

        return Cache::remember($prefixedKey, $this->ttl, $callback);
    }

    /**
     * Write data (invalidate cache only, actual DB write handled by caller)
     * In read-through, writes don't update cache - they invalidate it
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // If persist callback provided, execute it (DB write)
        if ($persist !== null) {
            $persist($value);
        }

        // Invalidate the cache entry
        if (!empty($this->cacheTags)) {
            return Cache::tags($this->cacheTags)->forget($prefixedKey);
        }

        return Cache::forget($prefixedKey);
    }

    /**
     * Remove specific key from cache
     */
    public function forget(string $key): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        if (!empty($this->cacheTags)) {
            return Cache::tags($this->cacheTags)->forget($prefixedKey);
        }

        return Cache::forget($prefixedKey);
    }

    /**
     * Remember data in cache (same as get for read-through)
     */
    public function remember(string $key, callable $callback): mixed
    {
        return $this->get($key, $callback);
    }

    /**
     * Set cache tags for this operation
     */
    public function tags(array $tags): self
    {
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * Flush all cache entries with specific tags
     */
    public function flushTags(array $tags): bool
    {
        try {
            Cache::tags($tags)->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get prefixed cache key
     */
    protected function getPrefixedKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
