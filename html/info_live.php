<?php
// /var/www/html/info_live.php
// Info Live (System + Calls + Extensions + Storage) with donut cards

function sh($cmd) {
  // run shell safely (no user input used here)
  return trim(shell_exec($cmd . " 2>/dev/null") ?? "");
}

function asterisk_rx($cmd) {
  // sudo rule for www-data required
  $cmd = escapeshellarg($cmd);
  return sh("sudo /usr/sbin/asterisk -rx $cmd");
}

function get_wan_iface() {
  // interface used for default route
  $iface = sh("ip route show default 0.0.0.0/0 | awk '{print $5}' | head -n1");
  return $iface ?: "eth0";
}

function get_wan_ip($iface) {
  $ip = sh("ip -4 addr show " . escapeshellarg($iface) . " | awk '/inet /{print $2}' | cut -d/ -f1 | head -n1");
  if ($ip) return $ip;

  // fallback: route lookup
  $ip2 = sh("ip -4 route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++){if($i==\"src\"){print $(i+1); exit}}}'");
  return $ip2 ?: "-";
}

function get_mac($iface) {
  $mac = sh("cat /sys/class/net/" . escapeshellarg($iface) . "/address");
  return $mac ?: "-";
}

function get_uptime_human() {
  $u = sh("uptime -p"); // e.g. up 1 day, 3 hours
  return $u ?: "-";
}

function get_storage_mb() {
  // root filesystem usage in MB
  // df output: size used avail
  $line = sh("df -BM / | awk 'NR==2{print $2\" \"$3\" \"$4}'"); // e.g. 29696M 10138M 18432M
  if (!$line) return ["total"=>0,"used"=>0,"avail"=>0];

  [$t,$u,$a] = array_pad(explode(" ", preg_replace('/\s+/', ' ', trim($line))), 3, "0M");
  $total = (int) rtrim($t, "M");
  $used  = (int) rtrim($u, "M");
  $avail = (int) rtrim($a, "M");
  return ["total"=>$total,"used"=>$used,"avail"=>$avail];
}

function classify_calls() {
  // counts inbound/outbound/internal from "core show channels concise"
  // We infer direction using context/exten patterns (customize if you want)
  $out = asterisk_rx("core show channels concise");
  if (!$out) return ["inbound"=>0,"outbound"=>0,"internal"=>0,"total"=>0];

  $lines = array_filter(explode("\n", $out));
  $inbound = $outbound = $internal = 0;

  foreach ($lines as $ln) {
    // concise fields: Channel!Context!Exten!Priority!State!Application!Data!CallerIDNum!...
    $parts = explode("!", $ln);
    $ctx = $parts[1] ?? "";
    $exten = $parts[2] ?? "";
    $clid = $parts[7] ?? "";

    $ctx_l = strtolower($ctx);
    $ext_l = strtolower($exten);

    if (str_contains($ctx_l, "from-trunk") || str_contains($ctx_l, "from-external") || str_contains($ctx_l, "inbound") || str_contains($ctx_l, "from-pstn")) {
      $inbound++;
    } elseif (str_contains($ctx_l, "out") || preg_match('/^(0|00|\+|9)/', $ext_l)) {
      $outbound++;
    } elseif (str_contains($ctx_l, "internal") || preg_match('/^\d{3,5}$/', $exten)) {
      $internal++;
    } else {
      $internal++;
    }
  }

  $total = $inbound + $outbound + $internal;
  return ["inbound"=>$inbound,"outbound"=>$outbound,"internal"=>$internal,"total"=>$total];
}

function extensions_status() {
  // Registered vs Unregistered using PJSIP contacts
  $endpoints = asterisk_rx("pjsip show endpoints");
  $contacts  = asterisk_rx("pjsip show contacts");

  $total = 0;
  if ($endpoints) {
    foreach (explode("\n", $endpoints) as $ln) {
      if (preg_match('/^\s*Endpoint:\s+/i', $ln)) $total++;
    }
    if ($total == 0 && preg_match('/Objects found:\s*(\d+)/i', $endpoints, $m)) $total = (int)$m[1];
  }

  $registered = 0;
  if ($contacts) {
    foreach (explode("\n", $contacts) as $ln) {
      if (preg_match('/^\s*Contact:\s+/i', $ln)) $registered++;
    }
    if ($registered == 0 && preg_match('/Objects found:\s*(\d+)/i', $contacts, $m)) $registered = (int)$m[1];
  }

  if ($total < $registered) $total = $registered;
  $unregistered = max(0, $total - $registered);

  return ["registered"=>$registered, "unregistered"=>$unregistered, "total"=>$total];
}

