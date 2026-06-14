<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ASTERISK_DIR = "/etc/asterisk";
$F_ENDPOINT  = "$ASTERISK_DIR/pjsip.gui.endpoint.conf";
$F_REG       = "$ASTERISK_DIR/pjsip.gui.reg.conf";
$F_IDENTIFY  = "$ASTERISK_DIR/pjsip.gui.identify.conf";

/* ===== Helpers ===== */
function read_file_safe($path) {
  return file_exists($path) ? file_get_contents($path) : "";
}

function parse_marker_blocks($content) {
  $blocks = [];
  if (!$content) return $blocks;
  $re = '/^\s*;\s*---\s*RCM-TRUNK:\s*([A-Za-z0-9_\-]+)\s*BEGIN\s*---\s*$([\s\S]*?)^\s*;\s*---\s*RCM-TRUNK:\s*\1\s*END\s*---\s*$/m';
  if (preg_match_all($re, $content, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
      $blocks[$hit[1]] = $hit[2];
    }
  }
  return $blocks;
}

function kv_from_block($block, $key) {
  $re = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+?)\s*$/m';
  if (preg_match($re, $block, $m)) return trim($m[1]);
  return "";
}

function ini_get_value($content, $section, $key) {
  $re = '/^\s*\[' . preg_quote($section, '/') . '\]\s*$([\s\S]*?)(?=^\s*\[|\z)/m';
  if (!preg_match($re, $content, $m)) return "";
  $body = $m[1];
  $re2 = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+?)\s*$/m';
  if (preg_match($re2, $body, $m2)) return trim($m2[1]);
  return "";
}

function get_mode_for_trunk($name, $endpoint_block, $reg_file, $identify_file) {
  $reg_content = read_file_safe($reg_file);
  if (preg_match('/^\s*\[' . preg_quote($name . '-reg', '/') . '\]\s*$/m', $reg_content)) {
    return "reg-client";
  }
  $id_content = read_file_safe($identify_file);
  if (preg_match('/^\s*\[' . preg_quote($name . '_identify', '/') . '\]\s*$/m', $id_content)) {
    return "peer";
  }
  $auth = kv_from_block($endpoint_block, "auth");
  if ($auth !== "") return "reg-server";
  return "peer";
}

function trunk_status($mode, $name) {
  $cmd = "sudo /usr/local/bin/rcm_pjsip_status.sh " . escapeshellarg($mode) . " " . escapeshellarg($name);
  $out = shell_exec($cmd);
  $data = json_decode($out ?: "", true);
  if (!is_array($data) || empty($data["ok"])) {
    return ["status" => "UNKNOWN", "raw" => trim($out ?: "")];
  }
  return $data;
}

function status_pill_class($status) {
  $s = strtolower((string)$status);
  if ($s === "reachable")    return "pill-ok";
  if ($s === "unreachable")  return "pill-bad";
  if ($s === "rejected")     return "pill-bad";
  if ($s === "unregistered") return "pill-warn";
  if ($s === "unqualified")  return "pill-unk";
  return "pill-unk";
}

function status_dot_class($status) {
  $s = strtolower((string)$status);
  if ($s === "reachable") return "dot-green";
  return "dot-red";
}

function default_port_from_transport($transport) {
  $t = strtolower((string)$transport);
  if (strpos($t, "tls") !== false) return 5061;
  return 5060;
}

function extract_host_port($value, $transport) {
  $value = trim((string)$value);
  if ($value === "") return "";
  $value = trim($value, "<> \t\n\r\0\x0B");
  $value = preg_split('/[;\?]/', $value, 2)[0];
  $value = preg_replace('/^\s*sips?:/i', '', $value);
  if (strpos($value, '@') !== false) {
    $parts = explode('@', $value);
    $value = end($parts);
  }
  $host = $value;
  $port = null;
  if (preg_match('/^\[(.+)\](?::(\d+))?$/', $value, $m)) {
    $host = $m[1];
    $port = isset($m[2]) ? (int)$m[2] : null;
  } else {
    if (preg_match('/^(.+):(\d+)$/', $value, $m)) {
      $host = $m[1];
      $port = (int)$m[2];
    }
  }
  $host = trim($host);
  if ($host === "") return "";
  if ($port === null) $port = default_port_from_transport($transport);
  if (strpos($host, ':') !== false && strpos($host, '.') === false) {
    return "[" . $host . "]:" . $port;
  }
  return $host . ":" . $port;
}

/* ===== Load trunks ===== */
$endpoint_content = read_file_safe($F_ENDPOINT);
$endpoint_blocks  = parse_marker_blocks($endpoint_content);
$reg_content      = read_file_safe($F_REG);
$identify_content = read_file_safe($F_IDENTIFY);

