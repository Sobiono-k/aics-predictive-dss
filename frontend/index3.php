<?php
// ==========================================
// 1. Pathing & Process Configurations
// ==========================================

$pythonPath = "C:\\Users\\A\\AppData\\Local\\Programs\\Python\\Python311\\python.exe"; 
$env = [
    'PATH' => 'C:\\Users\\A\\AppData\\Local\\Programs\\Python\\Python311;C:\\Users\\A\\AppData\\Local\\Programs\\Python\\Python311\\Scripts;C:\\Windows\\system32;C:\\Windows',
    'SystemRoot' => 'C:\\Windows',
    'USERPROFILE' => 'C:\\Windows\\Temp', 
    'HOME' => 'C:\\Windows\\Temp'         
];
$descriptorspec = [
    0 => ["pipe", "r"], 
    1 => ["pipe", "w"], 
    2 => ["pipe", "w"]  
];

// --- EXECUTE PIPELINE A: LSTM MODEL ---
$lstmScriptPath = dirname(__DIR__) . "/backend/lstm_model.py"; 
$lstmCmd = '"' . $pythonPath . '" "' . $lstmScriptPath . '"';
$lstmProcess = proc_open($lstmCmd, $descriptorspec, $lstmPipes, __DIR__, $env, ['bypass_shell' => true]);

$lstmJsonData = '';
$lstmErrorData = '';

if (is_resource($lstmProcess)) {
    $lstmJsonData = stream_get_contents($lstmPipes[1]);
    $lstmErrorData = stream_get_contents($lstmPipes[2]);
    fclose($lstmPipes[0]); fclose($lstmPipes[1]); fclose($lstmPipes[2]);
    proc_close($lstmProcess);
}

// --- EXECUTE PIPELINE B: RANDOM FOREST MODEL ---
$rfScriptPath = dirname(__DIR__) . "/backend/random_forest.py"; 
$rfCmd = '"' . $pythonPath . '" "' . $rfScriptPath . '"';
$rfProcess = proc_open($rfCmd, $descriptorspec, $rfPipes, __DIR__, $env, ['bypass_shell' => true]);

$rfJsonData = '';
$rfErrorData = '';

if (is_resource($rfProcess)) {
    $rfJsonData = stream_get_contents($rfPipes[1]);
    $rfErrorData = stream_get_contents($rfPipes[2]);
    fclose($rfPipes[0]); fclose($rfPipes[1]); fclose($rfPipes[2]);
    proc_close($rfProcess);
}

// ==========================================
// 2. Output Data Sanitization
// ==========================================
function cleanJsonOutput($dataString) {
    if (!empty($dataString) && strpos($dataString, '{') !== 0) {
        $startPos = strpos($dataString, '{');
        if ($startPos !== false) {
            return substr($dataString, $startPos);
        }
    }
    return $dataString;
}

$lstmJsonData = cleanJsonOutput($lstmJsonData);
$rfJsonData = cleanJsonOutput($rfJsonData);

$lstmResults = json_decode($lstmJsonData, true);
$rfResults = json_decode($rfJsonData, true);

// Fallback configuration if Random Forest array execution path fails
if ($rfResults === null) {
    $rfResults = [
        "weekly" => ["predictions" => [], "hotspots" => []],
        "monthly" => ["predictions" => [], "hotspots" => []],
        "yearly" => ["predictions" => [], "hotspots" => []]
    ];
}

