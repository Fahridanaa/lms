<?php

namespace Tests\Feature\Benchmark\Concerns;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

trait BenchmarkFixtureSetup
{
    private static ?string $cachedFixturesContent = null;

    protected string $generatedFixturePath = '';

    protected string $fixturesContent = '';

    /**
     * Flush cache, seed the benchmark dataset once, and generate k6 fixture data once per test class.
     */
    protected function setUpBenchmarkFixtures(bool $migrateFresh = false): void
    {
        Cache::flush();

        if (self::$cachedFixturesContent === null) {
            if ($migrateFresh) {
                Artisan::call('migrate:fresh', ['--force' => true]);
            }

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

            self::$cachedFixturesContent = file_get_contents($outputPath);
            @unlink($outputPath);
        }

        $this->fixturesContent = self::$cachedFixturesContent;
        $this->generatedFixturePath = tempnam(sys_get_temp_dir(), 'k6-fixtures-');
        file_put_contents($this->generatedFixturePath, $this->fixturesContent);
    }

    /**
     * Parse generated fixture constants from either raw arrays or k6 SharedArray wrappers.
     *
     * @return array<string, mixed>
     */
    protected function parseFixturePools(string $content): array
    {
        $fixtures = [];
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            $lineEnd = strpos($content, "\n", $offset);
            if ($lineEnd === false) {
                $lineEnd = $length;
            }

            $line = rtrim(substr($content, $offset, $lineEnd - $offset), "\r");
            $offset = $lineEnd + 1;

            if (! str_starts_with($line, 'const ')) {
                continue;
            }

            $equalsPosition = strpos($line, ' = ');
            if ($equalsPosition === false) {
                continue;
            }

            $name = substr($line, 6, $equalsPosition - 6);
            $expression = rtrim(substr($line, $equalsPosition + 3), ';');
            $json = $this->extractFixtureJson($expression);
            if ($json === null) {
                continue;
            }

            $data = json_decode($json, true);
            if (is_array($data)) {
                $fixtures[$name] = $data;
            }
        }

        return $fixtures;
    }

    private function extractFixtureJson(string $expression): ?string
    {
        $arrayPosition = strpos($expression, '[');
        $objectPosition = strpos($expression, '{');

        if ($arrayPosition === false && $objectPosition === false) {
            return null;
        }

        $start = $arrayPosition === false
            ? $objectPosition
            : ($objectPosition === false ? $arrayPosition : min($arrayPosition, $objectPosition));
        $open = $expression[$start];
        $close = $open === '[' ? ']' : '}';
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < strlen($expression); $i++) {
            $char = $expression[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($expression, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Clean up the generated fixture file.
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
