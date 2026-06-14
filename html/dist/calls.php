<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$AMI_HOST = "127.0.0.1";
$AMI_PORT = 5038;
$AMI_USER = "guiuser";
$AMI_PASS = "admin";

$ANSWER_CACHE_FILE = sys_get_temp_dir() . "/rcm_active_calls_answer_cache.json";

/* =========================
 * AMI helpers
 * ========================= */
function ami_connect($host, $port) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 3);
    if (!$fp) {
        http_response_code(500);
        die(json_encode([
            "ok" => false,
            "error" => "AMI connect failed: $errstr"
        ]));
    }
    stream_set_timeout($fp, 3);
    return $fp;
}

function ami_write($fp, $data) {
    fwrite($fp, $data);
}

function ami_login($fp, $user, $pass) {
    ami_write($fp, "Action: Login\r\nUsername: {$user}\r\nSecret: {$pass}\r\n\r\n");
}

function ami_action($fp, $block) {
    ami_write($fp, $block . "\r\n\r\n");
}

function ami_hangup_channel($fp, $channel) {
    ami_write($fp, "Action: Hangup\r\nChannel: {$channel}\r\n\r\n");
}

function ami_read_until_complete($fp) {
    $lines = [];
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) {
            break;
        }
        $lines[] = rtrim($line, "\r\n");
        if (strpos($line, "EventList: Complete") !== false) {
            break;
        }
    }
    return $lines;
}

function ami_read_response_block($fp, $timeoutSeconds = 2) {
    $lines = [];
    $start = microtime(true);

    while (!feof($fp) && (microtime(true) - $start) < $timeoutSeconds) {
        $line = fgets($fp);

        if ($line === false) {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                break;
            }
            usleep(100000);
            continue;
        }

        $line = rtrim($line, "\r\n");
        $lines[] = $line;

        if ($line === "") {
            break;
        }
    }

    $resp = [];
    foreach ($lines as $ln) {
        $p = strpos($ln, ":");
        if ($p === false) continue;
        $resp[trim(substr($ln, 0, $p))] = trim(substr($ln, $p + 1));
    }

    return [$resp, $lines];
}

function parse_ami_events($lines) {
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

    if ($cur) {
        $blocks[] = $cur;
    }

    $events = [];
    foreach ($blocks as $b) {
        $evt = [];
        foreach ($b as $ln) {
            $p = strpos($ln, ":");
            if ($p === false) continue;
            $evt[trim(substr($ln, 0, $p))] = trim(substr($ln, $p + 1));
        }
        if ($evt) $events[] = $evt;
    }

    return $events;
}

/* =========================
 * Cache helpers
 * ========================= */
