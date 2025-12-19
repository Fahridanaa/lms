<?php

namespace App\Services\Cache;

use App\Contracts\CacheStrategyInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * STRATEGI NO-CACHE (BASELINE/CONTROL GROUP)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KONSEP DASAR:
 * Strategi ini TIDAK menggunakan cache sama sekali - semua operasi langsung ke database.
 * Digunakan sebagai BASELINE (grup kontrol) untuk mengukur dampak caching.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ MENGAPA NO-CACHE DIPERLUKAN?                                         │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Dalam penelitian/benchmark caching, kita perlu tahu:
 * "Seberapa besar improvement yang diberikan oleh caching?"
 *
 * Untuk menjawabnya, kita perlu BASELINE (performa TANPA cache).
 * No-Cache Strategy = Kondisi aplikasi tanpa optimasi caching.
 *
 * ANALOGI:
 * Seperti melakukan tes obat:
 * - Group A: Diberi obat (Cache-Aside, Read-Through, Write-Through)
 * - Group B: Diberi plasebo (No-Cache)
 * - Bandingkan hasilnya → Berapa efektif obatnya?
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ PERBEDAAN DENGAN STRATEGI LAIN                                       │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * CACHE-ASIDE:
 *   App → Cek Cache → Miss? → Query DB → Simpan Cache → Return
 *   (Menggunakan cache untuk percepatan)
 *
 * READ-THROUGH:
 *   App → Cache Layer → Cache query DB jika miss → Return
 *   (Cache layer yang handle transparently)
 *
 * WRITE-THROUGH:
 *   App → Write DB + Cache bersamaan → Return
 *   (Synchronous write ke 2 tempat)
 *
 * NO-CACHE:
 *   App → Query DB langsung → Return
 *   (TIDAK ADA cache sama sekali - direct database access!)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI READ (Membaca Data)                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   SANGAT SEDERHANA - tidak ada caching logic:
 *
 *   1. Aplikasi panggil get()
 *      ↓
 *   2. Eksekusi callback (query database)
 *      ↓
 *   3. Return data
 *
 *   TIDAK ADA:
 *   - Cache check
 *   - Cache hit/miss
 *   - Cache store
 *
 *   Kode:
 *   $data = $noCache->get('user:1', function() {
 *       return DB::find(1);  // SELALU dieksekusi, tidak pernah dari cache
 *   });
 *
 *   Request pertama: Query DB → Return
 *   Request kedua: Query DB lagi → Return
 *   Request ketiga: Query DB lagi → Return
 *   (Setiap request = database query baru!)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI WRITE (Update Data)                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   HANYA write ke database, TIDAK ADA operasi cache:
 *
 *   1. Aplikasi panggil put()
 *      ↓
 *   2. Eksekusi persist callback (update database)
 *      ↓
 *   3. Return success
 *
 *   TIDAK ADA:
 *   - Cache update
 *   - Cache invalidation
 *   - Cache synchronization
 *
 *   Kode:
 *   $noCache->put('user:1', $newData, function($data) {
 *       DB::update($data);  // Update DB saja
 *   });
 *   // TIDAK ADA cache operation sama sekali
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ DIAGRAM KOMPARASI PERFORMA                                           │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   DENGAN CACHE (Cache-Aside):
 *   Request 1: DB query (300ms) → Cache store
 *   Request 2: Cache hit (5ms) ← CEPAT!
 *   Request 3: Cache hit (5ms) ← CEPAT!
 *   Request 4: Cache hit (5ms) ← CEPAT!
 *   Average: ~79ms
 *
 *   TANPA CACHE (No-Cache):
 *   Request 1: DB query (300ms)
 *   Request 2: DB query (300ms) ← LAMBAT!
 *   Request 3: DB query (300ms) ← LAMBAT!
 *   Request 4: DB query (300ms) ← LAMBAT!
 *   Average: 300ms
 *
 *   IMPROVEMENT = (300 - 79) / 300 × 100% = ~74% faster with cache!
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KARAKTERISTIK UTAMA                                                  │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ TIDAK ADA cache operation sama sekali
 * ✓ Semua request langsung ke database
 * ✓ Tidak ada cache hit/miss
 * ✓ Performa terendah (karena selalu query DB)
 * ✓ Stateless - tidak ada state yang disimpan
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KAPAN MENGGUNAKAN NO-CACHE?                                          │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Benchmarking - mengukur dampak cache
 * ✓ Testing - validasi logic tanpa kompleksitas cache
 * ✓ Research - sebagai control group
 * ✓ Debugging - isolasi masalah apakah dari cache atau DB
 *
 * CONTOH USE CASE PENELITIAN:
 * - Mengukur response time tanpa cache vs dengan cache
 * - Mengukur throughput (requests/sec) baseline
 * - Mengukur CPU/Memory usage tanpa cache overhead
 * - Validasi bahwa peningkatan performa benar-benar dari cache
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KELEBIHAN (UNTUK BENCHMARKING)                                       │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Baseline yang jelas untuk comparison
 * ✓ Tidak ada overhead dari cache layer
 * ✓ Sederhana dan mudah dipahami
 * ✓ Tidak ada cache consistency issues
 * ✓ Performa paling predictable (selalu query DB)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KEKURANGAN (UNTUK PRODUCTION)                                        │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✗ Performa SANGAT LAMBAT (setiap request = DB query)
 * ✗ Database load tinggi (tidak ada caching relief)
 * ✗ Tidak scalable untuk high traffic
 * ✗ Resource intensive (CPU, memory, network)
 * ✗ TIDAK DIREKOMENDASIKAN untuk production!
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ CONTOH PENGGUNAAN DALAM BENCHMARK                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Scenario 1: READ-HEAVY workload (80% read, 20% write)
 *
 * NO-CACHE:
 * - Average response time: 350ms
 * - Throughput: 50 req/s
 * - Database CPU: 85%
 *
 * CACHE-ASIDE:
 * - Average response time: 45ms (87% improvement!)
 * - Throughput: 380 req/s (660% improvement!)
 * - Database CPU: 15% (70% reduction!)
 *
 * Scenario 2: WRITE-HEAVY workload (40% read, 60% write)
 *
 * NO-CACHE:
 * - Average response time: 400ms
 * - Throughput: 45 req/s
 * - Database CPU: 90%
 *
 * WRITE-THROUGH:
 * - Average response time: 180ms (55% improvement)
 * - Throughput: 95 req/s (111% improvement)
 * - Database CPU: 75% (17% reduction)
 *
 * KESIMPULAN: Cache memberikan improvement signifikan!
 */
