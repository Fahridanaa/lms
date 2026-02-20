<?php

namespace App\Contracts;

/**
 * Cache Store Interface (Write-Through Pattern)
 *
 * Extends CacheLoader dengan kemampuan WRITE ke backing store.
 * Digunakan untuk Write-Through pattern di mana setiap write
 * ke cache juga di-persist ke database secara synchronous.
 *
 * KONSEP (Oracle Coherence):
 * - Store di-register ke strategy, bukan di-pass setiap call
 * - cache.put() otomatis memanggil store() untuk persist ke DB
 * - cache.forget() otomatis memanggil erase() untuk delete dari DB
 * - Store menentukan key mana yang bisa di-handle via supports()
 *
 * CONTOH IMPLEMENTASI:
 * ```php
 * class QuizCacheStore implements CacheStoreInterface
 * {
 *     public function supports(string $key): bool
 *     {
 *         return str_starts_with($key, 'quiz:');
 *     }
 *
 *     public function load(string $key): mixed
 *     {
 *         $id = (int) Str::afterLast($key, ':');
 *         return Quiz::find($id);
 *     }
 *
 *     public function store(string $key, mixed $value): void
 *     {
 *         $value->save();  // Eloquent model
 *     }
 *
 *     public function erase(string $key): void
 *     {
 *         $id = (int) Str::afterLast($key, ':');
 *         Quiz::destroy($id);
 *     }
 * }
 * ```
 *
 * PENGGUNAAN:
 * ```php
 * $cache = new WriteThroughStrategy([
 *     new QuizCacheStore($quizRepo),
 *     new UserCacheStore($userRepo),
 * ]);
 *
 * $cache->put('quiz:123', $quiz);  // QuizCacheStore handles DB write
 * $cache->put('user:456', $user);  // UserCacheStore handles DB write
 * ```
 *
 * PENTING - IDEMPOTENCY:
 * Semua operasi store/erase HARUS idempotent (bisa diulang tanpa side effect).
 */
interface CacheStoreInterface extends CacheLoaderInterface
{
    /**
     * Store single entry ke backing store
     *
     * Dipanggil otomatis oleh cache layer saat put().
     * HARUS idempotent - gunakan updateOrCreate/UPSERT atau model->save().
     *
     * @param string $key Cache key
     * @param mixed $value Data yang akan di-persist
     * @return void
     */
    public function store(string $key, mixed $value): void;

    /**
     * Erase single entry dari backing store
     *
     * Dipanggil otomatis oleh cache layer saat forget().
     *
     * @param string $key Cache key
     * @return void
     */
    public function erase(string $key): void;
}
