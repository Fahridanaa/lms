<?php

namespace App\Providers;

use App\Contracts\CacheStrategyInterface;
use App\Services\Cache\CacheAsideStrategy;
use App\Services\Cache\NoCacheStrategy;
use App\Services\Cache\ReadThroughStrategy;
use App\Services\Cache\WriteThroughStrategy;
use Illuminate\Support\ServiceProvider;

/**
 * Cache Strategy Service Provider
 *
 * Registers the caching strategy interface binding based on configuration.
 * Allows runtime switching between Cache-Aside, Read-Through, Write-Through, and No-Cache strategies.
 */
class CacheStrategyServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(CacheStrategyInterface::class, function ($app) {
            $strategy = config('caching-strategy.driver', 'cache-aside');

            return match ($strategy) {
                'cache-aside' => $app->make(CacheAsideStrategy::class),
                'read-through' => $app->make(ReadThroughStrategy::class),
                'write-through' => $app->make(WriteThroughStrategy::class),
                'no-cache' => $app->make(NoCacheStrategy::class),
                default => throw new \InvalidArgumentException("Unknown caching strategy: {$strategy}"),
            };
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
