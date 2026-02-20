<?php

namespace App\Services\Cache;

use App\Contracts\CacheStoreInterface;
use App\Contracts\CacheStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * STRATEGI WRITE-THROUGH
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KONSEP DASAR:
 * Setiap WRITE operation dilakukan ke DATABASE dan CACHE secara BERSAMAAN.
 * Cache SELALU sinkron dengan database!
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ DENGAN STORES (Oracle Pattern)                                          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 *   // Setup (sekali, di ServiceProvider)
 *   $cache = new WriteThroughStrategy([
 *       new QuizCacheStore(),
 *       new UserCacheStore(),
 *       new CourseCacheStore(),
 *   ]);
 *
 *   // Usage (simple, no callback!)
 *   $cache->put('quiz:123', $quiz);   // QuizCacheStore handles DB + cache
 *   $cache->put('user:456', $user);   // UserCacheStore handles DB + cache
 *   $cache->get('quiz:123');          // QuizCacheStore loads if miss
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ DENGAN CALLBACK (Fallback/Backward Compatible)                          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 *   // Jika tidak ada store yang match, callback digunakan
 *   $cache->get('custom:key', fn() => fetchData());
 *   $cache->put('custom:key', $data, fn($d) => saveData($d));
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI                                                            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 *   READ:
 *   1. Check cache → HIT? return
 *   2. Find store yang supports($key) → load()
 *   3. Simpan ke cache → Return data
 *
 *   WRITE:
 *   1. Find store yang supports($key) → store()
 *   2. Update cache dengan data yang sama
 *   3. Cache dan DB SELALU sinkron!
 */
class WriteThroughStrategy implements CacheStrategyInterface
{
    protected array $cacheTags = [];
    protected int $ttl;
    protected string $prefix;

    /** @var CacheStoreInterface[] */
    protected array $stores = [];

    /**
     * @param CacheStoreInterface[] $stores Array of stores
     */
    public function __construct(array $stores = [])
    {
        $this->ttl = config('caching-strategy.ttl', 3600);
        $this->prefix = config('caching-strategy.prefix', 'lms');
        $this->stores = $stores;
    }

    /**
     * Register additional store
     */
    public function addStore(CacheStoreInterface $store): static
    {
        $this->stores[] = $store;
        return $this;
    }

    /**
     * Find store that supports the given key
     */
    protected function findStore(string $key): ?CacheStoreInterface
    {
        foreach ($this->stores as $store) {
            if ($store->supports($key)) {
                return $store;
            }
        }
        return null;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * GET - Membaca data (menggunakan Store.load())
     * ═══════════════════════════════════════════════════════════════════
     *
     * @param string $key Cache key
     * @param callable|null $callback IGNORED - only for interface compatibility
     * @return mixed Data dari cache atau database
     * @throws \RuntimeException If no store supports the key
     */
    public function get(string $key, ?callable $callback = null): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // Check cache
        if (!empty($this->cacheTags)) {
            $value = Cache::tags($this->cacheTags)->get($prefixedKey);
        } else {
            $value = Cache::get($prefixedKey);
        }

        // Cache HIT
        if ($value !== null) {
            return $value;
        }

        // Cache MISS - use store
        $store = $this->findStore($key);
        if ($store === null) {
            throw new \RuntimeException("No store registered for cache key: {$key}");
        }

        $value = $store->load($key);

        // Store in cache
        $this->storeInCache($prefixedKey, $value);

        return $value;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PUT - Write ke DATABASE dan CACHE secara BERSAMAAN
     * ═══════════════════════════════════════════════════════════════════
     *
     * @param string $key Cache key
     * @param mixed $value Data yang akan disimpan
     * @param callable|null $persist IGNORED - only for interface compatibility
     * @return bool true jika berhasil
     * @throws \RuntimeException If no store supports the key
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        $store = $this->findStore($key);
        if ($store === null) {
            throw new \RuntimeException("No store registered for cache key: {$key}");
        }

        try {
            // STEP 1: Write ke DATABASE via store
            $store->store($key, $value);

            // STEP 2: Write ke CACHE
            $this->storeInCache($prefixedKey, $value);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * FORGET - Hapus dari cache dan database (via Store.erase())
     *
     * @throws \RuntimeException If no store supports the key
     */
    public function forget(string $key): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        $store = $this->findStore($key);
        if ($store === null) {
            throw new \RuntimeException("No store registered for cache key: {$key}");
        }

        try {
            // Erase dari database via store
            $store->erase($key);

            // Hapus dari cache
            if (!empty($this->cacheTags)) {
                return Cache::tags($this->cacheTags)->forget($prefixedKey);
            }

            return Cache::forget($prefixedKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * REMEMBER - Sama dengan get()
     */
    public function remember(string $key, ?callable $callback = null): mixed
    {
        return $this->get($key, $callback);
    }

    /**
     * TAGS - Set tag untuk cache berkelompok
     */
    public function tags(array $tags): self
    {
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * FLUSH TAGS - Hapus semua cache dengan tag tertentu
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
     * Store ke cache (dengan atau tanpa tags)
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
     * Add prefix ke cache key
     */
    protected function getPrefixedKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
