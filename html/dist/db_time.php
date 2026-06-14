<?php
$host = 'localhost';
$dbname = 'rcm_time';
$username = 'rcm_user';
$password = '123456';

$conn_time = new mysqli($host, $username, $password, $dbname);

if ($conn_time->connect_error) {
    die('Database Connection Failed: ' . $conn_time->connect_error);
}

$conn_time->set_charset('utf8mb4');
?>