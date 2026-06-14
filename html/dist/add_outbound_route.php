<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$JSON_FILE = "/etc/asterisk/rcm_outbound_routes.json";
$PJSIP_GUI_ENDPOINTS = "/etc/asterisk/pjsip.gui.endpoint.conf";
$RING_JSON = "/etc/asterisk/rcm_ring_groups.json";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function rcm_load_json(string $jsonFile): array {
  if (!file_exists($jsonFile)) return ["routes" => []];
  $raw = file_get_contents($jsonFile);
  $j = json_decode($raw, true);
  if (!is_array($j)) return ["routes" => []];
  if (isset($j["routes"]) && is_array($j["routes"])) return $j;
  if (array_is_list($j)) return ["routes" => $j];
  return ["routes" => []];
}

function rcm_read_file_safe(string $path): string {
  return file_exists($path) ? (file_get_contents($path) ?: "") : "";
}

function rcm_read_trunks_from_gui_markers(string $path): array {
  $txt = rcm_read_file_safe($path);
  if ($txt === "") return [];
  $re = '/^\\s*;\\s*---\\s*RCM-TRUNK:\\s*([A-Za-z0-9_\\-]+)\\s*BEGIN\\s*---\\s*$/m';
  if (!preg_match_all($re, $txt, $m)) return [];
  $names = array_values(array_unique(array_filter($m[1] ?? [], fn($x)=> trim((string)$x) !== "")));
  sort($names, SORT_NATURAL);
  return $names;
}

function rcm_read_extensions_from_pjsip_gui(string $path): array {
  $txt = rcm_read_file_safe($path);
  if ($txt === "") return [];
  $trunks = rcm_read_trunks_from_gui_markers($path);
  $trunkSet = array_fill_keys($trunks, true);
  preg_match_all('/^\\[(\\d+)\\]\\s*$/m', $txt, $m);
  $exts = $m[1] ?? [];
  $out = [];
  foreach ($exts as $e) {
    $e = (string)$e;
    if ($e === "") continue;
    if (isset($trunkSet[$e])) continue;
    $out[] = $e;
  }
  $out = array_values(array_unique($out));
  sort($out, SORT_NATURAL);
  return $out;
}

function rcm_read_groups(string $path): array {
  if (!file_exists($path)) return [];
  $j = json_decode(file_get_contents($path) ?: "{}", true);
  if (!is_array($j)) return [];
  $groups = $j["groups"] ?? (array_is_list($j) ? $j : []);
  $out = [];
  foreach ($groups as $g) {
    if (!is_array($g)) continue;
    if (!empty($g["enabled"]) || !isset($g["enabled"])) {
      $id   = (string)($g["id"]   ?? "");
      $name = (string)($g["name"] ?? "");
      if ($id !== "") $out[] = ["id" => $id, "name" => $name];
    }
  }
  usort($out, fn($a,$b)=> strnatcasecmp($a["id"], $b["id"]));
  return $out;
}

function rcm_ctx_to_slug(string $ctx): string {
  if (str_starts_with($ctx, "rcm-out-")) return substr($ctx, 8);
  return $ctx;
}

$ctx  = trim((string)($_GET["ctx"] ?? ""));
$data = rcm_load_json($JSON_FILE);
$routes = $data["routes"] ?? [];
$cur = null;
foreach ($routes as $r) {
  if ((string)($r["context"] ?? "") === $ctx) { $cur = $r; break; }
}

$exts    = rcm_read_extensions_from_pjsip_gui($PJSIP_GUI_ENDPOINTS);
$trunks  = rcm_read_trunks_from_gui_markers($PJSIP_GUI_ENDPOINTS);
$groups  = rcm_read_groups($RING_JSON);