function load_answer_cache($path) {
    if (!file_exists($path)) return [];

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === "") return [];

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_answer_cache($path, $cache) {
    @file_put_contents(
        $path,
        json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

/* =========================
 * Load trunk names
 * ========================= */
function load_trunks() {
    $file = "/etc/asterisk/pjsip.gui.endpoint.conf";
    $trunks = [];

    if (!file_exists($file)) return $trunks;

    $content = file_get_contents($file);
    $re = '/;\s*---\s*RCM-TRUNK:\s*([A-Za-z0-9_\-]+)\s*BEGIN\s*---([\s\S]*?);\s*---\s*RCM-TRUNK:\s*\1\s*END\s*---/m';

    if (preg_match_all($re, $content, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $ctx = "";
            if (preg_match('/^\s*context\s*=\s*(.+?)\s*$/m', $m[2], $cm)) {
                $ctx = trim($cm[1]);
            }
            $trunks[trim($m[1])] = $ctx;
        }
    }

    return $trunks;
}

/* =========================
 * Detect source
 * ========================= */
function detect_source($channel, $context, $trunks) {
    $trunk_contexts = ["from-trunk", "from-pri", "from-pstn", "from-provider"];

    if (!in_array(strtolower($context), $trunk_contexts, true)) {
        return "Internal";
    }

    if (preg_match('/^PJSIP\/([A-Za-z0-9_\-]+)-/', $channel, $m) && isset($trunks[$m[1]])) {
        return $m[1];
    }

    foreach ($trunks as $name => $ctx) {
        if (stripos($channel, $name) !== false) {
            return $name;
        }
    }

    return "Unknown Trunk";
}

/* =========================
 * Select best leg
 * سطر واحد لكل مكالمة
 * ========================= */
function channel_score($e) {
    $channel   = trim((string)($e["Channel"] ?? ""));
    $state     = strtolower(trim((string)($e["ChannelStateDesc"] ?? "")));
    $caller    = trim((string)($e["CallerIDNum"] ?? ""));
    $connected = trim((string)($e["ConnectedLineNum"] ?? ""));
    $context   = strtolower(trim((string)($e["Context"] ?? "")));
    $exten     = trim((string)($e["Exten"] ?? ""));
    $score = 0;

    if ($state === "up") $score += 50;
    if ($channel !== "" && strpos($channel, "Local/") !== 0) $score += 40;
    if ($connected !== "" && stripos($channel, "/" . $connected . "-") !== false) $score += 35;
    if ($caller !== "" && stripos($channel, "/" . $caller . "-") !== false) $score += 10;
    if ($connected !== "" && $caller !== "" && $connected !== $caller) $score += 10;
    if ($context === "from-internal") $score += 8;
    if (!preg_match('/^(s|h|i|t)$/i', $exten)) $score += 5;

    return $score;
}

function select_best_leg($legs) {
    usort($legs, function ($a, $b) {
        return channel_score($b) <=> channel_score($a);
    });
    return $legs[0];
}

/* =========================
 * Hangup endpoint
 * ========================= */
if (isset($_POST['action']) && $_POST['action'] === 'hangup') {
    header('Content-Type: application/json');

    $channel = trim($_POST['channel'] ?? '');
    if ($channel === '') {
        http_response_code(400);
        echo json_encode([
            "ok" => false,
            "error" => "Missing channel"
        ]);
        exit;
    }

    $fp = ami_connect($AMI_HOST, $AMI_PORT);
    ami_login($fp, $AMI_USER, $AMI_PASS);

    ami_hangup_channel($fp, $channel);
    list($resp, $raw) = ami_read_response_block($fp, 2);

    ami_action($fp, "Action: Logoff");
    fclose($fp);

    $ok = (strcasecmp($resp["Response"] ?? "", "Success") === 0);

    if (!$ok) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "AMI rejected hangup",
            "response" => $resp,
            "raw" => $raw
        ]);
        exit;
    }

    echo json_encode([
        "ok" => true,
        "channel" => $channel,
        "response" => $resp
    ]);
    exit;
}

/* =========================
 * JSON endpoint
 * ========================= */
