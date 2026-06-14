<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* ================= AMI CONFIG ================= */
$AMI_HOST = "127.0.0.1";
$AMI_PORT = 5038;
$AMI_USER = "guiuser";
$AMI_PASS = "admin";

/* ================= AMI HELPERS ================= */
function ami_connect($host, $port) {
  $fp = @fsockopen($host, $port, $errno, $errstr, 3);
  if (!$fp) { http_response_code(500); die("AMI connect failed: $errstr ($errno)"); }
  stream_set_timeout($fp, 3);
  return $fp;
}
function ami_write($fp, $data) { fwrite($fp, $data); }
function ami_login($fp, $user, $pass) {
  ami_write($fp, "Action: Login\r\nUsername: {$user}\r\nSecret: {$pass}\r\n\r\n");
}
function ami_action($fp, $actionBlock) { ami_write($fp, $actionBlock . "\r\n\r\n"); }

/* ???? ?? AMI response ??? QueueStatusComplete */
function ami_read_until_event($fp, $eventName){
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

/* Parse AMI blocks separated by blank line */
function parse_ami_blocks($lines){
  $blocks = [];
  $cur = [];
  foreach($lines as $ln){
    if ($ln === "") {
      if ($cur) { $blocks[] = $cur; $cur = []; }
      continue;
    }
    $cur[] = $ln;
  }
  if ($cur) $blocks[] = $cur;

  $events = [];
  foreach($blocks as $b){
    $evt = [];
    foreach($b as $ln){
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

function sec_to_mmss($sec){
  $sec = (int)$sec;
  if ($sec < 0) $sec = 0;
  $m = floor($sec / 60);
  $s = $sec % 60;
  return sprintf("%02d:%02d", $m, $s);
}

function bool_pill($v){
  $v = (string)$v;
  return ($v === "1" || strtolower($v) === "yes" || strtolower($v) === "true");
}

function member_status_label($statusNum){
  // Asterisk member status numeric (commonly):
  // 0=Unknown, 1=Not in use, 2=In use, 3=Busy, 4=Invalid, 5=Unavailable, 6=Ringing, 7=Ring+InUse, 8=OnHold
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

/* ================= FETCH QUEUESTATUS ================= */
$fp = ami_connect($AMI_HOST, $AMI_PORT);
ami_login($fp, $AMI_USER, $AMI_PASS);

ami_action($fp, "Action: QueueStatus\r\nActionID: QWEB");
$lines = ami_read_until_event($fp, "QueueStatusComplete");

ami_action($fp, "Action: Logoff");
fclose($fp);

$events = parse_ami_blocks($lines);

/* ================= BUILD DATA ================= */
$queues = []; // name => [params=>[], members=>[], entries=>[]]

foreach($events as $e){
  $ev = $e["Event"] ?? "";

  if ($ev === "QueueParams") {
    $q = $e["Queue"] ?? "";
    if ($q === "") continue;

    if (!isset($queues[$q])) $queues[$q] = ["params"=>[], "members"=>[], "entries"=>[]];

    $queues[$q]["params"] = [
      "Queue"        => $q,
      "Calls"        => $e["Calls"] ?? "0",
      "Holdtime"     => $e["Holdtime"] ?? "0",
      "Completed"    => $e["Completed"] ?? "0",
      "Abandoned"    => $e["Abandoned"] ?? "0",
      "ServiceLevel" => $e["ServiceLevel"] ?? "",
      "Strategy"     => $e["Strategy"] ?? "",
      "Weight"       => $e["Weight"] ?? "",
    ];
  }

  if ($ev === "QueueMember") {
    $q = $e["Queue"] ?? "";
    if ($q === "") continue;

    if (!isset($queues[$q])) $queues[$q] = ["params"=>["Queue"=>$q], "members"=>[], "entries"=>[]];

    $queues[$q]["members"][] = [
      "Name"       => $e["Name"] ?? "",
      "Location"   => $e["Location"] ?? "",
      "StateInterface" => $e["StateInterface"] ?? "",
      "Membership" => $e["Membership"] ?? "",
      "Status"     => $e["Status"] ?? "0",
      "Paused"     => $e["Paused"] ?? "0",
      "InCall"     => $e["InCall"] ?? "0",
      "CallsTaken" => $e["CallsTaken"] ?? "0",
      "LastCall"   => $e["LastCall"] ?? "",
      "Penalty"    => $e["Penalty"] ?? "",
    ];
  }

  if ($ev === "QueueEntry") {
    $q = $e["Queue"] ?? "";
    if ($q === "") continue;

    if (!isset($queues[$q])) $queues[$q] = ["params"=>["Queue"=>$q], "members"=>[], "entries"=>[]];

    $queues[$q]["entries"][] = [
      "Position"    => $e["Position"] ?? "",
      "CallerIDNum" => $e["CallerIDNum"] ?? "",
      "CallerIDName"=> $e["CallerIDName"] ?? "",
      "Wait"        => $e["Wait"] ?? "0",
      "Channel"     => $e["Channel"] ?? "",
      "Uniqueid"    => $e["Uniqueid"] ?? "",
    ];
  }
}

/* ================= COMPUTE SUMMARY ================= */
$totalQueues = count($queues);
$totalWaiting = 0;
$totalAgents = 0;
$totalInCallAgents = 0;

foreach($queues as $qname => $q){
  $waiting = count($q["entries"]);
  $totalWaiting += $waiting;

  $agents = count($q["members"]);
  $totalAgents += $agents;

  foreach($q["members"] as $m){
    if (bool_pill($m["InCall"])) $totalInCallAgents++;
  }
}

/* sort queues by name natural */
uksort($queues, function($a,$b){ return strnatcasecmp($a,$b); });

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="5">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Queue Monitor</title>

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/dist/assets/rcm.css">
<link rel="icon" type="image/png" href="/assets/rcm/logo.png">

<style>
/* ? FIX: start from top */
html, body{ height:auto !important; }
body{
  display:block !important;
  align-items:initial !important;
  justify-content:initial !important;
}

/* Helpers only */
.wrap{ width:min(1200px, 96vw); margin:0 auto 60px auto; }

/* header buttons layout */
.q-topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:10px;
}

/* summary cards */
.summary{
  display:grid;
  grid-template-columns: repeat(4, 1fr);
  gap:14px;
  margin-top:10px;
}
@media (max-width: 980px){ .summary{ grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px){ .summary{ grid-template-columns: 1fr; } }

.card{
  background:rgba(255,255,255,0.08);
  border:1px solid rgba(255,255,255,0.18);
  border-radius:18px;
  padding:16px 18px;
}
.card .k{
  font-family:'Orbitron',sans-serif;
  letter-spacing:2px;
  opacity:.9;
}
.card .v{
  font-family:'Orbitron',sans-serif;
  font-size:34px;
  font-weight:900;
  margin-top:8px;
}

/* queue section title */
.q-title{
  font-family:'Orbitron',sans-serif;
  font-weight:900;
  letter-spacing:6px;
  text-transform:uppercase;
  font-size:34px;
  margin:8px 0 10px 0;
  text-shadow: 0 0 18px rgba(255,255,255,.25);
}

/* queue cards row */
.q-cards{
  display:grid;
  grid-template-columns: repeat(4, 1fr);
  gap:14px;
  margin-top:12px;
}
@media (max-width: 1100px){ .q-cards{ grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px){ .q-cards{ grid-template-columns: 1fr; } }

/* pills colors without touching rcm.css */
.pill.ok{ border-color: rgba(120,255,120,.55); }
.pill.warn{ border-color: rgba(255,220,120,.6); }
.pill.bad{ border-color: rgba(255,140,140,.6); }

.search-row{
  display:flex;
  gap:12px;
  align-items:center;
  flex-wrap:wrap;
  margin-top:14px;
}
.search-row input{
  max-width: 360px;
}

.small-note{
  font-size:12px;
  opacity:.85;
}
</style>

<script>
function filterQueues(){
  const v = (document.getElementById('qSearch').value || '').toLowerCase();
  document.querySelectorAll('[data-qname]').forEach(el=>{
    const name = (el.getAttribute('data-qname') || '').toLowerCase();
    el.style.display = name.includes(v) ? '' : 'none';
  });
}
function toggleTable(id){
  const el = document.getElementById(id);
  if(!el) return;
  el.style.display = (el.style.display === 'none') ? '' : 'none';
}
</script>
</head>

<body>

<div class="wrap">

  <div class="q-topbar">
    <a class="btn" href="/dashboard.php">BACK</a>
    <h1 class="page-title" style="margin:0; font-size:54px;">QUEUE MONITOR</h1>
    <a class="btn" href="/add_queue.php">ADD New</a>

  </div>

  <div class="inner-box" style="margin-top:0;">

    <div class="top">
      <span class="pill">AUTO REFRESH: 5s</span>
      <span class="pill">QUEUES: <?php echo (int)$totalQueues; ?></span>
      <span class="pill">WAITING: <?php echo (int)$totalWaiting; ?></span>
      <span class="pill">AGENTS: <?php echo (int)$totalAgents; ?></span>
      <span class="pill">IN CALL: <?php echo (int)$totalInCallAgents; ?></span>
    </div>

    <div class="search-row">
      <label style="margin:0;">Search Queue</label>
      <input id="qSearch" type="text" placeholder="type queue name..." oninput="filterQueues()">
    </div>

    <div class="panel-box" style="margin-top:14px;">

      <div class="summary">
        <div class="card">
          <div class="k">QUEUES</div>
          <div class="v"><?php echo (int)$totalQueues; ?></div>
        </div>
        <div class="card">
          <div class="k">TOTAL WAITING</div>
          <div class="v"><?php echo (int)$totalWaiting; ?></div>
        </div>
        <div class="card">
          <div class="k">TOTAL AGENTS</div>
          <div class="v"><?php echo (int)$totalAgents; ?></div>
        </div>
        <div class="card">
          <div class="k">AGENTS IN CALL</div>
          <div class="v"><?php echo (int)$totalInCallAgents; ?></div>
        </div>
      </div>

      <div class="hr"></div>

      <?php if (!$queues): ?>
        <div class="muted">No queues found from AMI.</div>
      <?php endif; ?>

      <?php foreach($queues as $qname => $q): ?>
        <?php
          $entries = $q["entries"];
          $members = $q["members"];

          $callers = count($entries);
          $agents  = count($members);

          $sumWait = 0;
          $maxWait = 0;
          foreach($entries as $en){
            $w = (int)($en["Wait"] ?? 0);
            $sumWait += $w;
            if ($w > $maxWait) $maxWait = $w;
          }
          $avgWait = ($callers > 0) ? floor($sumWait / $callers) : 0;

          // pills status based on waiting
          $queuePillClass = "ok";
          if ($callers >= 3) $queuePillClass = "warn";
          if ($callers >= 6) $queuePillClass = "bad";

          $qid = preg_replace('/[^a-zA-Z0-9_]/','_', $qname);
        ?>

        <div class="panel-box" data-qname="<?php echo htmlspecialchars($qname); ?>">

          <div class="top" style="margin-bottom:10px;">
            <div class="q-title" style="margin:0;"><?php echo htmlspecialchars($qname); ?></div>
            <div class="btn-row">
              <span class="pill <?php echo $queuePillClass; ?>">CALLERS: <?php echo (int)$callers; ?></span>
              <span class="pill">AGENTS: <?php echo (int)$agents; ?></span>
              <button type="button" class="btn sm" onclick="toggleTable('members_<?php echo $qid; ?>')">TOGGLE MEMBERS</button>
              <button type="button" class="btn sm" onclick="toggleTable('callers_<?php echo $qid; ?>')">TOGGLE CALLERS</button>
            </div>
          </div>

          <div class="q-cards">
            <div class="card">
              <div class="k">CALLERS</div>
              <div class="v"><?php echo (int)$callers; ?></div>
            </div>
            <div class="card">
              <div class="k">AVG HOLD</div>
              <div class="v"><?php echo sec_to_mmss($avgWait); ?></div>
            </div>
            <div class="card">
              <div class="k">LONGEST HOLD</div>
              <div class="v"><?php echo sec_to_mmss($maxWait); ?></div>
            </div>
            <div class="card">
              <div class="k">AGENTS</div>
              <div class="v"><?php echo (int)$agents; ?></div>
            </div>
          </div>

          <!-- Members -->
          <div id="members_<?php echo $qid; ?>" style="margin-top:16px;">
            <table>
              <thead>
                <tr>
                  <th>MEMBER</th>
                  <th>STATUS</th>
                  <th>CALLS TAKEN</th>
                  <th>PAUSED</th>
                  <th>IN CALL</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$members): ?>
                  <tr><td colspan="5" class="muted">No members</td></tr>
                <?php else: ?>
                  <?php foreach($members as $m): ?>
                    <?php
                      $statusText = member_status_label($m["Status"] ?? "0");
                      $paused = bool_pill($m["Paused"] ?? "0");
                      $inCall = bool_pill($m["InCall"] ?? "0");

                      $sClass = "ok";
                      if (stripos($statusText, "Unavailable") !== false || stripos($statusText, "Invalid") !== false) $sClass = "bad";
                      if (stripos($statusText, "Busy") !== false || stripos($statusText, "Ringing") !== false || stripos($statusText, "In use") !== false) $sClass = "warn";

                      $memberLabel = $m["Location"] ?: ($m["Name"] ?: "-");
                    ?>
                    <tr>
                      <td style="font-family:'Orbitron',sans-serif;letter-spacing:1px;">
                        <?php echo htmlspecialchars($memberLabel); ?>
                      </td>
                      <td><span class="pill <?php echo $sClass; ?>"><?php echo htmlspecialchars($statusText); ?></span></td>
                      <td><?php echo htmlspecialchars($m["CallsTaken"] ?? "0"); ?></td>
                      <td><span class="pill <?php echo $paused ? "warn":"ok"; ?>"><?php echo $paused ? "YES":"NO"; ?></span></td>
                      <td><span class="pill <?php echo $inCall ? "bad":"ok"; ?>"><?php echo $inCall ? "YES":"NO"; ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Active callers (QueueEntry) -->
          <div id="callers_<?php echo $qid; ?>" style="margin-top:16px; <?php echo $callers ? "" : "display:none;"; ?>">
            <table>
              <thead>
                <tr>
                  <th>POSITION</th>
                  <th>CALLER</th>
                  <th>WAIT</th>
                  <th>CHANNEL</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$entries): ?>
                  <tr><td colspan="4" class="muted">No waiting callers</td></tr>
                <?php else: ?>
                  <?php
                    usort($entries, function($a,$b){
                      return ((int)($a["Position"]??0)) <=> ((int)($b["Position"]??0));
                    });
                  ?>
                  <?php foreach($entries as $en): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($en["Position"] ?? ""); ?></td>
                      <td><?php echo htmlspecialchars(($en["CallerIDNum"] ?? "") ?: ($en["CallerIDName"] ?? "")); ?></td>
                      <td><span class="pill <?php echo ((int)($en["Wait"]??0) >= 60) ? "warn":"ok"; ?>"><?php echo sec_to_mmss((int)($en["Wait"] ?? 0)); ?></span></td>
                      <td class="muted"><?php echo htmlspecialchars($en["Channel"] ?? ""); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      <?php endforeach; ?>

    </div><!-- /panel-box -->

  </div><!-- /inner-box -->

</div><!-- /wrap -->

</body>
</html>
