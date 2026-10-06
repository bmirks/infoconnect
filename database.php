<?php
date_default_timezone_set('Asia/Manila');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'infoconnect';

function getDbConnection() {
    static $connection = null;

    if ($connection === null) {
        global $dbHost, $dbUser, $dbPass, $dbName;

        $connection = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

        if ($connection->connect_error) {
            die('Database connection failed: ' . $connection->connect_error);
        }

        $connection->set_charset('utf8mb4');
        $connection->query("SET time_zone = '+08:00'");
    }

    return $connection;
}

$databaseConnection = getDbConnection();
