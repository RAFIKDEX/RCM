<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_time.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    die('Invalid Holiday ID.');
}

$holiday = null;
$entries = [];

$stmt = $conn_time->prepare("SELECT id, name, memo, created_at FROM holidays WHERE id = ?");
if (!$stmt) {
    die('Prepare failed: ' . $conn_time->error);
}

$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
    die('Execute failed: ' . $stmt->error);
}

$result = $stmt->get_result();
$holiday = $result->fetch_assoc();
$stmt->close();

if (!$holiday) {
    die('Holiday not found.');
}

$entry_stmt = $conn_time->prepare("
    SELECT id, years, week_days, months, month_days, time_from, time_to
    FROM holiday_entries
    WHERE holiday_id = ?
    ORDER BY id ASC
");
if (!$entry_stmt) {
    die('Prepare failed: ' . $conn_time->error);
}

$entry_stmt->bind_param("i", $id);

if (!$entry_stmt->execute()) {
    die('Execute failed: ' . $entry_stmt->error);
}

$entries_result = $entry_stmt->get_result();
while ($row = $entries_result->fetch_assoc()) {
    $entries[] = $row;
}
$entry_stmt->close();

function build_badges($csv, $fallback = 'Default')
{
    $csv = trim((string)$csv);

    if ($csv === '') {
        return '<span class="badge">' . htmlspecialchars($fallback) . '</span>';
    }

    $items = array_filter(array_map('trim', explode(',', $csv)));
    if (count($items) === 0) {
        return '<span class="badge">' . htmlspecialchars($fallback) . '</span>';
    }

    $html = '';
    foreach ($items as $item) {
        $html .= '<span class="badge">' . htmlspecialchars($item) . '</span>';
    }

    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Holiday</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <link rel="stylesheet" href="/assets/rcm.css">

  <style>
    .top-actions{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:24px;
    }

    .page-subtitle{
      margin:0;
      opacity:.9;
      font-size:16px;
    }

    .info-card,
    .entries-card{
      background:rgba(20,48,78,.78);
      border:1px solid rgba(255,255,255,.16);
      border-radius:28px;
      padding:24px;
      margin-bottom:24px;
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.04),
        0 10px 30px rgba(0,0,0,.18);
    }

    .card-title{
      margin:0 0 18px 0;
      font-family:'Orbitron', sans-serif;
      letter-spacing:2px;
      font-size:24px;
    }

    .info-grid{
      display:grid;
      grid-template-columns:repeat(3, minmax(220px, 1fr));
      gap:18px;
    }

    .info-box{
      background:rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.14);
      border-radius:18px;
      padding:18px;
    }

    .info-label{
      font-size:14px;
      opacity:.8;
      margin-bottom:8px;
      text-transform:uppercase;
      letter-spacing:1px;
    }

    .info-value{
      font-size:22px;
      font-weight:bold;
      word-break:break-word;
    }

    .table-shell{
      width:100%;
      border:2px solid rgba(255,255,255,.38);
      overflow:hidden;
      background:rgba(17,44,72,.42);
    }

    .table-wrap{
      width:100%;
      overflow-x:auto;
    }

    .table-wrap table{
      width:100%;
      margin:0;
      border-collapse:collapse;
    }

    .table-wrap th,
    .table-wrap td{
      border:1px solid rgba(255,255,255,.34);
      padding:18px 20px;
      text-align:center;
      vertical-align:middle;
      color:#fff;
    }

    .table-wrap th{
      background:rgba(255,255,255,.12);
      font-family:'Orbitron', sans-serif;
      font-size:15px;
      letter-spacing:1px;
    }

    .badge{
      display:inline-block;
      padding:6px 12px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.22);
      background:rgba(255,255,255,.08);
      font-size:13px;
      margin:2px 4px 2px 0;
    }

    .cell-days{
      line-height:1.9;
      min-width:180px;
    }

    .empty-row{
      text-align:center;
      opacity:.85;
      padding:24px 12px;
    }

    @media (max-width: 900px){
      .info-grid{
        grid-template-columns:1fr;
      }

      .card-title{
        font-size:20px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top-actions">
      <div>
        <h1 class="page-title" style="margin-bottom:8px;">VIEW HOLIDAY</h1>
        <p class="page-subtitle">See all entries inside this Holiday.</p>
      </div>

      <div class="bottom-actions" style="margin-top:0;">
        <a href="/time_condition.php?tab=holiday" class="btn">Back</a>
      </div>
    </div>

    <div class="inner-box">
      <div class="info-card">
        <h2 class="card-title">HOLIDAY INFO</h2>

        <div class="info-grid">
          <div class="info-box">
            <div class="info-label">ID</div>
            <div class="info-value"><?php echo (int)$holiday['id']; ?></div>
          </div>

          <div class="info-box">
            <div class="info-label">Name</div>
            <div class="info-value"><?php echo htmlspecialchars($holiday['name']); ?></div>
          </div>

          <div class="info-box">
            <div class="info-label">Created</div>
            <div class="info-value" style="font-size:18px;"><?php echo htmlspecialchars($holiday['created_at']); ?></div>
          </div>
        </div>
      </div>

      <div class="entries-card">
        <h2 class="card-title">HOLIDAY ENTRIES</h2>

        <div class="table-shell">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th style="width:80px;">#</th>
                  <th>Year</th>
                  <th>Month</th>
                  <th>Day</th>
                  <th style="width:140px;">From</th>
                  <th style="width:140px;">To</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($entries) > 0): ?>
                  <?php foreach ($entries as $index => $entry): ?>
                    <tr>
                      <td><?php echo $index + 1; ?></td>
                      <td class="cell-days"><?php echo build_badges($entry['years'], 'All Years'); ?></td>
                      <td class="cell-days"><?php echo build_badges($entry['months'], 'Default'); ?></td>
                      <td class="cell-days"><?php echo build_badges($entry['month_days'], 'Default'); ?></td>
                      <td><?php echo htmlspecialchars($entry['time_from'] ?: '00:00:00'); ?></td>
                      <td><?php echo htmlspecialchars($entry['time_to'] ?: '23:59:00'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6" class="empty-row">No entries found for this Holiday.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
