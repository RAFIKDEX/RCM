<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db_time.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    die('Invalid ID');
}

// الحذف (هيحذف entries تلقائي بسبب ON DELETE CASCADE)
$stmt = $conn_time->prepare("DELETE FROM holidays WHERE id = ?");
if (!$stmt) {
    die($conn_time->error);
}

$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
    die($stmt->error);
}

$stmt->close();

// رجوع لصفحة الهوليداي
header("Location: /time_condition.php?tab=holiday&deleted=1");
exit;
