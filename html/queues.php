<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$QUEUE_CONF = "/etc/asterisk/queues.conf";

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
    if (preg_match('/; BEGIN RCM QUEUE GUI(.*?); END RCM QUEUE GUI/s', $text, $m))
        return trim((string)$m[1]);
    return "";
}
function parse_ini_like_sections(string $text): array {
    $sections = []; $current = null;
    $lines = preg_split("/\r\n|\n|\r/", $text);
    foreach ($lines as $line) {
        $raw = rtrim((string)$line); $trim = trim($raw);
        if ($trim === "") { if ($current !== null) $sections[$current]["_lines"][] = $raw; continue; }
        if (preg_match('/^\[([^\]]+)\]$/', $trim, $m)) {
            $current = trim($m[1]);
            if (!isset($sections[$current])) $sections[$current] = ["_lines"=>[],"_kv"=>[],"_members"=>[]];
            continue;
        }
        if ($current === null) continue;
        $sections[$current]["_lines"][] = $raw;
        if (preg_match('/^member\s*=>\s*(.+)$/i', $trim, $m)) { $sections[$current]["_members"][] = trim($m[1]); continue; }
        if (preg_match('/^([a-zA-Z0-9_-]+)\s*=\s*(.*)$/', $trim, $m)) $sections[$current]["_kv"][strtolower(trim($m[1]))] = trim($m[2]);
    }
    return $sections;
}
function parse_queue_display_name(array $lines, string $fallback): string {
    foreach ($lines as $line) {
        $trim = trim((string)$line);
        if (preg_match('/^[;#]\s*RCM_NAME\s*=\s*(.+)$/i', $trim, $m) && trim($m[1]) !== "") return trim($m[1]);
    }
    return $fallback;
}
function normalize_member_name(string $raw): string {
    $raw = trim(preg_replace('/\s*[;#].*$/', '', trim($raw)));
    if ($raw === '') return '';
    if (preg_match('/^PJSIP\/([A-Za-z0-9_.-]+)$/i', $raw, $m)) return $m[1];
    if (preg_match('/^Local\/([^@\/]+)/i', $raw, $m)) return $m[1];
    return $raw;
}
function parse_gui_queues_only(string $queueConfText): array {
    $managed = extract_managed_queue_block($queueConfText);
    if ($managed === '') return [];
    $sections = parse_ini_like_sections($managed);
    $queues = [];
    foreach ($sections as $sectionName => $data) {
        if (!preg_match('/^[0-9]{2,6}$/', $sectionName)) continue;
        $kv = $data["_kv"] ?? []; $members = $data["_members"] ?? []; $lines = $data["_lines"] ?? [];
        $staticAgents = [];
        foreach ($members as $m) {
            $n = normalize_member_name((string)$m);
            if ($n !== '') $staticAgents[$n] = ['name'=>$n,'type'=>'S','raw'=>$m,'status_num'=>null,'paused'=>false,'in_call'=>false,'state'=>'unknown'];
        }
        $queues[$sectionName] = [
            "queue_name"    => parse_queue_display_name($lines, $sectionName),
            "queue_number"  => $sectionName,
            "strategy"      => $kv["strategy"] ?? "",
            "moh"           => $kv["musicclass"] ?? "",
            "static_agents" => $staticAgents,
            "agents"        => $staticAgents,
        ];
    }
    uksort($queues, fn($a,$b) => strnatcasecmp($a,$b));
    return $queues;
}

