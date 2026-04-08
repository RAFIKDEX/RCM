<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$JSON = "/etc/asterisk/rcm_ring_groups.json";
$PJSIP_GUI = "/etc/asterisk/pjsip.gui.endpoint.conf";

function rcm_load_rg(string $path): array {
  if (!file_exists($path)) return ["groups" => []];
  $raw = file_get_contents($path);
  $j = json_decode($raw, true);
  if (!is_array($j)) return ["groups" => []];
  if (isset($j["groups"]) && is_array($j["groups"])) return $j;
  if (array_is_list($j)) return ["groups" => $j];
  return ["groups" => []];
}

function rcm_read_extensions_from_gui(string $path): array {
  if (!file_exists($path)) return [];
  $txt = file_get_contents($path);
  preg_match_all('/^\[(\d+)\]\s*$/m', $txt, $m);
  $out = $m[1] ?? [];
  $out = array_values(array_unique(array_map("strval", $out)));
  sort($out, SORT_NATURAL);
  return $out;
}

$data = rcm_load_rg($JSON);
$groups = $data["groups"] ?? [];

$id = trim((string)($_GET["id"] ?? ""));
$editing = false;

$g = [
  "id" => "",
  "name" => "",
  "strategy" => "ringall",
  "timeout" => 20,
  "per_try" => 10,
  "enabled" => true,
  "members" => [],
];

if ($id !== "") {
  foreach ($groups as $x) {
    if ((string)($x["id"] ?? "") === $id) {
      $g = array_merge($g, $x);
      $editing = true;
      break;
    }
  }
}

/* لو فيه old form data من فشل سابق، استخدمها بدل default */
if (!empty($_SESSION["ring_group_old"]) && is_array($_SESSION["ring_group_old"])) {
  $old = $_SESSION["ring_group_old"];
  unset($_SESSION["ring_group_old"]);

  $g["id"] = (string)($old["id"] ?? $g["id"]);
  $g["name"] = (string)($old["name"] ?? $g["name"]);
  $g["strategy"] = (string)($old["strategy"] ?? $g["strategy"]);
  $g["timeout"] = (int)($old["timeout"] ?? $g["timeout"]);
  $g["per_try"] = (int)($old["per_try"] ?? $g["per_try"]);
  $g["enabled"] = !empty($old["enabled"]);
  $g["members"] = is_array($old["members"] ?? null) ? $old["members"] : $g["members"];
}

$allExt = rcm_read_extensions_from_gui($PJSIP_GUI);
$members = $g["members"] ?? [];
if (!is_array($members)) $members = [];
$members = array_values(array_unique(array_map("strval", $members)));

$available = array_values(array_diff($allExt, $members));
sort($available, SORT_NATURAL);