// Core termination check only on essential structural dependency (LSTM Pattern analysis)
if ($lstmResults === null || empty($lstmResults)) {
    echo "<h3 style='color:red; font-family:sans-serif;'>Pattern Analysis Model Pipeline Empty or Terminated</h3>";
    echo "<strong>LSTM Logs:</strong><pre style='background:#222; color:orange; padding:10px;'>" . htmlspecialchars($lstmErrorData) . "</pre>";
    echo "<strong>Random Forest Logs:</strong><pre style='background:#222; color:cyan; padding:10px;'>" . htmlspecialchars($rfErrorData) . "</pre>";
    die();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Medical Pattern Intelligence Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-900 text-gray-100 p-8">

    <div class="max-w-7xl mx-auto">
        <header class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center border-b border-gray-800 pb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-indigo-400">Medical Case Pattern Intelligence</h1>
                <p class="text-gray-400 mt-2">Hybrid LSTM categorization tracking rolling 7-day volume anomalies against Random Forest predictive timelines.</p>
            </div>
            <div class="bg-gray-800 px-4 py-2 rounded-lg border border-gray-700 text-xs">
                <span class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    ML Processing Engines Online
                </span>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            
            <div class="lg:col-span-3 space-y-8">
                <div>
                    <h2 class="text-sm font-semibold tracking-wider text-gray-400 uppercase mb-4">LSTM Rolling 7-Day Anomaly Detections</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <?php foreach ($lstmResults as $cause => $info): 
                            // Defensively extract data with default fallbacks
                            $status = $info['status'] ?? 'Unknown';
                            $color  = $info['color']  ?? '#4b5563'; // Neutral gray fallback
                            $count  = $info['count']  ?? 0;
                            $growth = $info['growth'] ?? '0%';
                        ?>
                            <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 shadow-sm hover:border-gray-600 transition-all">
                                <div class="flex justify-between items-start">
                                    <h4 class="text-sm font-bold text-gray-200 truncate max-w-[70%]" title="<?php echo htmlspecialchars($cause); ?>">
                                        <?php echo htmlspecialchars($cause); ?>
                                    </h4>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold text-white uppercase tracking-wide" style="background-color: <?php echo $color; ?>;">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </div>
                                <div class="mt-4 flex items-baseline gap-2">
                                    <span class="text-3xl font-extrabold text-white tracking-tight"><?php echo htmlspecialchars($count); ?></span>
                                    <span class="text-xs font-semibold text-gray-500">Records</span>
                                </div>
                                <p class="text-xs mt-2 font-medium" style="color: <?php echo $color; ?>;">
                                    Growth Rate: <?php echo htmlspecialchars($growth); ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 shadow-md">
                        <h3 class="text-sm font-semibold text-gray-300 mb-4">Top Classified Causes Distribution (LSTM)</h3>
                        <div class="relative h-[300px] w-full">
                            <canvas id="patternChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 shadow-md">
                        <h3 class="text-sm font-semibold text-gray-300 mb-4">Weekly Predicted Target Thresholds (Random Forest)</h3>
                        <div class="relative h-[300px] w-full">
                            <canvas id="rfChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-1 space-y-6">
                <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 shadow-md h-full">
                    <div class="border-b border-gray-700 pb-3 mb-4">
                        <h3 class="text-md font-bold text-indigo-400">Random Forest Forecast</h3>
                        <p class="text-xs text-gray-400 mt-1">Predictive matrix targeting upcoming clinical volume vectors.</p>
                    </div>

                    <div class="space-y-4">
                        <h4 class="text-xs font-bold text-gray-400 tracking-wider uppercase">Active Threat Profiles (Weekly)</h4>
                        
                        <?php if (empty($rfResults['weekly']['hotspots']) && empty($rfResults['weekly']['predictions'])): ?>
                            <div class="p-4 rounded-lg bg-gray-900/50 border border-gray-700/50 text-center">
                                <p class="text-xs text-gray-500">No Random Forest prediction data available for this timeline selection.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach (($rfResults['weekly']['predictions'] ?? []) as $predict): ?>
                                <div class="p-3.5 rounded-lg bg-gray-900 border-l-4 border-indigo-500 border-y border-r border-gray-700">
                                    <p class="text-xs font-bold text-gray-200 truncate"><?php echo htmlspecialchars($predict['assistance_type']); ?></p>
                                    <div class="flex justify-between items-center mt-2 pt-2 border-t border-gray-800/60 text-[11px]">
                                        <span class="text-gray-400">Projected: <b class="text-white"><?php echo htmlspecialchars($predict['predicted_count']); ?></b></span>
                                        <span class="text-emerald-400 font-medium">Conf: <?php echo htmlspecialchars(round($predict['confidence'] * 100, 1)); ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php foreach (($rfResults['weekly']['hotspots'] ?? []) as $hotspot): ?>
                                <div class="p-3.5 rounded-lg bg-red-950/20 border-l-4 border-red-500 border-y border-r border-gray-700">
                                    <div class="flex justify-between items-center">
                                        <p class="text-xs font-bold text-red-400 truncate max-w-[70%]"><?php echo htmlspecialchars($hotspot['cause_name']); ?></p>
                                        <span class="text-[10px] text-red-400 font-mono font-bold">+<?php echo htmlspecialchars($hotspot['velocity_growth']); ?>%</span>
                                    </div>
                                    <p class="text-[11px] text-gray-400 mt-1.5 leading-relaxed"><?php echo htmlspecialchars($hotspot['contributing_factor']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Data Parser Core 
        const lstmPhpData = <?php echo json_encode($lstmResults); ?>;
        const rfPhpData = <?php echo json_encode($rfResults); ?>;
        
        // --- CHART 1: LSTM BAR DISTRIBUTION ---
        const lstmLabels = Object.keys(lstmPhpData);
        const lstmCounts = lstmLabels.map(key => lstmPhpData[key].count);
        const lstmColors = lstmLabels.map(key => lstmPhpData[key].color);

        const ctxLstm = document.getElementById('patternChart').getContext('2d');
        new Chart(ctxLstm, {
            type: 'bar',
            data: {
                labels: lstmLabels,
                datasets: [{
                    label: 'Total Incidents Logged',
                    data: lstmCounts,
                    backgroundColor: lstmColors,
                    borderColor: 'rgba(255,255,255,0.05)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 10 } } },
                    y: { grid: { color: '#374151' }, ticks: { color: '#9ca3af' } }
                },
                plugins: { legend: { display: false } }
            }
        });

        // --- CHART 2: RANDOM FOREST PREDICTIVE TARGETS ---
        const rfPredictions = rfPhpData.weekly?.predictions || [];
        const rfLabels = rfPredictions.map(item => item.assistance_type);
        const rfCounts = rfPredictions.map(item => item.predicted_count);

        const ctxRf = document.getElementById('rfChart').getContext('2d');
        new Chart(ctxRf, {
            type: 'line',
            data: {
                labels: rfLabels.length ? rfLabels : ['No Data'],
                datasets: [{
                    label: 'Predicted Next-Week Counts',
                    data: rfCounts.length ? rfCounts : [0],
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#818cf8'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 10 } } },
                    y: { grid: { color: '#374151' }, ticks: { color: '#9ca3af' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    </script>
</body>
</html>