<?php

namespace App\Providers;

use App\Contracts\CacheStrategyInterface;
use App\Services\Cache\CacheAsideStrategy;
use Illuminate\Support\ServiceProvider;

/**
 * Cache Strategy Service Provider
 *
 * Registers the caching strategy interface binding based on configuration.
 * Allows runtime switching between Cache-Aside, Read-Through, and Write-Through strategies.
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
                // Will add read-through and write-through implementations later
                // 'read-through' => $app->make(ReadThroughStrategy::class),
                // 'write-through' => $app->make(WriteThroughStrategy::class),
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
