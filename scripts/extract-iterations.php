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

function cacheHitRatioForSummary(string $summaryPath): ?float
{
    $hitRatioPath = str_replace('-summary.json', '-cache-hit-ratio.txt', $summaryPath);

    if (! is_file($hitRatioPath)) {
        return null;
    }

    $content = trim((string) file_get_contents($hitRatioPath));

    if (preg_match('/Cache Hit Ratio\s*:\s*([0-9]+(?:\.[0-9]+)?)%/', $content, $matches) === 1) {
        return round((float) $matches[1], 2);
    }

    if (is_numeric($content)) {
        return round((float) $content, 2);
    }

    return null;
}

foreach ($strategies as $strategy) {
    foreach ($scenarios as $scenario) {
        $dirs = glob("{$base}/{$strategy}/{$scenario}/iter*", GLOB_ONLYDIR) ?: [];
        usort($dirs, fn (string $left, string $right): int => (int) preg_replace('/\D+/', '', basename($left)) <=> (int) preg_replace('/\D+/', '', basename($right)));

        foreach ($dirs as $dir) {
            $iter = (int) preg_replace('/\D+/', '', basename($dir));

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

                $http_reqs = $json['metrics']['http_reqs']['values']['count'] ?? 0;
                $throughput_rps = round((float) ($json['metrics']['http_reqs']['values']['rate'] ?? 0), 2);

                // Extract error rate
                $http_req_failed = $json['metrics']['http_req_failed']['values']['rate'] ?? 0;
                $error_rate_pct = round($http_req_failed * 100, 4);

                $cache_hit_pct = cacheHitRatioForSummary($file);

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
