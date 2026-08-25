<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'aics_dss';
$port = getenv('DB_PORT') ?: 3306;

$conn = mysqli_init();

if (!$conn) {
    die("mysqli_init failed");
}

// Set connection timeout (10 seconds)
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

// Configure SSL for cloud databases (like Aiven)
// When host is not localhost, enable SSL
if ($host !== 'localhost' && $host !== '127.0.0.1') {
    mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
    $flags = MYSQLI_CLIENT_SSL;
} else {
    $flags = 0;
}

// Establish connection
if (!@mysqli_real_connect($conn, $host, $user, $pass, $db, (int)$port, NULL, $flags)) {
    die("Database Connection Failed: " . mysqli_connect_error());
}
?>