<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Caching Strategy Driver
    |--------------------------------------------------------------------------
    |
    | This option controls which caching strategy will be used by the application.
    | You can switch between different strategies without changing code.
    |
    | Supported: "cache-aside", "read-through", "write-through"
    |
    */

    'driver' => env('CACHE_STRATEGY', 'cache-aside'),

    /*
    |--------------------------------------------------------------------------
    | Cache Time-To-Live (TTL)
    |--------------------------------------------------------------------------
    |
    | This value determines how long (in seconds) cached items should be stored
    | before they expire. Default is 3600 seconds (1 hour).
    |
    */

    'ttl' => env('CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be prepended to all cache keys to avoid collisions
    | with other applications using the same cache store.
    |
    */

    'prefix' => env('CACHE_PREFIX', 'lms'),

    /*
    |--------------------------------------------------------------------------
    | Strategy-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Additional configuration options for each caching strategy.
    |
    */

    'strategies' => [
        'cache-aside' => [
            'enabled' => true,
            'description' => 'Application explicitly manages cache (lazy loading)',
        ],

        'read-through' => [
            'enabled' => true,
            'description' => 'Cache layer handles DB reads transparently',
        ],

        'write-through' => [
            'enabled' => true,
            'description' => 'Synchronous write to both cache and database',
        ],
    ],

];
