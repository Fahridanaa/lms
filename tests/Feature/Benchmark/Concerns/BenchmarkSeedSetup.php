<?php

namespace Tests\Feature\Benchmark\Concerns;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

trait BenchmarkSeedSetup
{
    private static bool $benchmarkSeeded = false;

    /**
     * Flush cache and seed the benchmark dataset once per test class.
     */
    protected function setUpBenchmarkSeed(bool $migrateFresh = false): void
    {
        Cache::flush();

        if (self::$benchmarkSeeded) {
            return;
        }

        if ($migrateFresh) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        $this->seed(DatabaseSeeder::class);
        self::$benchmarkSeeded = true;
    }
}
