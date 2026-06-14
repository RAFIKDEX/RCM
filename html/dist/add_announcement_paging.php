<?php
require_once __DIR__ . '/auth.php';
rcm_require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$JSON     = '/etc/asterisk/rcm_announcement_paging.json';
$PJSIP    = '/etc/asterisk/pjsip.gui.endpoint.conf';
$MEDIA_DB = '/etc/asterisk/rcm_media_center.json';

function clean($v){ return trim((string)$v); }

function rcm_load_ann(string $path): array {
    if (!file_exists($path)) return ["items" => []];
    $raw = file_get_contents($path);
    $j = json_decode($raw, true);
    if (!is_array($j)) return ["items" => []];
    if (isset($j["items"]) && is_array($j["items"])) return $j;
    if (array_is_list($j)) return ["items" => $j];
    return ["items" => []];
}

function rcm_read_extensions(string $path): array {
    if (!file_exists($path)) return [];
    $txt = file_get_contents($path);
    preg_match_all('/^\[(\d+)\]\s*$/m', $txt, $m);
    $out = array_values(array_unique(array_map("strval", $m[1] ?? [])));
    sort($out, SORT_NATURAL);
    return $out;
}

function mc_load_db(string $file): array {
    if (!file_exists($file)) return ['prompts' => []];
    $raw = @file_get_contents($file);
    if (!$raw) return ['prompts' => []];
    $db = json_decode($raw, true);
    if (!is_array($db)) return ['prompts' => []];
    if (!isset($db['prompts'])) $db['prompts'] = [];
    return $db;
}

$data  = rcm_load_ann($JSON);
$items = $data["items"] ?? [];

$id      = clean($_GET["id"] ?? "");
$editing = false;

$g = [
    "id"         => uniqid("ann_"),
    "name"       => "",
    "prompt_id"  => "",
    "members"    => [],
    "play_count" => 1,
    "days"       => [],
    "time"       => "08:00",
    "enabled"    => true,
];

if ($id !== "") {
    foreach ($items as $x) {
        if ((string)($x["id"] ?? "") === $id) {
            $g = array_merge($g, $x);
            $editing = true;
            break;
        }
    }
}

if (!empty($_SESSION["ann_paging_old"]) && is_array($_SESSION["ann_paging_old"])) {
    $old = $_SESSION["ann_paging_old"];
    unset($_SESSION["ann_paging_old"]);
    $g = array_merge($g, $old);
}

$allExt = rcm_read_extensions($PJSIP);
$mediaDb = mc_load_db($MEDIA_DB);
$mediaPrompts = $mediaDb['prompts'] ?? [];
usort($mediaPrompts, function($a, $b){
    return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$members = is_array($g["members"]) ? $g["members"] : [];
$members = array_values(array_unique(array_map("strval", $members)));

$availableMembers = array_values(array_diff($allExt, $members));
sort($availableMembers, SORT_NATURAL);

$selectedDays = is_array($g["days"]) ? $g["days"] : [];
$allDays = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];

$err = clean($_GET["err"] ?? "");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $editing ? 'Edit Announcement' : 'Add Announcement'; ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <style>
    .row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px}
    .field{flex:1;min-width:200px}
    .hint{opacity:.75;margin-top:6px;font-size:13px}
    .err{margin:10px 0 14px 0;padding:12px 14px;border:1px solid rgba(255,0,0,.35);background:rgba(255,0,0,.08);border-radius:14px;}
    .dual{display:grid;grid-template-columns:1fr auto 1fr;gap:14px;align-items:start}
    .dual .mid{display:flex;flex-direction:column;gap:10px;padding-top:90px}
    .dual select{height:200px;width:100%}
    .days-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
    .day-check{display:flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid rgba(255,255,255,.25);border-radius:999px;cursor:pointer}
    .day-check input{cursor:pointer}
    .section-title{font-family:Orbitron,sans-serif;letter-spacing:3px;margin:16px 0 10px 0;font-size:14px}
    @media(max-width:900px){.dual{grid-template-columns:1fr}.dual .mid{flex-direction:row}}
  </style>
