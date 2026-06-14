<?php
require_once __DIR__ . '/auth.php';
rcm_require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$JSON = '/etc/asterisk/rcm_announcement_paging.json';
function rcm_load_ann(string $path): array {
    if (!file_exists($path)) return ["items" => []];
    $raw = file_get_contents($path);
    $j = json_decode($raw, true);
    if (!is_array($j)) return ["items" => []];
    if (isset($j["items"]) && is_array($j["items"])) return $j;
    if (array_is_list($j)) return ["items" => $j];
    return ["items" => []];
}
$data  = rcm_load_ann($JSON);
$items = $data["items"] ?? [];
usort($items, function($a, $b) {
    return strnatcmp((string)($a["id"] ?? ""), (string)($b["id"] ?? ""));
});
$msg = trim((string)($_GET["msg"] ?? ""));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Announcement Paging</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
</head>
<body>
<div class="wrap">
  <h1 class="page-title">ANNOUNCEMENT PAGING</h1>
  <div class="inner-box">
    <div class="top">
      <div class="btn-row">
        <a class="btn" href="dashboard.php">BACK</a>
        <a class="btn" href="add_announcement_paging.php">ADD</a>
      </div>
      <?php if ($msg !== ""): ?>
        <span class="pill"><?php echo htmlspecialchars($msg); ?></span>
      <?php endif; ?>
      <span class="muted">Powered by RAFIK</span>
    </div>
    <div class="panel-box">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Prompt</th>
            <th>Members</th>
            <th>Schedule</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="6" class="muted">No announcement paging entries yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($items as $x): ?>
            <?php
              $id         = (string)($x["id"] ?? "");
              $name       = (string)($x["name"] ?? "");
              $prompt     = (string)($x["prompt_name"] ?? $x["welcome_prompt"] ?? "-");
              $enabled    = (bool)($x["enabled"] ?? true);
              $play_count = (int)($x["play_count"] ?? 1);
              $time       = (string)($x["time"] ?? "");
              $days       = $x["days"] ?? [];
              if (!is_array($days)) $days = [];
              $members    = $x["members"] ?? [];
              if (!is_array($members)) $members = [];
            ?>
            <tr>
              <td><?php echo htmlspecialchars($name); ?></td>
              <td class="muted"><?php echo htmlspecialchars($prompt); ?></td>
              <td class="muted"><?php echo htmlspecialchars(implode(', ', array_map('strval', $members))); ?></td>
              <td class="muted">
                <?php echo htmlspecialchars(implode(', ', $days)); ?>
                <?php if ($time !== ""): ?>
                  @ <?php echo htmlspecialchars($time); ?>
                <?php endif; ?>
                <br>
                <span class="pill">x<?php echo $play_count; ?></span>
              </td>
              <td>
                <span class="pill" style="<?php echo $enabled
                  ? 'background:rgba(80,200,120,0.18); border-color:rgba(80,200,120,0.6); color:#7fffaa;'
                  : 'background:rgba(220,80,80,0.18); border-color:rgba(220,80,80,0.5); color:#ffaaaa;'; ?>">
                  <?php echo $enabled ? 'ENABLED' : 'DISABLED'; ?>
                </span>
              </td>
              <td class="actions">
                <a class="btn sm" href="add_announcement_paging.php?id=<?php echo urlencode($id); ?>">EDIT</a>
                <a class="btn sm"
                   href="save_announcement_paging.php?delete=1&id=<?php echo urlencode($id); ?>"
                   onclick="return confirm('Delete <?php echo htmlspecialchars($name, ENT_QUOTES); ?> ?')">DELETE</a>
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