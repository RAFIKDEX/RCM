<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_time.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method.');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    die('Invalid Office Time ID.');
}

$stmt = $conn_time->prepare("DELETE FROM office_times WHERE id = ?");
if (!$stmt) {
    die('Prepare failed: ' . $conn_time->error);
}

$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
    die('Delete failed: ' . $stmt->error);
}

$stmt->close();

header("Location: /time_condition.php?tab=office&deleted=1");
exit;
?>
