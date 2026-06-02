<?php

/**
 * Scoring System — Proposal §7.11
 *
 * Variant: Skalabilitas menggunakan rata-rata throughput dari SEMUA VU levels
 *          (100, 250, 500, 750, 1000, 1500, 2000), bukan hanya 2000 users.
 *
 * Sources:
 *   - metrics-summary.csv   (from analyze-results.sh)  — k6 metrics per strategy/scenario/VU
 *   - resources-summary.csv (from analyze-resources.sh) — CPU/Memory per strategy/scenario/VU
 *
 * All per-iteration averaging is done upstream; this script only aggregates
 * across VU levels to produce one score per (strategy × scenario).
 *
 * Rank 1 (terbaik): 4 poin, Rank 2: 3, Rank 3: 2, Rank 4: 1
 * Total = Σ(Rank × Bobot)
 */

function loadCsv(string $path): array
{
    $rows = [];
    if (($fp = fopen($path, "r")) !== false) {
        $headers = fgetcsv($fp, escape: "");
        while (($data = fgetcsv($fp, escape: "")) !== false) {
            if (count($data) === count($headers)) {
                $rows[] = array_combine($headers, $data);
            }
        }
        fclose($fp);
    }
    return $rows;
}

$base = __DIR__ . "/../benchmark-results";
$strategies = ["no-cache", "cache-aside", "read-through", "write-through"];
$scenarios = ["read-heavy", "write-heavy"];

$metrics_rows = loadCsv("{$base}/metrics-summary.csv");
$resources_rows = loadCsv("{$base}/resources-summary.csv");

// Aggregate per strategy + scenario: average across all VU levels
$data = [];
foreach ($strategies as $strategy) {
    foreach ($scenarios as $scenario) {
        $filtered = array_values(
            array_filter($metrics_rows, fn($r) => $r["strategy"] === $strategy && $r["scenario"] === $scenario),
        );

        $read_avgs = [];
        $write_avgs = [];
        $throughputs = [];
        $cache_hits = [];
        $errors = [];

        foreach ($filtered as $row) {
            if (is_numeric($row["read_avg_ms"])) {
                $read_avgs[] = (float) $row["read_avg_ms"];
            }
            if (is_numeric($row["write_avg_ms"])) {
                $write_avgs[] = (float) $row["write_avg_ms"];
            }
            if (is_numeric($row["cache_hit_ratio_pct"])) {
                $cache_hits[] = (float) $row["cache_hit_ratio_pct"];
            }
            if (is_numeric($row["error_rate_pct"])) {
                $errors[] = (float) $row["error_rate_pct"];
            }
            // Skalabilitas: rata-rata throughput dari semua VU levels
            if (is_numeric($row["throughput_rps"])) {
                $throughputs[] = (float) $row["throughput_rps"];
            }
        }

        // Resources (already per-VU-level averaged by analyze-resources.sh)
        $res_filtered = array_values(
            array_filter($resources_rows, fn($r) => $r["strategy"] === $strategy && $r["scenario"] === $scenario),
        );
        $cpus = array_map(fn($r) => (float) $r["cpu_avg_pct"], $res_filtered);
        $mems = array_map(fn($r) => (float) $r["mem_avg_mb"], $res_filtered);

        $data[$strategy][$scenario] = [
            "performa_baca"        => count($read_avgs) > 0 ? array_sum($read_avgs) / count($read_avgs) : 0,
            "performa_tulis"       => count($write_avgs) > 0 ? array_sum($write_avgs) / count($write_avgs) : 0,
            "skalabilitas"        => count($throughputs) > 0 ? array_sum($throughputs) / count($throughputs) : 0,
            "efisiensi_cache"     => count($cache_hits) > 0 ? array_sum($cache_hits) / count($cache_hits) : 0,
            "resource_efficiency" =>
                (count($cpus) > 0 ? array_sum($cpus) / count($cpus) : 0) +
                ((count($mems) > 0 ? array_sum($mems) / count($mems) : 0) / 20),
            "keandalan"           => count($errors) > 0 ? array_sum($errors) / count($errors) : 0,
        ];
    }
}

