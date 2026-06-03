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
            ->assertSee('Analisis')
            ->assertSee('aria-controls="benchmark-panel"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertDontSee('About')
            ->assertDontSee('CSV-derived playback')
            ->assertDontSee('Simulasi beban')
            ->assertDontSee('Playback sampai')
            ->assertSee('Perbandingan strategi cache berdasarkan VU terpilih')
            ->assertSee('Analisis statistik pada 1500 VU dengan data valid')
            ->assertSee('2000 VU dipakai untuk diskusi saturasi dan skalabilitas')
            ->assertSee('menjadi titik utama analisis inferensial')
            ->assertSee('ditampilkan sebagai bukti saturasi')
            ->assertSee('Ringkasan Hasil')
            ->assertSee('Strategi paling optimal pada titik stabil 1500 VU')
            ->assertSee('Skor memakai bobot proposal')
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
            ->assertSee('Saturation')
            ->assertSee('ANOVA')
            ->assertSee('Tukey HSD')
            ->assertSee('Iterasi valid vs saturasi');
    }

    public function test_benchmark_legacy_route_redirects_to_canonical_root(): void
    {
        $this->get('/benchmarks')->assertRedirect('/');
    }
}
