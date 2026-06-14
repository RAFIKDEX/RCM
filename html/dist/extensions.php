<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* ══════════════════════════════════════════
   CONFIG
══════════════════════════════════════════ */
$AMI_HOST = "127.0.0.1";
$AMI_PORT = 5038;
$AMI_USER = "guiuser";
$AMI_PASS = "admin";

const GUI_CONF = "/etc/asterisk/pjsip.gui.endpoint.conf";

/* ══════════════════════════════════════════
   HELPERS
══════════════════════════════════════════ */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

function clean_ua(string $ua): string {
    $ua = trim($ua);
    if ($ua === '' || $ua === '-') return '—';
    $ua = trim((string) preg_replace('/[\(;].*$/s', '', $ua));
    $ua = trim(explode('/', $ua)[0]);
    $tokens = (array) preg_split('/[\s\-]+/', $ua, -1, PREG_SPLIT_NO_EMPTY);
    $keep = [];
    foreach ($tokens as $tok) {
        if (preg_match('/^v?r?[\d][\d\.]*$/i', $tok))                 continue;
        if (preg_match('/^(build|rev|release|beta|alpha)\d*/i', $tok)) continue;
        $keep[] = $tok;
        if (count($keep) === 2) break;
    }
    $result = implode(' ', $keep);
    return $result !== '' ? $result : $ua;
}

/* ══════════════════════════════════════════
   AMI
══════════════════════════════════════════ */
function ami_connect(string $host, int $port) {
    $fp = @fsockopen($host, $port, $en, $es, 3);
    if (!$fp) { http_response_code(500); exit("AMI connect failed: $es ($en)"); }
    stream_set_timeout($fp, 4);
    return $fp;
}

function ami_cmd($fp, string $block): void {
    fwrite($fp, $block . "\r\n\r\n");
}

function ami_read_until($fp, string $marker): array {
    $lines = [];
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;
        $lines[] = rtrim($line, "\r\n");
        if (str_contains($line, $marker)) break;
    }
    return $lines;
}

function parse_ami_events(array $lines): array {
    $blocks = []; $cur = [];
    foreach ($lines as $ln) {
        if ($ln === '') {
            if ($cur) { $blocks[] = $cur; $cur = []; }
            continue;
        }
        $cur[] = $ln;
    }
    if ($cur) $blocks[] = $cur;
    $events = [];
    foreach ($blocks as $b) {
        $e = [];
        foreach ($b as $ln) {
            $p = strpos($ln, ':');
            if ($p === false) continue;
            $e[trim(substr($ln, 0, $p))] = trim(substr($ln, $p + 1));
        }
        if ($e) $events[] = $e;
    }
    return $events;
}

/* ══════════════════════════════════════════
   AMI REQUESTS
══════════════════════════════════════════ */
$fp = ami_connect($AMI_HOST, $AMI_PORT);

ami_cmd($fp, "Action: Login\r\nUsername: {$AMI_USER}\r\nSecret: {$AMI_PASS}");
fgets($fp); // consume login response

// 1) Endpoints — registration state
ami_cmd($fp, "Action: PJSIPShowEndpoints\r\nActionID: EP1");
$epRaw = parse_ami_events(ami_read_until($fp, "EndpointListComplete"));

// 2) Contacts — IP + UserAgent
ami_cmd($fp, "Action: PJSIPShowContacts\r\nActionID: CT1");
$ctRaw = parse_ami_events(ami_read_until($fp, "ContactListComplete"));

// 3) Active channels — real call state per extension
//    ChannelStateDesc: Down / Ringing / Ring / Dialing / Up / Busy
//    Application: MusicOnHold / Park → On Hold
ami_cmd($fp, "Action: CoreShowChannels\r\nActionID: CH1");
$chRaw = parse_ami_events(ami_read_until($fp, "CoreShowChannelsComplete"));

ami_cmd($fp, "Action: Logoff");
fclose($fp);

/* ══════════════════════════════════════════
   BUILD CHANNEL STATE MAP  ext → status
   Priority: busy > onhold > ringing
══════════════════════════════════════════ */
$chanState = []; // ext => 'busy' | 'ringing' | 'onhold'

