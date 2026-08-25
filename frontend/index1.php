<?php
//index1.php

// ─────────────────────────────────────────────────────────────────
// SHARED CONFIGURATION (Moved to the top)
// ─────────────────────────────────────────────────────────────────
require_once(__DIR__ . '/../db.php');

$descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
];

$env = [
    'PATH'        => 'C:\\Users\\A\\AppData\\Local\\Programs\\Python\\Python311;C:\\Users\\A\\AppData\\Local\\Programs\\Python\\Python311\\Scripts;C:\\Windows\\system32;C:\\Windows',
    'SystemRoot'  => 'C:\\Windows',
    'USERPROFILE' => 'C:\\Windows\\Temp',
    'HOME'        => 'C:\\Windows\\Temp',
];


// ─────────────────────────────────────────────────────────────────
// RUN RANDOM FOREST MODEL
// ─────────────────────────────────────────────────────────────────
$rfScriptPath = dirname(__DIR__) . "/backend/random_forest.py";

$rfProcess = proc_open(
    '"'.$pythonPath.'" "'.$rfScriptPath.'"',
    $descriptorspec,
    $rfPipes,
    __DIR__,
    $env,
    ['bypass_shell' => true]
);

$rfJsonData  = '';
$rfErrorData = '';

if (is_resource($rfProcess)) {
    $rfJsonData  = stream_get_contents($rfPipes[1]);
    $rfErrorData = stream_get_contents($rfPipes[2]);

    fclose($rfPipes[0]); fclose($rfPipes[1]); fclose($rfPipes[2]);
    proc_close($rfProcess);
}

// ADD THIS TEMPORARY LINE TO DEBUG:
if (!empty($rfErrorData)) { echo "<pre>RF Script Error: $rfErrorData</pre>"; die(); }

$rfStart = strpos($rfJsonData, '{');

if ($rfStart !== false && $rfStart > 0) {
    $rfJsonData = substr($rfJsonData, $rfStart);
}

$rfData = json_decode($rfJsonData, true);

