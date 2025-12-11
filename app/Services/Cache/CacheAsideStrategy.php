<?php

namespace App\Services\Cache;

use App\Contracts\CacheStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-Aside (Lazy Loading) Strategy
 *
 * Application explicitly manages cache:
 * - READ: Check cache → if miss, query DB → store in cache → return
 * - WRITE: Update DB → invalidate cache
 *
 * Best for: Read-heavy workloads where cache misses are acceptable
 */
class CacheAsideStrategy implements CacheStrategyInterface
{
    /**
     * Cache tags for grouped operations
     *
     * @var array
     */
    protected array $cacheTags = [];

    /**
     * Cache TTL in seconds
     *
     * @var int
     */
    protected int $ttl;

    /**
     * Cache key prefix
     *
     * @var string
     */
    protected string $prefix;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->ttl = config('caching-strategy.ttl', 3600);
        $this->prefix = config('caching-strategy.prefix', 'lms');
    }

    /**
     * Get item from cache or execute callback
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute on cache miss
     * @return mixed
     */
    public function get(string $key, callable $callback): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // Check if cache tags are set
        if (!empty($this->cacheTags)) {
            $value = Cache::tags($this->cacheTags)->get($prefixedKey);
        } else {
            $value = Cache::get($prefixedKey);
        }

        // Cache hit - return cached value
        if ($value !== null) {
            return $value;
        }

        // Cache miss - execute callback to fetch fresh data
        $value = $callback();

        // Store in cache
        $this->put($prefixedKey, $value);

        return $value;
    }

    /**
     * Store item in cache
     *
     * In Cache-Aside, application handles DB writes separately.
     * This method just updates the cache.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param callable|null $persist Not used in Cache-Aside (app handles DB writes)
     * @return bool
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        if (!empty($this->cacheTags)) {
            return Cache::tags($this->cacheTags)->put($prefixedKey, $value, $this->ttl);
        }

        return Cache::put($prefixedKey, $value, $this->ttl);
    }

    /**
     * Remove item from cache
     *
     * @param string $key Cache key
     * @return bool
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
     * Get from cache or execute callback and store result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute on cache miss
     * @return mixed
     */
    public function remember(string $key, callable $callback): mixed
    {
        return $this->get($key, $callback);
    }

    /**
     * Set cache tags for grouped operations
     *
     * @param array $tags Array of tag names
     * @return self
     */
    public function tags(array $tags): self
    {
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * Flush all cache entries with specific tags
     *
     * @param array $tags Array of tag names
     * @return bool
     */
    public function flushTags(array $tags): bool
    {
        return Cache::tags($tags)->flush();
    }

    /**
     * Get prefixed cache key
     *
     * @param string $key Original key
     * @return string Prefixed key
     */
    protected function getPrefixedKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
