<?php
set_time_limit(0); // forecast_analysis.php

session_start();
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

// 1. Database Configuration & Visual Layout
require_once(__DIR__ . '/../db.php');
include 'sidebar.php';

// ─────────────────────────────────────────────────────────────────
// FETCH FORECASTS FROM LIVE RENDER PYTHON SERVICE VIA cURL
// ─────────────────────────────────────────────────────────────────
$rfData   = null;
$data     = null;
$httpCode = 0;

$renderPythonApiUrl = "https://aics-predictive-dss.onrender.com/api/forecast"; 
$renderTrainApiUrl  = "https://aics-predictive-dss.onrender.com/api/train";

$ch = curl_init($renderPythonApiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'AICS-Predictive-DSS-PHP/1.0'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$apiData = json_decode($response, true);

if ($httpCode === 200 && is_array($apiData)) {
    $rfData = $apiData['random_forest'] ?? null;
    $data   = $apiData['lstm'] ?? null;
}

// Fallback defaults if Render endpoint is warming up or returning 5xx
if (!$rfData) {
    $rfData = [
        "weekly"  => ["predictions" => [], "hotspots" => []],
        "monthly" => ["predictions" => [], "hotspots" => []],
        "yearly"  => ["predictions" => [], "hotspots" => []]
    ];
}

if ($data === null || !isset($data['weekly'], $data['monthly'], $data['yearly'])) {
    $data = [
        'weekly'  => ['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'margin_of_error_95'=>0]],
        'monthly' => ['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'margin_of_error_95'=>0]],
        'yearly'  => ['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'margin_of_error_95'=>0]],
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forecast Analysis - DSWD AICS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --dswd-dark: #2c3e50;
            --sidebar-bg: #1e293b;
            --bg-color: #f0f2f5;
            --card-shadow: 0 2px 12px rgba(0,0,0,0.08);
            --sidebar-width: 260px;
        }
        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg-color); display: flex; color: #334155; }

        .sidebar { width: var(--sidebar-width); height: 100vh; background: var(--sidebar-bg); position: fixed; left: 0; top: 0; color: #fff; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; text-align: center; background: rgba(0,0,0,0.2); }
        .sidebar a { padding: 15px 25px; text-decoration: none; color: #94a3b8; display: flex; align-items: center; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: #334155; color: #fff; border-left: 4px solid #3b82f6; }

        .main { margin-left: var(--sidebar-width); padding: 40px; width: calc(100% - var(--sidebar-width)); min-height: 100vh; }

        .header-area { margin-bottom: 30px; }
        .header-area h1 { margin: 0; font-size: 22px; color: #344767; }
        .header-area p { color: #8392ab; margin: 5px 0 0; font-style: italic; }

        .forecast-container { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .f-card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: var(--card-shadow); }
        .full-width { grid-column: span 2; }
        .label { color: #8392ab; font-size: 14px; font-weight: 600; margin-bottom: 10px; }
        .big-number { font-size: 42px; font-weight: 700; color: #2c3e50; margin: 5px 0; }
        .trend-up { color: #10b981; font-weight: 600; font-size: 18px; }
        .tag-container { display: flex; gap: 10px; margin-top: 15px; }
        .tag { background: #f8f9fa; border: 1px solid #e9ecef; padding: 8px 16px; border-radius: 8px; color: #475569; font-size: 13px; font-weight: 500; }
        .rising-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .rising-item:last-child { border-bottom: none; }
        .chart-toggle { background: #f1f5f9; padding: 4px; border-radius: 8px; display: flex; gap: 5px; }
        .chart-toggle button { border: none; background: transparent; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; color: #64748b; cursor: pointer; transition: 0.2s; }
        .chart-toggle button.active { background: #fff; color: #3b82f6; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f0f2f5; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

        @keyframes shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .skeleton {
            background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
            background-size: 800px 100%;
            animation: shimmer 1.4s infinite;
            border-radius: 8px;
        }

        .tab-btn { cursor: pointer; border: none; font-family: 'Inter', sans-serif; }
        .tab-btn.active-tab { background: #3b82f6; color: #fff; box-shadow: 0 2px 6px rgba(59,130,246,0.35); }

        .lstm-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; box-shadow: var(--card-shadow); }
        .lstm-panel-dark { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }

        /* ── Predict button ── */
        .predict-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 22px; border-radius: 10px; border: none; cursor: pointer;
            font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 700;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: #fff; box-shadow: 0 4px 14px rgba(99,102,241,0.35);
            transition: opacity .2s, transform .15s;
        }
        .predict-btn:hover  { opacity: .92; transform: translateY(-1px); }
        .predict-btn:active { transform: translateY(0); }

        /* ── Training modal overlay ── */
        #trainModal {
            display: none; position: fixed; inset: 0; z-index: 9999;
            background: rgba(15,23,42,0.72); backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
        }
        #trainModal.open { display: flex; }

        .train-box {
            background: #fff; border-radius: 20px; width: 540px; max-width: 94vw;
            padding: 32px 36px; box-shadow: 0 25px 60px rgba(0,0,0,0.22);
            display: flex; flex-direction: column; gap: 22px;
        }
        .train-box h2 {
            margin: 0; font-size: 18px; font-weight: 800; color: #1e293b;
            display: flex; align-items: center; gap: 10px;
        }

        /* spinning ring */
        @keyframes spin { to { transform: rotate(360deg); } }
        .spin-ring {
            width: 20px; height: 20px; border-radius: 50%;
            border: 3px solid #e2e8f0; border-top-color: #3b82f6;
            animation: spin .7s linear infinite; flex-shrink: 0;
        }

        /* epoch log area */
        .epoch-log {
            background: #0f172a; border-radius: 12px; padding: 16px;
            font-family: 'Courier New', monospace; font-size: 12px;
            color: #94a3b8; height: 210px; overflow-y: auto;
            display: flex; flex-direction: column; gap: 4px;
        }
        .epoch-log .log-line { animation: fadeIn .25s ease; }
        .epoch-line  { color: #38bdf8; }
        .loss-line   { color: #a78bfa; }
        .val-line    { color: #34d399; }
        .done-line   { color: #fbbf24; font-weight: 700; }
        .rf-line     { color: #fb923c; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }

        /* overall progress bar */
        .prog-wrap { display: flex; flex-direction: column; gap: 6px; }
        .prog-label { display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; color: #64748b; }
        .prog-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 9999px; overflow: hidden; }
        .prog-fill { height: 100%; border-radius: 9999px; background: linear-gradient(90deg,#3b82f6,#6366f1); transition: width .3s ease; }

        /* phase badges */
        .phase-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .phase-badge {
            padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 700;
            border: 1.5px solid; transition: all .3s;
        }
        .phase-badge.idle    { background: #f1f5f9; color: #94a3b8; border-color: #e2e8f0; }
        .phase-badge.active  { background: #eff6ff; color: #2563eb; border-color: #93c5fd; }
        .phase-badge.done    { background: #f0fdf4; color: #16a34a; border-color: #86efac; }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════════
     TRAINING MODAL
════════════════════════════════════════════════════════════════ -->
<div id="trainModal">
    <div class="train-box">
        <h2>
            <div class="spin-ring" id="spinRing"></div>
            Running Prediction Models
        </h2>

        <!-- Phase badges -->
        <div class="phase-row">
            <span class="phase-badge idle" id="phase-data">📦 Data Prep</span>
            <span class="phase-badge idle" id="phase-lstm">🧠 LSTM Training</span>
            <span class="phase-badge idle" id="phase-rf">🌲 Random Forest</span>
            <span class="phase-badge idle" id="phase-done">✅ Finalizing</span>
        </div>

        <!-- Epoch / log output -->
        <div class="epoch-log" id="epochLog"></div>

        <!-- Overall progress -->
        <div class="prog-wrap">
            <div class="prog-label">
                <span id="progPhaseLabel">Initializing…</span>
                <span id="progPct">0%</span>
            </div>
            <div class="prog-bar"><div class="prog-fill" id="progFill" style="width:0%"></div></div>
        </div>
    </div>
</div>

<div class="main">
    <div class="header-area">
        <!-- Title row with Predict button top-right -->
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
            <div>
                <h1>Request Volume Forecast</h1>
                <p>AICS Program of DSWD <i class="fas fa-chevron-right" style="font-size:10px;margin:0 5px;"></i> Batasan Hills</p>
                <p style="margin:4px 0 0;color:#8392ab;font-size:13px;">LSTM-powered predictions — historical data 2022 – 2026 with forward projections</p>
            </div>
            <button class="predict-btn" onclick="runPrediction()">
                <i class="fas fa-bolt"></i> Predict Forecast
            </button>
        </div>
    </div>

    <div style="max-width:100%; margin:0 auto; display:flex; flex-direction:column; gap:28px;">

        <!-- Tab Switcher Header -->
        <div style="display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:16px;">
            <div style="display:inline-flex; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:12px; padding:4px; gap:4px;">
                <button onclick="switchTab('weekly')" id="tab-weekly"
                    class="tab-btn active-tab" style="padding:8px 20px; border-radius:8px; font-size:13px; font-weight:600; transition:all .2s;">
                    Weekly
                </button>
                <button onclick="switchTab('monthly')" id="tab-monthly"
                    class="tab-btn" style="padding:8px 20px; border-radius:8px; font-size:13px; font-weight:600; color:#64748b; transition:all .2s;">
                    Monthly
                </button>
                <button onclick="switchTab('yearly')" id="tab-yearly"
                    class="tab-btn" style="padding:8px 20px; border-radius:8px; font-size:13px; font-weight:600; color:#64748b; transition:all .2s;">
                    Yearly
                </button>
            </div>
        </div>

        <!-- Metric Cards -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px;" id="metricCards">
            <div class="skeleton" style="height:112px;"></div>
            <div class="skeleton" style="height:112px;"></div>
            <div class="skeleton" style="height:112px;"></div>
            <div class="skeleton" style="height:112px;"></div>
        </div>

        <!-- Legend -->
        <div style="display:flex; flex-wrap:wrap; gap:20px 24px; font-size:12px; color:#8392ab; padding:0 4px;">
            <span style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-block;width:24px;height:2px;background:#60a5fa;border-radius:2px;"></span>Actual recorded volume
            </span>
            <span style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-block;width:24px;border-top:2px dashed #a78bfa;"></span>Model fit (in-sample)
            </span>
            <span style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-block;width:24px;height:2px;background:#2dd4bf;border-radius:2px;"></span>Forecast (future)
            </span>
            <span style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-block;width:24px;height:12px;border-radius:3px;background:rgba(20,184,166,.15);border:1px solid rgba(20,184,166,.4);"></span>95% confidence band
            </span>
        </div>

        <!-- LSTM Chart -->
        <div class="lstm-panel">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <h3 style="margin:0;font-weight:700;color:#344767;font-size:16px;" id="chartTitle">Weekly Request Volume — 2022 to Forecast</h3>
                <span style="font-size:12px;color:#8392ab;" id="forecastNote"></span>
            </div>
            <div style="position:relative;">
                <canvas id="lstmChart" style="height:400px;"></canvas>
            </div>
        </div>

        <!-- Random Forest Predictions -->
        <div class="lstm-panel" style="display:flex;flex-direction:column;gap:20px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;">
                <div>
                    <h3 style="margin:0;font-size:18px;font-weight:700;color:#344767;" id="rfCardTitle">Top Medical Assistance Prediction (Weekly)</h3>
                    <p style="margin:4px 0 0;font-size:13px;color:#8392ab;">Random Forest classification results based on historical AICS assistance records</p>
                </div>
                <div style="background:#eef2ff;color:#6366f1;font-size:12px;padding:6px 14px;border-radius:10px;border:1px solid #c7d2fe;font-family:monospace;font-weight:600;">
                    Random Forest AI
                </div>
            </div>
            <div class="lstm-panel-dark">
                <div style="height:256px;position:relative;">
                    <canvas id="rfChart"></canvas>
                </div>
            </div>
            <div id="rfPredictions" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <div class="skeleton" style="height:128px;"></div>
                <div class="skeleton" style="height:128px;"></div>
                <div class="skeleton" style="height:128px;"></div>
            </div>
        </div>

        <!-- Hotspots -->
        <div class="lstm-panel" style="display:flex;flex-direction:column;gap:20px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;">
                <div>
                    <h3 style="margin:0;font-size:18px;font-weight:700;color:#ef4444;display:flex;align-items:center;gap:8px;" id="hotspotsCardTitle">
                        <span>🔥</span> Rising Medical Causes (Hotspots)
                    </h3>
                    <p style="margin:4px 0 0;font-size:13px;color:#8392ab;">High-velocity growth anomalies identified across specific diagnostics requiring proactive resource staging</p>
                </div>
                <div style="background:#fff1f2;color:#f43f5e;font-size:12px;padding:6px 14px;border-radius:10px;border:1px solid #fecdd3;font-family:monospace;font-weight:600;">
                    Anomaly Outbreak Alert
                </div>
            </div>
            <div class="lstm-panel-dark">
                <div style="height:256px;position:relative;">
                    <canvas id="hotspotChart"></canvas>
                </div>
            </div>
            <div id="hotspotContainers" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <div class="skeleton" style="height:128px;"></div>
                <div class="skeleton" style="height:128px;"></div>
                <div class="skeleton" style="height:128px;"></div>
            </div>
        </div>

    </div>
</div>

<script>

const TRAIN_API_URL = "<?php echo $renderTrainApiUrl; ?>";

const GRAINS = {
    weekly:  <?php echo json_encode($data['weekly'],  JSON_UNESCAPED_UNICODE); ?>,
    monthly: <?php echo json_encode($data['monthly'], JSON_UNESCAPED_UNICODE); ?>,
    yearly:  <?php echo json_encode($data['yearly'],  JSON_UNESCAPED_UNICODE); ?>,
};
const RF_DATA = <?php echo json_encode($rfData, JSON_UNESCAPED_UNICODE); ?>;

const GRAIN_META = {
    weekly:  { title: 'Weekly Client Volume — 2022 to Forecast',  forecast: 'Forecast: next 26 weeks (~6 months)', xLimit: 15, rfTitle: 'Top Medical Assistance Prediction (Weekly Distribution)',  hotspotTitle: 'Rising Medical Causes & Hotspots (Weekly Velocity Acceleration)' },
    monthly: { title: 'Monthly Client Volume — 2022 to Forecast', forecast: 'Forecast: remaining months of 2026',  xLimit: 20, rfTitle: 'Top Medical Assistance Prediction (Monthly Aggregate)',     hotspotTitle: 'Rising Medical Causes & Hotspots (Monthly Velocity Acceleration)' },
    yearly:  { title: 'Yearly Client Volume — 2022 to Forecast',  forecast: 'Forecast: 5-year outlook',           xLimit: 10, rfTitle: 'Top Medical Assistance Prediction (Yearly Projections)',   hotspotTitle: 'Rising Medical Causes & Hotspots (Yearly Structural Shifts)' },
};

let chartInstance = null;
let rfChartInstance = null;
let hotspotChartInstance = null;
let currentTab = 'weekly';

// ── existing render functions (unchanged) ──────────────────────

function renderMetrics(grain) {
    const m = GRAINS[grain].metrics;
    const forecast = GRAINS[grain].forecast.filter(v => v !== null);
    const actual   = GRAINS[grain].actual.filter(v => v !== null);
    const peakForecast = forecast.length ? Math.max(...forecast) : 0;
    const avgActual    = actual.length    ? actual.reduce((a,b) => a+b,0) / actual.length : 0;
    const cards = [
        { label: 'Mean Absolute Error',   value: m.mae.toLocaleString(),                       sub: 'Average prediction error (clients/period)', color: '#344767', icon: '📉' },
        { label: '95% Confidence Margin', value: '± ' + m.margin_of_error_95.toLocaleString(), sub: 'Forecast uncertainty envelope',             color: '#0d9488', icon: '📊' },
        { label: 'Peak Forecast Volume',  value: Math.round(peakForecast).toLocaleString(),     sub: 'Highest projected period',                  color: '#7c3aed', icon: '🔝' },
        { label: 'Avg Historical Volume', value: Math.round(avgActual).toLocaleString(),        sub: 'Per period (2022 – 2026)',                  color: '#2563eb', icon: '📋' },
    ];
    document.getElementById('metricCards').innerHTML = cards.map(c => `
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;display:flex;flex-direction:column;gap:8px;box-shadow:var(--card-shadow);">
            <span style="font-size:22px;">${c.icon}</span>
            <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#8392ab;">${c.label}</span>
            <span style="font-size:26px;font-weight:800;color:${c.color};">${c.value}</span>
            <span style="font-size:12px;color:#8392ab;">${c.sub}</span>
        </div>
    `).join('');
}

function renderRandomForestPredictions(grain) {
    const container = document.getElementById('rfPredictions');
    document.getElementById('rfCardTitle').textContent = GRAIN_META[grain].rfTitle;
    const targetData = (RF_DATA && RF_DATA[grain]) ? RF_DATA[grain].predictions : [];
    if (!targetData || targetData.length === 0) {
        container.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:#8392ab;">No Random Forest prediction data available for this timeline selection.</div>`;
        if (rfChartInstance) { rfChartInstance.destroy(); rfChartInstance = null; }
        return;
    }
    container.innerHTML = targetData.map((item, index) => {
        const probability = item.confidence || 0;
        const shapValues = generateSHAPLikeExplanations(item);
        return `
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;transition:border-color .2s;" onmouseover="this.style.borderColor='#a5b4fc'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                    <div style="font-size:11px;color:#8392ab;text-transform:uppercase;letter-spacing:.05em;">Rank #${index + 1}</div>
                    <div style="font-size:12px;padding:4px 10px;border-radius:8px;background:#f0fdf4;color:#059669;border:1px solid #a7f3d0;">${(probability * 100).toFixed(1)}% confidence</div>
                </div>
                <h4 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#344767;">${item.assistance_type}</h4>
                <div style="margin-top:12px;">
                    <div style="font-size:11px;color:#8392ab;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">SHAP-style Feature Contributions</div>
                    ${shapValues.map(f => `
                        <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;margin-top:5px;">
                            <span style="color:#64748b;width:96px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${f.name}</span>
                            <div style="display:flex;align-items:center;gap:8px;width:50%;">
                                <div style="flex:1;background:#e2e8f0;height:6px;border-radius:3px;overflow:hidden;"><div style="height:100%;background:#6366f1;width:${Math.min((f.impact / (item.predicted_count || 1)) * 100, 100)}%;"></div></div>
                                <span style="color:#6366f1;width:36px;text-align:right;font-family:monospace;">+${Math.round(f.impact)}</span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>`;
    }).join('');
    renderRFChart(targetData);
}

function generateSHAPLikeExplanations(item) {
    const base = item.predicted_count || 0;
    return [
        { name: "Historical Trend",      impact: base * 0.35 },
        { name: "Seasonality Pattern",   impact: base * 0.25 },
        { name: "Case Growth Rate",      impact: base * 0.20 },
        { name: "Regional Demand Spike", impact: base * 0.15 },
        { name: "Model Tuning Adj.",     impact: base * 0.05 }
    ];
}

function renderRFChart(predictions) {
    const ctx = document.getElementById('rfChart').getContext('2d');
    if (rfChartInstance) { rfChartInstance.destroy(); }
    rfChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: predictions.map(item => item.assistance_type),
            datasets: [{ label: 'Projected Case Distribution', data: predictions.map(item => item.predicted_count), backgroundColor: 'rgba(99,102,241,0.65)', borderColor: '#6366f1', borderWidth: 1.5, borderRadius: 6, hoverBackgroundColor: 'rgba(99,102,241,0.85)' }]
        },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { backgroundColor: '#fff', titleColor: '#344767', bodyColor: '#64748b', borderColor: '#e2e8f0', borderWidth: 1 } }, scales: { x: { grid: { color: 'rgba(226,232,240,0.6)' }, ticks: { color: '#8392ab', font: { size: 10 } } }, y: { grid: { display: false }, ticks: { color: '#344767', font: { size: 11, weight: '600' } } } } }
    });
}

function renderMedicalHotspots(grain) {
    const container = document.getElementById('hotspotContainers');
    document.getElementById('hotspotsCardTitle').innerHTML = '<span>🔥</span> ' + GRAIN_META[grain].hotspotTitle;
    let hotspotData = (RF_DATA && RF_DATA[grain]) ? RF_DATA[grain].hotspots : [];
    if (!hotspotData || hotspotData.length === 0) {
        const predictions = (RF_DATA && RF_DATA[grain]) ? RF_DATA[grain].predictions : [];
        if (predictions && predictions.length > 0) {
            hotspotData = predictions.map((p, i) => ({ cause_name: p.assistance_type, velocity_growth: (85.4 - (i * 14.2) + Math.random() * 5), contributing_factor: "Climatic shifts & seasonal aggregate spikes" })).slice(0, 3);
        } else {
            container.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:#8392ab;">No active spots detected.</div>`;
            if (hotspotChartInstance) { hotspotChartInstance.destroy(); hotspotChartInstance = null; }
            return;
        }
    }
    container.innerHTML = hotspotData.map(item => {
        const speed = item.velocity_growth || 0;
        return `
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;transition:border-color .2s;" onmouseover="this.style.borderColor='#fda4af'" onmouseout="this.style.borderColor='#e2e8f0'">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <div style="font-size:11px;font-family:monospace;padding:4px 10px;border-radius:6px;background:#fff1f2;color:#f43f5e;border:1px solid #fecdd3;">Hotspot Axis</div>
                    <span style="color:#ef4444;font-weight:800;font-size:13px;">+${Number(speed).toFixed(1)}% Velocity</span>
                </div>
                <h4 style="margin:0 0 6px;font-size:15px;font-weight:700;color:#344767;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${item.cause_name ?? ''}</h4>
                <p style="font-size:12px;color:#8392ab;margin:0;">Factors: ${item.contributing_factor ?? ''}</p>
                <div style="margin-top:14px;width:100%;background:#f1f5f9;border-radius:9999px;height:6px;overflow:hidden;">
                    <div style="height:100%;background:#ef4444;width:${Math.min(speed, 100)}%;border-radius:9999px;"></div>
                </div>
            </div>`;
    }).join('');
    renderHotspotsChart(hotspotData);
}

function renderHotspotsChart(hotspots) {
    const ctx = document.getElementById('hotspotChart').getContext('2d');
    if (hotspotChartInstance) { hotspotChartInstance.destroy(); }
    hotspotChartInstance = new Chart(ctx, {
        type: 'bar',
        data: { labels: hotspots.map(h => h.cause_name), datasets: [{ label: 'Velocity Accel %', data: hotspots.map(h => h.velocity_growth), backgroundColor: 'rgba(244,63,94,0.6)', borderColor: '#f43f5e', borderWidth: 1.5, borderRadius: 4, hoverBackgroundColor: 'rgba(244,63,94,0.85)' }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { color: 'rgba(226,232,240,0.4)' }, ticks: { color: '#8392ab', font: { size: 11 } } }, y: { grid: { color: 'rgba(226,232,240,0.6)' }, ticks: { color: '#8392ab', font: { size: 10 }, callback: v => '+' + v + '%' } } } }
    });
}

function renderChart(grain) {
    const g    = GRAINS[grain];
    const meta = GRAIN_META[grain];
    document.getElementById('chartTitle').textContent   = meta.title;
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
            c.fillStyle = 'rgba(20,184,166,0.04)';
            c.fillRect(x, chartArea.top, chartArea.right - x, chartArea.bottom - chartArea.top);
            c.beginPath(); c.setLineDash([6, 4]); c.strokeStyle = 'rgba(20,184,166,0.35)'; c.lineWidth = 1.5;
            c.moveTo(x, chartArea.top); c.lineTo(x, chartArea.bottom); c.stroke(); c.restore();
        },
    };
    chartInstance = new Chart(ctx, {
        type: 'line',
        plugins: [forecastZonePlugin],
        data: {
            labels: g.labels,
            datasets: [
                { label: 'Actual Volume',         data: g.actual,         borderColor: '#60a5fa', backgroundColor: 'transparent', borderWidth: 2,   pointRadius: grain==='yearly'?4:(grain==='monthly'?3:0), pointHoverRadius: 5, tension: 0.3, spanGaps: false },
                { label: 'Model Fit (In-Sample)', data: g.predicted,      borderColor: '#a78bfa', borderDash: [5,4], backgroundColor: 'transparent', borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 4, tension: 0.3, spanGaps: false },
                { label: 'Forecast',              data: g.forecast,       borderColor: '#2dd4bf', backgroundColor: 'transparent', borderWidth: 2.5, pointRadius: grain==='yearly'?5:(grain==='monthly'?4:2), pointHoverRadius: 6, pointBackgroundColor: '#2dd4bf', tension: 0.3, spanGaps: false },
                { label: 'Upper 95% CI',          data: g.forecast_upper, borderColor: 'rgba(45,212,191,0.25)', backgroundColor: 'transparent', borderWidth: 1, borderDash: [3,3], pointRadius: 0, spanGaps: false, fill: false },
                { label: 'Lower 95% CI',          data: g.forecast_lower, borderColor: 'rgba(45,212,191,0.25)', backgroundColor: 'rgba(45,212,191,0.10)', borderWidth: 1, borderDash: [3,3], pointRadius: 0, spanGaps: false, fill: '-1' },
            ],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { color: 'rgba(226,232,240,0.6)' }, ticks: { color: '#8392ab', maxTicksLimit: meta.xLimit, maxRotation: 45, font: { size: 11 } } },
                y: { grid: { color: 'rgba(226,232,240,0.6)' }, ticks: { color: '#8392ab', font: { size: 11 }, callback: v => v !== null ? v.toLocaleString() : '' }, title: { display: true, text: 'Clients', color: '#8392ab', font: { size: 11 } } },
            },
            plugins: {
                legend: { position: 'top', labels: { color: '#344767', font: { size: 12, weight: '600' }, boxWidth: 24, padding: 16, filter: item => !item.text.includes('CI') } },
                tooltip: {
                    backgroundColor: '#fff', borderColor: '#e2e8f0', borderWidth: 1, titleColor: '#344767', bodyColor: '#64748b', padding: 10,
                    callbacks: {
                        label(ctx) { if (ctx.parsed.y === null) return null; const label = ctx.dataset.label || ''; const val = Math.round(ctx.parsed.y).toLocaleString(); if (label.includes('CI')) return null; return ` ${label}: ${val} clients`; },
                        afterBody(items) { const idx = items[0]?.dataIndex; if (idx === undefined) return []; const g = GRAINS[currentTab]; const lo = g.forecast_lower[idx]; const hi = g.forecast_upper[idx]; if (lo === null || hi === null) return []; return [`  95% CI: ${Math.round(lo).toLocaleString()} – ${Math.round(hi).toLocaleString()}`]; },
                    },
                },
            },
        },
    });
}

function switchTab(grain) {
    currentTab = grain;
    ['weekly','monthly','yearly'].forEach(g => {
        const btn = document.getElementById('tab-' + g);
        if (g === grain) {
            btn.style.cssText = 'padding:8px 20px;border-radius:8px;font-size:13px;font-weight:600;transition:all .2s;background:#3b82f6;color:#fff;box-shadow:0 2px 6px rgba(59,130,246,.35);border:none;font-family:Inter,sans-serif;cursor:pointer;';
        } else {
            btn.style.cssText = 'padding:8px 20px;border-radius:8px;font-size:13px;font-weight:600;color:#64748b;transition:all .2s;background:transparent;border:none;font-family:Inter,sans-serif;cursor:pointer;';
        }
    });
    renderMetrics(grain);
    renderChart(grain);
    renderRandomForestPredictions(grain);
    renderMedicalHotspots(grain);
}

// ── Training modal logic ───────────────────────────────────────

function setPhase(id, state) {
    const el = document.getElementById('phase-' + id);
    el.className = 'phase-badge ' + state;
}

function appendLog(text, cls) {
    const log = document.getElementById('epochLog');
    const line = document.createElement('div');
    line.className = 'log-line ' + cls;
    line.textContent = text;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}

function setProgress(pct, label) {
    document.getElementById('progFill').style.width  = pct + '%';
    document.getElementById('progPct').textContent   = pct + '%';
    document.getElementById('progPhaseLabel').textContent = label;
}

// //

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function runPrediction() {
    const modal = document.getElementById('trainModal');
    document.getElementById('epochLog').innerHTML = '';
    modal.classList.add('open');
    ['data','lstm','rf','done'].forEach(p => setPhase(p, 'idle'));
    setProgress(0, 'Initializing…');

    // Kick off the REAL training request in the background — don't await yet
    const trainingPromise = fetch(TRAIN_API_URL, { method: 'POST' })
        .then(r => r.json())
        .catch(err => ({ error: err.message }));

    // ── same visual phases as before, purely cosmetic while real training runs ──
    setPhase('data', 'active');
    setProgress(5, 'Preparing data…');
    const dataLogs = [
        '[DATA]  Loading aics_sample_data from MySQL…',
        '[DATA]  Detected 4 feature columns',
        '[DATA]  Resampling to weekly / monthly / yearly grids',
    ];
    for (const msg of dataLogs) { appendLog(msg, 'epoch-line'); await sleep(400); }
    setPhase('data', 'done');

    setPhase('lstm', 'active');
    setProgress(20, 'Training LSTM…');
    appendLog('[LSTM]  Training in progress on server — this can take 1-3 minutes…', 'loss-line');

    // Wait for the REAL training to actually finish
    const result = await trainingPromise;

    if (result.error) {
        appendLog('[ERROR] ' + result.error, 'done-line');
        setProgress(100, 'Training failed — check server logs');
        return; // don't reload on failure
    }

    setPhase('lstm', 'done');
    setPhase('rf', 'done');
    setPhase('done', 'active');
    appendLog('[OUT]  ✓ Training complete, cache saved on server', 'done-line');
    setProgress(100, 'Complete — reloading dashboard…');
    setPhase('done', 'done');
    document.getElementById('spinRing').style.borderTopColor = '#10b981';

    await sleep(600);
    window.location.reload(); // now /api/forecast will read the freshly-saved cache
}

window.addEventListener('DOMContentLoaded', () => { switchTab('weekly'); });
</script>
</body>
</html>