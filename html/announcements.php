<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$DP_FILE = "/etc/asterisk/extensions_gui.conf";

function load_announcements($file){
  if (!file_exists($file)) return [];
  $lines = file($file, FILE_IGNORE_NEW_LINES);

  $list = [];
  $cur = null;
  $pendingName = null;

  foreach ($lines as $line){
    $t = trim($line);

    if (preg_match('/^;\s*---\s*ANN GUI:\s*([a-zA-Z0-9_-]+)\s*---/', $t, $m)){
      $pendingName = $m[1];
      continue;
    }

    if (preg_match('/^\[ann-(\d{2,6})\]$/', $t, $m)){
      $cur = $m[1];
      $list[$cur] = [
        "num" => $cur,
        "name" => $pendingName ?? ("ann-" . $cur),
        "dest" => "HANGUP"
      ];
      $pendingName = null;
      continue;
    }

    if (!$cur) continue;

    if (preg_match('/Goto\(internal,(\d{2,6}),1\)/', $t, $m)){
      $list[$cur]["dest"] = "EXT " . $m[1];
    }
    elseif (preg_match('/Goto\(ivr-(\d{2,6}),(\d{2,6}),1\)/', $t, $m)){
      $list[$cur]["dest"] = "IVR " . $m[1];
    }
    elseif (preg_match('/Queue\(([a-zA-Z0-9_-]+)\)/', $t, $m)){
      $list[$cur]["dest"] = "QUEUE " . $m[1];
    }
    elseif (stripos($t, 'Hangup()') !== false){
      $list[$cur]["dest"] = "HANGUP";
    }
  }

  return array_values($list);
}

$announcements = load_announcements($DP_FILE);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Announcements</title>

  <!-- Orbitron -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">

  <!-- Theme -->
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    .wrap{ width:min(1100px,96vw); }
  </style>
</head>
<body>

<div class="wrap">

  <h1 class="page-title">ANNOUNCEMENTS</h1>

  <div class="inner-box">

    <div class="top">
      <a class="btn" href="/add_announcement.php">ADD ANNOUNCEMENT</a>
      <a class="btn" href="/dashboard.php">BACK</a>
    </div>

    <!-- ✅ مربع داخلي زي ما اتفقنا -->
    <div class="panel-box">

      <table>
        <thead>
          <tr>
            <th>Number</th>
            <th>Name</th>
            <th>Destination</th>
            <th>Actions</th>
          </tr>
        </thead>

        <tbody>
          <?php if (!$announcements): ?>
            <tr>
              <td colspan="4" class="muted">No announcements found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($announcements as $a): ?>
              <tr>
                <td><span class="pill"><?php echo htmlspecialchars($a["num"]); ?></span></td>
                <td><?php echo htmlspecialchars($a["name"]); ?></td>
                <td><span class="pill"><?php echo htmlspecialchars($a["dest"]); ?></span></td>
                <td class="actions">
                  <a class="btn sm" href="/add_announcement.php?edit=<?php echo urlencode($a["num"]); ?>">EDIT</a>
                  <a class="btn sm" href="/delete_announcement.php?num=<?php echo urlencode($a["num"]); ?>" onclick="return confirm('Delete this announcement?')">DELETE</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

    </div><!-- panel-box -->

  </div><!-- inner-box -->

</div><!-- wrap -->

</body>
</html>
