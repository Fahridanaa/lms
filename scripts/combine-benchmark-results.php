<?php

/**
 * Combine single Redis and Redis Cluster benchmark outputs.
 *
 * Usage:
 *   php scripts/combine-benchmark-results.php
 *   php scripts/combine-benchmark-results.php benchmark-results-combined benchmark-results benchmark-results-cluster
 *
 * Outputs:
 *   - benchmark-results-combined/metrics-summary.csv
 *   - benchmark-results-combined/resources-summary.csv
 *   - benchmark-results-combined/endpoint-summary.csv
 *   - benchmark-results-combined/anova-input.csv
 *
 * The combined metrics file includes validity_status:
 *   - valid: error_rate_pct <= 5
 *   - saturated: error_rate_pct > 5
 */

$outputDir = $argv[1] ?? __DIR__ . '/../benchmark-results-combined';
$inputDirs = array_slice($argv, 2);

if ($inputDirs === []) {
    $inputDirs = [
        __DIR__ . '/../benchmark-results',
        __DIR__ . '/../benchmark-results-cluster',
    ];
}

$outputDir = rtrim($outputDir, '/');

if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Error: Cannot create output directory: {$outputDir}\n");
    exit(1);
}

$strategies = ['no-cache', 'cache-aside', 'read-through', 'write-through'];
$scenarios = ['read-heavy', 'write-heavy'];
$vuLevels = [100, 250, 500, 750, 1000, 1500, 2000];
$endpointDefinitions = [
    'assignment_detail_duration' => [
        'label' => 'Detail tugas',
        'method' => 'GET',
        'path_pattern' => '/api/assignments/{id}',
        'operation_type' => 'read',
    ],
    'grade_submission_duration' => [
        'label' => 'Penilaian tugas',
        'method' => 'PUT',
        'path_pattern' => '/api/submissions/{id}/grade',
        'operation_type' => 'write',
    ],
    'gradebook_duration' => [
        'label' => 'Gradebook course',
        'method' => 'GET',
        'path_pattern' => '/api/courses/{courseId}/gradebook',
        'operation_type' => 'read',
    ],
    'material_detail_duration' => [
        'label' => 'Detail materi',
        'method' => 'GET',
        'path_pattern' => '/api/materials/{id}',
        'operation_type' => 'read',
    ],
    'materials_list_duration' => [
        'label' => 'Daftar materi course',
        'method' => 'GET',
        'path_pattern' => '/api/courses/{courseId}/materials',
        'operation_type' => 'read',
    ],
    'quiz_detail_duration' => [
        'label' => 'Detail kuis',
        'method' => 'GET',
        'path_pattern' => '/api/quizzes/{id}',
        'operation_type' => 'read',
    ],
    'start_attempt_duration' => [
        'label' => 'Mulai attempt kuis',
        'method' => 'POST',
        'path_pattern' => '/api/quizzes/{id}/attempts',
        'operation_type' => 'write',
    ],
    'submit_assignment_duration' => [
        'label' => 'Submit tugas',
        'method' => 'POST',
        'path_pattern' => '/api/assignments/{id}/submissions',
        'operation_type' => 'write',
    ],
    'submit_quiz_duration' => [
        'label' => 'Submit kuis',
        'method' => 'PUT',
        'path_pattern' => '/api/quizzes/{quizId}/attempts/{attemptId}',
        'operation_type' => 'write',
    ],
];

function readCsvRows(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $rows = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $headers = fgetcsv($handle, escape: '');
    if ($headers === false) {
        fclose($handle);

        return [];
    }

    while (($data = fgetcsv($handle, escape: '')) !== false) {
        if (count($data) !== count($headers)) {
            continue;
        }

        $rows[] = array_combine($headers, $data);
    }

    fclose($handle);

    return $rows;
}

function writeCsvRows(string $path, array $headers, array $rows): void
{
    $handle = fopen($path, 'w');
    if ($handle === false) {
        throw new RuntimeException("Cannot write CSV: {$path}");
    }

    fputcsv($handle, $headers, escape: '');

    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $header) {
            $line[] = $row[$header] ?? '';
        }
        fputcsv($handle, $line, escape: '');
    }

    fclose($handle);
}