</head>
<body>
<div class="wrap">

  <h1 class="page-title"><?php echo $editing ? 'EDIT ANNOUNCEMENT' : 'ADD ANNOUNCEMENT'; ?></h1>

  <div class="inner-box">

    <div class="top">
      <div class="btn-row">
        <a class="btn" href="announcement_paging.php">BACK</a>
      </div>
      <span class="muted"><?php echo $editing ? 'Update existing entry' : 'Create new entry'; ?></span>
    </div>

    <?php if ($err !== ""): ?>
      <div class="err"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <div class="panel-box">
      <form method="post" action="save_announcement_paging.php" id="annForm">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$g["id"]); ?>">

        <!-- Name + Enabled -->
        <div class="row">
          <div class="field">
            <label>Name</label>
            <input type="text" name="name"
              value="<?php echo htmlspecialchars((string)$g["name"]); ?>"
              placeholder="Morning Announcement">
          </div>

          <div class="field" style="min-width:140px;flex:0 0 140px">
            <label style="display:flex;align-items:center;gap:10px;margin-top:32px">
              <input type="checkbox" name="enabled" <?php echo !empty($g["enabled"]) ? 'checked' : ''; ?>>
              Enabled
            </label>
          </div>
        </div>

        <!-- Prompt + Play Count -->
        <div class="row">
          <div class="field">
            <label>Announcement File</label>
            <select name="prompt_id">
              <option value="">-- none --</option>
              <?php foreach ($mediaPrompts as $p): ?>
                <?php
                  $pid   = clean($p['id']   ?? '');
                  $pname = clean($p['name'] ?? '');
                  $ptype = clean($p['type'] ?? '');
                  if ($pid === '') continue;
                  $label = $pname !== '' ? $pname : $pid;
                  if ($ptype !== '') $label .= ' [' . $ptype . ']';
                ?>
                <option value="<?php echo htmlspecialchars($pid); ?>"
                  <?php echo ((string)$g["prompt_id"] === $pid) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint muted">Only prompts from Media Center appear here.</div>
          </div>

          <div class="field" style="min-width:160px;flex:0 0 160px">
            <label>Play Count</label>
            <input type="number" name="play_count" min="1" max="99"
              value="<?php echo (int)($g["play_count"] ?? 1); ?>">
            <div class="hint muted">Number of times to repeat.</div>
          </div>
        </div>

        <!-- Time -->
        <div class="row">
          <div class="field" style="max-width:200px">
            <label>Time</label>
            <input type="time" name="time"
              value="<?php echo htmlspecialchars((string)$g["time"]); ?>">
          </div>
        </div>

        <!-- Days -->
        <div class="section-title">DAYS</div>
        <div class="days-row">
          <?php foreach ($allDays as $day): ?>
            <label class="day-check">
              <input type="checkbox" name="days[]" value="<?php echo $day; ?>"
                <?php echo in_array($day, $selectedDays) ? 'checked' : ''; ?>>
              <?php echo $day; ?>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="hr" style="margin:20px 0"></div>

        <!-- Members -->
        <div class="section-title">MEMBERS</div>
        <div class="dual">
          <div>
            <label>Available</label>
            <select id="avail_members" size="8" multiple>
              <?php foreach ($availableMembers as $e): ?>
                <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mid">
            <button type="button" class="btn sm" onclick="moveOptions('avail_members','sel_members')">ADD &rsaquo;</button>
            <button type="button" class="btn sm" onclick="moveOptions('sel_members','avail_members')">&lsaquo; REMOVE</button>
            <div style="height:10px"></div>
            
          </div>
          <div>
            <label>Selected</label>
            <select id="sel_members" size="8" multiple>
              <?php foreach ($members as $e): ?>
                <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint muted">Will receive the announcement.</p>
          </div>
        </div>

        <div id="hiddenMembers"></div>

        <div class="btn-row" style="margin-top:20px">
          <button class="btn" type="submit"><?php echo $editing ? 'SAVE' : 'CREATE'; ?></button>
          <a class="btn" href="announcement_paging.php">CANCEL</a>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
function moveOptions(srcId, dstId){
  const src = document.getElementById(srcId);
  const dst = document.getElementById(dstId);
  Array.from(src.selectedOptions).forEach(o => dst.add(o));
}
function moveUp(selId){
  const sel = document.getElementById(selId);
  const opts = Array.from(sel.options);
  for(let i=1;i<opts.length;i++) if(opts[i].selected&&!opts[i-1].selected) sel.insertBefore(opts[i],opts[i-1]);
}
function moveDown(selId){
  const sel = document.getElementById(selId);
  const opts = Array.from(sel.options);
  for(let i=opts.length-2;i>=0;i--) if(opts[i].selected&&!opts[i+1].selected) sel.insertBefore(opts[i+1],opts[i]);
}
document.getElementById('annForm').addEventListener('submit', function(){
  const hm = document.getElementById('hiddenMembers');
  hm.innerHTML = '';
  Array.from(document.getElementById('sel_members').options).forEach(o => {
    const i = document.createElement('input');
    i.type = 'hidden';
    i.name = 'members[]';
    i.value = o.value;
    hm.appendChild(i);
  });
});
</script>
</body>
</html>