$slug           = $cur ? rcm_ctx_to_slug((string)($cur["context"] ?? "")) : "";
$name           = (string)($cur["name"]           ?? "");
$enabled        = $cur ? !empty($cur["enabled"])  : true;
$mode           = (string)($cur["mode"]           ?? "whitelist");
$patterns       = $cur ? implode("\n", (array)($cur["patterns"] ?? [])) : "";
$timeout        = (int)($cur["timeout"]           ?? 30);
$time_limit_sec = (int)($cur["time_limit_sec"]    ?? 0);
$strip          = (int)($cur["strip"]             ?? 0);
$prepend        = (string)($cur["prepend"]        ?? "");
$pin            = (string)($cur["pin"]            ?? "");
$record         = $cur ? !empty($cur["record"])   : false;
$whitelist      = (array)($cur["whitelist"]       ?? []);
$sel_groups     = (array)($cur["groups"]          ?? []);
$sel_trunks     = (array)($cur["trunks"]          ?? []);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo $ctx ? "Edit Outbound" : "Add Outbound"; ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    /* -- Layout ----------------------------------------- */
    .panel-box {
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 18px;
      padding: 28px;
      margin-top: 18px;
    }

    .top {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 6px;
    }
    .top .btn {
      background: transparent !important;
      color: #fff !important;
    }

    /* -- Grid helpers ----------------------------------- */
    .grid2 { display: grid; grid-template-columns: 1fr 1fr;     gap: 18px; }
    .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }

    /* -- Form fields ------------------------------------ */
    .row { margin-bottom: 18px; }

    .lbl {
      display: block;
      font-weight: 900;
      letter-spacing: 1px;
      opacity: .9;
      margin-bottom: 8px;
      font-size: 13px;
      text-transform: uppercase;
    }

    input[type=text],
    input[type=number],
    textarea,
    select {
      width: 100%;
      padding: 12px 16px;
      border-radius: 12px;
      background: rgba(255,255,255,.07);
      border: 1px solid rgba(255,255,255,.20);
      color: #fff;
      font-size: 15px;
      outline: none;
      box-sizing: border-box;
      transition: border-color .2s;
    }
    input[type=text]:focus,
    input[type=number]:focus,
    textarea:focus,
    select:focus {
      border-color: rgba(255,255,255,.50);
    }

    /* number inputs: fix spinner overlap */
    input[type=number] {
      -moz-appearance: textfield;
      padding-right: 10px;
    }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button {
      opacity: 1;
    }

    textarea {
      min-height: 110px;
      resize: vertical;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas,
                   "Liberation Mono", "Courier New", monospace;
    }

    /* -- Dual-listbox ----------------------------------- */
    .two {
      display: grid;
      grid-template-columns: 1fr 90px 1fr;
      gap: 12px;
      align-items: center;
    }
    .two select[multiple] {
      height: 200px;
      padding: 6px 8px;
    }
    .btncol {
      display: flex;
      flex-direction: column;
      gap: 8px;
      align-items: stretch;
    }
    .btn.sm {
      padding: 8px 10px;
      font-size: 11px;
      letter-spacing: 1px;
      border-radius: 30px;
      text-align: center;
    }

    /* -- Misc ------------------------------------------- */
    .hint {
      opacity: .65;
      font-size: 12px;
      margin-top: 5px;
    }
    .check-row {
      display: flex;
      gap: 10px;
      align-items: center;
      padding: 12px 0;
    }
    .form-actions {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      margin-top: 24px;
    }
    .section-sep {
      border: none;
      border-top: 1px solid rgba(255,255,255,.10);
      margin: 24px 0;
    }

    @media (max-width: 900px) {
      .grid2, .grid3, .two { grid-template-columns: 1fr; }
      .btncol { flex-direction: row; flex-wrap: wrap; }
    }
  </style>