if (isset($_GET['json'])) {
    header('Content-Type: application/json');

    $trunks = load_trunks();

    $fp = ami_connect($AMI_HOST, $AMI_PORT);
    ami_login($fp, $AMI_USER, $AMI_PASS);
    ami_action($fp, "Action: CoreShowChannels\r\nActionID: CHWEB");
    $lines = ami_read_until_complete($fp);
    $events = parse_ami_events($lines);
    ami_action($fp, "Action: Logoff");
    fclose($fp);

    $server_now = time();

    /* group by LinkedID -> one row per call */
    $by_linked = [];
    foreach ($events as $e) {
        if (($e["Event"] ?? "") !== "CoreShowChannel") continue;

        $channel = trim((string)($e["Channel"] ?? ""));
        if ($channel === "") continue;

        $linked = $e["Linkedid"] ?? $e["LinkedID"] ?? $e["Uniqueid"] ?? $channel;

        if (!isset($by_linked[$linked])) {
            $by_linked[$linked] = [];
        }
        $by_linked[$linked][] = $e;
    }

    $cache = load_answer_cache($ANSWER_CACHE_FILE);
    $seen_linked = [];
    $calls = [];

    foreach ($by_linked as $linked => $legs) {
        $e = select_best_leg($legs);

        $channel   = trim((string)($e["Channel"] ?? ""));
        $context   = trim((string)($e["Context"] ?? ""));
        $state_raw = trim((string)($e["ChannelStateDesc"] ?? ""));
        $state_lc  = strtolower($state_raw);

        $caller    = trim((string)($e["CallerIDNum"] ?? ""));
        $connected = trim((string)($e["ConnectedLineNum"] ?? ""));

        /* حاول نطلع caller/connected صح لو الرجل المختارة ناقصة */
        if ($caller === $connected || $caller === "" || $connected === "") {
            foreach ($legs as $leg) {
                $c1 = trim((string)($leg["CallerIDNum"] ?? ""));
                $c2 = trim((string)($leg["ConnectedLineNum"] ?? ""));
                if ($c1 !== "" && $c2 !== "" && $c1 !== $c2) {
                    $caller = $c1;
                    $connected = $c2;
                    break;
                }
            }
        }

        /* answered_at يبدأ من أول مرة الحالة تبقى Up */
        $answered_at = null;
        $cache_key = (string)$linked;
        $seen_linked[$cache_key] = true;

        if ($state_lc === "up") {
            if (!isset($cache[$cache_key]) || !is_array($cache[$cache_key])) {
                $cache[$cache_key] = [
                    "answered_at" => $server_now,
                    "last_seen"   => $server_now
                ];
            } else {
                if (empty($cache[$cache_key]["answered_at"])) {
                    $cache[$cache_key]["answered_at"] = $server_now;
                }
                $cache[$cache_key]["last_seen"] = $server_now;
            }

            $answered_at = (int)$cache[$cache_key]["answered_at"];
        } else {
            if (!isset($cache[$cache_key]) || !is_array($cache[$cache_key])) {
                $cache[$cache_key] = [
                    "answered_at" => null,
                    "last_seen"   => $server_now
                ];
            } else {
                $cache[$cache_key]["last_seen"] = $server_now;
            }
        }

        $calls[] = [
            "linkedid"    => $linked,
            "channel"     => $channel,
            "caller"      => $caller,
            "connected"   => $connected,
            "state"       => ($state_raw !== "" ? $state_raw : "Unknown"),
            "answered_at" => $answered_at,
            "source"      => detect_source($channel, $context, $trunks),
            "can_hangup"  => ($channel !== "")
        ];
    }

    /* cleanup cache for finished calls */
    foreach ($cache as $k => $row) {
        if (!isset($seen_linked[$k])) {
            unset($cache[$k]);
        }
    }

    save_answer_cache($ANSWER_CACHE_FILE, $cache);

    echo json_encode([
        "ok"         => true,
        "server_now" => $server_now,
        "total"      => count($calls),
        "calls"      => array_values($calls)
    ]);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Active Calls</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">

  <style>
    .panel-box {
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 18px;
      padding: 22px;
      margin-top: 18px;
    }

    .top {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 6px;
    }

    .stat-line {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 16px;
    }

    .live-dot {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #22c55e;
      box-shadow: 0 0 8px rgba(34,197,94,.9);
      margin-right: 6px;
      vertical-align: middle;
      animation: pulse 1.4s ease-in-out infinite;
    }

    @keyframes pulse {
      0%,100% { opacity:1; transform:scale(1); }
      50% { opacity:.5; transform:scale(.75); }
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th, td {
      border: 2px solid rgba(255,255,255,0.45);
      padding: 14px 16px;
      font-size: 15px;
      vertical-align: middle;
    }

    th {
      background: rgba(255,255,255,0.10);
      font-family: 'Orbitron', sans-serif;
      font-size: 13px;
      letter-spacing: 1px;
      text-align: center;
      white-space: nowrap;
    }

    tbody tr:hover {
      background: rgba(255,255,255,0.05);
    }

    .src-internal { background: rgba(99,179,237,.18);  border-color: rgba(99,179,237,.45); }
    .src-trunk    { background: rgba(245,158,11,.18);  border-color: rgba(245,158,11,.45); }
    .src-unknown  { background: rgba(148,163,184,.18); border-color: rgba(148,163,184,.35); }

    .channel-cell {
      color: rgba(255,255,255,.92);
      white-space: nowrap;
    }

    .state-cell,
    .dur-cell,
    .actions-cell {
      text-align: center;
      white-space: nowrap;
    }

    .action-btn {
      display: inline-block;
      padding: 10px 16px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.55);
      background: rgba(255,255,255,.08);
      color: #fff;
      cursor: pointer;
      font-weight: 700;
      font-family: 'Orbitron', sans-serif;
      font-size: 12px;
      letter-spacing: 1px;
      transition: .2s;
    }

    .action-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 0 12px rgba(255,255,255,.18);
    }

    .hangup-btn {
      background: rgba(220,38,38,.85);
      border-color: rgba(255,255,255,.8);
    }

    .hangup-btn:hover {
      background: rgba(239,68,68,.95);
    }
  </style>
