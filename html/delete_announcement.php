<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: /announcements.php"); exit;
}

$n = trim($_POST["n"] ?? "");
if (!preg_match('/^\d{2,6}$/', $n)) {
  header("Location: /announcements.php"); exit;
}

$cmd = "sudo /usr/local/bin/rcm_del_ann.sh " . escapeshellarg($n) . " 2>&1";
shell_exec($cmd);

header("Location: /announcements.php");
exit;