function json_response($arr) {
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// AJAX endpoint
if (isset($_GET["ajax"]) && $_GET["ajax"] === "1") {
  $iface = get_wan_iface();
  $sys = [
    "wan_iface" => $iface,
    "wan_mac"   => get_mac($iface),
    "wan_ip"    => get_wan_ip($iface),
    "date"      => date("d-m-Y"),
    "time"      => date("H:i:s"),
    "uptime"    => get_uptime_human(),
  ];

  $calls = classify_calls();
  $ext   = extensions_status();
  $sto   = get_storage_mb();

  json_response([
    "system" => $sys,
    "calls"  => $calls,
    "ext"    => $ext,
    "storage"=> $sto,
  ]);
}
?>
<!doctype html>
<html lang="ar" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Info Live</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    /* Helpers only */
    .wrap{ width:min(1200px, 96vw); margin:0 auto 60px auto; }

    .topbar{
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
      justify-content:space-between;
      margin-bottom:14px;
    }
    .topbar-left{
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }

    .grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:16px;
      margin-top:14px;
    }
    @media (max-width: 980px){
      .grid{ grid-template-columns: 1fr; }
    }

    /* Donut visuals (بنفس الفكرة القديمة) */
    :root{
      --g:#22c55e;
      --b:#0ea5e9;
      --gr:#8a8f9a;
    }

    .donut-wrap{
      display:grid;
      grid-template-columns: 190px 1fr;
      gap:14px;
      align-items:center;
    }
    @media (max-width: 560px){
      .donut-wrap{ grid-template-columns:1fr; }
    }

    .donut{
      width:170px;
      height:170px;
      border-radius:50%;
      margin:auto;
      background:
        conic-gradient(var(--g) 0deg, var(--g) 0deg,
                       var(--b) 0deg, var(--b) 0deg,
                       var(--gr) 0deg, var(--gr) 360deg);
      position:relative;
      box-shadow: inset 0 0 0 10px rgba(255,255,255,.10);
      border:1px solid rgba(255,255,255,.18);
    }
    .donut::after{
      content:"";
      position:absolute;
      inset:26px;
      background: rgba(0,0,0,0.22);
      border-radius:50%;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.12);
    }

    .legend{
      border-left:2px solid rgba(255,255,255,0.12);
      padding-left:14px;
    }
    .title{
      font-family:'Orbitron',sans-serif;
      letter-spacing:2px;
      font-weight:900;
      margin:0 0 10px;
      font-size:22px;
      text-transform:uppercase;
    }

    .leg-row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:10px 0;
      border-top:1px solid rgba(255,255,255,0.12);
      font-size:18px;
      font-weight:650;
      opacity:.95;
    }
    .leg-row:first-child{ border-top:0; padding-top:6px; }

    .dot{
      width:10px; height:10px; border-radius:50%;
      display:inline-block; margin-right:10px;
    }
    .k{
      display:flex; align-items:center;
      gap:0;
    }

    .total{
      margin-top:6px;
      font-weight:900;
      opacity:.9;
      display:flex;
      justify-content:space-between;
      padding-top:10px;
      border-top:1px solid rgba(255,255,255,0.12);
      font-size:18px;
    }

    /* System rows */
    .sys-row{
      display:flex;
      justify-content:space-between;
      align-items:center;
      padding:10px 0;
      border-top:1px solid rgba(255,255,255,0.12);
      font-weight:700;
      opacity:.92;
    }
    .sys-row:first-of-type{ border-top:0; padding-top:6px; }
    .sys-row .v{
      font-family:'Orbitron',sans-serif;
      letter-spacing:1px;
      font-weight:900;
      opacity:1;
    }
  </style>
</head>

