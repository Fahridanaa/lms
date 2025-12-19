<?php

namespace App\Contracts;

/**
 * Interface Strategi Caching
 *
 * Mendefinisikan kontrak untuk semua strategi caching:
 * - Cache-Aside (Lazy Loading)
 * - Read-Through
 * - Write-Through
 * - No-Cache (Baseline)
 *
 * Interface ini memastikan API yang konsisten di semua implementasi caching.
 */
interface CacheStrategyInterface
{
    /**
     * Mengambil data dari cache atau eksekusi callback untuk fetch data
     *
     * Perilaku berbeda per strategi:
     * - Cache-Aside: Cek cache → miss? → eksekusi callback → simpan ke cache → return
     * - Read-Through: Cache::remember() (cache handle semuanya secara transparan)
     * - Write-Through: Sama seperti Cache-Aside untuk READ operations
     * - No-Cache: Langsung eksekusi callback (bypass cache)
     *
     * @param string $key Kunci cache
     * @param callable $callback Fungsi yang akan dieksekusi jika cache miss
     * @return mixed Data dari cache atau hasil callback
     */
    public function get(string $key, callable $callback): mixed;

    /**
     * Menyimpan data ke cache (dan database jika ada persist callback)
     *
     * Perilaku berbeda per strategi:
     * - Cache-Aside: Update cache saja (aplikasi handle DB write terpisah)
     * - Read-Through: INVALIDATE cache (hapus, bukan update)
     * - Write-Through: Write ke DB + Cache secara bersamaan (synchronous)
     * - No-Cache: Hanya eksekusi persist callback (tidak ada cache operation)
     *
     * @param string $key Kunci cache
     * @param mixed $value Nilai yang akan di-cache
     * @param callable|null $persist Callback opsional untuk persist ke database
     * @return bool Status keberhasilan
     */
    public function put(string $key, mixed $value, ?callable $persist = null): bool;

    /**
     * Menghapus item dari cache
     *
     * @param string $key Kunci cache yang akan dihapus
     * @return bool Status keberhasilan
     */
    public function forget(string $key): bool;

    /**
     * Ambil dari cache atau eksekusi callback dan simpan hasilnya
     *
     * Shorthand untuk get() - biasanya implementasinya sama dengan get()
     *
     * @param string $key Kunci cache
     * @param callable $callback Fungsi yang akan dieksekusi jika cache miss
     * @return mixed Data dari cache atau hasil callback
     */
    public function remember(string $key, callable $callback): mixed;

    /**
     * Tag cache entries untuk operasi berkelompok
     *
     * Contoh: $strategy->tags(['users', 'posts'])->get('key', $callback)
     * Berguna untuk invalidasi cache secara berkelompok
     *
     * @param array $tags Array nama tag
     * @return self Returns instance untuk method chaining
     */
    public function tags(array $tags): self;

    /**
     * Flush semua entries dengan tag tertentu
     *
     * Contoh: $strategy->flushTags(['users'])
     * Akan menghapus semua cache yang di-tag dengan 'users'
     *
     * @param array $tags Array nama tag yang akan di-flush
     * @return bool Status keberhasilan
     */
    public function flushTags(array $tags): bool;
}