foreach ($chRaw as $e) {
    if (($e['Event'] ?? '') !== 'CoreShowChannel') continue;

    $ch = $e['Channel'] ?? '';
    // match PJSIP/EXTNUMBER-xxxx  or  SIP/EXTNUMBER-xxxx
    if (!preg_match('/^(?:PJSIP|SIP)\/(\d{2,6})-/i', $ch, $m)) continue;
    $ext = $m[1];

    $stateDesc   = strtolower($e['ChannelStateDesc'] ?? '');
    $application = strtolower($e['Application']      ?? '');

    // On Hold: MusicOnHold or Parked
    if ($application === 'musiconhold' || str_contains($application, 'park')) {
        if (($chanState[$ext] ?? '') !== 'busy')
            $chanState[$ext] = 'onhold';
        continue;
    }

    // Busy / Up = active call
    if ($stateDesc === 'up' || $stateDesc === 'busy') {
        $chanState[$ext] = 'busy';
        continue;
    }

    // Ringing — both caller (Dialing) and callee (Ringing/Ring)
    if (in_array($stateDesc, ['ringing', 'ring', 'dialing', 'pre-ring', 'rsrvd'], true)) {
        if (($chanState[$ext] ?? '') !== 'busy')
            $chanState[$ext] = 'ringing';
        continue;
    }
}

/* ══════════════════════════════════════════
   BUILD ENDPOINT DATA
══════════════════════════════════════════ */
$all = [];

foreach ($epRaw as $e) {
    if (($e['Event'] ?? '') !== 'EndpointList' || ($e['ActionID'] ?? '') !== 'EP1') continue;
    $ext = trim($e['ObjectName'] ?? '');
    if ($ext === '') continue;
    $all[$ext] = [
        'registered' => trim($e['Contacts'] ?? '') !== '',
        'contacts'   => [],
    ];
}

foreach ($ctRaw as $e) {
    if (($e['Event'] ?? '') !== 'ContactList' || ($e['ActionID'] ?? '') !== 'CT1') continue;
    $ext = trim($e['Endpoint'] ?? '');
    if ($ext === '') continue;
    if (!isset($all[$ext])) $all[$ext] = ['registered' => true, 'contacts' => []];
    $ip   = trim($e['ViaAddr']    ?? '');
    $port = trim($e['ViaPort']    ?? '');
    $ua   = trim($e['UserAgent']  ?? '');
    $all[$ext]['registered'] = true;
    $all[$ext]['contacts'][] = [
        'ipport'   => ($ip && $port) ? "$ip:$port" : ($ip ?: '—'),
        'ua_raw'   => $ua ?: '—',
        'ua_clean' => clean_ua($ua),
    ];
}

/* ══════════════════════════════════════════
   FILTER — GUI extensions only, no trunks
══════════════════════════════════════════ */
function load_gui_conf(string $path): array {
    if (!file_exists($path)) return ['extensions' => [], 'trunks' => [], 'callerids' => []];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $exts  = []; $trunks = []; $cids = []; $cur = null;
    foreach ($lines as $ln) {
        $t = trim($ln);
        if (preg_match('/;\s*---\s*RCM-TRUNK:\s*([A-Za-z0-9_\-]+)\s*BEGIN/i', $t, $m)) {
            $trunks[$m[1]] = true; continue;
        }
        if (preg_match('/^\[(\d{2,6})\]$/', $t, $m)) { $cur = $m[1]; $exts[] = $m[1]; continue; }
        if ($cur && preg_match('/^callerid\s*=\s*(.+)$/i', $t, $m)) {
            $v = trim($m[1]);
            $cids[$cur] = preg_match('/^"?([^"<]+)"?\s*(?:<[^>]*>)?$/', $v, $nm)
                ? trim($nm[1], ' "') : $v;
        }
    }
    return ['extensions' => array_unique($exts), 'trunks' => $trunks, 'callerids' => $cids];
}

$gui       = load_gui_conf(GUI_CONF);
$guiExtSet = array_fill_keys($gui['extensions'], true);
$trunkSet  = $gui['trunks'];
$callerIds = $gui['callerids'];

$filtered = [];
foreach ($all as $ext => $info) {
    if (isset($trunkSet[$ext])) continue;
    if (!preg_match('/^\d{2,6}$/', (string)$ext)) continue;
    $filtered[$ext] = $info;
}
ksort($filtered, SORT_NATURAL);

