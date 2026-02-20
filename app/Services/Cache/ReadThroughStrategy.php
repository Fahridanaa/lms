<?php

namespace App\Services\Cache;

use App\Contracts\CacheLoaderInterface;
use App\Contracts\CacheStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * STRATEGI READ-THROUGH
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KONSEP DASAR:
 * Cache layer yang bertanggung jawab untuk fetch data dari database secara OTOMATIS.
 * Aplikasi hanya berinteraksi dengan cache, tidak perlu tahu tentang database!
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ DENGAN LOADERS (Oracle Pattern)                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 *   // Setup (sekali, di ServiceProvider)
 *   $cache = new ReadThroughStrategy([
 *       new QuizCacheLoader(),
 *       new UserCacheLoader(),
 *       new CourseCacheLoader(),
 *   ]);
 *
 *   // Usage (simple, no callback!)
 *   $quiz = $cache->get('quiz:123');   // QuizCacheLoader handles this
 *   $user = $cache->get('user:456');   // UserCacheLoader handles this
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ DENGAN CALLBACK (Fallback/Backward Compatible)                          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 *   // Jika tidak ada loader yang match, callback digunakan
 *   $data = $cache->get('custom:key', fn() => fetchData());
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI                                                            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 *   READ:
 *   1. Cache layer cek apakah data ada di cache
 *   2. Cache MISS → Find loader yang supports($key)
 *   3. Loader.load($key) → Fetch dari database
 *   4. Simpan ke cache → Return data
 *
 *   WRITE:
 *   1. Update database (via callback atau manual)
 *   2. INVALIDATE cache (hapus, bukan update!)
 *   3. Request berikutnya → cache miss → loader fetch data fresh
 */
class ReadThroughStrategy implements CacheStrategyInterface
{
    protected array $cacheTags = [];
    protected int $ttl;
    protected string $prefix;

    /** @var CacheLoaderInterface[] */
    protected array $loaders = [];

    /**
     * @param CacheLoaderInterface[] $loaders Array of loaders
     */
    public function __construct(array $loaders = [])
    {
        $this->ttl = config('caching-strategy.ttl', 3600);
        $this->prefix = config('caching-strategy.prefix', 'lms');
        $this->loaders = $loaders;
    }

    /**
     * Register additional loader
     */
    public function addLoader(CacheLoaderInterface $loader): static
    {
        $this->loaders[] = $loader;
        return $this;
    }

    /**
     * Find loader that supports the given key
     */
    protected function findLoader(string $key): ?CacheLoaderInterface
    {
        foreach ($this->loaders as $loader) {
            if ($loader->supports($key)) {
                return $loader;
            }
        }
        return null;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * GET - Cache layer yang transparently handle fetch dari database
     * ═══════════════════════════════════════════════════════════════════
     *
     * Flow:
     * 1. Check cache → HIT? return
     * 2. Find loader yang supports($key) → load()
     * 3. No loader? CRASH! (RuntimeException)
     *
     * @param string $key Cache key
     * @param callable|null $callback IGNORED - only for interface compatibility
     * @return mixed Data dari cache atau database
     * @throws \RuntimeException If no loader supports the key
     */
    public function get(string $key, ?callable $callback = null): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        $loader = $this->findLoader($key);
        if ($loader === null) {
            throw new \RuntimeException("No loader registered for cache key: {$key}");
        }

        $dataSource = fn() => $loader->load($key);

        if (!empty($this->cacheTags)) {
            return Cache::tags($this->cacheTags)->remember($prefixedKey, $this->ttl, $dataSource);
        }

        return Cache::remember($prefixedKey, $this->ttl, $dataSource);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PUT - Invalidate cache (BUKAN update cache!)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Di Read-Through, put() INVALIDATE cache (hapus), bukan update.
     * Request berikutnya akan cache miss dan loader akan fetch data fresh.
     *
     * @param string $key Cache key
     * @param mixed $value Nilai (untuk persist callback)
     * @param callable|null $persist Callback untuk update database
     * @return bool true jika berhasil
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // Update database (jika persist callback ada)
        if ($persist !== null) {
            $persist($value);
        }

        // INVALIDATE cache (hapus, bukan update!)
        if (!empty($this->cacheTags)) {
            return Cache::tags($this->cacheTags)->forget($prefixedKey);
        }

        return Cache::forget($prefixedKey);
    }

    /**
     * FORGET - Invalidate cache entry
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
     * Add prefix ke cache key
     */
    protected function getPrefixedKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
