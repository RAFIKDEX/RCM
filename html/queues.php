<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$QUEUE_CONF = "/etc/asterisk/queues.conf";

/* ================= AMI CONFIG ================= */
$AMI_HOST = "127.0.0.1";
$AMI_PORT = 5038;
$AMI_USER = "guiuser";
$AMI_PASS = "admin";

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function read_file_safe(string $path): string {
    return is_file($path) ? (string)file_get_contents($path) : "";
}

/* ================= QUEUES.CONF PARSING ================= */

function extract_managed_queue_block(string $text): string {
    if (preg_match('/; BEGIN RCM QUEUE GUI(.*?); END RCM QUEUE GUI/s', $text, $m)) {
        return trim((string)$m[1]);
    }
    return "";
}

function parse_ini_like_sections(string $text): array {
    $sections = [];
    $current = null;

    $lines = preg_split("/\r\n|\n|\r/", $text);
    foreach ($lines as $line) {
        $raw  = rtrim((string)$line);
        $trim = trim($raw);

        if ($trim === "") {
            if ($current !== null) {
                $sections[$current]["_lines"][] = $raw;
            }
            continue;
        }

        if (preg_match('/^\[([^\]]+)\]$/', $trim, $m)) {
            $current = trim($m[1]);
            if (!isset($sections[$current])) {
                $sections[$current] = [
                    "_lines"   => [],
                    "_kv"      => [],
                    "_members" => [],
                ];
            }
            continue;
        }

        if ($current === null) {
            continue;
        }

        $sections[$current]["_lines"][] = $raw;

        if (preg_match('/^member\s*=>\s*(.+)$/i', $trim, $m)) {
            $sections[$current]["_members"][] = trim($m[1]);
            continue;
        }

        if (preg_match('/^([a-zA-Z0-9_-]+)\s*=\s*(.*)$/', $trim, $m)) {
            $k = strtolower(trim($m[1]));
            $v = trim($m[2]);
            $sections[$current]["_kv"][$k] = $v;
        }
    }

    return $sections;
}

function parse_queue_display_name(array $lines, string $fallback): string {
    foreach ($lines as $line) {
        $trim = trim((string)$line);
        if (preg_match('/^[;#]\s*RCM_NAME\s*=\s*(.+)$/i', $trim, $m)) {
            $name = trim($m[1]);
            if ($name !== "") {
                return $name;
            }
        }
    }
    return $fallback;
}

function normalize_member_name(string $raw): string {
    $raw = trim($raw);
    $raw = preg_replace('/\s*[;#].*$/', '', $raw);
    $raw = trim((string)$raw);

    if ($raw === '') return '';

    // PJSIP/2121
    if (preg_match('/^PJSIP\/([A-Za-z0-9_.-]+)$/i', $raw, $m)) {
        return $m[1];
    }

    // Local/5000@from-queue/n
    if (preg_match('/^Local\/([^@\/]+)/i', $raw, $m)) {
        return $m[1];
    }

    return $raw;
}

function parse_gui_queues_only(string $queueConfText): array {
    $managed = extract_managed_queue_block($queueConfText);
    if ($managed === '') {
        return [];
    }

    $sections = parse_ini_like_sections($managed);
    $queues = [];

    foreach ($sections as $sectionName => $data) {
        if (!preg_match('/^[0-9]{2,6}$/', $sectionName)) {
            continue;
        }

        $kv      = $data["_kv"] ?? [];
        $members = $data["_members"] ?? [];
        $lines   = $data["_lines"] ?? [];

        $staticAgents = [];
        foreach ($members as $m) {
            $n = normalize_member_name((string)$m);
            if ($n !== '') {
                $staticAgents[$n] = [
                    'name'       => $n,
                    'type'       => 'S',
                    'raw'        => $m,
                    'status_num' => null,
                    'paused'     => false,
                    'in_call'    => false,
                    'state'      => 'unknown',
                ];
            }
        }

        $displayName = parse_queue_display_name($lines, $sectionName);

        $queues[$sectionName] = [
            "queue_name"   => $displayName,
            "queue_number" => $sectionName,
            "strategy"     => $kv["strategy"] ?? "",
            "moh"          => $kv["musicclass"] ?? "",
            "static_agents"=> $staticAgents,
            "agents"       => $staticAgents,
        ];
    }

    uksort($queues, fn($a, $b) => strnatcasecmp($a, $b));
    return $queues;
}

/* ================= AMI HELPERS ================= */

function ami_connect($host, $port) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 3);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 3);
    return $fp;
}

function ami_write($fp, $data): void {
    fwrite($fp, $data);
}

