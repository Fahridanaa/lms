<?php

namespace Tests\Unit;

use App\Services\BenchmarkResultsService;
use Tests\TestCase;

class BenchmarkResultsServiceTest extends TestCase
{
    public function test_it_parses_and_normalizes_benchmark_csv_rows(): void
    {
        $path = sys_get_temp_dir().'/lms-benchmark-results-service-test';

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path.'/metrics-summary.csv', implode(PHP_EOL, [
            'strategy,scenario,concurrent_users,avg_ms,p90_ms,p95_ms,p99_ms,max_ms,throughput_rps,error_rate_pct,unexpected_error_rate_pct,controlled_error_rate_pct,validity_status,http_reqs_total,cache_hit_ratio_pct,iterations_averaged,read_avg_ms,write_avg_ms,redis_mode',
            'no-cache,read-heavy,1500,300,330,350,400,500,100,9,4,5,valid,1000,0,5,300,400,single',
            'cache-aside,read-heavy,1500,100,120,140,160,200,180,8,0.4,7.6,valid,1000,80,5,100,120,single',
            'read-through,read-heavy,1500,110,130,150,170,210,170,8.2,0.8,7.4,valid,1000,75,5,110,150,single',
            'write-through,read-heavy,1500,120,140,160,180,220,160,8.5,1,7.5,valid,1000,70,5,120,100,single',
        ]));
        file_put_contents($path.'/resources-summary.csv', implode(PHP_EOL, [
            'strategy,scenario,concurrent_users,cpu_avg_pct,cpu_max_pct,mem_avg_mb,mem_max_mb,mem_avg_pct,mem_max_pct,disk_read_avg_mb_s,disk_read_max_mb_s,disk_write_avg_mb_s,disk_write_max_mb_s,iterations_averaged,redis_mode',
            'no-cache,read-heavy,1500,60,70,2000,2200,50,55,0.1,0.2,0.3,0.4,5,single',
            'cache-aside,read-heavy,1500,30,40,1000,1200,20,25,0.1,0.2,0.3,0.4,5,single',
            'read-through,read-heavy,1500,31,41,1050,1250,21,26,0.1,0.2,0.3,0.4,5,single',
            'write-through,read-heavy,1500,32,42,1100,1300,22,27,0.1,0.2,0.3,0.4,5,single',
        ]));
        file_put_contents($path.'/endpoint-summary.csv', implode(PHP_EOL, [
            'redis_mode,strategy,scenario,concurrent_users,endpoint_key,endpoint_label,method,path_pattern,operation_type,avg_ms,p90_ms,p95_ms,p99_ms,max_ms,iterations_averaged',
            'single,cache-aside,read-heavy,1500,gradebook_duration,Gradebook course,GET,/api/courses/{courseId}/gradebook,read,95.5,130.2,150.7,190.3,260.1,5',
        ]));
        file_put_contents($path.'/validity-summary.csv', implode(PHP_EOL, [
            'redis_mode,scenario,concurrent_users,strategy,valid,saturated,total_iterations',
            'single,read-heavy,1500,no-cache,5,0,5',
            'single,read-heavy,1500,cache-aside,5,0,5',
            'single,read-heavy,1500,read-through,5,0,5',
            'single,read-heavy,1500,write-through,5,0,5',
            'single,read-heavy,2000,no-cache,4,1,5',
            'single,read-heavy,2000,cache-aside,5,0,5',
            'single,read-heavy,2000,read-through,5,0,5',
            'single,read-heavy,2000,write-through,5,0,5',
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

        $this->assertSame('no-cache', $data['metrics'][0]['strategy']);
        $this->assertSame(1500, $data['metrics'][0]['concurrent_users']);
        $this->assertSame(300, $data['metrics'][0]['avg_ms']);
        $this->assertSame(9, $data['metrics'][0]['error_rate_pct']);
        $this->assertSame(4, $data['metrics'][0]['unexpected_error_rate_pct']);
        $this->assertSame(0, $data['metrics'][0]['cache_hit_ratio_pct']);
        $this->assertSame('gradebook_duration', $data['endpoints'][0]['endpoint_key']);
        $this->assertSame('/api/courses/{courseId}/gradebook', $data['endpoints'][0]['path_pattern']);
        $this->assertSame(95.5, $data['endpoints'][0]['avg_ms']);
        $this->assertTrue($data['anova'][0]['is_significant']);
        $this->assertTrue($data['tukey'][0]['reject']);
        $this->assertSame(['no-cache', 'cache-aside', 'read-through', 'write-through'], $data['strategies']);
        $this->assertSame(1500, $data['scoreSummary']['analysis_vu']);
        $this->assertSame(2000, $data['scoreSummary']['saturation_vu']);

        $group = collect($data['scoreSummary']['groups'])
            ->first(fn (array $group): bool => $group['redis_mode'] === 'single' && $group['scenario'] === 'read-heavy');

        $this->assertNotNull($group);
        $this->assertSame('cache-aside', $group['winner']['strategy']);
        $this->assertSame(3.8, $group['winner']['score']);
        $this->assertSame(20, $group['valid_iterations']);
        $this->assertSame(1, $group['saturated_iterations']);
        $this->assertSame(['cache-aside', 'read-through', 'write-through', 'no-cache'], array_column($group['rankings'], 'strategy'));
        $this->assertSame(4, $group['winner']['dimensions']['read_latency']['points']);
        $this->assertSame(1.0, $group['winner']['dimensions']['read_latency']['weighted_score']);

        $researchSummary = $data['researchSummary'];

        $this->assertSame(1500, $researchSummary['analysis_vu']);
        $this->assertSame(2000, $researchSummary['saturation_vu']);
        $this->assertSame('cache-aside', $researchSummary['overall_winner']['strategy']);
        $this->assertSame('Read Heavy', $researchSummary['overall_winner']['scenario_label']);
        $this->assertSame('Node Tunggal', $researchSummary['overall_winner']['redis_label']);
        $this->assertSame('cache-aside', $researchSummary['workload_winners'][0]['strategy']);
        $this->assertSame('read-heavy', $researchSummary['workload_winners'][0]['scenario']);
        $this->assertSame('cache-aside', $researchSummary['baseline_improvements'][0]['winner_strategy']);
        $this->assertSame(66.67, $researchSummary['baseline_improvements'][0]['latency_pct']);
        $this->assertSame(80.0, $researchSummary['baseline_improvements'][0]['throughput_pct']);
        $this->assertSame(90.0, $researchSummary['baseline_improvements'][0]['error_rate_pct']);
        $this->assertSame('cache-aside', $researchSummary['recommendation']['primary_strategy']);
        $this->assertCount(4, $researchSummary['trade_offs']);
        $this->assertCount(4, $researchSummary['threats_to_validity']);
    }
}