<body>
  <div class="wrap">
    <h1 class="page-title">INFO LIVE</h1>

    <div class="inner-box" style="margin-top:0;">

      <div class="topbar">
        <div class="topbar-left">
          <span class="pill">Info Live</span>
          <span class="pill" id="lastUpdate">Last update: --</span>
          <span class="pill" id="wanIface">WAN: --</span>
        </div>
        <div class="topbar-left">
          <a class="btn sm" href="/dashboard.php">BACK</a>
          <a class="btn sm" href="/logout.php">LOGOUT</a>
        </div>
      </div>

      <div class="panel-box" style="margin-top:0;">

        <div class="grid">

          <!-- System Info -->
          <div class="panel-box" style="margin-top:0;">
            <div class="title">SYSTEM</div>
            <div class="sys-row"><span>WAN MAC Address</span><span class="v" id="wanMac">-</span></div>
            <div class="sys-row"><span>WAN IP Address</span><span class="v" id="wanIp">-</span></div>
            <div class="sys-row"><span>System Date</span><span class="v" id="sysDate">-</span></div>
            <div class="sys-row"><span>System Time</span><span class="v" id="sysTime">-</span></div>
            <div class="sys-row"><span>Up Time</span><span class="v" id="upTime">-</span></div>
          </div>

          <!-- Active Calls -->
          <div class="panel-box" style="margin-top:0;">
            <div class="donut-wrap">
              <div class="donut" id="donutCalls"></div>
              <div class="legend">
                <div class="title">ACTIVE CALLS</div>
                <div class="leg-row">
                  <span class="k"><span class="dot" style="background:var(--g)"></span>inbound:</span>
                  <span id="cIn">0</span>
                </div>
                <div class="leg-row">
                  <span class="k"><span class="dot" style="background:var(--b)"></span>outbound:</span>
                  <span id="cOut">0</span>
                </div>
                <div class="leg-row">
                  <span class="k"><span class="dot" style="background:var(--gr)"></span>internal:</span>
                  <span id="cInt">0</span>
                </div>
                <div class="total">
                  <span>Total:</span><span id="cTot">0</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Extensions -->
          <div class="panel-box" style="margin-top:0;">
            <div class="donut-wrap">
              <div class="donut" id="donutExt"></div>
              <div class="legend">
                <div class="title">EXTENSIONS</div>
                <div class="leg-row">
                  <span class="k"><span class="dot" style="background:var(--b)"></span>Registered:</span>
                  <span id="eReg">0</span>
                </div>
                <div class="leg-row">
                  <span class="k"><span class="dot" style="background:var(--gr)"></span>Unregistered:</span>
                  <span id="eUnreg">0</span>
                </div>
                <div class="total">
                  <span>Total:</span><span id="eTot">0</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Storage -->
          <div class="panel-box" style="margin-top:0;">
            <div class="donut-wrap">
              <div class="donut" id="donutSto"></div>
              <div class="legend">
                <div class="title">STORAGE</div>
                <div class="leg-row">
                  <span class="k"><strong>Total:</strong></span>
                  <span><strong id="sTot">0</strong> MB</span>
                </div>
                <div class="leg-row">
                  <span class="k"><span class="dot" style="background:var(--g)"></span>Available:</span>
                  <span><span id="sAvail">0</span> MB</span>
                </div>
                <div class="leg-row">
                  <span class="k"><span class="dot" style="background:var(--b)"></span>Used:</span>
                  <span><span id="sUsed">0</span> MB</span>
                </div>
              </div>
            </div>
          </div>

        </div><!-- grid -->

      </div><!-- panel-box -->

    </div><!-- inner-box -->
  </div>

<script>
function setDonut(el, parts) {
  // parts: [{val, colorVar}] -> build conic-gradient
  const total = parts.reduce((a,p)=>a + (Number(p.val)||0), 0) || 1;
  let acc = 0;
  const segs = parts.map(p=>{
    const start = (acc/total)*360;
    acc += (Number(p.val)||0);
    const end = (acc/total)*360;
    return `var(${p.colorVar}) ${start}deg ${end}deg`;
  });
  el.style.background = `conic-gradient(${segs.join(",")})`;
}

async function refresh() {
  try {
    const res = await fetch("info_live.php?ajax=1", {cache:"no-store"});
    const j = await res.json();

    // System
    document.getElementById("wanIface").textContent = "WAN: " + (j.system.wan_iface || "--");
    document.getElementById("wanMac").textContent  = j.system.wan_mac || "-";
    document.getElementById("wanIp").textContent   = j.system.wan_ip || "-";
    document.getElementById("sysDate").textContent = j.system.date || "-";
    document.getElementById("sysTime").textContent = j.system.time || "-";
    document.getElementById("upTime").textContent  = j.system.uptime || "-";
    document.getElementById("lastUpdate").textContent = "Last update: " + (new Date()).toLocaleTimeString();

    // Calls
    document.getElementById("cIn").textContent  = j.calls.inbound ?? 0;
    document.getElementById("cOut").textContent = j.calls.outbound ?? 0;
    document.getElementById("cInt").textContent = j.calls.internal ?? 0;
    document.getElementById("cTot").textContent = j.calls.total ?? 0;

    setDonut(document.getElementById("donutCalls"), [
      {val: j.calls.inbound ?? 0,  colorVar: "--g"},
      {val: j.calls.outbound ?? 0, colorVar: "--b"},
      {val: j.calls.internal ?? 0, colorVar: "--gr"},
    ]);

    // Extensions
    document.getElementById("eReg").textContent   = j.ext.registered ?? 0;
    document.getElementById("eUnreg").textContent = j.ext.unregistered ?? 0;
    document.getElementById("eTot").textContent   = j.ext.total ?? 0;

    setDonut(document.getElementById("donutExt"), [
      {val: j.ext.registered ?? 0,   colorVar: "--b"},
      {val: j.ext.unregistered ?? 0, colorVar: "--gr"},
    ]);

    // Storage
    document.getElementById("sTot").textContent   = j.storage.total ?? 0;
    document.getElementById("sAvail").textContent = j.storage.avail ?? 0;
    document.getElementById("sUsed").textContent  = j.storage.used ?? 0;

    setDonut(document.getElementById("donutSto"), [
      {val: j.storage.avail ?? 0, colorVar: "--g"},
      {val: j.storage.used ?? 0,  colorVar: "--b"},
    ]);

  } catch(e) {
    console.log(e);
  }
}

refresh();
setInterval(refresh, 3000);
</script>
</body>
</html>