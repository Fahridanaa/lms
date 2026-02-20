<?php

namespace App\Providers;

use App\Contracts\CacheStrategyInterface;
use App\Services\Cache\CacheAsideStrategy;
use App\Services\Cache\Loaders\AssignmentCacheLoader;
use App\Services\Cache\Loaders\AttemptCacheLoader;
use App\Services\Cache\Loaders\CourseCacheLoader;
use App\Services\Cache\Loaders\MaterialCacheLoader;
use App\Services\Cache\Loaders\QuizCacheLoader;
use App\Services\Cache\Loaders\UserCacheLoader;
use App\Services\Cache\NoCacheStrategy;
use App\Services\Cache\ReadThroughStrategy;
use App\Services\Cache\Stores\AssignmentCacheStore;
use App\Services\Cache\Stores\AttemptCacheStore;
use App\Services\Cache\Stores\CourseCacheStore;
use App\Services\Cache\Stores\MaterialCacheStore;
use App\Services\Cache\Stores\QuizCacheStore;
use App\Services\Cache\Stores\UserCacheStore;
use App\Services\Cache\WriteThroughStrategy;
use Illuminate\Support\ServiceProvider;

/**
 * Cache Strategy Service Provider
 *
 * Registers the caching strategy interface binding based on configuration.
 * Allows runtime switching between Cache-Aside, Read-Through, Write-Through, and No-Cache strategies.
 *
 * Strategy Comparison:
 * ┌─────────────────┬────────────────────┬────────────────────────────────┐
 * │ Strategy        │ Data Source        │ Behavior                       │
 * ├─────────────────┼────────────────────┼────────────────────────────────┤
 * │ Cache-Aside     │ Callback           │ Manual cache management        │
 * │ Read-Through    │ Loaders            │ Auto-load on miss, invalidate  │
 * │ Write-Through   │ Stores             │ Sync write to DB + cache       │
 * │ No-Cache        │ Callback           │ Always hit database            │
 * └─────────────────┴────────────────────┴────────────────────────────────┘
 */
class CacheStrategyServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        $this->app->singleton(CacheStrategyInterface::class, function ($app) {
            $strategy = config('caching-strategy.driver', 'cache-aside');

            return match ($strategy) {
                'cache-aside' => $app->make(CacheAsideStrategy::class),
                'read-through' => $this->createReadThroughStrategy($app),
                'write-through' => $this->createWriteThroughStrategy($app),
                'no-cache' => $app->make(NoCacheStrategy::class),
                default => throw new \InvalidArgumentException("Unknown caching strategy: {$strategy}"),
            };
        });
    }

    /**
     * Create Read-Through strategy with all loaders registered
     *
     * Loaders handle automatic data fetching from database on cache miss.
     * No callbacks needed - just call $cache->get('quiz:123')
     */
    protected function createReadThroughStrategy($app): ReadThroughStrategy
    {
        return new ReadThroughStrategy([
            $app->make(QuizCacheLoader::class),
            $app->make(MaterialCacheLoader::class),
            $app->make(AssignmentCacheLoader::class),
            $app->make(AttemptCacheLoader::class),
            $app->make(CourseCacheLoader::class),
            $app->make(UserCacheLoader::class),
        ]);
    }

    /**
     * Create Write-Through strategy with all stores registered
     *
     * Stores handle:
     * - load(): Fetch from database on cache miss
     * - store(): Persist to database + cache synchronously
     * - erase(): Delete from database + cache
     */
    protected function createWriteThroughStrategy($app): WriteThroughStrategy
    {
        return new WriteThroughStrategy([
            $app->make(QuizCacheStore::class),
            $app->make(MaterialCacheStore::class),
            $app->make(AssignmentCacheStore::class),
            $app->make(AttemptCacheStore::class),
            $app->make(CourseCacheStore::class),
            $app->make(UserCacheStore::class),
        ]);
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        //
    }
}
