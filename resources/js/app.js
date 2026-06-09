import './bootstrap';

const dashboard = document.querySelector('[data-benchmark-dashboard]');

if (dashboard && window.benchmarkData) {
    const data = window.benchmarkData;
    const params = new URLSearchParams(window.location.search);
    const state = {
        tab: params.get('tab') || 'benchmark',
        scenario: params.get('scenario') || data.defaults.scenario,
        redisMode: params.get('redis_mode') || data.defaults.redisMode,
        concurrentUsers: Number(params.get('concurrent_users') || data.defaults.concurrentUsers),
        metric: params.get('metric') || data.defaults.metric,
    };

    const ANALYSIS_VU = data.researchSummary?.analysis_vu ?? 1500;
    const SATURATION_VU = data.researchSummary?.saturation_vu ?? 2000;
    const simulationStrategies = data.strategies;
    const tabs = ['benchmark', 'result', 'statistics'];
    const strategyColors = {
        'no-cache': '#f05424',
        'cache-aside': '#b98200',
        'read-through': '#0b1b43',
        'write-through': '#9a3733',
    };
    const scenarioEndpointKeys = {
        'read-heavy': [
            'quiz_detail_duration',
            'materials_list_duration',
            'material_detail_duration',
            'gradebook_duration',
            'start_attempt_duration',
            'submit_assignment_duration',
        ],
        'write-heavy': [
            'assignment_detail_duration',
            'gradebook_duration',
            'submit_assignment_duration',
            'submit_quiz_duration',
            'grade_submission_duration',
        ],
    };
    const metricUnits = {
        avg_ms: 'ms',
        p90_ms: 'ms',
        p95_ms: 'ms',
        p99_ms: 'ms',
        max_ms: 'ms',
        throughput_rps: 'rps',
        cache_hit_ratio_pct: '%',
        error_rate_pct: '%',
        cpu_avg_pct: '%',
        mem_avg_mb: 'MB',
    };

    validateState();
    bindControls();
    renderDashboard();

    function validateState() {
        if (state.tab === 'analysis') {
            state.tab = 'statistics';
        }

        if (!tabs.includes(state.tab)) {
            state.tab = 'benchmark';
        }

        if (!data.scenarios.includes(state.scenario)) {
            state.scenario = data.defaults.scenario;
        }

        if (!data.redisModes.includes(state.redisMode)) {
            state.redisMode = data.defaults.redisMode;
        }

        if (!data.concurrentUsers.includes(state.concurrentUsers)) {
            state.concurrentUsers = Number(data.defaults.concurrentUsers);
        }

        if (!Object.keys(data.metricOptions).includes(state.metric)) {
            state.metric = data.defaults.metric;
        }
    }

    function bindControls() {
        dashboard.querySelectorAll('[data-tab-option]').forEach((button) => {
            button.addEventListener('click', () => {
                state.tab = button.dataset.tabOption;
                syncUrl();
                renderDashboard();
            });

            button.addEventListener('keydown', (event) => {
                const currentIndex = tabs.indexOf(button.dataset.tabOption);
                const nextIndex = tabIndexFromKey(event.key, currentIndex);

                if (nextIndex === currentIndex) {
                    return;
                }

                event.preventDefault();
                state.tab = tabs[nextIndex];
                syncUrl();
                renderDashboard();
                dashboard.querySelector(`[data-tab-option="${state.tab}"]`)?.focus();
            });
        });

        dashboard.querySelectorAll('[data-scenario-option]').forEach((button) => {
            button.addEventListener('click', () => {
                state.scenario = button.dataset.scenarioOption;
                syncUrl();
                renderDashboard();
            });
        });

        dashboard.querySelectorAll('[data-vu-option]').forEach((button) => {
            button.addEventListener('click', () => {
                state.concurrentUsers = Number(button.dataset.vuOption);
                syncUrl();
                renderDashboard();
            });
        });

        dashboard.querySelectorAll('[data-benchmark-control]').forEach((control) => {
            control.addEventListener('change', () => {
                state[control.dataset.benchmarkControl] = control.value;
                syncUrl();
                renderDashboard();
            });
        });
    }

    function renderDashboard() {
        syncControls();
        renderTabs();
        renderBenchmark();
        renderResult();
        renderStatistics();
    }

    function renderTabs() {
        dashboard.querySelectorAll('[data-tab-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.tabPanel !== state.tab;
        });

        dashboard.querySelectorAll('[data-tab-option]').forEach((button) => {
            const isActive = button.dataset.tabOption === state.tab;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', String(isActive));
            button.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        toggle('[data-scenario-controls]', state.tab !== 'result');
        toggle('[data-vu-controls]', state.tab === 'benchmark');
        toggle('[data-redis-controls]', state.tab === 'statistics');
        toggle('[data-metric-controls]', state.tab !== 'result');
        toggle('[data-filter-controls]', state.tab !== 'result');
    }

    function tabIndexFromKey(key, currentIndex) {
        if (key === 'ArrowRight' || key === 'ArrowDown') {
            return (currentIndex + 1) % tabs.length;
        }

        if (key === 'ArrowLeft' || key === 'ArrowUp') {
            return (currentIndex - 1 + tabs.length) % tabs.length;
        }

        if (key === 'Home') {
            return 0;
        }

        if (key === 'End') {
            return tabs.length - 1;
        }

        return currentIndex;
    }

    function renderBenchmark() {
        renderSimulationMode('single');
        renderSimulationMode('cluster');
        renderLineChart('[data-chart="benchmark-line-single"]', 'single');
        renderLineChart('[data-chart="benchmark-line-cluster"]', 'cluster');
        renderEndpointAnalysis();
        renderBarChart(
            '[data-chart="benchmark-bar-single"]',
            filterRowsAtVu(data.metrics, 'single', state.concurrentUsers),
            state.metric,
            `Node Tunggal ${formatNumber(state.concurrentUsers)} VU`,
        );
        renderBarChart(
            '[data-chart="benchmark-bar-cluster"]',
            filterRowsAtVu(data.metrics, 'cluster', state.concurrentUsers),
            state.metric,
            `Cluster ${formatNumber(state.concurrentUsers)} VU`,
        );

        text('[data-benchmark-line-title]', `Tren ${data.metricOptions[state.metric].toLowerCase()} lintas mode Redis`);
        text('[data-benchmark-bar-single-title]', `Node Tunggal ${formatNumber(state.concurrentUsers)} VU`);
        text('[data-benchmark-bar-cluster-title]', `Cluster ${formatNumber(state.concurrentUsers)} VU`);
        text('[data-endpoint-title]', `${endpointMetricLabel()} endpoint API pada ${formatNumber(state.concurrentUsers)} VU`);
    }

    function renderSimulationMode(redisMode) {
        const container = dashboard.querySelector(`[data-simulation-mode="${redisMode}"]`);

        if (!container) {
            return;
        }

        container.innerHTML = simulationTable(redisMode);
    }

    function simulationTable(redisMode) {
        const rows = simulationStrategies.map((strategy) => {
            const metricRow = selectedMetricRow(redisMode, strategy);
            const resourceRow = selectedResourceRow(redisMode, strategy);

            return `<tr>
                <th scope="row" data-strategy="${escapeHtml(strategy)}">${escapeHtml(strategyLabel(strategy))}</th>
                <td>${escapeHtml(formatMetric(metricRow?.avg_ms, 'avg_ms'))}</td>
                <td>${escapeHtml(formatMetric(metricRow?.p95_ms, 'p95_ms'))}</td>
                <td>${escapeHtml(formatMetric(metricRow?.p99_ms, 'p99_ms'))}</td>
                <td>${escapeHtml(formatMetric(metricRow?.throughput_rps, 'throughput_rps'))}</td>
                <td>${escapeHtml(formatMetric(metricRow?.cache_hit_ratio_pct, 'cache_hit_ratio_pct'))}</td>
                <td>${escapeHtml(formatMetric(metricRow?.error_rate_pct, 'error_rate_pct'))}</td>
                <td>${escapeHtml(formatMetric(resourceRow?.cpu_avg_pct, 'cpu_avg_pct'))}</td>
                <td>${escapeHtml(formatMetric(resourceRow?.mem_avg_mb, 'mem_avg_mb'))}</td>
            </tr>`;
        }).join('');

        return `<div class="simulation-table-wrap">
            <table class="simulation-table">
                <caption>${redisMode === 'single' ? 'Mode Redis node tunggal' : 'Mode Redis cluster'} pada ${formatNumber(state.concurrentUsers)} VU</caption>
                <thead>
                    <tr>
                        <th scope="col">Strategi</th>
                        <th scope="col">Latensi rata-rata</th>
                        <th scope="col">P95</th>
                        <th scope="col">P99</th>
                        <th scope="col">Throughput</th>
                        <th scope="col">Cache hit</th>
                        <th scope="col">Tingkat error</th>
                        <th scope="col">CPU</th>
                        <th scope="col">Memori</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    function renderResult() {
        const summary = data.researchSummary ?? {};

        renderResearchWinner(summary.overall_winner);
        renderBaselineImprovements(summary.baseline_improvements ?? []);
        renderWorkloadWinners(summary.workload_winners ?? []);
        renderRedisModeInsights(summary.redis_mode_insights ?? []);
        renderTradeOffs(summary.trade_offs ?? []);
        renderRecommendation(summary.recommendation ?? {});
        renderThreats(summary.threats_to_validity ?? []);
    }

    function renderResearchWinner(winner) {
        if (!winner) {
            html('[data-research-overall-winner]', '<p class="table-empty" role="status">Tidak ada ringkasan hasil penelitian.</p>');

            return;
        }

        html('[data-research-overall-winner]', `<article>
            <span>Strategi terbaik</span>
            <strong data-strategy="${escapeHtml(winner.strategy)}">${escapeHtml(winner.label)}</strong>
            <p>Skor komposit ${escapeHtml(formatNumber(winner.score))} pada ${escapeHtml(winner.scenario_label)} · ${escapeHtml(winner.redis_label)}.</p>
            <small>${escapeHtml(formatNumber(winner.valid_iterations))}/${escapeHtml(formatNumber(winner.total_iterations))} iterasi valid di ${formatNumber(ANALYSIS_VU)} VU.</small>
        </article>`);
    }

    function renderBaselineImprovements(improvements) {
        html('[data-baseline-improvements]', improvements.map((improvement) => `<article class="score-card">
            <div class="score-card-header">
                <span>${escapeHtml(improvement.redis_label)} · ${escapeHtml(improvement.scenario_label)}</span>
                <strong data-strategy="${escapeHtml(improvement.winner_strategy)}">${escapeHtml(improvement.winner_label)}</strong>
                <small>Dibanding baseline Tanpa Cache pada ${formatNumber(ANALYSIS_VU)} VU</small>
            </div>
            <dl class="score-card-meta metric-delta-list">
                <div>
                    <dt>Latency</dt>
                    <dd>${escapeHtml(formatDirectionalPercent(improvement.latency_pct, 'lower'))}</dd>
                </div>
                <div>
                    <dt>Throughput</dt>
                    <dd>${escapeHtml(formatDirectionalPercent(improvement.throughput_pct, 'higher'))}</dd>
                </div>
                <div>
                    <dt>Error Rate</dt>
                    <dd>${escapeHtml(formatDirectionalPercent(improvement.error_rate_pct, 'lower'))}</dd>
                </div>
            </dl>
        </article>`).join(''));
    }

    function renderWorkloadWinners(winners) {
        html('[data-workload-winners]', winners.map((winner) => `<article class="score-card">
            <div class="score-card-header">
                <span>${escapeHtml(winner.scenario_label)}</span>
                <strong data-strategy="${escapeHtml(winner.strategy)}">${escapeHtml(winner.label)}</strong>
                <small>${escapeHtml(winner.redis_label)} · skor ${escapeHtml(formatNumber(winner.score))}</small>
            </div>
            <p class="report-card-copy">Strategi ini menjadi kandidat utama untuk workload ${escapeHtml(winner.scenario_label)} karena memiliki skor komposit tertinggi pada titik stabil ${formatNumber(ANALYSIS_VU)} VU.</p>
        </article>`).join(''));
    }

    function renderRedisModeInsights(insights) {
        html('[data-redis-mode-insights]', insights.map((insight) => {
            const fasterMode = insight.faster_mode;
            const stableMode = insight.stable_mode;
            const modeRows = insight.mode_summaries.map((summary) => `<li>
                <span>${escapeHtml(summary.redis_label)}</span>
                <strong>${escapeHtml(formatMetric(summary.avg_latency_ms, 'avg_ms'))}</strong>
                <small>${escapeHtml(formatMetric(summary.avg_error_rate_pct, 'error_rate_pct'))} error · ${escapeHtml(formatNumber(summary.saturated_iterations))}/${escapeHtml(formatNumber(summary.total_iterations))} jenuh</small>
            </li>`).join('');

            return `<article class="score-card">
                <div class="score-card-header">
                    <span>${escapeHtml(insight.scenario_label)}</span>
                    <strong>${escapeHtml(fasterMode?.redis_label ?? '-')} lebih cepat</strong>
                    <small>${escapeHtml(stableMode?.redis_label ?? '-')} paling stabil menurut error dan saturasi.</small>
                </div>
                <ul class="mode-insight-list">${modeRows}</ul>
            </article>`;
        }).join(''));
    }

    function renderTradeOffs(tradeOffs) {
        html('[data-trade-off-analysis]', tradeOffs.map((tradeOff) => `<article class="score-card">
            <div class="score-card-header">
                <span>Strategi</span>
                <strong data-strategy="${escapeHtml(tradeOff.strategy)}">${escapeHtml(tradeOff.label)}</strong>
            </div>
            <dl class="trade-off-copy">
                <div>
                    <dt>Kekuatan</dt>
                    <dd>${escapeHtml(tradeOff.strength)}</dd>
                </div>
                <div>
                    <dt>Trade-off</dt>
                    <dd>${escapeHtml(tradeOff.trade_off)}</dd>
                </div>
            </dl>
        </article>`).join(''));
    }

    function renderRecommendation(recommendation) {
        const notes = recommendation.notes ?? [];

        html('[data-final-recommendation]', `<article class="recommendation-panel">
            <span>${escapeHtml(recommendation.title ?? 'Rekomendasi implementasi')}</span>
            <strong data-strategy="${escapeHtml(recommendation.primary_strategy ?? '')}">${escapeHtml(recommendation.primary_label ?? '-')}</strong>
            <p>${escapeHtml(recommendation.body ?? 'Tidak ada rekomendasi untuk data ini.')}</p>
            <ul>${notes.map((note) => `<li>${escapeHtml(note)}</li>`).join('')}</ul>
        </article>`);
    }

    function renderThreats(threats) {
        html('[data-threats-to-validity]', `<ul class="threat-list">
            ${threats.map((threat) => `<li>${escapeHtml(threat)}</li>`).join('')}
        </ul>`);
    }

    function renderStatistics() {
        renderSummaries();
        renderAnovaTable();
        renderTukeyTable();
        renderSignificanceMatrix();
    }

    function filterRowsAtVu(rows, redisMode, concurrentUsers) {
        return rows.filter((row) => (
            row.scenario === state.scenario
            && row.redis_mode === redisMode
            && Number(row.concurrent_users) === concurrentUsers
        ));
    }

    function selectedMetricRow(redisMode, strategy) {
        return data.metrics.find((row) => (
            row.scenario === state.scenario
            && row.redis_mode === redisMode
            && row.strategy === strategy
            && Number(row.concurrent_users) === state.concurrentUsers
        ));
    }

    function selectedResourceRow(redisMode, strategy) {
        return data.resources.find((row) => (
            row.scenario === state.scenario
            && row.redis_mode === redisMode
            && row.strategy === strategy
            && Number(row.concurrent_users) === state.concurrentUsers
        ));
    }

    function renderEndpointAnalysis() {
        renderEndpointCharts();
        renderEndpointTable();
    }

    function endpointRowsAtVu(redisMode = null) {
        return (data.endpoints ?? []).filter((row) => (
            row.scenario === state.scenario
            && (redisMode === null || row.redis_mode === redisMode)
            && Number(row.concurrent_users) === state.concurrentUsers
        ));
    }

    function endpointMetric() {
        if (['avg_ms', 'p90_ms', 'p95_ms', 'p99_ms', 'max_ms'].includes(state.metric)) {
            return state.metric;
        }

        return 'avg_ms';
    }

    function endpointMetricLabel() {
        const labels = {
            avg_ms: 'Latensi rata-rata',
            p90_ms: 'Latensi P90',
            p95_ms: 'Latensi P95',
            p99_ms: 'Latensi P99',
            max_ms: 'Latensi maksimum',
        };

        return labels[endpointMetric()];
    }

    function endpointDefinitions() {
        const definitions = new Map();

        (data.endpoints ?? []).forEach((row) => {
            if (definitions.has(row.endpoint_key)) {
                return;
            }

            definitions.set(row.endpoint_key, {
                key: row.endpoint_key,
                label: row.endpoint_label,
                method: row.method,
                pathPattern: row.path_pattern,
                operationType: row.operation_type,
            });
        });

        return (scenarioEndpointKeys[state.scenario] ?? [])
            .map((endpointKey) => definitions.get(endpointKey))
            .filter(Boolean);
    }

    function renderEndpointCharts() {
        const metric = endpointMetric();
        const endpoints = endpointDefinitions();

        if (endpoints.length === 0) {
            html('[data-endpoint-charts]', '<p class="table-empty" role="status">Tidak ada data endpoint untuk filter ini.</p>');

            return;
        }

        html('[data-endpoint-charts]', endpoints.map((endpoint) => {
            const rows = endpointRowsAtVu()
                .filter((row) => row.endpoint_key === endpoint.key);
            const maxValue = Math.max(...rows.map((row) => Number(row[metric])).filter(Number.isFinite), 1);

            return `<article class="endpoint-chart-card">
                <div class="endpoint-card-header">
                    <div>
                        <span>${escapeHtml(endpoint.method)} · ${escapeHtml(endpoint.operationType === 'read' ? 'Read' : 'Write')}</span>
                        <h3>${escapeHtml(endpoint.label)}</h3>
                        <p>${escapeHtml(endpoint.pathPattern)}</p>
                    </div>
                </div>
                <div class="endpoint-mode-grid">
                    ${data.redisModes.map((redisMode) => `<div>
                        <h4>${escapeHtml(redisModeLabel(redisMode))}</h4>
                        <div class="chart-frame endpoint-chart" aria-label="${escapeHtml(endpoint.label)} ${escapeHtml(redisModeLabel(redisMode))}">
                            ${endpointStrategyBarChart(endpoint.key, redisMode, metric, maxValue)}
                        </div>
                    </div>`).join('')}
                </div>
            </article>`;
        }).join(''));
    }

    function endpointStrategyBarChart(endpointKey, redisMode, metric, maxValue) {
        const rows = data.strategies.map((strategy) => endpointRowsAtVu(redisMode).find((row) => (
            row.endpoint_key === endpointKey
            && row.strategy === strategy
        )) ?? {
            strategy,
            [metric]: null,
        });
        const width = 420;
        const height = 250;
        const padding = { top: 24, right: 18, bottom: 62, left: 48 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const gap = 14;
        const barWidth = (plotWidth - gap * (rows.length - 1)) / Math.max(rows.length, 1);

        const bars = rows.map((row, index) => {
            const value = Number(row[metric]);
            const hasValue = Number.isFinite(value);
            const barHeight = hasValue ? (value / maxValue) * plotHeight : 0;
            const x = padding.left + index * (barWidth + gap);
            const y = padding.top + plotHeight - barHeight;

            return `<rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}" rx="5" fill="${strategyColors[row.strategy]}" />
                <text class="chart-value" x="${x + barWidth / 2}" y="${Math.max(16, y - 8)}" text-anchor="middle">${escapeHtml(hasValue ? formatMetric(value, metric) : '-')}</text>
                <text class="chart-label" x="${x + barWidth / 2}" y="${height - 30}" text-anchor="middle">${escapeHtml(shortStrategyLabel(row.strategy))}</text>`;
        }).join('');

        return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(endpointMetricLabel())} berdasarkan strategi">
            <line class="chart-axis" x1="${padding.left}" y1="${height - padding.bottom}" x2="${width - padding.right}" y2="${height - padding.bottom}" />
            <line class="chart-axis" x1="${padding.left}" y1="${padding.top}" x2="${padding.left}" y2="${height - padding.bottom}" />
            <text class="chart-label" x="8" y="${padding.top + 4}">${escapeHtml(formatMetric(maxValue, metric))}</text>
            ${bars}
        </svg>`;
    }

    function renderEndpointTable() {
        const metric = endpointMetric();
        const rows = endpointRowsAtVu()
            .sort((left, right) => {
                if (left.redis_mode === right.redis_mode) {
                    return Number(right[metric]) - Number(left[metric]);
                }

                return data.redisModes.indexOf(left.redis_mode) - data.redisModes.indexOf(right.redis_mode);
            });

        html('[data-endpoint-table]', table(
            ['Mode', 'Strategi', 'Endpoint', 'Operasi', 'Avg', 'P95', 'P99', 'Iterasi'],
            rows.map((row) => [
                redisModeLabel(row.redis_mode),
                strategyLabel(row.strategy),
                `${row.method} ${row.path_pattern}`,
                row.operation_type === 'read' ? 'Read' : 'Write',
                formatMetric(row.avg_ms, 'avg_ms'),
                formatMetric(row.p95_ms, 'p95_ms'),
                formatMetric(row.p99_ms, 'p99_ms'),
                formatNumber(row.iterations_averaged),
            ]),
        ));
    }

    function renderSummaries() {
        const validityRows = validityRowsAtVu(ANALYSIS_VU);
        const saturationRows = validityRowsAtVu(SATURATION_VU);
        const validStrategies = validityRows.filter((row) => Number(row.valid) >= 3).length;
        const validIterations = validityRows.reduce((sum, row) => sum + Number(row.valid || 0), 0);
        const totalIterations = validityRows.reduce((sum, row) => sum + Number(row.total_iterations || 0), 0);
        const saturatedIterations = saturationRows.reduce((sum, row) => sum + Number(row.saturated || 0), 0);
        const saturationTotalIterations = saturationRows.reduce((sum, row) => sum + Number(row.total_iterations || 0), 0);
        const saturatedStrategies = saturationRows.filter((row) => Number(row.saturated) > 0).length;
        const significantCount = data.anova.filter((row) => (
            row.scenario === state.scenario
            && row.redis_mode === state.redisMode
            && row.is_significant === true
        )).length;

        text('[data-summary="validStrategies"]', `${validStrategies}/${data.strategies.length}`);
        text('[data-summary="validStrategiesValue"]', `${formatNumber(validIterations)}/${formatNumber(totalIterations)} iterasi valid`);
        text('[data-summary="significance"]', `${significantCount}/6`);
        text('[data-summary="saturation"]', `${formatNumber(saturatedIterations)}/${formatNumber(saturationTotalIterations)}`);
        text('[data-summary="saturationValue"]', `${formatNumber(saturatedStrategies)} strategi punya iterasi jenuh`);
    }

    function renderLineChart(selector, redisMode) {
        const rows = data.metrics.filter((row) => (
            row.scenario === state.scenario
            && row.redis_mode === redisMode
        ));
        const points = rows.map((row) => Number(row[state.metric])).filter(Number.isFinite);
        const maxValue = Math.max(...points, 1);
        const width = 900;
        const height = 320;
        const padding = { top: 28, right: 30, bottom: 48, left: 72 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const xFor = (vu) => padding.left + (data.concurrentUsers.indexOf(Number(vu)) / (data.concurrentUsers.length - 1)) * plotWidth;
        const yFor = (value) => padding.top + plotHeight - (Number(value) / maxValue) * plotHeight;

        const grid = [0, 0.25, 0.5, 0.75, 1].map((step) => {
            const y = padding.top + plotHeight - step * plotHeight;

            return `<line class="chart-grid" x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" />
                <text class="chart-label" x="18" y="${y + 4}">${escapeHtml(formatMetric(maxValue * step, state.metric))}</text>`;
        }).join('');
        const xLabels = data.concurrentUsers.map((vu) => `<text class="chart-label" x="${xFor(vu)}" y="${height - 16}" text-anchor="middle">${formatNumber(vu)}</text>`).join('');
        const series = data.strategies.map((strategy) => {
            const strategyRows = rows
                .filter((row) => row.strategy === strategy)
                .sort((a, b) => Number(a.concurrent_users) - Number(b.concurrent_users));
            const path = strategyRows.map((row, index) => `${index === 0 ? 'M' : 'L'} ${xFor(row.concurrent_users)} ${yFor(row[state.metric])}`).join(' ');
            const circles = strategyRows.map((row) => `<circle cx="${xFor(row.concurrent_users)}" cy="${yFor(row[state.metric])}" r="4" fill="${strategyColors[strategy]}" />`).join('');

            return path === ''
                ? ''
                : `<path d="${path}" fill="none" stroke="${strategyColors[strategy]}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />${circles}`;
        }).join('');

        html(selector, `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(data.metricOptions[state.metric])} berdasarkan virtual user">
            ${grid}
            <line class="chart-axis" x1="${padding.left}" y1="${height - padding.bottom}" x2="${width - padding.right}" y2="${height - padding.bottom}" />
            <line class="chart-axis" x1="${padding.left}" y1="${padding.top}" x2="${padding.left}" y2="${height - padding.bottom}" />
            ${xLabels}
            ${series}
        </svg>`);
    }

    function renderBarChart(selector, rows, metric, label) {
        const chartRows = [...rows].sort((a, b) => data.strategies.indexOf(a.strategy) - data.strategies.indexOf(b.strategy));
        const values = chartRows.map((row) => Number(row[metric])).filter(Number.isFinite);
        const maxValue = Math.max(...values, 1);
        const width = 520;
        const height = 300;
        const padding = { top: 20, right: 24, bottom: 76, left: 58 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const gap = 18;
        const barWidth = (plotWidth - gap * (chartRows.length - 1)) / Math.max(chartRows.length, 1);

        const bars = chartRows.map((row, index) => {
            const value = Number(row[metric]);
            const barHeight = (value / maxValue) * plotHeight;
            const x = padding.left + index * (barWidth + gap);
            const y = padding.top + plotHeight - barHeight;

            return `<rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}" rx="5" fill="${strategyColors[row.strategy]}" />
                <text class="chart-value" x="${x + barWidth / 2}" y="${Math.max(16, y - 8)}" text-anchor="middle">${escapeHtml(formatMetric(value, metric))}</text>
                <text class="chart-label" x="${x + barWidth / 2}" y="${height - 38}" text-anchor="middle">${escapeHtml(shortStrategyLabel(row.strategy))}</text>`;
        }).join('');

        html(selector, `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(label)} berdasarkan strategi">
            <line class="chart-axis" x1="${padding.left}" y1="${height - padding.bottom}" x2="${width - padding.right}" y2="${height - padding.bottom}" />
            <line class="chart-axis" x1="${padding.left}" y1="${padding.top}" x2="${padding.left}" y2="${height - padding.bottom}" />
            <text class="chart-label" x="${padding.left}" y="${height - 12}">${escapeHtml(label)}</text>
            ${bars}
        </svg>`);
    }

    function renderAnovaTable() {
        const rows = data.anova
            .filter((row) => row.scenario === state.scenario && row.redis_mode === state.redisMode)
            .sort((a, b) => Number(a.p_value) - Number(b.p_value));

        html('[data-anova-table]', table(
            ['Metrik', 'F statistik', 'p-value', 'Keputusan'],
            rows.map((row) => [
                metricLabel(row.metric),
                formatNumber(row.f_statistic),
                formatScientific(row.p_value),
                row.is_significant ? 'Signifikan' : 'Tidak signifikan',
            ]),
        ));
    }

    function renderTukeyTable() {
        const rows = tukeyRowsForCurrentMetric()
            .sort((a, b) => Number(a['p-adj']) - Number(b['p-adj']));

        html('[data-tukey-table]', table(
            ['Pasangan', 'Selisih rata-rata', 'p disesuaikan', 'Tolak H0'],
            rows.map((row) => [
                `${shortStrategyLabel(row.group1)} vs ${shortStrategyLabel(row.group2)}`,
                formatNumber(row.meandiff),
                formatScientific(row['p-adj']),
                row.reject ? 'Ya' : 'Tidak',
            ]),
        ));
    }

    function renderSignificanceMatrix() {
        const rows = tukeyRowsForCurrentMetric();

        if (rows.length === 0) {
            html('[data-significance-matrix]', '<p class="table-empty" role="status">Tidak ada data Tukey untuk metrik ini.</p>');

            return;
        }

        const lookup = new Map();

        rows.forEach((row) => {
            lookup.set(pairKey(row.group1, row.group2), row.reject === true);
            lookup.set(pairKey(row.group2, row.group1), row.reject === true);
        });

        const headerCells = data.strategies.map((strategy) => `<th scope="col">${escapeHtml(shortStrategyLabel(strategy))}</th>`).join('');
        const bodyRows = data.strategies.map((rowStrategy) => {
            const cells = data.strategies.map((columnStrategy) => {
                if (rowStrategy === columnStrategy) {
                    return '<td><span aria-label="Strategi yang sama">-</span></td>';
                }

                const isSignificant = lookup.get(pairKey(rowStrategy, columnStrategy));
                const mark = isSignificant ? '✓' : '✗';
                const label = isSignificant ? 'Signifikan' : 'Tidak signifikan';

                return `<td class="${isSignificant ? 'is-significant' : 'is-not-significant'}"><span aria-label="${label}">${mark}</span></td>`;
            }).join('');

            return `<tr>
                <th scope="row">${escapeHtml(shortStrategyLabel(rowStrategy))}</th>
                ${cells}
            </tr>`;
        }).join('');

        html('[data-significance-matrix]', `<table class="significance-matrix">
            <caption>${escapeHtml(metricLabel(state.metric))} · ${escapeHtml(scenarioLabel(state.scenario))} · ${escapeHtml(redisModeLabel(state.redisMode))}</caption>
            <thead>
                <tr>
                    <th scope="col">Strategi</th>
                    ${headerCells}
                </tr>
            </thead>
            <tbody>${bodyRows}</tbody>
        </table>`);
    }

    function tukeyRowsForCurrentMetric() {
        return data.tukey.filter((row) => (
            row.scenario === state.scenario
            && row.redis_mode === state.redisMode
            && row.metric === state.metric
        ));
    }

    function pairKey(left, right) {
        return `${left}::${right}`;
    }

    function table(headers, rows) {
        if (rows.length === 0) {
            return '<p class="table-empty" role="status">Tidak ada data untuk filter ini.</p>';
        }

        return `<table>
            <thead><tr>${headers.map((header) => `<th scope="col">${escapeHtml(header)}</th>`).join('')}</tr></thead>
            <tbody>${rows.map((row) => `<tr>${row.map((value) => `<td>${escapeHtml(value)}</td>`).join('')}</tr>`).join('')}</tbody>
        </table>`;
    }

    function validityRowsAtVu(concurrentUsers) {
        return data.validity.filter((row) => (
            row.scenario === state.scenario
            && row.redis_mode === state.redisMode
            && Number(row.concurrent_users) === concurrentUsers
        ));
    }

    function strategyLabel(strategy) {
        return data.strategyLabels[strategy] ?? strategy ?? '-';
    }

    function shortStrategyLabel(strategy) {
        return strategyLabel(strategy).replace(' Through', '').replace(' Aside', '').replace('Tanpa Cache', 'NC');
    }

    function scenarioLabel(scenario) {
        return scenario === 'read-heavy' ? 'Read Heavy' : 'Write Heavy';
    }

    function redisModeLabel(redisMode) {
        return redisMode === 'single' ? 'Node Tunggal' : 'Cluster';
    }

    function metricLabel(metric) {
        return data.metricOptions[metric] ?? String(metric).replaceAll('_', ' ');
    }

    function formatMetric(value, metric) {
        const number = Number(value);
        const suffix = metricUnits[metric] ? ` ${metricUnits[metric]}` : '';

        return `${formatNumber(number)}${suffix}`;
    }

    function formatDirectionalPercent(value, direction) {
        const number = Number(value);

        if (!Number.isFinite(number)) {
            return '-';
        }

        if (number === 0) {
            return '0%';
        }

        if (direction === 'lower') {
            return `${number > 0 ? '-' : '+'}${formatNumber(Math.abs(number))}%`;
        }

        return `${number > 0 ? '+' : '-'}${formatNumber(Math.abs(number))}%`;
    }

    function formatNumber(value) {
        const number = Number(value);

        if (!Number.isFinite(number)) {
            return '-';
        }

        return new Intl.NumberFormat('id-ID', {
            maximumFractionDigits: number >= 100 ? 0 : 2,
        }).format(number);
    }

    function formatScientific(value) {
        const number = Number(value);

        if (!Number.isFinite(number)) {
            return '-';
        }

        if (number === 0) {
            return '0';
        }

        return number < 0.001 ? number.toExponential(2) : formatNumber(number);
    }

    function text(selector, value) {
        const element = dashboard.querySelector(selector);

        if (element) {
            element.textContent = value;
        }
    }

    function html(selector, value) {
        const element = dashboard.querySelector(selector);

        if (element) {
            element.innerHTML = value;
        }
    }

    function toggle(selector, isVisible) {
        const element = dashboard.querySelector(selector);

        if (element) {
            element.hidden = !isVisible;
        }
    }

    function syncControls() {
        dashboard.querySelectorAll('[data-scenario-option]').forEach((button) => {
            const isActive = button.dataset.scenarioOption === state.scenario;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });

        dashboard.querySelectorAll('[data-vu-option]').forEach((button) => {
            const isActive = Number(button.dataset.vuOption) === state.concurrentUsers;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });

        setControl('redisMode', state.redisMode);
        setControl('metric', state.metric);
    }

    function setControl(key, value) {
        const control = dashboard.querySelector(`[data-benchmark-control="${key}"]`);

        if (control && [...control.options].some((option) => option.value === String(value))) {
            control.value = String(value);
        }
    }

    function syncUrl() {
        const nextParams = new URLSearchParams({
            tab: state.tab,
            scenario: state.scenario,
            redis_mode: state.redisMode,
            concurrent_users: String(state.concurrentUsers),
            metric: state.metric,
        });

        window.history.replaceState({}, '', `${window.location.pathname}?${nextParams.toString()}`);
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
}
