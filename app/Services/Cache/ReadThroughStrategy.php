<?php

namespace App\Services\Cache;

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
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ PERBEDAAN UTAMA DENGAN CACHE-ASIDE                                   │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * CACHE-ASIDE:
 *   Aplikasi → Cek Cache → Miss? → Aplikasi Query DB → Aplikasi Update Cache
 *   (Aplikasi yang HANDLE semuanya)
 *
 * READ-THROUGH:
 *   Aplikasi → Minta ke Cache → Cache otomatis cek → Miss? → Cache Query DB → Return
 *   (Cache yang HANDLE semuanya, aplikasi cuma minta)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI READ (Membaca Data)                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   1. Aplikasi minta data ke cache layer
 *      ↓
 *   2. Cache layer cek apakah data ada
 *      ├─ YES (Cache HIT)  → Return data dari cache ✓
 *      └─ NO  (Cache MISS) → Lanjut ke step 3
 *             ↓
 *   3. Cache layer OTOMATIS query database (aplikasi tidak tahu!)
 *      ↓
 *   4. Cache layer simpan hasil query untuk next request
 *      ↓
 *   5. Cache layer return data ke aplikasi
 *
 *   Kode (menggunakan Laravel Cache::remember):
 *   $data = Cache::remember('user:1', 3600, function() {
 *       return DB::find(1);  // Dieksekusi otomatis oleh cache jika miss
 *   });
 *
 *   PERHATIKAN: Aplikasi hanya memanggil Cache::remember SEKALI.
 *   Cache yang menangani semua logic: cek → miss? → query → simpan → return
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI WRITE (Update Data)                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   PENTING: Di Read-Through, write operation TIDAK update cache!
 *   Yang dilakukan adalah INVALIDATION (hapus cache).
 *
 *   1. Aplikasi/Service update database
 *      ↓
 *   2. Aplikasi/Service INVALIDATE (hapus) cache
 *      ↓
 *   3. Request READ berikutnya akan cache miss
 *      ↓
 *   4. Cache akan otomatis fetch data TERBARU dari database
 *
 *   Kode:
 *   DB::update(['name' => 'New']);    // Step 1 - Update DB
 *   Cache::forget('user:1');           // Step 2 - Hapus cache
 *   // Step 3-4 terjadi otomatis di request berikutnya
 *
 *   KENAPA INVALIDATE, BUKAN UPDATE?
 *   ✓ Lebih sederhana - tidak perlu tahu struktur data yang di-cache
 *   ✓ Lebih aman - cache pasti sinkron dengan DB (karena langsung diambil dari DB)
 *   ✓ Konsisten dengan prinsip "cache as single source" untuk READ
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KARAKTERISTIK UTAMA                                                  │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Cache layer sebagai ABSTRAKSI transparan antara app dan database
 * ✓ Aplikasi tidak perlu tahu tentang cache miss/hit
 * ✓ Cache yang handle semua read logic (fetch, store, return)
 * ✓ Write = UPDATE DB + INVALIDATE cache (bukan update cache)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KAPAN MENGGUNAKAN READ-THROUGH?                                      │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Ingin simplifikasi application code (cache handle semuanya)
 * ✓ Read-heavy workload dengan data yang konsisten
 * ✓ Acceptable jika write operation sedikit lebih lambat
 * ✓ Ingin cache sebagai "transparent layer" di depan database
 *
 * CONTOH USE CASE:
 * - Course catalog (banyak read, jarang write)
 * - User profiles (sering dibaca, jarang update)
 * - Configuration settings (hampir selalu read)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KELEBIHAN                                                            │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Application code lebih simple (cache handle complexity)
 * ✓ Cache consistency terjamin (selalu sync dengan DB)
 * ✓ Cache as "single source of truth" untuk read operations
 * ✓ Tidak perlu khawatir lupa update cache saat write
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KEKURANGAN                                                           │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✗ Cache miss penalty (request pertama lambat)
 * ✗ Write operation butuh 2 step: update DB + invalidate cache
 * ✗ Tidak cocok untuk write-heavy workload (banyak invalidation)
 * ✗ Cache churn jika data sering berubah
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
     * ═══════════════════════════════════════════════════════════════════
     * GET - Cache layer yang transparently handle fetch dari database
     * ═══════════════════════════════════════════════════════════════════
     *
     * INI ADALAH INTI dari Read-Through pattern!
     * Menggunakan Cache::remember() yang secara OTOMATIS:
     * 1. Cek cache
     * 2. Jika miss → Eksekusi callback
     * 3. Simpan hasil ke cache
     * 4. Return data
     *
     * FLOW INTERNAL (handled by Laravel Cache::remember):
     * → Cache::get('key')
     * → null? → callback() → Cache::put('key', result) → return result
     * → ada? → return result
     *
     * CONTOH PENGGUNAAN:
     * ```php
     * $quiz = $readThrough->get('quiz:123', function() {
     *     return Quiz::find(123);  // Hanya dieksekusi jika cache miss
     * });
     * ```
     *
     * APLIKASI TIDAK PERLU TAHU:
     * - Apakah data dari cache atau database
     * - Kapan cache di-populate
     * - Bagaimana cache di-manage
     *
     * Cache layer handle semuanya secara transparan!
     *
     * @param string $key Cache key
     * @param callable $callback Data source (akan dieksekusi otomatis jika cache miss)
     * @return mixed Data dari cache atau database
     */
    public function get(string $key, callable $callback): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // READ-THROUGH: Cache::remember() melakukan semua magic!
        // 1. Cek cache
        // 2. Miss? → Eksekusi callback → Simpan hasil → Return
        // 3. Hit? → Return langsung
        if (!empty($this->cacheTags)) {
            return Cache::tags($this->cacheTags)->remember($prefixedKey, $this->ttl, $callback);
        }

        return Cache::remember($prefixedKey, $this->ttl, $callback);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PUT - Invalidate cache (BUKAN update cache!)
     * ═══════════════════════════════════════════════════════════════════
     *
     * INI PERBEDAAN PENTING dari Cache-Aside!
     *
     * CACHE-ASIDE put():    Update cache dengan value baru
     * READ-THROUGH put():   HAPUS cache (invalidation)
     *
     * KENAPA INVALIDATE?
     * Karena prinsip Read-Through: "Cache akan fetch data fresh dari DB
     * secara otomatis di request berikutnya."
     *
     * FLOW:
     * 1. Eksekusi persist callback (update database)
     * 2. HAPUS cache (bukan update!)
     * 3. Request berikutnya akan cache miss
     * 4. Cache akan otomatis fetch data TERBARU dari database
     *
     * CONTOH PENGGUNAAN:
     * ```php
     * // Update database dan invalidate cache
     * $readThrough->put('quiz:123', $newData, function($data) {
     *     Quiz::find(123)->update($data);  // Update DB
     * });
     * // Cache dihapus, request berikutnya akan ambil data fresh dari DB
     * ```
     *
     * ATAU bisa juga:
     * ```php
     * // Update DB manual
     * Quiz::find(123)->update(['title' => 'New']);
     *
     * // Lalu invalidate cache
     * $readThrough->forget('quiz:123');
     * ```
     *
     * @param string $key Cache key
     * @param mixed $value Nilai (untuk persist callback)
     * @param callable|null $persist Callback untuk update database
     * @return bool true jika berhasil
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // Step 1: Update database (jika persist callback ada)
        if ($persist !== null) {
            $persist($value);
        }

        // Step 2: INVALIDATE cache (hapus, bukan update!)
        // Request berikutnya akan cache miss dan fetch data fresh
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
     * Untuk Read-Through, remember() dan get() identik
     */
    public function remember(string $key, callable $callback): mixed
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
     *
     * CONTOH:
     * ```php
     * // Semua quiz di-cache dengan tag 'quizzes'
     * $strategy->tags(['quizzes'])->get('quiz:1', ...);
     * $strategy->tags(['quizzes'])->get('quiz:2', ...);
     *
     * // Invalidate semua quiz sekaligus
     * $strategy->flushTags(['quizzes']);
     * ```
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
