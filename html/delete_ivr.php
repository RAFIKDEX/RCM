<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: /ivr.php"); exit;
}

$n = trim($_POST["n"] ?? "");
if (!preg_match('/^\d{2,6}$/', $n)) {
  header("Location: /ivr.php"); exit;
}

$cmd = "sudo /usr/local/bin/rcm_del_ivr.sh " . escapeshellarg($n) . " 2>&1";
$out = shell_exec($cmd) ?? "";

header("Location: /ivr.php");
exit;
