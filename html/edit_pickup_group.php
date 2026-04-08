<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$EXT_GUI_FILE = "/etc/asterisk/extensions_gui.conf";
$PJSIP_FILE   = "/etc/asterisk/pjsip.gui.endpoint.conf";

$error = "";

function h($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirect_back(){
  header("Location: /pickup_groups.php");
  exit;
}

function write_lines($file, array $lines){
  $content = implode(PHP_EOL, $lines);
  if ($content !== "" && !str_ends_with($content, PHP_EOL)) {
    $content .= PHP_EOL;
  }
  return file_put_contents($file, $content, LOCK_EX) !== false;
}

function reload_asterisk(){
  @exec('sudo /usr/sbin/asterisk -rx "core reload" 2>&1', $out, $rc);

  return [
    "ok"  => ($rc === 0),
    "out" => $out,
    "rc"  => $rc
  ];
}

function normalize_group_list($value){
  if (is_array($value)) {
    $parts = $value;
  } else {
    $parts = explode(',', (string)$value);
  }

  $parts = array_map('trim', $parts);
  $parts = array_values(array_filter($parts, fn($x) => $x !== ""));
  $parts = array_values(array_unique($parts, SORT_STRING));
  usort($parts, fn($a, $b) => strnatcmp($a, $b));

  return $parts;
}

function merge_group_value($existingValue, $groupNumber){
  $parts = normalize_group_list($existingValue);
  $g = (string)$groupNumber;

  if (!in_array($g, $parts, true)) {
    $parts[] = $g;
  }

  usort($parts, fn($a, $b) => strnatcmp($a, $b));
  return implode(',', $parts);
}

function remove_group_value($existingValue, $groupNumber){
  $parts = normalize_group_list($existingValue);
  $g = (string)$groupNumber;

  $parts = array_values(array_filter($parts, fn($x) => $x !== $g));
  usort($parts, fn($a, $b) => strnatcmp($a, $b));

  return implode(',', $parts);
}

function parse_extensions_from_pjsip($file){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $exts = [];

  $curSection = null;
  $curType = null;
  $curCaller = "";

  foreach ($lines as $line) {
    $t = trim($line);

    if ($t === "" || str_starts_with($t, ";")) continue;

    if (preg_match('/^\[([^\]]+)\]$/', $t, $m)) {
      $curSection = trim($m[1]);
      $curType = null;
      $curCaller = "";
      continue;
    }

    if (!$curSection) continue;

    if (preg_match('/^type\s*=\s*(.+)$/i', $t, $m)) {
      $curType = strtolower(trim($m[1]));
      continue;
    }

    if (preg_match('/^callerid\s*=\s*(.+)$/i', $t, $m)) {
      $curCaller = trim($m[1]);
      continue;
    }

    if ($curType === "endpoint" && preg_match('/^\d+$/', $curSection)) {
      if (!isset($exts[$curSection])) {
        $label = $curSection;

        if ($curCaller !== "") {
          $label = preg_replace('/\s*<\d+>\s*$/', '', $curCaller);
          $label = trim($label);
          if ($label === "") {
            $label = $curSection;
          }
        }

        $exts[$curSection] = [
          "ext"  => $curSection,
          "name" => $label
        ];
      }
    }
  }

  uasort($exts, fn($a, $b) => strnatcmp($a["ext"], $b["ext"]));
  return array_values($exts);
}

function parse_pickup_group_by_number($file, $groupNumber){
  if (!file_exists($file)) return null;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $cur = null;
  $group = null;
  $pendingName = null;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^;\s*---\s*PICKUP GROUP GUI:\s*(.+?)\s*---$/i', $t, $m)) {
      $pendingName = trim($m[1]);
      continue;
    }

    if (preg_match('/^\[pickup-(\d{1,10})\]$/i', $t, $m)) {
      $num = $m[1];
      $cur = $num;

      if ((string)$num === (string)$groupNumber) {
        $group = [
          "group"   => $num,
          "name"    => $pendingName ?: ("pickup-" . $num),
          "members" => []
        ];
      }

      $pendingName = null;
      continue;
    }

    if (!$cur) continue;

    if ((string)$cur !== (string)$groupNumber) {
      if (preg_match('/^\[[^]]+\]$/', $t) && !preg_match('/^\[pickup-(\d{1,10})\]$/i', $t)) {
        $cur = null;
      }
      continue;
    }

    if ($group && preg_match('/^name\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $group["name"] = trim($m[1]);
      continue;
    }

    if ($group && preg_match('/^members\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $raw = trim($m[1]);
      if ($raw !== "") {
        $group["members"] = array_values(
          array_filter(array_map('trim', explode(',', $raw)), fn($x) => $x !== "")
        );
      }
      continue;
    }

    if (preg_match('/^\[[^]]+\]$/', $t) && !preg_match('/^\[pickup-(\d{1,10})\]$/i', $t)) {
      $cur = null;
      continue;
    }
  }

  return $group;
}

function parse_all_pickup_groups($file){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $groups = [];
  $cur = null;
  $pendingName = null;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^;\s*---\s*PICKUP GROUP GUI:\s*(.+?)\s*---$/i', $t, $m)) {
      $pendingName = trim($m[1]);
      continue;
    }

    if (preg_match('/^\[pickup-(\d{1,10})\]$/i', $t, $m)) {
      $num = $m[1];
      $cur = $num;
      $groups[$num] = [
        "group"   => $num,
        "name"    => $pendingName ?: ("pickup-" . $num),
        "members" => []
      ];
      $pendingName = null;
      continue;
    }

    if (!$cur) continue;

    if (preg_match('/^name\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $groups[$cur]["name"] = trim($m[1]);
      continue;
    }

    if (preg_match('/^members\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $raw = trim($m[1]);
      if ($raw !== "") {
        $groups[$cur]["members"] = array_values(
          array_filter(array_map('trim', explode(',', $raw)), fn($x) => $x !== "")
        );
      }
      continue;
    }

    if (preg_match('/^\[[^]]+\]$/', $t) && !preg_match('/^\[pickup-(\d{1,10})\]$/i', $t)) {
      $cur = null;
      continue;
    }
  }

  return $groups;
}

function update_pickup_group_section($file, $groupNumber, $name, $members){
  if (!file_exists($file)) return false;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $out = [];
  $insideTarget = false;
  $commentHandled = false;

  for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $t = trim($line);

    if (preg_match('/^;\s*---\s*PICKUP GROUP GUI:\s*(.+?)\s*---$/i', $t)) {
      $next = trim($lines[$i + 1] ?? "");
      if (preg_match('/^\[pickup-' . preg_quote((string)$groupNumber, '/') . '\]$/i', $next)) {
        $out[] = "; --- PICKUP GROUP GUI: " . $name . " ---";
        $commentHandled = true;
        continue;
      }
    }

    if (preg_match('/^\[pickup-(\d{1,10})\]$/i', $t, $m)) {
      if ((string)$m[1] === (string)$groupNumber) {
        $insideTarget = true;
        $out[] = "[pickup-" . $groupNumber . "]";
        $out[] = "name=" . $name;
        $out[] = "group=" . $groupNumber;
        $out[] = "members=" . implode(",", $members);
        continue;
      } else {
        $insideTarget = false;
      }
    }

    if ($insideTarget) {
      if (preg_match('/^\[[^]]+\]$/', $t)) {
        $insideTarget = false;
        $out[] = $line;
      }
      continue;
    }

    $out[] = $line;
  }

  if (!$commentHandled) {
    $out[] = "";
    $out[] = "; --- PICKUP GROUP GUI: " . $name . " ---";
    $out[] = "[pickup-" . $groupNumber . "]";
    $out[] = "name=" . $name;
    $out[] = "group=" . $groupNumber;
    $out[] = "members=" . implode(",", $members);
  }

  return write_lines($file, $out);
}

