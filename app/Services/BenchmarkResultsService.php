<?php

namespace App\Services;

use RuntimeException;
use SplFileObject;

class BenchmarkResultsService
{
    public const STRATEGIES = [
        'no-cache',
        'cache-aside',
        'read-through',
        'write-through',
    ];

    public const SCENARIOS = [
        'read-heavy',
        'write-heavy',
    ];

    public const REDIS_MODES = [
        'single',
        'cluster',
    ];

    public const CONCURRENT_USERS = [
        100,
        250,
        500,
        750,
        1000,
        1500,
        2000,
    ];

    public function __construct(private readonly ?string $resultsPath = null) {}

    /**
     * @return array{
     *     metrics: list<array<string, bool|float|int|string|null>>,
     *     resources: list<array<string, bool|float|int|string|null>>,
     *     validity: list<array<string, bool|float|int|string|null>>,
     *     anova: list<array<string, bool|float|int|string|null>>,
     *     tukey: list<array<string, bool|float|int|string|null>>,
     *     strategies: list<string>,
     *     scenarios: list<string>,
     *     redisModes: list<string>,
     *     concurrentUsers: list<int>,
     *     defaults: array{scenario: string, redisMode: string, concurrentUsers: int, metric: string},
     *     metricOptions: array<string, string>,
     *     strategyLabels: array<string, string>
     * }
     */
    public function dashboardData(): array
    {
        return [
            'metrics' => $this->readCsv('metrics-summary.csv'),
            'resources' => $this->readCsv('resources-summary.csv'),
            'validity' => $this->readCsv('validity-summary.csv'),
            'anova' => $this->readCsv('anova-results-1500vu.csv'),
            'tukey' => $this->readCsv('tukey-results-1500vu.csv'),
            'strategies' => self::STRATEGIES,
            'scenarios' => self::SCENARIOS,
            'redisModes' => self::REDIS_MODES,
            'concurrentUsers' => self::CONCURRENT_USERS,
            'defaults' => [
                'scenario' => 'read-heavy',
                'redisMode' => 'single',
                'concurrentUsers' => 1500,
                'metric' => 'avg_ms',
            ],
            'metricOptions' => [
                'avg_ms' => 'Latensi rata-rata',
                'p95_ms' => 'Latensi P95',
                'p99_ms' => 'Latensi P99',
                'throughput_rps' => 'Throughput',
                'cache_hit_ratio_pct' => 'Rasio cache hit',
                'error_rate_pct' => 'Tingkat error',
            ],
            'strategyLabels' => [
                'no-cache' => 'Tanpa Cache',
                'cache-aside' => 'Cache Aside',
                'read-through' => 'Read Through',
                'write-through' => 'Write Through',
            ],
        ];
    }

    /**
     * @return list<array<string, bool|float|int|string|null>>
     */
    private function readCsv(string $filename): array
    {
        $path = $this->resultsDirectory().DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            throw new RuntimeException("Benchmark result file [{$filename}] was not found.");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $headers = [];
        $rows = [];

        foreach ($file as $index => $row) {
            if ($row === false || $row === [null]) {
                continue;
            }

            if ($index === 0) {
                $headers = array_map(static fn (?string $header): string => trim((string) $header), $row);

                continue;
            }

            if ($headers === []) {
                continue;
            }

            $rows[] = $this->normalizeRow($headers, $row);
        }

        return $rows;
    }

    private function resultsDirectory(): string
    {
        return $this->resultsPath ?? base_path('benchmark-results-combined');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $values
     * @return array<string, bool|float|int|string|null>
     */
    private function normalizeRow(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = $this->normalizeValue($header, $values[$index] ?? null);
        }

        return $row;
    }

    private function normalizeValue(string $key, ?string $value): bool|float|int|string|null
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (in_array($key, ['is_significant', 'reject'], true)) {
            return strtolower($value) === 'true';
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }
}
