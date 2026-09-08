<?php
// Retrieve environment variables with production Aiven defaults
$host    = getenv('DB_HOST') ?: 'mysql-36a3dde9-aics-predictive-dss.d.aivencloud.com';
$user    = getenv('DB_USER') ?: 'avnadmin';
$pass    = getenv('DB_PASS') ?: ''; // Replace with new password
$DB_NAME = getenv('DB_NAME') ?: 'defaultdb';                       // Must be defaultdb
$port    = getenv('DB_PORT') ?: 19547;

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