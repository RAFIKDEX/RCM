<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$CFG_FILE = "/etc/asterisk/extensions_gui.conf";

function get_context_lines($file, $contextName){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $result = [];
  $inContext = false;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[' . preg_quote($contextName, '/') . '\]$/i', $t)) {
      $inContext = true;
      continue;
    }

    if ($inContext && preg_match('/^\[[^]]+\]$/', $t)) {
      break;
    }

    if ($inContext) {
      $result[] = $line;
    }
  }

  return $result;
}

function get_all_context_names($file){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $names = [];

  foreach ($lines as $line) {
    $t = trim($line);
    if (preg_match('/^\[([^\]]+)\]$/', $t, $m)) {
      $names[] = trim($m[1]);
    }
  }

  $names = array_values(array_unique($names));
  sort($names, SORT_NATURAL | SORT_FLAG_CASE);
  return $names;
}

function parse_values_from_context($file, $contextName, $mode = "same"){
  $lines = get_context_lines($file, $contextName);
  $values = [];

  foreach ($lines as $line) {
    $t = trim($line);
    if ($t === "" || str_starts_with($t, ";")) continue;

    if ($mode === "same") {
      if (preg_match('/^exten\s*=>\s*(\d+)\s*,/i', $t, $m)) {
        $values[] = $m[1];
      }
    } else {
      if (preg_match('/^exten\s*=>\s*(\d+)\s*,1,Goto\(([^,]+),/i', $t, $m)) {
        $values[] = $m[1];
      }
    }
  }

  $values = array_values(array_unique($values));
  natsort($values);
  return array_values($values);
}

function get_extension_values($file){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $values = [];
  $inInternal = false;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[internal\]$/i', $t)) {
      $inInternal = true;
      continue;
    }

    if ($inInternal && preg_match('/^\[[^]]+\]$/', $t)) {
      break;
    }

    if (!$inInternal) continue;
    if ($t === "" || str_starts_with($t, ";")) continue;

    if (preg_match('/^exten\s*=>\s*(\d+)\s*,1,Goto\(dexter,\$\{EXTEN\},1\)$/i', $t, $m)) {
      $values[] = $m[1];
    }
  }

  $values = array_values(array_unique($values));
  natsort($values);
  return array_values($values);
}

function get_outbound_routes($file){
  $contexts = get_all_context_names($file);
  $routes = [];

  foreach ($contexts as $ctx) {
    if (preg_match('/^(out-|outrt-|route-|trunk-)/i', $ctx)) {
      $routes[] = $ctx;
    }
  }

  if (empty($routes)) {
    $routes = ["out-all", "out-pri", "out-gsm"];
  }

  $routes = array_values(array_unique($routes));
  natsort($routes);
  return array_values($routes);
}

function parse_speed_dial_line($line){
  $t = trim($line);
  if ($t === "") return null;

  $enabled = true;

  if (str_starts_with($t, ";")) {
    $enabled = false;
    $t = ltrim(substr($t, 1));
  }

  if (!preg_match('/^exten\s*=>\s*(\d+),1,Goto\(([^,]+),([^,]+),1\)$/i', $t, $m)) {
    return null;
  }

  $ext = $m[1];
  $context = $m[2];
  $value = $m[3];

  $type = "unknown";
  $route = "";

  if (str_starts_with($context, "ivr-")) {
    $type = "ivr";
  } elseif (str_starts_with($context, "queue-")) {
    $type = "queue";
  } elseif ($context === "dexter") {
    $type = "extension";
  } elseif ($context === "rcm-ring-groups") {
    $type = "ring_group";
  } elseif (str_starts_with($context, "ann-")) {
    $type = "announcement";
  } else {
    $type = "external";
    $route = $context;
  }

  return [
    "enabled"   => $enabled,
    "extension" => $ext,
    "type"      => $type,
    "value"     => $value,
    "route"     => $route
  ];
}

