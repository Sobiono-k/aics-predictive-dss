<?php
/**
 * api_train_proxy.php
 *
 * Bridges the browser (which wants a live Server-Sent-Events stream)
 * to the Render Flask service (which exposes a fire-and-forget
 * /api/train endpoint plus a /api/train/status polling endpoint).
 */

session_start();
if (!isset($_SESSION['role'])) {
    http_response_code(403);
    exit("Unauthorized");
}

// CRITICAL FIX: Close session write lock immediately so the user can navigate 
// or refresh other pages on your site while training runs in the background.
session_write_close();

// ─────────────────────────────────────────────────────────────────
// SSE HEADERS & BUFFER CLEARING
// ─────────────────────────────────────────────────────────────────
set_time_limit(0);                               // Allow long-running polling
ignore_user_abort(true);                        // Let PHP exit cleanly on tab close
while (ob_get_level() > 0) { ob_end_flush(); }   // Clear all output buffers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');                 // Disable buffering on Nginx/Apache

function sse_send(string $message): void {
    echo "data: " . json_encode(['message' => $message]) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

function sse_ping(): void {
    echo ": ping\n\n"; // Keep-alive comment for proxy/browser
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// ─────────────────────────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────────────────────────
$renderBaseUrl     = "https://aics-predictive-dss.onrender.com";
$renderTrainUrl    = $renderBaseUrl . "/api/train";
$renderStatusUrl   = $renderBaseUrl . "/api/train/status";

$maxWaitSeconds    = 280;
$pollIntervalSecs  = 2;

// ─────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────
function http_json(string $url, string $method = 'GET', int $timeout = 20): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || !$response) {
        return null;
    }
    $decoded = json_decode($response, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
}

/**
 * Translate a plain status message from Flask into the bracket-tagged
 * format the frontend's runPrediction() knows how to parse.
 */
function tag_message(string $rawMessage): string {
    $lower = strtolower($rawMessage);

    if (str_contains($lower, 'load') || str_contains($lower, 'data')) {
        return "[DATA]  {$rawMessage}";
    }
    if (str_contains($lower, 'lstm')) {
        return "[LSTM]  {$rawMessage}";
    }
    if (str_contains($lower, 'forest') || str_contains($lower, 'rf')) {
        return "[RF]  {$rawMessage}";
    }
    if (str_contains($lower, 'trend')) {
        return "[LSTM]  {$rawMessage}";
    }
    return "[DATA]  {$rawMessage}";
}

// ─────────────────────────────────────────────────────────────────
// 1. KICK OFF TRAINING
// ─────────────────────────────────────────────────────────────────
sse_send("[DATA]  Connecting to training worker…");

$startResult = http_json($renderTrainUrl, 'POST', 20);

if ($startResult === null) {
    sse_send("[ERROR] Could not reach the training service. It may be waking up — try again shortly.");
    exit();
}

if (isset($startResult['status']) && $startResult['status'] === 'already_running') {
    sse_send("[DATA]  Training already in progress on the server — attaching to it…");
} else {
    sse_send("[DATA]  Training started on the server.");
}

// ─────────────────────────────────────────────────────────────────
// 2. POLL STATUS UNTIL DONE / ERROR / TIMEOUT
// ─────────────────────────────────────────────────────────────────
$elapsed   = 0;
$lastState = null;
$lastMsg   = null;

while ($elapsed < $maxWaitSeconds) {
    $status = http_json($renderStatusUrl, 'GET', 15);

    if ($status === null) {
        sse_send("[DATA]  (status check failed, retrying…)");
    } else {
        $state   = $status['state']   ?? 'unknown';
        $message = $status['message'] ?? '';

        // Send message on state change; send ping if unchanged to maintain connection
        if ($state !== $lastState || $message !== $lastMsg) {
            if ($state === 'running') {
                sse_send(tag_message($message ?: 'Training in progress…'));
            } elseif ($state === 'error') {
                sse_send("[ERROR] " . ($message ?: 'Unknown training error.'));
                exit();
            } elseif ($state === 'done') {
                sse_send("[RF]  Finalizing outputs…");
                sse_send("[DONE] Training complete.");
                exit();
            } elseif ($state === 'idle') {
                sse_send("[DATA]  Waiting for training to begin…");
            }
            $lastState = $state;
            $lastMsg   = $message;
        } else {
            sse_ping(); // Prevent network socket timeout
        }
    }

    // Terminate script if user closes or navigates away from tab
    if (connection_aborted()) {
        exit();
    }

    sleep($pollIntervalSecs);
    $elapsed += $pollIntervalSecs;
}

// ─────────────────────────────────────────────────────────────────
// 3. TIMEOUT FALLBACK
// ─────────────────────────────────────────────────────────────────
sse_send("[ERROR] Training is taking longer than expected. It may still finish in the background — check back in a minute and reload the page.");