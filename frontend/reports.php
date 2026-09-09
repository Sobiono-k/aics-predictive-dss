<?php
session_start();

// Security Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// =========================
// DB CONNECTION
// =========================
require_once(__DIR__ . '/../db.php');

// ❌ REMOVED: mysqli_close($conn); <-- Do not close here!


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
// SAFE JSON ENCODING
// =========================
$chartLabels = json_encode(array_column($allocations, 'type') ?: []);
$chartData = json_encode(array_column($allocations, 'percent') ?: []);

$medicalLabels = json_encode(array_column($medicalCauses, 'medical_cause') ?: []);
$medicalCounts = json_encode(array_column($medicalCauses, 'count') ?: []);

// =========================
// MODEL PERFORMANCE (MOCK DATA)
// =========================
// NOTE: Placeholder data only — no prediction/model table exists yet.
// Replace this block with real queries once a predictions data source is defined.

$modelPerformance = [
    'accuracy' => 87.4,
    'mae' => 4.32,
    'confidence' => 91.0,
];

$predictionVolume = [
    'today' => 128,
    'week' => 842,
    'month' => 3510,
];

$outcomeBreakdown = [
    'labels' => ['Positive', 'Negative'],
    'counts' => [612, 230],
];

$predictionTrend = [
    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    'values' => [102, 118, 95, 130, 121, 140, 136],
];

$topFactors = [
    ['factor' => 'Household Income', 'impact' => 34.2],
    ['factor' => 'Number of Dependents', 'impact' => 22.8],
    ['factor' => 'Medical Cause Severity', 'impact' => 18.5],
    ['factor' => 'Employment Status', 'impact' => 14.1],
    ['factor' => 'Barangay Location', 'impact' => 10.4],
];

$dataDriftAlerts = [
    ['feature' => 'Household Income', 'status' => 'warning', 'message' => 'Distribution shifted 12% vs. training baseline'],
    ['feature' => 'Number of Dependents', 'status' => 'ok', 'message' => 'Within expected range'],
    ['feature' => 'Medical Cause Severity', 'status' => 'critical', 'message' => 'Distribution shifted 28% vs. training baseline'],
];

$modelPerfJson = json_encode($modelPerformance);
$outcomeLabelsJson = json_encode($outcomeBreakdown['labels']);
$outcomeCountsJson = json_encode($outcomeBreakdown['counts']);
$trendLabelsJson = json_encode($predictionTrend['labels']);
$trendValuesJson = json_encode($predictionTrend['values']);
$factorLabelsJson = json_encode(array_column($topFactors, 'factor'));
$factorImpactJson = json_encode(array_column($topFactors, 'impact'));

