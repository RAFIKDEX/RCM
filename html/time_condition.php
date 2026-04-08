<?php
require_once 'db_time.php';

$tab = $_GET['tab'] ?? 'office';
if (!in_array($tab, ['office', 'holiday'])) {
    $tab = 'office';
}

$office_times = [];
$holidays = [];
$success_message = '';

if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $success_message = 'Office Time deleted successfully.';
}

if (isset($_GET['holiday_deleted']) && $_GET['holiday_deleted'] == '1') {
    $success_message = 'Holiday deleted successfully.';
}

if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $success_message = 'Office Time updated successfully.';
}

if (isset($_GET['holiday_updated']) && $_GET['holiday_updated'] == '1') {
    $success_message = 'Holiday updated successfully.';
}

if (isset($_GET['added']) && $_GET['added'] == '1') {
    $success_message = 'Office Time added successfully.';
}

if (isset($_GET['holiday_added']) && $_GET['holiday_added'] == '1') {
    $success_message = 'Holiday added successfully.';
}

if ($tab === 'office') {
    $sql = "
        SELECT 
            ot.id,
            ot.name,
            ot.created_at,
            COUNT(ote.id) AS entries_count
        FROM office_times ot
        LEFT JOIN office_time_entries ote ON ote.office_time_id = ot.id
        GROUP BY ot.id, ot.name, ot.created_at
        ORDER BY ot.id DESC
    ";

    $result = $conn_time->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $office_times[] = $row;
        }
    }
}

