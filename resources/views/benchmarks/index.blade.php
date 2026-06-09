<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Hasil Benchmark | {{ config('app.name', 'Laravel') }}</title>
        <meta name="theme-color" content="#f8f9fa">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=outfit:400,500,600,700|space-grotesk:500,600,700|jetbrains-mono:400,500,700" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            @php
                $benchmarkCss = preg_replace('/\A.*?(?=@layer components)/s', '', file_get_contents(resource_path('css/app.css')));
                $benchmarkJs = str_replace("import './bootstrap';\n\n", '', file_get_contents(resource_path('js/app.js')));
            @endphp

            <style>
                {!! $benchmarkCss !!}
            </style>
        @endif
    </head>
    <body class="benchmark-page">
        <a class="skip-link" href="#konten-utama">Lewati ke konten utama</a>

        <script>
            window.benchmarkData = @js($benchmarkData);
        </script>

        <main id="konten-utama" class="benchmark-shell" data-benchmark-dashboard>
            <header class="benchmark-console">
                <div class="benchmark-titlebar">
                    <a class="benchmark-brand" href="{{ route('benchmarks.index') }}" aria-label="Dashboard benchmark">
                        <span class="benchmark-brand-mark" aria-hidden="true"></span>
                        Benchmark Tesis
                    </a>

                    <div class="benchmark-tabs" role="tablist" aria-label="Tampilan benchmark">
                        <button id="benchmark-tab" type="button" role="tab" aria-controls="benchmark-panel" data-tab-option="benchmark">Benchmark</button>
                        <button id="result-tab" type="button" role="tab" aria-controls="result-panel" data-tab-option="result">Hasil</button>
                        <button id="statistics-tab" type="button" role="tab" aria-controls="statistics-panel" data-tab-option="statistics">Statistik</button>
                    </div>
                </div>

                <form class="benchmark-controls" aria-label="Kontrol benchmark">
                    <fieldset class="segmented-field" data-scenario-controls>
                        <legend>Skenario</legend>
                        @foreach ($benchmarkData['scenarios'] as $scenario)
                            <button type="button" data-scenario-option="{{ $scenario }}">
                                {{ $scenario === 'read-heavy' ? 'Read Heavy' : 'Write Heavy' }}
                            </button>
                        @endforeach
                    </fieldset>

                    <fieldset class="vu-field" data-vu-controls>
                        <legend>Virtual user</legend>
                        <div class="vu-options">
                            @foreach ($benchmarkData['concurrentUsers'] as $concurrentUsers)
                                <button type="button" data-vu-option="{{ $concurrentUsers }}">
                                    {{ number_format($concurrentUsers, 0, ',', '.') }}
                                </button>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="analysis-controls" data-filter-controls>
                        <label data-redis-controls>
                            <span>Mode Redis</span>
                            <select name="redis_mode" autocomplete="off" data-benchmark-control="redisMode">
                                @foreach ($benchmarkData['redisModes'] as $redisMode)
                                    <option value="{{ $redisMode }}" @selected($benchmarkData['defaults']['redisMode'] === $redisMode)>
                                        {{ $redisMode === 'single' ? 'Node Tunggal' : 'Cluster' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label data-metric-controls>
                            <span>Metrik</span>
                            <select name="metric" autocomplete="off" data-benchmark-control="metric">
                                @foreach ($benchmarkData['metricOptions'] as $metric => $label)
                                    <option value="{{ $metric }}" @selected($benchmarkData['defaults']['metric'] === $metric)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </form>
            </header>

            <section id="benchmark-panel" class="benchmark-tab-panel" role="tabpanel" aria-labelledby="benchmark-tab" data-tab-panel="benchmark">
                <div class="section-heading">
                    <p>Perbandingan benchmark</p>
                    <h1 id="benchmark-tab-title">Perbandingan strategi cache berdasarkan VU terpilih.</h1>
                    <span>Eksplorasi data mentah untuk workload, virtual user, mode Redis, dan metrik benchmark.</span>
                </div>

                <section class="analysis-grid benchmark-chart-grid" aria-label="Grafik benchmark mentah">
                    <article class="benchmark-panel benchmark-panel-full">
                        <div class="panel-header">
                            <div>
                                <span>Tren 100-2000 VU</span>
                                <h2 data-benchmark-line-title>Tren latensi rata-rata lintas mode Redis</h2>
                            </div>
                            <div class="strategy-legend" aria-label="Legenda strategi">
                                @foreach ($benchmarkData['strategyLabels'] as $strategy => $label)
                                    <span data-strategy="{{ $strategy }}">{{ $label }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="mode-chart-grid">
                            <div>
                                <h3>Node Tunggal</h3>
                                <div class="chart-frame compact" aria-live="polite" data-chart="benchmark-line-single"></div>
                            </div>
                            <div>
                                <h3>Cluster</h3>
                                <div class="chart-frame compact" aria-live="polite" data-chart="benchmark-line-cluster"></div>
                            </div>
                        </div>
                    </article>

                    <article class="benchmark-panel">
                        <div class="panel-header">
                            <div>
                                <span>VU terpilih</span>
                                <h2 data-benchmark-bar-single-title>Node Tunggal</h2>
                            </div>
                        </div>
                        <div class="chart-frame compact" aria-live="polite" data-chart="benchmark-bar-single"></div>
                    </article>

                    <article class="benchmark-panel">
                        <div class="panel-header">
                            <div>
                                <span>VU terpilih</span>
                                <h2 data-benchmark-bar-cluster-title>Cluster</h2>
                            </div>
                        </div>
                        <div class="chart-frame compact" aria-live="polite" data-chart="benchmark-bar-cluster"></div>
                    </article>
                </section>

                <section class="comparison-block" aria-labelledby="single-comparison-title">
                    <h2 id="single-comparison-title">Perbandingan Mode Node Tunggal</h2>
                    <div class="simulation-grid" aria-live="polite" data-simulation-mode="single"></div>
                </section>

                <section class="comparison-block" aria-labelledby="cluster-comparison-title">
                    <h2 id="cluster-comparison-title">Perbandingan Mode Cluster</h2>
                    <div class="simulation-grid" aria-live="polite" data-simulation-mode="cluster"></div>
                </section>

                <section class="comparison-block" aria-labelledby="endpoint-comparison-title">
                    <h2 id="endpoint-comparison-title">Analisis Endpoint API</h2>
                    <div class="endpoint-analysis-heading">
                        <div>
                            <span>Endpoint-level dari k6 summary</span>
                            <h3 data-endpoint-title>Latensi endpoint API pada filter terpilih</h3>
                        </div>
                        <div class="strategy-legend" aria-label="Legenda strategi endpoint">
                            @foreach ($benchmarkData['strategyLabels'] as $strategy => $label)
                                <span data-strategy="{{ $strategy }}">{{ $label }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="endpoint-chart-grid" aria-live="polite" data-endpoint-charts></div>
                    <article class="benchmark-panel benchmark-panel-full endpoint-table-panel">
                        <div class="panel-header">
                            <div>
                                <span>Data endpoint</span>
                                <h2>Tabel latensi endpoint berdasarkan mode Redis dan strategi</h2>
                            </div>
                        </div>
                        <div class="metric-table" aria-live="polite" data-endpoint-table></div>
                    </article>
                </section>
            </section>

            <section id="result-panel" class="benchmark-tab-panel" role="tabpanel" aria-labelledby="result-tab" data-tab-panel="result" hidden>
                <div class="section-heading">
                    <p>Hasil penelitian</p>
                    <h1 id="result-tab-title">Kesimpulan performa strategi cache pada LMS Mini.</h1>
                    <span>Bagian ini membaca data benchmark sebagai laporan penelitian: temuan utama, rekomendasi, dan keterbatasan.</span>
                </div>

                <div class="result-report-layout">
                    <nav class="result-toc" aria-label="Daftar isi hasil penelitian">
                        <span>Results</span>
                        <a href="#ringkasan-eksekutif">1. Executive Summary</a>
                        <a href="#peningkatan-baseline">2. Improvement vs Baseline</a>
                        <a href="#pemenang-workload">3. Best Strategy per Workload</a>
                        <a href="#insight-redis">4. Redis Single vs Cluster</a>
                        <a href="#analisis-trade-off">5. Trade-Off Analysis</a>
                        <a href="#rekomendasi-akhir">6. Final Recommendation</a>
                        <a href="#threats-validity">7. Threats to Validity</a>
                    </nav>

                    <div class="result-report">
                        <section id="ringkasan-eksekutif" class="report-section" aria-labelledby="ringkasan-eksekutif-title">
                            <div class="report-section-heading">
                                <span>Executive Summary</span>
                                <h2 id="ringkasan-eksekutif-title">Strategi terbaik dan konteks pengujiannya.</h2>
                            </div>
                            <div class="research-hero" aria-live="polite" data-research-overall-winner></div>
                            <div class="benchmark-overview result-overview" aria-label="Ringkasan titik penelitian">
                                <article>
                                    <span>Titik analisis utama</span>
                                    <strong>{{ number_format($benchmarkData['researchSummary']['analysis_vu'], 0, ',', '.') }} VU</strong>
                                    <small>Dasar pemeringkatan dan inferensi utama</small>
                                </article>
                                <article>
                                    <span>Bukti saturasi</span>
                                    <strong>{{ number_format($benchmarkData['researchSummary']['saturation_vu'], 0, ',', '.') }} VU</strong>
                                    <small>Konteks skalabilitas, bukan dasar ANOVA</small>
                                </article>
                            </div>
                        </section>

                        <section id="peningkatan-baseline" class="report-section" aria-labelledby="peningkatan-baseline-title">
                            <div class="report-section-heading">
                                <span>Improvement vs Baseline</span>
                                <h2 id="peningkatan-baseline-title">Dampak strategi terbaik dibanding Tanpa Cache.</h2>
                            </div>
                            <div class="score-summary-grid" aria-live="polite" data-baseline-improvements></div>
                        </section>

                        <section id="pemenang-workload" class="report-section" aria-labelledby="pemenang-workload-title">
                            <div class="report-section-heading">
                                <span>Best Strategy per Workload</span>
                                <h2 id="pemenang-workload-title">Strategi terbaik untuk Read Heavy dan Write Heavy.</h2>
                            </div>
                            <div class="score-summary-grid" aria-live="polite" data-workload-winners></div>
                        </section>

                        <section id="insight-redis" class="report-section" aria-labelledby="insight-redis-title">
                            <div class="report-section-heading">
                                <span>Single vs Cluster Insight</span>
                                <h2 id="insight-redis-title">Perbandingan mode Redis pada titik stabil dan saturasi.</h2>
                            </div>
                            <div class="score-summary-grid" aria-live="polite" data-redis-mode-insights></div>
                        </section>

                        <section id="analisis-trade-off" class="report-section" aria-labelledby="analisis-trade-off-title">
                            <div class="report-section-heading">
                                <span>Trade-Off Analysis</span>
                                <h2 id="analisis-trade-off-title">Kekuatan dan konsekuensi tiap strategi.</h2>
                            </div>
                            <div class="score-summary-grid" aria-live="polite" data-trade-off-analysis></div>
                        </section>

                        <section id="rekomendasi-akhir" class="report-section" aria-labelledby="rekomendasi-akhir-title">
                            <div class="report-section-heading">
                                <span>Research Conclusion</span>
                                <h2 id="rekomendasi-akhir-title">Rekomendasi implementasi.</h2>
                            </div>
                            <div aria-live="polite" data-final-recommendation></div>
                        </section>

                        <section id="threats-validity" class="report-section" aria-labelledby="threats-validity-title">
                            <div class="report-section-heading">
                                <span>Threats to Validity</span>
                                <h2 id="threats-validity-title">Keterbatasan penelitian.</h2>
                            </div>
                            <div aria-live="polite" data-threats-to-validity></div>
                        </section>
                    </div>
                </div>
            </section>

            <section id="statistics-panel" class="benchmark-tab-panel" role="tabpanel" aria-labelledby="statistics-tab" data-tab-panel="statistics" hidden>
                <div class="section-heading">
                    <p>Lapisan bukti</p>
                    <h1 id="statistics-tab-title">Analisis statistik pada 1500 VU dengan data valid.</h1>
                    <span>ANOVA dan Tukey menjawab apakah perbedaan strategi signifikan pada workload dan mode Redis yang sama.</span>
                </div>

                <section class="analysis-brief" aria-label="Ringkasan metodologi analisis">
                    <p>
                        <strong>1500 VU</strong>
                        menjadi titik utama analisis inferensial karena seluruh strategi masih memiliki minimal tiga iterasi valid untuk dibandingkan secara statistik.
                    </p>
                    <p>
                        <strong>2000 VU</strong>
                        tetap ditampilkan sebagai konteks saturasi, tetapi tidak digunakan sebagai dasar ANOVA.
                    </p>
                </section>

                <section class="benchmark-overview" aria-label="Ringkasan statistik">
                    <article>
                        <span>Strategi valid</span>
                        <strong aria-live="polite" data-summary="validStrategies">-</strong>
                        <small aria-live="polite" data-summary="validStrategiesValue">Cakupan pada 1500 VU</small>
                    </article>
                    <article>
                        <span>Metrik signifikan</span>
                        <strong aria-live="polite" data-summary="significance">-</strong>
                        <small>ANOVA pada 1500 VU</small>
                    </article>
                    <article>
                        <span>Saturasi 2000 VU</span>
                        <strong aria-live="polite" data-summary="saturation">-</strong>
                        <small aria-live="polite" data-summary="saturationValue">Hanya deskriptif</small>
                    </article>
                </section>

                <section class="statistics-layout" aria-label="Tabel statistik">
                    <article class="benchmark-panel">
                        <div class="panel-header">
                            <div>
                                <span>ANOVA pada 1500 VU</span>
                                <h2>Strategi dalam mode Redis dan workload yang sama</h2>
                            </div>
                        </div>
                        <div class="metric-table" aria-live="polite" data-anova-table></div>
                    </article>

                    <article class="benchmark-panel">
                        <div class="panel-header">
                            <div>
                                <span>Tukey HSD pada 1500 VU</span>
                                <h2>Selisih pasangan strategi untuk metrik terpilih</h2>
                            </div>
                        </div>
                        <div class="metric-table" aria-live="polite" data-tukey-table></div>
                    </article>

                    <article class="benchmark-panel benchmark-panel-full">
                        <div class="panel-header">
                            <div>
                                <span>Significant Difference Matrix</span>
                                <h2>Ringkasan keputusan Tukey untuk metrik terpilih</h2>
                            </div>
                        </div>
                        <div class="significance-matrix-wrap" aria-live="polite" data-significance-matrix></div>
                    </article>
                </section>
            </section>
        </main>

        @unless (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            <script>
                {!! $benchmarkJs !!}
            </script>
        @endunless
    </body>
</html>