// ── Scoring ──
$dims = ["performa_baca", "performa_tulis", "skalabilitas", "efisiensi_cache", "resource_efficiency", "keandalan"];
$lower_is_better = ["performa_baca", "performa_tulis", "resource_efficiency", "keandalan"];
$higher_is_better = ["skalabilitas", "efisiensi_cache"];

$weights = [
    "performa_baca" => 0.25,
    "performa_tulis" => 0.2,
    "skalabilitas" => 0.2,
    "efisiensi_cache" => 0.15,
    "resource_efficiency" => 0.1,
    "keandalan" => 0.1,
];

$dim_labels = [
    "performa_baca" => "Perf.Baca (25%)",
    "performa_tulis" => "Perf.Tulis (20%)",
    "skalabilitas" => "Skalabilitas (20%)",
    "efisiensi_cache" => "Ef.Cache (15%)",
    "resource_efficiency" => "Res.Eff (10%)",
    "keandalan" => "Keandalan (10%)",
];

$fmt_val = function ($dim, $val) {
    return match ($dim) {
        "performa_baca", "performa_tulis" => round((float) $val, 0) . "ms",
        "skalabilitas" => round((float) $val, 1) . " RPS",
        "efisiensi_cache" => round((float) $val, 1) . "%",
        "resource_efficiency" => round((float) $val, 1),
        "keandalan" => round((float) $val, 2) . "%",
    };
};

foreach (["read-heavy", "write-heavy"] as $scenario) {
    echo str_repeat("=", 110) . "\n";
    echo "  SCORING — " . strtoupper($scenario) . "\n";
    echo "  Proposal §7.11 — Per-workload scoring (from metrics-summary.csv)\n";
    echo str_repeat("=", 110) . "\n\n";

    // Rank
    $ranks = [];
    foreach ($dims as $dim) {
        $order = $strategies;
        if (in_array($dim, $lower_is_better)) {
            usort($order, fn($a, $b) => $data[$a][$scenario][$dim] <=> $data[$b][$scenario][$dim]);
        } else {
            usort($order, fn($a, $b) => $data[$b][$scenario][$dim] <=> $data[$a][$scenario][$dim]);
        }
        foreach ($order as $rank => $strategy) {
            $ranks[$strategy][$dim] = 4 - $rank;
        }
    }

    // Total
    $totals = [];
    foreach ($strategies as $s) {
        $t = 0;
        foreach ($weights as $dim => $w) {
            $t += $ranks[$s][$dim] * $w;
        }
        $totals[$s] = round($t, 3);
    }

    // Table
    echo str_pad("Strategi", 15);
    foreach ($dim_labels as $l) {
        echo str_pad($l, 16) . "  ";
    }
    echo "Total\n";
    echo str_repeat("-", 110) . "\n";

    $order = $strategies;
    usort($order, fn($a, $b) => $totals[$b] <=> $totals[$a]);

    foreach ($order as $s) {
        echo str_pad(ucfirst($s), 15);
        foreach ($dims as $dim) {
            $v = $fmt_val($dim, $data[$s][$scenario][$dim]);
            echo str_pad($v . " [" . $ranks[$s][$dim] . "]", 16) . "  ";
        }
        echo number_format($totals[$s], 3) . "\n";
    }

    echo "\n  RANKING:\n";
    foreach ($order as $i => $s) {
        $perf_baca = round($data[$s][$scenario]["performa_baca"], 0);
        $perf_tulis = round($data[$s][$scenario]["performa_tulis"], 0);
        echo "    #" .
            ($i + 1) .
            ". " .
            ucfirst($s) .
            " — Score: " .
            number_format($totals[$s], 3) .
            " (Read: {$perf_baca}ms, Write: {$perf_tulis}ms)\n";
    }
    echo "\n\n";
}