if ($tab === 'holiday') {
    $sql = "
        SELECT 
            h.id,
            h.name,
           
            h.created_at,
            COUNT(he.id) AS entries_count
        FROM holidays h
        LEFT JOIN holiday_entries he ON he.holiday_id = h.id
        GROUP BY h.id, h.name,  h.created_at
        ORDER BY h.id DESC
    ";

    $result = $conn_time->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $holidays[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Time Condition</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <link rel="stylesheet" href="/assets/rcm.css">

  <style>
    .page-head{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:26px;
    }

    .head-left{
      flex:1;
    }

    .head-right{
      display:flex;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
    }

    .powered-by{
      margin:0 0 18px 2px;
      font-size:18px;
      color:rgba(255,255,255,.92);
    }

    .tabs{
      display:flex;
      gap:14px;
      margin-bottom:26px;
      flex-wrap:wrap;
    }

    .tab-btn{
      display:inline-block;
      padding:14px 28px;
      border-radius:40px;
      border:2px solid #fff;
      background:transparent;
      color:#fff;
      text-decoration:none;
      font-family:'Orbitron', sans-serif;
      font-weight:700;
      letter-spacing:1px;
      transition:.2s;
      box-shadow:none;
    }

    .tab-btn:hover,
    .tab-btn.active{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 22px rgba(255,255,255,.6);
    }

    .board{
      background:rgba(20,48,78,.78);
      border:1px solid rgba(255,255,255,.16);
      border-radius:28px;
      padding:28px;
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.04),
        0 10px 30px rgba(0,0,0,.18);
    }

    .section-top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:18px;
    }

    .section-title{
      margin:0;
      font-family:'Orbitron', sans-serif;
      font-size:28px;
      letter-spacing:2px;
    }

    .mini-note{
      margin:0 0 22px 0;
      font-size:16px;
      opacity:.92;
    }

    .table-shell{
      width:100%;
      border:2px solid rgba(255,255,255,.38);
      border-radius:0;
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
      table-layout:auto;
    }

    .table-wrap th,
    .table-wrap td{
      border:1px solid rgba(255,255,255,.34);
      padding:18px 20px;
      text-align:center;
      vertical-align:middle;
      white-space:nowrap;
    }

    .table-wrap th{
      background:rgba(255,255,255,.12);
      color:#fff;
      font-family:'Orbitron', sans-serif;
      font-size:15px;
      letter-spacing:1px;
    }

    .table-wrap td{
      font-size:16px;
      color:#fff;
    }

    .name-cell{
      font-weight:700;
      letter-spacing:.5px;
    }

    .created-at{
      font-size:15px;
      opacity:.92;
    }

    

    .actions{
      display:flex;
      gap:10px;
      justify-content:center;
      align-items:center;
      flex-wrap:wrap;
    }

    .actions form{
      margin:0;
    }

    .action-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:92px;
      padding:10px 18px;
      border-radius:999px;
      border:2px solid #fff;
      background:transparent;
      color:#fff;
      text-decoration:none;
      font-family:'Orbitron', sans-serif;
      font-size:13px;
      font-weight:700;
      letter-spacing:1px;
      cursor:pointer;
      transition:.2s;
      line-height:1;
    }

    .action-btn:hover{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 18px rgba(255,255,255,.45);
    }

    .empty-row{
      text-align:center;
      opacity:.88;
      padding:28px 14px;
    }

    .msg{
      margin-bottom:18px;
      padding:14px 16px;
      border-radius:14px;
      font-weight:bold;
      background:rgba(255,255,255,0.12);
      border:1px solid rgba(255,255,255,0.20);
    }

    @media (max-width: 1100px){
      .table-wrap th,
      .table-wrap td{
        padding:15px 14px;
      }

      .section-title{
        font-size:24px;
      }
    }

    @media (max-width: 760px){
      .page-head,
      .section-top{
        flex-direction:column;
        align-items:flex-start;
      }

      .board{
        padding:18px;
      }

      .section-title{
        font-size:21px;
      }

      .action-btn{
        min-width:82px;
        padding:9px 14px;
        font-size:12px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="page-head">
      <div class="head-left">
        <h1 class="page-title" style="margin-bottom:12px;">TIME CONDITION</h1>
      </div>

      <div class="head-right">
        <a href="/dashboard.php" class="btn">Back</a>
      </div>
    </div>

    <div class="inner-box">
      <p class="powered-by">Powered by RAFIK</p>

      <?php if ($success_message !== ''): ?>
        <div class="msg"><?php echo htmlspecialchars($success_message); ?></div>
      <?php endif; ?>

      <div class="tabs">
        <a href="?tab=office" class="tab-btn <?php echo ($tab === 'office') ? 'active' : ''; ?>">Office Time</a>
        <a href="?tab=holiday" class="tab-btn <?php echo ($tab === 'holiday') ? 'active' : ''; ?>">Holiday</a>
      </div>

      <div class="board">
        <?php if ($tab === 'office'): ?>
          <div class="section-top">
            <h2 class="section-title">Office Time</h2>
            <a href="/add_office_time.php" class="btn">Add Office Time</a>
          </div>

          <p class="mini-note">Create named office time groups that can contain multiple time ranges.</p>

          <div class="table-shell">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th style="width:90px;">ID</th>
                    <th>Name</th>
                    <th style="width:170px;">Entries</th>
                    <th style="width:240px;">Created</th>
                    <th style="width:360px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($office_times) > 0): ?>
                    <?php foreach ($office_times as $office_time): ?>
                      <tr>
                        <td><?php echo (int)$office_time['id']; ?></td>
                        <td class="name-cell"><?php echo htmlspecialchars($office_time['name']); ?></td>
                        <td><?php echo (int)$office_time['entries_count']; ?> Ranges</td>
                        <td class="created-at"><?php echo htmlspecialchars($office_time['created_at']); ?></td>
                        <td>
                          <div class="actions">
                            <a href="/view_office_time.php?id=<?php echo (int)$office_time['id']; ?>" class="action-btn">VIEW</a>
                            <a href="/edit_office_time.php?id=<?php echo (int)$office_time['id']; ?>" class="action-btn">EDIT</a>

                            <form method="POST" action="/delete_office_time.php" onsubmit="return confirm('Are you sure you want to delete this Office Time?');">
                              <input type="hidden" name="id" value="<?php echo (int)$office_time['id']; ?>">
                              <button type="submit" class="action-btn">DELETE</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" class="empty-row">No Office Time found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        <?php else: ?>
          <div class="section-top">
            <h2 class="section-title">Holiday</h2>
            <a href="/add_holiday.php" class="btn">Add Holiday</a>
          </div>

          <p class="mini-note">Create named holiday groups that can contain multiple holiday rules.</p>

          <div class="table-shell">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th style="width:90px;">ID</th>
                    <th>Name</th>
                  
                    <th style="width:160px;">Entries</th>
                    <th style="width:240px;">Created</th>
                    <th style="width:360px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($holidays) > 0): ?>
                    <?php foreach ($holidays as $holiday): ?>
                      <tr>
                        <td><?php echo (int)$holiday['id']; ?></td>
                        <td class="name-cell"><?php echo htmlspecialchars($holiday['name']); ?></td>
                        
                        <td><?php echo (int)$holiday['entries_count']; ?> Entries</td>
                        <td class="created-at"><?php echo htmlspecialchars($holiday['created_at']); ?></td>
                        <td>
                          <div class="actions">
                            <a href="/view_holiday.php?id=<?php echo (int)$holiday['id']; ?>" class="action-btn">VIEW</a>
                            <a href="/edit_holiday.php?id=<?php echo (int)$holiday['id']; ?>" class="action-btn">EDIT</a>

                            <a href="/delete_holiday.php?id=<?php echo (int)$holiday['id']; ?>"
                               class="btn"
                                  onclick="return confirm('Are you sure you want to delete this Holiday?');">
                                     DELETE
                                     </a>
                              
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="6" class="empty-row">No Holiday found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>