function inferRedisModeFromDir(string $dir): string
{
    return str_contains(basename($dir), 'cluster') ? 'cluster' : 'single';
}

function detectRedisMode(string $dir, string $fallback): string
{
    $marker = rtrim($dir, '/') . '/.redis-mode';

    if (is_file($marker)) {
        $mode = trim((string) file_get_contents($marker));

        if ($mode !== '') {
            return $mode;
        }
    }

    return $fallback;
}

function normalizeRedisMode(array $row, string $fallback): string
{
    if ($fallback === 'cluster') {
        return 'cluster';
    }

    $mode = trim((string) ($row['redis_mode'] ?? ''));

    return $mode !== '' ? $mode : $fallback;
}

function validityStatus(string|float|int|null $errorRate): string
{
    if ($errorRate === null || $errorRate === '' || ! is_numeric($errorRate)) {
        return 'unknown';
    }

    return (float) $errorRate > 5.0 ? 'saturated' : 'valid';
}

function metricValue(array $json, string $metric, string $value): float|string
{
    $raw = $json['metrics'][$metric]['values'][$value] ?? null;

    return is_numeric($raw) ? round((float) $raw, 2) : '';
}

function endpointMetricValue(array $metric, string $value): float|string
{
    $raw = $metric['values'][$value] ?? null;

    return is_numeric($raw) ? round((float) $raw, 2) : '';
}

function endpointSummaryRows(array $json, array $definitions, array $context): array
{
    $rows = [];

    foreach ($definitions as $metricKey => $definition) {
        $metric = $json['metrics'][$metricKey] ?? null;

        if (! is_array($metric)) {
            continue;
        }

        $rows[] = array_merge($context, [
            'endpoint_key' => $metricKey,
            'endpoint_label' => $definition['label'],
            'method' => $definition['method'],
            'path_pattern' => $definition['path_pattern'],
            'operation_type' => $definition['operation_type'],
            'avg_ms' => endpointMetricValue($metric, 'avg'),
            'p90_ms' => endpointMetricValue($metric, 'p(90)'),
            'p95_ms' => endpointMetricValue($metric, 'p(95)'),
            'p99_ms' => endpointMetricValue($metric, 'p(99)'),
            'max_ms' => endpointMetricValue($metric, 'max'),
        ]);
    }

    return $rows;
}

function summarizeEndpointRows(array $rows): array
{
    $groups = [];
    $metrics = ['avg_ms', 'p90_ms', 'p95_ms', 'p99_ms', 'max_ms'];

    foreach ($rows as $row) {
        $key = implode('|', [
            $row['redis_mode'],
            $row['strategy'],
            $row['scenario'],
            $row['concurrent_users'],
            $row['endpoint_key'],
        ]);

        if (! isset($groups[$key])) {
            $groups[$key] = [
                'row' => [
                    'redis_mode' => $row['redis_mode'],
                    'strategy' => $row['strategy'],
                    'scenario' => $row['scenario'],
                    'concurrent_users' => $row['concurrent_users'],
                    'endpoint_key' => $row['endpoint_key'],
                    'endpoint_label' => $row['endpoint_label'],
                    'method' => $row['method'],
                    'path_pattern' => $row['path_pattern'],
                    'operation_type' => $row['operation_type'],
                ],
                'metric_totals' => array_fill_keys($metrics, 0.0),
                'metric_counts' => array_fill_keys($metrics, 0),
                'iterations' => [],
            ];
        }

        $groups[$key]['iterations'][(int) $row['iteration']] = true;

        foreach ($metrics as $metric) {
            if (! is_numeric($row[$metric])) {
                continue;
            }

            $groups[$key]['metric_totals'][$metric] += (float) $row[$metric];
            $groups[$key]['metric_counts'][$metric]++;
        }
    }

    $summaries = [];

    foreach ($groups as $group) {
        $summary = $group['row'];

        foreach ($metrics as $metric) {
            $count = $group['metric_counts'][$metric];
            $summary[$metric] = $count > 0 ? round($group['metric_totals'][$metric] / $count, 2) : '';
        }

        $summary['iterations_averaged'] = count($group['iterations']);
        $summaries[] = $summary;
    }

    usort($summaries, function (array $left, array $right): int {
        return [
            $left['redis_mode'],
            $left['strategy'],
            $left['scenario'],
            (int) $left['concurrent_users'],
            $left['endpoint_key'],
        ] <=> [
            $right['redis_mode'],
            $right['strategy'],
            $right['scenario'],
            (int) $right['concurrent_users'],
            $right['endpoint_key'],
        ];
    });

    return $summaries;
}