function rebuild_pjsip_for_group_change($file, $groupNumber, $oldMembers, $newMembers){
  if (!file_exists($file)) return false;

  $oldLookup = array_fill_keys($oldMembers, true);
  $newLookup = array_fill_keys($newMembers, true);

  $lines = file($file, FILE_IGNORE_NEW_LINES);

  $sections = [];
  $currentHeader = null;
  $currentBody = [];

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[([^\]]+)\]$/', $t, $m)) {
      if ($currentHeader !== null) {
        $sections[] = [
          "header" => $currentHeader,
          "body"   => $currentBody
        ];
      }
      $currentHeader = $line;
      $currentBody = [];
      continue;
    }

    if ($currentHeader === null) {
      $sections[] = [
        "header" => null,
        "body"   => [$line]
      ];
      continue;
    }

    $currentBody[] = $line;
  }

  if ($currentHeader !== null) {
    $sections[] = [
      "header" => $currentHeader,
      "body"   => $currentBody
    ];
  }

  $out = [];

  foreach ($sections as $section) {
    if ($section["header"] === null) {
      foreach ($section["body"] as $line) {
        $out[] = $line;
      }
      continue;
    }

    $headerTrim = trim($section["header"]);
    preg_match('/^\[([^\]]+)\]$/', $headerTrim, $m);
    $sectionName = $m[1] ?? '';

    $out[] = $section["header"];

    $isOld = isset($oldLookup[$sectionName]);
    $isNew = isset($newLookup[$sectionName]);

    if (!$isOld && !$isNew) {
      foreach ($section["body"] as $line) {
        $out[] = $line;
      }
      continue;
    }

    $existingCall = '';
    $existingPickup = '';
    $cleanBody = [];

    foreach ($section["body"] as $line) {
      $t = trim($line);

      if (preg_match('/^call_group\s*=\s*(.*?)\s*$/i', $t, $mm)) {
        $existingCall = $mm[1];
        continue;
      }

      if (preg_match('/^pickup_group\s*=\s*(.*?)\s*$/i', $t, $mm)) {
        $existingPickup = $mm[1];
        continue;
      }

      $cleanBody[] = $line;
    }

    $finalCall = $existingCall;
    $finalPickup = $existingPickup;

    if ($isOld && !$isNew) {
      $finalCall = remove_group_value($finalCall, $groupNumber);
      $finalPickup = remove_group_value($finalPickup, $groupNumber);
    }

    if ($isNew) {
      $finalCall = merge_group_value($finalCall, $groupNumber);
      $finalPickup = merge_group_value($finalPickup, $groupNumber);
    }

    $inserted = false;

    foreach ($cleanBody as $line) {
      $out[] = $line;

      if (!$inserted && preg_match('/^;?\s*direct_media\s*=/i', trim($line))) {
        if ($finalCall !== "") {
          $out[] = "call_group=" . $finalCall;
        }
        if ($finalPickup !== "") {
          $out[] = "pickup_group=" . $finalPickup;
        }
        $inserted = true;
      }
    }

    if (!$inserted) {
      if ($finalCall !== "") {
        $out[] = "call_group=" . $finalCall;
      }
      if ($finalPickup !== "") {
        $out[] = "pickup_group=" . $finalPickup;
      }
    }
  }

  return write_lines($file, $out);
}

