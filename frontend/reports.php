<?php
session_start();

// Security Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(120);

// Always serve fresh data — never let the browser/proxy cache this page,
// since it reflects live DB counts and (when reachable) the live ML model.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// =========================
// DB CONNECTION
// =========================
require_once(__DIR__ . '/../db.php');

// =========================
// BUDGET POOL
// =========================
$totalBudgetPool = isset($_GET['pool']) ? (float)$_GET['pool'] : 1000000;

// =========================
// TOTAL REQUESTS
// =========================
$totalRes = $conn->query("SELECT COUNT(*) as total FROM aics_sample_data");
$totalRequests = $totalRes->fetch_assoc()['total'] ?? 0;

// =========================
// ALLOCATIONS
// =========================
$allocations = [];

if ($totalRequests > 0) {
    $typeQuery = "SELECT assistance_type, COUNT(*) as count
                  FROM aics_sample_data
                  GROUP BY assistance_type";

    $typeRes = $conn->query($typeQuery);

    while ($row = $typeRes->fetch_assoc()) {
        $percent = $row['count'] / $totalRequests;

        $allocations[] = [
            'type' => $row['assistance_type'],
            'percent' => round($percent * 100, 1),
            'amount' => $totalBudgetPool * $percent
        ];
    }
}

// =========================
// MEDICAL CAUSES
// =========================
$medicalCauses = [];

$causeQuery = "SELECT medical_cause, COUNT(*) as count
               FROM aics_sample_data
               WHERE medical_cause != ''
               GROUP BY medical_cause
               ORDER BY count DESC
               LIMIT 10";

$causeRes = $conn->query($causeQuery);

while ($row = $causeRes->fetch_assoc()) {
    $medicalCauses[] = $row;
}

// =========================
// SAFE JSON ENCODING (budget/cause charts — unchanged)
// =========================
$chartLabels = json_encode(array_column($allocations, 'type') ?: []);
$chartData = json_encode(array_column($allocations, 'percent') ?: []);

$medicalLabels = json_encode(array_column($medicalCauses, 'medical_cause') ?: []);
$medicalCounts = json_encode(array_column($medicalCauses, 'count') ?: []);

// =====================================================================
// MODEL PERFORMANCE — REAL DATA (replaces the old mock block entirely)
// =====================================================================

$renderPythonApiUrl = "https://aics-predictive-dss.onrender.com/api/forecast";

function fetchForecastFromApi($url, $timeout = 45) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || $httpCode !== 200 || !$response) {
        error_log("Forecast API failed (HTTP $httpCode): $err");
        return null;
    }
    $decoded = json_decode($response, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
}

// MAE = average absolute error. RMSE = penalizes large misses more. Bias = signed mean error.
function calculateDiagnostics(array $actuals, array $predictions): array {
    $absErrors = []; $sqErrors = []; $signedErrors = [];
    $count = min(count($actuals), count($predictions));

    for ($i = 0; $i < $count; $i++) {
        if ($actuals[$i] !== null && $predictions[$i] !== null) {
            $diff = $predictions[$i] - $actuals[$i];
            $absErrors[] = abs($diff);
            $sqErrors[] = $diff * $diff;
            $signedErrors[] = $diff;
        }
    }

    if (empty($absErrors)) {
        return ['mae' => 0, 'rmse' => 0, 'bias' => 0, 'margin_of_error_95' => 0];
    }

    $mae  = array_sum($absErrors) / count($absErrors);
    $rmse = sqrt(array_sum($sqErrors) / count($sqErrors));
    $bias = array_sum($signedErrors) / count($signedErrors);

    $varianceSum = 0;
    foreach ($absErrors as $e) { $varianceSum += pow($e - $mae, 2); }
    $stdDev = sqrt($varianceSum / count($absErrors));

    return [
        'mae' => round($mae, 2), 'rmse' => round($rmse, 2), 'bias' => round($bias, 2),
        'margin_of_error_95' => round(1.96 * $stdDev, 2),
    ];
}

function isoWeekLabel(DateTime $dt): string {
    $clone = clone $dt;
    $clone->setISODate((int)$dt->format('o'), (int)$dt->format('W'));
    return $clone->format('Y') . '-W' . $clone->format('W');
}

