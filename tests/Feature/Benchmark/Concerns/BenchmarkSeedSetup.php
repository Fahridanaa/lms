<?php

namespace Tests\Feature\Benchmark\Concerns;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;

trait BenchmarkSeedSetup
{
    /**
     * Flush cache and seed the benchmark dataset.
     *
     * Call from setUp() after parent::setUp().
     *
     * Unlike BenchmarkFixtureSetup, this does NOT generate k6 fixture data.
     * It is intended for tests that only need to validate seeded database state.
     */
    protected function setUpBenchmarkSeed(): void
    {
        Cache::flush();

        // Explicitly seed every time a benchmark seed test runs.
        // This ensures the database has the full deterministic benchmark
        // dataset regardless of whether another RefreshDatabase test class
        // migrated the database first in the same PHPUnit process.
        $this->seed(DatabaseSeeder::class);
    }
}
