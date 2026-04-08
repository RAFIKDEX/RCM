<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_time.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    die('Invalid Office Time ID.');
}

$success_message = '';
$error_message = '';

$office_time = null;
$existing_entries = [];

$stmt = $conn_time->prepare("SELECT id, name, created_at FROM office_times WHERE id = ?");
if (!$stmt) {
    die('Prepare failed: ' . $conn_time->error);
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$office_time = $result->fetch_assoc();
$stmt->close();

if (!$office_time) {
    die('Office Time not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $office_time_name = trim($_POST['office_time_name'] ?? '');
    $entries_json = $_POST['entries_json'] ?? '[]';
    $entries = json_decode($entries_json, true);

    if ($office_time_name === '') {
        $error_message = 'Please enter Office Time Name.';
    } elseif (!is_array($entries) || count($entries) === 0) {
        $error_message = 'Please add at least one time range before saving.';
    } else {
        $conn_time->begin_transaction();

        try {
            $update_stmt = $conn_time->prepare("UPDATE office_times SET name = ? WHERE id = ?");
            if (!$update_stmt) {
                throw new Exception('Prepare failed for office_times update: ' . $conn_time->error);
            }

            $update_stmt->bind_param("si", $office_time_name, $id);

            if (!$update_stmt->execute()) {
                throw new Exception('Execute failed for office_times update: ' . $update_stmt->error);
            }
            $update_stmt->close();

            $delete_entries_stmt = $conn_time->prepare("DELETE FROM office_time_entries WHERE office_time_id = ?");
            if (!$delete_entries_stmt) {
                throw new Exception('Prepare failed for deleting old entries: ' . $conn_time->error);
            }

            $delete_entries_stmt->bind_param("i", $id);

            if (!$delete_entries_stmt->execute()) {
                throw new Exception('Execute failed for deleting old entries: ' . $delete_entries_stmt->error);
            }
            $delete_entries_stmt->close();

            $insert_entry_stmt = $conn_time->prepare("
                INSERT INTO office_time_entries
                (office_time_id, time_from, time_to, week_days, months, month_days)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if (!$insert_entry_stmt) {
                throw new Exception('Prepare failed for office_time_entries insert: ' . $conn_time->error);
            }

            foreach ($entries as $entry) {
                $time_from = trim($entry['from'] ?? '');
                $time_to = trim($entry['to'] ?? '');
                $week_days = isset($entry['weekDays']) && is_array($entry['weekDays']) ? implode(',', $entry['weekDays']) : '';
                $months = isset($entry['months']) && is_array($entry['months']) ? implode(',', $entry['months']) : '';
                $month_days = isset($entry['monthDays']) && is_array($entry['monthDays']) ? implode(',', $entry['monthDays']) : '';

                if ($time_from === '' || $time_to === '') {
                    throw new Exception('One of the entries has empty time values.');
                }

                $insert_entry_stmt->bind_param(
                    "isssss",
                    $id,
                    $time_from,
                    $time_to,
                    $week_days,
                    $months,
                    $month_days
                );

                if (!$insert_entry_stmt->execute()) {
                    throw new Exception('Execute failed for office_time_entries insert: ' . $insert_entry_stmt->error);
                }
            }

            $insert_entry_stmt->close();

            $conn_time->commit();

            header("Location: edit_office_time.php?id=" . $id . "&success=1");
            exit;
        } catch (Throwable $e) {
            $conn_time->rollback();
            $error_message = $e->getMessage();
        }
    }
}

$entry_stmt = $conn_time->prepare("
    SELECT id, time_from, time_to, week_days, months, month_days
    FROM office_time_entries
    WHERE office_time_id = ?
    ORDER BY id ASC
");
if (!$entry_stmt) {
    die('Prepare failed: ' . $conn_time->error);
}
$entry_stmt->bind_param("i", $id);
$entry_stmt->execute();
$entries_result = $entry_stmt->get_result();

while ($row = $entries_result->fetch_assoc()) {
    $existing_entries[] = [
        'from' => substr($row['time_from'], 0, 5),
        'to' => substr($row['time_to'], 0, 5),
        'weekDays' => $row['week_days'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $row['week_days'])))) : [],
        'months' => $row['months'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $row['months'])))) : [],
        'monthDays' => $row['month_days'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $row['month_days'])))) : []
    ];
}
$entry_stmt->close();

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = 'Office Time updated successfully.';
}

