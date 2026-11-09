
<?php
require_once __DIR__ . '/../db.php';

function getActiveQrToken($conn) {
    $result = $conn->query(
        "SELECT active_token FROM application_qr_settings WHERE id = 1 LIMIT 1"
    );

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['active_token'];
    }

    $token = bin2hex(random_bytes(32));
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO application_qr_settings (id, active_token) VALUES (1, ?)"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->close();

    $result = $conn->query(
        "SELECT active_token FROM application_qr_settings WHERE id = 1 LIMIT 1"
    );

    return $result->fetch_assoc()['active_token'];
}

function isValidQrToken($conn, $submittedToken) {
    if (!is_string($submittedToken) || $submittedToken === '') {
        return false;
    }

    $activeToken = getActiveQrToken($conn);

    return hash_equals($activeToken, $submittedToken);
}

function resetActiveQrToken($conn) {
    $newToken = bin2hex(random_bytes(32));

    $stmt = $conn->prepare(
        "INSERT INTO application_qr_settings (id, active_token)
         VALUES (1, ?)
         ON DUPLICATE KEY UPDATE active_token = VALUES(active_token)"
    );

    $stmt->bind_param("s", $newToken);
    $success = $stmt->execute();
    $stmt->close();

    return $success ? $newToken : false;
}
