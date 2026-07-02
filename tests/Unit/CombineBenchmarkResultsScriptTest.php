<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CombineBenchmarkResultsScriptTest extends TestCase
{
    public function test_it_classifies_saturation_from_unexpected_errors(): void
    {
        $root = storage_path('framework/testing/combine-benchmark-results');
        $input = $root.'/input';
        $output = $root.'/output';

        File::deleteDirectory($root);
        File::ensureDirectoryExists($input.'/no-cache/read-heavy/iter1');
        File::ensureDirectoryExists($input.'/no-cache/read-heavy/iter2');

        file_put_contents($input.'/metrics-summary.csv', implode(PHP_EOL, [
            'strategy,scenario,concurrent_users,avg_ms,p90_ms,p95_ms,p99_ms,max_ms,throughput_rps,error_rate_pct,http_reqs_total,cache_hit_ratio_pct,iterations_averaged,read_avg_ms,write_avg_ms,redis_mode',
            'no-cache,read-heavy,100,100,110,120,130,140,50,10,100,0,2,100,100,single',
        ]));
        file_put_contents($input.'/resources-summary.csv', implode(PHP_EOL, [
            'strategy,scenario,concurrent_users,cpu_avg_pct,cpu_max_pct,mem_avg_mb,mem_max_mb,mem_avg_pct,mem_max_pct,disk_read_avg_mb_s,disk_read_max_mb_s,disk_write_avg_mb_s,disk_write_max_mb_s,iterations_averaged,redis_mode',
            'no-cache,read-heavy,100,10,20,100,120,10,12,0,0,0,0,2,single',
        ]));

        $this->writeSummary($input.'/no-cache/read-heavy/iter1/100vu-valid-summary.json', 0.10, 2);
        $this->writeSummary($input.'/no-cache/read-heavy/iter2/100vu-saturated-summary.json', 0.10, 8);

        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('scripts/combine-benchmark-results.php')),
            escapeshellarg($output),
            escapeshellarg($input),
        ), $commandOutput, $exitCode);

        $this->assertSame(0, $exitCode, implode(PHP_EOL, $commandOutput));

        $anovaRows = $this->readCsvRows($output.'/anova-input.csv');
        $validityRows = $this->readCsvRows($output.'/validity-summary.csv');
        $metricRows = $this->readCsvRows($output.'/metrics-summary.csv');

        $this->assertSame(['valid', 'saturated'], array_column($anovaRows, 'validity_status'));
        $this->assertSame('2', $anovaRows[0]['unexpected_error_rate_pct']);
        $this->assertSame('8', $anovaRows[1]['unexpected_error_rate_pct']);
        $this->assertSame('1', $validityRows[0]['valid']);
        $this->assertSame('1', $validityRows[0]['saturated']);
        $this->assertSame('5', $metricRows[0]['unexpected_error_rate_pct']);
        $this->assertSame('valid', $metricRows[0]['validity_status']);
    }

    private function writeSummary(string $path, float $httpFailedRate, int $unexpectedErrors): void
    {
        file_put_contents($path, json_encode([
            'metrics' => [
                'http_req_duration' => [
                    'values' => [
                        'avg' => 100,
                        'p(90)' => 110,
                        'p(95)' => 120,
                        'p(99)' => 130,
                        'max' => 140,
                    ],
                ],
                'http_reqs' => [
                    'values' => [
                        'rate' => 50,
                        'count' => 100,
                    ],
                ],
                'http_req_failed' => [
                    'values' => [
                        'rate' => $httpFailedRate,
                    ],
                ],
                'errors' => [
                    'values' => [
                        'passes' => $unexpectedErrors,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsvRows(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle, escape: '');
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }
}