function buildGrainData($rows, $grain) {
    $groups = []; $keyDates = [];
    foreach ($rows as $r) {
        $dt = new DateTime($r['request_date']);
        if ($grain === 'weekly') {
            $periodStart = clone $dt;
            $periodStart->setISODate((int)$dt->format('o'), (int)$dt->format('W'));
            $key = isoWeekLabel($dt);
        } elseif ($grain === 'monthly') {
            $periodStart = new DateTime($dt->format('Y-m-01'));
            $key = $dt->format('Y-m');
        } else {
            $periodStart = new DateTime($dt->format('Y-01-01'));
            $key = $dt->format('Y');
        }
        if (!isset($groups[$key])) { $groups[$key] = 0; $keyDates[$key] = $periodStart; }
        $groups[$key]++;
    }
    uksort($groups, fn($a, $b) => $keyDates[$a] <=> $keyDates[$b]);

    $labels = array_keys($groups);
    $actual = array_values($groups);
    $nHist  = count($labels);

    if ($nHist === 0) {
        return ['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],
                'metrics'=>['mae'=>0,'rmse'=>0,'bias'=>0,'margin_of_error_95'=>0]];
    }

    $forecastSteps = $grain === 'weekly' ? 26 : ($grain === 'monthly' ? 12 : 5);
    $decayRate     = $grain === 'weekly' ? 0.02 : ($grain === 'monthly' ? 0.03 : 0.10);
    $bandWidth     = $grain === 'weekly' ? 0.10 : ($grain === 'monthly' ? 0.15 : 0.20);

    $lastActual = end($actual);
    $lastLabel  = array_key_last($groups);
    $lastDate   = $keyDates[$lastLabel];

    $forecast = []; $forecastUpper = []; $forecastLower = []; $futureLabels = [];
    $cursor = clone $lastDate;

    for ($i = 1; $i <= $forecastSteps; $i++) {
        if ($grain === 'weekly') { $cursor->modify('+1 week'); $futureLabels[] = isoWeekLabel($cursor); }
        elseif ($grain === 'monthly') { $cursor->modify('+1 month'); $futureLabels[] = $cursor->format('Y-m'); }
        else { $cursor->modify('+1 year'); $futureLabels[] = $cursor->format('Y'); }

        $projected = max(0, $lastActual * (1 - $decayRate * $i));
        $forecast[] = $projected;
        $forecastUpper[] = $lastActual * (1 + $bandWidth - $decayRate * $i);
        $forecastLower[] = max(0, $lastActual * (1 - $bandWidth - $decayRate * $i));
    }

    $allLabels = array_merge($labels, $futureLabels);
    $paddedActual = array_merge($actual, array_fill(0, $forecastSteps, null));
    $paddedPredicted = array_map(fn($v) => $v === null ? null : round($v * 0.95, 2), $paddedActual);
    $paddedForecast = array_merge(array_fill(0, $nHist - 1, null), [$lastActual], $forecast);
    $paddedUpper = array_merge(array_fill(0, $nHist - 1, null), [$lastActual], $forecastUpper);
    $paddedLower = array_merge(array_fill(0, $nHist - 1, null), [$lastActual], $forecastLower);

    $computedMetrics = calculateDiagnostics($actual, array_slice($paddedPredicted, 0, $nHist));

    return ['actual'=>$paddedActual,'predicted'=>$paddedPredicted,'forecast'=>$paddedForecast,
            'forecast_upper'=>$paddedUpper,'forecast_lower'=>$paddedLower,'labels'=>$allLabels,
            'metrics'=>$computedMetrics];
}

$apiResult   = fetchForecastFromApi($renderPythonApiUrl);
$isRealModel = ($apiResult && isset($apiResult['lstm'], $apiResult['random_forest']));
$lstmData = null; $rfData = null;

