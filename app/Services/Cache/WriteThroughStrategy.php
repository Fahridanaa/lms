<?php

namespace App\Services\Cache;

use App\Contracts\CacheStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * STRATEGI WRITE-THROUGH
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KONSEP DASAR:
 * Setiap WRITE operation dilakukan ke DATABASE dan CACHE secara BERSAMAAN (synchronous).
 * Cache SELALU sinkron dengan database karena di-update di waktu yang sama!
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ PERBEDAAN DENGAN STRATEGI LAIN                                       │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * CACHE-ASIDE (Write):
 *   1. Update DB
 *   2. Update cache (atau invalidate)
 *   (2 step terpisah, bisa inconsistent jika step 2 gagal)
 *
 * READ-THROUGH (Write):
 *   1. Update DB
 *   2. Invalidate cache (hapus)
 *   3. Request berikutnya → cache miss → fetch dari DB
 *   (Cache kosong setelah write, perlu populate lagi)
 *
 * WRITE-THROUGH (Write):
 *   1. Update DB dan Cache BERSAMAAN (atomic operation)
 *   (Cache langsung ter-update, selalu sinkron dengan DB!)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI READ (Membaca Data)                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   SAMA seperti Cache-Aside untuk READ operations:
 *
 *   1. Cek cache dulu
 *      ↓
 *   2. Cache HIT?
 *      ├─ YES → Return dari cache ✓
 *      └─ NO  → Lanjut step 3
 *   3. Query database
 *      ↓
 *   4. Simpan ke cache
 *      ↓
 *   5. Return data
 *
 *   TIDAK ADA PERBEDAAN untuk READ antara Cache-Aside dan Write-Through.
 *   Perbedaannya HANYA di WRITE operation!
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI WRITE (Update Data) - INI YANG BERBEDA!                │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   WRITE-THROUGH melakukan write ke DB dan Cache SECARA BERSAMAAN:
 *
 *   1. START Write Operation
 *      ↓
 *   2. Write ke DATABASE ← Step pertama
 *      ↓
 *   3. Write ke CACHE    ← Step kedua (segera setelah DB)
 *      ↓
 *   4. DONE - Both DB and Cache updated!
 *
 *   Kode:
 *   $writeThrough->put('user:1', $newData, function($data) {
 *       DB::update($data);  // Step 2 - Update DB
 *   });
 *   // Step 3 terjadi otomatis di dalam put()
 *   // Cache langsung ter-update dengan data yang sama!
 *
 *   KEUNTUNGAN:
 *   ✓ Cache SELALU sinkron dengan database
 *   ✓ Request berikutnya langsung cache HIT (tidak perlu fetch dari DB)
 *   ✓ Konsistensi data terjamin
 *
 *   KEKURANGAN:
 *   ✗ Write operation LEBIH LAMBAT (harus write ke 2 tempat)
 *   ✗ Jika cache write gagal, bisa inconsistent
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ DIAGRAM KOMPARASI WRITE OPERATION                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   CACHE-ASIDE:
 *   App → Update DB → App → Update Cache
 *   (2 step manual, bisa lupa update cache)
 *
 *   READ-THROUGH:
 *   App → Update DB → App → Delete Cache → Wait next request → Fetch DB
 *   (Cache kosong, request berikutnya lambat)
 *
 *   WRITE-THROUGH:
 *   App → [Update DB + Update Cache simultaneously]
 *   (1 operation, 2 target, cache langsung ready!)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KARAKTERISTIK UTAMA                                                  │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Synchronous write ke DB dan Cache
 * ✓ Cache SELALU update-to-date dengan database
 * ✓ No cache miss after write (langsung available di cache)
 * ✓ Strong consistency antara cache dan database
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KAPAN MENGGUNAKAN WRITE-THROUGH?                                     │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Data yang SERING DI-READ setelah WRITE (butuh immediately available)
 * ✓ Critical data yang HARUS selalu konsisten
 * ✓ Write operation tidak terlalu sering (acceptable latency tambahan)
 * ✓ Cache consistency > write performance
 *
 * CONTOH USE CASE:
 * - User profile (update → langsung tampil di cache untuk request berikutnya)
 * - Product catalog (update harga → langsung available tanpa cache miss)
 * - Configuration (update setting → langsung aktif untuk semua request)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KELEBIHAN                                                            │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Cache SELALU sinkron dengan database (strong consistency)
 * ✓ No cache miss setelah write (data langsung ready di cache)
 * ✓ Simplifikasi logic - tidak perlu pikir kapan invalidate cache
 * ✓ Predictable performance untuk read after write
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KEKURANGAN                                                           │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✗ Write latency lebih tinggi (write ke 2 tempat)
 * ✗ Tidak cocok untuk write-heavy workload
 * ✗ Bisa waste resource jika data jarang di-read setelah write
 * ✗ Kompleksitas tambahan untuk handle write failure
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
     * ═══════════════════════════════════════════════════════════════════
     * GET - Membaca data (SAMA seperti Cache-Aside)
     * ═══════════════════════════════════════════════════════════════════
     *
     * FLOW:
     * 1. Cek cache
     * 2. Cache HIT? → Return
     * 3. Cache MISS? → Query DB → Simpan ke cache → Return
     *
     * TIDAK ADA PERBEDAAN dengan Cache-Aside untuk READ!
     * Perbedaannya hanya di WRITE operation.
     *
     * @param string $key Cache key
     * @param callable $callback Data source jika cache miss
     * @return mixed Data dari cache atau database
     */
    public function get(string $key, callable $callback): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // Cek cache (dengan atau tanpa tags)
        if (!empty($this->cacheTags)) {
            $value = Cache::tags($this->cacheTags)->get($prefixedKey);
        } else {
            $value = Cache::get($prefixedKey);
        }

        // Cache HIT - return langsung
        if ($value !== null) {
            return $value;
        }

        // Cache MISS - fetch dari database
        $value = $callback();

        // Simpan ke cache untuk request berikutnya
        $this->storeInCache($prefixedKey, $value);

        return $value;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PUT - Write ke DATABASE dan CACHE secara BERSAMAAN
     * ═══════════════════════════════════════════════════════════════════
     *
     * INI ADALAH INTI dari Write-Through pattern!
     *
     * FLOW:
     * 1. Write ke DATABASE (via persist callback)
     * 2. LANGSUNG write ke CACHE dengan data yang sama
     * 3. Return success/failure
     *
     * CONTOH PENGGUNAAN:
     * ```php
     * $writeThrough->put('quiz:123', $updatedQuiz, function($quiz) {
     *     // Step 1: Update database
     *     Quiz::find(123)->update($quiz);
     * });
     * // Step 2: Cache otomatis ter-update di dalam method ini
     * // Request READ berikutnya langsung HIT (tidak perlu query DB!)
     * ```
     *
     * PERBANDINGAN DENGAN STRATEGI LAIN:
     *
     * CACHE-ASIDE:
     * ```php
     * Quiz::find(123)->update($data);      // Update DB
     * $cache->put('quiz:123', $data);       // Update cache (manual)
     * ```
     * Problem: Bisa lupa update cache!
     *
     * READ-THROUGH:
     * ```php
     * Quiz::find(123)->update($data);      // Update DB
     * $cache->forget('quiz:123');           // Hapus cache
     * // Request berikutnya MISS → Query DB lagi
     * ```
     * Problem: Request berikutnya lambat (cache miss)
     *
     * WRITE-THROUGH:
     * ```php
     * $writeThrough->put('quiz:123', $data, fn($d) => Quiz::update($d));
     * // DB updated + Cache updated BERSAMAAN
     * // Request berikutnya CEPAT (cache HIT)
     * ```
     * Advantage: Konsisten + Request berikutnya cepat!
     *
     * @param string $key Cache key
     * @param mixed $value Data yang akan disimpan
     * @param callable|null $persist Callback untuk write ke database
     * @return bool true jika berhasil (DB + Cache), false jika gagal
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        $prefixedKey = $this->getPrefixedKey($key);

        try {
            // STEP 1: Write ke DATABASE dulu
            // Ini penting - DB sebagai source of truth
            if ($persist !== null) {
                $persist($value);
            }

            // STEP 2: LANGSUNG write ke CACHE (synchronous)
            // Cache sekarang sinkron dengan database!
            $this->storeInCache($prefixedKey, $value);

            return true;
        } catch (\Exception $e) {
            // Jika salah satu operasi gagal, return false
            // Di production, ini harus di-log untuk monitoring
            return false;
        }
    }

    /**
     * FORGET - Hapus dari cache
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
     * ═══════════════════════════════════════════════════════════════════
     * STORE IN CACHE - Helper untuk simpan ke cache (dengan atau tanpa tags)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Method internal yang dipanggil oleh get() dan put()
     * untuk menyimpan data ke cache.
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
