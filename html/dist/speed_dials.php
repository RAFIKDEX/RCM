<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$CFG_FILE = "/etc/asterisk/extensions_gui.conf";

function parse_speed_dials($file){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $list = [];
  $inContext = false;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[speed-dials\]$/i', $t)) {
      $inContext = true;
      continue;
    }

    if ($inContext && preg_match('/^\[[^]]+\]$/', $t)) {
      $inContext = false;
      continue;
    }

    if (!$inContext) continue;
    if ($t === "") continue;

    $enabled = true;

    if (str_starts_with($t, ";")) {
      $enabled = false;
      $t = ltrim(substr($t, 1));
    }

    if (preg_match('/^exten\s*=>\s*(\d+),1,Goto\(([^,]+),([^,]+),1\)$/i', $t, $m)) {
      $ext = $m[1];
      $context = $m[2];
      $value = $m[3];

      $type = "unknown";
      $route = "";

      if (str_starts_with($context, "ivr-")) {
        $type = "ivr";
      } elseif (str_starts_with($context, "queue-")) {
        $type = "queue";
      } elseif ($context === "dexter") {
        $type = "extension";
      } elseif ($context === "rcm-ring-groups") {
        $type = "ring group";
      } elseif (str_starts_with($context, "ann-")) {
        $type = "announcement";
      } else {
        $type = "external number";
        $route = $context;
      }

      $list[] = [
        "enabled"   => $enabled,
        "extension" => $ext,
        "type"      => $type,
        "value"     => $value,
        "route"     => $route
      ];
    }
  }

  usort($list, fn($a, $b) => strnatcmp($a["extension"], $b["extension"]));
  return $list;
}

$speedDials = parse_speed_dials($CFG_FILE);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Speed Dials</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    html,body{height:100%;margin:0}

    body{
      font-family: Arial, sans-serif;
      background:
        radial-gradient(circle at 1px 1px, #3d3d3d 1px, transparent 0),
        #5398d7;
      background-size: 10px 10px;
      display:block;
      padding:24px;
      color:#fff;
    }

    .wrap{
      width:min(1200px, 96vw);
      background:rgba(0,0,0,0.25);
      border:2px solid rgba(255,255,255,0.2);
      border-radius:22px;
      box-shadow:0 20px 50px rgba(0,0,0,.35);
      padding:26px 24px;
      backdrop-filter: blur(6px);
      margin:40px auto;
    }

    .page-title{
      width:100%;
      text-align:center;
      font-family:'Orbitron', sans-serif;
      font-weight:900;
      letter-spacing:10px;
      text-transform:uppercase;
      font-size:52px;
      margin:0 0 30px 0;
      text-shadow:
        0 0 20px rgba(255,255,255,.6),
        0 0 40px rgba(255,255,255,.3);
    }

    .inner-box{
      background: rgba(0,0,0,0.30);
      border-radius: 25px;
      padding: 24px;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
    }

    .top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom:16px;
    }

    .btn{
      display:inline-block;
      padding:12px 20px;
      border-radius:40px;
      border:2px solid #fff;
      background:transparent;
      color:#fff;
      font-weight:900;
      letter-spacing:2px;
      text-decoration:none;
      transition:.2s;
      text-align:center;
      cursor:pointer;
      font-family:'Orbitron',sans-serif;
    }

    .btn:hover{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 22px rgba(255,255,255,.6);
    }

    table{
      width:100%;
      border-collapse:collapse;
      margin-top:16px;
    }

    th,td{
      border:1px solid rgba(255,255,255,0.22);
      padding:10px;
      text-align:center;
      vertical-align:middle;
    }

    th{
      background:rgba(255,255,255,0.10);
      font-family:'Orbitron',sans-serif;
      letter-spacing:1px;
    }

    .pill{
      display:inline-block;
      padding:6px 12px;
      border-radius:20px;
      border:1px solid rgba(255,255,255,.35);
      background:rgba(255,255,255,.10);
    }

    .actions{
      display:flex;
      gap:10px;
      justify-content:center;
      flex-wrap:wrap;
    }

    form{margin:0}
    .muted{opacity:.85}
  </style>
</head>
<body>

<div class="wrap">
  <h1 class="page-title">SPEED DIALS</h1>

  <div class="inner-box">
    <div class="top">
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn" href="/add_speed_dial.php">ADD SPEED DIAL</a>
        <a class="btn" href="/dashboard.php">CANCEL</a>
      </div>
    </div>

    <?php if (empty($speedDials)): ?>
      <p class="muted" style="margin-top:16px">No Speed Dials found yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Enable</th>
            <th>Extension</th>
            <th>Type</th>
            <th>Value</th>
            <th>Route</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($speedDials as $x): ?>
            <tr>
              <td><span class="pill"><?php echo $x["enabled"] ? "ON" : "OFF"; ?></span></td>
              <td><?php echo htmlspecialchars($x["extension"]); ?></td>
              <td><?php echo htmlspecialchars($x["type"]); ?></td>
              <td><?php echo htmlspecialchars($x["value"]); ?></td>
              <td><?php echo htmlspecialchars($x["route"]); ?></td>
              <td>
                <div class="actions">
                  <a class="btn" href="/edit_speed_dial.php?ext=<?php echo urlencode($x["extension"]); ?>">EDIT</a>
                  <a class="btn" href="/delete_speed_dial.php?ext=<?php echo urlencode($x["extension"]); ?>">DELETE</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

</body>
</html>