function get_speed_dial_by_extension($file, $extension){
  if (!file_exists($file)) return null;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $inContext = false;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[speed-dials\]$/i', $t)) {
      $inContext = true;
      continue;
    }

    if ($inContext && preg_match('/^\[[^]]+\]$/', $t)) {
      break;
    }

    if (!$inContext) continue;
    if ($t === "") continue;

    $parsed = parse_speed_dial_line($line);
    if ($parsed && $parsed["extension"] == $extension) {
      return $parsed;
    }
  }

  return null;
}

function build_speed_dial_line($enabled, $extension, $type, $value, $route){
  switch ($type) {
    case "ivr":
      $line = "exten => {$extension},1,Goto(ivr-{$value},{$value},1)";
      break;

    case "queue":
      $line = "exten => {$extension},1,Goto(queue-{$value},{$value},1)";
      break;

    case "extension":
      $line = "exten => {$extension},1,Goto(dexter,{$value},1)";
      break;

    case "ring_group":
      $line = "exten => {$extension},1,Goto(rcm-ring-groups,{$value},1)";
      break;

    case "announcement":
      $line = "exten => {$extension},1,Goto(ann-{$value},{$value},1)";
      break;

    case "external":
      $line = "exten => {$extension},1,Goto({$route},{$value},1)";
      break;

    default:
      return "";
  }

  return $enabled ? $line : ";" . $line;
}

function speed_dial_extension_exists($file, $extension, $ignoreExtension = ""){
  if (!file_exists($file)) return false;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $inContext = false;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[speed-dials\]$/i', $t)) {
      $inContext = true;
      continue;
    }

    if ($inContext && preg_match('/^\[[^]]+\]$/', $t)) {
      break;
    }

    if (!$inContext) continue;
    if ($t === "") continue;

    $parsed = parse_speed_dial_line($line);
    if ($parsed && $parsed["extension"] == $extension && $parsed["extension"] != $ignoreExtension) {
      return true;
    }
  }

  return false;
}

function update_speed_dial_in_context($file, $oldExtension, $newLine){
  if (!file_exists($file)) return false;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $newLines = [];
  $inContext = false;
  $updated = false;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[speed-dials\]$/i', $t)) {
      $inContext = true;
      $newLines[] = $line;
      continue;
    }

    if ($inContext && preg_match('/^\[[^]]+\]$/', $t)) {
      $inContext = false;
      $newLines[] = $line;
      continue;
    }

    if ($inContext) {
      $parsed = parse_speed_dial_line($line);
      if ($parsed && $parsed["extension"] == $oldExtension) {
        $newLines[] = $newLine;
        $updated = true;
        continue;
      }
    }

    $newLines[] = $line;
  }

  if (!$updated) return false;

  $final = implode("\n", $newLines);
  return file_put_contents($file, $final) !== false;
}

$ivrList          = [];
$queueList        = [];
$ringGroupList    = [];
$announcementList = [];
$extensionList    = get_extension_values($CFG_FILE);
$outboundRoutes   = get_outbound_routes($CFG_FILE);

$allContexts = get_all_context_names($CFG_FILE);
foreach ($allContexts as $ctx) {
  if (preg_match('/^queue-\d+$/i', $ctx)) {
    $num = preg_replace('/^queue-/i', '', $ctx);
    $queueList[] = $num;
  }
  if (preg_match('/^ivr-\d+$/i', $ctx)) {
    $num = preg_replace('/^ivr-/i', '', $ctx);
    $ivrList[] = $num;
  }
  if (preg_match('/^ann-\d+$/i', $ctx)) {
    $num = preg_replace('/^ann-/i', '', $ctx);
    $announcementList[] = $num;
  }
}

$ringGroupList = parse_values_from_context($CFG_FILE, "rcm-ring-groups", "same");

