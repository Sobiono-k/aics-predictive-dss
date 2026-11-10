<?php
session_start();

// Security Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(60);

// Always serve fresh data — this page reflects live DB counts.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// Same connection file the Records page uses ($conn, mysqli).
require_once(__DIR__ . '/../db.php');

// ─────────────────────────────────────────────────────────────────
// SMALL HELPERS
// ─────────────────────────────────────────────────────────────────
$dbErrors = [];
function dbq($conn, $sql) {
    global $dbErrors;
    $r = $conn->query($sql);
    if ($r === false) { $dbErrors[] = $conn->error; }
    return $r;
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function js($v) {
    $out = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $out === false ? 'null' : $out;
}
function padNull($n) { return $n > 0 ? array_fill(0, $n, null) : []; }
function stdDev(array $x, $ddof = 1) {
    $n = count($x);
    if ($n - $ddof < 1) return 0.0;
    $m = array_sum($x) / $n;
    $s = 0.0;
    foreach ($x as $v) { $s += ($v - $m) ** 2; }
    return sqrt($s / ($n - $ddof));
}

// ─────────────────────────────────────────────────────────────────
// 1. DASHBOARD FIGURES — every number below is a query on aics_sample_data
// ─────────────────────────────────────────────────────────────────
$STATUS_ORDER  = ['Pending', 'Approved', 'Paid', 'Waitlisted', 'Declined'];   // same list as records.php
$STATUS_COLORS = ['Pending'=>'#f59e0b','Approved'=>'#10b981','Paid'=>'#2563eb','Waitlisted'=>'#9333ea','Declined'=>'#dc2626','Other'=>'#64748b'];

$r = dbq($conn, "SELECT COUNT(*) AS c, MAX(CASE WHEN request_date > '1990-01-01' THEN request_date END) AS latest FROM aics_sample_data");
$row = $r ? $r->fetch_assoc() : [];
$totalRequests = (int)($row['c'] ?? 0);
$latestDate    = $row['latest'] ?? null;
$daysOld       = $latestDate ? (int)floor((strtotime('today') - strtotime($latestDate)) / 86400) : null;

// Demand by assistance type
$allocations = [];
$r = dbq($conn, "SELECT COALESCE(NULLIF(TRIM(assistance_type),''),'Unspecified') AS t, COUNT(*) AS c
                 FROM aics_sample_data GROUP BY t ORDER BY c DESC");
if ($r) while ($x = $r->fetch_assoc()) {
    $allocations[] = ['type' => $x['t'], 'count' => (int)$x['c'],
                      'percent' => $totalRequests ? round($x['c'] / $totalRequests * 100, 1) : 0];
}

// Top 10 medical causes + share of ALL records that have a cause recorded
$medicalCauses = [];
$r = dbq($conn, "SELECT TRIM(medical_cause) AS cause_name, COUNT(*) AS c FROM aics_sample_data
                 WHERE medical_cause IS NOT NULL AND TRIM(medical_cause) <> ''
                 GROUP BY cause_name ORDER BY c DESC LIMIT 10");
if ($r) while ($x = $r->fetch_assoc()) { $medicalCauses[] = ['cause' => $x['cause_name'], 'count' => (int)$x['c']]; }

$r = dbq($conn, "SELECT COUNT(*) AS c FROM aics_sample_data WHERE medical_cause IS NOT NULL AND TRIM(medical_cause) <> ''");
$classifiedTotal = $r ? (int)$r->fetch_assoc()['c'] : 0;
$topCause    = $medicalCauses[0] ?? null;
$topCausePct = ($topCause && $classifiedTotal > 0) ? round($topCause['count'] / $classifiedTotal * 100, 1) : 0;

// Beneficiaries = Approved + Paid (a paid request was necessarily approved first)
$beneficiaries = [];
$r = dbq($conn, "SELECT COALESCE(NULLIF(TRIM(assistance_type),''),'Unspecified') AS t, COUNT(*) AS total
                 FROM aics_sample_data WHERE LOWER(TRIM(status)) IN ('approved','paid')
                 GROUP BY t ORDER BY total DESC");
if ($r) while ($x = $r->fetch_assoc()) { $beneficiaries[] = ['type' => $x['t'], 'total' => (int)$x['total']]; }
$totalBeneficiaries = array_sum(array_column($beneficiaries, 'total'));

// Status breakdown across ALL records (blank status is treated as Pending, as records.php does)
$statusCounts = array_fill_keys($STATUS_ORDER, 0);
$r = dbq($conn, "SELECT COALESCE(NULLIF(TRIM(status),''),'Pending') AS s, COUNT(*) AS c FROM aics_sample_data GROUP BY s");
if ($r) while ($x = $r->fetch_assoc()) {
    $name = ucfirst(strtolower($x['s']));
    if (!isset($statusCounts[$name])) { $name = 'Other'; $statusCounts['Other'] = $statusCounts['Other'] ?? 0; }
    $statusCounts[$name] += (int)$x['c'];
}
$statusLabels = []; $statusValues = []; $statusColors = [];
foreach ($statusCounts as $name => $cnt) {
    if ($cnt > 0) { $statusLabels[] = "$name ($cnt)"; $statusValues[] = $cnt; $statusColors[] = $STATUS_COLORS[$name] ?? '#64748b'; }
}

// ─────────────────────────────────────────────────────────────────
// 2. FORECAST DATA — live LSTM service if reachable, otherwise a DB baseline
// ─────────────────────────────────────────────────────────────────
$renderPythonApiUrl = "https://aics-predictive-dss.onrender.com/api/forecast";
$MODEL_START = '2022-09-01';   // same cut-off lstm_model.py applies
$GRAIN_CFG = [
    'weekly'  => ['window' => 26, 'steps' => 26, 'fmt' => 'M d, Y', 'unit' => 'week'],
    'monthly' => ['window' => 6,  'steps' => 6,  'fmt' => 'M Y',    'unit' => 'month'],
    'yearly'  => ['window' => 0,  'steps' => 5,  'fmt' => 'Y',      'unit' => 'year'],
];

function fetchForecastFromApi($url, $timeout = 8, &$why = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err || $httpCode !== 200 || !$response) {
        $why = $err ?: "HTTP $httpCode";
        error_log("Forecast API failed (HTTP $httpCode): $err");
        return null;
    }
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) { $why = 'Invalid JSON from API'; return null; }
    return $decoded;
}

// Period buckets mirror pandas: weekly = weeks ending Monday (W-MON), monthly = MS, yearly = YS.
function dbSeriesCounts($conn, $grain, $startDate) {
    $exprs = [
        'weekly'  => "DATE_ADD(DATE(request_date), INTERVAL MOD(7 - WEEKDAY(request_date), 7) DAY)",
        'monthly' => "DATE_FORMAT(request_date, '%Y-%m-01')",
        'yearly'  => "DATE_FORMAT(request_date, '%Y-01-01')",
    ];
    $expr = $exprs[$grain];
    $r = dbq($conn, "SELECT $expr AS k, COUNT(*) AS c FROM aics_sample_data
                     WHERE request_date >= '$startDate' AND request_date <= CURDATE()
                     GROUP BY k ORDER BY k ASC");
    $counts = [];
    if ($r) while ($x = $r->fetch_assoc()) { $counts[(string)$x['k']] = (int)$x['c']; }
    return $counts;
}
function stepSpec($grain) { return ['weekly' => '+7 days', 'monthly' => '+1 month', 'yearly' => '+1 year'][$grain]; }
function fillSeries(array $counts, $grain) {
    if (!$counts) return [];
    ksort($counts);
    $keys = array_keys($counts);
    $d = new DateTime($keys[0]); $end = new DateTime(end($keys));
    $out = []; $guard = 0;
    while ($d <= $end && $guard++ < 5000) {
        $k = $d->format('Y-m-d');
        $out[$k] = $counts[$k] ?? 0;
        $d->modify(stepSpec($grain));
    }
    return $out;
}
function dropIncomplete(array $series, $grain) {
    $today = new DateTime('today');
    $limit = ['weekly' => $today->format('Y-m-d'), 'monthly' => $today->format('Y-m-01'), 'yearly' => $today->format('Y-01-01')][$grain];
    foreach (array_keys($series) as $k) { if ($k >= $limit) unset($series[$k]); }
    return $series;
}
function nextKeys($lastKey, $grain, $steps) {
    $d = new DateTime($lastKey); $out = [];
    for ($i = 0; $i < $steps; $i++) { $d->modify(stepSpec($grain)); $out[] = $d->format('Y-m-d'); }
    return $out;
}
function fmtKeys(array $keys, $fmt) {
    return array_map(function ($k) use ($fmt) { return (new DateTime($k))->format($fmt); }, $keys);
}

// Same rule as lstm_model.py: last ~20% held out, errors measured against what actually happened.
// Baseline = average of the last `window` training periods, held flat.
function levelHoldout(array $vals, $window) {
    $n = count($vals);
    if ($window < 1 || $n <= $window + 5) return null;
    $split = max((int)floor($n * 0.8), $window + 2);
    if ($n - $split < 2) return null;
    $level = array_sum(array_slice($vals, $split - $window, $window)) / $window;
    $res = [];
    foreach (array_slice($vals, $split) as $a) { $res[] = $a - $level; }
    $k = count($res);
    $abs = array_map('abs', $res);
    return [
        'mae' => array_sum($abs) / $k,
        'rmse' => sqrt(array_sum(array_map(function ($e) { return $e * $e; }, $res)) / $k),
        'std' => $k > 1 ? stdDev($res, 1) : abs($res[0]),
        'k' => $k, 'split' => $split, 'level' => $level,
    ];
}
function linearTrend(array $y, $steps) {
    $n = count($y);
    $sx = $sy = $sxx = $sxy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sx += $i; $sy += $y[$i]; $sxx += $i * $i; $sxy += $i * $y[$i]; }
    $den = $n * $sxx - $sx * $sx;
    $slope = $den != 0 ? ($n * $sxy - $sx * $sy) / $den : 0.0;
    $icpt  = ($sy - $slope * $sx) / $n;
    $fit = []; $res = [];
    for ($i = 0; $i < $n; $i++) { $fit[] = $slope * $i + $icpt; $res[] = $y[$i] - $fit[$i]; }
    $std = $n > 2 ? stdDev($res, 1) : abs($y[$n - 1] - $y[0]) * 0.15;
    $fut = []; $lo = []; $hi = [];
    for ($s = 1; $s <= $steps; $s++) {
        $v = max(0, $slope * ($n - 1 + $s) + $icpt);
        $m = 1.96 * $std * sqrt($s);
        $fut[] = round($v, 2); $lo[] = round(max(0, $v - $m), 2); $hi[] = round($v + $m, 2);
    }
    return [
        'fit' => $fit, 'std' => $std, 'fut' => $fut, 'lo' => $lo, 'hi' => $hi,
        'mae' => array_sum(array_map('abs', $res)) / $n,
        'rmse' => sqrt(array_sum(array_map(function ($e) { return $e * $e; }, $res)) / $n),
    ];
}
function emptyGrain($why) {
    return ['labels' => [], 'actual' => [], 'predicted' => [], 'forecast' => [], 'forecast_lower' => [], 'forecast_upper' => [],
            'metrics' => ['mae' => null, 'rmse' => null, 'margin_of_error_95' => null, 'basis' => $why],
            'forecast_info' => ['forecast_steps' => 0]];
}

function buildFallbackGrain($conn, $grain, $cfg, $startDate, $latestDate) {
    $series = dropIncomplete(fillSeries(dbSeriesCounts($conn, $grain, $startDate), $grain), $grain);
    $keys = array_keys($series); $vals = array_map('floatval', array_values($series)); $n = count($vals);
    $steps = $cfg['steps'];

    if ($grain === 'yearly') {
        if ($n < 2) return emptyGrain('Not enough completed calendar years in the database (need at least 2).');
        $t = linearTrend($vals, $steps);
        $fut = nextKeys(end($keys), $grain, $steps);
        return [
            'labels' => array_merge(fmtKeys($keys, $cfg['fmt']), fmtKeys($fut, $cfg['fmt'])),
            'actual' => array_merge($vals, padNull($steps)),
            'predicted' => array_merge(array_map(function ($v) { return round($v, 2); }, $t['fit']), padNull($steps)),
            'forecast' => array_merge(padNull($n), $t['fut']),
            'forecast_lower' => array_merge(padNull($n), $t['lo']),
            'forecast_upper' => array_merge(padNull($n), $t['hi']),
            'metrics' => ['mae' => round($t['mae'], 2), 'rmse' => round($t['rmse'], 2),
                          'margin_of_error_95' => round(1.96 * $t['std'], 2),
                          'evaluation' => 'in_sample_linear_trend', 'validation_periods' => $n,
                          'basis' => 'Linear trend computed from the database'],
            'forecast_info' => ['forecast_steps' => $steps, 'data_as_of' => $latestDate, 'forecast_start' => $fut[0], 'forecast_end' => end($fut)],
        ];
    }

    $w = $cfg['window'];
    $h = levelHoldout($vals, $w);
    if (!$h) return emptyGrain("Not enough complete {$cfg['unit']}s in the database (need more than " . ($w + 5) . ", have $n).");

    $level = array_sum(array_slice($vals, -$w)) / $w;
    $pred = padNull($n);
    for ($i = $h['split']; $i < $n; $i++) { $pred[$i] = round($h['level'], 2); }
    $fc = []; $lo = []; $hi = [];
    for ($s = 1; $s <= $steps; $s++) {
        $m = 1.96 * $h['std'] * sqrt($s);
        $fc[] = round($level, 2); $lo[] = round(max(0, $level - $m), 2); $hi[] = round($level + $m, 2);
    }
    $fut = nextKeys(end($keys), $grain, $steps);
    return [
        'labels' => array_merge(fmtKeys($keys, $cfg['fmt']), fmtKeys($fut, $cfg['fmt'])),
        'actual' => array_merge($vals, padNull($steps)),
        'predicted' => array_merge($pred, padNull($steps)),
        'forecast' => array_merge(padNull($n), $fc),
        'forecast_lower' => array_merge(padNull($n), $lo),
        'forecast_upper' => array_merge(padNull($n), $hi),
        'metrics' => ['mae' => round($h['mae'], 2), 'rmse' => round($h['rmse'], 2),
                      'margin_of_error_95' => round(1.96 * $h['std'], 2),
                      'evaluation' => 'chronological_holdout', 'validation_periods' => $h['k'],
                      'basis' => "Database baseline: average of the last $w {$cfg['unit']}s held flat (not the LSTM)"],
        'forecast_info' => ['forecast_steps' => $steps, 'data_as_of' => $latestDate, 'forecast_start' => $fut[0], 'forecast_end' => end($fut)],
    ];
}

// Live LSTM output → fill in what lstm_model.py does not supply.
function enrichLiveGrain(array $g, $grain, $cfg) {
    $m = (isset($g['metrics']) && is_array($g['metrics'])) ? $g['metrics'] : [];
    $hist = array_values(array_filter($g['actual'] ?? [], function ($v) { return $v !== null; }));
    $n = count($hist);

    if ($grain === 'yearly') {
        // run_yearly_trend() returns only mae/rmse; derive the margin from its own fitted values.
        if ($n >= 2 && !isset($m['margin_of_error_95'])) {
            $res = [];
            foreach (($g['actual'] ?? []) as $i => $a) {
                $p = $g['predicted'][$i] ?? null;
                if ($a !== null && $p !== null) $res[] = $a - $p;
            }
            $std = count($res) > 2 ? stdDev($res, 1) : abs(end($hist) - $hist[0]) * 0.15;
            $m['margin_of_error_95'] = round(1.96 * $std, 2);
        }
        $m['evaluation'] = $m['evaluation'] ?? 'in_sample_linear_trend';
        $m['validation_periods'] = $m['validation_periods'] ?? $n;
        $m['basis'] = 'Linear trend over completed calendar years';
    } else {
        $h = levelHoldout($hist, $cfg['window']);
        if ($h) $m['baseline_mae'] = round($h['mae'], 2);
        $m['basis'] = 'Live LSTM model (last training run)';
    }
    $g['metrics'] = $m;
    return $g;
}

$apiWhy = null;
$apiResult = fetchForecastFromApi($renderPythonApiUrl, 8, $apiWhy);
$isRealModel = ($apiResult && isset($apiResult['lstm']['weekly'], $apiResult['lstm']['monthly'], $apiResult['lstm']['yearly']));

$GRAINS = []; $topDriver = [];
$dbTopType = $allocations[0]['type'] ?? 'No data';
foreach (['weekly', 'monthly', 'yearly'] as $g) {
    if ($isRealModel) {
        $GRAINS[$g] = enrichLiveGrain($apiResult['lstm'][$g], $g, $GRAIN_CFG[$g]);
        $p = $apiResult['random_forest'][$g]['predictions'][0]['assistance_type'] ?? null;
        $topDriver[$g] = $p ? preg_replace('/^Medical:\s*/', '', $p) : $dbTopType;
    } else {
        $GRAINS[$g] = buildFallbackGrain($conn, $g, $GRAIN_CFG[$g], $MODEL_START, $latestDate);
        $topDriver[$g] = $dbTopType;
    }
}

if ($isRealModel) {
    $methodology = "Source: AICS request records (one record = one request) from Sep 2022 to the latest record, aggregated to weekly and monthly counts. "
        . "Weekly and monthly forecasts come from an LSTM (log1p + min-max scaling; 16 LSTM units, an 8-unit ReLU layer and one output; Adam optimizer, MAE loss, early stopping). "
        . "Weekly uses the previous 26 weeks to forecast the next 26; monthly uses the previous 6 complete months to forecast the next 6. Forecasts are recursive: each predicted value is fed back in to predict the next. "
        . "Accuracy: the most recent ~20% of periods are withheld, forecast from the earlier history, and compared with the actual counts (MAE, RMSE). The 95% margin is 1.96 × the standard deviation of those holdout errors, widened by √(periods ahead). "
        . "Because the holdout is forecast recursively, these errors are conservative compared with one-step-ahead accuracy. "
        . "Yearly figures are a straight-line trend over completed calendar years; their errors are measured on the data the line was fitted to, so they describe fit, not forecasting skill.";
} else {
    $methodology = "The live LSTM service could not be reached, so nothing on this page comes from the LSTM. "
        . "Forecast figures are computed directly from the database with a simple baseline: the average of the last 26 weeks (weekly) or 6 months (monthly), held flat. "
        . "Its accuracy is measured the same way the LSTM's is: the most recent ~20% of periods are withheld, forecast from the earlier history, and compared with actual counts. The 95% margin is 1.96 × the standard deviation of those errors, widened by √(periods ahead). "
        . "Yearly figures use the same straight-line trend over completed years that the model uses; their errors describe fit only.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics</title>
    <link rel="icon" type="image/png" href="<?php echo defined('BASE_URL') ? BASE_URL : '../'; ?>images/dswdlogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-y: scroll; }
        :root {
            --dswd-dark: #2c3e50; --sidebar-bg: #1e293b; --bg-color: #f8fafc;
            --card-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); --sidebar-width: 260px;
            --dswd-blue: #0038a8; --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
        }
        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg-color); display: flex; color: #334155; }

        .sidebar { width: var(--sidebar-width); height: 100vh; background: var(--sidebar-bg); position: fixed; left: 0; top: 0; color: #fff; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; text-align: center; background: rgba(0,0,0,0.2); }
        .sidebar a { padding: 15px 25px; text-decoration: none; color: #94a3b8; display: flex; align-items: center; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: #334155; color: #fff; border-left: 4px solid #3b82f6; }
        .main { margin-left: var(--sidebar-width); padding: 40px; width: calc(100% - var(--sidebar-width)); min-height: 100vh; }

        .header-area { margin-bottom: 24px; border-bottom: 2px solid var(--dswd-blue); padding-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; }
        .header-text h4 { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        .header-text h1 { margin: 5px 0; font-size: 24px; color: var(--dswd-dark); font-weight: 800; }
        .header-meta { text-align: right; font-size: 11px; color: #94a3b8; line-height: 1.6; }

        .engine-badge { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:6px 12px; border-radius:999px; margin-bottom: 4px; }
        .engine-badge.live { background:#f0fdf4; color:#16a34a; border:1px solid #86efac; }
        .engine-badge.fallback { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }

        .alert { border-radius: 10px; padding: 12px 16px; font-size: 13px; margin-bottom: 16px; line-height: 1.5; }
        .alert.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
        .alert.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

        .section-box { background: #fff; padding: 25px; border-radius: 12px; box-shadow: var(--card-shadow); margin-bottom: 20px; border-left: 4px solid #0038a8; transition: all 0.3s ease; }
        .section-box:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }

        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .report-card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: var(--card-shadow); cursor: pointer; transition: 0.3s; border: 1px solid transparent; display: flex; align-items: center; gap: 20px; }
        .report-card:hover { transform: translateY(-3px); border-color: var(--dswd-blue); }
        .report-card i { font-size: 32px; color: var(--dswd-blue); background: #eff6ff; padding: 15px; border-radius: 10px; }
        .report-card h3 { margin: 0; font-size: 16px; color: var(--dswd-dark); }

        .highlight-card { background: linear-gradient(135deg, #0038a8, #1d4ed8); color: #fff; border-radius: 12px; padding: 24px 28px; margin-bottom: 20px; box-shadow: var(--card-shadow); display: flex; align-items: center; gap: 20px; }
        .highlight-card i { font-size: 34px; background: rgba(255,255,255,.15); padding: 16px; border-radius: 12px; }
        .highlight-label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .8; font-weight: 700; }
        .highlight-name { font-size: 24px; font-weight: 800; margin: 4px 0; }
        .highlight-sub { font-size: 13px; opacity: .9; }

        .chart-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-wrapper { position: relative; height: 300px; width: 100%; }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table th { text-align: left; padding: 15px; background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; border-bottom: 2px solid #e2e8f0; }
        table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }

        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; transition: 0.2s; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .btn-outline { background: #fff; color: var(--dswd-dark); border: 2px solid #e2e8f0; }
        .btn-outline:hover { border-color: var(--dswd-blue); color: var(--dswd-blue); }

        .signature-section { display: none; margin-top: 50px; justify-content: space-between; padding: 0 50px; }
        .sig-box { text-align: center; border-top: 1px solid #000; width: 200px; padding-top: 10px; font-size: 12px; font-weight: 600; }

        .section-title-row { display:flex; justify-content: space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .section-title-row h2 { margin:0; font-size:18px; color: var(--dswd-dark); }
        .section-subtitle { font-size:12px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }

        .grain-toggle { display:inline-flex; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:12px; padding:4px; gap:4px; }
        .grain-toggle button { border:none; background:transparent; padding:7px 16px; border-radius:8px; font-size:12px; font-weight:600; color:#64748b; cursor:pointer; font-family:'Inter',sans-serif; }
        .grain-toggle button.active { background:#3b82f6; color:#fff; }

        .horizon-line { font-size: 12px; color: #64748b; margin: -8px 0 16px; }
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:20px; }
        .kpi-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; border-left: 4px solid var(--dswd-blue); }
        .kpi-card .kpi-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
        .kpi-card .kpi-val { font-size:20px; font-weight:800; color:#1e293b; margin:4px 0 2px; }
        .kpi-card .kpi-sub { font-size:11px; color:#94a3b8; }

        .metric-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:12px; }
        .metric-card { background:#fff; padding:22px; border-radius:12px; box-shadow: var(--card-shadow); border-left: 4px solid var(--dswd-blue); }
        .metric-card .metric-label { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; font-weight:700; }
        .metric-card .metric-value { font-size:24px; font-weight:800; color: var(--dswd-dark); margin-top:6px; }
        .metric-card .metric-sub { font-size:12px; color:#94a3b8; margin-top:4px; }
        .metric-card.mae { border-left-color: var(--warning); }
        .metric-card.margin { border-left-color: var(--dswd-blue); }
        .metric-note { font-size: 12px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }

        .method-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; font-size:12px; color:#475569; line-height:1.65; }
        .method-box h4 { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#64748b; margin-bottom:8px; }

        .export-row { display:flex; gap:12px; flex-wrap:wrap; }

        @media print {
            .sidebar, .report-grid, .btn, .no-print { display: none !important; }
            .main { margin: 0; padding: 0; width: 100%; }
            .chart-container { display: block !important; }
            .section-box { box-shadow: none; border: 1px solid #eee; display: block !important; margin-bottom: 30px; page-break-inside: avoid; }
            .signature-section { display: flex !important; page-break-inside: avoid; }
            .header-area { border-bottom: 2px solid #000; }
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main">
    <div class="header-area">
        <div class="header-text">
            <h4>Republic of the Philippines</h4>
            <h1>Reports & Analytics</h1>
            <p style="color:#64748b; font-size: 12px;">Batasan Hills AICS - Department of Social Welfare and Development</p>
        </div>
        <div class="header-meta">
            <span class="engine-badge <?php echo $isRealModel ? 'live' : 'fallback'; ?>">
                <i class="fas <?php echo $isRealModel ? '' : 'fa-database'; ?>"></i>
                <?php echo $isRealModel ? 'Live Report' : 'Database Baseline (LSTM unavailable)'; ?>
            </span><br>
            <?php echo number_format($totalRequests); ?> records
            <?php if ($latestDate): ?> · latest request <?php echo h(date('M d, Y', strtotime($latestDate))); ?><?php endif; ?><br>
            
        </div>
    </div>

    <?php if (!empty($dbErrors)): ?>
    <div class="alert err"><strong>Database query problem:</strong> <?php echo h(implode(' | ', array_unique($dbErrors))); ?></div>
    <?php endif; ?>
    <?php if (!$isRealModel): ?>
    <div class="alert warn"><strong>Live model unreachable</strong> (<?php echo h($apiWhy ?: 'no response'); ?>). Forecast and accuracy figures below are computed from the database with a simple baseline, not the LSTM.</div>
    <?php endif; ?>
    <?php if ($daysOld !== null && $daysOld > 45): ?>
    <div class="alert warn"><strong>Data may be outdated:</strong> the latest request on record is <?php echo (int)$daysOld; ?> days old, so forecasts start after the last complete period rather than from today.</div>
    <?php endif; ?>

    <div class="report-grid no-print">
        <div class="report-card" onclick="showSection('medical')">
            <i class="fas fa-notes-medical"></i>
            <div><h3>Medical Drivers</h3><p>Health Distress Analysis</p></div>
        </div>
        <div class="report-card" onclick="showSection('model')">
            <i class="fas fa-robot"></i>
            <div><h3>Model Performance</h3><p>Prediction Analytics</p></div>
        </div>
        <div class="report-card">
            <i class="fas fa-calendar-check"></i>
            <div><h3>Report Date</h3><p><?php echo date('F d, Y'); ?></p></div>
        </div>
    </div>

    <?php if ($topCause): ?>
    <div id="topCauseCard" class="highlight-card">
        <i class="fas fa-trophy"></i>
        <div>
            <div class="highlight-label">#1 Medical Cause</div>
            <div class="highlight-name"><?php echo h($topCause['cause']); ?></div>
            <div class="highlight-sub"><?php echo number_format($topCause['count']); ?> cases · <?php echo $topCausePct; ?>% of the <?php echo number_format($classifiedTotal); ?> records with a recorded cause</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="chart-container">
        <div class="section-box">
            <h3 style="margin-top:0; font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Demand Distribution (%)</h3>
            <div class="chart-wrapper"><canvas id="budgetChart"></canvas></div>
        </div>
        <div class="section-box">
            <h3 style="margin-top:0; font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Top Medical Incidents</h3>
            <div class="chart-wrapper"><canvas id="causeChart"></canvas></div>
        </div>
    </div>

    <div id="beneficiarySection" class="section-box">
        <div class="section-title-row">
            <div>
                <h2>Beneficiaries per Assistance Category</h2>
                <div class="section-subtitle">Approved and Paid requests</div>
            </div>
            <div style="font-weight:800; color:var(--dswd-blue); font-size:20px;"><?php echo number_format($totalBeneficiaries); ?> total</div>
        </div>
        <div class="chart-container" style="margin-bottom:0;">
            <div>
                <table>
                    <thead><tr><th>Category</th><th>Beneficiaries</th><th>Share</th></tr></thead>
                    <tbody>
                        <?php foreach ($beneficiaries as $b): ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo h($b['type']); ?></td>
                            <td><?php echo number_format($b['total']); ?></td>
                            <td><?php echo $totalBeneficiaries ? round($b['total'] / $totalBeneficiaries * 100, 1) : 0; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($beneficiaries)): ?>
                        <tr><td colspan="3" style="color:#94a3b8;">No approved or paid records yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="chart-wrapper"><canvas id="beneficiaryChart"></canvas></div>
        </div>
    </div>

    <div id="modelSection" class="section-box">
        <div class="section-title-row">
            <div>
                <h2>Model Performance & Prediction Analytics</h2>
                <div class="section-subtitle">Forecasting Diagnostics</div>
            </div>
            <div class="grain-toggle no-print">
                <button onclick="switchGrain('weekly')"  id="g-weekly">Weekly</button>
                <button onclick="switchGrain('monthly')" id="g-monthly" class="active">Monthly</button>
                <button onclick="switchGrain('yearly')"  id="g-yearly">Yearly</button>
            </div>
        </div>

        <div class="horizon-line" id="horizonLine"></div>
        <div class="kpi-grid" id="kpiGrid"></div>

        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Error Metrics</h3>
        <div class="metric-grid" id="diagGrid"></div>
        <div class="metric-note" id="metricNote"></div>

        <div class="chart-container" style="margin-bottom:0;">
            <div>
                <h3 style="font-size:12px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Request Status (all records)</h3>
                <div class="chart-wrapper" style="height:260px;"><canvas id="outcomeChart"></canvas></div>
                <div style="font-size:11px; color:#94a3b8; margin-top:8px;">Total <?php echo number_format($totalRequests); ?> — matches “Total Applicants” on the Records page with no filters.</div>
            </div>
            <div class="method-box">
                <h4>Methodology & evaluation basis</h4>
                <?php echo h($methodology); ?>
            </div>
        </div>

        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin: 25px 0 15px;">Export</h3>
        <div class="export-row no-print">
            <button type="button" class="btn btn-outline" onclick="exportCsv()"><i class="fas fa-file-csv"></i> Export CSV</button>
            <button type="button" class="btn btn-outline" onclick="window.print()"><i class="fas fa-file-pdf"></i> Export PDF</button>
        </div>
    </div>

    <div class="signature-section">
        <div class="sig-box">Prepared By: System Admin</div>
        <div class="sig-box">Approved By: Department Head</div>
    </div>
</div>

<script>
const budgetLabels = <?php echo js(array_column($allocations, 'type')); ?>;
const budgetData   = <?php echo js(array_column($allocations, 'percent')); ?>;
const causeLabels  = <?php echo js(array_column($medicalCauses, 'cause')); ?>;
const causeData    = <?php echo js(array_column($medicalCauses, 'count')); ?>;
const benLabels    = <?php echo js(array_column($beneficiaries, 'type')); ?>;
const benCounts    = <?php echo js(array_column($beneficiaries, 'total')); ?>;
const statusLabels = <?php echo js($statusLabels); ?>;
const statusValues = <?php echo js($statusValues); ?>;
const statusColors = <?php echo js($statusColors); ?>;

const GRAINS = <?php echo js($GRAINS); ?>;
const TOP_DRIVER = <?php echo js($topDriver); ?>;
const GRAIN_UNIT = <?php echo js(array_map(function ($c) { return $c['unit']; }, $GRAIN_CFG)); ?>;
const IS_REAL_MODEL = <?php echo $isRealModel ? 'true' : 'false'; ?>;

let currentGrain = 'monthly';
const nz  = v => v !== null && v !== undefined;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const num = (v, d = 2) => nz(v) ? Number(v).toLocaleString(undefined, { maximumFractionDigits: d }) : 'n/a';

function showSection(type) {
    const id = type === 'model' ? 'modelSection' : 'topCauseCard';
    const el = document.getElementById(id) || document.getElementById('beneficiarySection');
    if (el) el.scrollIntoView({ behavior: 'smooth' });
}

// ── Charts ──────────────────────────────────────────────────────
const animationConfig = { duration: 1800, easing: 'easeOutQuart' };

const budgetCanvas = document.getElementById('budgetChart');
if (budgetCanvas && budgetLabels.length) {
    new Chart(budgetCanvas.getContext('2d'), {
        type: 'doughnut',
        data: { labels: budgetLabels, datasets: [{ data: budgetData,
            backgroundColor: ['#0038a8','#ce1126','#f59e0b','#10b981','#6366f1','#64748b'],
            borderWidth: 2, borderColor: '#ffffff', hoverOffset: 10 }] },
        options: { responsive: true, maintainAspectRatio: false, animation: animationConfig, cutout: '60%',
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11, weight: '600' }, color: '#334155' } } } }
    });
}

const causeCanvas = document.getElementById('causeChart');
if (causeCanvas && causeLabels.length) {
    new Chart(causeCanvas.getContext('2d'), {
        type: 'bar',
        data: { labels: causeLabels, datasets: [{ label: 'Cases Reported', data: causeData,
            backgroundColor: causeLabels.map((_, i) => i === 0 ? '#ce1126' : '#0038a8'),
            borderRadius: 6, borderSkipped: false, barThickness: 18 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 2000, easing: 'easeOutQuart' },
            plugins: { legend: { display: false } },
            scales: { x: { grid: { color: '#e2e8f0' }, ticks: { precision: 0 } }, y: { grid: { display: false } } } }
    });
}

const benCanvas = document.getElementById('beneficiaryChart');
if (benCanvas && benLabels.length) {
    new Chart(benCanvas.getContext('2d'), {
        type: 'bar',
        data: { labels: benLabels, datasets: [{ label: 'Beneficiaries (Approved + Paid)', data: benCounts,
            backgroundColor: '#10b981', borderRadius: 6, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false, animation: animationConfig,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e2e8f0' } }, x: { grid: { display: false } } } }
    });
}

const outcomeCanvas = document.getElementById('outcomeChart');
if (outcomeCanvas && statusLabels.length) {
    new Chart(outcomeCanvas.getContext('2d'), {
        type: 'pie',
        data: { labels: statusLabels, datasets: [{ data: statusValues, backgroundColor: statusColors, borderWidth: 2, borderColor: '#ffffff' }] },
        options: { responsive: true, maintainAspectRatio: false, animation: animationConfig,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11, weight: '600' }, color: '#334155' } } } }
    });
}

// ── Forecast section ────────────────────────────────────────────
// lstm_model.py puts the last ACTUAL value at the start of every forecast array (a connector point),
// so the real forecast begins at index = number of historical periods. Monthly output can also
// include "bridge" months before the requested window, so only the last forecast_steps are used.
function horizon(g) {
    const n = (g.actual || []).filter(nz).length;
    let labels = (g.labels || []).slice(n);
    let vals   = (g.forecast || []).slice(n);
    const steps = g.forecast_info && g.forecast_info.forecast_steps;
    if (steps && vals.length > steps) { labels = labels.slice(-steps); vals = vals.slice(-steps); }
    return { n, labels, vals: vals.map(Number).filter(v => isFinite(v)) , labelsAll: labels };
}

function renderKpis(grain) {
    const g = GRAINS[grain], m = g.metrics || {};
    const hz = horizon(g);
    const total = hz.vals.reduce((a, b) => a + b, 0);
    const mean  = hz.vals.length ? total / hz.vals.length : 0;
    const unit  = GRAIN_UNIT[grain];
    const marginPct = (nz(m.margin_of_error_95) && mean) ? (m.margin_of_error_95 / mean * 100) : null;

    let peakVal = -Infinity, peakLabel = '—';
    hz.vals.forEach((v, i) => { if (v > peakVal) { peakVal = v; peakLabel = hz.labelsAll[i]; } });
    const flat = hz.vals.length && (Math.max(...hz.vals) - Math.min(...hz.vals)) < 1e-6;

    document.getElementById('horizonLine').textContent = hz.vals.length
        ? `Forecast window: ${hz.labelsAll[0]} – ${hz.labelsAll[hz.labelsAll.length - 1]} (${hz.vals.length} ${unit}${hz.vals.length === 1 ? '' : 's'}) · based on ${hz.n} complete historical ${unit}s`
        : 'No forecast available for this view yet.';

    const cards = [
        { label: 'Projected Total Volume', val: Math.round(total).toLocaleString(), sub: `Sum of the next ${hz.vals.length} ${unit}${hz.vals.length === 1 ? '' : 's'}` },
        { label: 'Forecast Uncertainty', val: nz(marginPct) ? '± ' + marginPct.toFixed(1) + '%' : 'n/a', sub: '95% margin vs. average forecast' },
        flat
          ? { label: 'Next Period Forecast', val: esc(peakLabel), sub: Math.round(peakVal).toLocaleString() + ' requests per ' + unit }
          : { label: 'Peak Demand Window',   val: esc(peakLabel), sub: Math.round(Math.max(peakVal, 0)).toLocaleString() + ' requests projected' },
        { label: 'Primary Driver Category', val: esc(TOP_DRIVER[grain] || 'No data'), sub: 'Highest-demand assistance type' },
    ];
    document.getElementById('kpiGrid').innerHTML = cards.map(c => `
        <div class="kpi-card"><div class="kpi-label">${c.label}</div><div class="kpi-val">${c.val}</div><div class="kpi-sub">${c.sub}</div></div>`).join('');
}

function renderDiagnostics(grain) {
    const m = (GRAINS[grain] && GRAINS[grain].metrics) || {};
    const cards = [
        { label: 'MAE', val: num(m.mae), sub: 'Mean Absolute Error (requests per period)', cls: 'mae' },
        { label: '95% Margin', val: nz(m.margin_of_error_95) ? '± ' + num(m.margin_of_error_95) : 'n/a', sub: 'Forecast uncertainty envelope', cls: 'margin' },
    ];
    document.getElementById('diagGrid').innerHTML = cards.map(c => `
        <div class="metric-card ${c.cls}"><div class="metric-label">${c.label}</div><div class="metric-value">${c.val}</div><div class="metric-sub">${c.sub}</div></div>`).join('');

    let note = '';
    if (!nz(m.mae)) {
        note = m.basis || 'Not enough complete periods in the database to evaluate accuracy at this level yet.';
    } else {
        const evalTxt = { chronological_holdout: 'chronological holdout', in_sample_linear_trend: 'in-sample trend fit' }[m.evaluation] || (m.evaluation || '');
        note = `${m.basis ? esc(m.basis) + ' · ' : ''}Evaluation: ${evalTxt}${m.validation_periods ? ' over ' + m.validation_periods + ' periods' : ''}.`;
        if (nz(m.baseline_mae) && m.baseline_mae > 0) {
            const diff = (m.baseline_mae - m.mae) / m.baseline_mae * 100;
            note += ` A naive baseline (recent average held flat) scores MAE ${num(m.baseline_mae)} on the same holdout, so the model's error is ${Math.abs(diff).toFixed(0)}% ${diff >= 0 ? 'lower' : 'higher'}.`;
        }
        if (m.evaluation === 'in_sample_linear_trend') note += ' This describes how well the line fits past years, not how well it forecasts.';
        if (m.validation_periods && m.validation_periods < 6) note += ' Few evaluation periods — treat as provisional.';
    }
    document.getElementById('metricNote').innerHTML = note;
}

function switchGrain(grain) {
    currentGrain = grain;
    ['weekly', 'monthly', 'yearly'].forEach(g => document.getElementById('g-' + g).classList.toggle('active', g === grain));
    renderKpis(grain);
    renderDiagnostics(grain);
}

function exportCsv() {
    const g = GRAINS[currentGrain];
    const n = (g.actual || []).filter(nz).length;
    const fitHeader = IS_REAL_MODEL && currentGrain !== 'yearly' ? 'Model Fit (in-sample)' : (IS_REAL_MODEL ? 'Trend Fit (in-sample)' : 'Baseline / Fit');
    const rows = [['Period', 'Actual', fitHeader, 'Forecast', 'Lower 95%', 'Upper 95%']];
    (g.labels || []).forEach((label, i) => {
        const fut = i >= n;   // skip the connector point lstm_model.py places at index n-1
        rows.push([label, g.actual?.[i] ?? '', g.predicted?.[i] ?? '',
                   fut ? (g.forecast?.[i] ?? '') : '', fut ? (g.forecast_lower?.[i] ?? '') : '', fut ? (g.forecast_upper?.[i] ?? '') : '']);
    });
    const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `forecast_${currentGrain}_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

window.addEventListener('load', () => {
    document.querySelectorAll('.section-box').forEach((el, index) => {
        el.style.opacity = 0;
        el.style.transform = 'translateY(20px)';
        setTimeout(() => { el.style.transition = 'all 0.6s ease'; el.style.opacity = 1; el.style.transform = 'translateY(0)'; }, 200 * index);
    });
    switchGrain('monthly');
});
</script>

</body>
</html>