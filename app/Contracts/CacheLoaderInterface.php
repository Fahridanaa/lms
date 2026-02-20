<?php

namespace App\Contracts;

/**
 * Cache Loader Interface (Read-Through Pattern)
 *
 * Adapter yang bertanggung jawab untuk LOAD data dari backing store (database)
 * ketika terjadi cache miss. Cache layer akan memanggil loader secara otomatis.
 *
 * KONSEP (Oracle Coherence):
 * - Loader di-register ke strategy, bukan di-pass setiap call
 * - Cache memanggil load() secara transparan saat miss
 * - Loader menentukan key mana yang bisa di-handle via supports()
 *
 * CONTOH IMPLEMENTASI:
 * ```php
 * class QuizCacheLoader implements CacheLoaderInterface
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
 * }
 * ```
 *
 * PENGGUNAAN:
 * ```php
 * $cache = new ReadThroughStrategy([
 *     new QuizCacheLoader($quizRepo),
 *     new UserCacheLoader($userRepo),
 * ]);
 *
 * $cache->get('quiz:123');  // QuizCacheLoader handles this
 * $cache->get('user:456');  // UserCacheLoader handles this
 * ```
 */
interface CacheLoaderInterface
{
    /**
     * Check apakah loader ini bisa handle key tertentu
     *
     * @param string $key Cache key (e.g., "quiz:123", "user:456")
     * @return bool true jika loader ini bisa handle key tersebut
     */
    public function supports(string $key): bool;

    /**
     * Load single entry dari backing store
     *
     * Dipanggil otomatis oleh cache layer ketika cache miss.
     * Implementasi harus bisa parse key untuk query database.
     *
     * @param string $key Cache key (e.g., "quiz:123", "user:456")
     * @return mixed Data dari backing store, atau null jika tidak ditemukan
     */
    public function load(string $key): mixed;
}