</head>
<body>
<div class="wrap">

  <h1 class="page-title"><?php echo $ctx ? "EDIT OUTBOUND" : "ADD OUTBOUND"; ?></h1>

  <div class="inner-box">

    <div class="top">
      <a class="btn" href="/outbound_routes.php">BACK</a>
    </div>

    <div class="panel-box">
      <p class="muted" style="margin:0 0 20px 2px;">Powered by RAFIK</p>

      <form method="post" action="/save_outbound_route.php"
            onsubmit="selectAll('sel_trunks'); selectAll('sel_whitelist'); selectAll('sel_groups');">

        <input type="hidden" name="old_context" value="<?php echo h($ctx); ?>"/>

        <!-- Row 1: slug + name -->
        <div class="grid2">
          <div class="row">
            <label class="lbl">Route Name (slug)</label>
            <input type="text" name="slug" value="<?php echo h($slug); ?>" placeholder="e.g. hamo" required />
            <div class="hint">Context ? <b>rcm-out-&lt;slug&gt;</b></div>
          </div>
          <div class="row">
            <label class="lbl">Display Name</label>
            <input type="text" name="name" value="<?php echo h($name); ?>" placeholder="Friendly name (optional)" />
          </div>
        </div>

        <!-- Row 2: enabled + mode + record -->
        <div class="grid3">
          <div class="row">
            <label class="lbl">Enabled</label>
            <div class="check-row">
              <input type="checkbox" name="enabled" <?php echo $enabled ? "checked" : ""; ?> />
              <span>YES</span>
            </div>
          </div>
          <div class="row">
            <label class="lbl">Mode</label>
            <select name="mode" id="modeSel" onchange="toggleMode()">
              <option value="whitelist" <?php echo $mode==="whitelist"?"selected":""; ?>>Whitelist (Extensions)</option>
              <option value="groups"    <?php echo $mode==="groups"   ?"selected":""; ?>>Groups (Ring Groups)</option>
            </select>
          </div>
          <div class="row">
            <label class="lbl">Record</label>
            <div class="check-row">
              <input type="checkbox" name="record" <?php echo $record ? "checked" : ""; ?> />
              <span>MixMonitor</span>
            </div>
          </div>
        </div>

        <!-- Patterns -->
        <div class="row">
          <label class="lbl">Patterns (one per line)</label>
          <textarea name="patterns" placeholder="Example: 88011x."><?php echo h($patterns); ?></textarea>
          <div class="hint">Auto: adds <b>_</b> prefix  converts <b>x n z</b> ? <b>X N Z</b></div>
        </div>

        <hr class="section-sep">

        <!-- Row 3: timeout + time limit + pin -->
        <div class="grid3">
          <div class="row">
            <label class="lbl">Timeout (sec)</label>
            <input type="number" name="timeout" value="<?php echo (int)$timeout; ?>" min="1" />
          </div>
          <div class="row">
            <label class="lbl">Call Time Limit (sec)</label>
            <input type="number" name="time_limit_sec" value="<?php echo (int)$time_limit_sec; ?>" min="0" />
          </div>
          <div class="row">
            <label class="lbl">PIN (optional)</label>
            <input type="text" name="pin" value="<?php echo h($pin); ?>" placeholder="e.g. 1327" />
          </div>
        </div>

        <!-- Row 4: strip + prepend (2 cols only) -->
        <div class="grid2">
          <div class="row">
            <label class="lbl">Strip digits</label>
            <input type="number" name="strip" value="<?php echo (int)$strip; ?>" min="0" />
            <div class="hint">Remove N digits from the start of the dialled number</div>
          </div>
          <div class="row">
            <label class="lbl">Prepend</label>
            <input type="text" name="prepend" value="<?php echo h($prepend); ?>" placeholder="e.g. 0" />
            <div class="hint">Add a prefix after stripping</div>
          </div>
        </div>

        <hr class="section-sep">

        <!-- Trunks dual-listbox -->
        <div class="row">
          <label class="lbl">Trunks (order matters)</label>
          <div class="two">
            <div>
              <div class="hint" style="margin-bottom:6px;">Available</div>
              <select id="avail_trunks" multiple>
                <?php foreach ($trunks as $t): if (in_array($t, $sel_trunks, true)) continue; ?>
                  <option value="<?php echo h($t); ?>"><?php echo h($t); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="btncol">
              <button class="btn sm" type="button" onclick="moveSel('avail_trunks','sel_trunks')">&gt;&gt;</button>
              <button class="btn sm" type="button" onclick="moveSel('sel_trunks','avail_trunks')">&lt;&lt;</button>
              <button class="btn sm" type="button" onclick="moveUp('sel_trunks')">UP</button>
              <button class="btn sm" type="button" onclick="moveDown('sel_trunks')">DN</button>
            </div>
            <div>
              <div class="hint" style="margin-bottom:6px;">Selected</div>
              <select id="sel_trunks" name="trunks[]" multiple>
                <?php foreach ($sel_trunks as $t): ?>
                  <option value="<?php echo h($t); ?>"><?php echo h($t); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <!-- Whitelist Extensions -->
        <div class="row" id="whitelistBox">
          <label class="lbl">Whitelist Extensions</label>
          <div class="two">
            <div>
              <div class="hint" style="margin-bottom:6px;">Available</div>
              <select id="avail_whitelist" multiple>
                <?php foreach ($exts as $e): if (in_array($e, $whitelist, true)) continue; ?>
                  <option value="<?php echo h($e); ?>"><?php echo h($e); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="btncol">
              <button class="btn sm" type="button" onclick="moveSel('avail_whitelist','sel_whitelist')">&gt;&gt;</button>
              <button class="btn sm" type="button" onclick="moveSel('sel_whitelist','avail_whitelist')">&lt;&lt;</button>
            </div>
            <div>
              <div class="hint" style="margin-bottom:6px;">Selected</div>
              <select id="sel_whitelist" name="whitelist[]" multiple>
                <?php foreach ($whitelist as $e): ?>
                  <option value="<?php echo h($e); ?>"><?php echo h($e); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <!-- Groups -->
        <div class="row" id="groupsBox" style="display:none">
          <label class="lbl">Allowed Groups (Ring Groups)</label>
          <div class="two">
            <div>
              <div class="hint" style="margin-bottom:6px;">Available</div>
              <select id="avail_groups" multiple>
                <?php foreach ($groups as $g):
                  $gid = $g["id"]; $gnm = $g["name"];
                  if (in_array($gid, $sel_groups, true)) continue;
                ?>
                  <option value="<?php echo h($gid); ?>"><?php echo h($gid.($gnm?"  ".$gnm:"")); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="btncol">
              <button class="btn sm" type="button" onclick="moveSel('avail_groups','sel_groups')">&gt;&gt;</button>
              <button class="btn sm" type="button" onclick="moveSel('sel_groups','avail_groups')">&lt;&lt;</button>
            </div>
            <div>
              <div class="hint" style="margin-bottom:6px;">Selected</div>
              <select id="sel_groups" name="groups[]" multiple>
                <?php foreach ($sel_groups as $id): ?>
                  <option value="<?php echo h($id); ?>"><?php echo h($id); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <!-- Save -->
        <div class="form-actions">
          <button class="btn" type="submit">SAVE</button>
        </div>

      </form>
    </div><!-- /panel-box -->
  </div><!-- /inner-box -->
