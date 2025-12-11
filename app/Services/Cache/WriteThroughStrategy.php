<?php

namespace App\Services\Cache;

use App\Contracts\CacheStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Write-Through Caching Strategy
 *
 * Synchronous writes to both cache and database.
 * Cache is always kept in sync with the database.
 *
 * READ: Check cache → if miss, query DB → store in cache
 * WRITE: Write to cache AND database simultaneously
 */
class WriteThroughStrategy implements CacheStrategyInterface
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
     */
    public function get(string $key, callable $callback): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        if (!empty($this->cacheTags)) {
            $value = Cache::tags($this->cacheTags)->get($prefixedKey);
        } else {
            $value = Cache::get($prefixedKey);
        }

        // If cache hit, return the value
        if ($value !== null) {
            return $value;
        }

        // Cache miss: fetch from database
        $value = $callback();

        // Store in cache for future reads
        $this->storeInCache($prefixedKey, $value);

        return $value;
    }

    /**
     * Write data to both cache and database simultaneously
     * This is the key difference from cache-aside and read-through
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        try {
            // Step 1: Write to database first (if persist callback provided)
            if ($persist !== null) {
                $persist($value);
            }

            // Step 2: Write to cache immediately (synchronous)
            $this->storeInCache($prefixedKey, $value);

            return true;
        } catch (\Exception $e) {
            // If either operation fails, the strategy should handle it
            // In production, you might want to log this
            return false;
        }
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
     * Remember data in cache
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
     * Store value in cache with tags if applicable
     */
    protected function storeInCache(string $key, mixed $value): void
    {
        if (!empty($this->cacheTags)) {
            Cache::tags($this->cacheTags)->put($key, $value, $this->ttl);
        } else {
            Cache::put($key, $value, $this->ttl);
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
