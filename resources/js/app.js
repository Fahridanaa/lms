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

    const ANALYSIS_VU = 1500;
    const SATURATION_VU = 2000;
    const simulationStrategies = ['cache-aside', 'read-through', 'write-through'];
    const tabs = ['benchmark', 'analysis'];
    const strategyColors = {
        'no-cache': '#f97316',
        'cache-aside': '#9dff5f',
        'read-through': '#6aa7ff',
        'write-through': '#d778ff',
    };
    const metricUnits = {
        avg_ms: 'ms',
        p95_ms: 'ms',
        p99_ms: 'ms',
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
        renderAnalysis();
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

        const analysisControls = dashboard.querySelector('[data-analysis-controls]');
        const vuControls = dashboard.querySelector('[data-vu-controls]');

        if (analysisControls) {
            analysisControls.hidden = state.tab !== 'analysis';
        }

        if (vuControls) {
            vuControls.hidden = state.tab === 'analysis';
        }
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

    function renderAnalysis() {
        const selectedMetrics = filterRowsAtVu(data.metrics, state.redisMode, ANALYSIS_VU);
        const selectedResources = filterRowsAtVu(data.resources, state.redisMode, ANALYSIS_VU);

        renderSummaries(selectedMetrics);
        renderLineChart();
        renderBarChart('[data-chart="bar"]', selectedMetrics, state.metric, data.metricOptions[state.metric]);
        renderBarChart('[data-chart="cpu"]', selectedResources, 'cpu_avg_pct', 'Rata-rata CPU');
        renderBarChart('[data-chart="memory"]', selectedResources, 'mem_avg_mb', 'Rata-rata memori');
        renderReliabilityTable(selectedMetrics);
        renderSaturationTable();
        renderAnovaTable();
        renderTukeyTable();

        text('[data-line-title]', `Tren ${data.metricOptions[state.metric].toLowerCase()} lintas VU`);
        text('[data-bar-title]', `Perbandingan ${formatNumber(ANALYSIS_VU)} VU`);
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

    function renderSummaries(rows) {
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

    function renderLineChart() {
        const rows = data.metrics.filter((row) => (
            row.scenario === state.scenario
            && row.redis_mode === state.redisMode
        ));
        const points = rows.map((row) => Number(row[state.metric])).filter(Number.isFinite);
        const maxValue = Math.max(...points, 1);
        const width = 900;
        const height = 360;
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

            return `<path d="${path}" fill="none" stroke="${strategyColors[strategy]}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />${circles}`;
        }).join('');

        html('[data-chart="line"]', `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(data.metricOptions[state.metric])} berdasarkan virtual user">
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

    function renderReliabilityTable(rows) {
        html('[data-reliability-table]', table(
            ['Strategi', 'Cache hit', 'Tingkat error', 'Jumlah request', 'Iterasi valid', 'Validitas'],
            rows.map((row) => {
                const validity = validityRow(row.strategy, ANALYSIS_VU);

                return [
                    strategyLabel(row.strategy),
                    formatMetric(row.cache_hit_ratio_pct, 'cache_hit_ratio_pct'),
                    formatMetric(row.error_rate_pct, 'error_rate_pct'),
                    formatNumber(row.http_reqs_total),
                    validity ? `${formatNumber(validity.valid)}/${formatNumber(validity.total_iterations)}` : '-',
                    row.validity_status === 'valid' ? 'Valid' : String(row.validity_status ?? '-'),
                ];
            }),
        ));
    }

    function renderSaturationTable() {
        const rows = validityRowsAtVu(SATURATION_VU)
            .sort((a, b) => data.strategies.indexOf(a.strategy) - data.strategies.indexOf(b.strategy));

        html('[data-saturation-table]', table(
            ['Strategi', 'Valid', 'Jenuh', 'Total', 'Status'],
            rows.map((row) => [
                strategyLabel(row.strategy),
                formatNumber(row.valid),
                formatNumber(row.saturated),
                formatNumber(row.total_iterations),
                Number(row.saturated) > 0 ? 'Bukti saturasi' : 'Valid',
            ]),
        ));
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
        const rows = data.tukey
            .filter((row) => (
                row.scenario === state.scenario
                && row.redis_mode === state.redisMode
                && row.metric === state.metric
            ))
            .sort((a, b) => Number(a['p-adj']) - Number(b['p-adj']))
            .slice(0, 8);

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

    function table(headers, rows) {
        if (rows.length === 0) {
            return '<p class="table-empty" role="status">Tidak ada data untuk filter ini.</p>';
        }

        return `<table>
            <thead><tr>${headers.map((header) => `<th scope="col">${escapeHtml(header)}</th>`).join('')}</tr></thead>
            <tbody>${rows.map((row) => `<tr>${row.map((value) => `<td>${escapeHtml(value)}</td>`).join('')}</tr>`).join('')}</tbody>
        </table>`;
    }

    function bestRow(rows, metric, lowerIsBetter) {
        return rows.reduce((best, row) => {
            if (!best) {
                return row;
            }

            return lowerIsBetter
                ? Number(row[metric]) < Number(best[metric]) ? row : best
                : Number(row[metric]) > Number(best[metric]) ? row : best;
        }, null);
    }

    function validityRowsAtVu(concurrentUsers) {
        return data.validity.filter((row) => (
            row.scenario === state.scenario
            && row.redis_mode === state.redisMode
            && Number(row.concurrent_users) === concurrentUsers
        ));
    }

    function validityRow(strategy, concurrentUsers) {
        return data.validity.find((row) => (
            row.scenario === state.scenario
            && row.redis_mode === state.redisMode
            && row.strategy === strategy
            && Number(row.concurrent_users) === concurrentUsers
        ));
    }

    function strategyLabel(strategy) {
        return data.strategyLabels[strategy] ?? strategy ?? '-';
    }

    function shortStrategyLabel(strategy) {
        return strategyLabel(strategy).replace(' Through', '').replace(' Aside', '');
    }

    function metricLabel(metric) {
        return data.metricOptions[metric] ?? String(metric).replaceAll('_', ' ');
    }

    function formatMetric(value, metric) {
        const number = Number(value);
        const suffix = metricUnits[metric] ? ` ${metricUnits[metric]}` : '';

        return `${formatNumber(number)}${suffix}`;
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