function cacheHitRatioForSummary(string $summaryPath): float|string
{
    $hitRatioPath = str_replace('-summary.json', '-cache-hit-ratio.txt', $summaryPath);

    if (is_file($hitRatioPath)) {
        $content = trim((string) file_get_contents($hitRatioPath));

        if (preg_match('/Cache Hit Ratio\s*:\s*([0-9]+(?:\.[0-9]+)?)%/', $content, $matches) === 1) {
            return round((float) $matches[1], 2);
        }

        if (is_numeric($content)) {
            return round((float) $content, 2);
        }
    }

    return '';
}

// Combine metrics-summary.csv
$combinedMetrics = [];
$metricHeaders = [
    'strategy',
    'scenario',
    'concurrent_users',
    'avg_ms',
    'p90_ms',
    'p95_ms',
    'p99_ms',
    'max_ms',
    'throughput_rps',
    'error_rate_pct',
    'http_reqs_total',
    'cache_hit_ratio_pct',
    'iterations_averaged',
    'read_avg_ms',
    'write_avg_ms',
    'redis_mode',
    'validity_status',
];

foreach ($inputDirs as $inputDir) {
    $inputDir = rtrim($inputDir, '/');
    $rows = readCsvRows("{$inputDir}/metrics-summary.csv");
    $fallbackMode = inferRedisModeFromDir($inputDir);

    foreach ($rows as $row) {
        $row['redis_mode'] = normalizeRedisMode($row, $fallbackMode);
        $row['validity_status'] = validityStatus($row['error_rate_pct'] ?? null);
        $combinedMetrics[] = $row;
    }
}

writeCsvRows("{$outputDir}/metrics-summary.csv", $metricHeaders, $combinedMetrics);

// Combine resources-summary.csv
$combinedResources = [];
$resourceHeaders = [
    'strategy',
    'scenario',
    'concurrent_users',
    'cpu_avg_pct',
    'cpu_max_pct',
    'mem_avg_mb',
    'mem_max_mb',
    'mem_avg_pct',
    'mem_max_pct',
    'disk_read_avg_mb_s',
    'disk_read_max_mb_s',
    'disk_write_avg_mb_s',
    'disk_write_max_mb_s',
    'iterations_averaged',
    'redis_mode',
];

foreach ($inputDirs as $inputDir) {
    $inputDir = rtrim($inputDir, '/');
    $rows = readCsvRows("{$inputDir}/resources-summary.csv");
    $fallbackMode = inferRedisModeFromDir($inputDir);

    foreach ($rows as $row) {
        $row['redis_mode'] = normalizeRedisMode($row, $fallbackMode);
        $combinedResources[] = $row;
    }
}

writeCsvRows("{$outputDir}/resources-summary.csv", $resourceHeaders, $combinedResources);

// Extract per-iteration k6 summary rows for ANOVA/Tukey
$anovaRows = [];
$endpointRows = [];
$anovaHeaders = [
    'redis_mode',
    'strategy',
    'scenario',
    'concurrent_users',
    'iteration',
    'avg_ms',
    'p90_ms',
    'p95_ms',
    'p99_ms',
    'max_ms',
    'throughput_rps',
    'error_rate_pct',
    'http_reqs_total',
    'cache_hit_ratio_pct',
    'validity_status',
];

