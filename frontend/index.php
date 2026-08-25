<?php
set_time_limit(0);
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

// ─── SHARED PYTHON CONFIG ────────────────────────────────────────
$pythonPath = "C:\\Users\\A\\AppData\\Local\\Programs\\Python\\Python311\\python.exe";

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

// ─── PIPELINE A: LSTM ────────────────────────────────────────────
$lstmScriptPath = dirname(__DIR__) . "/backend/lstm_model.py";
$lstmProcess    = proc_open('"'.$pythonPath.'" "'.$lstmScriptPath.'"', $descriptorspec, $lstmPipes, __DIR__, $env, ['bypass_shell' => true]);
$lstmJsonData   = '';
$lstmErrorData  = '';

if (is_resource($lstmProcess)) {
    $lstmJsonData  = stream_get_contents($lstmPipes[1]);
    $lstmErrorData = stream_get_contents($lstmPipes[2]);
    fclose($lstmPipes[0]); fclose($lstmPipes[1]); fclose($lstmPipes[2]);
    proc_close($lstmProcess);
}

$lstmStart = strpos($lstmJsonData, '{');
if ($lstmStart !== false && $lstmStart > 0) $lstmJsonData = substr($lstmJsonData, $lstmStart);
$lstmData = json_decode($lstmJsonData, true);

