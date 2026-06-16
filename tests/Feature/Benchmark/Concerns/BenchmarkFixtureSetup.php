<?php

namespace Tests\Feature\Benchmark\Concerns;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

trait BenchmarkFixtureSetup
{
    protected string $generatedFixturePath = '';

    protected string $fixturesContent = '';

    /**
     * Flush cache, seed the benchmark dataset, and generate k6 fixture data.
     *
     * Call from setUp() after parent::setUp().
     */
    protected function setUpBenchmarkFixtures(): void
    {
        Cache::flush();

        // Explicitly seed every time a benchmark fixture test runs.
        // This ensures the database has the full deterministic benchmark
        // dataset regardless of whether another RefreshDatabase test class
        // migrated the database first in the same PHPUnit process.
        $this->seed(DatabaseSeeder::class);

        $outputPath = tempnam(sys_get_temp_dir(), 'k6-fixtures-');
        $exitCode = Artisan::call('benchmark:generate-k6-fixtures', ['--output' => $outputPath]);

        if ($exitCode !== 0) {
            $error = Artisan::output();
            @unlink($outputPath);
            $this->fail(
                'benchmark:generate-k6-fixtures exited with code '.$exitCode
                .' and output: '.substr($error, 0, 500)
            );
        }

        $this->fixturesContent = file_get_contents($outputPath);
        $this->generatedFixturePath = $outputPath;
    }

    /**
     * Clean up the generated fixture file.
     *
     * Call from tearDown() before parent::tearDown().
     */
    protected function tearDownBenchmarkFixtures(): void
    {
        if ($this->generatedFixturePath && file_exists($this->generatedFixturePath)) {
            @unlink($this->generatedFixturePath);
        }
        $this->generatedFixturePath = '';
        $this->fixturesContent = '';
    }
}