$ivrList          = array_values(array_unique(array_filter($ivrList)));
$queueList        = array_values(array_unique(array_filter($queueList)));
$ringGroupList    = array_values(array_unique(array_filter($ringGroupList)));
$announcementList = array_values(array_unique(array_filter($announcementList)));
$extensionList    = array_values(array_unique(array_filter($extensionList)));

natsort($ivrList);
natsort($queueList);
natsort($ringGroupList);
natsort($announcementList);
natsort($extensionList);

$ivrList          = array_values($ivrList);
$queueList        = array_values($queueList);
$ringGroupList    = array_values($ringGroupList);
$announcementList = array_values($announcementList);
$extensionList    = array_values($extensionList);

$oldExtension = trim($_GET["ext"] ?? "");
$item = get_speed_dial_by_extension($CFG_FILE, $oldExtension);

if (!$item) {
  die("Speed Dial not found.");
}

$error = "";

$enabled   = $item["enabled"];
$extension = $item["extension"];
$type      = $item["type"];
$value     = $item["value"];
$route     = $item["route"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $enabled   = isset($_POST["enabled"]);
  $extension = trim($_POST["extension"] ?? "");
  $type      = trim($_POST["type"] ?? "");
  $value     = trim($_POST["value"] ?? "");
  $route     = trim($_POST["route"] ?? "");

  if ($extension === "") {
    $error = "Speed Dial Extension is required.";
  } elseif (!preg_match('/^\d+$/', $extension)) {
    $error = "Speed Dial Extension must be numeric.";
  } elseif ($type === "") {
    $error = "Destination Type is required.";
  } elseif ($value === "") {
    $error = "Destination Value is required.";
  } elseif ($type === "external" && $route === "") {
    $error = "Outbound Route is required for external number.";
  } elseif (speed_dial_extension_exists($CFG_FILE, $extension, $oldExtension)) {
    $error = "Speed Dial Extension already exists.";
  } else {
    $line = build_speed_dial_line($enabled, $extension, $type, $value, $route);

    if ($line === "") {
      $error = "Failed to build Speed Dial line.";
    } else {
      if (update_speed_dial_in_context($CFG_FILE, $oldExtension, $line)) {
        

          exec("sudo /usr/sbin/asterisk -rx 'core reload' 2>&1");

        header("Location: /speed_dials.php");

        exit;
      } else {
        $error = "Failed to update Speed Dial.";
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Speed Dial</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    html,body{height:100%;margin:0}

    body{
      font-family: Arial, sans-serif;
      background:
        radial-gradient(circle at 1px 1px, #3d3d3d 1px, transparent 0),
        #5398d7;
      background-size: 10px 10px;
      display:block;
      padding:24px;
      color:#fff;
    }

    .wrap{
      width:min(1000px, 96vw);
      background:rgba(0,0,0,0.25);
      border:2px solid rgba(255,255,255,0.2);
      border-radius:22px;
      box-shadow:0 20px 50px rgba(0,0,0,.35);
      padding:26px 24px;
      backdrop-filter: blur(6px);
      margin:40px auto;
    }

    .page-title{
      width:100%;
      text-align:center;
      font-family:'Orbitron', sans-serif;
      font-weight:900;
      letter-spacing:10px;
      text-transform:uppercase;
      font-size:46px;
      margin:0 0 30px 0;
      text-shadow:
        0 0 20px rgba(255,255,255,.6),
        0 0 40px rgba(255,255,255,.3);
    }

    .inner-box{
      background: rgba(0,0,0,0.30);
      border-radius: 25px;
      padding: 24px;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
    }

    .field{
      margin-bottom:22px;
    }

    .label{
      display:block;
      margin-bottom:10px;
      font-size:22px;
      font-family:'Orbitron', sans-serif;
      letter-spacing:1px;
    }

    .req{
      color:#ffb3b3;
    }

    .input{
      width:100%;
      max-width:420px;
      padding:14px 16px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,0.25);
      background:rgba(255,255,255,0.10);
      color:#fff;
      font-size:18px;
      outline:none;
      box-sizing:border-box;
    }

    select.input option{
      color:#000;
    }

    .check-wrap{
      display:flex;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
    }

    .check-wrap input[type="checkbox"]{
      width:20px;
      height:20px;
    }

    .pill{
      display:inline-block;
      padding:6px 12px;
      border-radius:20px;
      border:1px solid rgba(255,255,255,.35);
      background:rgba(255,255,255,.10);
    }

    .bottom-actions{
      display:flex;
      gap:16px;
      flex-wrap:wrap;
      margin-top:28px;
    }

    .btn{
      display:inline-block;
      padding:12px 24px;
      border-radius:40px;
      border:2px solid #fff;
      background:transparent;
      color:#fff;
      font-weight:900;
      letter-spacing:2px;
      text-decoration:none;
      transition:.2s;
      text-align:center;
      cursor:pointer;
      font-family:'Orbitron', sans-serif;
      font-size:15px;
    }

    .btn:hover{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 22px rgba(255,255,255,.6);
    }

    .msg{
      margin-bottom:18px;
      padding:14px 16px;
      border-radius:14px;
      font-weight:bold;
    }

    .error{
      background:rgba(255,80,80,0.18);
      border:1px solid rgba(255,120,120,0.45);
    }

    .muted{
      opacity:.85;
    }
  </style>
</head>
<body>

<div class="wrap">
  <h1 class="page-title">EDIT SPEED DIAL</h1>

  <div class="inner-box">

    <?php if ($error !== ""): ?>
      <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="field">
        <label class="label">Enable Speed Dial</label>
        <div class="check-wrap">
          <input type="checkbox" name="enabled" <?php echo $enabled ? "checked" : ""; ?>>
          <span class="pill">Unchecked = write line commented with ;</span>
        </div>
      </div>

      <div class="field">
        <label class="label">Speed Dial Extension <span class="req">*</span></label>
        <input class="input" type="text" name="extension" value="<?php echo htmlspecialchars($extension); ?>" placeholder="6000">
      </div>

      <div class="field">
        <label class="label">Destination Type <span class="req">*</span></label>
        <select class="input" name="type" id="type" onchange="toggleFields()">
          <option value="ivr" <?php echo $type === "ivr" ? "selected" : ""; ?>>IVR</option>
          <option value="queue" <?php echo $type === "queue" ? "selected" : ""; ?>>Queue</option>
          <option value="extension" <?php echo $type === "extension" ? "selected" : ""; ?>>Extension</option>
          <option value="ring_group" <?php echo $type === "ring_group" ? "selected" : ""; ?>>Ring Group</option>
          <option value="announcement" <?php echo $type === "announcement" ? "selected" : ""; ?>>Announcement</option>
          <option value="external" <?php echo $type === "external" ? "selected" : ""; ?>>External Number</option>
        </select>
      </div>

      <div class="field" id="ivr_wrap" style="display:none;">
        <label class="label">IVR <span class="req">*</span></label>
        <select class="input" id="ivr_value">
          <option value="">Select IVR</option>
          <?php foreach ($ivrList as $x): ?>
            <option value="<?php echo htmlspecialchars($x); ?>" <?php echo ($type === "ivr" && $value == $x) ? "selected" : ""; ?>>
              <?php echo htmlspecialchars($x); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" id="queue_wrap" style="display:none;">
        <label class="label">Queue <span class="req">*</span></label>
        <select class="input" id="queue_value">
          <option value="">Select Queue</option>
          <?php foreach ($queueList as $x): ?>
            <option value="<?php echo htmlspecialchars($x); ?>" <?php echo ($type === "queue" && $value == $x) ? "selected" : ""; ?>>
              <?php echo htmlspecialchars($x); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" id="extension_wrap" style="display:none;">
        <label class="label">Extension <span class="req">*</span></label>
        <select class="input" id="extension_value">
          <option value="">Select Extension</option>
          <?php foreach ($extensionList as $x): ?>
            <option value="<?php echo htmlspecialchars($x); ?>" <?php echo ($type === "extension" && $value == $x) ? "selected" : ""; ?>>
              <?php echo htmlspecialchars($x); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" id="ring_group_wrap" style="display:none;">
        <label class="label">Ring Group <span class="req">*</span></label>
        <select class="input" id="ring_group_value">
          <option value="">Select Ring Group</option>
          <?php foreach ($ringGroupList as $x): ?>
            <option value="<?php echo htmlspecialchars($x); ?>" <?php echo ($type === "ring_group" && $value == $x) ? "selected" : ""; ?>>
              <?php echo htmlspecialchars($x); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" id="announcement_wrap" style="display:none;">
        <label class="label">Announcement <span class="req">*</span></label>
        <select class="input" id="announcement_value">
          <option value="">Select Announcement</option>
          <?php foreach ($announcementList as $x): ?>
            <option value="<?php echo htmlspecialchars($x); ?>" <?php echo ($type === "announcement" && $value == $x) ? "selected" : ""; ?>>
              <?php echo htmlspecialchars($x); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" id="external_wrap" style="display:none;">
        <label class="label">External Number <span class="req">*</span></label>
        <input class="input" type="text" id="external_value" value="<?php echo $type === "external" ? htmlspecialchars($value) : ""; ?>" placeholder="01012345678">
      </div>

      <div class="field" id="route_wrap" style="display:none;">
        <label class="label">Outbound Route <span class="req">*</span></label>
        <select class="input" name="route">
          <option value="">Select Route</option>
          <?php foreach ($outboundRoutes as $x): ?>
            <option value="<?php echo htmlspecialchars($x); ?>" <?php echo $route === $x ? "selected" : ""; ?>>
              <?php echo htmlspecialchars($x); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <input type="hidden" name="value" id="final_value" value="<?php echo htmlspecialchars($value); ?>">

      <div class="bottom-actions">
        <button class="btn" type="submit" onclick="syncValue()">SAVE</button>
        <a class="btn" href="/speed_dials.php">CANCEL</a>
      </div>
    </form>
  </div>
</div>

<script>
function toggleFields(){
  var type = document.getElementById("type").value;

  document.getElementById("ivr_wrap").style.display = "none";
  document.getElementById("queue_wrap").style.display = "none";
  document.getElementById("extension_wrap").style.display = "none";
  document.getElementById("ring_group_wrap").style.display = "none";
  document.getElementById("announcement_wrap").style.display = "none";
  document.getElementById("external_wrap").style.display = "none";
  document.getElementById("route_wrap").style.display = "none";

  if (type === "ivr") document.getElementById("ivr_wrap").style.display = "block";
  if (type === "queue") document.getElementById("queue_wrap").style.display = "block";
  if (type === "extension") document.getElementById("extension_wrap").style.display = "block";
  if (type === "ring_group") document.getElementById("ring_group_wrap").style.display = "block";
  if (type === "announcement") document.getElementById("announcement_wrap").style.display = "block";
  if (type === "external") {
    document.getElementById("external_wrap").style.display = "block";
    document.getElementById("route_wrap").style.display = "block";
  }
}

function syncValue(){
  var type = document.getElementById("type").value;
  var finalValue = "";

  if (type === "ivr") finalValue = document.getElementById("ivr_value").value;
  if (type === "queue") finalValue = document.getElementById("queue_value").value;
  if (type === "extension") finalValue = document.getElementById("extension_value").value;
  if (type === "ring_group") finalValue = document.getElementById("ring_group_value").value;
  if (type === "announcement") finalValue = document.getElementById("announcement_value").value;
  if (type === "external") finalValue = document.getElementById("external_value").value;

  document.getElementById("final_value").value = finalValue;
}

toggleFields();
</script>

</body>
</html>