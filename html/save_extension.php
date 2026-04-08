<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

function clean($s){ return trim((string)$s); }

$ext            = clean($_POST["ext"] ?? "");
$secret         = clean($_POST["secret"] ?? "");
$calleridName   = clean($_POST["callerid_name"] ?? "");
if ($calleridName === "") {
  $calleridName = $ext;
}
$calleridNumber = clean($_POST["callerid_number"] ?? "");
$context        = clean($_POST["context"] ?? "internal");
$maxContacts    = clean($_POST["max_contacts"] ?? "10");
$allow          = clean($_POST["allow"] ?? "alaw,ulaw");
$recordMode     = clean($_POST["record_mode"] ?? "noo");
$vmMode         = clean($_POST["vm_mode"] ?? "Off");

$errors = [];
if (!preg_match('/^\d{2,6}$/', $ext)) $errors[] = "Invalid extension";
if ($secret === "") $errors[] = "Secret is required";

if (!preg_match('/^[0-9+*#]{2,20}$/', $calleridNumber)) $errors[] = "Invalid Caller ID number";
if ($context !== "internal") $errors[] = "Context must be internal";
if (!preg_match('/^\d+$/', $maxContacts)) $errors[] = "Invalid max_contacts";
if (!preg_match('/^[a-zA-Z0-9,]+$/', $allow)) $errors[] = "Invalid allow codecs";
if (!in_array($recordMode, ["in","out","noo","all"], true)) $errors[] = "Invalid record mode";
if (!in_array($vmMode, ["On","Off"], true)) $errors[] = "Invalid VM mode";

$out = "";
$ok = false;

if (!$errors) {
  $cmd = "sudo /usr/local/bin/rcm_add_ext.sh "
      . escapeshellarg($ext) . " "
      . escapeshellarg($secret) . " "
      . escapeshellarg($context) . " "
      . escapeshellarg($maxContacts) . " "
      . escapeshellarg($allow) . " "
      . escapeshellarg($recordMode) . " "
      . escapeshellarg($vmMode) . " "
      . escapeshellarg($calleridName) . " "
      . escapeshellarg($calleridNumber) . " 2>&1";

  $out = shell_exec($cmd) ?? "";
  $ok = (strpos($out, "OK:") === 0);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Save Extension</title>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <style>
    .wrap{ width:min(760px, 94vw); }
    .box{
      background:rgba(255,255,255,0.10);
      border:1px solid rgba(255,255,255,0.18);
      border-radius:18px;
      padding:14px;
      margin-top:14px;
      white-space:pre-wrap;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      font-size:13px;
      opacity:.95;
    }
    .actions{
      display:flex;
      gap:12px;
      justify-content:center;
      margin-top:18px;
      flex-wrap:wrap;
    }
    .ok{color:#b8ffcc;font-weight:900;text-align:center}
    .bad{color:#ffd0d0;font-weight:900;text-align:center}
    ul{margin:10px 0 0 18px}
    .summary{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:12px;
      margin-top:14px;
    }
    .summary .pill{display:block;text-align:center}
    @media (max-width: 640px){ .summary{ grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="inner-box" style="margin-top:0;">
      <h1 class="page-title">RESULT</h1>

      <?php if ($errors): ?>
        <div class="bad">FAILED</div>
        <ul>
          <?php foreach($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <?php if ($ok): ?>
          <div class="ok">SUCCESS</div>
        <?php else: ?>
          <div class="bad">FAILED</div>
        <?php endif; ?>

        <div class="summary">
          <div class="pill">Extension: <?php echo htmlspecialchars($ext); ?></div>
          <div class="pill">Caller ID: <?php echo htmlspecialchars($calleridName . ' <' . $calleridNumber . '>'); ?></div>
        </div>

        <div class="box"><?php echo htmlspecialchars($out ?: "No output"); ?></div>
      <?php endif; ?>

      <div class="actions">
        <a class="btn" href="/add_extension.php">ADD ANOTHER</a>
        <a class="btn" href="/extensions.php">EXTENSIONS</a>
        <a class="btn" href="/dashboard.php">DASHBOARD</a>
      </div>
    </div>
  </div>
</body>
</html>