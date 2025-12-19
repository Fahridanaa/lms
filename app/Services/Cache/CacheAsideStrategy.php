<?php

namespace App\Services\Cache;

use App\Contracts\CacheStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * STRATEGI CACHE-ASIDE (LAZY LOADING)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KONSEP DASAR:
 * Aplikasi yang bertanggung jawab penuh untuk mengelola cache secara eksplisit.
 * Cache hanya menyimpan data, tidak tahu apa-apa tentang database.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI READ (Membaca Data)                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   1. Aplikasi cek cache dulu
 *      ↓
 *   2. Cache HIT (ada)?
 *      ├─ YES → Return data dari cache (SELESAI) ✓
 *      └─ NO  → Lanjut ke step 3
 *             ↓
 *   3. Cache MISS → Aplikasi query database
 *      ↓
 *   4. Aplikasi simpan hasil query ke cache
 *      ↓
 *   5. Return data ke user
 *
 *   Kode:
 *   $data = Cache::get('user:1');        // Step 1-2
 *   if (!$data) {                         // Step 2 (NO)
 *       $data = DB::find(1);              // Step 3
 *       Cache::put('user:1', $data);      // Step 4
 *   }
 *   return $data;                         // Step 5
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ FLOW OPERASI WRITE (Update Data)                                    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 *   1. Aplikasi update database dulu
 *      ↓
 *   2. Aplikasi invalidate (hapus) cache
 *      atau
 *   2. Aplikasi update cache dengan data baru
 *
 *   Kode (Opsi 1 - Invalidate):
 *   DB::update(['name' => 'New']);       // Step 1
 *   Cache::forget('user:1');              // Step 2 - Hapus cache
 *
 *   Kode (Opsi 2 - Update):
 *   $user = DB::update(['name' => 'New']);  // Step 1
 *   Cache::put('user:1', $user);             // Step 2 - Update cache
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KARAKTERISTIK UTAMA                                                  │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Aplikasi yang KONTROL penuh atas cache
 * ✓ Cache hanya sebagai penyimpanan pasif (dumb storage)
 * ✓ Lazy loading - data di-cache hanya ketika di-request pertama kali
 * ✓ Cache miss = query database (bisa lambat di request pertama)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KAPAN MENGGUNAKAN CACHE-ASIDE?                                       │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Read-heavy workload (banyak baca, jarang write)
 * ✓ Data yang jarang berubah
 * ✓ Acceptable jika request pertama agak lambat (cache miss)
 * ✓ Butuh kontrol penuh kapan data di-cache
 *
 * CONTOH USE CASE:
 * - Daftar kursus (jarang berubah, sering dibaca)
 * - Profile user (jarang update, sering dilihat)
 * - Static content (kategori, tags, dll)
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KELEBIHAN                                                            │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✓ Sederhana dan mudah dipahami
 * ✓ Cache failure tidak break aplikasi (fallback ke DB)
 * ✓ Hanya data yang benar-benar dibutuhkan yang di-cache
 * ✓ Cocok untuk read-heavy workload
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ KEKURANGAN                                                           │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ✗ Cache miss penalty - request pertama lambat
 * ✗ Aplikasi harus manage cache secara manual
 * ✗ Bisa terjadi cache inconsistency jika lupa invalidate
 * ✗ Write operation perlu extra logic untuk update cache
 */
class CacheAsideStrategy implements CacheStrategyInterface
{
    /**
     * Tag cache untuk operasi berkelompok
     * Contoh: ['users', 'user:1'] untuk invalidate semua cache terkait user
     */
    protected array $cacheTags = [];

    /**
     * Time To Live - berapa lama data disimpan di cache (dalam detik)
     * Default: 3600 detik (1 jam)
     */
    protected int $ttl;

    /**
     * Prefix untuk semua cache key
     * Contoh: 'lms' → cache key jadi 'lms:user:1'
     * Berguna untuk menghindari collision dengan aplikasi lain
     */
    protected string $prefix;

