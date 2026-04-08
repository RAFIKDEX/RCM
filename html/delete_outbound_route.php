<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$JSON="/etc/asterisk/rcm_outbound_routes.json";
$id = trim($_GET["id"] ?? "");
if ($id === "") { header("Location: outbound_routes.php"); exit; }

$data = json_decode(@file_get_contents($JSON), true);
if (!is_array($data)) $data = [];

$new = [];
foreach($data as $r){
  if (($r["id"] ?? "") !== $id) $new[] = $r;
}

@file_put_contents($JSON . ".tmp", json_encode($new, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
@rename($JSON . ".tmp", $JSON);
@chmod($JSON, 0664);

$out1 = shell_exec("sudo /usr/local/bin/rcm_gen_outbound_routes.sh 2>&1") ?? "";
$out2 = shell_exec("sudo /usr/local/bin/rcm_apply_outbound_includes.sh 2>&1") ?? "";
$out3 = shell_exec("sudo /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload") . " 2>&1") ?? "";

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Delete Route</title>
  <link rel="stylesheet" href="assets/rcm.css">
</head>
<body>
<div class="wrap">
  <h1 class="page-title">DELETED</h1>
  <div class="pill">ID: <?=h($id)?></div>

  <h3 style="margin-top:14px;">Generator Output</h3>
  <div class="pill" style="white-space:pre-wrap;"><?=h($out1)?></div>

  <h3 style="margin-top:14px;">Includes Output</h3>
  <div class="pill" style="white-space:pre-wrap;"><?=h($out2)?></div>

  <h3 style="margin-top:14px;">Dialplan Reload</h3>
  <div class="pill" style="white-space:pre-wrap;"><?=h($out3)?></div>

  <div style="margin-top:14px;">
    <a class="btn" href="outbound_routes.php">BACK</a>
  </div>
</div>
</body>
</html>