/* ══════════════════════════════════════════
   STATISTICS
══════════════════════════════════════════ */
$total   = count($filtered);
$reg     = 0; $busy = 0; $ringing = 0; $onhold = 0;
foreach ($filtered as $ext => $info) {
    if (!empty($info['registered'])) $reg++;
    $cs = $chanState[$ext] ?? '';
    if ($cs === 'busy')    $busy++;
    if ($cs === 'ringing') $ringing++;
    if ($cs === 'onhold')  $onhold++;
}
$unreg = $total - $reg;
$inUse = $busy + $ringing + $onhold;

/* ══════════════════════════════════════════
   BADGE FACTORIES
══════════════════════════════════════════ */
function reg_badge(bool $r): string {
    return $r
        ? '<span class="badge b-green"><span class="dot d-green"></span>YES</span>'
        : '<span class="badge b-red"><span class="dot d-red"></span>NO</span>';
}

function status_badge(string $cs, bool $reg): string {
    switch ($cs) {
        case 'busy':
            return '<span class="badge b-blue"><span class="dot d-blue"></span>Busy</span>';
        case 'onhold':
            return '<span class="badge b-purple"><span class="dot d-purple"></span>On Hold</span>';
        case 'ringing':
            return '<span class="badge b-orange"><span class="dot d-orange"></span>Ringing</span>';
    }
    return $reg
        ? '<span class="badge b-green"><span class="dot d-green"></span>Reachable</span>'
        : '<span class="badge b-red"><span class="dot d-red"></span>Unreachable</span>';
}