</div><!-- /wrap -->

<script>
function moveSel(fromId, toId) {
  const from = document.getElementById(fromId);
  const to   = document.getElementById(toId);
  Array.from(from.selectedOptions).forEach(opt => {
    to.add(new Option(opt.text, opt.value));
    opt.remove();
  });
}
function selectAll(id) {
  const s = document.getElementById(id);
  if (!s) return;
  Array.from(s.options).forEach(o => o.selected = true);
}
function moveUp(id) {
  const s = document.getElementById(id);
  for (let i = 1; i < s.options.length; i++) {
    const o = s.options[i];
    if (o.selected && !s.options[i-1].selected) s.insertBefore(o, s.options[i-1]);
  }
}
function moveDown(id) {
  const s = document.getElementById(id);
  for (let i = s.options.length - 2; i >= 0; i--) {
    const o = s.options[i];
    if (o.selected && !s.options[i+1].selected) s.insertBefore(s.options[i+1], o);
  }
}
function toggleMode() {
  const mode = document.getElementById('modeSel').value;
  document.getElementById('whitelistBox').style.display = mode === 'whitelist' ? '' : 'none';
  document.getElementById('groupsBox').style.display    = mode === 'groups'    ? '' : 'none';
}
toggleMode();
</script>
</body>
</html>