function ami_login($fp, $user, $pass): void {
    ami_write($fp, "Action: Login\r\nUsername: {$user}\r\nSecret: {$pass}\r\n\r\n");
}

function ami_action($fp, $actionBlock): void {
    ami_write($fp, $actionBlock . "\r\n\r\n");
}

function ami_read_until_event($fp, $eventName): array {
    $lines = [];
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;
        $line = rtrim($line, "\r\n");
        $lines[] = $line;
        if (strpos($line, "Event: {$eventName}") !== false) break;
    }
    return $lines;
}

function parse_ami_blocks(array $lines): array {
    $blocks = [];
    $cur = [];

    foreach ($lines as $ln) {
        if ($ln === "") {
            if ($cur) {
                $blocks[] = $cur;
                $cur = [];
            }
            continue;
        }
        $cur[] = $ln;
    }
    if ($cur) $blocks[] = $cur;

    $events = [];
    foreach ($blocks as $b) {
        $evt = [];
        foreach ($b as $ln) {
            $pos = strpos($ln, ":");
            if ($pos === false) continue;
            $k = trim(substr($ln, 0, $pos));
            $v = trim(substr($ln, $pos + 1));
            $evt[$k] = $v;
        }
        if ($evt) $events[] = $evt;
    }

    return $events;
}

function fetch_ami_queue_members(): array {
    global $AMI_HOST, $AMI_PORT, $AMI_USER, $AMI_PASS;

    $fp = ami_connect($AMI_HOST, $AMI_PORT);
    if (!$fp) {
        return [];
    }

    ami_login($fp, $AMI_USER, $AMI_PASS);
    ami_action($fp, "Action: QueueStatus\r\nActionID: QWEB");
    $lines = ami_read_until_event($fp, "QueueStatusComplete");
    ami_action($fp, "Action: Logoff");
    fclose($fp);

    $events = parse_ami_blocks($lines);

    $result = []; // queue => list of members

    foreach ($events as $e) {
        if (($e["Event"] ?? "") !== "QueueMember") {
            continue;
        }

        $queue = trim((string)($e["Queue"] ?? ""));
        if ($queue === '') {
            continue;
        }

        $name = trim((string)($e["Name"] ?? ""));
        $location = trim((string)($e["Location"] ?? ""));
        $membership = strtolower(trim((string)($e["Membership"] ?? "")));

        $normalized = normalize_member_name($location);
        if ($normalized === '') {
            $normalized = normalize_member_name($name);
        }
        if ($normalized === '') {
            continue;
        }

        $type = ($membership === 'dynamic') ? 'D' : 'S';

        $result[$queue][] = [
            'name'       => $normalized,
            'type'       => $type,
            'raw'        => $location !== '' ? $location : $name,
            'status_num' => (int)($e["Status"] ?? 0),
            'paused'     => ((string)($e["Paused"] ?? "0") === "1"),
            'in_call'    => ((string)($e["InCall"] ?? "0") === "1"),
            'state'      => agent_state_slug((int)($e["Status"] ?? 0), ((string)($e["Paused"] ?? "0") === "1"), ((string)($e["InCall"] ?? "0") === "1")),
        ];
    }

    return $result;
}

/* ================= AGENT STATUS ================= */

function member_status_label($statusNum): string {
    $n = (int)$statusNum;
    $map = [
        0 => "Unknown",
        1 => "Not in use",
        2 => "In use",
        3 => "Busy",
        4 => "Invalid",
        5 => "Unavailable",
        6 => "Ringing",
        7 => "Ring+InUse",
        8 => "On Hold",
    ];
    return $map[$n] ?? ("Status " . $n);
}

function agent_state_slug(int $statusNum, bool $paused, bool $inCall): string {
    if ($paused) return 'paused';
    if ($inCall) return 'incall';

    switch ($statusNum) {
        case 1:
            return 'available';
        case 2:
        case 6:
        case 7:
        case 8:
            return 'incall';
        case 3:
        case 4:
        case 5:
            return 'busy';
        default:
            return 'unknown';
    }
}

function agent_state_title(array $agent): string {
    if (!empty($agent['paused'])) {
        return 'Paused';
    }

    $label = member_status_label((int)($agent['status_num'] ?? 0));
    if (!empty($agent['in_call'])) {
        return $label . ' / In Call';
    }

    return $label;
}

function agent_css_class(array $agent): string {
    $state = (string)($agent['state'] ?? 'unknown');
    switch ($state) {
        case 'available': return 'available';
        case 'incall':    return 'incall';
        case 'busy':      return 'busy';
        case 'paused':    return 'paused';
        default:          return 'unknown';
    }
}

