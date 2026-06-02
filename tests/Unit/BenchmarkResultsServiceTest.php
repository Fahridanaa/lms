<?php

namespace Tests\Unit;

use App\Services\BenchmarkResultsService;
use Tests\TestCase;

class BenchmarkResultsServiceTest extends TestCase
{
    public function test_it_parses_and_normalizes_benchmark_csv_rows(): void
    {
        $path = storage_path('framework/testing/benchmark-results');

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path.'/metrics-summary.csv', implode(PHP_EOL, [
            'strategy,scenario,concurrent_users,avg_ms,p90_ms,p95_ms,p99_ms,max_ms,throughput_rps,error_rate_pct,http_reqs_total,cache_hit_ratio_pct,iterations_averaged,read_avg_ms,write_avg_ms,redis_mode,validity_status',
            'cache-aside,read-heavy,1500,123.45,130.1,145.6,170.9,220.0,98.75,0.01,1000.5,71.2,5,110.4,140.8,single,valid',
        ]));
        file_put_contents($path.'/resources-summary.csv', implode(PHP_EOL, [
            'strategy,scenario,concurrent_users,cpu_avg_pct,cpu_max_pct,mem_avg_mb,mem_max_mb,mem_avg_pct,mem_max_pct,disk_read_avg_mb_s,disk_read_max_mb_s,disk_write_avg_mb_s,disk_write_max_mb_s,iterations_averaged,redis_mode',
            'cache-aside,read-heavy,1500,12.5,20.1,1400.7,1500.2,35.7,38.1,0.1,0.2,0.3,0.4,5,single',
        ]));
        file_put_contents($path.'/validity-summary.csv', implode(PHP_EOL, [
            'redis_mode,scenario,concurrent_users,strategy,valid,saturated,total_iterations',
            'single,read-heavy,1500,cache-aside,5,0,5',
        ]));
        file_put_contents($path.'/anova-results-1500vu.csv', implode(PHP_EOL, [
            'redis_mode,scenario,metric,target_vu,alpha,n_no_cache,n_cache_aside,n_read_through,n_write_through,f_statistic,p_value,is_significant,decision,skip_reason',
            'single,read-heavy,avg_ms,1500,0.05,5,5,5,5,25.1,0.000002,true,significant,',
        ]));
        file_put_contents($path.'/tukey-results-1500vu.csv', implode(PHP_EOL, [
            'redis_mode,scenario,metric,target_vu,group1,group2,meandiff,p-adj,lower,upper,reject',
            'single,read-heavy,avg_ms,1500,cache-aside,no-cache,100.5,0.001,50.1,150.8,true',
        ]));

        $data = (new BenchmarkResultsService($path))->dashboardData();

        $this->assertSame('cache-aside', $data['metrics'][0]['strategy']);
        $this->assertSame(1500, $data['metrics'][0]['concurrent_users']);
        $this->assertSame(123.45, $data['metrics'][0]['avg_ms']);
        $this->assertSame(71.2, $data['metrics'][0]['cache_hit_ratio_pct']);
        $this->assertTrue($data['anova'][0]['is_significant']);
        $this->assertTrue($data['tukey'][0]['reject']);
        $this->assertSame(['no-cache', 'cache-aside', 'read-through', 'write-through'], $data['strategies']);
    }
}