foreach ($inputDirs as $inputDir) {
    $inputDir = rtrim($inputDir, '/');
    $fallbackMode = inferRedisModeFromDir($inputDir);

    foreach ($strategies as $strategy) {
        foreach ($scenarios as $scenario) {
            $iterationDirs = glob("{$inputDir}/{$strategy}/{$scenario}/iter*", GLOB_ONLYDIR) ?: [];
            usort($iterationDirs, fn (string $left, string $right): int => (int) preg_replace('/\D+/', '', basename($left)) <=> (int) preg_replace('/\D+/', '', basename($right)));

            foreach ($iterationDirs as $iterationDir) {
                $iteration = (int) preg_replace('/\D+/', '', basename($iterationDir));

                $redisMode = detectRedisMode($iterationDir, $fallbackMode);

                foreach ($vuLevels as $vu) {
                    $files = glob("{$iterationDir}/{$vu}vu-*-summary.json");

                    if ($files === false || $files === []) {
                        continue;
                    }

                    sort($files);
                    $summaryPath = end($files);
                    $json = json_decode((string) file_get_contents($summaryPath), true);

                    if (! is_array($json)) {
                        continue;
                    }

                    $throughput = $json['metrics']['http_reqs']['values']['rate'] ?? null;
                    $httpReqs = $json['metrics']['http_reqs']['values']['count'] ?? 0;
                    $errorRate = ($json['metrics']['http_req_failed']['values']['rate'] ?? 0) * 100;

                    $anovaRows[] = [
                        'redis_mode' => $redisMode,
                        'strategy' => $strategy,
                        'scenario' => $scenario,
                        'concurrent_users' => $vu,
                        'iteration' => $iteration,
                        'avg_ms' => metricValue($json, 'http_req_duration', 'avg'),
                        'p90_ms' => metricValue($json, 'http_req_duration', 'p(90)'),
                        'p95_ms' => metricValue($json, 'http_req_duration', 'p(95)'),
                        'p99_ms' => metricValue($json, 'http_req_duration', 'p(99)'),
                        'max_ms' => metricValue($json, 'http_req_duration', 'max'),
                        'throughput_rps' => is_numeric($throughput) ? round((float) $throughput, 2) : '',
                        'error_rate_pct' => round((float) $errorRate, 4),
                        'http_reqs_total' => $httpReqs,
                        'cache_hit_ratio_pct' => cacheHitRatioForSummary($summaryPath),
                        'validity_status' => validityStatus($errorRate),
                    ];

                    array_push($endpointRows, ...endpointSummaryRows($json, $endpointDefinitions, [
                        'redis_mode' => $redisMode,
                        'strategy' => $strategy,
                        'scenario' => $scenario,
                        'concurrent_users' => $vu,
                        'iteration' => $iteration,
                    ]));
                }
            }
        }
    }
}

$endpointHeaders = [
    'redis_mode',
    'strategy',
    'scenario',
    'concurrent_users',
    'endpoint_key',
    'endpoint_label',
    'method',
    'path_pattern',
    'operation_type',
    'avg_ms',
    'p90_ms',
    'p95_ms',
    'p99_ms',
    'max_ms',
    'iterations_averaged',
];

$endpointSummaries = summarizeEndpointRows($endpointRows);

writeCsvRows("{$outputDir}/endpoint-summary.csv", $endpointHeaders, $endpointSummaries);
writeCsvRows("{$outputDir}/anova-input.csv", $anovaHeaders, $anovaRows);

echo "Combined benchmark results written to {$outputDir}\n";
echo 'metrics-summary.csv rows  : ' . count($combinedMetrics) . "\n";
echo 'resources-summary.csv rows: ' . count($combinedResources) . "\n";
echo 'endpoint-summary.csv rows : ' . count($endpointSummaries) . "\n";
echo 'anova-input.csv rows      : ' . count($anovaRows) . "\n";