</head>

<body>
<div class="wrap">
  <h1 class="page-title">ACTIVE CALLS</h1>

  <div class="inner-box">
    <div class="top">
      <a class="btn" href="/dashboard.php">BACK</a>
      <span class="pill"><span class="live-dot"></span>LIVE</span>
      <a class="btn" href="/logout.php">LOGOUT</a>
    </div>

    <div class="panel-box">
      <div class="stat-line">
        <span class="pill" id="totalPill">TOTAL ACTIVE CALLS: —</span>
      </div>

      <table>
        <thead>
          <tr>
            <th>Channel</th>
            <th>Caller</th>
            <th>Connected</th>
            <th>State</th>
            <th>Duration</th>
            <th>Source</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="callsBody">
          <tr><td colspan="7" class="muted">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function srcClass(src) {
    if (src === "Internal") return "src-internal";
    if (src === "Unknown Trunk") return "src-unknown";
    return "src-trunk";
  }

  function formatSecs(secs) {
    secs = parseInt(secs || 0, 10);
    if (secs < 0) secs = 0;

    const h = Math.floor(secs / 3600);
    const m = Math.floor((secs % 3600) / 60);
    const s = secs % 60;

    if (h > 0) {
      return String(h).padStart(2, '0') + ':' +
             String(m).padStart(2, '0') + ':' +
             String(s).padStart(2, '0');
    }

    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function tickDurations() {
    const now = Math.floor(Date.now() / 1000);

    document.querySelectorAll('td[data-answered]').forEach(td => {
      const at = parseInt(td.dataset.answered || '0', 10);
      td.textContent = formatSecs(now - at);
    });
  }

  function hangupCall(channel) {
    if (!channel) return;
    if (!confirm('Hang up this call?')) return;

    fetch(location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=hangup&channel=' + encodeURIComponent(channel)
    })
    .then(async r => {
      const data = await r.json();
      if (!r.ok || !data.ok) {
        console.error(data);
        throw new Error(data.error || 'Hangup failed');
      }
      return data;
    })
    .then(() => {
      fetchCalls();
    })
    .catch(err => {
      alert('Error: ' + err.message);
    });
  }

  function fetchCalls() {
    fetch(location.pathname + '?json=1', { cache: 'no-store' })
      .then(async r => {
        const data = await r.json();
        if (!r.ok || !data.ok) {
          throw new Error(data.error || ('HTTP ' + r.status));
        }
        return data;
      })
      .then(data => {
        document.getElementById('totalPill').textContent =
          'TOTAL ACTIVE CALLS: ' + data.total;

        const body = document.getElementById('callsBody');

        if (!data.calls || data.calls.length === 0) {
          body.innerHTML = '<tr><td colspan="7" class="muted">No active calls.</td></tr>';
          return;
        }

        body.innerHTML = data.calls.map(c => {
          let durCell = '<td class="dur-cell muted">Ringing…</td>';

          if (c.answered_at !== null && c.answered_at !== undefined) {
            const elapsed = Math.floor(Date.now() / 1000) - parseInt(c.answered_at, 10);
            durCell = '<td class="dur-cell" data-answered="' + esc(c.answered_at) + '">' + formatSecs(elapsed) + '</td>';
          }

          let actionCell = '<span class="muted">—</span>';
          if (c.can_hangup) {
            actionCell =
  '<button class="action-btn hangup-btn" onclick="hangupCall(\'' + c.channel + '\')">HANGUP</button>';
          }

          return '<tr>' +
            '<td class="channel-cell">' + esc(c.channel) + '</td>' +
            '<td>' + esc(c.caller) + '</td>' +
            '<td>' + esc(c.connected) + '</td>' +
            '<td class="state-cell">' + esc(c.state) + '</td>' +
            durCell +
            '<td><span class="pill ' + srcClass(c.source) + '">' + esc(c.source) + '</span></td>' +
            '<td class="actions-cell">' + actionCell + '</td>' +
          '</tr>';
        }).join('');
      })
      .catch(err => {
        document.getElementById('callsBody').innerHTML =
          '<tr><td colspan="7" class="muted">Error: ' + esc(err.message) + '</td></tr>';
      });
  }

  fetchCalls();
  setInterval(fetchCalls, 3000);
  setInterval(tickDurations, 1000);
</script>
</body>
</html>