$groupNumber = trim($_GET["g"] ?? $_POST["g"] ?? "");
if ($groupNumber === "" || !preg_match('/^\d{1,10}$/', $groupNumber)) {
  redirect_back();
}

$currentGroup = parse_pickup_group_by_number($EXT_GUI_FILE, $groupNumber);
if (!$currentGroup) {
  redirect_back();
}

$allExtensions = parse_extensions_from_pjsip($PJSIP_FILE);
$allGroups = parse_all_pickup_groups($EXT_GUI_FILE);

$name = $currentGroup["name"];
$selectedMembers = $currentGroup["members"];
$oldMembers = $currentGroup["members"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $name = trim($_POST["name"] ?? "");
  $selectedMembers = $_POST["members"] ?? [];
  $oldMembers = $_POST["old_members"] ?? [];

  if (!is_array($selectedMembers)) $selectedMembers = [];
  if (!is_array($oldMembers)) $oldMembers = [];

  $selectedMembers = array_values(
    array_unique(
      array_filter(array_map('trim', $selectedMembers), fn($x) => $x !== "")
    )
  );

  $oldMembers = array_values(
    array_unique(
      array_filter(array_map('trim', $oldMembers), fn($x) => $x !== "")
    )
  );

  if ($name === "") {
    $error = "Pickup Group Name is required.";
  } elseif (empty($selectedMembers)) {
    $error = "Please choose at least one member.";
  } else {
    foreach ($allGroups as $gnum => $gdata) {
      if ((string)$gnum !== (string)$groupNumber && strcasecmp($gdata["name"], $name) === 0) {
        $error = "Pickup Group Name already exists.";
        break;
      }
    }

    if ($error === "") {
      $ok1 = update_pickup_group_section($EXT_GUI_FILE, $groupNumber, $name, $selectedMembers);

      if (!$ok1) {
        $error = "Failed to update extensions_gui.conf.";
      } else {
        $ok2 = rebuild_pjsip_for_group_change($PJSIP_FILE, $groupNumber, $oldMembers, $selectedMembers);

        if (!$ok2) {
          $error = "Failed to update pjsip.gui.endpoint.conf.";
        } else {
          $reload = reload_asterisk();

          if (!$reload["ok"]) {
            $error = "Pickup Group updated, but Asterisk core reload failed.";
          } else {
            header("Location: /pickup_groups.php");
            exit;
          }
        }
      }
    }
  }
}