$existing_entries_json = json_encode($existing_entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Office Time</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <link rel="stylesheet" href="/assets/rcm.css">

  <style>
    .top-actions{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:14px;
      flex-wrap:wrap;
      margin-bottom:24px;
    }

    .page-subtitle{
      margin:0;
      opacity:.9;
      font-size:16px;
    }

    .builder-card{
      background:rgba(0,0,0,0.24);
      border:1px solid rgba(255,255,255,0.14);
      border-radius:22px;
      padding:24px;
      margin-bottom:24px;
    }

    .builder-title{
      margin:0 0 18px 0;
      font-family:'Orbitron', sans-serif;
      letter-spacing:2px;
      font-size:24px;
    }

    .field-row{
      display:grid;
      grid-template-columns:1fr;
      gap:18px;
      margin-bottom:20px;
    }

    .time-row{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:18px;
      margin-bottom:20px;
    }

    .days-grid{
      display:grid;
      grid-template-columns:repeat(7, minmax(90px, 1fr));
      gap:10px;
      margin-top:10px;
    }

    .day-chip,
    .month-chip,
    .month-day-chip{
      position:relative;
    }

    .day-chip input,
    .month-chip input,
    .month-day-chip input{
      position:absolute;
      opacity:0;
      pointer-events:none;
    }

    .day-chip span,
    .month-chip span,
    .month-day-chip span{
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:48px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.28);
      background:rgba(255,255,255,.08);
      color:#fff;
      cursor:pointer;
      font-weight:700;
      letter-spacing:.5px;
      transition:.2s;
      user-select:none;
      text-align:center;
      padding:8px 10px;
    }

    .day-chip input:checked + span,
    .month-chip input:checked + span,
    .month-day-chip input:checked + span{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 18px rgba(255,255,255,.45);
      border-color:#fff;
    }

    .advanced-toggle{
      margin:10px 0 18px 0;
    }

    .toggle-btn{
      width:auto;
      min-width:240px;
    }

    .advanced-box{
      display:none;
      background:rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.14);
      border-radius:18px;
      padding:18px;
      margin-bottom:20px;
    }

    .advanced-box.show{
      display:block;
    }

    .advanced-section{
      margin-bottom:22px;
    }

    .advanced-section:last-child{
      margin-bottom:0;
    }

    .helper{
      margin-top:8px;
      font-size:14px;
      opacity:.8;
    }

    .builder-actions{
      display:flex;
      gap:14px;
      flex-wrap:wrap;
      margin-top:8px;
    }

    .entries-card{
      background:rgba(0,0,0,0.24);
      border:1px solid rgba(255,255,255,0.14);
      border-radius:22px;
      padding:24px;
    }

    .entries-head{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:14px;
      flex-wrap:wrap;
      margin-bottom:18px;
    }

    .entries-title{
      margin:0;
      font-family:'Orbitron', sans-serif;
      letter-spacing:2px;
      font-size:22px;
    }

    .empty-box{
      padding:22px;
      border-radius:16px;
      border:1px dashed rgba(255,255,255,.25);
      background:rgba(255,255,255,.04);
      text-align:center;
      opacity:.88;
    }

    .mini-btn{
      padding:8px 14px;
      border-radius:30px;
      border:2px solid #fff;
      background:transparent;
      color:#fff;
      text-decoration:none;
      cursor:pointer;
      font-family:'Orbitron', sans-serif;
      font-size:12px;
      font-weight:700;
      letter-spacing:1px;
      transition:.2s;
      display:inline-block;
    }

    .mini-btn:hover{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 18px rgba(255,255,255,.45);
    }

    .danger-btn:hover{
      background:#ffdddd;
      color:#8a1f1f;
      border-color:#ffdddd;
      box-shadow:0 0 18px rgba(255,220,220,.45);
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
  line-height:1.8;
  white-space:normal;
}