if (!$lstmData || !isset($lstmData['weekly'], $lstmData['monthly'], $lstmData['yearly'])) {
    $lstmData = [
        'weekly'  => ['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'margin_of_error_95'=>0]],
        'monthly' => ['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'margin_of_error_95'=>0]],
        'yearly'  => ['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'margin_of_error_95'=>0]],
    ];
}

$lstm_val = 0;
foreach (($lstmData['monthly']['forecast'] ?? []) as $fv) {
    if ($fv !== null) { $lstm_val = round($fv); break; }
}

// ─── PIPELINE B: RANDOM FOREST ───────────────────────────────────
$rfScriptPath = dirname(__DIR__) . "/backend/random_forest.py";
$rfProcess    = proc_open('"'.$pythonPath.'" "'.$rfScriptPath.'"', $descriptorspec, $rfPipes, __DIR__, $env, ['bypass_shell' => true]);
$rfJsonData   = '';

if (is_resource($rfProcess)) {
    $rfJsonData = stream_get_contents($rfPipes[1]);
    fclose($rfPipes[0]); fclose($rfPipes[1]); fclose($rfPipes[2]);
    proc_close($rfProcess);
}

$rfStart = strpos($rfJsonData, '{');
if ($rfStart !== false && $rfStart > 0) $rfJsonData = substr($rfJsonData, $rfStart);
$rfData = json_decode($rfJsonData, true);

if (!$rfData) {
    $rfData = ["weekly"=>["predictions"=>[],"hotspots"=>[]],"monthly"=>["predictions"=>[],"hotspots"=>[]],"yearly"=>["predictions"=>[],"hotspots"=>[]]];
} else {
    foreach (['weekly','monthly','yearly'] as $g) {
        if (!isset($rfData[$g]))             $rfData[$g] = ["predictions"=>[],"hotspots"=>[]];
        if (!isset($rfData[$g]['hotspots'])) $rfData[$g]['hotspots'] = [];
    }
}

// ─── DATABASE ────────────────────────────────────────────────────
require_once(__DIR__ . '/../db.php');

$columns_res = $conn->query("SHOW COLUMNS FROM aics_sample_data");
$cols = [];
if ($columns_res) while($c = $columns_res->fetch_assoc()) $cols[] = $c['Field'];

$date_col   = in_array('request_date',   $cols) ? 'request_date'   : ($cols[0] ?? 'COL1');
$cause_col  = in_array('medical_cause',  $cols) ? 'medical_cause'  : ($cols[1] ?? 'COL2');
$type_col   = in_array('assistance_type',$cols) ? 'assistance_type': ($cols[2] ?? 'COL3');
$status_col = in_array('status',         $cols) ? 'status'         : ($cols[3] ?? 'status');

$total_requests = $conn->query("SELECT COUNT(*) as t FROM aics_sample_data")->fetch_assoc()['t'] ?? 0;

$pending_count = $approved_count = 0;
$sd = $conn->query("SELECT `$status_col` as s, COUNT(*) as q FROM aics_sample_data GROUP BY `$status_col`");
if ($sd) while ($r = $sd->fetch_assoc()) {
    if (strtolower($r['s']) === 'pending')  $pending_count  = $r['q'];
    if (strtolower($r['s']) === 'approved') $approved_count = $r['q'];
}

$top_3_assistance = [];
$tr = $conn->query("SELECT `$type_col` as type, COUNT(*) as count FROM aics_sample_data GROUP BY `$type_col` ORDER BY count DESC LIMIT 3");
if ($tr) while($r = $tr->fetch_assoc()) $top_3_assistance[$r['type']] = $r['count'];

$recent_records = [];
$rr = $conn->query("SELECT `$date_col` as date,`$cause_col` as cause,`$type_col` as type,`$status_col` as status FROM aics_sample_data ORDER BY `$date_col` DESC");
if ($rr) while($r = $rr->fetch_assoc()) $recent_records[] = ['date'=>$r['date'],'cause'=>$r['cause']??'Not Specified','type'=>$r['type']??'Not Specified','status'=>$r['status']??'Logged'];

$cause_counts = [];
foreach ($recent_records as $rec) if (!empty($rec['cause'])) $cause_counts[$rec['cause']] = ($cause_counts[$rec['cause']] ?? 0) + 1;
arsort($cause_counts);
$topCause = !empty($cause_counts) ? array_key_first($cause_counts) : "No Data";

$location_data = $top_3_barangays = [];
$loc_col = in_array('barangay',$cols) ? 'barangay' : (in_array('location',$cols) ? 'location' : null);
if ($loc_col) {
    $lat_col = in_array('latitude',$cols)  ? 'latitude'  : 'null';
    $lng_col = in_array('longitude',$cols) ? 'longitude' : 'null';
    $lr = $conn->query("SELECT `$loc_col` as name,$lat_col as lat,$lng_col as lng,COUNT(*) as count FROM aics_sample_data GROUP BY `$loc_col` ORDER BY count DESC");
    if ($lr) while($r = $lr->fetch_assoc()) $location_data[] = $r;
    $br = $conn->query("SELECT `$loc_col` as brgy,COUNT(*) as count FROM aics_sample_data GROUP BY `$loc_col` ORDER BY count DESC LIMIT 3");
    if ($br) while($r = $br->fetch_assoc()) $top_3_barangays[$r['brgy']] = $r['count'];
}

$conn->close();

echo "<script>
    const csvData       = " . json_encode($recent_records)              . ";
    const mapData       = " . json_encode($location_data)               . ";
    const GRAINS        = " . json_encode($lstmData, JSON_UNESCAPED_UNICODE) . ";
    const RF_DATA       = " . json_encode($rfData,   JSON_UNESCAPED_UNICODE) . ";
    const backendLstmVal= " . floatval($lstm_val)                        . ";
</script>";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://leaflet.github.io/Leaflet.heat/dist/leaflet-heat.js"></script>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        html{overflow-y:scroll}
        :root{--dswd-dark:#2c3e50;--sidebar-bg:#1e293b;--bg-color:#f8fafc;--card-shadow:0 4px 6px -1px rgba(0,0,0,0.1);--sidebar-width:260px}
        body{font-family:'Inter',sans-serif;margin:0;background:var(--bg-color);display:flex;color:#334155}
        .sidebar{width:var(--sidebar-width);height:100vh;background:var(--sidebar-bg);position:fixed;left:0;top:0;color:#fff;display:flex;flex-direction:column;z-index:1000}
        .sidebar-header{padding:30px 20px;text-align:center;background:rgba(0,0,0,0.2)}
        .sidebar a{padding:15px 25px;text-decoration:none;color:#94a3b8;display:flex;align-items:center;transition:all 0.3s ease;border-left:4px solid transparent}
        .sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,0.05);color:#fff;border-left:4px solid #3b82f6}
        .main{margin-left:260px;padding:40px;width:calc(100% - 260px);min-height:100vh}
        .header-area{margin-bottom:30px}
        .header-area h1{margin:0;font-size:24px;color:var(--dswd-dark)}
        .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:30px}
        .card{background:#fff;padding:24px;border-radius:12px;box-shadow:var(--card-shadow);border-bottom:4px solid #e2e8f0}
        .card.highlight{border-bottom-color:#3b82f6}
        .card.warning{border-bottom-color:#8b5cf6}
        .card h3{margin:0;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.05em}
        .card .value{font-size:28px;font-weight:700;color:#1e293b;margin:10px 0}
        .card .trend{font-size:13px;font-weight:600;display:flex;align-items:center;gap:5px}
        .top-3-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9}
        .top-3-label{font-size:13px;font-weight:600;color:#475569}
        .top-3-val{background:#eff6ff;color:#3b82f6;padding:2px 8px;border-radius:6px;font-weight:700;font-size:12px}
        .grid-container{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:30px}
        .section-box{background:#fff;padding:25px;border-radius:12px;box-shadow:var(--card-shadow)}
        .section-box h2{font-size:18px;margin:0 0 20px 0;color:var(--dswd-dark)}
        .chart-controls{display:flex;gap:5px;background:#f1f5f9;padding:4px;border-radius:8px}
        .tgl-btn{padding:6px 12px;border:none;background:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;color:#64748b;transition:0.2s;font-family:'Inter',sans-serif}
        .tgl-btn.active{background:#fff;color:#3b82f6;box-shadow:0 2px 4px rgba(0,0,0,0.05)}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        table th{text-align:left;padding:12px;border-bottom:2px solid #f1f5f9;color:#94a3b8;font-size:11px;text-transform:uppercase;font-weight:700}
        table td{padding:14px 12px;border-bottom:1px solid #f1f5f9;font-size:14px}
        .trend-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f8fafc}
        .trend-dot{width:8px;height:8px;border-radius:50%;margin-right:10px;flex-shrink:0}

        /* ── Insight panels ── */
        .insight-panel{border-radius:8px;padding:14px 16px;margin-bottom:10px;transition:background 0.3s,border-color 0.3s}
        .insight-panel-a{border-left:4px solid #3b82f6;background:#eff6ff}
        .insight-panel-b{border-left:4px solid #8b5cf6;background:#f5f3ff}
        .insight-panel-c{border-left:4px solid #0891b2;background:#ecfeff;margin-bottom:16px}
        .insight-grain-badge{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:2px 7px;border-radius:4px;margin-bottom:6px}
        .insight-panel-a .insight-grain-badge{background:#dbeafe;color:#1e40af}
        .insight-panel-b .insight-grain-badge{background:#ede9fe;color:#5b21b6}
        .insight-panel-c .insight-grain-badge{background:#cffafe;color:#164e63}
        .insight-title{font-weight:700;font-size:14px;margin:0 0 4px 0;line-height:1.3}
        .insight-panel-a .insight-title{color:#1e3a8a}
        .insight-panel-b .insight-title{color:#4c1d95}
        .insight-panel-c .insight-title{color:#164e63}
        .insight-desc{font-size:12px;margin:0;line-height:1.5}
        .insight-panel-a .insight-desc{color:#1e40af}
        .insight-panel-b .insight-desc{color:#5b21b6}
        .insight-panel-c .insight-desc{color:#155e75}

        #map{height:250px;width:100%;border-radius:8px;margin-top:15px;z-index:1}
        .map-stats{display:flex;justify-content:space-between;align-items:center}

        /* ── Forecast section ── */
        .forecast-section{background:#fff;border-radius:12px;box-shadow:var(--card-shadow);padding:28px;margin-bottom:30px;border:1px solid #e2e8f0}
        .forecast-section h2{font-size:18px;margin:0 0 6px 0;color:var(--dswd-dark)}
        .forecast-metric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:20px 0}
        .fc-metric{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px}
        .fc-metric .fc-icon{font-size:20px;margin-bottom:6px}
        .fc-metric .fc-label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em}
        .fc-metric .fc-val{font-size:22px;font-weight:800;color:#1e293b;margin:4px 0 2px}
        .fc-metric .fc-sub{font-size:11px;color:#94a3b8}
        .fc-legend{display:flex;flex-wrap:wrap;gap:16px;font-size:11px;color:#64748b;margin:10px 0}
        .fc-legend span{display:flex;align-items:center;gap:6px}
        .fc-line{display:inline-block;width:22px;height:2px;border-radius:2px}
        #lstmChart{height:340px !important}
    </style>
</head>
<body>

<?php if(file_exists('sidebar.php')) include 'sidebar.php'; ?>

<div class="main">
    <div class="header-area">
        <h1>Predictive Decision Support Dashboard</h1>
        <p>AICS Program Analytics — Batasan Hills</p>
    </div>

    <!-- KPI CARDS -->
    <div class="cards">
        <div class="card">
            <h3>Total Requests</h3>
            <div class="value"><?php echo number_format($total_requests); ?></div>
            <div class="trend" style="color:#10b981"><i class="fas fa-database"></i> Total Applicant</div>
        </div>
        <div class="card warning">
            <h3>Queue Overview</h3>
            <div class="value"><?php echo number_format($pending_count); ?> <span style="font-size:14px;color:#64748b;font-weight:400">Pending</span></div>
            <div class="trend" style="color:#8b5cf6"><i class="fas fa-clock"></i> <?php echo number_format($approved_count); ?> Approved</div>
        </div>
        <div class="card">
            <h3>Top 3 Assistance Types</h3>
            <div style="margin-top:10px">
                <?php if (!empty($top_3_assistance)): ?>
                    <?php foreach ($top_3_assistance as $tn => $tc): ?>
                        <div class="top-3-item">
                            <span class="top-3-label"><?php echo htmlspecialchars($tn ?: 'Unknown'); ?></span>
                            <span class="top-3-val"><?php echo number_format($tc); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="top-3-item"><span class="top-3-label">No Data Available</span></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <h3>Top 3 Barangays</h3>
            <div style="margin-top:10px">
                <?php if (!empty($top_3_barangays)): ?>
                    <?php foreach ($top_3_barangays as $bn => $bc): ?>
                        <div class="top-3-item">
                            <span class="top-3-label"><?php echo htmlspecialchars($bn ?: 'Unknown'); ?></span>
                            <span class="top-3-val" style="background:#f0fdf4;color:#16a34a"><?php echo number_format($bc); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="top-3-item"><span class="top-3-label">No Data Available</span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- HEATMAP + INSIGHTS ROW -->
    <div class="grid-container">
        <div class="section-box">
            <div class="map-stats">
                <h2>Geographic Demand Heatmap</h2>
                <small style="color:#64748b"><i class="fas fa-map-marker-alt"></i> Quezon City Focus</small>
            </div>
            <div id="map"></div>
        </div>

        <!-- DECISION INSIGHTS — no toggle; syncs automatically with forecast tabs -->
        <div class="section-box">
            <h2 style="margin-bottom:14px">Decision Insights</h2>

            <!-- Pipeline A: Volume Forecast -->
            <div class="insight-panel insight-panel-a" id="pa-panel">
                <div class="insight-grain-badge" id="pa-badge">Pipeline A · Monthly</div>
                <div class="insight-title" id="pa-title">Calculating…</div>
                <p class="insight-desc" id="pa-desc"></p>
            </div>

            <!-- Pipeline B: Medical Trends -->
            <div class="insight-panel insight-panel-b" id="pb-panel">
                <div class="insight-grain-badge" id="pb-badge">Pipeline B · Monthly</div>
                <div class="insight-title" id="pb-title">Analyzing…</div>
                <p class="insight-desc" id="pb-desc"></p>
            </div>

            <!-- Pipeline C: Strategic / Budget -->
            <div class="insight-panel insight-panel-c" id="pc-panel">
                <div class="insight-grain-badge" id="pc-badge">Pipeline C · Monthly</div>
                <div class="insight-title" id="pc-title">Budget Planning</div>
                <p class="insight-desc" id="pc-desc"></p>
            </div>

            <small style="color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Pipeline B — Cause Breakdown</small>
            <div style="margin-top:10px" id="rf-trends-container"></div>

            <p style="font-size:12px;color:#64748b;margin-top:16px;line-height:1.6;background:#f8fafc;padding:14px;border-radius:8px;border-left:3px solid #3b82f6">
                <strong>Strategic Insight:</strong> Automated clustering suggests
                <strong id="live-top-cause"><?php echo htmlspecialchars($topCause); ?></strong> remains the primary driver.
            </p>
        </div>
    </div>

    <!-- VOLUME FORECAST SECTION -->
    <div class="forecast-section">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:4px">
            <div>
                <h2>AICS Client Volume Forecast</h2>
                <p style="font-size:12px;color:#64748b;margin:0">LSTM-powered predictions — historical data 2022–2026 with forward projections</p>
            </div>
            <div class="chart-controls">
                <button id="btn-weekly"  class="tgl-btn"        onclick="switchGrain('weekly')">Weekly</button>
                <button id="btn-monthly" class="tgl-btn active" onclick="switchGrain('monthly')">Monthly</button>
                <button id="btn-yearly"  class="tgl-btn"        onclick="switchGrain('yearly')">Yearly</button>
            </div>
        </div>

        <div class="forecast-metric-grid" id="fcMetricCards"></div>

        <div class="fc-legend">
            <span><span class="fc-line" style="background:#3b82f6"></span>Actual recorded volume</span>
            <span><span class="fc-line" style="background:#a78bfa;border-top:2px dashed #a78bfa;height:0"></span>Model fit (in-sample)</span>
            <span><span class="fc-line" style="background:#0d9488"></span>Forecast (future)</span>
            <span><span class="fc-line" style="background:rgba(13,148,136,.25);border:1px solid rgba(13,148,136,.4);height:10px;border-radius:3px"></span>95% confidence band</span>
        </div>

        <div style="position:relative;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <span id="fcChartTitle" style="font-size:13px;font-weight:700;color:#1e293b"></span>
                <span id="fcForecastNote" style="font-size:11px;color:#94a3b8"></span>
            </div>
            <canvas id="lstmChart"></canvas>
        </div>
    </div>

    <!-- RECENT RECORDS -->
    <div class="section-box">
        <h2>Recent Request Records</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th><th>Medical Cause</th><th>Assistance Type</th><th>Status</th>
                </tr>
            </thead>
            <tbody id="table-body"></tbody>
        </table>
    </div>
</div>

<script>
// ─── State ────────────────────────────────────────────────────────
let fcChartInstance = null;
let currentGrain    = 'monthly';

const GRAIN_LABEL = { weekly: 'Weekly', monthly: 'Monthly', yearly: 'Yearly' };

const FC_META = {
    weekly:  { title:'Weekly Client Volume — 2022 to Forecast',  forecast:'Forecast: next 26 weeks (~6 months)', xLimit:15 },
    monthly: { title:'Monthly Client Volume — 2022 to Forecast', forecast:'Forecast: remaining months of 2026',  xLimit:20 },
    yearly:  { title:'Yearly Client Volume — 2022 to Forecast',  forecast:'Forecast: 5-year outlook',           xLimit:10 },
};

// ─── Master entry point ───────────────────────────────────────────
// Every UI change flows through here. Order matters:
// 1. toggle buttons  2. insights  3. metrics  4. chart
function switchGrain(grain) {
    currentGrain = grain;

    // 1. Sync forecast toggle buttons
    ['weekly','monthly','yearly'].forEach(g =>
        document.getElementById('btn-' + g).classList.toggle('active', g === grain)
    );

    // 2. Compute the PEAK (max) forecast value for this grain — same value shown in the metric card
    const forecastNonNull = (GRAINS[grain].forecast || []).filter(v => v !== null);
    const forecastVal     = forecastNonNull.length ? Math.max(...forecastNonNull) : backendLstmVal;

    // 3. Update all three insight panels (they read `grain` and `forecastVal`)
    renderInsightPanelA(grain, forecastVal);
    renderInsightPanelB(grain, forecastVal);
    renderInsightPanelC(grain, forecastVal);

    // 4. Render metric cards and chart
    renderMetrics(grain);
    renderChart(grain);
}

// ─── Pipeline A: Volume Forecast Insight ─────────────────────────
function renderInsightPanelA(grain, forecastVal) {
    const actual  = (GRAINS[grain].actual || []).filter(v => v !== null);
    const lastVal = actual.length ? actual[actual.length - 1] : 0;
    const growth  = (((forecastVal - lastVal) / (lastVal || 1)) * 100).toFixed(1);
    const isSurge = parseFloat(growth) > 10;

    document.getElementById('pa-badge').textContent  = 'Pipeline A · ' + GRAIN_LABEL[grain];
    document.getElementById('pa-title').innerHTML    = isSurge
        ? `<i class="fas fa-chart-line"></i> Forecasted Surge (${growth}%)`
        : `<i class="fas fa-check-circle"></i> Normal Volume (${growth}%)`;
    document.getElementById('pa-desc').textContent   = isSurge
        ? `Expecting ${Math.round(forecastVal).toLocaleString()} total requests. High workload anticipated for this ${grain} period.`
        : `Growth at ${growth}%. Operations expected to remain steady for the ${grain} period.`;

    const panel = document.getElementById('pa-panel');
    if (isSurge) {
        panel.style.borderLeftColor = '#ef4444';
        panel.style.background      = '#fef2f2';
        document.getElementById('pa-badge').style.cssText = 'background:#fee2e2;color:#991b1b;display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:2px 7px;border-radius:4px;margin-bottom:6px';
        document.getElementById('pa-title').style.color   = '#7f1d1d';
        document.getElementById('pa-desc').style.color    = '#991b1b';
    } else {
        panel.style.borderLeftColor = '#10b981';
        panel.style.background      = '#f0fdf4';
        document.getElementById('pa-badge').style.cssText = 'background:#dcfce7;color:#14532d;display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:2px 7px;border-radius:4px;margin-bottom:6px';
        document.getElementById('pa-title').style.color   = '#14532d';
        document.getElementById('pa-desc').style.color    = '#166534';
    }
}

// ─── Pipeline B: Medical Cause Trend Insight ─────────────────────
function renderInsightPanelB(grain, forecastVal) {
    const now = new Date();

    // Bucket csvData into the selected grain window
    const counts = {};
    let total = 0;
    csvData.forEach(row => {
        const d = new Date(row.date);
        if (isNaN(d) || !row.cause) return;
        let inWindow = false;
        if      (grain === 'weekly')  inWindow = (now - d) <= 7 * 24 * 60 * 60 * 1000;
        else if (grain === 'monthly') inWindow = d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
        else if (grain === 'yearly')  inWindow = d.getFullYear() === now.getFullYear();
        if (inWindow) { counts[row.cause] = (counts[row.cause] || 0) + 1; total++; }
    });

    // Fall back to all-time data if no records in the grain window
    const useAllTime = total === 0;
    if (useAllTime) {
        csvData.forEach(row => {
            if (!row.cause) return;
            counts[row.cause] = (counts[row.cause] || 0) + 1;
            total++;
        });
    }

    const sorted = Object.entries(counts).sort((a, b) => b[1] - a[1]);
    if (!sorted.length) return;

    const [topCause, topCount] = sorted[0];
    const demandRatio          = topCount / (total || 1);
    const predictedCauseVol    = Math.round(forecastVal * demandRatio);
    const grainLabel           = GRAIN_LABEL[grain];
    const windowNote           = useAllTime ? '(all-time data)' : `(${grainLabel.toLowerCase()} window)`;

    document.getElementById('pb-badge').textContent = 'Pipeline B · ' + grainLabel;
    document.getElementById('pb-title').innerHTML   =
        `<i class="fas fa-stethoscope"></i> ${grainLabel} Forecast: ${topCause}`;
    document.getElementById('pb-desc').textContent  =
        `Of the ${Math.round(forecastVal).toLocaleString()} predicted requests ${windowNote}, `
        + `approx. ${predictedCauseVol.toLocaleString()} are expected for ${topCause}.`;

    // Cause breakdown bars
    const container = document.getElementById('rf-trends-container');
    if (container) {
        container.innerHTML = sorted.slice(0, 4).map(([cause, count]) => {
            const pct      = ((count / total) * 100).toFixed(1);
            const isTop    = cause === topCause;
            const dotColor = isTop ? '#8b5cf6' : '#94a3b8';
            return `<div class="trend-row">
                <div style="display:flex;align-items:center;flex:1;min-width:0">
                    <div class="trend-dot" style="background:${dotColor}"></div>
                    <span style="font-size:13px;font-weight:${isTop?'700':'500'};color:${isTop?'#1e293b':'#475569'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${cause}</span>
                </div>
                <span style="font-size:11px;font-weight:700;color:#64748b;margin-left:8px">${pct}%</span>
            </div>`;
        }).join('');
    }

    // Update strategic insight chip
    const chip = document.getElementById('live-top-cause');
    if (chip) chip.textContent = topCause;
}

// ─── Pipeline C: Budget / Strategic Insight ───────────────────────
function renderInsightPanelC(grain, forecastVal) {
    const grainLabel  = GRAIN_LABEL[grain];
    const budget      = Math.round(forecastVal) * 3500;
    const staffNeeded = Math.ceil(Math.round(forecastVal) / 50); // 1 staff per 50 cases

    document.getElementById('pc-badge').textContent = 'Pipeline C · ' + grainLabel;
    document.getElementById('pc-title').innerHTML   =
        `<i class="fas fa-wallet"></i> ${grainLabel} Budget & Staffing Plan`;
    document.getElementById('pc-desc').innerHTML    =
        `To support the predicted <strong>${Math.round(forecastVal).toLocaleString()}</strong> applicants `
        + `for the ${grainLabel.toLowerCase()} period, an allocation of `
        + `<strong>₱${budget.toLocaleString()}</strong> is recommended, `
        + `with an estimated <strong>${staffNeeded} staff</strong> required to manage caseload.`;
}

// ─── Metric cards ─────────────────────────────────────────────────
function renderMetrics(grain) {
    const g        = GRAINS[grain];
    const forecast = (g.forecast || []).filter(v => v !== null);
    const actual   = (g.actual   || []).filter(v => v !== null);
    const peak     = forecast.length ? Math.max(...forecast) : 0;
    const avg      = actual.length   ? actual.reduce((a, b) => a + b, 0) / actual.length : 0;
    const m        = g.metrics || { mae: 0, margin_of_error_95: 0 };

    const cards = [
        { label:'Mean Absolute Error',   value:Number(m.mae).toLocaleString(),               sub:'Avg prediction error (clients/period)', icon:'📉', color:'#1e293b' },
        { label:'95% Confidence Margin', value:'± ' + Number(m.margin_of_error_95).toLocaleString(), sub:'Forecast uncertainty envelope',   icon:'📊', color:'#0d9488' },
        { label:'Peak Forecast Volume',  value:Math.round(peak).toLocaleString(),             sub:'Highest projected period',              icon:'🔝', color:'#7c3aed' },
        { label:'Avg Historical Volume', value:Math.round(avg).toLocaleString(),              sub:'Per period (2022–2026)',                 icon:'📋', color:'#3b82f6' },
    ];

    document.getElementById('fcMetricCards').innerHTML = cards.map(c => `
        <div class="fc-metric">
            <div class="fc-icon">${c.icon}</div>
            <div class="fc-label">${c.label}</div>
            <div class="fc-val" style="color:${c.color}">${c.value}</div>
            <div class="fc-sub">${c.sub}</div>
        </div>`).join('');
}

// ─── LSTM Chart ───────────────────────────────────────────────────
function renderChart(grain) {
    const g    = GRAINS[grain];
    const meta = FC_META[grain];

    document.getElementById('fcChartTitle').textContent   = meta.title;
    document.getElementById('fcForecastNote').textContent = meta.forecast;

    const forecastStartIdx = (g.forecast || []).findIndex(v => v !== null);

    const forecastZonePlugin = {
        id: 'fcForecastZone',
        beforeDraw(chart) {
            if (forecastStartIdx < 0) return;
            const { ctx: c, chartArea, scales } = chart;
            const x = scales.x.getPixelForValue(forecastStartIdx);
            if (!x || x > chartArea.right) return;
            c.save();
            c.fillStyle = 'rgba(13,148,136,0.04)';
            c.fillRect(x, chartArea.top, chartArea.right - x, chartArea.bottom - chartArea.top);
            c.beginPath(); c.setLineDash([6, 4]);
            c.strokeStyle = 'rgba(13,148,136,0.35)'; c.lineWidth = 1.5;
            c.moveTo(x, chartArea.top); c.lineTo(x, chartArea.bottom); c.stroke();
            c.restore();
        }
    };

    const ctx = document.getElementById('lstmChart').getContext('2d');
    if (fcChartInstance) fcChartInstance.destroy();

    fcChartInstance = new Chart(ctx, {
        type: 'line',
        plugins: [forecastZonePlugin],
        data: {
            labels: g.labels || [],
            datasets: [
                { label:'Actual Volume',         data:g.actual||[],         borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,0.06)', borderWidth:2, fill:true,  tension:0.3, pointRadius:grain==='yearly'?4:(grain==='monthly'?3:0), pointHoverRadius:5, spanGaps:false },
                { label:'Model Fit (In-Sample)', data:g.predicted||[],      borderColor:'#a78bfa', borderDash:[5,4], backgroundColor:'transparent', borderWidth:1.5, pointRadius:0, pointHoverRadius:4, tension:0.3, spanGaps:false },
                { label:'Forecast',              data:g.forecast||[],       borderColor:'#0d9488', backgroundColor:'transparent', borderWidth:2.5, pointRadius:grain==='yearly'?5:(grain==='monthly'?4:2), pointHoverRadius:6, pointBackgroundColor:'#0d9488', tension:0.3, spanGaps:false },
                { label:'Upper 95% CI',          data:g.forecast_upper||[], borderColor:'rgba(13,148,136,0.25)', backgroundColor:'transparent', borderWidth:1, borderDash:[3,3], pointRadius:0, spanGaps:false, fill:false },
                { label:'Lower 95% CI',          data:g.forecast_lower||[], borderColor:'rgba(13,148,136,0.25)', backgroundColor:'rgba(13,148,136,0.08)', borderWidth:1, borderDash:[3,3], pointRadius:0, spanGaps:false, fill:'-1' },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid:{ color:'rgba(226,232,240,0.8)' }, ticks:{ color:'#94a3b8', maxTicksLimit:meta.xLimit, maxRotation:45, font:{ size:11, family:"'Inter',sans-serif" } } },
                y: { beginAtZero:true, grid:{ color:'rgba(226,232,240,0.8)' }, ticks:{ color:'#94a3b8', font:{ size:11 }, callback: v => v !== null ? v.toLocaleString() : '' }, title:{ display:true, text:'Clients', color:'#94a3b8', font:{ size:11 } } }
            },
            plugins: {
                legend: { position:'top', labels:{ color:'#334155', font:{ size:12, weight:'600', family:"'Inter',sans-serif" }, boxWidth:24, padding:16, filter: item => !item.text.includes('CI') } },
                tooltip: {
                    backgroundColor:'#1e293b', borderColor:'#334155', borderWidth:1,
                    titleColor:'#f8fafc', bodyColor:'#cbd5e1', padding:10,
                    callbacks: {
                        label(c) {
                            if (c.parsed.y === null || c.dataset.label.includes('CI')) return null;
                            return ` ${c.dataset.label}: ${Math.round(c.parsed.y).toLocaleString()} clients`;
                        },
                        afterBody(items) {
                            const idx = items[0]?.dataIndex;
                            if (idx === undefined) return [];
                            const gg = GRAINS[currentGrain];
                            const lo = (gg.forecast_lower || [])[idx];
                            const hi = (gg.forecast_upper || [])[idx];
                            if (lo === null || hi === null || lo === undefined) return [];
                            return [`  95% CI: ${Math.round(lo).toLocaleString()} – ${Math.round(hi).toLocaleString()}`];
                        }
                    }
                }
            }
        }
    });
}

// ─── Table ────────────────────────────────────────────────────────
function renderTable() {
    const body = document.getElementById('table-body');
    if (!body) return;
    body.innerHTML = csvData.slice(0, 8).map(r => {
        const d       = new Date(r.date);
        const fmtDate = isNaN(d) ? r.date : d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
        const st      = r.status || 'Logged';
        const color   = st.toLowerCase() === 'pending'  ? '#f59e0b'
                      : st.toLowerCase() === 'approved' ? '#10b981'
                      : '#64748b';
        return `<tr>
            <td style="font-weight:500">${fmtDate}</td>
            <td style="color:#1e293b;font-weight:600">${r.cause || 'Not Specified'}</td>
            <td>${r.type || 'Not Specified'}</td>
            <td><span style="color:${color};font-size:12px"><i class="fas fa-check-circle"></i> ${st}</span></td>
        </tr>`;
    }).join('');
}

// ─── Heatmap ──────────────────────────────────────────────────────
function initHeatmap() {
    const map = L.map('map', { scrollWheelZoom: false }).setView([14.6839, 121.0860], 13);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);

    const coordMap = {
        "Alicia":[14.6601,121.0253],"Bagong Pag-asa":[14.6544,121.0336],"Bahay Toro":[14.6713,121.0264],
        "Bungad":[14.6517,121.0210],"Del Monte":[14.6391,121.0116],"Laging Handa":[14.6367,121.0360],
        "Manresa":[14.6397,121.0049],"Nayon Kanluran":[14.6473,121.0216],"Paltok":[14.6394,121.0179],
        "Project 6":[14.6631,121.0374],"San Antonio":[14.6358,121.0191],"Vasra":[14.6512,121.0435],
        "Batasan Hills":[14.6839,121.0860],"Commonwealth":[14.6826,121.0608],"Holy Spirit":[14.6845,121.0710],
        "Payatas":[14.7136,121.1064],"Bagong Silangan":[14.7042,121.1121],"Bagumbayan":[14.6074,121.0792],
        "E. Rodriguez":[14.6289,121.0583],"Libis":[14.6111,121.0772],"Loyola Heights":[14.6385,121.0744],
        "Matandang Balara":[14.6617,121.0811],"Pansol":[14.6436,121.0784],"San Roque":[14.6178,121.0631],
        "Socorro":[14.6200,121.0544],"Ugong Norte":[14.5960,121.0640],"White Plains":[14.6040,121.0638],
        "Bagong Lipunan ng Crame":[14.6094,121.0519],"Don Manuel":[14.6190,121.0116],"Immaculate Concepcion":[14.6212,121.0409],
        "Kamuning":[14.6294,121.0390],"Kaunlaran":[14.6172,121.0475],"Kristong Hari":[14.6216,121.0298],
        "Obrero":[14.6285,121.0297],"Pinagkaisahan":[14.6240,121.0494],"Sacred Heart":[14.6341,121.0396],
        "San Martin de Porres":[14.6158,121.0511],"Tatalon":[14.6213,121.0189],"U.P. Campus":[14.6549,121.0652],
        "U.P. Village":[14.6469,121.0560],"Fairview":[14.7011,121.0583],"Greater Lagro":[14.7194,121.0654],
        "Pasong Putik":[14.7327,121.0604],"North Fairview":[14.6994,121.0519],"Santa Monica":[14.7126,121.0456],
        "Gulod":[14.7042,121.0371],"San Bartolome":[14.6908,121.0347],"Bagbag":[14.6967,121.0336],
        "Nagkaisang Nayon":[14.7176,121.0315],"Novaliches Proper":[14.7000,121.0333],"San Agustin":[14.7077,121.0402],
        "Tandang Sora":[14.6775,121.0433],"Pasong Tamo":[14.6732,121.0610],"Culiat":[14.6644,121.0515],
        "Sauyo":[14.6896,121.0431],"Talipapa":[14.6901,121.0208],"Baesa":[14.6706,121.0113],
        "Sangandaan":[14.6749,121.0252],"Apolonio Samson":[14.6534,121.0069],"Unang Sigaw":[14.6538,121.0016],
        "Project 4":[14.6191,121.0682],"Project 8":[14.6756,121.0219]
    };

    const pts = mapData.map(loc => {
        const name   = loc.name ? loc.name.trim() : '';
        const coords = (loc.lat && loc.lng) ? [loc.lat, loc.lng] : coordMap[name];
        return coords ? [...coords, Math.min(loc.count / 50, 1)] : null;
    }).filter(Boolean);

    L.heatLayer(pts, { radius:35, blur:20, max:1.0, minOpacity:0.5, gradient:{ 0.4:'blue', 0.6:'cyan', 0.7:'lime', 0.8:'yellow', 1.0:'red' } }).addTo(map);
}

// ─── Init ─────────────────────────────────────────────────────────
window.onload = () => {
    renderTable();
    initHeatmap();
    switchGrain('monthly'); // boots everything: insights + chart + metrics
};
</script>
</body>
</html>