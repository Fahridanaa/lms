<?php

namespace App\Contracts;

/**
 * Cache Strategy Interface
 *
 * Defines the contract for all caching strategies (Cache-Aside, Read-Through, Write-Through).
 * This interface ensures consistent API across different caching implementations.
 */
interface CacheStrategyInterface
{
    /**
     * Get item from cache or execute callback to fetch data
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss occurs
     * @return mixed Cached or freshly fetched data
     */
    public function get(string $key, callable $callback): mixed;

    /**
     * Store item in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param callable|null $persist Optional callback to persist data to database
     * @return bool Success status
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool;

    /**
     * Remove item from cache
     *
     * @param string $key Cache key to remove
     * @return bool Success status
     */
    public function forget(string $key): bool;

    /**
     * Get from cache or execute callback and store result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @return mixed Cached or freshly fetched data
     */
    public function remember(string $key, callable $callback): mixed;

    /**
     * Tag cache entries for grouped operations
     *
     * @param array $tags Array of tag names
     * @return self Returns instance for method chaining
     */
    public function tags(array $tags): self;

    /**
     * Flush all entries with specific tags
     *
     * @param array $tags Array of tag names to flush
     * @return bool Success status
     */
    public function flushTags(array $tags): bool;
}