$trunks = [];
foreach ($endpoint_blocks as $name => $block) {
  $transport = kv_from_block($block, "transport");
  $mode = get_mode_for_trunk($name, $block, $F_REG, $F_IDENTIFY);

  $ipdomain = "";

  if ($mode === "reg-client") {
    $server_uri = ini_get_value($reg_content, $name . "-reg", "server_uri");
    $ipdomain = extract_host_port($server_uri, $transport);
  }

  if ($ipdomain === "" && $mode === "peer") {
    $match = ini_get_value($identify_content, $name . "_identify", "match");
    $ipdomain = extract_host_port($match, $transport);
    if ($ipdomain === "" && trim((string)$match) !== "") {
      $ipdomain = trim($match) . ":" . default_port_from_transport($transport);
    }
  }

  if ($ipdomain === "") {
    $server_uri = kv_from_block($block, "server_uri");
    $contact    = kv_from_block($block, "contact");
    $out_proxy  = kv_from_block($block, "outbound_proxy");
    $ipdomain   = extract_host_port($server_uri ?: ($out_proxy ?: $contact), $transport);
  }

  if ($ipdomain === "") $ipdomain = "-";

  $st     = trunk_status($mode, $name);
  $status = $st["status"] ?? "UNKNOWN";

  $trunks[] = [
    "name"      => $name,
    "mode"      => $mode,
    "status"    => $status,
    "transport" => $transport ?: "-",
    "ipdomain"  => $ipdomain,
  ];
}

usort($trunks, fn($a,$b) => strcmp($a["name"], $b["name"]));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Trunks</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">

  <style>
    /* Status pill colours */
    .pill-ok   { background: rgba(34,197,94,.20);  border-color: rgba(34,197,94,.40);  }
    .pill-warn { background: rgba(245,158,11,.20); border-color: rgba(245,158,11,.40); }
    .pill-bad  { background: rgba(239,68,68,.20);  border-color: rgba(239,68,68,.40);  }
    .pill-unk  { background: rgba(148,163,184,.18); border-color: rgba(148,163,184,.35); }

    /* Status dot */
    .status-dot {
      display: inline-block;
      width: 9px;
      height: 9px;
      border-radius: 50%;
      margin-right: 8px;
      vertical-align: middle;
    }
    .dot-green { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,.7); }
    .dot-red   { background: #ef4444; box-shadow: 0 0 6px rgba(239,68,68,.7); }

    /* panel-box: المربع الداخلي جوه inner-box */
    .panel-box {
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 18px;
      padding: 22px;
      margin-top: 18px;
    }

    /* top buttons row */
    .top {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 6px;
    }

    /* force all .btn in .top to look identical — outline only */
    .top .btn {
      background: transparent !important;
      color: #fff !important;
    }

    /* small action buttons in table */
    .btn.sm {
      padding: 7px 16px;
      font-size: 12px;
      letter-spacing: 1.5px;
      border-radius: 30px;
    }

    td.actions { white-space: nowrap; }

    /* table full width */
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      padding: 14px 16px;
      font-size: 15px;
      border: 2px solid rgba(255,255,255,0.45);
    }
    th {
      background: rgba(255,255,255,0.12);
    }
    tbody tr:hover { background: rgba(255,255,255,0.05); }
  </style>
</head>

<body>
<div class="wrap">

  <h1 class="page-title">TRUNKS</h1>

  <div class="inner-box">

    <div class="top">
      <a class="btn" href="/add_trunk.php">ADD TRUNK</a>
      <a class="btn" href="/dashboard.php">BACK</a>
    </div>

    <div class="panel-box">

      <p class="muted" style="margin: 0 0 16px 2px;">Powered by RAFIK</p>

      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Mode</th>
            <th>Status</th>
            <th>Transport</th>
            <th>IP / Domain</th>
            <th>Actions</th>
          </tr>
        </thead>

        <tbody>
        <?php if (!$trunks): ?>
          <tr>
            <td colspan="6" class="muted">No trunks found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($trunks as $t): ?>
            <tr>
              <td><span class="pill"><?php echo htmlspecialchars($t["name"]); ?></span></td>

              <td class="muted"><?php echo htmlspecialchars($t["mode"]); ?></td>

              <td>
                <span class="pill <?php echo status_pill_class($t["status"]); ?>">
                  <span class="status-dot <?php echo status_dot_class($t["status"]); ?>"></span>
                  <?php echo htmlspecialchars($t["status"]); ?>
                </span>
              </td>

              <td class="muted"><?php echo htmlspecialchars($t["transport"]); ?></td>
              <td class="muted"><?php echo htmlspecialchars($t["ipdomain"]); ?></td>

              <td class="actions">
                <a class="btn sm" href="/edit_trunk.php?name=<?php echo urlencode($t["name"]); ?>">EDIT</a>
                <a class="btn sm"
                   href="/delete_trunk.php?name=<?php echo urlencode($t["name"]); ?>"
                   onclick="return confirm('Delete trunk: <?php echo htmlspecialchars(addslashes($t["name"])); ?>?');">DELETE</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>

    </div><!-- /panel-box -->
  </div><!-- /inner-box -->
</div><!-- /wrap -->
</body>
</html>