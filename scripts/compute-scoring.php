<?php

/**
 * Scoring System — Proposal §7.11
 *
 * Usage: php scripts/compute-scoring.php [results_dir]
 *
 * Examples:
 *   php scripts/compute-scoring.php                         # benchmark-results/
 *   php scripts/compute-scoring.php benchmark-results-cluster # cluster results
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

$base = $argv[1] ?? __DIR__ . "/../benchmark-results";

// Strip trailing slash for consistency
$base = rtrim($base, "/");

$strategies = ["no-cache", "cache-aside", "read-through", "write-through"];
$scenarios = ["read-heavy", "write-heavy"];

$metrics_rows = loadCsv("{$base}/metrics-summary.csv");
$resources_rows = loadCsv("{$base}/resources-summary.csv");

// Aggregate per strategy + scenario + redis_mode: average across all VU levels
$data = [];
foreach ($strategies as $strategy) {
    foreach ($scenarios as $scenario) {
        // Detect available redis_modes in the data
        $redis_modes = ["single"]; // default
        $available_modes = array_unique(array_map(
            fn($r) => $r["redis_mode"] ?? "single",
            array_filter($metrics_rows, fn($r) => $r["strategy"] === $strategy && $r["scenario"] === $scenario),
        ));
        if (!empty($available_modes)) {
            $redis_modes = $available_modes;
        }

        foreach ($redis_modes as $redis_mode) {
            $filtered = array_values(
                array_filter($metrics_rows, fn($r) =>
                    $r["strategy"] === $strategy
                    && $r["scenario"] === $scenario
                    && ($r["redis_mode"] ?? "single") === $redis_mode
                ),
            );

            if (empty($filtered)) {
                continue;
            }

            $read_avgs = [];
            $write_avgs = [];
            $throughputs = [];
            $cache_hits = [];
            $errors = [];

            foreach ($filtered as $row) {
                if (is_numeric($row["read_avg_ms"] ?? null)) {
                    $read_avgs[] = (float) $row["read_avg_ms"];
                }
                if (is_numeric($row["write_avg_ms"] ?? null)) {
                    $write_avgs[] = (float) $row["write_avg_ms"];
                }
                if (is_numeric($row["cache_hit_ratio_pct"] ?? null)) {
                    $cache_hits[] = (float) $row["cache_hit_ratio_pct"];
                }
                if (is_numeric($row["error_rate_pct"] ?? null)) {
                    $errors[] = (float) $row["error_rate_pct"];
                }
                // Skalabilitas: throughput spesifik pada 2000 users (Proposal §7.8)
                // Hanya VU=2000 yang dipakai, bukan rata-rata semua VU levels.
                if ((int) ($row["concurrent_users"] ?? 0) === 2000 && is_numeric($row["throughput_rps"] ?? null)) {
                    $throughputs[] = (float) $row["throughput_rps"];
                }
            }

            // Resources (already per-VU-level averaged by analyze-resources.sh)
            $res_filtered = array_values(
                array_filter($resources_rows, fn($r) =>
                    $r["strategy"] === $strategy
                    && $r["scenario"] === $scenario
                    && ($r["redis_mode"] ?? "single") === $redis_mode
                ),
            );
            $cpus = array_map(fn($r) => (float) ($r["cpu_avg_pct"] ?? 0), $res_filtered);
            $mems = array_map(fn($r) => (float) ($r["mem_avg_mb"] ?? 0), $res_filtered);

            $data[$strategy][$scenario][$redis_mode] = [
                "performa_baca"        => count($read_avgs) > 0 ? array_sum($read_avgs) / count($read_avgs) : 0,
                "performa_tulis"       => count($write_avgs) > 0 ? array_sum($write_avgs) / count($write_avgs) : 0,
                "skalabilitas"        => count($throughputs) > 0 ? $throughputs[0] : 0,
                "efisiensi_cache"     => count($cache_hits) > 0 ? array_sum($cache_hits) / count($cache_hits) : 0,
                "resource_efficiency" =>
                    (count($cpus) > 0 ? array_sum($cpus) / count($cpus) : 0) +
                    ((count($mems) > 0 ? array_sum($mems) / count($mems) : 0) / 20),
                "keandalan"           => count($errors) > 0 ? array_sum($errors) / count($errors) : 0,
            ];
        }
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

// Collect unique redis_modes from data
$all_redis_modes = [];
foreach ($strategies as $s) {
    foreach ($scenarios as $sc) {
        if (isset($data[$s][$sc])) {
            $all_redis_modes = array_merge($all_redis_modes, array_keys($data[$s][$sc]));
        }
    }
}
$all_redis_modes = array_unique($all_redis_modes);
sort($all_redis_modes);

foreach (["read-heavy", "write-heavy"] as $scenario) {
    // Untuk setiap redis_mode, cetak tabel terpisah
    foreach ($all_redis_modes as $redis_mode) {
        // Skip jika tidak ada data untuk redis_mode ini
        $has_data = false;
        foreach ($strategies as $s) {
            if (isset($data[$s][$scenario][$redis_mode])) {
                $has_data = true;
                break;
            }
        }
        if (!$has_data) {
            continue;
        }

        $redis_label = $redis_mode === "cluster" ? " (Redis Cluster)" : " (Single Redis)";

        echo str_repeat("=", 110) . "\n";
        echo "  SCORING — " . strtoupper($scenario) . $redis_label . "\n";
        echo "  Proposal §7.11 — Per-workload scoring (from metrics-summary.csv)\n";
        echo str_repeat("=", 110) . "\n\n";

        // Rank
        // Rounding precision per dimension — matches display precision so ties reflect what the user sees
        $rank_precision = [
            "performa_baca" => 0,
            "performa_tulis" => 0,
            "skalabilitas" => 1,
            "efisiensi_cache" => 1,
            "resource_efficiency" => 0,
            "keandalan" => 2,
        ];
        $ranks = [];
        foreach ($dims as $dim) {
            $prec = $rank_precision[$dim];
            // Round values before grouping so displayed-identical values share the same point
            $groups = [];
            foreach ($strategies as $s) {
                if (!isset($data[$s][$scenario][$redis_mode][$dim])) {
                    continue;
                }
                $val = round($data[$s][$scenario][$redis_mode][$dim], $prec);
                $groups[(string) $val][] = $s;
            }

            if (empty($groups)) {
                continue;
            }

            // Sort group keys (ascending for lower-is-better, descending for higher-is-better)
            $unique_vals = array_keys($groups);
            if (in_array($dim, $lower_is_better)) {
                usort($unique_vals, fn($a, $b) => (float) $a <=> (float) $b);
            } else {
                usort($unique_vals, fn($a, $b) => (float) $b <=> (float) $a);
            }

            // Assign points per unique rank: 4, 3, 2, 1 (ties share the same point)
            $point = 4;
            foreach ($unique_vals as $val) {
                foreach ($groups[$val] as $s) {
                    $ranks[$s][$dim] = $point;
                }
                $point--;
            }
        }

        // Total
        $totals = [];
        foreach ($strategies as $s) {
            $t = 0;
            foreach ($weights as $dim => $w) {
                $t += ($ranks[$s][$dim] ?? 0) * $w;
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
        usort($order, fn($a, $b) => ($totals[$b] ?? 0) <=> ($totals[$a] ?? 0));

        foreach ($order as $s) {
            if (!isset($data[$s][$scenario][$redis_mode])) {
                continue;
            }
            echo str_pad(ucfirst($s), 15);
            foreach ($dims as $dim) {
                $v = $fmt_val($dim, $data[$s][$scenario][$redis_mode][$dim]);
                $pts = $ranks[$s][$dim] ?? "-";
                echo str_pad($v . " [" . $pts . "]", 16) . "  ";
            }
            echo number_format($totals[$s], 3) . "\n";
        }

        echo "\n  RANKING:\n";
        foreach ($order as $i => $s) {
            if (!isset($data[$s][$scenario][$redis_mode])) {
                continue;
            }
            $perf_baca = round($data[$s][$scenario][$redis_mode]["performa_baca"], 0);
            $perf_tulis = round($data[$s][$scenario][$redis_mode]["performa_tulis"], 0);
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
}
