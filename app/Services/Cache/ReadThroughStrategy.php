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
     * @param  CacheLoaderInterface[]  $loaders  Array of loaders
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
     * 3. No loader? Execute callback fallback
     *
     * @param  string  $key  Cache key
     * @param  callable|null  $callback  Fallback jika tidak ada loader yang support key ini
     * @return mixed Data dari cache atau database
     *
     * @throws \RuntimeException If no loader supports the key AND no callback provided
     */
    public function get(string $key, ?callable $callback = null): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        try {
            $loader = $this->findLoader($key);

            if ($loader !== null) {
                // Ada loader — gunakan loader, callback diabaikan
                $dataSource = fn () => $loader->load($key);

                if (! empty($this->cacheTags)) {
                    return Cache::tags($this->cacheTags)->remember($prefixedKey, $this->ttl, $dataSource);
                }

                return Cache::remember($prefixedKey, $this->ttl, $dataSource);
            }

            // Tidak ada loader — fallback ke callback
            if ($callback === null) {
                throw new \RuntimeException("No loader registered for cache key: {$key}");
            }

            $dataSource = fn () => $callback();

            if (! empty($this->cacheTags)) {
                return Cache::tags($this->cacheTags)->remember($prefixedKey, $this->ttl, $dataSource);
            }

            return Cache::remember($prefixedKey, $this->ttl, $dataSource);
        } finally {
            // Reset tags setelah operasi selesai
            $this->cacheTags = [];
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PUT - Invalidate cache (BUKAN update cache!)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Di Read-Through, put() INVALIDATE cache (hapus), bukan update.
     * Request berikutnya akan cache miss dan loader akan fetch data fresh.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $value  Nilai (untuk persist callback)
     * @param  callable|null  $persist  Callback untuk update database
     * @return bool true jika berhasil
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        try {
            // Update database (jika persist callback ada)
            if ($persist !== null) {
                $persist($value);
            }

            // INVALIDATE cache (hapus, bukan update!)
            return !empty($this->cacheTags)
                ? Cache::tags($this->cacheTags)->forget($prefixedKey)
                : Cache::forget($prefixedKey);
        } finally {
            // Reset tags setelah operasi selesai
            $this->cacheTags = [];
        }
    }

    /**
     * FORGET - Invalidate cache entry
     */
    public function forget(string $key): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        try {
            return !empty($this->cacheTags)
                ? Cache::tags($this->cacheTags)->forget($prefixedKey)
                : Cache::forget($prefixedKey);
        } finally {
            // Reset tags setelah operasi selesai
            $this->cacheTags = [];
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
    /**
     * TAGS - Set tag untuk cache berkelompok
     *
     * Tags akan di-reset secara otomatis setelah operasi cache (get/put/forget)
     * selesai. Ini mencegah tags bertahan antar operasi yang berbeda.
     *
     * @param array $tags Array nama tag
     * @return self Untuk method chaining
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
        // Reset cacheTags karena operasi ini eksplisit menggunakan tags baru
        $this->cacheTags = [];

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