.hidden{
  display:none;
}

.table-shell{
  width:100%;
  border:2px solid rgba(255,255,255,.38);
  overflow:hidden;
  background:rgba(17,44,72,.42);
  margin-top:10px;
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
  color:#fff;
}

.table-wrap th{
  background:rgba(255,255,255,.12);
  font-family:'Orbitron', sans-serif;
  font-size:15px;
  letter-spacing:1px;
}

    .month-grid{
      display:grid;
      grid-template-columns:repeat(4, minmax(90px, 1fr));
      gap:10px;
      margin-top:10px;
      max-width:520px;
    }

    .month-days-grid{
      display:grid;
      grid-template-columns:repeat(7, minmax(60px, 1fr));
      gap:10px;
      margin-top:10px;
      max-width:520px;
    }

    .month-day-chip span{
      min-height:44px;
      padding:6px;
      font-size:15px;
    }

    .month-chip span{
      min-height:44px;
      padding:6px 8px;
      font-size:15px;
    }

    @media (max-width: 1100px){
      .days-grid{
        grid-template-columns:repeat(4, minmax(90px, 1fr));
      }
    }

    @media (max-width: 760px){
      .time-row{
        grid-template-columns:1fr;
      }

      .days-grid{
        grid-template-columns:repeat(2, minmax(90px, 1fr));
      }

      .month-grid{
        grid-template-columns:repeat(2, minmax(90px, 1fr));
      }

      .month-days-grid{
        grid-template-columns:repeat(4, minmax(60px, 1fr));
      }

      .builder-title,
      .entries-title{
        font-size:20px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top-actions">
      <div>
        <h1 class="page-title" style="margin-bottom:8px;">EDIT OFFICE TIME</h1>
        <p class="page-subtitle">Update the Office Time and its ranges.</p>
      </div>

      <div class="bottom-actions" style="margin-top:0;">
        <a href="/time_condition.php?tab=office" class="btn">Back</a>
      </div>
    </div>

    <div class="inner-box">
      <?php if ($success_message !== ''): ?>
        <div class="msg"><?php echo htmlspecialchars($success_message); ?></div>
      <?php endif; ?>

      <?php if ($error_message !== ''): ?>
        <div class="msg error"><?php echo htmlspecialchars($error_message); ?></div>
      <?php endif; ?>

      <form method="POST" id="officeTimeForm">
        <input type="hidden" name="id" value="<?php echo (int)$office_time['id']; ?>">
        <input type="hidden" name="entries_json" id="entries_json">

        <div class="builder-card">
          <h2 class="builder-title">OFFICE TIME INFO</h2>

          <div class="field-row">
            <div class="field">
              <label class="label">Office Time Name <span class="req">*</span></label>
              <input
                type="text"
                name="office_time_name"
                id="office_time_name"
                class="input"
                value="<?php echo htmlspecialchars($office_time['name']); ?>"
                placeholder="Example: CUC"
              >
            </div>
          </div>

          <h2 class="builder-title" style="margin-top:26px;">ADD TIME RANGE</h2>

          <div class="time-row">
            <div class="field">
              <label class="label">From <span class="req">*</span></label>
              <input type="time" id="time_from" class="input" value="09:00">
            </div>

            <div class="field">
              <label class="label">To <span class="req">*</span></label>
              <input type="time" id="time_to" class="input" value="17:00">
            </div>
          </div>

          <div class="field">
            <label class="label">Days Of Week <span class="req">*</span></label>
            <div class="days-grid">
              <label class="day-chip"><input type="checkbox" value="Sun"><span>Sunday</span></label>
              <label class="day-chip"><input type="checkbox" value="Mon"><span>Monday</span></label>
              <label class="day-chip"><input type="checkbox" value="Tue"><span>Tuesday</span></label>
              <label class="day-chip"><input type="checkbox" value="Wed"><span>Wednesday</span></label>
              <label class="day-chip"><input type="checkbox" value="Thu"><span>Thursday</span></label>
              <label class="day-chip"><input type="checkbox" value="Fri"><span>Friday</span></label>
              <label class="day-chip"><input type="checkbox" value="Sat"><span>Saturday</span></label>
            </div>
          </div>

          <div class="advanced-toggle">
            <button type="button" id="toggleAdvanced" class="btn toggle-btn">Show Advanced Options</button>
          </div>

          <div id="advancedBox" class="advanced-box">
            <div class="advanced-section">
              <label class="label">Month</label>
              <div class="month-grid">
                <label class="month-chip"><input type="checkbox" value="Jan"><span>Jan</span></label>
                <label class="month-chip"><input type="checkbox" value="Feb"><span>Feb</span></label>
                <label class="month-chip"><input type="checkbox" value="Mar"><span>Mar</span></label>
                <label class="month-chip"><input type="checkbox" value="Apr"><span>Apr</span></label>
                <label class="month-chip"><input type="checkbox" value="May"><span>May</span></label>
                <label class="month-chip"><input type="checkbox" value="Jun"><span>Jun</span></label>
                <label class="month-chip"><input type="checkbox" value="Jul"><span>Jul</span></label>
                <label class="month-chip"><input type="checkbox" value="Aug"><span>Aug</span></label>
                <label class="month-chip"><input type="checkbox" value="Sep"><span>Sep</span></label>
                <label class="month-chip"><input type="checkbox" value="Oct"><span>Oct</span></label>
                <label class="month-chip"><input type="checkbox" value="Nov"><span>Nov</span></label>
                <label class="month-chip"><input type="checkbox" value="Dec"><span>Dec</span></label>
              </div>
              <div class="helper">You can choose multiple months. Leave all unchecked to apply on all months.</div>
            </div>

            <div class="advanced-section">
              <label class="label">Day</label>
              <div class="month-days-grid">
                <?php for ($i = 1; $i <= 31; $i++): ?>
                  <label class="month-day-chip">
                    <input type="checkbox" value="<?php echo $i; ?>">
                    <span><?php echo $i; ?></span>
                  </label>
                <?php endfor; ?>
              </div>
              <div class="helper">You can choose multiple days. Leave all unchecked to apply on all days of month.</div>
            </div>
          </div>

          <div class="builder-actions">
            <button type="button" id="addRangeBtn" class="btn">Add Range</button>
            <button type="button" id="clearBuilderBtn" class="btn">Clear Current Inputs</button>
          </div>
        </div>

        <div class="entries-card">
          <div class="entries-head">
            <h2 class="entries-title">OFFICE TIME RANGES</h2>
            <span id="entryCount" class="pill">0 Entries</span>
          </div>

          <div id="emptyState" class="empty-box">
            No ranges added yet.
          </div>

          <div id="tableWrap" class="table-shell hidden">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:80px;">#</th>
          <th style="width:160px;">From</th>
          <th style="width:160px;">To</th>
          <th>Week</th>
          <th style="width:220px;">Month</th>
          <th style="width:220px;">Day</th>
          <th style="width:140px;">Action</th>
        </tr>
      </thead>
      <tbody id="entriesBody"></tbody>
    </table>
  </div>
</div>

          <div class="bottom-actions">
            <button type="submit" class="btn">Save Changes</button>
            <button type="button" id="clearAllBtn" class="btn">Clear All Entries</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      const existingEntries = <?php echo $existing_entries_json ?: '[]'; ?>;

      const form = document.getElementById('officeTimeForm');
      const entriesJsonInput = document.getElementById('entries_json');

      const toggleAdvancedBtn = document.getElementById('toggleAdvanced');
      const advancedBox = document.getElementById('advancedBox');

      const timeFrom = document.getElementById('time_from');
      const timeTo = document.getElementById('time_to');

      const addRangeBtn = document.getElementById('addRangeBtn');
      const clearBuilderBtn = document.getElementById('clearBuilderBtn');
      const clearAllBtn = document.getElementById('clearAllBtn');

      const entriesBody = document.getElementById('entriesBody');
      const tableWrap = document.getElementById('tableWrap');
      const emptyState = document.getElementById('emptyState');
      const entryCount = document.getElementById('entryCount');

      let entries = Array.isArray(existingEntries) ? existingEntries : [];

      toggleAdvancedBtn.addEventListener('click', function () {
        advancedBox.classList.toggle('show');
        toggleAdvancedBtn.textContent = advancedBox.classList.contains('show')
          ? 'Hide Advanced Options'
          : 'Show Advanced Options';
      });

      function getCheckedValues(selector) {
        return Array.from(document.querySelectorAll(selector + ':checked')).map(function (el) {
          return el.value;
        });
      }

      function resetBuilderInputs() {
        timeFrom.value = '09:00';
        timeTo.value = '17:00';

        document.querySelectorAll('.day-chip input, .month-chip input, .month-day-chip input').forEach(function (el) {
          el.checked = false;
        });
      }

      function updateCounter() {
        entryCount.textContent = entries.length + (entries.length === 1 ? ' Entry' : ' Entries');
      }

      function buildBadges(values, fallback) {
        if (!values || values.length === 0) {
          return fallback;
        }

        return values.map(function (value) {
          return '<span class="badge">' + value + '</span>';
        }).join('');
      }

      function renderTable() {
        entriesBody.innerHTML = '';

        if (entries.length === 0) {
          tableWrap.classList.add('hidden');
          emptyState.classList.remove('hidden');
          updateCounter();
          return;
        }

        emptyState.classList.add('hidden');
        tableWrap.classList.remove('hidden');

        entries.forEach(function (entry, index) {
          const tr = document.createElement('tr');

          tr.innerHTML =
            '<td>' + (index + 1) + '</td>' +
            '<td>' + entry.from + '</td>' +
            '<td>' + entry.to + '</td>' +
            '<td class="cell-days">' + buildBadges(entry.weekDays, 'All Week') + '</td>' +
            '<td class="cell-days">' + buildBadges(entry.months, 'All Months') + '</td>' +
            '<td class="cell-days">' + buildBadges(entry.monthDays, 'All Days') + '</td>' +
            '<td><button type="button" class="mini-btn danger-btn" data-index="' + index + '">Delete</button></td>';

          entriesBody.appendChild(tr);
        });

        document.querySelectorAll('.danger-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const idx = parseInt(this.getAttribute('data-index'), 10);
            entries.splice(idx, 1);
            renderTable();
          });
        });

        updateCounter();
      }

      addRangeBtn.addEventListener('click', function () {
        const from = timeFrom.value.trim();
        const to = timeTo.value.trim();
        const weekDays = getCheckedValues('.day-chip input');
        const months = getCheckedValues('.month-chip input');
        const monthDays = getCheckedValues('.month-day-chip input');

        if (!from || !to) {
          alert('Please select From and To time.');
          return;
        }

        if (from >= to) {
          alert('From time must be less than To time.');
          return;
        }

        if (weekDays.length === 0) {
          alert('Please select at least one week day.');
          return;
        }

        entries.push({
          from: from,
          to: to,
          weekDays: weekDays,
          months: months,
          monthDays: monthDays
        });

        renderTable();
        resetBuilderInputs();
      });

      clearBuilderBtn.addEventListener('click', function () {
        resetBuilderInputs();
      });

      clearAllBtn.addEventListener('click', function () {
        if (entries.length === 0) {
          return;
        }

        if (!confirm('Are you sure you want to clear all entries?')) {
          return;
        }

        entries = [];
        renderTable();
      });

      form.addEventListener('submit', function (e) {
        const officeTimeName = document.getElementById('office_time_name').value.trim();

        if (officeTimeName === '') {
          e.preventDefault();
          alert('Please enter Office Time Name.');
          return;
        }

        if (entries.length === 0) {
          e.preventDefault();
          alert('Please add at least one time range before saving.');
          return;
        }

        entriesJsonInput.value = JSON.stringify(entries);
      });

      renderTable();
    })();
  </script>
</body>
</html>
