<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

function clean($s){ return trim((string)$s); }

$ext = clean($_GET["ext"] ?? $_POST["ext"] ?? "");
if (!preg_match('/^\d{2,6}$/', $ext)) {
  http_response_code(400);
  echo "Bad request: invalid extension";
  exit;
}

$EP_FILE   = "/etc/asterisk/pjsip.gui.endpoint.conf";
$AUTH_FILE = "/etc/asterisk/pjsip.gui.auth.conf";
$AOR_FILE  = "/etc/asterisk/pjsip.gui.aor.conf";
$DP_FILE   = "/etc/asterisk/extensions_gui.conf";

function read_ini_block($file, $section) {
  if (!file_exists($file)) return null;
  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $in = false;
  $data = [];

  foreach ($lines as $ln) {
    $t = trim($ln);
    if ($t === "") continue;

    if (preg_match('/^\[(.+)\]$/', $t, $m)) {
      $in = ($m[1] === $section);
      continue;
    }

    if ($in) {
      if ($t[0] === ';') continue;
      if (strpos($t, '=') !== false) {
        [$k,$v] = array_map('trim', explode('=', $t, 2));
        $data[$k] = $v;
      }
    }
  }

  return $data ?: null;
}

function read_dp_globals($file, $ext) {
  $rec = "noo";
  $vm = "Off";

  if (!file_exists($file)) return [$rec, $vm];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  foreach ($lines as $ln) {
    $t = trim($ln);
    if (preg_match('/^RECORD_'.$ext.'=(.+)$/', $t, $m)) $rec = trim($m[1]);
    if (preg_match('/^VM_'.$ext.'=(.+)$/', $t, $m)) $vm = trim($m[1]);
  }

  return [$rec, $vm];
}

function parse_callerid_parts(string $raw, string $fallbackExt): array {
  $raw = trim($raw);

  if ($raw !== '' && preg_match('/^"?(.*?)"?\s*<\s*([^>]+)\s*>$/', $raw, $m)) {
    return [trim($m[1]), trim($m[2])];
  }

  if ($raw !== '') {
    return [$raw, $fallbackExt];
  }

  return ['', $fallbackExt];
}

$ep   = read_ini_block($EP_FILE, $ext);
$auth = read_ini_block($AUTH_FILE, $ext);
$aor  = read_ini_block($AOR_FILE, $ext);

if (!$ep || !$auth || !$aor) {
  http_response_code(404);
  echo "Extension [$ext] not found in GUI files.";
  exit;
}

$allow = $ep["allow"] ?? "alaw,ulaw";
$context = $ep["context"] ?? "internal";
$maxContacts = $aor["max_contacts"] ?? "10";
$secret = $auth["password"] ?? "";

[$calleridName, $calleridNumber] = parse_callerid_parts((string)($ep['callerid'] ?? ''), $ext);
if ($calleridName === '') $calleridName = $ext;

[$recordMode, $vmMode] = read_dp_globals($DP_FILE, $ext);