/* ================= BUILD PAGE DATA ================= */

$queueConfText = read_file_safe($QUEUE_CONF);
$queues = parse_gui_queues_only($queueConfText);

/*
  نضيف الدايناميك من AMI
  ونحدّث حالة الاستاتيك من AMI لو موجود
*/
$amiMembers = fetch_ami_queue_members();

foreach ($queues as $queueNumber => &$queue) {
    $queueKey = $queue['queue_number'];

    if (!isset($amiMembers[$queueKey]) || !is_array($amiMembers[$queueKey])) {
        continue;
    }

    foreach ($amiMembers[$queueKey] as $member) {
        $agentName = $member['name'];

        if (isset($queue['agents'][$agentName])) {
            // لو الاستاتيك موجود في الكونف، حدّث حالته فقط
            $queue['agents'][$agentName]['status_num'] = $member['status_num'];
            $queue['agents'][$agentName]['paused']     = $member['paused'];
            $queue['agents'][$agentName]['in_call']    = $member['in_call'];
            $queue['agents'][$agentName]['state']      = $member['state'];

            // لو ظهر من AMI إنه dynamic فعلاً، نبدّل النوع
            if (($member['type'] ?? 'S') === 'D') {
                $queue['agents'][$agentName]['type'] = 'D';
            }
        } else {
            // agent dynamic logged-in ومش static في الكونف
            $queue['agents'][$agentName] = $member;
        }
    }

    uasort($queue['agents'], function($a, $b){
        if (($a['type'] ?? 'S') !== ($b['type'] ?? 'S')) {
            return (($a['type'] ?? 'S') === 'S') ? -1 : 1; // static first
        }
        return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
}
unset($queue);

$totalQueues = count($queues);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Queues</title>

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/rcm.css">
<link rel="icon" type="image/png" href="/assets/rcm/logo.png">

<style>
html, body{ height:auto !important; }
body{
  display:block !important;
  align-items:initial !important;
  justify-content:initial !important;
}
.wrap{
  width:min(1500px, 96vw);
  margin:0 auto 60px auto;
}
.q-topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:10px;
}
.search-row{
  display:flex;
  gap:12px;
  align-items:center;
  flex-wrap:wrap;
  margin-top:14px;
}
.search-row input{
  max-width:360px;
}
.small-note{
  font-size:12px;
  opacity:.85;
}
.mono{
  font-family:'Orbitron',sans-serif;
  letter-spacing:1px;
}
.agent-list{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
}
.agent-pill{
  position:relative;
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:7px 11px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.05);
  font-size:12px;
  white-space:nowrap;
}
.agent-type{
  font-family:'Orbitron',sans-serif;
  font-size:11px;
  line-height:1;
  padding:2px 6px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.20);
  background:rgba(255,255,255,.06);
}
.agent-type.static{
  background:rgba(80,150,255,.16);
  border-color:rgba(80,150,255,.45);
}
.agent-type.dynamic{
  background:rgba(50,220,140,.16);
  border-color:rgba(50,220,140,.45);
}
.agent-dot{
  width:10px;
  height:10px;
  border-radius:50%;
  display:inline-block;
  box-shadow:0 0 10px rgba(255,255,255,.15);
}
.agent-pill.available .agent-dot{
  background:#52d273;
}
.agent-pill.incall .agent-dot{
  background:#ffad33;
}
.agent-pill.busy .agent-dot{
  background:#ff6363;
}
.agent-pill.paused .agent-dot{
  background:#b07cff;
}
.agent-pill.unknown .agent-dot{
  background:#8b98a9;
}
.table-wrap{
  overflow:auto;
}
.actions-stack{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
}
.legend{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  margin-top:8px;
}
.legend .pill{
  font-size:12px;
}
.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px 0;
}

.legend .pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: 999px;
  border: 1px solid;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.03em;
}
</style>

<script>
function filterQueues(){
  const v = (document.getElementById('qSearch').value || '').toLowerCase();
  document.querySelectorAll('[data-row]').forEach(el => {
    const txt = (el.getAttribute('data-row') || '').toLowerCase();
    el.style.display = txt.includes(v) ? '' : 'none';
  });
}
</script>
</head>
<body>

