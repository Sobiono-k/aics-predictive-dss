<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'aics_dss';
$port = getenv('DB_PORT') ?: 3306;

$conn = mysqli_init();

// Set timeout to prevent long hangs
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

// Enable SSL flag for Aiven
mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);

if (!@mysqli_real_connect($conn, $host, $user, $pass, $db, (int)$port, NULL, MYSQL_CLIENT_SSL)) {
    die("Database Connection Failed: " . mysqli_connect_error());
}
?>