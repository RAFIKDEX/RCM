<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$JSON="/etc/asterisk/rcm_outbound_routes.json";
$id = trim($_GET["id"] ?? "");
if ($id === "") { header("Location: outbound_routes.php"); exit; }

$data = json_decode(@file_get_contents($JSON), true);
if (!is_array($data)) $data = [];

$route = null;
foreach($data as $r){
  if (($r["id"] ?? "") === $id) { $route = $r; break; }
}
if (!$route) { header("Location: outbound_routes.php"); exit; }

// reuse add page via simple form (no dual list for edit to keep it short)
// edit as text areas (whitelist + trunks one per line)
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Outbound Route</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/rcm.css">
  <style>
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .box{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px;}
    textarea{width:100%;min-height:140px;}
    input,select{width:100%;}
  </style>
</head>
<body>
<div class="wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <h1 class="page-title">EDIT OUTBOUND ROUTE</h1>
    <a class="btn" href="outbound_routes.php">BACK</a>
  </div>

  <form method="post" action="save_outbound_route_edit.php">
    <input type="hidden" name="id" value="<?=h($id)?>">
    <div class="grid" style="margin-top:14px;">
      <div class="box">
        <label><b>Name *</b></label>
        <input name="name" value="<?=h($route["name"] ?? "")?>" required>

        <div style="height:10px"></div>

        <label><b>Context *</b></label>
        <input name="context" value="<?=h($route["context"] ?? "")?>" required>

        <div style="height:10px"></div>

        <label><b>Patterns (one per line)</b></label>
        <textarea name="patterns" required><?=h(implode("\n", (array)($route["patterns"] ?? [])))?></textarea>
      </div>

      <div class="box">
        <label><b>Enabled</b></label>
        <select name="enabled">
          <option value="yes" <?=($route["enabled"]??"yes")==="yes"?"selected":""?>>yes</option>
          <option value="no"  <?=($route["enabled"]??"yes")==="no" ?"selected":""?>>no</option>
        </select>

        <div style="height:10px"></div>

        <label><b>Record</b></label>
        <select name="record">
          <option value="no"  <?=($route["record"]??"no")==="no" ?"selected":""?>>no</option>
          <option value="yes" <?=($route["record"]??"no")==="yes"?"selected":""?>>yes</option>
        </select>

        <div style="height:10px"></div>

        <label><b>PIN</b></label>
        <input name="pin" value="<?=h($route["pin"] ?? "")?>">

        <div style="height:10px"></div>

        <div class="grid" style="grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label><b>Timeout</b></label>
            <input name="timeout" type="number" value="<?=h($route["timeout"] ?? 60)?>">
          </div>
          <div>
            <label><b>Time Limit (sec)</b></label>
            <input name="time_limit_sec" type="number" value="<?=h($route["time_limit_sec"] ?? 0)?>">
          </div>
        </div>

        <div style="height:10px"></div>

        <div class="grid" style="grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label><b>Strip</b></label>
            <input name="strip" type="number" value="<?=h($route["strip"] ?? 0)?>">
          </div>
          <div>
            <label><b>Prepend</b></label>
            <input name="prepend" value="<?=h($route["prepend"] ?? "")?>">
          </div>
        </div>

        <div style="height:10px"></div>

        <label><b>Whitelist (one ext per line)</b></label>
        <textarea name="whitelist"><?=h(implode("\n", (array)($route["whitelist"] ?? [])))?></textarea>

        <div style="height:10px"></div>

        <label><b>Trunks Order (one per line)</b></label>
        <textarea name="trunks" required><?=h(implode("\n", (array)($route["trunks"] ?? [])))?></textarea>
      </div>
    </div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn" type="submit">SAVE</button>
      <a class="btn" href="outbound_routes.php">CANCEL</a>
    </div>
  </form>
</div>
</body>
</html>
