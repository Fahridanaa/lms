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
            ->assertSee('aria-controls="benchmark-panel"', false)
            ->assertSee('aria-controls="result-panel"', false)
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
            ->assertSee('Kesimpulan performa strategi cache pada LMS Mini')
            ->assertSee('Executive Summary')
            ->assertSee('Improvement vs Baseline')
            ->assertSee('Best Strategy per Workload')
            ->assertSee('Single vs Cluster Insight')
            ->assertSee('Trade-Off Analysis')
            ->assertSee('Threats to Validity')
            ->assertSee('Keterbatasan penelitian')
            ->assertSee('Analisis statistik pada 1500 VU dengan data valid')
            ->assertSee('Significant Difference Matrix')
            ->assertSee('menjadi titik utama analisis inferensial')
            ->assertSee('tetap ditampilkan sebagai konteks saturasi')
            ->assertSee('Perbandingan Mode Node Tunggal')
            ->assertSee('Perbandingan Mode Cluster')
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