class NoCacheStrategy implements CacheStrategyInterface
{
    /**
     * Tag cache (tidak digunakan, hanya untuk kompatibilitas interface)
     *
     * No-Cache tidak menggunakan cache, jadi tags tidak dipakai.
     * Property ini ada supaya class tetap compatible dengan interface.
     *
     * @var array
     */
    protected array $cacheTags = [];

    /**
     * ═══════════════════════════════════════════════════════════════════
     * GET - Langsung eksekusi callback (TIDAK pakai cache)
     * ═══════════════════════════════════════════════════════════════════
     *
     * INI ADALAH INTI dari No-Cache pattern!
     * Callback SELALU dieksekusi - tidak ada cache check sama sekali.
     *
     * FLOW:
     * 1. Eksekusi callback (query database)
     * 2. Return data
     *
     * TIDAK ADA:
     * - Cache::get() check
     * - Cache hit/miss detection
     * - Cache::put() store
     *
     * CONTOH PENGGUNAAN:
     * ```php
     * $quiz = $noCache->get('quiz:123', function() {
     *     return Quiz::find(123);  // Dieksekusi SETIAP kali get() dipanggil
     * });
     * ```
     *
     * PERBANDINGAN DENGAN STRATEGI LAIN:
     *
     * CACHE-ASIDE get():
     * ```php
     * if ($cached = Cache::get('key')) return $cached; // Check cache first
     * $data = callback();
     * Cache::put('key', $data);
     * return $data;
     * ```
     *
     * READ-THROUGH get():
     * ```php
     * return Cache::remember('key', $ttl, $callback); // Cache handles it
     * ```
     *
     * NO-CACHE get():
     * ```php
     * return $callback(); // Always execute, never cache!
     * ```
     *
     * PERBEDAAN UTAMA:
     * - No-Cache: Parameter $key DIABAIKAN (tidak digunakan sama sekali)
     * - Cache strategies: Parameter $key DIGUNAKAN untuk lookup dan store
     *
     * @param string $key Cache key (DIABAIKAN - tidak digunakan)
     * @param callable $callback Fungsi yang akan SELALU dieksekusi
     * @return mixed Data hasil eksekusi callback (langsung dari database)
     */
    public function get(string $key, callable $callback): mixed
    {
        // SELALU eksekusi callback - TIDAK ADA cache lookup
        // Setiap pemanggilan get() = database query baru
        return $callback();
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PUT - Hanya update database (TIDAK ada operasi cache)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Method ini HANYA eksekusi persist callback untuk update database.
     * TIDAK ADA operasi cache sama sekali (tidak store, tidak invalidate).
     *
     * FLOW:
     * 1. Eksekusi persist callback (jika ada)
     * 2. Return true
     *
     * TIDAK ADA:
     * - Cache::put() untuk update cache
     * - Cache::forget() untuk invalidate cache
     * - Cache synchronization
     *
     * CONTOH PENGGUNAAN:
     * ```php
     * // Update database saja, tidak ada cache operation
     * $noCache->put('quiz:123', $updatedQuiz, function($quiz) {
     *     Quiz::find(123)->update($quiz);
     * });
     * // TIDAK ADA Cache::put() atau Cache::forget()
     * ```
     *
     * PERBANDINGAN DENGAN STRATEGI LAIN:
     *
     * CACHE-ASIDE put():
     * ```php
     * Cache::put($key, $value, $ttl); // Update cache
     * // Aplikasi handle DB update terpisah
     * ```
     *
     * READ-THROUGH put():
     * ```php
     * $persist($value);         // Update DB
     * Cache::forget($key);      // INVALIDATE cache
     * ```
     *
     * WRITE-THROUGH put():
     * ```php
     * $persist($value);         // Update DB
     * Cache::put($key, $value); // Update cache BERSAMAAN
     * ```
     *
     * NO-CACHE put():
     * ```php
     * $persist($value);         // Update DB saja
     * // TIDAK ADA cache operation!
     * ```
     *
     * CATATAN PENTING:
     * - Parameter $key DIABAIKAN (tidak digunakan)
     * - Parameter $value hanya dipass ke persist callback
     * - Tidak ada state yang tersimpan
     * - Selalu return true (karena tidak ada cache operation yang bisa gagal)
     *
     * @param string $key Cache key (DIABAIKAN - tidak digunakan)
     * @param mixed $value Nilai untuk dipass ke persist callback
     * @param callable|null $persist Callback untuk update database
     * @return bool Selalu true (tidak ada cache operation yang bisa gagal)
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool
    {
        // Jika persist callback disediakan, eksekusi untuk update database
        if ($persist !== null) {
            $persist($value);
        }

        // Selalu return true - tidak ada cache operation yang bisa gagal
        return true;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * FORGET - No-op (tidak ada cache untuk dihapus)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Method ini tidak melakukan apa-apa karena tidak ada cache.
     * Selalu return true untuk kompatibilitas interface.
     *
     * CONTOH:
     * ```php
     * $noCache->forget('quiz:123'); // Tidak melakukan apa-apa
     * // Return: true (always successful, nothing to forget)
     * ```
     *
     * PERBANDINGAN:
     * - Cache-Aside: Cache::forget($key) → Hapus dari cache
     * - Read-Through: Cache::forget($key) → Invalidate cache
     * - Write-Through: Cache::forget($key) → Hapus dari cache
     * - No-Cache: (tidak melakukan apa-apa, tidak ada cache)
     *
     * @param string $key Cache key (DIABAIKAN)
     * @return bool Selalu true
     */
    public function forget(string $key): bool
    {
        // Tidak ada cache untuk dihapus - selalu berhasil
        return true;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * REMEMBER - Sama dengan get() (tidak pakai cache)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Untuk No-Cache, remember() dan get() identik - keduanya selalu
     * eksekusi callback tanpa cache.
     *
     * CONTOH:
     * ```php
     * $quiz = $noCache->remember('quiz:123', function() {
     *     return Quiz::find(123);
     * });
     * // Sama persis dengan get() - langsung eksekusi callback
     * ```
     *
     * PERBANDINGAN:
     * - Cache-Aside: remember() = get() (cek cache → miss? → callback → store)
     * - Read-Through: remember() = get() = Cache::remember()
     * - Write-Through: remember() = get() (sama dengan Read-Through)
     * - No-Cache: remember() = get() = callback() (tidak ada bedanya)
     *
     * @param string $key Cache key (DIABAIKAN)
     * @param callable $callback Fungsi yang akan SELALU dieksekusi
     * @return mixed Data hasil eksekusi callback
     */
    public function remember(string $key, callable $callback): mixed
    {
        // Selalu eksekusi callback - tidak ada caching
        return $callback();
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * TAGS - Set tag untuk cache (tidak digunakan di No-Cache)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Method ini hanya untuk kompatibilitas dengan interface.
     * Tags disimpan tapi tidak dipakai karena tidak ada cache.
     *
     * CONTOH:
     * ```php
     * $noCache->tags(['quizzes'])->get('quiz:123', $callback);
     * // Tags diabaikan, langsung eksekusi callback
     * ```
     *
     * PERBANDINGAN:
     * - Cache-Aside: Cache::tags($tags)->get($key)
     * - Read-Through: Cache::tags($tags)->remember($key, $ttl, $callback)
     * - Write-Through: Cache::tags($tags)->put($key, $value)
     * - No-Cache: Tags disimpan tapi tidak digunakan
     *
     * @param array $tags Array nama tag (DIABAIKAN)
     * @return self Instance untuk method chaining
     */
    public function tags(array $tags): self
    {
        // Simpan tags tapi tidak digunakan
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * FLUSH TAGS - No-op (tidak ada cache untuk di-flush)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Method ini tidak melakukan apa-apa karena tidak ada cache.
     * Selalu return true untuk kompatibilitas interface.
     *
     * CONTOH:
     * ```php
     * $noCache->flushTags(['quizzes']); // Tidak melakukan apa-apa
     * // Return: true (always successful, nothing to flush)
     * ```
     *
     * PERBANDINGAN:
     * - Cache-Aside: Cache::tags($tags)->flush() → Hapus semua cache dengan tag
     * - Read-Through: Cache::tags($tags)->flush() → Invalidate semua
     * - Write-Through: Cache::tags($tags)->flush() → Hapus semua cache dengan tag
     * - No-Cache: (tidak melakukan apa-apa, tidak ada cache)
     *
     * USE CASE (strategi lain):
     * ```php
     * // Invalidate semua quiz setelah ada perubahan data
     * $strategy->flushTags(['quizzes']);
     * ```
     *
     * @param array $tags Array nama tag (DIABAIKAN)
     * @return bool Selalu true
     */
    public function flushTags(array $tags): bool
    {
        // Tidak ada cache untuk di-flush - selalu berhasil
        return true;
    }
}