    /**
     * Constructor - Load konfigurasi dari config/caching-strategy.php
     */
    public function __construct()
    {
        $this->ttl = config('caching-strategy.ttl', 3600);
        $this->prefix = config('caching-strategy.prefix', 'lms');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * GET - Mengambil data dari cache atau database
     * ═══════════════════════════════════════════════════════════════════
     *
     * FLOW:
     * 1. Cek cache dengan key yang diberikan
     * 2. CACHE HIT? → Return data dari cache ✓
     * 3. CACHE MISS? → Eksekusi callback (query DB)
     * 4. Simpan hasil ke cache untuk request berikutnya
     * 5. Return data
     *
     * CONTOH PENGGUNAAN:
     * ```php
     * $quiz = $cacheStrategy->get('quiz:123', function() {
     *     return DB::table('quizzes')->find(123);
     * });
     * ```
     *
     * Request pertama: Cache MISS → Query DB → Simpan ke cache → Return
     * Request kedua: Cache HIT → Return langsung dari cache (CEPAT!)
     *
     * @param string $key Cache key (contoh: 'quiz:123')
     * @param callable $callback Function untuk fetch data dari DB jika cache miss
     * @return mixed Data dari cache atau database
     */
    public function get(string $key, callable $callback): mixed
    {
        $prefixedKey = $this->getPrefixedKey($key);

        // STEP 1-2: Cek cache (dengan atau tanpa tags)
        if (!empty($this->cacheTags)) {
            $value = Cache::tags($this->cacheTags)->get($prefixedKey);
        } else {
            $value = Cache::get($prefixedKey);
        }

        // STEP 2: CACHE HIT - langsung return
        if ($value !== null) {
            return $value;
        }

        // STEP 3: CACHE MISS - eksekusi callback untuk fetch dari database
        $value = $callback();

        // STEP 4: Simpan ke cache untuk request berikutnya
        $this->put($key, $value);

        // STEP 5: Return data
        return $value;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * PUT - Menyimpan data ke cache
     * ═══════════════════════════════════════════════════════════════════
     *
     * PENTING: Di Cache-Aside, method ini HANYA update cache.
     * Aplikasi yang bertanggung jawab untuk update database secara terpisah!
     *
     * FLOW:
     * 1. Aplikasi sudah update DB (di luar method ini)
     * 2. Method ini hanya simpan/update value di cache
     *
     * CONTOH PENGGUNAAN (Yang BENAR):
     * ```php
     * // Step 1: Update database dulu
     * $quiz = Quiz::find(123);
     * $quiz->update(['title' => 'New Title']);
     *
     * // Step 2: Update cache
     * $cacheStrategy->put('quiz:123', $quiz);
     * ```
     *
     * ATAU bisa juga invalidate (hapus cache):
     * ```php
     * $quiz->update(['title' => 'New Title']);
     * $cacheStrategy->forget('quiz:123');  // Hapus cache
     * // Request berikutnya akan cache miss → query DB → dapat data terbaru
     * ```
     *
     * CATATAN: Parameter $persist diabaikan di Cache-Aside karena
     * aplikasi yang manage DB write secara eksplisit.
     *
     * @param string $key Cache key
     * @param mixed $value Nilai yang akan disimpan di cache
     * @param callable|null $persist DIABAIKAN di Cache-Aside
     * @return bool true jika berhasil
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
     * ═══════════════════════════════════════════════════════════════════
     * FORGET - Menghapus data dari cache (Invalidation)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Digunakan untuk invalidate cache setelah data di database berubah.
     *
     * CONTOH:
     * ```php
     * // Update database
     * Quiz::find(123)->update(['title' => 'New']);
     *
     * // Invalidate cache
     * $cacheStrategy->forget('quiz:123');
     *
     * // Request berikutnya akan ambil data fresh dari database
     * ```
     *
     * @param string $key Cache key yang akan dihapus
     * @return bool true jika berhasil
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
     * REMEMBER - Alias untuk get()
     * Perilaku sama persis dengan get()
     */
    public function remember(string $key, callable $callback): mixed
    {
        return $this->get($key, $callback);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     * TAGS - Set tag untuk operasi cache berkelompok
     * ═══════════════════════════════════════════════════════════════════
     *
     * Tags berguna untuk invalidate banyak cache sekaligus.
     *
     * CONTOH:
     * ```php
     * // Simpan dengan tag
     * $strategy->tags(['users', 'user:1'])->put('profile:1', $user);
     * $strategy->tags(['users', 'user:2'])->put('profile:2', $user2);
     *
     * // Invalidate semua cache dengan tag 'users'
     * $strategy->flushTags(['users']);
     * // Kedua profile:1 dan profile:2 terhapus!
     * ```
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
     *
     * @param array $tags Array nama tag yang akan di-flush
     * @return bool true jika berhasil
     */
    public function flushTags(array $tags): bool
    {
        return Cache::tags($tags)->flush();
    }

    /**
     * Menambahkan prefix ke cache key
     * Contoh: 'quiz:123' → 'lms:quiz:123'
     */
    protected function getPrefixedKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
