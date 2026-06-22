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

        @php
            $moodleFlowcharts = require resource_path('benchmark/moodle-flowcharts.php');
        @endphp

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
                        <button id="compare-tab" type="button" role="tab" aria-controls="compare-panel" data-tab-option="compare">Subset Moodle</button>
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
                    <h1 id="result-tab-title">Kesimpulan performa strategi cache pada LMS Purwarupa.</h1>
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

            <section id="compare-panel" class="benchmark-tab-panel" role="tabpanel" aria-labelledby="compare-tab" data-tab-panel="compare" hidden>
                <div class="section-heading">
                    <p>Bukti subset Moodle</p>
                    <h1 id="compare-tab-title">LMS Purwarupa ini mengambil jalur cache-hot Moodle, bukan seluruh Moodle.</h1>
                    <span>Perbandingan ini diturunkan dari checkout Moodle lokal 5.3dev di ~/Projects/moodle/public: schema install.xml, external API, dan module code Moodle.</span>
                </div>

                <div class="result-report-layout">
                    <nav class="result-toc" aria-label="Daftar isi perbandingan Moodle">
                        <span>Subset</span>
                        <a href="#moodle-source-evidence">1. Evidence Moodle</a>
                        <a href="#moodle-flowcharts">2. Flowchart endpoint</a>
                        <a href="#moodle-erd">3. ERD subset</a>
                        <a href="#moodle-read-heavy-flow">4. Alur beban baca</a>
                        <a href="#moodle-write-heavy-flow">5. Alur beban tulis</a>
                        <a href="#moodle-out-of-scope">6. Yang dipangkas</a>
                    </nav>

                    <div class="result-report">
                        <section id="moodle-source-evidence" class="report-section" aria-labelledby="moodle-source-evidence-title">
                            <div class="report-section-heading">
                                <span>Source evidence</span>
                                <h2 id="moodle-source-evidence-title">Moodle yang dibandingkan adalah Moodle 5.3dev, bukan asumsi umum.</h2>
                            </div>
                            <div class="benchmark-overview result-overview" aria-label="Ringkasan bukti Moodle lokal">
                                <article>
                                    <span>Versi Moodle lokal</span>
                                    <strong>5.3dev</strong>
                                    <small>public/version.php: release 5.3dev, branch 503, build 20260605.</small>
                                </article>
                                <article>
                                    <span>Schema Moodle</span>
                                    <strong>85 install.xml</strong>
                                    <small>Core + module + plugin schema; LMS Purwarupa hanya mengambil subset yang disentuh k6.</small>
                                </article>
                                <article>
                                    <span>Scope benchmark</span>
                                    <strong>Cache strategy</strong>
                                    <small>Fitur masuk hanya jika memengaruhi read, write, authorization, completion, grade, atau invalidation.</small>
                                </article>
                            </div>
                            <div class="simulation-table-wrap">
                                <table class="simulation-table">
                                    <caption>Bukti source Moodle yang dipakai untuk membandingkan subset</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Area</th>
                                            <th scope="col">Moodle source yang dicek</th>
                                            <th scope="col">Makna untuk LMS Purwarupa</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th scope="row">Course read</th>
                                            <td>course/externallib.php::get_course_contents(), lib/modinfolib.php::get_fast_modinfo(), availability/classes/info_module.php</td>
                                            <td>LMS Purwarupa mengambil course structure + module availability + completion state sebagai read path utama.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Assignment write/read</th>
                                            <td>mod/assign/externallib.php, mod/assign/locallib.php::save_submission(), submit_for_grading(), view_grading_table()</td>
                                            <td>LMS Purwarupa mengambil submission, grading, marker allocation, return/reopen, dan override yang memicu invalidasi.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Quiz attempt</th>
                                            <td>mod/quiz/classes/external.php::start_attempt(), process_attempt(), get_attempt_review()</td>
                                            <td>LMS Purwarupa mengambil attempt lifecycle dan result read, tetapi tidak mengambil full Moodle question engine.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Resource/file</th>
                                            <td>mod/resource/db/install.xml, lib/db/install.xml::files, pluginfile.php/file.php</td>
                                            <td>LMS Purwarupa mengambil material metadata/download sebagai cacheable content read dan completion-triggering access.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Gradebook/access</th>
                                            <td>lib/db/install.xml::grade_items, grade_grades, grade_categories, context, role_assignments, role_capabilities, enrol, user_enrolments</td>
                                            <td>LMS Purwarupa mengambil grade aggregation dan authorization hot path yang memengaruhi response dan cache key.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section id="moodle-flowcharts" class="report-section" aria-labelledby="moodle-flowcharts-title">
                            <div class="report-section-heading">
                                <span>Flowchart Mermaid</span>
                                <h2 id="moodle-flowcharts-title">Flowchart per endpoint: alur Moodle penuh dan alur LMS Purwarupa.</h2>
                            </div>
                            <div class="analysis-brief" aria-label="Petunjuk flowchart Mermaid">
                                <p><strong>Format:</strong> setiap endpoint punya dua diagram terpisah: alur Moodle dan alur LMS Purwarupa. Sumber disimpan di <code>resources/benchmark/moodle-flowcharts.php</code>.</p>
                                <p><strong>Tujuan:</strong> pembaca tidak perlu membaca lane paralel. Mereka bisa melihat urutan Moodle dulu, lalu urutan LMS Purwarupa yang menjadi subset benchmark.</p>
                            </div>
                            <div class="flowchart-list" aria-label="Daftar flowchart endpoint Moodle dan LMS Purwarupa">
                                @foreach ($moodleFlowcharts as $flowchart)
                                    <details class="flowchart-card" @if ($loop->first) open @endif>
                                        <summary>
                                            <span>{{ $flowchart['scenario'] }}</span>
                                            <strong>{{ $flowchart['weight'] }} {{ $flowchart['endpoint'] }}</strong>
                                        </summary>
                                        <div class="flowchart-pair">
                                            <article>
                                                <h3>Alur Moodle</h3>
                                                <pre class="mermaid">{{ $flowchart['moodle_diagram'] }}</pre>
                                            </article>
                                            <article>
                                                <h3>Alur LMS Purwarupa</h3>
                                                <pre class="mermaid">{{ $flowchart['lms_diagram'] }}</pre>
                                            </article>
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        </section>

                        <section id="moodle-erd" class="report-section" aria-labelledby="moodle-erd-title">
                            <div class="report-section-heading">
                                <span>ERD subset</span>
                                <h2 id="moodle-erd-title">ERD LMS Purwarupa sejajar dengan tabel Moodle yang dipakai workload.</h2>
                            </div>
                            <div class="simulation-table-wrap">
                                <table class="simulation-table">
                                    <caption>Pemetaan ERD LMS Purwarupa ke ERD Moodle lokal</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Domain</th>
                                            <th scope="col">Tabel LMS Purwarupa</th>
                                            <th scope="col">Tabel Moodle lokal</th>
                                            <th scope="col">Status subset</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th scope="row">Course tree</th>
                                            <td>courses, course_sections, learning_modules, materials, quizzes, assignments</td>
                                            <td>course, course_sections, course_modules, modules, resource, quiz, assign</td>
                                            <td>Diambil. LMS Purwarupa mengganti plugin module registry Moodle dengan tiga activity yang memang di-benchmark.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Enrolment</th>
                                            <td>course_enrollments, course_enrolment_methods</td>
                                            <td>enrol, user_enrolments</td>
                                            <td>Disederhanakan. Moodle memisahkan enrol plugin instance dan user enrolment; LMS Purwarupa menjaga efek access/cache tanpa semua plugin enrolment.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Authorization</th>
                                            <td>contexts, roles, role_assignments, role_capabilities, capabilities</td>
                                            <td>context, role, role_assignments, role_capabilities, role_allow_assign, role_allow_override, role_allow_switch</td>
                                            <td>Diambil sebagian. Inherited context dan capability check ada; role override/switch/admin matrix Moodle tidak diambil.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Availability/group</th>
                                            <td>module_availability_rules, course_groups, course_group_members, course_groupings, course_grouping_groups, quiz_overrides, assignment_overrides</td>
                                            <td>availability subsystem, groups, groups_members, groupings, groupings_groups, quiz_overrides, assign_overrides</td>
                                            <td>Diambil sebagai fixed rules: hidden, date, group/grouping, prerequisite, min grade. Full Moodle availability tree tidak diambil.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Completion</th>
                                            <td>module_completions, course_completions, course_completion_criteria, course_completion_criterion_completions</td>
                                            <td>course_modules_completion, course_completions, course_completion_criteria, course_completion_crit_compl, course_completion_defaults</td>
                                            <td>Diambil. LMS Purwarupa mempertahankan progress invalidation, tanpa default/admin/cron reaggregation penuh Moodle.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Gradebook</th>
                                            <td>grade_categories, grade_items, grades, grade_histories, grade_item_histories, grade_category_histories, gradebook_recalculations</td>
                                            <td>grade_categories, grade_items, grade_grades, grade_categories_history, grade_items_history, grade_grades_history</td>
                                            <td>Diambil untuk aggregation dan invalidation. Formula, outcomes, scales, dan seluruh report surface Moodle dipangkas.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Assignment</th>
                                            <td>assignments, submissions, assignment_marks, assignment_allocated_markers, assignment_overrides, file_records</td>
                                            <td>assign, assign_submission, assign_grades, assign_mark, assign_allocated_marker, assign_overrides, assign_plugin_config, assign_user_flags, assign_user_mapping</td>
                                            <td>Diambil untuk submit/grade/marker. Submission/feedback plugin architecture Moodle dipangkas.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Quiz</th>
                                            <td>quizzes, questions, quiz_question_slots, quiz_attempts, quiz_attempt_questions, quiz_attempt_steps, quiz_attempt_step_data, quiz_grades, quiz_overrides</td>
                                            <td>quiz, quiz_slots, quiz_sections, quiz_attempts, quiz_grades, quiz_overrides, quiz_feedback, question engine tables</td>
                                            <td>Diambil untuk lifecycle attempt. Full question bank/type engine dan review behavior lengkap Moodle dipangkas.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Files/material</th>
                                            <td>materials, file_records</td>
                                            <td>resource, files</td>
                                            <td>Disederhanakan. LMS Purwarupa owner-based; Moodle content-addressed dengan contextid/component/filearea/contenthash/pathnamehash.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section id="moodle-read-heavy-flow" class="report-section" aria-labelledby="moodle-read-heavy-flow-title">
                            <div class="report-section-heading">
                                <span>Read-heavy 80/20</span>
                                <h2 id="moodle-read-heavy-flow-title">Alur beban baca dibandingkan per endpoint k6.</h2>
                            </div>
                            <div class="simulation-table-wrap">
                                <table class="simulation-table">
                                    <caption>Read-heavy endpoint flow: LMS Purwarupa vs Moodle</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Bobot dan endpoint</th>
                                            <th scope="col">Flow LMS Purwarupa</th>
                                            <th scope="col">Flow Moodle yang sepadan</th>
                                            <th scope="col">Simplifikasi yang dipangkas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th scope="row">25% GET /api/courses/{courseId}/structure</th>
                                            <td>Resolve actor dari header, cek course:view via context/role atau enrolment fallback, cache key course:{courseId}:structure:{actorId}, load sections - learning_modules - availability_rules, batch readability, batch completion, batch grades untuk min_grade, batch group/grouping membership, lalu attach material/quiz/assignment summary.</td>
                                            <td>Moodle memakai core_course_external::get_course_contents() dan get_fast_modinfo() untuk course, course_sections, course_modules, modules, availability/classes/info_module.php, dan completion_info. Ini adalah padanan course page/modinfo read.</td>
                                            <td>LMS Purwarupa tidak mengambil block renderer, theme output, arbitrary module plugin rendering, stealth sections penuh, delegated sections penuh, atau availability JSON tree lengkap.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">10% GET /api/materials/{id}</th>
                                            <td>MaterialService mengambil material shared dari cache material:{id}, lalu access check tetap berjalan per actor melalui assertActivityAvailableForRead() agar cache tidak membocorkan module hidden/restricted.</td>
                                            <td>Moodle resource view membaca mod_resource instance, course module context, availability, capability, dan file metadata dari files sebelum konten disajikan lewat file API.</td>
                                            <td>LMS Purwarupa mengambil resource/material sebagai metadata tunggal, bukan repository plugins, draft file area, content bank, filter pipeline, atau full file browser.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% GET /api/quizzes/{id}</th>
                                            <td>QuizService cache quiz:{id}:with-questions, load course + question slots, controller cek enrolment/capability + module availability sebelum response.</td>
                                            <td>Moodle quiz view memakai pengaturan quiz, course module, access manager, quiz slots, referensi question, dan capability check sebelum attempt dapat dimulai.</td>
                                            <td>LMS Purwarupa tidak mengambil full question bank, random question behavior penuh, all question types, accessrule plugins lengkap, atau review option matrix penuh.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">8% GET /api/assignments/{id}</th>
                                            <td>AssignmentService cache assignment:{id}, load course + learning module, controller cek actor bisa membaca activity via course access dan availability.</td>
                                            <td>Moodle assign view membaca assign instance, course module, assign_submission/user flags, availability, capability, dan status submission user.</td>
                                            <td>LMS Purwarupa tidak mengambil assign submission plugins UI, feedback plugins UI, grading form UI, teams submission penuh, atau workflow UI Moodle.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">12% GET /api/courses/{id}/gradebook</th>
                                            <td>GradebookController hanya untuk instructor/capability gradebook:view. GradebookService cache course:{id}:gradebook:instructor, mark recalculated, load active students, grade_items, weighted averages, grades per student, category tree.</td>
                                            <td>Moodle gradebook membaca grade_items, grade_grades, grade_categories, grade_item/grade_grade classes, grade reports, visibility/lock state, dan capability grade:viewall atau grading permissions.</td>
                                            <td>LMS Purwarupa tidak mengambil semua grade reports, formula editor penuh, outcomes, scales, letters UI, aggregation UI, atau full gradebook setup surface.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% GET /api/users/{id}/grades</th>
                                            <td>Self-view cache user:{id}:grades:student-visible dan filter hidden grade_items. Instructor-view cache memasukkan instructor id dan scope hanya course yang dia ajar.</td>
                                            <td>Moodle user grade report membaca grade_grades lewat grade_items/course context dengan grade visibility, hidden/locked items, dan permissions per course.</td>
                                            <td>LMS Purwarupa tidak mengambil semua user report format, natural/weighted aggregation option penuh, export, outcomes, atau report plugin variants.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">3% GET /api/courses/{id}/materials</th>
                                            <td>MaterialService cache course:{id}:materials:actor:{actorId}, load materials course, batch module readability, batch completion/grade/group rules, filter material yang available untuk actor.</td>
                                            <td>Moodle course contents/resource listing datang dari course module list plus mod_resource instances, availability filtering, completion info, dan capability checks.</td>
                                            <td>LMS Purwarupa hanya list material activity; Moodle dapat menampilkan semua activity/resource types, sections rendering, blocks, filters, dan completion UI penuh.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">2% GET /api/courses/{id}/completion</th>
                                            <td>CourseCompletionController cek canReadCourse, CourseCompletionService cache course_completion:progress:{courseId}:{userId}, load criteria, criterion completions, course_completions, module/grade/date criterion titles.</td>
                                            <td>Moodle completion_info di lib/completionlib.php membaca course_completion_criteria, course_completion_crit_compl, course_completions, dan course_modules_completion.</td>
                                            <td>LMS Purwarupa tidak mengambil default completion settings penuh, cron reaggregation detail, manual/admin override surface, atau aggregation method matrix penuh.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">7% Expected failure: restricted/hidden/unavailable</th>
                                            <td>k6 memilih target group-restricted, grouping-restricted, prerequisite-locked, min-grade-locked, hidden, suspended, atau non-enrolled. LMS Purwarupa mengembalikan 403/404 dari CourseAccessService/ModuleAvailabilityService, dan k6 menandainya expected_response:false.</td>
                                            <td>Moodle melakukan hal serupa lewat enrol/user_enrolments, context role/capability, course module visible flag, availability API, group/grouping restrictions, completion and grade conditions.</td>
                                            <td>LMS Purwarupa tidak membuat random invalid traffic; kegagalan tetap relationship-valid agar benchmark menguji kontrol akses Moodle-like, bukan noise error.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">3% GET /api/quizzes/{id}/attempts/{id}/result</th>
                                            <td>QuizController load cached attempt result, validasi attempt milik quiz, actor harus owner atau instructor course, response dari normalized quiz_attempt_questions/steps/step_data.</td>
                                            <td>Moodle mod_quiz_external::get_attempt_review() membaca quiz_attempts dan question attempt data melalui question engine, dengan review permissions dan ownership checks.</td>
                                            <td>LMS Purwarupa mengambil normalized attempt detail, tetapi tidak mengambil review layout penuh, per-question behaviour plugins, manual grading UI, atau all review option rules.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">10% POST /api/quizzes/{id}/attempts</th>
                                            <td>StartAttemptQuizRequest validasi input, QuizService cek active quiz/course, module availability, quiz:attempt capability, effective user/group override, max attempts, lalu transaksi membuat quiz_attempt, quiz_attempt_questions, quiz_attempt_steps, dan flush tag user:{id}:attempts.</td>
                                            <td>Moodle mod_quiz_external::start_attempt() dan quiz_attempt::start_attempt() membuat attempt berdasarkan quiz settings, overrides, access manager, slots, dan question usage.</td>
                                            <td>LMS Purwarupa tidak mengambil full question usage engine, randomization kompleks, accessrule plugin lengkap, password/SEB/proctoring rules penuh.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">10% POST /api/assignments/{id}/submissions</th>
                                            <td>SubmitAssignmentRequest validasi file_path, AssignmentService cek active assignment/course, module availability, assignment:submit capability, effective deadline/group override, latest submission, max attempts, late flag, lalu transaksi menandai submission lama bukan latest dan membuat submission baru.</td>
                                            <td>Moodle mod_assign_external::save_submission() dan submit_for_grading() memproses assign_submission, plugin submission data, due/cutoff/override, attempt number, and submission status.</td>
                                            <td>LMS Purwarupa tidak mengambil submission plugin architecture, online text/file plugin payload penuh, statement acceptance, team submissions penuh, atau grading workflow UI lengkap.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section id="moodle-write-heavy-flow" class="report-section" aria-labelledby="moodle-write-heavy-flow-title">
                            <div class="report-section-heading">
                                <span>Write-heavy 40/60</span>
                                <h2 id="moodle-write-heavy-flow-title">Alur beban tulis dibandingkan per endpoint k6.</h2>
                            </div>
                            <div class="simulation-table-wrap">
                                <table class="simulation-table">
                                    <caption>Write-heavy endpoint flow: LMS Purwarupa vs Moodle</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Bobot dan endpoint</th>
                                            <th scope="col">Flow LMS Purwarupa</th>
                                            <th scope="col">Flow Moodle yang sepadan</th>
                                            <th scope="col">Efek cache / simplifikasi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th scope="row">10% GET /api/courses/{courseId}/structure</th>
                                            <td>Sama seperti beban baca, tetapi dipakai sebagai baseline state sebelum tekanan tulis. Cache key tetap actor-specific dan completion-aware.</td>
                                            <td>Moodle course page/modinfo read sebelum user melakukan activity write.</td>
                                            <td>Mengukur apakah strategi cache menjaga latensi baca saat beban tulis mulai menekan invalidation.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">10% GET /api/courses/{courseId}/gradebook</th>
                                            <td>Instructor gradebook read memakai cached weighted aggregation dan stale marker recalculation.</td>
                                            <td>Moodle gradebook instructor report membaca grade_items/grade_grades/category hierarchy setelah assignment/quiz grade berubah.</td>
                                            <td>Mengukur read model yang paling terdampak grade writes tanpa membawa semua grade report plugins.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% GET /api/assignments/{id} or material/quiz</th>
                                            <td>k6 memilih satu activity detail. LMS Purwarupa menjalankan cache shared object + actor-specific access check untuk assignment/material/quiz.</td>
                                            <td>Moodle activity view untuk assign/resource/quiz: course module context, availability, capability, instance table, and user state.</td>
                                            <td>Menggabungkan tiga detail reads yang punya pola cache serupa, tanpa seluruh Moodle activity catalog.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% GET /api/users/{id}/grades or performance</th>
                                            <td>Grades memakai actor-aware cache dan hidden-item filtering. Performance summary membaca ringkasan nilai user sendiri.</td>
                                            <td>Moodle user grade report dan course/user grade summaries berbasis grade_grades + grade_items visibility.</td>
                                            <td>Performance summary adalah ringkasan lokal, bukan clone report Moodle; jalur grade visibility tetap yang dibandingkan.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% GET course structure again after write cascade</th>
                                            <td>k6 membaca structure, menjalankan material download yang menandai completion, lalu membaca structure lagi. CourseStructureService harus melihat completion/availability terbaru atau cache invalidation gagal.</td>
                                            <td>Moodle modinfo/course page sensitif pada course_modules_completion dan availability condition, sehingga completion write dapat mengubah module availability di course view.</td>
                                            <td>Ini jalur paling langsung untuk membuktikan cache consistency setelah write, bukan sekadar throughput write.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% Controlled failures</th>
                                            <td>Restricted/suspended/non-enrolled/locked-grade target sengaja menghasilkan 403/404 dan diberi tag expected failure di k6.</td>
                                            <td>Check capability/enrolment/availability/grade lock di Moodle juga menghasilkan penolakan untuk actor yang tidak valid.</td>
                                            <td>Failure tetap bagian dari flow LMS realistis, bukan error acak; threshold k6 memisahkan expected dari unexpected failure.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">20% POST /api/assignments/{id}/submissions</th>
                                            <td>AssignmentService membuat latest submission baru, menghitung attempt_number, late flag, max_attempts, deadline override, dan menonaktifkan latest submission lama dalam transaksi.</td>
                                            <td>Moodle assign_submission update via save_submission()/submit_for_grading() dengan due/cutoff, attempts, and submission status.</td>
                                            <td>Write ini menekan cache assignment detail, submission list, course structure/progress, dan gradebook-related downstream reads.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">15% POST quiz attempt -> PUT submit answers</th>
                                            <td>Start attempt membuat attempt + attempt questions + initial steps. Submit answers validasi owner/quiz, simpan answers, score attempt, update quiz grade, completion, dan flush cache terkait user/quiz/gradebook.</td>
                                            <td>Moodle start_attempt() + process_attempt() membuat quiz_attempt/question usage, memproses responses, state transitions, marks, and grade update.</td>
                                            <td>LMS Purwarupa mengambil lifecycle dan invalidation, tetapi memotong behaviour engine dan question type ecosystem penuh.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% GET /api/materials/{id}/download</th>
                                            <td>Walau HTTP method GET, endpoint ini write-like: access check per actor, firstOrCreate file_records, completeForMaterial() untuk student, lalu completion/course progress cache invalidation.</td>
                                            <td>Moodle resource access/file serving dapat memicu completion_info::update_state() untuk activity completion dan memengaruhi course completion/availability.</td>
                                            <td>Dipakai sebagai lightweight Moodle-like progress write, bukan download throughput/file streaming benchmark.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% PUT /api/submissions/{id}/grade</th>
                                            <td>Instructor authorization canGradeSubmission, update submission score/feedback/status, create/update Grade/GradeHistory, trigger completion on grade criterion, flush gradebook/user/course caches.</td>
                                            <td>Moodle assign grading flow menulis assign_grades dan memanggil grade_update() untuk gradebook grade_grades.</td>
                                            <td>LMS Purwarupa mempertahankan gradebook invalidation, tetapi tidak membawa advanced grading form/rubric UI penuh.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">5% PUT /api/submissions/{id}/marker-grade</th>
                                            <td>Allocated marker menulis AssignmentMark, lalu assignment multi_mark_method menentukan final grade/submission update.</td>
                                            <td>Moodle assign mendukung marker allocation dan grading workflow melalui assign_user_flags, assign_allocated_marker, assign_mark, dan assign_grades.</td>
                                            <td>Diambil karena marker workflow menambah write complexity; dipangkas dari workflow UI dan plugin grading lengkap.</td>
                                        </tr>
                                        <tr>
                                            <th scope="row">10% PUT /api/grades/{id}</th>
                                            <td>GradebookService updateGrade validasi instructor/capability, grade item lock/visibility, score max, simpan history, mark recalculation, onGradeUpdate completion, dan invalidate gradebook/user caches.</td>
                                            <td>Moodle grade_update() dan grade_grade/grade_item classes mengelola grade_grades, hidden/locked state, history, regrading, dan gradebook cache/report effects.</td>
                                            <td>Ini direct gradebook write untuk cache strategy; LMS Purwarupa tidak mengambil formula engine, outcomes/scales penuh, atau gradebook setup UI.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section id="moodle-out-of-scope" class="report-section" aria-labelledby="moodle-out-of-scope-title">
                            <div class="report-section-heading">
                                <span>Removed complexity</span>
                                <h2 id="moodle-out-of-scope-title">Yang dipangkas adalah platform Moodle, bukan jalur cache-hot yang diuji.</h2>
                            </div>
                            <section class="analysis-brief" aria-label="Batas fitur Moodle yang tidak diambil">
                                <p><strong>Tidak diambil dari checkout Moodle lokal:</strong> blocks, blog, badges, calendar, cohort UI, communication, competency, contentbank, H5P, LTI, forum, wiki, workshop, SCORM, lesson, book, choice, feedback, glossary, URL/page/folder modules, messaging, payment, portfolio, repository plugins, search engines, reportbuilder, analytics, theme system, backup/restore, webservice surface penuh, dan admin tool plugins.</p>
                                <p><strong>Kenapa valid untuk tesis:</strong> k6 tidak menyentuh area itu. Memasukkannya akan membuat project terlihat lebih seperti Moodle, tetapi tidak memperkuat perbandingan strategi cache karena tidak mengubah hit/miss, latency, invalidation, atau consistency pada endpoint yang diukur.</p>
                            </section>
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

        <script type="module">
            if (document.querySelector('.mermaid')) {
                import('https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs')
                    .then(({ default: mermaid }) => {
                        mermaid.initialize({
                            startOnLoad: false,
                            theme: 'base',
                            themeVariables: {
                                primaryColor: '#fffaf0',
                                primaryTextColor: '#0b1b43',
                                primaryBorderColor: '#0b1b43',
                                lineColor: '#9a3733',
                                fontFamily: 'Outfit, ui-sans-serif, system-ui, sans-serif',
                            },
                        });

                        return mermaid.run({ querySelector: '.mermaid' });
                    })
                    .catch(() => {});
            }
        </script>
    </body>
</html>