$err = trim((string)($_GET["err"] ?? ""));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo $editing ? "Edit Ring Group" : "Add Ring Group"; ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
<link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    body{display:block!important;}
    .wrap{width:min(1200px,96vw); margin:40px auto 60px auto;}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
    .center-title{
      flex:1;min-width:240px;text-align:center;
      font-family:'Orbitron',sans-serif;font-weight:900;letter-spacing:6px;text-transform:uppercase;
      font-size:44px;text-shadow:0 0 20px rgba(255,255,255,.30);pointer-events:none;
    }
    .grid{display:grid;grid-template-columns: 1fr 1fr; gap:16px;}
    @media(max-width:900px){ .grid{grid-template-columns:1fr;} .center-title{font-size:30px;} }
    .row{display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;}
    .field{flex:1; min-width:260px;}
    label{display:block; font-weight:800; letter-spacing:1px; margin:6px 0;}
    input[type="text"], input[type="number"], select{ width:100%; }
    .dual{display:grid;grid-template-columns: 1fr auto 1fr;gap:14px;align-items:center;}
    .dual .mid{display:flex;flex-direction:column;gap:10px;}
    .dual select{height:240px;}
    .btn.sm{padding:10px 14px;border-radius:26px;}
    .hint{opacity:.75;margin-top:6px}
    .err{margin:10px 0 14px 0; padding:12px 14px; border:1px solid rgba(255,0,0,.35); background:rgba(255,0,0,.08); border-radius:14px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <a class="btn" href="/ring_groups.php">BACK</a>
      <div class="center-title"><?php echo $editing ? "EDIT GROUP" : "ADD GROUP"; ?></div>
      <span class="muted">IDs are 8XXX</span>
    </div>

    <?php if($err !== ""): ?>
      <div class="err"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <div class="inner-box" style="margin-top:0;">
      <div class="panel-box" style="margin-top:0;">

        <form method="post" action="/save_ring_group.php" id="rgForm">
          <div class="row">
            <div class="field">
              <label>Group ID (8XXX)</label>
              <input type="text" name="id" value="<?php echo htmlspecialchars((string)($g["id"] ?? "")); ?>" placeholder="8001" <?php echo $editing ? "readonly" : ""; ?> />
              <div class="hint muted"><?php echo $editing ? "ID cannot be changed." : "Example: 8001"; ?></div>
            </div>

            <div class="field">
              <label>Group Name</label>
              <input type="text" name="name" value="<?php echo htmlspecialchars((string)($g["name"] ?? "")); ?>" placeholder="support / sales" />
            </div>
          </div>

          <div class="row">
            <div class="field">
              <label>Strategy</label>
              <select name="strategy" id="strategy">
                <?php $st = (string)($g["strategy"] ?? "ringall"); ?>
                <option value="ringall" <?php echo $st==="ringall"?"selected":""; ?>>ringall</option>
                <option value="ordered" <?php echo $st==="ordered"?"selected":""; ?>>ordered</option>
              </select>
              <div class="hint muted">ordered = sequential per_try, ringall = all at once.</div>
            </div>

            <div class="field">
              <label>Timeout (ringall total seconds)</label>
              <input type="number" name="timeout" min="1" value="<?php echo (int)($g["timeout"] ?? 20); ?>" />
            </div>

            <div class="field">
              <label>Per Try (ordered seconds per member)</label>
              <input type="number" name="per_try" min="1" value="<?php echo (int)($g["per_try"] ?? 10); ?>" />
            </div>
          </div>

          <div class="row" style="margin-top:8px;">
            <label style="display:flex;align-items:center;gap:10px;">
              <input type="checkbox" name="enabled" <?php echo (($g["enabled"] ?? true) ? "checked" : ""); ?> />
              Enabled
            </label>
          </div>

          <hr style="opacity:.25;margin:18px 0;">

          <h3 style="font-family:'Orbitron',sans-serif;letter-spacing:3px;margin:0 0 10px 0;">MEMBERS</h3>
          <div class="dual">
            <div>
              <label>Available Extensions</label>
              <select id="avail" multiple>
                <?php foreach($available as $e): ?>
                  <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mid">
              <button type="button" class="btn sm" onclick="addSel()">ADD →</button>
              <button type="button" class="btn sm" onclick="removeSel()">← REMOVE</button>
              <div style="height:10px;"></div>
              <button type="button" class="btn sm" onclick="moveUp()">↑ UP</button>
              <button type="button" class="btn sm" onclick="moveDown()">↓ DOWN</button>
            </div>

            <div>
              <label>Selected (Order matters for ordered)</label>
              <select id="sel" size="10" multiple>
                <?php foreach($members as $e): ?>
                  <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint muted">For ringall, order doesn’t matter.</div>
            </div>
          </div>

          <div id="hiddenMembers"></div>

          <div class="row" style="margin-top:18px;">
            <button class="btn" type="submit"><?php echo $editing ? "SAVE" : "CREATE"; ?></button>
            <a class="btn" href="/ring_groups.php">CANCEL</a>
          </div>
        </form>

      </div>
    </div>
  </div>

<script>
function moveOptions(src, dst){
  const sel = Array.from(src.selectedOptions);
  sel.forEach(o => { dst.add(o); });
}
function addSel(){ moveOptions(document.getElementById('avail'), document.getElementById('sel')); }
function removeSel(){ moveOptions(document.getElementById('sel'), document.getElementById('avail')); }

function moveUp(){
  const sel = document.getElementById('sel');
  const opts = Array.from(sel.options);
  for(let i=1;i<opts.length;i++){
    if(opts[i].selected && !opts[i-1].selected){
      sel.insertBefore(opts[i], opts[i-1]);
    }
  }
}
function moveDown(){
  const sel = document.getElementById('sel');
  const opts = Array.from(sel.options);
  for(let i=opts.length-2;i>=0;i--){
    if(opts[i].selected && !opts[i+1].selected){
      sel.insertBefore(opts[i+1], opts[i]);
    }
  }
}

document.getElementById("rgForm").addEventListener("submit", function(){
  const holder = document.getElementById("hiddenMembers");
  holder.innerHTML = "";
  const sel = document.getElementById("sel");
  Array.from(sel.options).forEach(o=>{
    const inp = document.createElement("input");
    inp.type="hidden";
    inp.name="members[]";
    inp.value=o.value;
    holder.appendChild(inp);
  });
});
</script>
</body>
</html>
