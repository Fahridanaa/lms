<?php

/**
 * Extract individual iteration metrics from per-iteration k6 summary.json
 * Output: CSV ready for Google Colab (ANOVA + Tukey HSD)
 */

$base = __DIR__ . '/../benchmark-results';
$strategies = ['no-cache', 'cache-aside', 'read-through', 'write-through'];
$scenarios = ['read-heavy', 'write-heavy'];
$vu_levels = [100, 250, 500, 750, 1000, 1500, 2000];

$output = [];
$output[] = ['strategy', 'scenario', 'concurrent_users', 'iteration', 'avg_ms', 'p95_ms', 'p99_ms', 'throughput_rps', 'error_rate_pct', 'http_reqs_total', 'cache_hit_ratio_pct'];

foreach ($strategies as $strategy) {
    foreach ($scenarios as $scenario) {
        for ($iter = 1; $iter <= 5; $iter++) {
            $dir = "{$base}/{$strategy}/{$scenario}/iter{$iter}";
            if (!is_dir($dir)) {
                echo "WARNING: Missing {$dir}\n";
                continue;
            }

            foreach ($vu_levels as $vu) {
                $files = glob("{$dir}/{$vu}vu-*-summary.json");
                if (empty($files)) {
                    echo "WARNING: No summary for {$strategy}/{$scenario}/iter{$iter}/{$vu}vu\n";
                    continue;
                }

                // Sort by filename (timestamp suffix) and take the last one (avoid duplicates)
                sort($files);
                $file = end($files);

                $json = json_decode(file_get_contents($file), true);
                if (!$json) {
                    echo "WARNING: Invalid JSON in {$file}\n";
                    continue;
                }

                // Extract http_req_duration average
                $req_duration = $json['metrics']['http_req_duration']['values'] ?? [];
                $avg_ms = $req_duration['avg'] ?? null;
                $p95_ms = $req_duration['p(95)'] ?? null;
                $p99_ms = $req_duration['p(99)'] ?? null;

                // Extract throughput (http_reqs / duration)
                $http_reqs = $json['metrics']['http_reqs']['values']['count'] ?? 0;
                // Calculate duration from stages
                $duration_sec = (60 + 300 + 30); // ramp-up + steady + ramp-down = 390s
                $throughput_rps = $duration_sec > 0 ? round($http_reqs / $duration_sec, 2) : 0;

                // Extract error rate
                $http_req_failed = $json['metrics']['http_req_failed']['values']['rate'] ?? 0;
                $error_rate_pct = round($http_req_failed * 100, 4);

                // Cache hit ratio — from the dedicated cache-hit-ratio file
                $cache_dir = dirname($file);
                $cache_hit_file = null;
                $hit_files = glob(str_replace('-summary.json', '-cache-hit-ratio.txt', $file));
                $cache_hit_pct = null;
                if (!empty($hit_files)) {
                    $content = trim(file_get_contents($hit_files[0]));
                    if (is_numeric($content)) {
                        $cache_hit_pct = round((float)$content, 2);
                    }
                }

                // Fallback: look for redis.txt cache hit ratio section
                if ($cache_hit_pct === null) {
                    $redis_files = glob(str_replace('-summary.json', '-redis.txt', $file));
                    if (!empty($redis_files)) {
                        $redis_content = file_get_contents($redis_files[0]);
                        if (preg_match('/Cache Hit Ratio\s*:\s*([\d.]+)%/', $redis_content, $m)) {
                            $cache_hit_pct = round((float)$m[1], 2);
                        }
                    }
                }

                // Handle no-cache: no cache operations
                if ($cache_hit_pct === null && $strategy === 'no-cache') {
                    $cache_hit_pct = 0;
                }

                if ($avg_ms !== null) {
                    $output[] = [
                        $strategy,
                        $scenario,
                        $vu,
                        $iter,
                        round($avg_ms, 2),
                        $p95_ms !== null ? round($p95_ms, 2) : '',
                        $p99_ms !== null ? round($p99_ms, 2) : '',
                        $throughput_rps,
                        $error_rate_pct,
                        $http_reqs,
                        $cache_hit_pct ?? '',
                    ];
                }
            }
        }
    }
}

// Write CSV
$csv_path = $base . '/iteration-data.csv';
$fp = fopen($csv_path, 'w');
foreach ($output as $row) {
    fputcsv($fp, $row, escape: '');
}
fclose($fp);

echo "Done. Wrote " . (count($output) - 1) . " rows to {$csv_path}\n";
echo "Columns: " . implode(', ', $output[0]) . "\n";