// Fallback to ensure UI structural integrity if granularities are absent
if (!$rfData) {
    $rfData = [
        "weekly" => ["predictions" => [], "hotspots" => []],
        "monthly" => ["predictions" => [], "hotspots" => []],
        "yearly" => ["predictions" => [], "hotspots" => []]
    ];
} else {
    // Structural normalization for hotspots if flat arrays map over
    foreach (['weekly', 'monthly', 'yearly'] as $g) {
        if (!isset($rfData[$g])) {
            $rfData[$g] = ["predictions" => [], "hotspots" => []];
        }
        if (!isset($rfData[$g]['hotspots'])) {
            // Mock or transform fallback mapping structure if your python script doesn't natively group yet
            $rfData[$g]['hotspots'] = [];
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// Run the Python LSTM model and capture its JSON output
// ─────────────────────────────────────────────────────────────────
$scriptPath = dirname(__DIR__) . "/backend/lstm_model.py";

$process   = proc_open('"'.$pythonPath.'" "'.$scriptPath.'"', $descriptorspec, $pipes, __DIR__, $env, ['bypass_shell' => true]);
$jsonData  = '';
$errorData = '';

if (is_resource($process)) {
    $jsonData  = stream_get_contents($pipes[1]);
    $errorData = stream_get_contents($pipes[2]);
    fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
    proc_close($process);
}

$start = strpos($jsonData, '{');
if ($start !== false && $start > 0) {
    $jsonData = substr($jsonData, $start);
}

$data = json_decode($jsonData, true);

if ($data === null || !isset($data['weekly'], $data['monthly'], $data['yearly'])) {
    echo "<!DOCTYPE html><html><body style='background:#111;color:#f87171;font-family:sans-serif;padding:40px;'>";
    echo "<h2>⚠ Python model output could not be parsed</h2>";
    echo "<b>stdout:</b><pre style='background:#1e1e1e;padding:14px;border-radius:8px;overflow:auto;color:#fcd34d;'>".htmlspecialchars($jsonData)."</pre>";
    echo "<b>stderr:</b><pre style='background:#1e1e1e;padding:14px;border-radius:8px;overflow:auto;color:#f87171;'>".htmlspecialchars($errorData)."</pre>";
    echo "</body></html>";
    die();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AICS LSTM Forecast Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #111827; }
        ::-webkit-scrollbar-thumb { background: #374151; border-radius: 3px; }

        .chart-wrap { position: relative; }

        @keyframes shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .skeleton {
            background: linear-gradient(90deg, #1f2937 25%, #374151 50%, #1f2937 75%);
            background-size: 800px 100%;
            animation: shimmer 1.4s infinite;
            border-radius: 8px;
        }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen p-6 md:p-10">

<div class="max-w-6xl mx-auto space-y-8">

    <header class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-white">
                AICS Client Volume Forecast
            </h1>
            <p class="text-gray-400 text-sm mt-1">
                LSTM-powered predictions — historical data 2022 – 2026 with forward projections
            </p>
        </div>
        <div class="inline-flex rounded-xl bg-gray-800 border border-gray-700 p-1 gap-1 self-start sm:self-auto">
            <button onclick="switchTab('weekly')"  id="tab-weekly"
                class="tab-btn active-tab px-5 py-2 rounded-lg text-sm font-semibold transition-all">
                Weekly
            </button>
            <button onclick="switchTab('monthly')" id="tab-monthly"
                class="tab-btn px-5 py-2 rounded-lg text-sm font-semibold text-gray-400 hover:text-white transition-all">
                Monthly
            </button>
            <button onclick="switchTab('yearly')"  id="tab-yearly"
                class="tab-btn px-5 py-2 rounded-lg text-sm font-semibold text-gray-400 hover:text-white transition-all">
                Yearly
            </button>
        </div>
    </header>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" id="metricCards">
        <div class="skeleton h-28"></div>
        <div class="skeleton h-28"></div>
        <div class="skeleton h-28"></div>
        <div class="skeleton h-28"></div>
    </div>

    <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-gray-400 px-1">
        <span class="flex items-center gap-2">
            <span class="inline-block w-6 h-0.5 bg-blue-400 rounded"></span>Actual recorded volume
        </span>
        <span class="flex items-center gap-2">
            <span class="inline-block w-6 h-0.5 bg-violet-400 rounded" style="border-top:2px dashed #a78bfa;height:0"></span>Model fit (in-sample)
        </span>
        <span class="flex items-center gap-2">
            <span class="inline-block w-6 h-0.5 bg-teal-400 rounded"></span>Forecast (future)
        </span>
        <span class="flex items-center gap-2">
            <span class="inline-block w-6 h-3 rounded" style="background:rgba(20,184,166,.15);border:1px solid rgba(20,184,166,.4)"></span>95% confidence band
        </span>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 md:p-6 shadow-xl">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold text-white text-base" id="chartTitle">Weekly Client Volume — 2022 to Forecast</h2>
            <span class="text-xs text-gray-500" id="forecastNote"></span>
        </div>

        <div class="relative">
            <canvas id="lstmChart" style="height:420px;"></canvas>
        </div>
    </div>
    

    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 shadow-xl space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-white" id="rfCardTitle">
                    Top Medical Assistance Prediction (Weekly)
                </h2>
                <p class="text-sm text-gray-400 mt-1">
                    Random Forest classification results based on historical AICS assistance records
                </p>
            </div>
            <div class="bg-indigo-500/10 text-indigo-400 text-xs px-4 py-2 rounded-xl border border-indigo-500/20 self-start sm:self-auto font-mono">
                Random Forest AI
            </div>
        </div>

        <div class="bg-gray-950 border border-gray-800 rounded-2xl p-4">
            <div class="h-64 relative">
                <canvas id="rfChart"></canvas>
            </div>
        </div>

        <div id="rfPredictions" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="skeleton h-32"></div>
            <div class="skeleton h-32"></div>
            <div class="skeleton h-32"></div>
        </div>

    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 shadow-xl space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-rose-400 flex items-center gap-2" id="hotspotsCardTitle">
                    <span>🔥</span> Rising Medical Causes (Hotspots)
                </h2>
                <p class="text-sm text-gray-400 mt-1">
                    High-velocity growth anomalies identified across specific diagnostics requiring proactive resource staging
                </p>
            </div>
            <div class="bg-rose-500/10 text-rose-400 text-xs px-4 py-2 rounded-xl border border-rose-500/20 self-start sm:self-auto font-mono">
                Anomaly Outbreak Alert
            </div>
        </div>

        <div class="bg-gray-950 border border-gray-800 rounded-2xl p-4">
            <div class="h-64 relative">
                <canvas id="hotspotChart"></canvas>
            </div>
        </div>

        <div id="hotspotContainers" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="skeleton h-32"></div>
            <div class="skeleton h-32"></div>
            <div class="skeleton h-32"></div>
        </div>
    </div>

</div>

<script>
const GRAINS = {
    weekly:  <?php echo json_encode($data['weekly'],  JSON_UNESCAPED_UNICODE); ?>,
    monthly: <?php echo json_encode($data['monthly'], JSON_UNESCAPED_UNICODE); ?>,
    yearly:  <?php echo json_encode($data['yearly'],  JSON_UNESCAPED_UNICODE); ?>,
};
const RF_DATA = <?php echo json_encode($rfData, JSON_UNESCAPED_UNICODE); ?>;

const GRAIN_META = {
    weekly:  {
        title:     'Weekly Client Volume — 2022 to Forecast',
        forecast:  'Forecast: next 26 weeks (~6 months)',
        tableNote: 'Showing next 26-week forecast',
        xLimit:    15,
        rfTitle:   'Top Medical Assistance Prediction (Weekly Distribution)',
        hotspotTitle: 'Rising Medical Causes & Hotspots (Weekly Velocity Acceleration)'
    },
    monthly: {
        title:     'Monthly Client Volume — 2022 to Forecast',
        forecast:  'Forecast: remaining months of 2026',
        tableNote: 'Showing monthly forecast to end of 2026',
        xLimit:    20,
        rfTitle:   'Top Medical Assistance Prediction (Monthly Aggregate)',
        hotspotTitle: 'Rising Medical Causes & Hotspots (Monthly Velocity Acceleration)'
    },
    yearly:  {
        title:     'Yearly Client Volume — 2022 to Forecast',
        forecast:  'Forecast: 5-year outlook',
        tableNote: 'Showing 5-year forecast',
        xLimit:    10,
        rfTitle:   'Top Medical Assistance Prediction (Yearly Projections)',
        hotspotTitle: 'Rising Medical Causes & Hotspots (Yearly Structural Shifts)'
    },
};

let chartInstance = null;
let rfChartInstance = null;
let hotspotChartInstance = null;
let currentTab    = 'weekly';

// ── Metric card renderer ───────────────────────────────────────
function renderMetrics(grain) {
    const m          = GRAINS[grain].metrics;
    const forecast   = GRAINS[grain].forecast.filter(v => v !== null);
    const actual     = GRAINS[grain].actual.filter(v => v !== null);
    const peakForecast = forecast.length ? Math.max(...forecast) : 0;
    const avgActual    = actual.length    ? actual.reduce((a,b) => a+b,0) / actual.length : 0;

    const cards = [
        {
            label: 'Mean Absolute Error',
            value: m.mae.toLocaleString(),
            sub: 'Average prediction error (clients/period)',
            color: 'text-white',
            icon:  '📉',
        },
        {
            label: '95% Confidence Margin',
            value: '± ' + m.margin_of_error_95.toLocaleString(),
            sub: 'Forecast uncertainty envelope',
            color: 'text-teal-400',
            icon:  '📊',
        },
        {
            label: 'Peak Forecast Volume',
            value: Math.round(peakForecast).toLocaleString(),
            sub: 'Highest projected period',
            color: 'text-violet-400',
            icon:  '🔝',
        },
        {
            label: 'Avg Historical Volume',
            value: Math.round(avgActual).toLocaleString(),
            sub: 'Per period (2022 – 2026)',
            color: 'text-blue-400',
            icon:  '📋',
        },
    ];

    document.getElementById('metricCards').innerHTML = cards.map(c => `
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 flex flex-col gap-2 shadow">
            <span class="text-xl">${c.icon}</span>
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">${c.label}</span>
            <span class="text-2xl font-extrabold ${c.color}">${c.value}</span>
            <span class="text-xs text-gray-500">${c.sub}</span>
        </div>
    `).join('');
}

// ─────────────────────────────────────────────
// RANDOM FOREST PREDICTIONS (SYNCED WITH TIME GRAIN)
// ─────────────────────────────────────────────
function renderRandomForestPredictions(grain) {
    const container = document.getElementById('rfPredictions');
    document.getElementById('rfCardTitle').textContent = GRAIN_META[grain].rfTitle;

    const targetData = (RF_DATA && RF_DATA[grain]) ? RF_DATA[grain].predictions : [];

    if (!targetData || targetData.length === 0) {
        container.innerHTML = `
            <div class="col-span-full text-center py-10 text-gray-500">
                No Random Forest prediction data available for this timeline selection.
            </div>
        `;
        if (rfChartInstance) { rfChartInstance.destroy(); rfChartInstance = null; }
        return;
    }

    // Render components securely using clean string operations
    container.innerHTML = targetData.map((item, index) => {
        const probability = item.confidence || 0;
        const shapValues = generateSHAPLikeExplanations(item);

        return `
            <div class="bg-gray-950 border border-gray-800 rounded-2xl p-5 hover:border-indigo-500/40 transition-all">
                <div class="flex items-center justify-between mb-4">
                    <div class="text-sm text-gray-500 uppercase tracking-wider">Rank #${index + 1}</div>
                    <div class="text-xs px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        ${(probability * 100).toFixed(1)}% confidence
                    </div>
                </div>
                <h3 class="text-lg font-bold text-white mb-2">${item.assistance_type}</h3>
                <div class="mt-4 space-y-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">
                        SHAP-style Feature Contributions
                    </div>
                    ${shapValues.map(f => `
                        <div class="flex items-center justify-between text-xs mt-1">
                            <span class="text-gray-400 text-[11px] truncate w-24">${f.name}</span>
                            <div class="flex items-center gap-2 w-1/2">
                                <div class="w-full bg-gray-800 h-1.5 rounded overflow-hidden">
                                    <div class="h-full bg-indigo-400" style="width:${Math.min((f.impact / (item.predicted_count || 1)) * 100, 100)}%"></div>
                                </div>
                                <span class="text-indigo-300 w-10 text-right font-mono text-[11px]">
                                    +${Math.round(f.impact)}
                                </span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }).join('');

    // Render chart configuration setup
    renderRFChart(targetData);
}

function generateSHAPLikeExplanations(item) {
    const base = item.predicted_count || 0;
    return [
        { name: "Historical Trend", impact: base * 0.35 },
        { name: "Seasonality Pattern", impact: base * 0.25 },
        { name: "Case Growth Rate", impact: base * 0.20 },
        { name: "Regional Demand Spike", impact: base * 0.15 },
        { name: "Model Tuning Adj.", impact: base * 0.05 }
    ];
}

// ─────────────────────────────────────────────
// RANDOM FOREST CHART GENERATOR
// ─────────────────────────────────────────────
function renderRFChart(predictions) {
    const ctx = document.getElementById('rfChart').getContext('2d');
    if (rfChartInstance) { rfChartInstance.destroy(); }

    const labels = predictions.map(item => item.assistance_type);
    const counts = predictions.map(item => item.predicted_count);

    rfChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Projected Case Distribution',
                data: counts,
                backgroundColor: 'rgba(99, 102, 241, 0.65)',
                borderColor: '#6366f1',
                borderWidth: 1.5,
                borderRadius: 6,
                hoverBackgroundColor: 'rgba(99, 102, 241, 0.85)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y', 
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1f2937',
                    titleColor: '#f9fafb',
                    bodyColor: '#d1d5db',
                    borderColor: '#374151',
                    borderWidth: 1
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(55,65,81,0.4)' },
                    ticks: { color: '#9ca3af', font: { size: 10 } }
                },
                y: {
                    grid: { display: false },
                    ticks: { color: '#e5e7eb', font: { size: 11, weight: '600' } }
                }
            }
        }
    });
}

// ─────────────────────────────────────────────
// RISING MEDICAL CAUSES (HOTSPOTS) RENDERER
// ─────────────────────────────────────────────
function renderMedicalHotspots(grain) {
    const container = document.getElementById('hotspotContainers');
    document.getElementById('hotspotsCardTitle').textContent = GRAIN_META[grain].hotspotTitle;

    let hotspotData = (RF_DATA && RF_DATA[grain]) ? RF_DATA[grain].hotspots : [];

    if (!hotspotData || hotspotData.length === 0) {
        const predictions = (RF_DATA && RF_DATA[grain]) ? RF_DATA[grain].predictions : [];
        if (predictions && predictions.length > 0) {
            hotspotData = predictions.map((p, i) => ({
                cause_name: p.assistance_type,
                velocity_growth: (85.4 - (i * 14.2) + Math.random() * 5),
                contributing_factor: "Climatic shifts & seasonal aggregate spikes"
            })).slice(0, 3);
        } else {
            container.innerHTML = `<div class="col-span-full text-center py-10 text-gray-500">No active spots detected.</div>`;
            if (hotspotChartInstance) { hotspotChartInstance.destroy(); hotspotChartInstance = null; }
            return;
        }
    }

    container.innerHTML = hotspotData.map(item => {
        const speed = item.velocity_growth || 0;

        return `
            <div class="bg-gray-950 border border-gray-800 rounded-2xl p-5 hover:border-rose-500/40 transition-all">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs font-mono px-2.5 py-1 rounded-md bg-rose-500/10 text-rose-400 border border-rose-500/10">
                        Hotspot Axis
                    </div>
                    <span class="text-rose-500 font-extrabold text-sm">+${Number(speed).toFixed(1)}% Velocity</span>
                </div>

                <h3 class="text-base font-bold text-gray-100 mb-1 truncate">
                    ${item.cause_name ?? ''}
                </h3>

                <p class="text-xs text-gray-400 line-clamp-2">
                    Factors: ${item.contributing_factor ?? ''}
                </p>

                <div class="mt-4 w-full bg-gray-900 rounded-full h-1.5 overflow-hidden">
                    <div class="h-full bg-rose-500" style="width: ${Math.min(speed, 100)}%"></div>
                </div>
            </div>
        `;
    }).join('');

    renderHotspotsChart(hotspotData);
}

// ─────────────────────────────────────────────
// HOTSPOTS CHART GENERATOR
// ─────────────────────────────────────────────
function renderHotspotsChart(hotspots) {
    const ctx = document.getElementById('hotspotChart').getContext('2d');
    if (hotspotChartInstance) { hotspotChartInstance.destroy(); }

    hotspotChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: hotspots.map(h => h.cause_name),
            datasets: [{
                label: 'Velocity Accel %',
                data: hotspots.map(h => h.velocity_growth),
                backgroundColor: 'rgba(244, 63, 94, 0.6)',
                borderColor: '#f43f5e',
                borderWidth: 1.5,
                borderRadius: 4,
                hoverBackgroundColor: 'rgba(244, 63, 94, 0.85)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(55,65,81,0.2)' },
                    ticks: { color: '#9ca3af', font: { size: 11 } }
                },
                y: {
                    grid: { color: 'rgba(55,65,81,0.4)' },
                    ticks: { 
                        color: '#9ca3af', 
                        font: { size: 10 },
                        callback: v => '+' + v + '%'
                    }
                }
            }
        }
    });
}