// ⚠️ Note: If you have an `include 'sidebar.php';` later in this file, 
// move $conn->close() to AFTER that include (or at the absolute end of the file).
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-y: scroll; }

        :root {
            --dswd-dark: #2c3e50;
            --sidebar-bg: #1e293b;
            --bg-color: #f8fafc;
            --card-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --sidebar-width: 260px;
            --dswd-blue: #0038a8; /* Official Gov Blue */
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
        
        .header-area { margin-bottom: 30px; border-bottom: 2px solid var(--dswd-blue); padding-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header-text h4 { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        .header-text h1 { margin: 5px 0; font-size: 24px; color: var(--dswd-dark); font-weight: 800; }

        .section-box {
            border-left: 4px solid #0038a8;
            transition: all 0.3s ease;
        }

        .section-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        canvas {
            transition: all 0.3s ease;
        }
        
        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .report-card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: var(--card-shadow); cursor: pointer; transition: 0.3s; border: 1px solid transparent; display: flex; align-items: center; gap: 20px; }
        .report-card:hover { transform: translateY(-3px); border-color: var(--dswd-blue); }
        .report-card i { font-size: 32px; color: var(--dswd-blue); background: #eff6ff; padding: 15px; border-radius: 10px; }
        .report-card h3 { margin: 0; font-size: 16px; color: var(--dswd-dark); }

        .chart-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .section-box { background: #fff; padding: 25px; border-radius: 12px; box-shadow: var(--card-shadow); margin-bottom: 20px; }
        .hidden { display: none; }

        /* Fixed wrapper to prevent height-bugging */
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

        /* ===== Model Performance section ===== */
        .section-title-row { display:flex; justify-content: space-between; align-items:center; margin-bottom: 20px; }
        .section-title-row h2 { margin:0; font-size:18px; color: var(--dswd-dark); }
        .section-subtitle { font-size:12px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }

        .mock-badge { display:inline-flex; align-items:center; gap:6px; background:#fef3c7; color:#92400e; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:4px 10px; border-radius:999px; }

        .metric-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px; }
        .metric-card { background:#fff; padding:22px; border-radius:12px; box-shadow: var(--card-shadow); border-left: 4px solid var(--dswd-blue); }
        .metric-card .metric-label { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; font-weight:700; }
        .metric-card .metric-value { font-size:28px; font-weight:800; color: var(--dswd-dark); margin-top:6px; }
        .metric-card .metric-sub { font-size:12px; color:#94a3b8; margin-top:4px; }
        .metric-card.accuracy { border-left-color: var(--success); }
        .metric-card.error { border-left-color: var(--warning); }
        .metric-card.confidence { border-left-color: var(--dswd-blue); }

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

        .drift-item { display:flex; align-items:flex-start; gap:12px; padding:12px 0; border-bottom:1px solid #f1f5f9; }
        .drift-item:last-child { border-bottom:none; }
        .drift-dot { width:10px; height:10px; border-radius:50%; margin-top:4px; flex-shrink:0; }
        .drift-dot.ok { background: var(--success); }
        .drift-dot.warning { background: var(--warning); }
        .drift-dot.critical { background: var(--danger); }
        .drift-feature { font-size:13px; font-weight:700; color:var(--dswd-dark); }
        .drift-message { font-size:12px; color:#64748b; margin-top:2px; }
        .drift-status-label { font-size:10px; text-transform:uppercase; font-weight:700; letter-spacing:.5px; }
        .drift-status-label.ok { color: var(--success); }
        .drift-status-label.warning { color: var(--warning); }
        .drift-status-label.critical { color: var(--danger); }

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

<?php include 'sidebar.php'; ?>

<div class="main">
    <div class="header-area">
        <div class="header-text">
            <h4>Republic of the Philippines</h4>
            <h1>Reports & Analytics</h1>
            <p style="color:#64748b; font-size: 12px;">Batasan Hills AICS - Department of Social Welfare and Development</p>
        </div>
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

    <div id="budgetSection" class="section-box">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
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
         MODEL PERFORMANCE SECTION (MOCK DATA)
         ========================= -->
    <div id="modelSection" class="section-box">
        <div class="section-title-row">
            <div>
                <h2>Model Performance & Prediction Analytics</h2>
                <div class="section-subtitle">Forecasting Diagnostics</div>
            </div>
            <span class="mock-badge no-print"><i class="fas fa-flask"></i> Sample Data</span>
        </div>

        <!-- Performance Metrics -->
        <div class="metric-grid">
            <div class="metric-card accuracy">
                <div class="metric-label">Accuracy Score</div>
                <div class="metric-value"><?php echo number_format($modelPerformance['accuracy'], 1); ?>%</div>
                <div class="metric-sub">% of correct predictions</div>
            </div>
            <div class="metric-card error">
                <div class="metric-label">Error Rate (MAE)</div>
                <div class="metric-value"><?php echo number_format($modelPerformance['mae'], 2); ?></div>
                <div class="metric-sub">Mean Absolute Error</div>
            </div>
            <div class="metric-card confidence">
                <div class="metric-label">Confidence Level</div>
                <div class="metric-value"><?php echo number_format($modelPerformance['confidence'], 1); ?>%</div>
                <div class="metric-sub">Avg. confidence, recent forecasts</div>
            </div>
        </div>

        <!-- Prediction Summaries -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Prediction Summaries</h3>
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
                <h3 style="font-size:12px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Outcome Breakdown</h3>
                <div class="chart-wrapper" style="height:240px;">
                    <canvas id="outcomeChart"></canvas>
                </div>
            </div>
            <div>
                <h3 style="font-size:12px; text-transform: uppercase; color: #64748b; margin-bottom: 15px;">Recent Trend (Predictions / Day)</h3>
                <div class="chart-wrapper" style="height:240px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Data and Feature Insights -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin: 25px 0 15px;">Top Factors</h3>
        <div>
            <?php foreach ($topFactors as $f): ?>
            <div class="factor-row">
                <div class="factor-name"><?php echo htmlspecialchars($f['factor']); ?></div>
                <div class="factor-bar-track">
                    <div class="factor-bar-fill" style="width:<?php echo $f['impact']; ?>%;"></div>
                </div>
                <div class="factor-pct"><?php echo number_format($f['impact'], 1); ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin: 25px 0 15px;">Data Drift Alerts</h3>
        <div>
            <?php foreach ($dataDriftAlerts as $alert): ?>
            <div class="drift-item">
                <div class="drift-dot <?php echo htmlspecialchars($alert['status']); ?>"></div>
                <div>
                    <div class="drift-feature"><?php echo htmlspecialchars($alert['feature']); ?>
                        <span class="drift-status-label <?php echo htmlspecialchars($alert['status']); ?>">
                            &nbsp;· <?php echo htmlspecialchars(ucfirst($alert['status'])); ?>
                        </span>
                    </div>
                    <div class="drift-message"><?php echo htmlspecialchars($alert['message']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Export and History -->
        <h3 style="font-size:14px; text-transform: uppercase; color: #64748b; margin: 25px 0 15px;">Export & History</h3>
        <div class="export-row no-print">
            <button type="button" class="btn btn-outline" onclick="alert('CSV export not yet wired to a data source.');">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
            <button type="button" class="btn btn-outline" onclick="alert('PDF export not yet wired to a data source.');">
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

// Model performance mock chart data
const outcomeLabels = <?php echo $outcomeLabelsJson ?: '[]'; ?>;
const outcomeCounts = <?php echo $outcomeCountsJson ?: '[]'; ?>;
const trendLabels = <?php echo $trendLabelsJson ?: '[]'; ?>;
const trendValues = <?php echo $trendValuesJson ?: '[]'; ?>;

// =========================
// INTERACTIVE SECTION FILTER
// =========================
function showSection(type) {
    let targetId = 'budgetSection';
    if (type === 'model') targetId = 'modelSection';
    if (type === 'medical') targetId = 'budgetSection'; // medical chart lives up top; no dedicated section yet

    const section = document.getElementById(targetId);
    if (section) {
        section.scrollIntoView({ behavior: 'smooth' });
    }
}

// =========================
// GOV STYLE ANIMATION SETTINGS
// =========================
const animationConfig = {
    duration: 1800,
    easing: 'easeOutQuart'
};


// =========================
// BUDGET (DOUGHNUT - GOV STYLE)
// =========================
const budgetCanvas = document.getElementById('budgetChart');

if (budgetCanvas && budgetLabels.length > 0) {
    new Chart(budgetCanvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: budgetLabels,
            boxWidth: 20,
            datasets: [{
                data: budgetData,
                backgroundColor: [
                    '#0038a8', // gov blue
                    '#ce1126', // gov red
                    '#f59e0b', // amber
                    '#10b981', // green
                    '#6366f1', // indigo
                    '#64748b'  // gray
                ],
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,

            animation: {
                animateRotate: true,
                animateScale: true,
                duration: animationConfig.duration,
                easing: animationConfig.easing
            },

            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: {
                            size: 11,
                            weight: '600'
                        },
                        color: '#334155'
                    }
                }
            },

            cutout: '60%'
        }
    });
}


// =========================
// MEDICAL CAUSE (GOV BAR CHART)
// =========================
const causeCanvas = document.getElementById('causeChart');

if (causeCanvas && causeLabels.length > 0) {
    new Chart(causeCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: causeLabels,
            datasets: [{
                label: 'Cases Reported',
                data: causeData,
                backgroundColor: '#0038a8',
                borderRadius: 6,
                borderSkipped: false,
                barThickness: 18
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,

            animation: {
                duration: 2000,
                easing: 'easeOutQuart'
            },

            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleFont: { size: 13 },
                    bodyFont: { size: 12 }
                }
            },

            scales: {
                x: {
                    grid: {
                        color: '#e2e8f0'
                    },
                    ticks: {
                        precision: 0
                    }
                },
                y: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}

// =========================
// OUTCOME BREAKDOWN (PIE - MOCK DATA)
// =========================
const outcomeCanvas = document.getElementById('outcomeChart');

if (outcomeCanvas && outcomeLabels.length > 0) {
    new Chart(outcomeCanvas.getContext('2d'), {
        type: 'pie',
        data: {
            labels: outcomeLabels,
            datasets: [{
                data: outcomeCounts,
                backgroundColor: ['#10b981', '#ef4444'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: animationConfig,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 11, weight: '600' }, color: '#334155' }
                }
            }
        }
    });
}

// =========================
// RECENT TREND (LINE - MOCK DATA)
// =========================
const trendCanvas = document.getElementById('trendChart');

if (trendCanvas && trendLabels.length > 0) {
    new Chart(trendCanvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Predictions',
                data: trendValues,
                borderColor: '#0038a8',
                backgroundColor: 'rgba(0,56,168,0.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 3,
                pointBackgroundColor: '#0038a8'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 1800, easing: 'easeOutQuart' },
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#e2e8f0' } },
                x: { grid: { display: false } }
            }
        }
    });
}


// =========================
// PAGE LOAD ANIMATION (GOV STYLE FADE-IN)
// =========================
window.addEventListener("load", () => {
    const sections = document.querySelectorAll('.section-box');

    sections.forEach((el, index) => {
        el.style.opacity = 0;
        el.style.transform = "translateY(20px)";

        setTimeout(() => {
            el.style.transition = "all 0.6s ease";
            el.style.opacity = 1;
            el.style.transform = "translateY(0)";
        }, 200 * index);
    });
});
</script>

</body>
</html>