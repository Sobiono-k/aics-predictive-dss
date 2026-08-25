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

        .signature-section { display: none; margin-top: 50px; justify-content: space-between; padding: 0 50px; }
        .sig-box { text-align: center; border-top: 1px solid #000; width: 200px; padding-top: 10px; font-size: 12px; font-weight: 600; }

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

// =========================
// INTERACTIVE SECTION FILTER
// =========================
function showSection(type) {
    const section = document.getElementById('budgetSection');
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