<div class="wrap">

  <div class="q-topbar">
    <a class="btn" href="/dashboard.php">BACK</a>
   
    <h1 class="page-title" style="margin:0;font-size:54px;">QUEUES</h1>
    <div class="btn-row">
      <a class="btn" href="/queue.php">QUEUE MONITOR</a>
         <a class="btn" href="/stats.php">Q Statistics</a>
           <a class="btn" href="/add_queue.php">ADD NEW</a>
    </div>
  </div>

  <div class="inner-box" style="margin-top:0;">
    <div class="top">
      <span class="pill">TOTAL QUEUES: <?php echo (int)$totalQueues; ?></span>
    </div>

    <div class="search-row">
      <label style="margin:0;">Search</label>
      <input id="qSearch" type="text" placeholder="type queue number / name..." oninput="filterQueues()">
      <span class="small-note muted">new gui queues only</span>
    </div>

 <div class="legend">
  <span class="pill" style="color:#94a3b8; border-color:#94a3b822; background:#94a3b810;">
    <span style="width:9px;height:9px;border-radius:50%;background:#94a3b8;box-shadow:0 0 6px #94a3b8;display:inline-block;"></span>
    S = STATIC
  </span>
  <span class="pill" style="color:#94a3b8; border-color:#94a3b822; background:#94a3b810;">
    <span style="width:9px;height:9px;border-radius:50%;background:#94a3b8;box-shadow:0 0 6px #94a3b8;display:inline-block;"></span>
    D = DYNAMIC
  </span>
  <span class="pill" style="color:#22c55e; border-color:#22c55e22; background:#22c55e10;">
    <span style="width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 6px #22c55e;display:inline-block;"></span>
    AVAILABLE
  </span>
  <span class="pill" style="color:#f97316; border-color:#f9731622; background:#f9731610;">
    <span style="width:9px;height:9px;border-radius:50%;background:#f97316;box-shadow:0 0 6px #f97316;display:inline-block;"></span>
    IN CALL / RINGING
  </span>
  <span class="pill" style="color:#ef4444; border-color:#ef444422; background:#ef444410;">
    <span style="width:9px;height:9px;border-radius:50%;background:#ef4444;box-shadow:0 0 6px #ef4444;display:inline-block;"></span>
    BUSY / UNAVAILABLE
  </span>
  <span class="pill" style="color:#a855f7; border-color:#a855f722; background:#a855f710;">
    <span style="width:9px;height:9px;border-radius:50%;background:#a855f7;box-shadow:0 0 6px #a855f7;display:inline-block;"></span>
    PAUSED
  </span>
</div>

    <div class="panel-box" style="margin-top:14px;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>QUEUE</th>
              <th>NUMBER</th>
              <th>STRATEGY</th>
              <th>MOH</th>
              <th>AGENTS (S/D)</th>
              <th>ACTIONS</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$queues): ?>
            <tr>
              <td colspan="6" class="muted">No new GUI queues configured.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($queues as $q): ?>
              <?php
                $searchParts = [
                    $q["queue_name"] ?? "",
                    $q["queue_number"] ?? "",
                    $q["strategy"] ?? "",
                    $q["moh"] ?? "",
                ];

                foreach (($q["agents"] ?? []) as $agent) {
                    $searchParts[] = $agent['name'] ?? '';
                    $searchParts[] = $agent['type'] ?? '';
                }

                $rowText = trim(implode(' ', $searchParts));
              ?>
              <tr data-row="<?php echo h($rowText); ?>">
                <td class="mono"><?php echo h($q["queue_name"] ?? ""); ?></td>
                <td class="mono"><?php echo h($q["queue_number"] ?? "-"); ?></td>
                <td><?php echo h($q["strategy"] !== "" ? $q["strategy"] : "-"); ?></td>
                <td><?php echo h($q["moh"] !== "" ? $q["moh"] : "-"); ?></td>
                <td>
                  <?php if (!empty($q["agents"])): ?>
                    <div class="agent-list">
                      <?php foreach ($q["agents"] as $agent): ?>
                        <?php
                          $cssClass = agent_css_class($agent);
                          $title = agent_state_title($agent);
                          $type = ($agent['type'] ?? 'S') === 'D' ? 'D' : 'S';
                        ?>
                        <span class="agent-pill <?php echo h($cssClass); ?>" title="<?php echo h($title); ?>">
                          <span><?php echo h($agent['name'] ?? ''); ?></span>
                          <span class="agent-type <?php echo $type === 'D' ? 'dynamic' : 'static'; ?>">
                            <?php echo h($type); ?>
                          </span>
                          <span class="agent-dot"></span>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="muted">No agents</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="actions-stack">
                    <a class="btn sm" href="/edit_queue.php?queue=<?php echo urlencode($q["queue_number"]); ?>">EDIT</a>
                    <a class="btn sm" href="/delete_queue.php?queue=<?php echo urlencode($q["queue_number"]); ?>">DELETE</a>
                    <a class="btn sm" href="/queue.php">MONITOR</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div>
</body>
</html>