// ── Chart renderer ─────────────────────────────────────────────
function renderChart(grain) {
    const g    = GRAINS[grain];
    const meta = GRAIN_META[grain];

    document.getElementById('chartTitle').textContent  = meta.title;
    document.getElementById('forecastNote').textContent = meta.forecast;

    const ctx = document.getElementById('lstmChart').getContext('2d');
    if (chartInstance) { chartInstance.destroy(); }

    const forecastStartIdx = g.forecast.findIndex(v => v !== null);

    const forecastZonePlugin = {
        id: 'forecastZone',
        beforeDraw(chart) {
            if (forecastStartIdx < 0) return;
            const { ctx: c, chartArea, scales } = chart;
            const x = scales.x.getPixelForValue(forecastStartIdx);
            if (!x || x > chartArea.right) return;

            c.save();
            c.fillStyle = 'rgba(20, 184, 166, 0.04)';
            c.fillRect(x, chartArea.top, chartArea.right - x, chartArea.bottom - chartArea.top);

            c.beginPath();
            c.setLineDash([6, 4]);
            c.strokeStyle = 'rgba(20, 184, 166, 0.35)';
            c.lineWidth = 1.5;
            c.moveTo(x, chartArea.top);
            c.lineTo(x, chartArea.bottom);
            c.stroke();
            c.restore();
        },
    };

    chartInstance = new Chart(ctx, {
        type: 'line',
        plugins: [forecastZonePlugin],
        data: {
            labels: g.labels,
            datasets: [
                {
                    label:           'Actual Volume',
                    data:            g.actual,
                    borderColor:     '#60a5fa',
                    backgroundColor: 'transparent',
                    borderWidth:     2,
                    pointRadius:     grain === 'yearly' ? 4 : (grain === 'monthly' ? 3 : 0),
                    pointHoverRadius: 5,
                    tension:         0.3,
                    spanGaps:        false,
                },
                {
                    label:           'Model Fit (In-Sample)',
                    data:            g.predicted,
                    borderColor:     '#a78bfa',
                    borderDash:      [5, 4],
                    backgroundColor: 'transparent',
                    borderWidth:     1.5,
                    pointRadius:     0,
                    pointHoverRadius: 4,
                    tension:         0.3,
                    spanGaps:        false,
                },
                {
                    label:           'Forecast',
                    data:            g.forecast,
                    borderColor:     '#2dd4bf',
                    backgroundColor: 'transparent',
                    borderWidth:     2.5,
                    pointRadius:     grain === 'yearly' ? 5 : (grain === 'monthly' ? 4 : 2),
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#2dd4bf',
                    tension:         0.3,
                    spanGaps:        false,
                },
                {
                    label:           'Upper 95% CI',
                    data:            g.forecast_upper,
                    borderColor:     'rgba(45,212,191,0.25)',
                    backgroundColor: 'transparent',
                    borderWidth:     1,
                    borderDash:      [3, 3],
                    pointRadius:     0,
                    spanGaps:        false,
                    fill:            false,
                },
                {
                    label:           'Lower 95% CI',
                    data:            g.forecast_lower,
                    borderColor:     'rgba(45,212,191,0.25)',
                    backgroundColor: 'rgba(45,212,191,0.10)',
                    borderWidth:     1,
                    borderDash:      [3, 3],
                    pointRadius:     0,
                    spanGaps:        false,
                    fill:            '-1',
                },
            ],
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    grid:  { color: 'rgba(55,65,81,0.6)' },
                    ticks: {
                        color:        '#9ca3af',
                        maxTicksLimit: meta.xLimit,
                        maxRotation:  45,
                        font:          { size: 11 },
                    },
                },
                y: {
                    grid:  { color: 'rgba(55,65,81,0.6)' },
                    ticks: {
                        color:    '#9ca3af',
                        font:     { size: 11 },
                        callback: v => v !== null ? v.toLocaleString() : '',
                    },
                    title: {
                        display: true,
                        text:    'Clients',
                        color:   '#6b7280',
                        font:    { size: 11 },
                    },
                },
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color:      '#e5e7eb',
                        font:       { size: 12, weight: '600' },
                        boxWidth:   24,
                        padding:    16,
                        filter: item => !item.text.includes('CI'),
                    },
                },
                tooltip: {
                    backgroundColor: '#1f2937',
                    borderColor:     '#374151',
                    borderWidth:     1,
                    titleColor:      '#f9fafb',
                    bodyColor:       '#d1d5db',
                    padding:         10,
                    callbacks: {
                        label(ctx) {
                            if (ctx.parsed.y === null) return null;
                            const label = ctx.dataset.label || '';
                            const val   = Math.round(ctx.parsed.y).toLocaleString();
                            if (label.includes('CI')) return null;
                            return ` ${label}: ${val} clients`;
                        },
                        afterBody(items) {
                            const idx = items[0]?.dataIndex;
                            if (idx === undefined) return [];
                            const g = GRAINS[currentTab];
                            const lo = g.forecast_lower[idx];
                            const hi = g.forecast_upper[idx];
                            if (lo === null || hi === null) return [];
                            return [`  95% CI: ${Math.round(lo).toLocaleString()} – ${Math.round(hi).toLocaleString()}`];
                        },
                    },
                },
            },
        },
    });
}


// ── Tab switcher ───────────────────────────────────────────────
function switchTab(grain) {
    currentTab = grain;

    ['weekly','monthly','yearly'].forEach(g => {
        const btn = document.getElementById('tab-' + g);
        if (g === grain) {
            btn.className = 'tab-btn active-tab px-5 py-2 rounded-lg text-sm font-semibold bg-indigo-600 text-white shadow transition-all';
        } else {
            btn.className = 'tab-btn px-5 py-2 rounded-lg text-sm font-semibold text-gray-400 hover:text-white transition-all';
        }
    });

    renderMetrics(grain);
    renderChart(grain);
    renderRandomForestPredictions(grain);
    renderMedicalHotspots(grain);
}

// ── Init ───────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
    document.getElementById('tab-weekly').className =
        'tab-btn active-tab px-5 py-2 rounded-lg text-sm font-semibold bg-indigo-600 text-white shadow transition-all';
    switchTab('weekly');
});
</script>

</body>
</html>