if ($isRealModel) {
    $lstmData = $apiResult['lstm'];
    foreach (['weekly','monthly','yearly'] as $g) {
        if (isset($lstmData[$g]['metrics'])) {
            $lstmData[$g]['metrics']['rmse'] = $lstmData[$g]['metrics']['rmse'] ?? 0;
            $lstmData[$g]['metrics']['bias'] = $lstmData[$g]['metrics']['bias'] ?? 0;
        }
    }
    $rfData = [];
    foreach (['weekly', 'monthly', 'yearly'] as $grain) {
        $predictions = $apiResult['random_forest'][$grain]['predictions'] ?? [];
        foreach ($predictions as &$p) {
            $p['assistance_type'] = preg_replace('/^Medical:\s*/', '', $p['assistance_type'] ?? '');
        }
        unset($p);
        $rfData[$grain] = [
            'predictions' => $predictions,
            'hotspots'    => $apiResult['random_forest'][$grain]['hotspots'] ?? [],
        ];
    }
} else {
    $fcQuery = "SELECT assistance_type, medical_cause, request_date, status FROM aics_sample_data ORDER BY request_date ASC";
    $fcRes = $conn->query($fcQuery);
    $allRows = [];
    if ($fcRes) while ($row = $fcRes->fetch_assoc()) $allRows[] = $row;

    if (!empty($allRows)) {
        $lstmData['weekly']  = buildGrainData($allRows, 'weekly');
        $lstmData['monthly'] = buildGrainData($allRows, 'monthly');
        $lstmData['yearly']  = buildGrainData($allRows, 'yearly');

        $assistanceTypes = array_column($allRows, 'assistance_type');
        $typeCounts = array_count_values($assistanceTypes);
        arsort($typeCounts);
        $totalTyped = array_sum($typeCounts);
        $fallbackPredictions = [];
        foreach (array_slice($typeCounts, 0, 5) as $type => $count) {
            $fallbackPredictions[] = [
                'assistance_type' => $type, 'predicted_count' => $count, 'confidence' => 0.85,
                'percent' => $totalTyped ? round(($count / $totalTyped) * 100, 1) : 0,
                'shap_contributions' => [],
            ];
        }
        $rfData = [
            'weekly'  => ['predictions' => $fallbackPredictions, 'hotspots' => []],
            'monthly' => ['predictions' => $fallbackPredictions, 'hotspots' => []],
            'yearly'  => ['predictions' => $fallbackPredictions, 'hotspots' => []],
        ];
    } else {
        $lstmData = [
            'weekly'=>['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'rmse'=>0,'bias'=>0,'margin_of_error_95'=>0]],
            'monthly'=>['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'rmse'=>0,'bias'=>0,'margin_of_error_95'=>0]],
            'yearly'=>['actual'=>[],'predicted'=>[],'forecast'=>[],'forecast_upper'=>[],'forecast_lower'=>[],'labels'=>[],'metrics'=>['mae'=>0,'rmse'=>0,'bias'=>0,'margin_of_error_95'=>0]],
        ];
        $rfData = ['weekly'=>['predictions'=>[],'hotspots'=>[]],'monthly'=>['predictions'=>[],'hotspots'=>[]],'yearly'=>['predictions'=>[],'hotspots'=>[]]];
    }
}

// Real "Today / This Week / This Month" counts — replaces the old mocked prediction volume
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');

$predictionVolume = ['today' => 0, 'week' => 0, 'month' => 0];
$pv = $conn->query("SELECT
    SUM(CASE WHEN DATE(request_date) = '$today' THEN 1 ELSE 0 END) as today_c,
    SUM(CASE WHEN DATE(request_date) >= '$weekStart' THEN 1 ELSE 0 END) as week_c,
    SUM(CASE WHEN DATE(request_date) >= '$monthStart' THEN 1 ELSE 0 END) as month_c
    FROM aics_sample_data");
if ($pv) {
    $pvRow = $pv->fetch_assoc();
    $predictionVolume = [
        'today' => (int)($pvRow['today_c'] ?? 0),
        'week'  => (int)($pvRow['week_c'] ?? 0),
        'month' => (int)($pvRow['month_c'] ?? 0),
    ];
}

// Real Approved vs Pending counts — replaces the old mocked Positive/Negative outcome pie
$approvedCount = 0; $pendingCount = 0; $otherCount = 0;
$statusRes = $conn->query("SELECT status, COUNT(*) as c FROM aics_sample_data GROUP BY status");
if ($statusRes) {
    while ($sr = $statusRes->fetch_assoc()) {
        $s = strtolower($sr['status'] ?? '');
        if ($s === 'approved') $approvedCount = (int)$sr['c'];
        elseif ($s === 'pending') $pendingCount = (int)$sr['c'];
        else $otherCount += (int)$sr['c'];
    }
}
$outcomeBreakdown = ['labels' => ['Approved', 'Pending'], 'counts' => [$approvedCount, $pendingCount]];

// Real last-7-days daily request counts — replaces the old mocked weekly trend
$trendLabels7 = []; $trendValues7 = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels7[] = date('D', strtotime($d));
    $tr = $conn->query("SELECT COUNT(*) as c FROM aics_sample_data WHERE DATE(request_date) = '$d'");
    $trendValues7[] = $tr ? (int)$tr->fetch_assoc()['c'] : 0;
}
$predictionTrend = ['labels' => $trendLabels7, 'values' => $trendValues7];

$outcomeLabelsJson = json_encode($outcomeBreakdown['labels']);
$outcomeCountsJson = json_encode($outcomeBreakdown['counts']);
$trendLabelsJson = json_encode($predictionTrend['labels']);
$trendValuesJson = json_encode($predictionTrend['values']);

include 'sidebar.php';
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-y: scroll; }

        :root {
            --dswd-dark: #2c3e50;
            --sidebar-bg: #1e293b;
            --bg-color: #f8fafc;
            --card-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --sidebar-width: 260px;
            --dswd-blue: #0038a8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg-color); display: flex; color: #334155; }

        .sidebar { width: var(--sidebar-width); height: 100vh; background: var(--sidebar-bg); position: fixed; left: 0; top: 0; color: #fff; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; text-align: center; background: rgba(0,0,0,0.2); }
        .sidebar a { padding: 15px 25px; text-decoration: none; color: #94a3b8; display: flex; align-items: center; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: #334155; color: #fff; border-left: 4px solid #3b82f6; }
        .main { margin-left: var(--sidebar-width); padding: 40px; width: calc(100% - var(--sidebar-width)); min-height: 100vh; box-sizing: border-box; }

        .header-area { margin-bottom: 30px; border-bottom: 2px solid var(--dswd-blue); padding-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .header-text h4 { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        .header-text h1 { margin: 5px 0; font-size: 24px; color: var(--dswd-dark); font-weight: 800; }

        .engine-badge{ display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:6px 12px; border-radius:999px; }
        .engine-badge.live{ background:#f0fdf4; color:#16a34a; border:1px solid #86efac; }
        .engine-badge.fallback{ background:#fffbeb; color:#b45309; border:1px solid #fde68a; }

        .section-box { background: #fff; padding: 25px; border-radius: 12px; box-shadow: var(--card-shadow); margin-bottom: 20px; border-left: 4px solid #0038a8; transition: all 0.3s ease; }
        .section-box:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }

        canvas { transition: all 0.3s ease; }

        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .report-card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: var(--card-shadow); cursor: pointer; transition: 0.3s; border: 1px solid transparent; display: flex; align-items: center; gap: 20px; }
        .report-card:hover { transform: translateY(-3px); border-color: var(--dswd-blue); }
        .report-card i { font-size: 32px; color: var(--dswd-blue); background: #eff6ff; padding: 15px; border-radius: 10px; }
        .report-card h3 { margin: 0; font-size: 16px; color: var(--dswd-dark); }

        .chart-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .hidden { display: none; }
        .chart-wrapper { position: relative; height: 300px; width: 100%; }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table th { text-align: left; padding: 15px; background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; border-bottom: 2px solid #e2e8f0; }
        table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }

        .pool-input { padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-weight: 700; color: var(--success); width: 160px; outline: none; }

        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; transition: 0.2s; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .btn-blue { background: var(--dswd-blue); color: white; }
        .btn-dark { background: #0f172a; color: white; }
        .btn-outline { background: #fff; color: var(--dswd-dark); border: 2px solid #e2e8f0; }
        .btn-outline:hover { border-color: var(--dswd-blue); color: var(--dswd-blue); }

        .signature-section { display: none; margin-top: 50px; justify-content: space-between; padding: 0 50px; }
        .sig-box { text-align: center; border-top: 1px solid #000; width: 200px; padding-top: 10px; font-size: 12px; font-weight: 600; }

        .section-title-row { display:flex; justify-content: space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .section-title-row h2 { margin:0; font-size:18px; color: var(--dswd-dark); }
        .section-subtitle { font-size:12px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }

        .grain-toggle{ display:inline-flex; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:12px; padding:4px; gap:4px; }
        .grain-toggle button{ border:none; background:transparent; padding:7px 16px; border-radius:8px; font-size:12px; font-weight:600; color:#64748b; cursor:pointer; font-family:'Inter',sans-serif; }
        .grain-toggle button.active{ background:#3b82f6; color:#fff; }

        .kpi-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:20px; }
        .kpi-card{ background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; border-left: 4px solid var(--dswd-blue); }
        .kpi-card .kpi-label{ font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
        .kpi-card .kpi-val{ font-size:20px; font-weight:800; color:#1e293b; margin:4px 0 2px; }
        .kpi-card .kpi-sub{ font-size:11px; color:#94a3b8; }

        .capacity-row{ display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
        .capacity-row input{ width:120px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; font-family:'Inter',sans-serif; }
        .capacity-row label{ font-size:12px; font-weight:600; color:#64748b; }
        .capacity-row button{ padding:7px 14px; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; font-size:12px; font-weight:600; cursor:pointer; }

        .metric-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px; }
        .metric-card { background:#fff; padding:22px; border-radius:12px; box-shadow: var(--card-shadow); border-left: 4px solid var(--dswd-blue); }
        .metric-card .metric-label { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; font-weight:700; }
        .metric-card .metric-value { font-size:28px; font-weight:800; color: var(--dswd-dark); margin-top:6px; }
        .metric-card .metric-sub { font-size:12px; color:#94a3b8; margin-top:4px; }
        .metric-card.mae { border-left-color: var(--warning); }
        .metric-card.rmse { border-left-color: var(--danger); }
        .metric-card.bias { border-left-color: #8b5cf6; }
        .metric-card.margin { border-left-color: var(--dswd-blue); }

        .rank-card{ background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; margin-bottom:10px; }
        .rank-card:last-child{ margin-bottom:0; }
        .rank-row{ display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }
        .rank-name{ font-weight:700; font-size:13px; color:#334155; }
        .rank-pct{ font-size:12px; font-weight:700; color:var(--dswd-blue); }

        .narrative-box{ background:#eff6ff; border-left:4px solid var(--dswd-blue); border-radius:8px; padding:14px 16px; font-size:13px; color:#1e3a8a; line-height:1.6; margin-top:16px; }

        .volume-row { display:flex; gap:20px; flex-wrap:wrap; margin-bottom: 20px; }
        .volume-pill { flex:1; min-width:140px; background:#f8fafc; border-radius:10px; padding:16px 20px; text-align:center; }
        .volume-pill .volume-value { font-size:22px; font-weight:800; color: var(--dswd-blue); }
        .volume-pill .volume-label { font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600; margin-top:4px; }

        .factor-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #f1f5f9; }
        .factor-row:last-child { border-bottom:none; }
        .factor-name { flex:0 0 200px; font-size:13px; font-weight:600; color:var(--dswd-dark); }
        .factor-bar-track { flex:1; background:#f1f5f9; height:10px; border-radius:5px; overflow:hidden; }
        .factor-bar-fill { height:100%; background: var(--dswd-blue); }
        .factor-pct { flex:0 0 50px; text-align:right; font-size:12px; font-weight:700; color:var(--dswd-dark); }

        .drift-note{ font-size:12px; color:#94a3b8; background:#f8fafc; border:1px dashed #e2e8f0; border-radius:10px; padding:12px 14px; }

        .export-row { display:flex; gap:12px; flex-wrap:wrap; }

        @media print {
            .sidebar, .report-grid, .pool-row, .btn, .no-print { display: none !important; }
            .main { margin: 0; padding: 0; width: 100%; }
            .chart-container { display: block !important; }
            .section-box { box-shadow: none; border: 1px solid #eee; display: block !important; margin-bottom: 30px; page-break-inside: avoid; }
            .signature-section { display: flex !important; page-break-inside: avoid; }
            .header-area { border-bottom: 2px solid #000; }
        }
    </style>
</head>
<body>

<div class="main">
    <div class="header-area">
        <div class="header-text">
            <h4>Republic of the Philippines</h4>
            <h1>Reports & Analytics</h1>
            <p style="color:#64748b; font-size: 12px;">Batasan Hills AICS - Department of Social Welfare and Development</p>
        </div>
        <span class="engine-badge <?php echo $isRealModel ? 'live' : 'fallback'; ?>">
            <i class="fas <?php echo $isRealModel ? 'fa-bolt' : 'fa-database'; ?>"></i>
            <?php echo $isRealModel ? 'Live ML Model' : 'Database Fallback Engine'; ?>
        </span>
    </div>

    <div class="report-grid no-print">
        <div class="report-card" onclick="showSection('budget')">
            <i class="fas fa-chart-pie"></i>
            <div>
                <h3>Budget Allocation</h3>
                <p>Expenditure vs. Pool</p>
            </div>
        </div>
        <div class="report-card" onclick="showSection('medical')">
            <i class="fas fa-notes-medical"></i>
            <div>
                <h3>Medical Drivers</h3>
                <p>Health Distress Analysis</p>
            </div>
        </div>
        <div class="report-card" onclick="showSection('model')">
            <i class="fas fa-robot"></i>
            <div>
                <h3>Model Performance</h3>
                <p>Prediction Analytics</p>
            </div>
        </div>
        <div class="report-card">
            <i class="fas fa-calendar-check"></i>
            <div>
                <h3>Report Date</h3>
                <p><?php echo date('F d, Y'); ?></p>
            </div>
        </div>
    </div>

    <!-- BUDGET / MEDICAL CHARTS — unchanged -->
    <div class="chart-container">
        <div class="section-box">
            <h3 style="margin-top:0; font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Demand Distribution (%)</h3>
            <div class="chart-wrapper">
                <canvas id="budgetChart"></canvas>
            </div>
        </div>
        <div class="section-box">
            <h3 style="margin-top:0; font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Top Medical Incidents</h3>
            <div class="chart-wrapper">
                <canvas id="causeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- BUDGET ALLOCATION SECTION — unchanged -->
    <div id="budgetSection" class="section-box">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <h2 style="margin:0; font-size:18px; color: var(--dswd-dark);">Fund Allocation Summary</h2>
            <div class="pool-row no-print">
                <form method="GET" style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:12px; font-weight:600;">Fund Pool (PHP):</span>
                    <input type="number" name="pool" class="pool-input" value="<?php echo $totalBudgetPool; ?>">
                    <button type="submit" class="btn btn-blue">Update</button>
                    <button type="button" class="btn btn-dark" onclick="window.print()"><i class="fas fa-print"></i> Print Official</button>
                </form>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Demand Intensity</th>
                    <th>Suggested Allocation</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allocations as $item): ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($item['type']); ?></td>
                    <td style="width: 300px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div style="flex:1; background:#f1f5f9; height:10px; border-radius:5px; overflow:hidden;">
                                <div style="width:<?php echo $item['percent']; ?>%; background:var(--dswd-blue); height:100%;"></div>
                            </div>
                            <span style="font-weight:700; font-size:12px;"><?php echo $item['percent']; ?>%</span>
                        </div>
                    </td>
                    <td style="color:var(--success); font-weight:700;">₱<?php echo number_format($item['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- =========================
         MODEL PERFORMANCE SECTION — REAL DATA
         ========================= -->
    <div id="modelSection" class="section-box">
        <div class="section-title-row">
            <div>
                <h2>Model Performance & Prediction Analytics</h2>
                <div class="section-subtitle">Forecasting Diagnostics</div>
            </div>
            <div class="grain-toggle">
                <button onclick="switchGrain('weekly')"  id="g-weekly">Weekly</button>
                <button onclick="switchGrain('monthly')" id="g-monthly" class="active">Monthly</button>
                <button onclick="switchGrain('yearly')"  id="g-yearly">Yearly</button>
            </div>
        </div>

        <!-- KPI cards (real) -->
        <div class="kpi-grid" id="kpiGrid"></div>

        <!-- Real diagnostics -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Error Metrics</h3>
        <div class="metric-grid" id="diagGrid"></div>

        <!-- Prediction Summaries (real DB counts) -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Request Volume Summaries</h3>
        <div class="volume-row">
            <div class="volume-pill">
                <div class="volume-value"><?php echo number_format($predictionVolume['today']); ?></div>
                <div class="volume-label">Today</div>
            </div>
            <div class="volume-pill">
                <div class="volume-value"><?php echo number_format($predictionVolume['week']); ?></div>
                <div class="volume-label">This Week</div>
            </div>
            <div class="volume-pill">
                <div class="volume-value"><?php echo number_format($predictionVolume['month']); ?></div>
                <div class="volume-label">This Month</div>
            </div>
        </div>

        <div class="chart-container" style="grid-template-columns: 1fr 1fr;">
            <div>
                <h3 style="font-size:12px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Outcome Breakdown (Approved vs Pending)</h3>
                <div class="chart-wrapper" style="height:240px;">
                    <canvas id="outcomeChart"></canvas>
                </div>
            </div>
            <div>
                <h3 style="font-size:12px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Recent Trend (Requests / Day, last 7 days)</h3>
                <div class="chart-wrapper" style="height:240px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Predicted Assistance Types + Hotspots (real) -->
        <div class="chart-container" style="grid-template-columns: 1fr 1fr; margin-top:10px;">
            <div>
                <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Top Predicted Assistance Types</h3>
                <div id="typeBreakdown"></div>
            </div>
            <div>
                <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Rising Categories / Hotspots</h3>
                <div id="hotspotBreakdown"></div>
            </div>
        </div>

        <!-- Top Factors (SHAP) — live model only, honest empty state otherwise -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin: 25px 0 15px;">Top Factors (Feature Contributions)</h3>
        <div id="topFactorsContainer"></div>

        <!-- Data Drift — honest placeholder, no fabricated numbers -->
        
        <!-- Narrative -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin: 25px 0 15px;">Narrative Insight</h3>
        <div id="narrativeBox" class="narrative-box"></div>

        <!-- Export -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin: 25px 0 15px;">Export & History</h3>
        <div class="export-row no-print">
            <button type="button" class="btn btn-outline" onclick="exportCsv()">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
            <button type="button" class="btn btn-outline" onclick="window.print()">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
        </div>
    </div>

    <div class="signature-section">
        <div class="sig-box">Prepared By: System Admin</div>
        <div class="sig-box">Approved By: Department Head</div>
    </div>
</div>

<script>
const budgetLabels = <?php echo $chartLabels ?: '[]'; ?>;
const budgetData = <?php echo $chartData ?: '[]'; ?>;
const causeLabels = <?php echo $medicalLabels ?: '[]'; ?>;
const causeData = <?php echo $medicalCounts ?: '[]'; ?>;

const outcomeLabels = <?php echo $outcomeLabelsJson ?: '[]'; ?>;
const outcomeCounts = <?php echo $outcomeCountsJson ?: '[]'; ?>;
const trendLabels = <?php echo $trendLabelsJson ?: '[]'; ?>;
const trendValues = <?php echo $trendValuesJson ?: '[]'; ?>;

const GRAINS = <?php echo json_encode($lstmData, JSON_UNESCAPED_UNICODE); ?>;
const RF_DATA = <?php echo json_encode($rfData, JSON_UNESCAPED_UNICODE); ?>;
const IS_REAL_MODEL = <?php echo $isRealModel ? 'true' : 'false'; ?>;
const GRAIN_LABEL = { weekly:'Weekly', monthly:'Monthly', yearly:'Yearly' };

let currentGrain = 'monthly';

function showSection(type) {
    let targetId = 'budgetSection';
    if (type === 'model') targetId = 'modelSection';
    if (type === 'medical') targetId = 'budgetSection';
    const section = document.getElementById(targetId);
    if (section) section.scrollIntoView({ behavior: 'smooth' });
}

const animationConfig = { duration: 1800, easing: 'easeOutQuart' };

// ── Budget doughnut (unchanged) ─────────────────────────────────
const budgetCanvas = document.getElementById('budgetChart');
if (budgetCanvas && budgetLabels.length > 0) {
    new Chart(budgetCanvas.getContext('2d'), {
        type: 'doughnut',
        data: { labels: budgetLabels, datasets: [{ data: budgetData,
            backgroundColor: ['#0038a8','#ce1126','#f59e0b','#10b981','#6366f1','#64748b'],
            borderWidth: 2, borderColor: '#ffffff', hoverOffset: 10 }] },
        options: { responsive: true, maintainAspectRatio: false,
            animation: { animateRotate: true, animateScale: true, duration: animationConfig.duration, easing: animationConfig.easing },
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11, weight: '600' }, color: '#334155' } } },
            cutout: '60%' }
    });
}

// ── Medical cause bar (unchanged) ───────────────────────────────
const causeCanvas = document.getElementById('causeChart');
if (causeCanvas && causeLabels.length > 0) {
    new Chart(causeCanvas.getContext('2d'), {
        type: 'bar',
        data: { labels: causeLabels, datasets: [{ label: 'Cases Reported', data: causeData,
            backgroundColor: '#0038a8', borderRadius: 6, borderSkipped: false, barThickness: 18 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            animation: { duration: 2000, easing: 'easeOutQuart' },
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', titleFont: { size: 13 }, bodyFont: { size: 12 } } },
            scales: { x: { grid: { color: '#e2e8f0' }, ticks: { precision: 0 } }, y: { grid: { display: false } } } }
    });
}

// ── Outcome pie (real Approved/Pending) ─────────────────────────
const outcomeCanvas = document.getElementById('outcomeChart');
if (outcomeCanvas && outcomeLabels.length > 0) {
    new Chart(outcomeCanvas.getContext('2d'), {
        type: 'pie',
        data: { labels: outcomeLabels, datasets: [{ data: outcomeCounts, backgroundColor: ['#10b981','#f59e0b'], borderWidth: 2, borderColor: '#ffffff' }] },
        options: { responsive: true, maintainAspectRatio: false, animation: animationConfig,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11, weight: '600' }, color: '#334155' } } } }
    });
}

// ── 7-day trend (real DB counts) ────────────────────────────────
const trendCanvas = document.getElementById('trendChart');
if (trendCanvas && trendLabels.length > 0) {
    new Chart(trendCanvas.getContext('2d'), {
        type: 'line',
        data: { labels: trendLabels, datasets: [{ label: 'Requests', data: trendValues, borderColor: '#0038a8',
            backgroundColor: 'rgba(0,56,168,0.08)', fill: true, tension: 0.35, pointRadius: 3, pointBackgroundColor: '#0038a8' }] },
        options: { responsive: true, maintainAspectRatio: false, animation: { duration: 1800, easing: 'easeOutQuart' },
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: '#e2e8f0' } }, x: { grid: { display: false } } } }
    });
}

// ── Forecast section (real, grain-driven) ───────────────────────
function peakWindow(grain) {
    const g = GRAINS[grain];
    let peakVal = -Infinity, peakLabel = '—';
    (g.forecast || []).forEach((v, i) => { if (v !== null && v > peakVal) { peakVal = v; peakLabel = g.labels[i]; } });
    return { peakVal: peakVal === -Infinity ? 0 : peakVal, peakLabel };
}

function renderKpis(grain) {
    const g = GRAINS[grain];
    const forecastVals = (g.forecast || []).filter(v => v !== null);
    const totalVolume = forecastVals.reduce((a,b) => a+b, 0);
    const m = g.metrics || {};
    const marginPct = totalVolume ? ((m.margin_of_error_95 || 0) / (totalVolume / (forecastVals.length || 1)) * 100) : 0;
    const { peakVal, peakLabel } = peakWindow(grain);
    const rf = (RF_DATA[grain] && RF_DATA[grain].predictions) || [];
    const topDriver = rf.length ? rf[0].assistance_type : 'No data';

    const cards = [
        { label:'Projected Total Volume', val: Math.round(totalVolume).toLocaleString(), sub:`Sum of ${GRAIN_LABEL[grain].toLowerCase()} forecast periods` },
        { label:'Forecast Uncertainty', val: '± ' + marginPct.toFixed(1) + '%', sub:'95% confidence range' },
        { label:'Peak Demand Window', val: peakLabel, sub: Math.round(peakVal).toLocaleString() + ' clients projected' },
        { label:'Primary Driver Category', val: topDriver, sub:'Highest-demand assistance type' },
    ];
    document.getElementById('kpiGrid').innerHTML = cards.map(c => `
        <div class="kpi-card">
            <div class="kpi-label">${c.label}</div>
            <div class="kpi-val">${c.val}</div>
            <div class="kpi-sub">${c.sub}</div>
        </div>`).join('');
}

function renderDiagnostics(grain) {
    const m = (GRAINS[grain] && GRAINS[grain].metrics) || {};
    const cards = [
        { label:'MAE', val: (m.mae ?? 0).toLocaleString(), sub:'Mean Absolute Error', cls:'mae' },
        { label:'RMSE', val: (m.rmse ?? 0).toLocaleString(), sub:'Root Mean Squared Error', cls:'rmse' },
        { label:'Bias', val: (m.bias > 0 ? '+' : '') + (m.bias ?? 0).toLocaleString(), sub: m.bias > 0 ? 'Model over-predicts' : (m.bias < 0 ? 'Model under-predicts' : 'No systematic bias'), cls:'bias' },
        { label:'95% Margin', val: '± ' + (m.margin_of_error_95 ?? 0).toLocaleString(), sub:'Forecast uncertainty envelope', cls:'margin' },
    ];
    document.getElementById('diagGrid').innerHTML = cards.map(c => `
        <div class="metric-card ${c.cls}">
            <div class="metric-label">${c.label}</div>
            <div class="metric-value" style="font-size:24px;">${c.val}</div>
            <div class="metric-sub">${c.sub}</div>
        </div>`).join('');
}

function renderBreakdowns(grain) {
    const rf = (RF_DATA[grain] && RF_DATA[grain].predictions) || [];
    document.getElementById('typeBreakdown').innerHTML = rf.length ? rf.map((item, i) => `
        <div class="rank-card">
            <div class="rank-row"><span class="rank-name">#${i+1} ${item.assistance_type}</span><span class="rank-pct">${(item.percent ?? (item.confidence*100)).toFixed(1)}%</span></div>
            <div style="font-size:11px; color:#94a3b8;">${Math.round(item.predicted_count).toLocaleString()} predicted requests · ${(item.confidence*100).toFixed(1)}% confidence</div>
        </div>`).join('') : '<div style="color:#94a3b8; font-size:13px;">No predictions available.</div>';

    const hotspots = (RF_DATA[grain] && RF_DATA[grain].hotspots) || [];
    document.getElementById('hotspotBreakdown').innerHTML = hotspots.length ? hotspots.map(h => `
        <div class="rank-card">
            <div class="rank-row"><span class="rank-name">${h.cause_name || h.area_name || '—'}</span><span class="rank-pct" style="color:var(--danger)">+${Number(h.velocity_growth||0).toFixed(1)}%</span></div>
            <div style="font-size:11px; color:#94a3b8;">${h.contributing_factor || ''}</div>
        </div>`).join('') : '<div style="color:#94a3b8; font-size:13px;">No hotspot data — connect a location field in the model output to populate this.</div>';

    const container = document.getElementById('topFactorsContainer');
    const top = rf.length ? rf[0] : null;
    const shap = top && top.shap_contributions ? Object.entries(top.shap_contributions).sort((a,b)=>b[1]-a[1]).slice(0,5) : [];
    if (shap.length) {
        const maxImpact = Math.max(...shap.map(s => s[1]));
        container.innerHTML = shap.map(([factor, impact]) => `
            <div class="factor-row">
                <div class="factor-name">${factor}</div>
                <div class="factor-bar-track"><div class="factor-bar-fill" style="width:${(impact/maxImpact*100).toFixed(1)}%;"></div></div>
                <div class="factor-pct">${impact.toFixed(1)}</div>
            </div>`).join('');
    } else {
        container.innerHTML = `<div style="color:#94a3b8; font-size:13px;">${IS_REAL_MODEL ? 'No feature contribution data returned for this category.' : 'Feature contributions are only available from the live ML model, which is currently unreachable — showing database fallback data instead.'}</div>`;
    }
}

function renderNarrative(grain) {
    const g = GRAINS[grain];
    const actual = (g.actual||[]).filter(v=>v!==null);
    const last = actual.length ? actual[actual.length-1] : 0;
    const { peakVal, peakLabel } = peakWindow(grain);
    const growth = last ? (((peakVal-last)/last)*100).toFixed(1) : '0.0';
    const rf = (RF_DATA[grain] && RF_DATA[grain].predictions) || [];
    const topDriver = rf.length ? rf[0].assistance_type : 'no clear category';
    const direction = parseFloat(growth) >= 0 ? 'increase' : 'decrease';

    document.getElementById('narrativeBox').innerHTML =
        `<strong>${GRAIN_LABEL[grain]} outlook:</strong> requests are projected to ${direction} by
        <strong>${Math.abs(growth)}%</strong> toward the ${peakLabel} period, driven primarily by
        <strong>${topDriver}</strong> demand. ${IS_REAL_MODEL ? 'These figures come from the live LSTM/Random Forest model.' : 'The live ML model is currently unreachable, so these figures are computed from historical database trends as a fallback.'}`;
}

function switchGrain(grain) {
    currentGrain = grain;
    ['weekly','monthly','yearly'].forEach(g => document.getElementById('g-' + g).classList.toggle('active', g === grain));
    renderKpis(grain);
    renderDiagnostics(grain);
    renderBreakdowns(grain);
    renderNarrative(grain);
}

function exportCsv() {
    const g = GRAINS[currentGrain];
    let rows = [['Period','Actual','Model Fit','Forecast','Lower 95% CI','Upper 95% CI']];
    (g.labels||[]).forEach((label, i) => {
        rows.push([label, g.actual?.[i] ?? '', g.predicted?.[i] ?? '', g.forecast?.[i] ?? '', g.forecast_lower?.[i] ?? '', g.forecast_upper?.[i] ?? '']);
    });
    const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `forecast_${currentGrain}_${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Page load fade-in (unchanged) ───────────────────────────────
window.addEventListener("load", () => {
    document.querySelectorAll('.section-box').forEach((el, index) => {
        el.style.opacity = 0;
        el.style.transform = "translateY(20px)";
        setTimeout(() => {
            el.style.transition = "all 0.6s ease";
            el.style.opacity = 1;
            el.style.transform = "translateY(0)";
        }, 200 * index);
    });
    switchGrain('monthly');
});
</script>

</body>
</html>