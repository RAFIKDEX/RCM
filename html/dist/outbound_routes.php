<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$JSON_FILE = "/etc/asterisk/rcm_outbound_routes.json";

function rcm_load_json(string $jsonFile): array {
  if (!file_exists($jsonFile)) return ["routes" => []];
  $raw = file_get_contents($jsonFile);
  $j = json_decode($raw, true);
  if (!is_array($j)) return ["routes" => []];
  if (isset($j["routes"]) && is_array($j["routes"])) return $j;
  if (array_is_list($j)) return ["routes" => $j];
  return ["routes" => []];
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$data = rcm_load_json($JSON_FILE);
$routes = $data["routes"] ?? [];
usort($routes, fn($a,$b)=> strnatcasecmp((string)($a["name"]??""),(string)($b["name"]??"")));

$msg = $_GET["msg"] ?? "";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Outbound Routes</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

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
      margin-bottom: 6px;
    }

    .top .btn {
      background: transparent !important;
      color: #fff !important;
    }

    .btn.sm {
      padding: 7px 16px;
      font-size: 12px;
      letter-spacing: 1.5px;
      border-radius: 30px;
    }

    td.actions { white-space: nowrap; }

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

    .pill-on  { background: rgba(34,197,94,.20);  border-color: rgba(34,197,94,.40);  }
    .pill-off { background: rgba(239,68,68,.20);   border-color: rgba(239,68,68,.40);  }

    .msg {
      margin: 10px 0 0 0;
      padding: 10px 14px;
      border-radius: 14px;
      background: rgba(0,255,200,.10);
      border: 1px solid rgba(0,255,200,.25);
    }
  </style>
</head>
<body>
<div class="wrap">

  <h1 class="page-title">OUTBOUND ROUTES</h1>

  <?php if ($msg): ?>
    <div class="msg"><?php echo h($msg); ?></div>
  <?php endif; ?>

  <div class="inner-box">

    <div class="top">
      <a class="btn" href="/add_outbound_route.php">ADD ROUTE</a>
      <a class="btn" href="/dashboard.php">BACK</a>
    </div>

    <div class="panel-box">

      <p class="muted" style="margin: 0 0 16px 2px;">Powered by RAFIK</p>

      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Enabled</th>
            <th>Mode</th>
            <th>Patterns</th>
            <th>Trunks</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($routes)): ?>
          <tr><td colspan="6" class="muted">No routes yet.</td></tr>
        <?php else: ?>
          <?php foreach ($routes as $r): ?>
            <?php
$ctx     = (string)($r["context"] ?? "");
$name    = (string)($r["name"] ?? "");
// ?? name ????? ?????? ??? slug ?? ??? context
if ($name === "" && str_starts_with($ctx, "rcm-out-")) {
    $name = substr($ctx, 8);
}
              $ctx     = (string)($r["context"] ?? "");
              $enabled = !empty($r["enabled"]);
              $mode    = (string)($r["mode"]    ?? "whitelist");
              $pats    = $r["patterns"] ?? [];
              $trunks  = $r["trunks"]   ?? [];
            ?>
            <tr>
              <td>
                <span class="pill"><?php echo h($name ?: $ctx); ?></span>
              </td>

              <td>
                <span class="pill <?php echo $enabled ? 'pill-on' : 'pill-off'; ?>">
                  <?php echo $enabled ? 'YES' : 'NO'; ?>
                </span>
              </td>

              <td class="muted"><?php echo h($mode); ?></td>

              <td class="muted">
                <?php echo h(is_array($pats) ? implode(", ", $pats) : ""); ?>
              </td>

              <td class="muted">
                <?php echo h(is_array($trunks) ? implode(" > ", $trunks) : ""); ?>
              </td>

              <td class="actions">
                <a class="btn sm" href="/add_outbound_route.php?ctx=<?php echo urlencode($ctx); ?>">EDIT</a>
                <a class="btn sm"
                   href="/save_outbound_route.php?delete=1&context=<?php echo urlencode($ctx); ?>"
                   onclick="return confirm('Delete route <?php echo h(addslashes($name ?: $ctx)); ?>?');">DELETE</a>
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