/* ================= AMI HELPERS ================= */
function ami_connect($host,$port){ $fp=@fsockopen($host,$port,$errno,$errstr,3); if(!$fp)return false; stream_set_timeout($fp,3); return $fp; }
function ami_write($fp,$data): void { fwrite($fp,$data); }
function ami_login($fp,$user,$pass): void { ami_write($fp,"Action: Login\r\nUsername: {$user}\r\nSecret: {$pass}\r\n\r\n"); }
function ami_action($fp,$block): void { ami_write($fp,$block."\r\n\r\n"); }
function ami_read_until_event($fp,$eventName): array {
    $lines=[];
    while(!feof($fp)){ $line=fgets($fp); if($line===false)break; $line=rtrim($line,"\r\n"); $lines[]=$line; if(strpos($line,"Event: {$eventName}")!==false)break; }
    return $lines;
}
function parse_ami_blocks(array $lines): array {
    $blocks=[];$cur=[];
    foreach($lines as $ln){ if($ln===""){ if($cur){$blocks[]=$cur;$cur=[];} continue; } $cur[]=$ln; }
    if($cur)$blocks[]=$cur;
    $events=[];
    foreach($blocks as $b){ $evt=[]; foreach($b as $ln){ $pos=strpos($ln,":"); if($pos===false)continue; $evt[trim(substr($ln,0,$pos))]=trim(substr($ln,$pos+1)); } if($evt)$events[]=$evt; }
    return $events;
}
function agent_state_slug(int $s,bool $paused,bool $inCall): string {
    if($paused)return'paused'; if($inCall)return'incall';
    return match($s){1=>'available',2,6,7,8=>'incall',3,4,5=>'busy',default=>'unknown'};
}
function member_status_label($n): string {
    return [0=>"Unknown",1=>"Not in use",2=>"In use",3=>"Busy",4=>"Invalid",5=>"Unavailable",6=>"Ringing",7=>"Ring+InUse",8=>"On Hold"][(int)$n]??("Status ".(int)$n);
}
function agent_state_title(array $agent): string {
    if(!empty($agent['paused']))return'Paused';
    $label=member_status_label((int)($agent['status_num']??0));
    return !empty($agent['in_call'])?$label.' / In Call':$label;
}
function agent_css_class(array $agent): string {
    return match((string)($agent['state']??'unknown')){'available'=>'available','incall'=>'incall','busy'=>'busy','paused'=>'paused',default=>'unknown'};
}
function fetch_ami_queue_members(): array {
    global $AMI_HOST,$AMI_PORT,$AMI_USER,$AMI_PASS;
    $fp=ami_connect($AMI_HOST,$AMI_PORT); if(!$fp)return [];
    ami_login($fp,$AMI_USER,$AMI_PASS);
    ami_action($fp,"Action: QueueStatus\r\nActionID: QWEB");
    $lines=ami_read_until_event($fp,"QueueStatusComplete");
    ami_action($fp,"Action: Logoff"); fclose($fp);
    $events=parse_ami_blocks($lines); $result=[];
    foreach($events as $e){
        if(($e["Event"]??"")!=="QueueMember")continue;
        $queue=trim((string)($e["Queue"]??"")); if($queue==='')continue;
        $location=trim((string)($e["Location"]??"")); $name=trim((string)($e["Name"]??""));
        $normalized=normalize_member_name($location); if($normalized==='')$normalized=normalize_member_name($name); if($normalized==='')continue;
        $membership = strtolower(trim((string)($e["Membership"]??"")));
        $type = ($membership === 'dynamic') ? 'D' : 'S';
        $paused=((string)($e["Paused"]??"0")==="1"); $inCall=((string)($e["InCall"]??"0")==="1");
        $result[$queue][]=['name'=>$normalized,'type'=>$type,'raw'=>$location!==''?$location:$name,'status_num'=>(int)($e["Status"]??0),'paused'=>$paused,'in_call'=>$inCall,'state'=>agent_state_slug((int)($e["Status"]??0),$paused,$inCall)];
    }
    return $result;
}

/* ================= BUILD PAGE DATA ================= */
$queueConfText = read_file_safe($QUEUE_CONF);
$queues = parse_gui_queues_only($queueConfText);
$amiMembers = fetch_ami_queue_members();

