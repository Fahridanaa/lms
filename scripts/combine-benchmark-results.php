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
 *   - benchmark-results-combined/validity-summary.csv
 *
 * Saturation is based on unexpected_error_rate_pct, not total error_rate_pct.
 */
$outputDir = $argv[1] ?? __DIR__.'/../benchmark-results-combined';
$inputDirs = array_slice($argv, 2);

if ($inputDirs === []) {
    $inputDirs = [__DIR__.'/../benchmark-results', __DIR__.'/../benchmark-results-cluster'];
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
    'course_structure_duration' => [
        'label' => 'Struktur course',
        'method' => 'GET',
        'path_pattern' => '/api/courses/{courseId}/structure',
        'operation_type' => 'read',
    ],
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
    'user_grades_duration' => [
        'label' => 'Nilai pengguna',
        'method' => 'GET',
        'path_pattern' => '/api/users/{userId}/grades',
        'operation_type' => 'read',
    ],
    'material_detail_duration' => [
        'label' => 'Detail materi',
        'method' => 'GET',
        'path_pattern' => '/api/materials/{id}',
        'operation_type' => 'read',
    ],
    'material_list_duration' => [
        'label' => 'Daftar materi course',
        'method' => 'GET',
        'path_pattern' => '/api/courses/{courseId}/materials',
        'operation_type' => 'read',
    ],
    'course_completion_duration' => [
        'label' => 'Completion course',
        'method' => 'GET',
        'path_pattern' => '/api/courses/{courseId}/completion',
        'operation_type' => 'read',
    ],
    'quiz_detail_duration' => [
        'label' => 'Detail kuis',
        'method' => 'GET',
        'path_pattern' => '/api/quizzes/{id}',
        'operation_type' => 'read',
    ],
    'quiz_attempt_result_duration' => [
        'label' => 'Hasil attempt kuis',
        'method' => 'GET',
        'path_pattern' => '/api/quizzes/{quizId}/attempts/{attemptId}/result',
        'operation_type' => 'read',
    ],
    'read_activity_duration' => [
        'label' => 'Detail aktivitas',
        'method' => 'GET',
        'path_pattern' => '/api/{activityType}/{id}',
        'operation_type' => 'read',
    ],
    'cascade_read_duration' => [
        'label' => 'Struktur course setelah write',
        'method' => 'GET',
        'path_pattern' => '/api/courses/{courseId}/structure',
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
    'material_download_duration' => [
        'label' => 'Download materi',
        'method' => 'GET',
        'path_pattern' => '/api/materials/{id}/download',
        'operation_type' => 'write',
    ],
    'marker_grade_duration' => [
        'label' => 'Penilaian marker',
        'method' => 'PUT',
        'path_pattern' => '/api/submissions/{id}/marker-grade',
        'operation_type' => 'write',
    ],
    'grade_update_duration' => [
        'label' => 'Update nilai',
        'method' => 'PUT',
        'path_pattern' => '/api/grades/{id}',
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
    $marker = rtrim($dir, '/').'/.redis-mode';

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

function validityStatus(string|float|int|null $unexpectedErrorRate): string
{
    if ($unexpectedErrorRate === null || $unexpectedErrorRate === '' || ! is_numeric($unexpectedErrorRate)) {
        return 'unknown';
    }

    return (float) $unexpectedErrorRate > 5.0 ? 'saturated' : 'valid';
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

        if (preg_match("/Cache Hit Ratio\s*:\s*([0-9]+(?:\.[0-9]+)?)%/", $content, $matches) === 1) {
            return round((float) $matches[1], 2);
        }

        if (is_numeric($content)) {
            return round((float) $content, 2);
        }
    }

    return '';
}

function k6ErrorRates(array $json): array
{
    $httpReqs = (float) ($json['metrics']['http_reqs']['values']['count'] ?? 0);
    $totalErrorRate = ((float) ($json['metrics']['http_req_failed']['values']['rate'] ?? 0)) * 100;
    $unexpectedErrors = (float) ($json['metrics']['errors']['values']['passes'] ?? 0);
    $unexpectedErrorRate = $httpReqs > 0 ? ($unexpectedErrors / $httpReqs) * 100 : 0.0;
    $controlledErrorRate = max($totalErrorRate - $unexpectedErrorRate, 0.0);

    return [
        'error_rate_pct' => round($totalErrorRate, 4),
        'unexpected_error_rate_pct' => round($unexpectedErrorRate, 4),
        'controlled_error_rate_pct' => round($controlledErrorRate, 4),
        'validity_status' => validityStatus($unexpectedErrorRate),
    ];
}

function benchmarkKey(array $row): string
{
    return implode('|', [
        $row['redis_mode'],
        $row['strategy'],
        $row['scenario'],
        $row['concurrent_users'],
    ]);
}

function metricErrorSummaries(array $rows): array
{
    $groups = [];

    foreach ($rows as $row) {
        $key = benchmarkKey($row);

        $groups[$key] ??= [
            'unexpected_total' => 0.0,
            'controlled_total' => 0.0,
            'count' => 0,
        ];

        $groups[$key]['unexpected_total'] += (float) $row['unexpected_error_rate_pct'];
        $groups[$key]['controlled_total'] += (float) $row['controlled_error_rate_pct'];
        $groups[$key]['count']++;
    }

    $summaries = [];

    foreach ($groups as $key => $group) {
        $unexpected = $group['count'] > 0 ? round($group['unexpected_total'] / $group['count'], 4) : '';
        $controlled = $group['count'] > 0 ? round($group['controlled_total'] / $group['count'], 4) : '';

        $summaries[$key] = [
            'unexpected_error_rate_pct' => $unexpected,
            'controlled_error_rate_pct' => $controlled,
            'validity_status' => validityStatus($unexpected),
        ];
    }

    return $summaries;
}

function validitySummaryRows(array $rows): array
{
    $groups = [];

    foreach ($rows as $row) {
        $key = benchmarkKey($row);

        $groups[$key] ??= [
            'redis_mode' => $row['redis_mode'],
            'scenario' => $row['scenario'],
            'concurrent_users' => $row['concurrent_users'],
            'strategy' => $row['strategy'],
            'valid' => 0,
            'saturated' => 0,
            'total_iterations' => 0,
        ];

        if (($row['validity_status'] ?? '') === 'valid') {
            $groups[$key]['valid']++;
        }

        if (($row['validity_status'] ?? '') === 'saturated') {
            $groups[$key]['saturated']++;
        }

        $groups[$key]['total_iterations']++;
    }

    return array_values($groups);
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
    'unexpected_error_rate_pct',
    'controlled_error_rate_pct',
    'validity_status',
    'http_reqs_total',
    'cache_hit_ratio_pct',
    'iterations_averaged',
    'read_avg_ms',
    'write_avg_ms',
    'redis_mode',
];

foreach ($inputDirs as $inputDir) {
    $inputDir = rtrim($inputDir, '/');
    $rows = readCsvRows("{$inputDir}/metrics-summary.csv");
    $fallbackMode = inferRedisModeFromDir($inputDir);

    foreach ($rows as $row) {
        $row['redis_mode'] = normalizeRedisMode($row, $fallbackMode);
        $combinedMetrics[] = $row;
    }
}

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
    'unexpected_error_rate_pct',
    'controlled_error_rate_pct',
    'validity_status',
    'http_reqs_total',
    'cache_hit_ratio_pct',
];

foreach ($inputDirs as $inputDir) {
    $inputDir = rtrim($inputDir, '/');
    $fallbackMode = inferRedisModeFromDir($inputDir);

    foreach ($strategies as $strategy) {
        foreach ($scenarios as $scenario) {
            $iterationDirs = glob("{$inputDir}/{$strategy}/{$scenario}/iter*", GLOB_ONLYDIR) ?: [];
            usort(
                $iterationDirs,
                fn (string $left, string $right): int => (int) preg_replace("/\D+/", '', basename($left)) <=>
                    (int) preg_replace("/\D+/", '', basename($right)),
            );

            foreach ($iterationDirs as $iterationDir) {
                $iteration = (int) preg_replace("/\D+/", '', basename($iterationDir));

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
                    $errorRates = k6ErrorRates($json);

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
                        'error_rate_pct' => $errorRates['error_rate_pct'],
                        'unexpected_error_rate_pct' => $errorRates['unexpected_error_rate_pct'],
                        'controlled_error_rate_pct' => $errorRates['controlled_error_rate_pct'],
                        'validity_status' => $errorRates['validity_status'],
                        'http_reqs_total' => $httpReqs,
                        'cache_hit_ratio_pct' => cacheHitRatioForSummary($summaryPath),
                    ];

                    array_push(
                        $endpointRows,
                        ...endpointSummaryRows($json, $endpointDefinitions, [
                            'redis_mode' => $redisMode,
                            'strategy' => $strategy,
                            'scenario' => $scenario,
                            'concurrent_users' => $vu,
                            'iteration' => $iteration,
                        ]),
                    );
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
$errorSummaries = metricErrorSummaries($anovaRows);

foreach ($combinedMetrics as &$row) {
    $summary = $errorSummaries[benchmarkKey($row)] ?? null;

    if ($summary === null) {
        $row['unexpected_error_rate_pct'] ??= '';
        $row['controlled_error_rate_pct'] ??= '';
        $row['validity_status'] ??= 'unknown';

        continue;
    }

    $row['unexpected_error_rate_pct'] = $summary['unexpected_error_rate_pct'];
    $row['controlled_error_rate_pct'] = $summary['controlled_error_rate_pct'];
    $row['validity_status'] = $summary['validity_status'];
}
unset($row);

writeCsvRows("{$outputDir}/metrics-summary.csv", $metricHeaders, $combinedMetrics);
writeCsvRows("{$outputDir}/endpoint-summary.csv", $endpointHeaders, $endpointSummaries);
writeCsvRows("{$outputDir}/anova-input.csv", $anovaHeaders, $anovaRows);
writeCsvRows("{$outputDir}/validity-summary.csv", ['redis_mode', 'scenario', 'concurrent_users', 'strategy', 'valid', 'saturated', 'total_iterations'], validitySummaryRows($anovaRows));

echo "Combined benchmark results written to {$outputDir}\n";
echo 'metrics-summary.csv rows  : '.count($combinedMetrics)."\n";
echo 'resources-summary.csv rows: '.count($combinedResources)."\n";
echo 'endpoint-summary.csv rows : '.count($endpointSummaries)."\n";
echo 'anova-input.csv rows      : '.count($anovaRows)."\n";
echo 'validity-summary.csv rows : '.count(validitySummaryRows($anovaRows))."\n";
