<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$CFG_FILE = "/etc/asterisk/extensions_gui.conf";

function parse_pickup_groups($file){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $groups = [];
  $cur = null;
  $pendingName = null;

  foreach ($lines as $line) {
    $t = trim($line);

    // comment name style
    if (preg_match('/^;\s*---\s*PICKUP GROUP GUI:\s*(.+?)\s*---$/i', $t, $m)) {
      $pendingName = trim($m[1]);
      continue;
    }

    // section start
    if (preg_match('/^\[pickup-(\d{1,10})\]$/i', $t, $m)) {
      $num = $m[1];
      $cur = "pickup-" . $num;

      $groups[$cur] = [
        "section" => $cur,
        "group"   => $num,
        "name"    => $pendingName ?: ("pickup-" . $num),
        "members" => []
      ];

      $pendingName = null;
      continue;
    }

    if (!$cur) continue;

    // name=
    if (preg_match('/^name\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $groups[$cur]["name"] = trim($m[1]);
      continue;
    }

    // group=
    if (preg_match('/^group\s*=\s*(\d{1,10})\s*$/i', $t, $m)) {
      $groups[$cur]["group"] = $m[1];
      continue;
    }

    // members=
    if (preg_match('/^members\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $raw = trim($m[1]);
      if ($raw === "") {
        $groups[$cur]["members"] = [];
      } else {
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, fn($x) => $x !== ""));
        $groups[$cur]["members"] = $parts;
      }
      continue;
    }

    // end section
    if (preg_match('/^\[[^]]+\]$/', $t) && !preg_match('/^\[pickup-(\d{1,10})\]$/i', $t)) {
      $cur = null;
      continue;
    }
  }

  uasort($groups, fn($a, $b) => strnatcmp($a["group"], $b["group"]));
  return array_values($groups);
}

$pickupGroups = parse_pickup_groups($CFG_FILE);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pickup Groups</title>

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

  display:block; /* ??? */
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
      margin:40px auto; /* ?? ???? ???????? ?????? */
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

    .members{
      text-align:left;
      line-height:1.8;
      max-width:320px;
      margin:auto;
      white-space:normal;
      word-break:break-word;
    }
  </style>
</head>
<body>

<div class="wrap">
  <h1 class="page-title">PICKUP GROUPS</h1>

  <div class="inner-box">
    <div class="top">
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn" href="/add_pickup_group.php">ADD PICKUP GROUP</a>
        <a class="btn" href="/dashboard.php">CANCEL</a>
      </div>
    </div>

    <?php if (empty($pickupGroups)): ?>
      <p class="muted" style="margin-top:16px">No Pickup Groups found yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Group</th>
            <th>Name</th>
            <th>Members Count</th>
            <th>Members</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pickupGroups as $x): ?>
            <tr>
              <td><span class="pill"><?php echo htmlspecialchars($x["group"]); ?></span></td>
              <td><?php echo htmlspecialchars($x["name"]); ?></td>
              <td><?php echo count($x["members"]); ?></td>
              <td class="members"><?php echo htmlspecialchars(implode(", ", $x["members"])); ?></td>
              <td>
                <div class="actions">
                  <a class="btn" href="/edit_pickup_group.php?g=<?php echo urlencode($x["group"]); ?>">EDIT</a>

                  <form method="post"
                        action="/delete_pickup_group.php"
                        onsubmit="return confirm('Delete Pickup Group <?php echo htmlspecialchars($x["group"]); ?> ?');">
                    <input type="hidden" name="g" value="<?php echo htmlspecialchars($x["group"]); ?>">
                    <button class="btn" type="submit">DELETE</button>
                  </form>
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
