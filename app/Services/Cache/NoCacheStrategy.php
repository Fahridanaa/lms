<?php

namespace App\Services\Cache;

use App\Contracts\CacheStrategyInterface;

/**
 * No-Cache (Baseline) Strategy
 *
 * Bypasses cache entirely - all operations go directly to the database.
 * Used as a baseline/control group for performance comparisons.
 *
 * - READ: Always query database directly
 * - WRITE: Update database only (no cache operations)
 *
 * Best for: Baseline measurements to quantify cache impact
 */
class NoCacheStrategy implements CacheStrategyInterface
{
    /**
     * Cache tags (unused, kept for interface compatibility)
     *
     * @var array
     */
    protected array $cacheTags = [];

    /**
     * Get item - always executes callback (no caching)
     *
     * @param string $key Cache key (ignored)
     * @param callable $callback Function to execute
     * @return mixed
     */
    public function get(string $key, callable $callback): mixed
    {
        // Always execute callback - no cache lookup
        return $callback();
    }

    /**
     * Store item - does nothing (no caching)
     *
     * @param string $key Cache key (ignored)
     * @param mixed $value Value (ignored)
     * @param callable|null $persist Optional persist callback
     * @return bool
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        // If persist callback provided, execute it
        if ($persist !== null) {
            $persist($value);
        }

        // Always return true (operation successful)
        return true;
    }

    /**
     * Remove item - does nothing (no cache to clear)
     *
     * @param string $key Cache key (ignored)
     * @return bool
     */
    public function forget(string $key): bool
    {
        // Nothing to forget - always successful
        return true;
    }

    /**
     * Remember - always executes callback (no caching)
     *
     * @param string $key Cache key (ignored)
     * @param callable $callback Function to execute
     * @return mixed
     */
    public function remember(string $key, callable $callback): mixed
    {
        // Always execute callback - no caching
        return $callback();
    }

    /**
     * Set cache tags - no-op for interface compatibility
     *
     * @param array $tags Array of tag names (ignored)
     * @return self
     */
    public function tags(array $tags): self
    {
        // Store tags but don't use them
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * Flush cache tags - no-op (no cache to flush)
     *
     * @param array $tags Array of tag names (ignored)
     * @return bool
     */
    public function flushTags(array $tags): bool
    {
        // Nothing to flush - always successful
        return true;
    }
}
