<?php
// api_train_proxy.php
// Streams the Flask /api/train SSE endpoint through to the browser.
// Must live in the same directory as forecast.php (or wherever EventSource('api_train_proxy.php') is called from).
ini_set('zlib.output_compression', '0');
ini_set('output_buffering', 'off');
ini_set('implicit_flush', '1');

session_start();
if (!isset($_SESSION['role'])) {
    http_response_code(401);
    exit();
}

set_time_limit(180); // generous ceiling in case Render is cold-starting

// ── Critical: disable every layer of output buffering ───────────────
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // stops Nginx from buffering the stream if it's in front of this

$trainUrl = "https://aics-predictive-dss.onrender.com/api/train";

$ch = curl_init($trainUrl);

curl_setopt_array($ch, [
    CURLOPT_HTTPGET        => true,           // Flask route accepts GET
    CURLOPT_TIMEOUT        => 170,            // must exceed Render cold-start + full training time
    CURLOPT_CONNECTTIMEOUT => 60,             // Render free tier can take a while just to wake up
    CURLOPT_HTTPHEADER     => ['Accept: text/event-stream'],
    // Relay each chunk to the browser the instant it arrives from Flask
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
        echo $chunk;
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
        return strlen($chunk);
    },
]);

$success = curl_exec($ch);
$err     = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// If the upstream call failed outright (e.g. Render never responded, DNS error, etc.)
// send a well-formed SSE error event so the frontend's onmessage handler can show it
// instead of just dying with a generic onerror.
if ($success === false || $httpCode === 0) {
    echo "data: " . json_encode([
        'message' => "[ERROR] Could not reach training service: $err"
    ]) . "\n\n";
    flush();
}