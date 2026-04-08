<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$JSON = "/etc/asterisk/rcm_ring_groups.json";

function rcm_load_rg(string $path): array {
  if (!file_exists($path)) return ["groups" => []];
  $raw = file_get_contents($path);
  $j = json_decode($raw, true);
  if (!is_array($j)) return ["groups" => []];
  if (isset($j["groups"]) && is_array($j["groups"])) return $j;
  if (array_is_list($j)) return ["groups" => $j];
  return ["groups" => []];
}

$data = rcm_load_rg($JSON);
$groups = $data["groups"] ?? [];
usort($groups, function($a,$b){
  return strnatcmp((string)($a["id"]??""),(string)($b["id"]??""));
});

$msg = trim((string)($_GET["msg"] ?? ""));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Ring Groups</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    body{display:block!important;}
    .wrap{width:min(1200px,96vw); margin:40px auto 60px auto;}
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
    .center-title{
      flex:1;min-width:240px;text-align:center;
      font-family:'Orbitron',sans-serif;font-weight:900;letter-spacing:6px;text-transform:uppercase;
      font-size:44px;text-shadow:0 0 20px rgba(255,255,255,.30);pointer-events:none;
    }
    .msg{margin:10px 0 14px 0;}
    .pill2{display:inline-block;padding:6px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.06);font-weight:800}
    .act-btn{min-width:auto;padding:10px 14px;border-radius:30px;margin-right:8px;}
    @media(max-width:900px){ .center-title{font-size:30px;} }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <a class="btn" href="/dashboard.php">BACK</a>
      <a class="btn" href="/add_ring_group.php">ADD</a>
      <div class="center-title">RING GROUPS</div>
      <span class="muted">Powered by RAFIK</span>
    </div>

    <?php if($msg !== ""): ?>
      <div class="inner-box msg"><div class="pill2"><?php echo htmlspecialchars($msg); ?></div></div>
    <?php endif; ?>

    <div class="inner-box" style="margin-top:0;">
      <div class="panel-box" style="margin-top:0;">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Strategy</th>
              <th>Members</th>
              <th>Timeout</th>
              <th>Per Try</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($groups)): ?>
              <tr><td colspan="8" class="muted">No ring groups yet.</td></tr>
            <?php else: ?>
              <?php foreach($groups as $g): ?>
                <?php
                  $id = (string)($g["id"] ?? "");
                  $name = (string)($g["name"] ?? "");
                  $strategy = (string)($g["strategy"] ?? "ringall");
                  $members = $g["members"] ?? [];
                  if(!is_array($members)) $members=[];
                  $enabled = ($g["enabled"] ?? true) ? true:false;
                  $timeout = (int)($g["timeout"] ?? 20);
                  $per = (int)($g["per_try"] ?? 10);
                ?>
                <tr>
                  <td style="font-family:'Orbitron',sans-serif;"><?php echo htmlspecialchars($id); ?></td>
                  <td><?php echo htmlspecialchars($name); ?></td>
                  <td><span class="pill"><?php echo htmlspecialchars($strategy); ?></span></td>
                  <td class="muted"><?php echo htmlspecialchars(implode(", ", array_map("strval",$members))); ?></td>
                  <td class="muted"><?php echo $timeout; ?></td>
                  <td class="muted"><?php echo $per; ?></td>
                  <td><?php echo $enabled ? '<span class="pill">YES</span>' : '<span class="pill">NO</span>'; ?></td>
                  <td>
                    <a class="btn sm act-btn" href="/add_ring_group.php?id=<?php echo urlencode($id); ?>">EDIT</a>
                    <a class="btn sm act-btn"
                       href="/save_ring_group.php?delete=1&id=<?php echo urlencode($id); ?>"
                       onclick="return confirm('Delete ring group <?php echo htmlspecialchars($id); ?> ?');">
                       DELETE
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
