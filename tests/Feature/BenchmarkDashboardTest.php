<?php

namespace Tests\Feature;

use Tests\TestCase;

class BenchmarkDashboardTest extends TestCase
{
    public function test_benchmark_dashboard_renders_results_context(): void
    {
        $response = $this->get('/');

        $response
            ->assertStatus(200)
            ->assertSee('Benchmark Tesis')
            ->assertSee('Benchmark')
            ->assertSee('Hasil')
            ->assertSee('Statistik')
            ->assertSee('Subset Moodle')
            ->assertSee('aria-controls="benchmark-panel"', false)
            ->assertSee('aria-controls="result-panel"', false)
            ->assertSee('aria-controls="compare-panel"', false)
            ->assertSee('aria-controls="statistics-panel"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('<meta name="theme-color" content="#f8f9fa">', false)
            ->assertSee('--bench-primary: #0b1b43', false)
            ->assertSee('--bench-yellow: #fec115', false)
            ->assertSee("'write-through': '#9a3733'", false)
            ->assertDontSee('About')
            ->assertDontSee('CSV-derived playback')
            ->assertDontSee('Simulasi beban')
            ->assertDontSee('Playback sampai')
            ->assertSee('Perbandingan strategi cache berdasarkan VU terpilih')
            ->assertSee('Eksplorasi data mentah')
            ->assertSee('Tren 100-2000 VU')
            ->assertSee('Kesimpulan performa strategi cache pada LMS Purwarupa')
            ->assertSee('Executive Summary')
            ->assertSee('Improvement vs Baseline')
            ->assertSee('Best Strategy per Workload')
            ->assertSee('Single vs Cluster Insight')
            ->assertSee('Trade-Off Analysis')
            ->assertSee('Threats to Validity')
            ->assertSee('Keterbatasan penelitian')
            ->assertSee('LMS Purwarupa ini mengambil jalur cache-hot Moodle, bukan seluruh Moodle')
            ->assertSee('Moodle yang dibandingkan adalah Moodle 5.3dev')
            ->assertSee('ERD LMS Purwarupa sejajar dengan tabel Moodle yang dipakai workload')
            ->assertSee('Flowchart Mermaid')
            ->assertSee('resources/benchmark/moodle-flowcharts.php')
            ->assertSee('flowchart TD')
            ->assertSee('Alur Moodle')
            ->assertSee('Alur LMS Purwarupa')
            ->assertDontSee('Removed Moodle complexity')
            ->assertSee('mermaid.run', false)
            ->assertSee('Alur beban baca dibandingkan per endpoint k6')
            ->assertSee('25% GET /api/courses/{courseId}/structure')
            ->assertSee('Request masuk ke course/view.php atau core_course_external::get_course_contents')
            ->assertSee('10% POST /api/quizzes/{id}/attempts')
            ->assertSee('Alur beban tulis dibandingkan per endpoint k6')
            ->assertSee('15% POST quiz attempt -> PUT submit answers', false)
            ->assertSee('10% PUT /api/grades/{id}')
            ->assertSee('Tidak diambil dari checkout Moodle lokal')
            ->assertSee('Analisis statistik pada 1500 VU dengan data valid')
            ->assertSee('Significant Difference Matrix')
            ->assertSee('menjadi titik utama analisis inferensial')
            ->assertSee('tetap ditampilkan sebagai konteks saturasi')
            ->assertSee('Perbandingan Mode Node Tunggal')
            ->assertSee('Perbandingan Mode Cluster')
            ->assertSee('Analisis Endpoint API')
            ->assertSee('Endpoint-level dari k6 summary')
            ->assertSee('data-endpoint-charts', false)
            ->assertSee("'read-heavy': [", false)
            ->assertSee("'write-heavy': [", false)
            ->assertSee('P99')
            ->assertDontSee('Best latency sampai VU ini')
            ->assertDontSee('Best throughput sampai VU ini')
            ->assertSee('Tanpa Cache')
            ->assertSee('Cache Aside')
            ->assertSee('Read Heavy')
            ->assertSee('1500 VU')
            ->assertSee('Saturasi 2000 VU')
            ->assertSee('ANOVA')
            ->assertSee('Tukey HSD')
            ->assertSee('data-significance-matrix', false);
    }

    public function test_benchmark_legacy_route_redirects_to_canonical_root(): void
    {
        $this->get('/benchmarks')->assertRedirect('/');
    }
}
