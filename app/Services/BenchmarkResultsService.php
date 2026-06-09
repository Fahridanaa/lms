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
     *     endpoints: list<array<string, bool|float|int|string|null>>,
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
     *     scoreSummary: array<string, mixed>,
     *     researchSummary: array<string, mixed>
     * }
     */
    public function dashboardData(): array
    {
        $metrics = $this->readCsv('metrics-summary.csv');
        $resources = $this->readCsv('resources-summary.csv');
        $validity = $this->readCsv('validity-summary.csv');
        $scoreSummary = $this->scoreSummary($metrics, $resources, $validity);

        return [
            'metrics' => $metrics,
            'resources' => $resources,
            'endpoints' => $this->readCsv('endpoint-summary.csv'),
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
            'scoreSummary' => $scoreSummary,
            'researchSummary' => $this->researchSummary($metrics, $validity, $scoreSummary),
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
     * @param  list<array<string, bool|float|int|string|null>>  $validity
     * @param  array<string, mixed>  $scoreSummary
     * @return array<string, mixed>
     */
    private function researchSummary(array $metrics, array $validity, array $scoreSummary): array
    {
        $overallWinner = $this->overallWinner($scoreSummary);
        $workloadWinners = $this->workloadWinners($scoreSummary);

        return [
            'analysis_vu' => self::ANALYSIS_VU,
            'saturation_vu' => self::SATURATION_VU,
            'overall_winner' => $overallWinner,
            'baseline_improvements' => $this->baselineImprovements($metrics, $scoreSummary),
            'workload_winners' => $workloadWinners,
            'redis_mode_insights' => $this->redisModeInsights($metrics, $validity),
            'trade_offs' => $this->tradeOffs(),
            'recommendation' => $this->recommendation($overallWinner, $workloadWinners),
            'threats_to_validity' => [
                'LMS Mini merepresentasikan alur akademik inti, tetapi belum mencakup seluruh kompleksitas LMS produksi.',
                'Workload dibentuk melalui skenario k6 sehingga perilaku pengguna nyata dapat memiliki variasi yang lebih lebar.',
                'Pengujian dilakukan pada lingkungan VPS terbatas; hasil absolut dapat berubah pada perangkat keras atau jaringan berbeda.',
                'Redis Cluster digunakan sebagai pembanding skalabilitas, bukan sebagai fokus utama optimasi aplikasi.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $scoreSummary
     * @return array<string, mixed>|null
     */
    private function overallWinner(array $scoreSummary): ?array
    {
        $candidates = [];

        foreach ($scoreSummary['groups'] ?? [] as $group) {
            foreach ($group['rankings'] ?? [] as $ranking) {
                $candidates[] = $this->researchRanking($group, $ranking);
            }
        }

        return $this->bestResearchRanking($candidates);
    }

    /**
     * @param  array<string, mixed>  $scoreSummary
     * @return list<array<string, mixed>>
     */
    private function workloadWinners(array $scoreSummary): array
    {
        $winners = [];

        foreach (self::SCENARIOS as $scenario) {
            $candidates = [];

            foreach ($scoreSummary['groups'] ?? [] as $group) {
                if (($group['scenario'] ?? null) !== $scenario || ($group['winner'] ?? null) === null) {
                    continue;
                }

                $candidates[] = $this->researchRanking($group, $group['winner']);
            }

            $winner = $this->bestResearchRanking($candidates);

            if ($winner !== null) {
                $winners[] = $winner;
            }
        }

        return $winners;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $ranking
     * @return array<string, mixed>
     */
    private function researchRanking(array $group, array $ranking): array
    {
        return [
            'strategy' => (string) $ranking['strategy'],
            'label' => (string) $ranking['label'],
            'score' => (float) $ranking['score'],
            'rank' => (int) $ranking['rank'],
            'redis_mode' => (string) $group['redis_mode'],
            'redis_label' => (string) $group['redis_label'],
            'scenario' => (string) $group['scenario'],
            'scenario_label' => (string) $group['scenario_label'],
            'valid_iterations' => (int) $ranking['valid_iterations'],
            'total_iterations' => (int) $ranking['total_iterations'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rankings
     * @return array<string, mixed>|null
     */
    private function bestResearchRanking(array $rankings): ?array
    {
        usort($rankings, function (array $left, array $right): int {
            if ((float) $left['score'] === (float) $right['score']) {
                return $this->strategyOrder((string) $left['strategy']) <=> $this->strategyOrder((string) $right['strategy']);
            }

            return (float) $right['score'] <=> (float) $left['score'];
        });

        return $rankings[0] ?? null;
    }

    /**
     * @param  list<array<string, bool|float|int|string|null>>  $metrics
     * @param  array<string, mixed>  $scoreSummary
     * @return list<array<string, mixed>>
     */
    private function baselineImprovements(array $metrics, array $scoreSummary): array
    {
        $improvements = [];

        foreach ($scoreSummary['groups'] ?? [] as $group) {
            $winner = $group['winner'] ?? null;

            if ($winner === null) {
                continue;
            }

            $baselineRow = $this->findBenchmarkRow(
                $metrics,
                (string) $group['redis_mode'],
                (string) $group['scenario'],
                'no-cache',
                self::ANALYSIS_VU
            );
            $winnerRow = $this->findBenchmarkRow(
                $metrics,
                (string) $group['redis_mode'],
                (string) $group['scenario'],
                (string) $winner['strategy'],
                self::ANALYSIS_VU
            );

            if ($baselineRow === null || $winnerRow === null) {
                continue;
            }

            $improvements[] = [
                'redis_mode' => (string) $group['redis_mode'],
                'redis_label' => (string) $group['redis_label'],
                'scenario' => (string) $group['scenario'],
                'scenario_label' => (string) $group['scenario_label'],
                'winner_strategy' => (string) $winner['strategy'],
                'winner_label' => (string) $winner['label'],
                'latency_pct' => $this->percentageChange($this->numericValue($baselineRow['avg_ms'] ?? null), $this->numericValue($winnerRow['avg_ms'] ?? null), 'lower'),
                'throughput_pct' => $this->percentageChange($this->numericValue($baselineRow['throughput_rps'] ?? null), $this->numericValue($winnerRow['throughput_rps'] ?? null), 'higher'),
                'error_rate_pct' => $this->percentageChange($this->numericValue($baselineRow['error_rate_pct'] ?? null), $this->numericValue($winnerRow['error_rate_pct'] ?? null), 'lower'),
            ];
        }

        return $improvements;
    }

    private function percentageChange(float $baseline, float $current, string $direction): ?float
    {
        if ($baseline === 0.0) {
            return $current === 0.0 ? 0.0 : null;
        }

        $change = $direction === 'lower'
            ? (($baseline - $current) / abs($baseline)) * 100
            : (($current - $baseline) / abs($baseline)) * 100;

        return round($change, 2);
    }

    /**
     * @param  list<array<string, bool|float|int|string|null>>  $metrics
     * @param  list<array<string, bool|float|int|string|null>>  $validity
     * @return list<array<string, mixed>>
     */
    private function redisModeInsights(array $metrics, array $validity): array
    {
        $insights = [];

        foreach (self::SCENARIOS as $scenario) {
            $summaries = [];

            foreach (self::REDIS_MODES as $redisMode) {
                $metricRows = array_values(array_filter($metrics, fn (array $row): bool => (
                    ($row['scenario'] ?? null) === $scenario
                    && ($row['redis_mode'] ?? null) === $redisMode
                    && (int) ($row['concurrent_users'] ?? 0) === self::ANALYSIS_VU
                )));
                $saturationRows = array_values(array_filter($validity, fn (array $row): bool => (
                    ($row['scenario'] ?? null) === $scenario
                    && ($row['redis_mode'] ?? null) === $redisMode
                    && (int) ($row['concurrent_users'] ?? 0) === self::SATURATION_VU
                )));

                $summaries[] = [
                    'redis_mode' => $redisMode,
                    'redis_label' => $this->redisModeLabel($redisMode),
                    'avg_latency_ms' => $this->average($metricRows, 'avg_ms'),
                    'avg_throughput_rps' => $this->average($metricRows, 'throughput_rps'),
                    'avg_error_rate_pct' => $this->average($metricRows, 'error_rate_pct'),
                    'saturated_iterations' => array_sum(array_map(fn (array $row): int => (int) ($row['saturated'] ?? 0), $saturationRows)),
                    'total_iterations' => array_sum(array_map(fn (array $row): int => (int) ($row['total_iterations'] ?? 0), $saturationRows)),
                ];
            }

            $insights[] = [
                'scenario' => $scenario,
                'scenario_label' => $this->scenarioLabel($scenario),
                'faster_mode' => $this->bestMode($summaries, 'avg_latency_ms', 'lower'),
                'stable_mode' => $this->stableMode($summaries),
                'mode_summaries' => $summaries,
            ];
        }

        return $insights;
    }

    /**
     * @param  list<array<string, bool|float|int|string|null>>  $rows
     */
    private function average(array $rows, string $metric): float
    {
        $values = array_filter(
            array_map(fn (array $row): float => $this->numericValue($row[$metric] ?? null, NAN), $rows),
            is_finite(...)
        );

        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 3);
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return array<string, mixed>|null
     */
    private function bestMode(array $summaries, string $metric, string $direction): ?array
    {
        usort($summaries, function (array $left, array $right) use ($metric, $direction): int {
            if ((float) $left[$metric] === (float) $right[$metric]) {
                return $this->redisModeOrder((string) $left['redis_mode']) <=> $this->redisModeOrder((string) $right['redis_mode']);
            }

            return $direction === 'lower'
                ? (float) $left[$metric] <=> (float) $right[$metric]
                : (float) $right[$metric] <=> (float) $left[$metric];
        });

        return $summaries[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return array<string, mixed>|null
     */
    private function stableMode(array $summaries): ?array
    {
        usort($summaries, function (array $left, array $right): int {
            if ((float) $left['avg_error_rate_pct'] === (float) $right['avg_error_rate_pct']) {
                if ((int) $left['saturated_iterations'] === (int) $right['saturated_iterations']) {
                    return $this->redisModeOrder((string) $left['redis_mode']) <=> $this->redisModeOrder((string) $right['redis_mode']);
                }

                return (int) $left['saturated_iterations'] <=> (int) $right['saturated_iterations'];
            }

            return (float) $left['avg_error_rate_pct'] <=> (float) $right['avg_error_rate_pct'];
        });

        return $summaries[0] ?? null;
    }

    /**
     * @return list<array<string, string>>
     */
    private function tradeOffs(): array
    {
        return [
            [
                'strategy' => 'cache-aside',
                'label' => 'Cache Aside',
                'strength' => 'Respons tercepat ketika data banyak dibaca berulang.',
                'trade_off' => 'Membutuhkan invalidasi cache yang disiplin agar data tidak basi.',
            ],
            [
                'strategy' => 'write-through',
                'label' => 'Write Through',
                'strength' => 'Konsistensi tulis lebih kuat karena cache diperbarui saat data berubah.',
                'trade_off' => 'Operasi tulis membayar biaya tambahan untuk sinkronisasi cache.',
            ],
            [
                'strategy' => 'read-through',
                'label' => 'Read Through',
                'strength' => 'Akses baca lebih terpusat pada loader cache.',
                'trade_off' => 'Manfaatnya bergantung pada pola akses dan biaya miss pertama.',
            ],
            [
                'strategy' => 'no-cache',
                'label' => 'Tanpa Cache',
                'strength' => 'Baseline paling sederhana untuk mengukur dampak strategi cache.',
                'trade_off' => 'Tidak memberi perlindungan performa saat jumlah virtual user meningkat.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $overallWinner
     * @param  list<array<string, mixed>>  $workloadWinners
     * @return array<string, mixed>
     */
    private function recommendation(?array $overallWinner, array $workloadWinners): array
    {
        $readHeavyWinner = $this->winnerForScenario($workloadWinners, 'read-heavy');
        $writeHeavyWinner = $this->winnerForScenario($workloadWinners, 'write-heavy');

        return [
            'title' => 'Rekomendasi implementasi',
            'primary_strategy' => $overallWinner['strategy'] ?? null,
            'primary_label' => $overallWinner['label'] ?? 'strategi cache terbaik',
            'body' => sprintf(
                'Gunakan %s sebagai strategi utama ketika prioritas penelitian adalah performa komposit pada titik stabil %s VU.',
                $overallWinner['label'] ?? 'strategi cache terbaik',
                number_format(self::ANALYSIS_VU, 0, ',', '.')
            ),
            'notes' => [
                sprintf('Untuk Read Heavy, prioritaskan %s.', $readHeavyWinner['label'] ?? 'strategi dengan skor tertinggi'),
                sprintf('Untuk Write Heavy, prioritaskan %s.', $writeHeavyWinner['label'] ?? 'strategi dengan skor tertinggi'),
                'Gunakan hasil 2000 VU sebagai sinyal saturasi, bukan dasar inferensi statistik utama.',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $workloadWinners
     * @return array<string, mixed>|null
     */
    private function winnerForScenario(array $workloadWinners, string $scenario): ?array
    {
        foreach ($workloadWinners as $winner) {
            if (($winner['scenario'] ?? null) === $scenario) {
                return $winner;
            }
        }

        return null;
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

    private function redisModeOrder(string $redisMode): int
    {
        $index = array_search($redisMode, self::REDIS_MODES, true);

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