foreach ($queues as $queueNumber => &$queue) {
    $queueKey = $queue['queue_number'];
    if (!isset($amiMembers[$queueKey]) || !is_array($amiMembers[$queueKey])) continue;
    foreach ($amiMembers[$queueKey] as $member) {
        $agentName = $member['name'];
        if (isset($queue['agents'][$agentName])) {
            $queue['agents'][$agentName]['status_num'] = $member['status_num'];
            $queue['agents'][$agentName]['paused']     = $member['paused'];
            $queue['agents'][$agentName]['in_call']    = $member['in_call'];
            $queue['agents'][$agentName]['state']      = $member['state'];
            if (($member['type']??'S')==='D') $queue['agents'][$agentName]['type']='D';
        } else {
            $queue['agents'][$agentName] = $member;
        }
    }
    uasort($queue['agents'], function($a,$b){
        if(($a['type']??'S')!==($b['type']??'S')) return(($a['type']??'S')==='S')?-1:1;
        return strnatcasecmp((string)($a['name']??''),(string)($b['name']??''));
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

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    /* layout */
    .top {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 6px;
    }

    .panel-box {
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 18px;
      padding: 22px;
      margin-top: 18px;
    }

    .btn.sm {
      padding: 7px 16px;
      font-size: 12px;
      letter-spacing: 1.5px;
      border-radius: 30px;
    }

    /* search */
    .search-row {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
      margin-top: 14px;
    }
    .search-row label {
      font-family: 'Orbitron', sans-serif;
      font-size: 13px;
      letter-spacing: 1px;
      opacity: .85;
    }
    .search-row input {
      padding: 10px 14px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.22);
      background: rgba(255,255,255,0.08);
      color: #fff;
      font-size: 15px;
      outline: none;
      width: 300px;
      box-sizing: border-box;
      transition: border-color .2s;
    }
    .search-row input:focus { border-color: rgba(255,255,255,0.55); }
    .search-row input::placeholder { color: rgba(255,255,255,0.40); }

    /* legend */
    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
    }
    .legend .pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 12px;
    }
    .legend-dot {
      width: 9px; height: 9px;
      border-radius: 50%;
      display: inline-block;
    }

    /* table */
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 2px solid rgba(255,255,255,0.45); padding: 14px 16px; font-size: 15px; }
    th {
      background: rgba(255,255,255,0.10);
      font-family: 'Orbitron', sans-serif;
      font-size: 13px;
      letter-spacing: 1px;
    }
    tbody tr:hover { background: rgba(255,255,255,0.04); }
    .table-wrap { overflow-x: auto; }

    /* agents */
    .agent-list { display: flex; flex-wrap: wrap; gap: 8px; }
    .agent-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 6px 11px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.05);
      font-size: 13px;
      white-space: nowrap;
    }
    .agent-type {
      font-family: 'Orbitron', sans-serif;
      font-size: 10px;
      padding: 2px 6px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.20);
    }
    .agent-type.static  { background: rgba(80,150,255,.16); border-color: rgba(80,150,255,.45); }
    .agent-type.dynamic { background: rgba(50,220,140,.16); border-color: rgba(50,220,140,.45); }
    .agent-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }

    .agent-pill.available .agent-dot { background:#52d273; box-shadow:0 0 6px rgba(82,210,115,.7); }
    .agent-pill.incall    .agent-dot { background:#ffad33; box-shadow:0 0 6px rgba(255,173,51,.7); }
    .agent-pill.busy      .agent-dot { background:#ff6363; box-shadow:0 0 6px rgba(255,99,99,.7); }
    .agent-pill.paused    .agent-dot { background:#b07cff; box-shadow:0 0 6px rgba(176,124,255,.7); }
    .agent-pill.unknown   .agent-dot { background:#8b98a9; }

    .actions-stack { display:flex; flex-wrap:wrap; gap:8px; }
    .mono { font-family:'Orbitron',sans-serif; letter-spacing:1px; }
  </style>

  <script>
    function filterQueues(){
      const v = (document.getElementById('qSearch').value||'').toLowerCase();
      document.querySelectorAll('[data-row]').forEach(el=>{
        el.style.display = (el.getAttribute('data-row')||'').toLowerCase().includes(v)?'':'none';
      });
    }
  </script>
</head>

<body>
<div class="wrap">

  <h1 class="page-title">QUEUES</h1>

  <div class="inner-box">

    <!-- Top buttons -->
    <div class="top">
      <a class="btn" href="/dashboard.php">BACK</a>
      <a class="btn" href="/queue.php">QUEUE MONITOR</a>
      <a class="btn" href="/stats.php">Q STATISTICS</a>
      <a class="btn" href="/add_queue.php">ADD NEW</a>
      <span class="pill">TOTAL QUEUES: <?php echo (int)$totalQueues; ?></span>
    </div>

    <!-- Search -->
    <div class="search-row">
      <label>Search</label>
      <input id="qSearch" type="text" placeholder="queue number / name / agent…" oninput="filterQueues()">
      <span class="muted" style="font-size:13px;">GUI-managed queues only</span>
    </div>

    <!-- Legend -->
    <div class="legend">
      <span class="pill"><span class="legend-dot" style="background:#94a3b8;"></span>S = Static</span>
      <span class="pill"><span class="legend-dot" style="background:#94a3b8;"></span>D = Dynamic</span>
      <span class="pill" style="color:#52d273;"><span class="legend-dot" style="background:#52d273;box-shadow:0 0 6px rgba(82,210,115,.7);"></span>Available</span>
      <span class="pill" style="color:#ffad33;"><span class="legend-dot" style="background:#ffad33;box-shadow:0 0 6px rgba(255,173,51,.7);"></span>In Call / Ringing</span>
      <span class="pill" style="color:#ff6363;"><span class="legend-dot" style="background:#ff6363;box-shadow:0 0 6px rgba(255,99,99,.7);"></span>Busy / Unavailable</span>
      <span class="pill" style="color:#b07cff;"><span class="legend-dot" style="background:#b07cff;box-shadow:0 0 6px rgba(176,124,255,.7);"></span>Paused</span>
    </div>

    <div class="panel-box">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Queue</th>
              <th>Number</th>
              <th>Strategy</th>
              <th>MOH</th>
              <th>Agents (S/D)</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$queues): ?>
            <tr><td colspan="6" class="muted">No GUI-managed queues configured.</td></tr>
          <?php else: ?>
            <?php foreach ($queues as $q): ?>
              <?php
                $parts = [$q["queue_name"]??"",$q["queue_number"]??"",$q["strategy"]??"",$q["moh"]??""];
                foreach(($q["agents"]??[]) as $a){ $parts[]=$a['name']??''; $parts[]=$a['type']??''; }
                $rowText = trim(implode(' ',$parts));
              ?>
              <tr data-row="<?php echo h($rowText); ?>">
                <td class="mono"><?php echo h($q["queue_name"]??""); ?></td>
                <td class="mono"><?php echo h($q["queue_number"]??"-"); ?></td>
                <td><?php echo h($q["strategy"]!==""?$q["strategy"]:"-"); ?></td>
                <td><?php echo h($q["moh"]!==""?$q["moh"]:"-"); ?></td>
                <td>
                  <?php if (!empty($q["agents"])): ?>
                    <div class="agent-list">
                      <?php foreach ($q["agents"] as $agent): ?>
                        <?php $type = ($agent['type']??'S')==='D'?'D':'S'; ?>
                        <span class="agent-pill <?php echo h(agent_css_class($agent)); ?>"
                              title="<?php echo h(agent_state_title($agent)); ?>">
                          <span><?php echo h($agent['name']??''); ?></span>
                          <span class="agent-type <?php echo $type==='D'?'dynamic':'static'; ?>"><?php echo h($type); ?></span>
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
                    <a class="btn sm" href="/delete_queue.php?queue=<?php echo urlencode($q["queue_number"]); ?>"
                       onclick="return confirm('Delete queue <?php echo h(addslashes($q["queue_number"])); ?>?')">DELETE</a>
                    <a class="btn sm" href="/queue.php">MONITOR</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div><!-- /panel-box -->

  </div><!-- /inner-box -->
</div><!-- /wrap -->
</body>
</html>