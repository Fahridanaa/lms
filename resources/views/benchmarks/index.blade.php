<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Hasil Benchmark | {{ config('app.name', 'Laravel') }}</title>
        <meta name="theme-color" content="#08090b">

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
                        <button id="analysis-tab" type="button" role="tab" aria-controls="analysis-panel" data-tab-option="analysis">Analisis</button>
                    </div>
                </div>

                <form class="benchmark-controls" aria-label="Kontrol benchmark">
                    <fieldset class="segmented-field">
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

                    <div class="analysis-controls" data-analysis-controls>
                        <label>
                            <span>Mode Redis</span>
                            <select name="redis_mode" autocomplete="off" data-benchmark-control="redisMode">
                                @foreach ($benchmarkData['redisModes'] as $redisMode)
                                    <option value="{{ $redisMode }}" @selected($benchmarkData['defaults']['redisMode'] === $redisMode)>
                                        {{ $redisMode === 'single' ? 'Node Tunggal' : 'Cluster' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label>
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
                    <span>Tabel membandingkan latensi rata-rata, P95, P99, throughput, cache hit, tingkat error, CPU, dan memori.</span>
                </div>

                <section class="comparison-block" aria-labelledby="single-comparison-title">
                    <h2 id="single-comparison-title">Perbandingan Mode Node Tunggal</h2>
                    <div class="simulation-grid" aria-live="polite" data-simulation-mode="single"></div>
                </section>

                <section class="comparison-block" aria-labelledby="cluster-comparison-title">
                    <h2 id="cluster-comparison-title">Perbandingan Mode Cluster</h2>
                    <div class="simulation-grid" aria-live="polite" data-simulation-mode="cluster"></div>
                </section>
            </section>

            <section id="analysis-panel" class="benchmark-tab-panel" role="tabpanel" aria-labelledby="analysis-tab" data-tab-panel="analysis" hidden>
                <div class="section-heading">
                    <p>Lapisan bukti</p>
                    <h1 id="analysis-tab-title">Analisis statistik pada 1500 VU dengan data valid.</h1>
                    <span>ANOVA dan Tukey memakai 1500 VU sebagai pembanding utama; 2000 VU dipakai untuk diskusi saturasi dan skalabilitas.</span>
                </div>

                <section class="analysis-brief" aria-label="Ringkasan metodologi analisis">
                    <p>
                        <strong>1500 VU</strong>
                        menjadi titik utama analisis inferensial karena seluruh strategi masih memiliki minimal tiga iterasi valid untuk dibandingkan secara statistik.
                    </p>
                    <p>
                        <strong>2000 VU</strong>
                        ditampilkan sebagai bukti saturasi, bukan dasar ANOVA, karena beberapa kondisi melewati batas tingkat error 5%.
                    </p>
                </section>

                <section class="benchmark-overview" aria-label="Ringkasan temuan benchmark">
                    <article>
                        <span>Titik analisis utama</span>
                        <strong>1500 VU</strong>
                        <small>Hanya baris valid, tingkat error <= 5%</small>
                    </article>
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

                <section class="score-summary" aria-labelledby="score-summary-title">
                    <div class="score-summary-heading">
                        <div>
                            <p>Ringkasan Hasil</p>
                            <h2 id="score-summary-title">Strategi paling optimal pada titik stabil 1500 VU.</h2>
                        </div>
                        <span>Skor memakai bobot proposal; 2000 VU tetap dipakai sebagai bukti saturasi, bukan dasar pemeringkatan.</span>
                    </div>
                    <div class="score-summary-grid" aria-live="polite" data-score-summary></div>
                </section>

                <section class="analysis-grid" aria-label="Grafik analisis benchmark">
                    <article class="benchmark-panel benchmark-panel-full">
                        <div class="panel-header">
                            <div>
                                <span>Tren skalabilitas</span>
                                <h2 data-line-title>Tren latensi rata-rata</h2>
                            </div>
                            <div class="strategy-legend" aria-label="Legenda strategi">
                                @foreach ($benchmarkData['strategyLabels'] as $strategy => $label)
                                    <span data-strategy="{{ $strategy }}">{{ $label }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="chart-frame" aria-live="polite" data-chart="line"></div>
                    </article>

                    <article class="benchmark-panel">
                        <div class="panel-header">
                            <div>
                                <span>Perbandingan 1500 VU</span>
                                <h2 data-bar-title>Perbandingan 1500 VU</h2>
                            </div>
                        </div>
                        <div class="chart-frame compact" aria-live="polite" data-chart="bar"></div>
                    </article>

                    <article class="benchmark-panel benchmark-panel-dark">
                        <div class="panel-header">
                            <div>
                                <span>Validitas 1500 VU</span>
                                <h2>Tingkat error dan cache hit</h2>
                            </div>
                        </div>
                        <div class="metric-table" aria-live="polite" data-reliability-table></div>
                    </article>

                    <article class="benchmark-panel">
                        <div class="panel-header">
                            <div>
                                <span>Resource 1500 VU</span>
                                <h2>Tekanan CPU</h2>
                            </div>
                        </div>
                        <div class="chart-frame compact" aria-live="polite" data-chart="cpu"></div>
                    </article>

                    <article class="benchmark-panel">
                        <div class="panel-header">
                            <div>
                                <span>Resource 1500 VU</span>
                                <h2>Jejak memori</h2>
                            </div>
                        </div>
                        <div class="chart-frame compact" aria-live="polite" data-chart="memory"></div>
                    </article>

                    <article class="benchmark-panel benchmark-panel-wide">
                        <div class="panel-header">
                            <div>
                                <span>Saturasi 2000 VU</span>
                                <h2>Iterasi valid vs saturasi</h2>
                            </div>
                        </div>
                        <div class="metric-table" aria-live="polite" data-saturation-table></div>
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