$selectedLookup = array_fill_keys($selectedMembers, true);
$availableExtensions = [];
$chosenExtensions = [];

foreach ($allExtensions as $ex) {
  if (isset($selectedLookup[$ex["ext"]])) {
    $chosenExtensions[] = $ex;
  } else {
    $availableExtensions[] = $ex;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Pickup Group</title>

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

  display:block; /* المهم */
  padding:24px;
  color:#fff;
}

    .wrap{
      width:min(1200px, 96vw);
      background:rgba(0,0,0,0.25);
      border:2px solid rgba(255,255,255,0.2);
      border-radius:22px;
      box-shadow:0 20px 50px rgba(0,0,0,.35);
      padding:26px 24px;
      backdrop-filter: blur(6px);
      margin:40px auto; /* ده اللي بيوسّطها أفقياً */
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

    .req{color:#ffb3b3}

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

    .input::placeholder{
      color:rgba(255,255,255,0.70);
    }

    .group-pill{
      display:inline-block;
      padding:10px 16px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.35);
      background:rgba(255,255,255,.10);
      font-family:'Orbitron', sans-serif;
      letter-spacing:1px;
    }

    .dual-wrap{
      display:grid;
      grid-template-columns: 1fr 70px 1fr;
      gap:18px;
      align-items:center;
      margin-top:8px;
    }

    .box-title{
      margin-bottom:10px;
      font-family:'Orbitron', sans-serif;
      letter-spacing:1px;
      font-size:18px;
    }

    .search{
      width:100%;
      padding:12px 14px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,0.25);
      background:rgba(255,255,255,0.10);
      color:#fff;
      font-size:16px;
      margin-bottom:12px;
      box-sizing:border-box;
      outline:none;
    }

    .search::placeholder{
      color:rgba(255,255,255,0.70);
    }

    .list-box{
      width:100%;
      height:280px;
      border-radius:16px;
      border:1px solid rgba(255,255,255,0.20);
      background:rgba(255,255,255,0.08);
      color:#fff;
      padding:10px;
      box-sizing:border-box;
      font-size:16px;
    }

    .middle-actions, .side-actions{
      display:flex;
      flex-direction:column;
      gap:10px;
      align-items:center;
      justify-content:center;
    }

    .move-btn{
      width:48px;
      height:48px;
      border-radius:12px;
      border:2px solid #fff;
      background:transparent;
      color:#fff;
      font-size:24px;
      cursor:pointer;
      transition:.2s;
    }

    .move-btn:hover{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 18px rgba(255,255,255,.5);
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
      font-family:'Orbitron',sans-serif;
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

    @media (max-width: 900px){
      .dual-wrap{
        grid-template-columns: 1fr;
      }

      .middle-actions, .side-actions{
        flex-direction:row;
        flex-wrap:wrap;
      }

      .page-title{
        font-size:34px;
        letter-spacing:6px;
      }
    }
  </style>
</head>
<body>

<div class="wrap">
  <h1 class="page-title">EDIT PICKUP GROUP</h1>

  <div class="inner-box">
    <?php if ($error !== ""): ?>
      <div class="msg error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="post" id="pickupForm">
      <input type="hidden" name="g" value="<?php echo h($groupNumber); ?>">
      <?php foreach ($oldMembers as $m): ?>
        <input type="hidden" name="old_members[]" value="<?php echo h($m); ?>">
      <?php endforeach; ?>

      <div class="field">
        <label class="label">Group Number</label>
        <div class="group-pill"><?php echo h($groupNumber); ?></div>
      </div>

      <div class="field">
        <label class="label"><span class="req">*</span> Name</label>
        <input
          class="input"
          type="text"
          name="name"
          value="<?php echo h($name); ?>"
          placeholder="PickUp Groups Name"
          maxlength="100"
        >
      </div>

      <div class="field">
        <label class="label"><span class="req">*</span> Members</label>

        <div class="dual-wrap">
          <div>
            <div class="box-title">Available Extensions</div>
            <input class="search" type="text" id="leftSearch" placeholder="Search...">
            <select class="list-box" id="availableList" multiple>
              <?php foreach ($availableExtensions as $ex): ?>
                <option value="<?php echo h($ex["ext"]); ?>">
                  <?php echo h($ex["name"]); ?> [<?php echo h($ex["ext"]); ?>]
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="middle-actions">
            <button class="move-btn" type="button" onclick="moveAll('availableList','selectedList')">»</button>
            <button class="move-btn" type="button" onclick="moveSelected('availableList','selectedList')">›</button>
            <button class="move-btn" type="button" onclick="moveSelected('selectedList','availableList')">‹</button>
            <button class="move-btn" type="button" onclick="moveAll('selectedList','availableList')">«</button>
          </div>

          <div>
            <div class="box-title">Selected Members</div>
            <input class="search" type="text" id="rightSearch" placeholder="Search...">
            <div style="display:grid;grid-template-columns:1fr 56px;gap:12px;align-items:start;">
              <select class="list-box" id="selectedList" multiple name="members[]">
                <?php foreach ($chosenExtensions as $ex): ?>
                  <option value="<?php echo h($ex["ext"]); ?>">
                    <?php echo h($ex["name"]); ?> [<?php echo h($ex["ext"]); ?>]
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="side-actions">
                <button class="move-btn" type="button" onclick="moveUp('selectedList')">˄</button>
                <button class="move-btn" type="button" onclick="moveTop('selectedList')">⤒</button>
                <button class="move-btn" type="button" onclick="moveDown('selectedList')">˅</button>
                <button class="move-btn" type="button" onclick="moveBottom('selectedList')">⤓</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="bottom-actions">
        <button class="btn" type="submit" onclick="selectAllMembers()">SAVE</button>
        <a class="btn" href="/pickup_groups.php">CANCEL</a>
      </div>
    </form>
  </div>
</div>

<script>
function moveSelected(fromId, toId){
  const from = document.getElementById(fromId);
  const to = document.getElementById(toId);

  const selected = Array.from(from.options).filter(o => o.selected);
  selected.forEach(o => {
    o.selected = false;
    to.appendChild(o);
  });
}

function moveAll(fromId, toId){
  const from = document.getElementById(fromId);
  const to = document.getElementById(toId);

  Array.from(from.options).forEach(o => {
    o.selected = false;
    to.appendChild(o);
  });
}

function moveUp(selectId){
  const sel = document.getElementById(selectId);
  for (let i = 1; i < sel.options.length; i++) {
    const o = sel.options[i];
    if (o.selected && !sel.options[i - 1].selected) {
      sel.insertBefore(o, sel.options[i - 1]);
    }
  }
}

function moveDown(selectId){
  const sel = document.getElementById(selectId);
  for (let i = sel.options.length - 2; i >= 0; i--) {
    const o = sel.options[i];
    if (o.selected && !sel.options[i + 1].selected) {
      sel.insertBefore(sel.options[i + 1], o);
    }
  }
}

function moveTop(selectId){
  const sel = document.getElementById(selectId);
  const selected = Array.from(sel.options).filter(o => o.selected);
  selected.forEach(o => sel.insertBefore(o, sel.firstChild));
}

function moveBottom(selectId){
  const sel = document.getElementById(selectId);
  const selected = Array.from(sel.options).filter(o => o.selected);
  selected.forEach(o => sel.appendChild(o));
}

function selectAllMembers(){
  const sel = document.getElementById('selectedList');
  Array.from(sel.options).forEach(o => o.selected = true);
}

function attachSearch(inputId, selectId){
  const input = document.getElementById(inputId);
  const select = document.getElementById(selectId);

  input.addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();

    Array.from(select.options).forEach(opt => {
      const txt = opt.text.toLowerCase();
      opt.style.display = txt.includes(q) ? '' : 'none';
    });
  });
}

attachSearch('leftSearch', 'availableList');
attachSearch('rightSearch', 'selectedList');

document.getElementById('pickupForm').addEventListener('submit', function(){
  selectAllMembers();
});
</script>

</body>
</html>