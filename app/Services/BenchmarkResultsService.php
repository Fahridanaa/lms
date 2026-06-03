<?php

namespace App\Services;

use RuntimeException;
use SplFileObject;

class BenchmarkResultsService
{
    private const ANALYSIS_VU = 1500;

    private const SATURATION_VU = 2000;

    private const SCORE_DIMENSIONS = [
        'read_latency' => [
            'label' => 'Performa baca',
            'metric' => 'read_avg_ms',
            'weight' => 0.25,
            'direction' => 'lower',
        ],
        'write_latency' => [
            'label' => 'Performa tulis',
            'metric' => 'write_avg_ms',
            'weight' => 0.20,
            'direction' => 'lower',
        ],
        'throughput' => [
            'label' => 'Skalabilitas',
            'metric' => 'throughput_rps',
            'weight' => 0.20,
            'direction' => 'higher',
        ],
        'cache_hit_ratio' => [
            'label' => 'Efisiensi cache',
            'metric' => 'cache_hit_ratio_pct',
            'weight' => 0.15,
            'direction' => 'higher',
        ],
        'resource_efficiency' => [
            'label' => 'Efisiensi resource',
            'metric' => 'resource_efficiency_pct',
            'weight' => 0.10,
            'direction' => 'lower',
        ],
        'error_rate' => [
            'label' => 'Keandalan',
            'metric' => 'error_rate_pct',
            'weight' => 0.10,
            'direction' => 'lower',
        ],
    ];

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
     *     strategyLabels: array<string, string>,
     *     scoreSummary: array<string, mixed>
     * }
     */
    public function dashboardData(): array
    {
        $metrics = $this->readCsv('metrics-summary.csv');
        $resources = $this->readCsv('resources-summary.csv');
        $validity = $this->readCsv('validity-summary.csv');

        return [
            'metrics' => $metrics,
            'resources' => $resources,
            'validity' => $validity,
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
            'scoreSummary' => $this->scoreSummary($metrics, $resources, $validity),
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

    /**
     * @param  list<array<string, bool|float|int|string|null>>  $metrics
     * @param  list<array<string, bool|float|int|string|null>>  $resources
     * @param  list<array<string, bool|float|int|string|null>>  $validity
     * @return array{
     *     analysis_vu: int,
     *     saturation_vu: int,
     *     weights: array<string, array{label: string, metric: string, weight: float, direction: string}>,
     *     groups: list<array<string, mixed>>
     * }
     */
    private function scoreSummary(array $metrics, array $resources, array $validity): array
    {
        $groups = [];

        foreach (self::REDIS_MODES as $redisMode) {
            foreach (self::SCENARIOS as $scenario) {
                $groups[] = $this->scoreGroup($metrics, $resources, $validity, $redisMode, $scenario);
            }
        }

        return [
            'analysis_vu' => self::ANALYSIS_VU,
            'saturation_vu' => self::SATURATION_VU,
            'weights' => self::SCORE_DIMENSIONS,
            'groups' => $groups,
        ];
    }

    /**
     * @param  list<array<string, bool|float|int|string|null>>  $metrics
     * @param  list<array<string, bool|float|int|string|null>>  $resources
     * @param  list<array<string, bool|float|int|string|null>>  $validity
     * @return array<string, mixed>
     */
    private function scoreGroup(
        array $metrics,
        array $resources,
        array $validity,
        string $redisMode,
        string $scenario
    ): array {
        $rankings = [];

        foreach (self::STRATEGIES as $strategy) {
            $metricRow = $this->findBenchmarkRow($metrics, $redisMode, $scenario, $strategy, self::ANALYSIS_VU);
            $resourceRow = $this->findBenchmarkRow($resources, $redisMode, $scenario, $strategy, self::ANALYSIS_VU);

            if ($metricRow === null || $resourceRow === null) {
                continue;
            }

            $validityRow = $this->findBenchmarkRow($validity, $redisMode, $scenario, $strategy, self::ANALYSIS_VU);
            $saturationRow = $this->findBenchmarkRow($validity, $redisMode, $scenario, $strategy, self::SATURATION_VU);

            $rankings[$strategy] = [
                'rank' => 0,
                'strategy' => $strategy,
                'label' => $this->strategyLabel($strategy),
                'score' => 0.0,
                'valid_iterations' => (int) ($validityRow['valid'] ?? 0),
                'total_iterations' => (int) ($validityRow['total_iterations'] ?? 0),
                'saturated_iterations' => (int) ($saturationRow['saturated'] ?? 0),
                'saturation_total_iterations' => (int) ($saturationRow['total_iterations'] ?? 0),
                'dimensions' => $this->scoreDimensions($metricRow, $resourceRow),
            ];
        }

        $rankings = $this->applyDimensionScores($rankings);
        $rankings = $this->sortRankings($rankings);

        return [
            'redis_mode' => $redisMode,
            'redis_label' => $this->redisModeLabel($redisMode),
            'scenario' => $scenario,
            'scenario_label' => $this->scenarioLabel($scenario),
            'winner' => $rankings[0] ?? null,
            'rankings' => $rankings,
            'valid_iterations' => array_sum(array_column($rankings, 'valid_iterations')),
            'total_iterations' => array_sum(array_column($rankings, 'total_iterations')),
            'saturated_iterations' => array_sum(array_column($rankings, 'saturated_iterations')),
            'saturation_total_iterations' => array_sum(array_column($rankings, 'saturation_total_iterations')),
        ];
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $metricRow
     * @param  array<string, bool|float|int|string|null>  $resourceRow
     * @return array<string, array{label: string, metric: string, value: float, weight: float, direction: string}>
     */
    private function scoreDimensions(array $metricRow, array $resourceRow): array
    {
        return [
            'read_latency' => $this->scoreDimension('read_latency', $this->numericValue($metricRow['read_avg_ms'] ?? null)),
            'write_latency' => $this->scoreDimension('write_latency', $this->numericValue($metricRow['write_avg_ms'] ?? null)),
            'throughput' => $this->scoreDimension('throughput', $this->numericValue($metricRow['throughput_rps'] ?? null)),
            'cache_hit_ratio' => $this->scoreDimension('cache_hit_ratio', $this->numericValue($metricRow['cache_hit_ratio_pct'] ?? null)),
            'resource_efficiency' => $this->scoreDimension('resource_efficiency', $this->resourceEfficiency($resourceRow)),
            'error_rate' => $this->scoreDimension('error_rate', $this->numericValue($metricRow['error_rate_pct'] ?? null)),
        ];
    }

    /**
     * @return array{label: string, metric: string, value: float, weight: float, direction: string}
     */
    private function scoreDimension(string $dimension, float $value): array
    {
        $definition = self::SCORE_DIMENSIONS[$dimension];

        return [
            'label' => $definition['label'],
            'metric' => $definition['metric'],
            'value' => round($value, 3),
            'weight' => $definition['weight'],
            'direction' => $definition['direction'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rankings
     * @return array<string, array<string, mixed>>
     */
    private function applyDimensionScores(array $rankings): array
    {
        foreach (array_keys(self::SCORE_DIMENSIONS) as $dimension) {
            $orderedStrategies = array_keys($rankings);

            usort($orderedStrategies, function (string $left, string $right) use ($rankings, $dimension): int {
                $leftValue = (float) $rankings[$left]['dimensions'][$dimension]['value'];
                $rightValue = (float) $rankings[$right]['dimensions'][$dimension]['value'];
                $direction = self::SCORE_DIMENSIONS[$dimension]['direction'];

                if ($leftValue === $rightValue) {
                    return $this->strategyOrder($left) <=> $this->strategyOrder($right);
                }

                return $direction === 'lower'
                    ? $leftValue <=> $rightValue
                    : $rightValue <=> $leftValue;
            });

            $strategyCount = count($orderedStrategies);

            foreach ($orderedStrategies as $index => $strategy) {
                $points = $strategyCount - $index;
                $weightedScore = $points * self::SCORE_DIMENSIONS[$dimension]['weight'];

                $rankings[$strategy]['score'] += $weightedScore;
                $rankings[$strategy]['dimensions'][$dimension]['points'] = $points;
                $rankings[$strategy]['dimensions'][$dimension]['weighted_score'] = round($weightedScore, 3);
            }
        }

        return $rankings;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rankings
     * @return list<array<string, mixed>>
     */
    private function sortRankings(array $rankings): array
    {
        $rankings = array_values($rankings);

        usort($rankings, function (array $left, array $right): int {
            if ((float) $left['score'] === (float) $right['score']) {
                return $this->strategyOrder((string) $left['strategy']) <=> $this->strategyOrder((string) $right['strategy']);
            }

            return (float) $right['score'] <=> (float) $left['score'];
        });

        foreach ($rankings as $index => $ranking) {
            $rankings[$index]['rank'] = $index + 1;
            $rankings[$index]['score'] = round((float) $ranking['score'], 3);
        }

        return $rankings;
    }

    /**
     * @param  list<array<string, bool|float|int|string|null>>  $rows
     * @return array<string, bool|float|int|string|null>|null
     */
    private function findBenchmarkRow(
        array $rows,
        string $redisMode,
        string $scenario,
        string $strategy,
        int $concurrentUsers
    ): ?array {
        foreach ($rows as $row) {
            if (
                ($row['redis_mode'] ?? null) === $redisMode
                && ($row['scenario'] ?? null) === $scenario
                && ($row['strategy'] ?? null) === $strategy
                && (int) ($row['concurrent_users'] ?? 0) === $concurrentUsers
            ) {
                return $row;
            }
        }

        return null;
    }

    private function resourceEfficiency(array $resourceRow): float
    {
        $cpuAverage = $this->numericValue($resourceRow['cpu_avg_pct'] ?? null);
        $memoryAverage = array_key_exists('mem_avg_pct', $resourceRow)
            ? $this->numericValue($resourceRow['mem_avg_pct'])
            : $this->numericValue($resourceRow['mem_avg_mb'] ?? null) / 20;

        return ($cpuAverage + $memoryAverage) / 2;
    }

    private function numericValue(mixed $value, float $default = 0.0): float
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : $default;
    }

    private function strategyOrder(string $strategy): int
    {
        $index = array_search($strategy, self::STRATEGIES, true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    private function strategyLabel(string $strategy): string
    {
        return match ($strategy) {
            'no-cache' => 'Tanpa Cache',
            'cache-aside' => 'Cache Aside',
            'read-through' => 'Read Through',
            'write-through' => 'Write Through',
            default => $strategy,
        };
    }

    private function redisModeLabel(string $redisMode): string
    {
        return $redisMode === 'single' ? 'Node Tunggal' : 'Cluster';
    }

    private function scenarioLabel(string $scenario): string
    {
        return $scenario === 'read-heavy' ? 'Read Heavy' : 'Write Heavy';
    }
}