$errors = [];
$out = "";
$ok = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $secret = clean($_POST["secret"] ?? "");
  $calleridName = clean($_POST["callerid_name"] ?? "");
  if ($calleridName === "") {
    $calleridName = $ext;
  }
  $calleridNumber = clean($_POST["callerid_number"] ?? "");
  $allow = clean($_POST["allow"] ?? "alaw,ulaw");
  $maxContacts = clean($_POST["max_contacts"] ?? "10");
  $recordMode = clean($_POST["record_mode"] ?? "noo");
  $vmMode = clean($_POST["vm_mode"] ?? "Off");

  if ($secret === "") $errors[] = "Secret is required";
  if (!preg_match('/^[0-9+*#]{2,20}$/', $calleridNumber)) $errors[] = "Invalid Caller ID number";
  if (!preg_match('/^\d+$/', $maxContacts)) $errors[] = "Invalid max_contacts";
  if (!preg_match('/^[a-zA-Z0-9,]+$/', $allow)) $errors[] = "Invalid allow codecs";
  if (!in_array($recordMode, ["in","out","noo","all"], true)) $errors[] = "Invalid record mode";
  if (!in_array($vmMode, ["On","Off"], true)) $errors[] = "Invalid VM mode";

  $context = "internal";

  if (!$errors) {
    $cmd = "sudo /usr/local/bin/rcm_edit_ext.sh "
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
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Extension</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">

  <style>
    .wrap{ width:min(760px, 95vw); }

    .page-subtitle{
      margin:0 0 22px 0;
      text-align:center;
      font-family:'Orbitron', sans-serif;
      font-weight:900;
      letter-spacing:6px;
      text-transform:uppercase;
      font-size:34px;
      text-shadow: 0 0 18px rgba(255,255,255,.35);
    }

    .panel-box{
      margin-top:0;
    }

    form{
      width:100%;
    }

    .row{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:16px;
    }

    .field{
      margin-bottom:16px;
    }

    label{
      display:block;
      margin:0 0 8px 0;
      font-weight:800;
      font-size:15px;
      color:#fff;
    }

    input,
    select{
      width:100%;
      box-sizing:border-box;
      min-height:46px;
      padding:10px 14px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.28);
      background:rgba(255,255,255,.10);
      color:#fff;
      font-size:16px;
      outline:none;
    }

    input::placeholder{
      color:rgba(255,255,255,.70);
    }

    select{
      cursor:pointer;
    }

    input:disabled{
      opacity:.85;
      cursor:not-allowed;
    }

    .actions{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:16px;
      margin-top:22px;
    }

    .box{
      background: rgba(255,255,255,0.10);
      border: 1px solid rgba(255,255,255,0.18);
      border-radius: 18px;
      padding: 14px;
      margin-top: 14px;
      white-space: pre-wrap;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 13px;
      opacity: .95;
    }

    ul{ margin:10px 0 0 18px; }
    .ok{ color:#b8ffcc; font-weight:900; text-align:center; }
    .bad{ color:#ffd0d0; font-weight:900; text-align:center; }

    .hint{
      margin-top:18px;
      text-align:center;
      opacity:.92;
      font-weight:800;
      letter-spacing:1px;
      font-size:12px;
      line-height:1.8;
    }

    @media (max-width: 700px){
      .row,
      .actions{
        grid-template-columns: 1fr;
      }

      .wrap{
        width:min(94vw, 760px);
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="inner-box" style="margin-top:0;">
      <h1 class="page-subtitle">EDIT EXTENSION</h1>

      <?php if ($errors): ?>
        <div class="bad">FAILED</div>
        <ul>
          <?php foreach($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php elseif ($_SERVER["REQUEST_METHOD"] === "POST"): ?>
        <?php if ($ok): ?>
          <div class="ok">SUCCESS</div>
        <?php else: ?>
          <div class="bad">FAILED</div>
        <?php endif; ?>
        <div class="box"><?= htmlspecialchars($out ?: "No output") ?></div>
      <?php endif; ?>

      <div class="panel-box">
        <form method="post" autocomplete="off">
          <input type="hidden" name="ext" value="<?= htmlspecialchars($ext) ?>">

          <div class="row">
            <div class="field">
              <label>Extension</label>
              <input value="<?= htmlspecialchars($ext) ?>" disabled>
            </div>

            <div class="field">
              <label>Password</label>
              <input name="secret" value="<?= htmlspecialchars($secret) ?>" required>
            </div>
          </div>

          <div class="row">
            <div class="field">
              <label>Caller ID Number</label>
              <input
                name="callerid_number"
                value="<?= htmlspecialchars($calleridNumber) ?>"
                required
              >
            </div>

            <div class="field">
              <label>Caller ID Name</label>
              <input
                name="callerid_name"
                value="<?= htmlspecialchars($calleridName === $ext ? '' : $calleridName) ?>"
                placeholder="leave empty to use extension number"
              >
            </div>
          </div>

          <div class="row">
            <div class="field">
              <label>Context</label>
              <input value="internal" disabled>
            </div>

            <div class="field">
              <label>Max Contacts</label>
              <input name="max_contacts" value="<?= htmlspecialchars($maxContacts) ?>" required>
            </div>
          </div>

          <div class="field">
            <label>Allow Codecs</label>
            <input name="allow" value="<?= htmlspecialchars($allow) ?>" required>
          </div>

          <div class="row">
            <div class="field">
              <label>Recording</label>
              <select name="record_mode">
                <option value="noo" <?= $recordMode==="noo" ? "selected" : "" ?>>noo</option>
                <option value="in"  <?= $recordMode==="in"  ? "selected" : "" ?>>in</option>
                <option value="out" <?= $recordMode==="out" ? "selected" : "" ?>>out</option>
                <option value="all" <?= $recordMode==="all" ? "selected" : "" ?>>all</option>
              </select>
            </div>

            <div class="field">
              <label>Voicemail</label>
              <select name="vm_mode">
                <option value="Off" <?= $vmMode==="Off" ? "selected" : "" ?>>Off</option>
                <option value="On"  <?= $vmMode==="On"  ? "selected" : "" ?>>On</option>
              </select>
            </div>
          </div>

          <div class="actions">
            <a class="btn" href="/extensions.php">CANCEL</a>
            <button class="btn" type="submit">SAVE</button>
          </div>

          <div class="hint">
            * Caller ID Name is optional. If left empty, system will use the extension number.<br>
            * Edit keeps the extension number fixed and updates the endpoint settings.
          </div>
        </form>
      </div>

    </div>
  </div>
</body>
</html>