function row_search(string $ext, array $info, string $cs, array $cids): string {
    $p = [$ext, strtolower($cids[$ext] ?? '')];
    foreach ($info['contacts'] as $c) {
        $p[] = strtolower($c['ipport']);
        $p[] = strtolower($c['ua_raw']);
        $p[] = strtolower($c['ua_clean']);
    }
    switch ($cs) {
        case 'busy':   $p[] = 'busy';               break;
        case 'onhold': $p[] = 'onhold on hold hold'; break;
        case 'ringing':$p[] = 'ringing';             break;
        default:
            $p[] = !empty($info['registered']) ? 'reachable idle' : 'unreachable';
    }
    $p[] = !empty($info['registered']) ? 'registered yes' : 'unregistered no';
    return implode(' ', array_filter($p));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="refresh" content="5">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Extensions</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">
  <style>
    body  { display:block !important; }
    .wrap { width:min(1380px,96vw); margin:36px auto 80px; }

    .topbar {
      display:flex; align-items:center; justify-content:space-between;
      gap:14px; flex-wrap:wrap; margin-bottom:24px;
    }
    .topbar .page-title {
      flex:1; text-align:center; font-family:'Orbitron',sans-serif;
      font-weight:900; letter-spacing:7px; text-transform:uppercase;
      font-size:clamp(20px,4vw,40px); pointer-events:none;
      text-shadow:0 0 30px rgba(255,255,255,.22); margin:0;
    }

    .stats {
      display:grid; grid-template-columns:repeat(4,1fr);
      gap:14px; margin-bottom:20px;
    }
    .stat {
      background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);
      border-radius:20px; padding:18px 22px;
      display:flex; flex-direction:column; gap:8px; transition:background .2s;
    }
    .stat:hover { background:rgba(255,255,255,.09); }
    .stat .k {
      font-size:10px; font-family:'Orbitron',sans-serif;
      letter-spacing:2.5px; text-transform:uppercase; opacity:.6;
    }
    .stat .v { font-family:'Orbitron',sans-serif; font-size:32px; font-weight:900; line-height:1; }
    .stat .sub { font-size:11px; opacity:.45; margin-top:2px; }

    .search-wrap { margin-bottom:18px; }
    .search-bar {
      display:flex; align-items:center; gap:10px;
      background:rgba(255,255,255,.07); border:1.5px solid rgba(255,255,255,.16);
      border-radius:50px; padding:11px 20px;
      transition:border-color .2s, background .2s;
    }
    .search-bar:focus-within {
      border-color:rgba(255,255,255,.42); background:rgba(255,255,255,.11);
    }
    .search-bar svg { flex-shrink:0; opacity:.45; }
    .search-bar input {
      flex:1; background:none; border:none; outline:none;
      color:#fff; font-size:14px; font-family:inherit;
    }
    .search-bar input::placeholder { color:rgba(255,255,255,.35); }
    #searchCount {
      font-size:11px; opacity:.5; white-space:nowrap;
      font-family:'Orbitron',sans-serif; letter-spacing:1px;
    }
    .clr-btn {
      background:none; border:none; color:#fff; opacity:.4;
      font-size:20px; cursor:pointer; line-height:1; padding:0 2px; transition:opacity .15s;
    }
    .clr-btn:hover { opacity:.9; }

    .table-card {
      border-radius:20px; border:1px solid rgba(255,255,255,.10);
      overflow:hidden; background:rgba(255,255,255,.03);
    }
    .table-scroll { width:100%; overflow-x:auto; }
    table { width:100%; border-collapse:collapse; min-width:860px; }
    thead { background:rgba(255,255,255,.07); }
    thead tr { border-bottom:2px solid rgba(255,255,255,.12); }
    th {
      font-family:'Orbitron',sans-serif; font-size:10px; letter-spacing:2.5px;
      text-transform:uppercase; color:rgba(255,255,255,.6);
      padding:16px 18px; text-align:left; white-space:nowrap;
      border-right:1px solid rgba(255,255,255,.06);
    }
    th:last-child { border-right:none; }
    td {
      padding:14px 18px; vertical-align:middle;
      border-bottom:1px solid rgba(255,255,255,.06);
      border-right:1px solid rgba(255,255,255,.04);
    }
    td:last-child { border-right:none; }
    tbody tr:last-child td { border-bottom:none; }
    tbody tr { transition:background .15s; }
    tbody tr:hover { background:rgba(255,255,255,.05); }
    tbody tr.hidden-row { display:none; }

    .ext-num  {
      font-family:'Orbitron',sans-serif; font-size:16px;
      font-weight:900; letter-spacing:2px; color:#fff; white-space:nowrap;
    }
    .ext-name { font-size:12px; margin-top:4px; color:rgba(255,255,255,.5); white-space:nowrap; }

    .ip-stack { display:flex; flex-direction:column; gap:5px; }
    .ip-chip  {
      display:inline-block; font-family:monospace; font-size:12px;
      background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
      border-radius:8px; padding:4px 10px; white-space:nowrap; letter-spacing:.5px;
    }
    .ip-chip.empty { opacity:.3; }

    .ua-stack { display:flex; flex-direction:column; gap:4px; }
    .ua-item  {
      font-size:13px; color:rgba(255,255,255,.75); white-space:nowrap;
      overflow:hidden; text-overflow:ellipsis; max-width:180px; cursor:default;
    }
    .ua-empty { font-size:13px; color:rgba(255,255,255,.25); }

    .act-cell { white-space:nowrap; }
    .act-btn  {
      display:inline-block; padding:7px 16px; border-radius:30px;
      font-size:11px; font-family:'Orbitron',sans-serif; letter-spacing:1px;
      text-transform:uppercase; text-decoration:none; cursor:pointer;
      transition:all .18s; white-space:nowrap; margin-right:6px;
    }
    .act-edit {
      background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.20);
      color:rgba(255,255,255,.85);
    }
    .act-edit:hover {
      background:rgba(255,255,255,.18); border-color:rgba(255,255,255,.5); color:#fff;
    }
    .act-del {
      background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); color:#fca5a5;
    }
    .act-del:hover {
      background:rgba(239,68,68,.25); border-color:rgba(239,68,68,.7); color:#fff;
    }
    .act-none { opacity:.25; font-size:12px; }

    .no-results {
      text-align:center; padding:50px 20px; opacity:.35;
      font-family:'Orbitron',sans-serif; letter-spacing:2px;
      font-size:12px; text-transform:uppercase;
    }

    .dot {
      display:inline-block; width:8px; height:8px; border-radius:50%;
      flex-shrink:0; vertical-align:middle; margin-right:6px;
    }
    .d-green  { background:#22c55e; box-shadow:0 0 6px 2px rgba(34,197,94,.65); }
    .d-red    { background:#ef4444; box-shadow:0 0 6px 2px rgba(239,68,68,.65); }
    .d-blue   { background:#3b82f6; box-shadow:0 0 6px 2px rgba(59,130,246,.65); }
    .d-orange { background:#f97316; box-shadow:0 0 6px 2px rgba(249,115,22,.65); }
    .d-purple { background:#a855f7; box-shadow:0 0 6px 2px rgba(168,85,247,.65); }
    .d-gray   { background:#6b7280; box-shadow:0 0 4px 2px rgba(107,114,128,.4); }

    .badge {
      display:inline-flex; align-items:center;
      padding:4px 12px 4px 8px; border-radius:30px;
      font-size:11px; font-weight:700; font-family:'Orbitron',sans-serif;
      letter-spacing:.8px; text-transform:uppercase; white-space:nowrap;
    }
    .b-green  { background:rgba(34,197,94,.13);  color:#86efac; border:1px solid rgba(34,197,94,.28); }
    .b-red    { background:rgba(239,68,68,.13);  color:#fca5a5; border:1px solid rgba(239,68,68,.28); }
    .b-blue   { background:rgba(59,130,246,.13); color:#93c5fd; border:1px solid rgba(59,130,246,.28); }
    .b-orange { background:rgba(249,115,22,.13); color:#fdba74; border:1px solid rgba(249,115,22,.28); }
    .b-purple { background:rgba(168,85,247,.13); color:#d8b4fe; border:1px solid rgba(168,85,247,.28); }
    .b-gray   { background:rgba(107,114,128,.13);color:#d1d5db; border:1px solid rgba(107,114,128,.28); }

    @media(max-width:960px){ .stats { grid-template-columns:repeat(2,1fr); } }
    @media(max-width:600px){
      .stats { grid-template-columns:1fr 1fr; }
      th:nth-child(5),td:nth-child(5) { display:none; }
    }
  </style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <a class="btn" href="/dashboard.php">BACK</a>
    <a class="btn" href="/add_extension.php">ADD</a>
    <span class="page-title">Extensions</span>
    <a class="btn" href="/bulk_add_extensions.php">BULK ADD</a>
  </div>

  <div class="inner-box" style="margin-top:0;">
    <div class="panel-box" style="margin-top:0; padding:24px;">

      <!-- STATS -->
      <div class="stats">
        <div class="stat">
          <div class="k">Total</div>
          <div class="v" id="sTotal"><?php echo $total; ?></div>
          <div class="sub">extensions</div>
        </div>
        <div class="stat">
          <div class="k">Registered</div>
          <div class="v" id="sReg" style="color:#22c55e;"><?php echo $reg; ?></div>
          <div class="sub">online</div>
        </div>
        <div class="stat">
          <div class="k">Unregistered</div>
          <div class="v" id="sUnreg" style="color:#ef4444;"><?php echo $unreg; ?></div>
          <div class="sub">offline</div>
        </div>
        <div class="stat">
          <div class="k">In Use</div>
          <div class="v" id="sBusy" style="color:#3b82f6;"><?php echo $inUse; ?></div>
          <div class="sub">busy / ringing / hold</div>
        </div>
      </div>

      <!-- SEARCH -->
      <div class="search-wrap">
        <div class="search-bar">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.2">
            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
          </svg>
          <input id="q" type="text" autocomplete="off" spellcheck="false"
                 placeholder="Search by number, name, IP, status… (press / to focus)">
          <span id="searchCount"></span>
          <button class="clr-btn" id="qClr" title="Clear (Esc)" style="display:none">×</button>
        </div>
      </div>

      <!-- TABLE -->
      <div class="table-card">
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th style="width:130px">Extension</th>
                <th style="width:110px">Registered</th>
                <th>IP : Port</th>
                <th style="width:145px">Status</th>
                <th>User Agent</th>
                <th style="width:165px">Actions</th>
              </tr>
            </thead>
            <tbody id="tbody">

<?php if (empty($filtered)): ?>
  <tr><td colspan="6" class="no-results">No extensions found</td></tr>
<?php else: foreach ($filtered as $ext => $info):
    $contacts = $info['contacts'];
    $isReg    = !empty($info['registered']);
    $name     = $callerIds[$ext] ?? '';
    $inGui    = isset($guiExtSet[$ext]);
    $cs       = $chanState[$ext] ?? '';
    $search   = row_search($ext, $info, $cs, $callerIds);
?>
  <tr data-search="<?php echo h($search); ?>">

    <td>
      <div class="ext-num"><?php echo h($ext); ?></div>
      <?php if ($name !== ''): ?>
        <div class="ext-name"><?php echo h($name); ?></div>
      <?php endif; ?>
    </td>

    <td><?php echo reg_badge($isReg); ?></td>

    <td>
      <?php if (empty($contacts)): ?>
        <span class="ip-chip empty">—</span>
      <?php else: ?>
        <div class="ip-stack">
          <?php foreach ($contacts as $c): ?>
            <span class="ip-chip"><?php echo h($c['ipport']); ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </td>

    <td><?php echo status_badge($cs, $isReg); ?></td>

    <td>
      <?php if (empty($contacts)): ?>
        <span class="ua-empty">—</span>
      <?php else: ?>
        <div class="ua-stack">
          <?php foreach ($contacts as $c): ?>
            <span class="ua-item" title="<?php echo h($c['ua_raw']); ?>">
              <?php echo h($c['ua_clean']); ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </td>

    <td class="act-cell">
      <?php if ($inGui): ?>
        <a class="act-btn act-edit"
           href="/edit_extension.php?ext=<?php echo urlencode($ext); ?>">Edit</a>
        <a class="act-btn act-del"
           href="/delete_extension.php?ext=<?php echo urlencode($ext); ?>"
           onclick="return confirm('Delete extension <?php echo h($ext); ?>?')">Delete</a>
      <?php else: ?>
        <span class="act-none">—</span>
      <?php endif; ?>
    </td>

  </tr>
<?php endforeach; endif; ?>

            </tbody>
          </table>
          <div class="no-results" id="noRes" style="display:none;">No results found</div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  const qInput = document.getElementById('q');
  const qClr   = document.getElementById('qClr');
  const qCount = document.getElementById('searchCount');
  const noRes  = document.getElementById('noRes');
  const rows   = [...document.querySelectorAll('#tbody tr[data-search]')];

  const ORIG = {
    total : <?php echo $total; ?>,
    reg   : <?php echo $reg; ?>,
    unreg : <?php echo $unreg; ?>,
    inuse : <?php echo $inUse; ?>
  };

  function setStats(t, r, u, b) {
    document.getElementById('sTotal').textContent = t;
    document.getElementById('sReg').textContent   = r;
    document.getElementById('sUnreg').textContent = u;
    document.getElementById('sBusy').textContent  = b;
  }

  function recalcStats(visible) {
    let r = 0, u = 0, b = 0;
    visible.forEach(tr => {
      const s = tr.dataset.search;
      if (s.includes('registered yes')) r++; else u++;
      if (s.includes('busy') || s.includes('ringing') || s.includes('onhold')) b++;
    });
    setStats(visible.length, r, u, b);
  }

  function search() {
    const raw = qInput.value.trim().toLowerCase();
    qClr.style.display = raw ? 'inline' : 'none';

    if (!raw) {
      rows.forEach(r => r.classList.remove('hidden-row'));
      qCount.textContent = '';
      noRes.style.display = 'none';
      setStats(ORIG.total, ORIG.reg, ORIG.unreg, ORIG.inuse);
      return;
    }

    const terms   = raw.split(/\s+/).filter(Boolean);
    const visible = [];
    rows.forEach(tr => {
      const hit = terms.every(t => tr.dataset.search.includes(t));
      tr.classList.toggle('hidden-row', !hit);
      if (hit) visible.push(tr);
    });

    const n = visible.length;
    qCount.textContent = n + (n === 1 ? ' result' : ' results');
    noRes.style.display = n === 0 ? 'block' : 'none';
    recalcStats(visible);
  }

  qInput.addEventListener('input', search);
  qClr.addEventListener('click', () => { qInput.value = ''; search(); qInput.focus(); });

  document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement !== qInput && e.target.tagName !== 'INPUT') {
      e.preventDefault(); qInput.focus(); qInput.select();
    }
    if (e.key === 'Escape' && document.activeElement === qInput) {
      qInput.value = ''; search(); qInput.blur();
    }
  });
})();
</script>
</body>
</html>