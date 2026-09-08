<?php
$host    = getenv('DB_HOST') ?: 'localhost';
$user    = getenv('DB_USER') ?: 'root';
$pass    = getenv('DB_PASS') ?: '';
$DB_NAME = getenv('DB_NAME') ?: 'aics_dss';
$port    = getenv('DB_PORT') ?: 3306;

$conn = mysqli_init();

if (!$conn) {
    die("mysqli_init failed");
}

// Set connection timeout (10 seconds)
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

// Configure SSL for cloud databases (Aiven)
if ($host !== 'localhost' && $host !== '127.0.0.1') {
    mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
    mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $flags = MYSQLI_CLIENT_SSL;
} else {
    $flags = 0;
}

// Establish connection
if (!@mysqli_real_connect($conn, $host, $user, $pass, $DB_NAME, (int)$port, NULL, $flags)) {
    die("Database Connection Failed: " . mysqli_connect_error());
}
?>