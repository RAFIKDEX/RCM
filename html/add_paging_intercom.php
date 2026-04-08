<?php
require_once __DIR__ . '/auth.php';
rcm_require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$JSON       = '/etc/asterisk/rcm_paging_intercom.json';
$PJSIP_GUI  = '/etc/asterisk/pjsip.gui.endpoint.conf';
$MEDIA_DB   = '/etc/asterisk/rcm_media_center.json';

function clean($v){
    return trim((string)$v);
}

function rcm_load_pi(string $path): array {
    if (!file_exists($path)) return ["items" => []];
    $raw = file_get_contents($path);
    $j = json_decode($raw, true);

    if (!is_array($j)) return ["items" => []];
    if (isset($j["items"]) && is_array($j["items"])) return $j;
    if (array_is_list($j)) return ["items" => $j];

    return ["items" => []];
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

/* نفس منطق media_center.php مختصر للموديول ده */
function mc_default_db(){
    return [
        'prompts'     => [],
        'moh_classes' => [],
    ];
}

function mc_load_db(string $file): array {
    if (!file_exists($file)) return mc_default_db();
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return mc_default_db();
    $db = json_decode($raw, true);
    if (!is_array($db)) return mc_default_db();
    if (!isset($db['prompts']) || !is_array($db['prompts']))     $db['prompts'] = [];
    if (!isset($db['moh_classes']) || !is_array($db['moh_classes'])) $db['moh_classes'] = [];
    return $db;
}

/* تحميل بيانات paging/intercom */
$data  = rcm_load_pi($JSON);
$items = $data["items"] ?? [];

$id      = clean($_GET["id"] ?? "");
$editing = false;

$g = [
    "id"              => "",
    "name"            => "",
    "type"            => "paging",
    "members"         => [],
    "allowed_callers" => [],
    "welcome_prompt"  => "",
    "enabled"         => true,
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

/* old form data من محاولة فاشلة قبل كده */
if (!empty($_SESSION["paging_intercom_old"]) && is_array($_SESSION["paging_intercom_old"])) {
    $old = $_SESSION["paging_intercom_old"];
    unset($_SESSION["paging_intercom_old"]);

    $g["id"]              = (string)($old["id"] ?? $g["id"]);
    $g["name"]            = (string)($old["name"] ?? $g["name"]);
    $g["type"]            = (string)($old["type"] ?? $g["type"]);
    $g["members"]         = is_array($old["members"] ?? null) ? $old["members"] : $g["members"];
    $g["allowed_callers"] = is_array($old["allowed_callers"] ?? null) ? $old["allowed_callers"] : $g["allowed_callers"];
    $g["welcome_prompt"]  = (string)($old["welcome_prompt"] ?? $g["welcome_prompt"]);
    $g["enabled"]         = !empty($old["enabled"]);
}

$allExt = rcm_read_extensions_from_gui($PJSIP_GUI);

/* prompts من قاعدة الميديا سنتر */
$mediaDb      = mc_load_db($MEDIA_DB);
$mediaPrompts = $mediaDb['prompts'] ?? [];
usort($mediaPrompts, function($a, $b){
    return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$members = $g["members"] ?? [];
if (!is_array($members)) $members = [];
$members = array_values(array_unique(array_map("strval", $members)));

$allowed = $g["allowed_callers"] ?? [];
if (!is_array($allowed)) $allowed = [];
$allowed = array_values(array_unique(array_map("strval", $allowed)));

$availableMembers = array_values(array_diff($allExt, $members));
sort($availableMembers, SORT_NATURAL);

$availableAllowed = array_values(array_diff($allExt, $allowed));
sort($availableAllowed, SORT_NATURAL);

$err = clean($_GET["err"] ?? "");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo $editing ? 'Edit Paging / Intercom' : 'Add Paging / Intercom'; ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/rcm.css">

  <style>
    body{display:block!important}
    .wrap{width:min(1200px,96vw);margin:40px auto 60px auto}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
    .center-title{
      flex:1;min-width:240px;text-align:center;
      font-family:Orbitron,sans-serif;font-weight:900;letter-spacing:6px;text-transform:uppercase;
      font-size:44px;text-shadow:0 0 20px rgba(255,255,255,.30);pointer-events:none
    }
    .row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
    .field{flex:1;min-width:260px}
    label{display:block;font-weight:800;letter-spacing:1px;margin:6px 0}
    input[type=text], select{width:100%}
    .dual{display:grid;grid-template-columns:1fr auto 1fr;gap:14px;align-items:center}
    .dual .mid{display:flex;flex-direction:column;gap:10px}
    .dual select{height:240px}
    .btn.sm{padding:10px 14px;border-radius:26px}
    .hint{opacity:.75;margin-top:6px}
    .err{
      margin:10px 0 14px 0;
      padding:12px 14px;
      border:1px solid rgba(255,0,0,.35);
      background:rgba(255,0,0,.08);
      border-radius:14px;
    }
    .section-title{
      font-family:Orbitron,sans-serif;
      letter-spacing:3px;
      margin:0 0 10px 0;
    }
    @media (max-width:900px){
      .center-title{font-size:30px}
      .dual{grid-template-columns:1fr}
      .dual .mid{flex-direction:row;flex-wrap:wrap}
    }
  .field.field-prompt {
  max-width: 500px;
}
.field.field-prompt select {
  width: 100%;
}



  </style>
</head>
<body>

<div class="wrap">
  <div class="topbar">
    <a class="btn" href="paging_intercom.php">BACK</a>
    <div class="center-title"><?php echo $editing ? 'EDIT PAGING' : 'ADD PAGING'; ?></div>
    <span class="muted"><?php echo $editing ? 'Update existing entry' : 'Create new entry'; ?></span>
  </div>

  <?php if ($err !== ""): ?>
    <div class="err"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <div class="inner-box" style="margin-top:0">
    <div class="panel-box" style="margin-top:0">
      <form method="post" action="save_paging_intercom.php" id="piForm">

        <!-- الصف الأول: Extension + Name + Enabled على اليمين -->
        <div class="row" style="align-items:flex-start">
          <div class="field">
            <label>Extension</label>
            <input
              type="text"
              name="id"
              value="<?php echo htmlspecialchars((string)$g["id"]); ?>"
              placeholder="7000"
              <?php echo $editing ? 'readonly' : ''; ?>
            >
            <div class="hint muted">
              <?php echo $editing ? 'Extension cannot be changed after creation.' : 'Example: 7000'; ?>
            </div>
          </div>

          <div class="field">
            <label>Name</label>
            <input
              type="text"
              name="name"
              value="<?php echo htmlspecialchars((string)$g["name"]); ?>"
              placeholder="Office Paging"
            >
          </div>

          <div class="field" style="min-width:180px;flex:0 0 180px">
            <label style="display:flex;align-items:center;gap:10px;margin-top:32px">
              <input type="checkbox" name="enabled" <?php echo !empty($g["enabled"]) ? 'checked' : ''; ?>>
              Enabled
            </label>
          </div>
        </div>

        <!-- الصف الثاني: Strategy + Welcome Prompt -->
        <div class="row">
          <div class="field">
            <label>Strategy</label>
            <?php $type = (string)$g["type"]; ?>
            <select name="type" id="type">
              <option value="paging"   <?php echo $type === 'paging'   ? 'selected' : ''; ?>>paging</option>
              <option value="intercom" <?php echo $type === 'intercom' ? 'selected' : ''; ?>>intercom</option>
            </select>
            <div class="hint muted">paging = broadcast, intercom = two-way talk.</div>
          </div>

          <div class="field">
            <label>Welcome Prompt</label>
            <select name="welcome_prompt">
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
                  <?php echo ((string)$g["welcome_prompt"] === (string)$pid) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
            
            <div class="hint muted">Only prompts uploaded in Media Center appear here.</div>
          </div>
                    <div class="field field-prompt"></div>
        </div>

        <hr style="opacity:.25;margin:18px 0">

        <h3 class="section-title">MEMBERS</h3>
        <div class="dual">
          <div>
            <label>Available Extensions</label>
            <select id="avail_members" size="10" multiple>
              <?php foreach ($availableMembers as $e): ?>
                <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mid">
            <button type="button" class="btn sm" onclick="moveOptions('avail_members','sel_members')">ADD</button>
            <button type="button" class="btn sm" onclick="moveOptions('sel_members','avail_members')">REMOVE</button>
            <div style="height:10px"></div>
          </div>

          <div>
            <label>Selected Members</label>
            <select id="sel_members" size="10" multiple>
              <?php foreach ($members as $e): ?>
                <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint muted">These extensions will receive the paging/intercom call.</div>
          </div>
        </div>

        <hr style="opacity:.25;margin:18px 0">

        <h3 class="section-title">ALLOWED CALLERS</h3>
        <div class="dual">
          <div>
            <label>Available Extensions</label>
            <select id="avail_allowed" size="10" multiple>
              <?php foreach ($availableAllowed as $e): ?>
                <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
ك
          <div class="mid">
            <button type="button" class="btn sm" onclick="moveOptions('avail_allowed','sel_allowed')">ADD</button>
            <button type="button" class="btn sm" onclick="moveOptions('sel_allowed','avail_allowed')">REMOVE</button>
            <div style="height:10px"></div>
           
          </div>

          <div>
            <label>Selected Allowed Callers</label>
            <select id="sel_allowed" size="10" multiple>
              <?php foreach ($allowed as $e): ?>
                <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint muted">If empty, any extension can dial this paging/intercom number.</div>
          </div>
        </div>

        <div id="hiddenMembers"></div>
        <div id="hiddenAllowed"></div>

        <div class="row" style="margin-top:18px">
          <button class="btn" type="submit"><?php echo $editing ? 'SAVE' : 'CREATE'; ?></button>
          <a class="btn" href="paging_intercom.php">CANCEL</a>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
function moveOptions(srcId, dstId){
  const src = document.getElementById(srcId);
  const dst = document.getElementById(dstId);
  const sel = Array.from(src.selectedOptions);
  sel.forEach(o => dst.add(o));
}

function moveUp(selId){
  const sel = document.getElementById(selId);
  const opts = Array.from(sel.options);
  for(let i = 1; i < opts.length; i++){
    if(opts[i].selected && !opts[i-1].selected){
      sel.insertBefore(opts[i], opts[i-1]);
    }
  }
}

function moveDown(selId){
  const sel = document.getElementById(selId);
  const opts = Array.from(sel.options);
  for(let i = opts.length - 2; i >= 0; i--){
    if(opts[i].selected && !opts[i+1].selected){
      sel.insertBefore(opts[i+1], opts[i]);
    }
  }
}

document.getElementById('piForm').addEventListener('submit', function(){
  const holderMembers = document.getElementById('hiddenMembers');
  holderMembers.innerHTML = '';

  const selMembers = document.getElementById('sel_members');
  Array.from(selMembers.options).forEach(o => {
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'members[]';
    inp.value = o.value;
    holderMembers.appendChild(inp);
  });

  const holderAllowed = document.getElementById('hiddenAllowed');
  holderAllowed.innerHTML = '';

  const selAllowed = document.getElementById('sel_allowed');
  Array.from(selAllowed.options).forEach(o => {
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'allowed_callers[]';
    inp.value = o.value;
    holderAllowed.appendChild(inp);
  });
});
</script>

</body>
</html>
