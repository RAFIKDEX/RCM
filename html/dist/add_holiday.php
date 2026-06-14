<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_time.php';

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $holiday_name = trim($_POST['holiday_name'] ?? '');
    $entries_json = $_POST['entries_json'] ?? '[]';
    $entries = json_decode($entries_json, true);

    if ($holiday_name === '') {
        $error_message = 'Please enter Holiday Name.';
    } elseif (!is_array($entries) || count($entries) === 0) {
        $error_message = 'Please add at least one holiday entry before saving.';
    } else {
        $conn_time->begin_transaction();

        try {
            $empty_memo = '';

            $stmt = $conn_time->prepare("INSERT INTO holidays (name, memo) VALUES (?, ?)");
            if (!$stmt) {
                throw new Exception('Prepare failed for holidays: ' . $conn_time->error);
            }

            $stmt->bind_param("ss", $holiday_name, $empty_memo);

            if (!$stmt->execute()) {
                throw new Exception('Execute failed for holidays: ' . $stmt->error);
            }

            $holiday_id = $stmt->insert_id;
            $stmt->close();

            $entry_stmt = $conn_time->prepare("
                INSERT INTO holiday_entries
                (holiday_id, years, week_days, months, month_days, time_from, time_to)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$entry_stmt) {
                throw new Exception('Prepare failed for holiday_entries: ' . $conn_time->error);
            }

            foreach ($entries as $entry) {
                $years = trim($entry['year'] ?? '');
                $week_days = '';
                $months = isset($entry['months']) && is_array($entry['months']) ? implode(',', $entry['months']) : '';
                $month_days = isset($entry['monthDays']) && is_array($entry['monthDays']) ? implode(',', $entry['monthDays']) : '';
                $time_from = trim($entry['from'] ?? '');
                $time_to = trim($entry['to'] ?? '');

                if ($years === '' && $months === '' && $month_days === '') {
                    throw new Exception('One holiday entry has no date selection.');
                }

                if (($time_from === '' && $time_to !== '') || ($time_from !== '' && $time_to === '')) {
                    throw new Exception('Time range must contain both From and To.');
                }

                $entry_stmt->bind_param(
                    "issssss",
                    $holiday_id,
                    $years,
                    $week_days,
                    $months,
                    $month_days,
                    $time_from,
                    $time_to
                );

                if (!$entry_stmt->execute()) {
                    throw new Exception('Execute failed for holiday_entries: ' . $entry_stmt->error);
                }
            }

            $entry_stmt->close();
            $conn_time->commit();

            header("Location: /time_condition.php?tab=holiday&holiday_added=1");
            exit;
        } catch (Throwable $e) {
            $conn_time->rollback();
            $error_message = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Holiday</title>

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <link rel="stylesheet" href="/dist/assets/rcm.css">

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

    .builder-card,
    .entries-card{
      background:rgba(20,48,78,.78);
      border:1px solid rgba(255,255,255,.16);
      border-radius:28px;
      padding:24px;
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.04),
        0 10px 30px rgba(0,0,0,.18);
    }

    .builder-card{
      margin-bottom:24px;
    }

    .builder-title,
    .entries-title{
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

    .builder-actions,
    .bottom-actions{
      display:flex;
      gap:14px;
      flex-wrap:wrap;
      margin-top:8px;
    }

    .entries-head{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:14px;
      flex-wrap:wrap;
      margin-bottom:18px;
    }

    .empty-box{
      padding:22px;
      border-radius:16px;
      border:1px dashed rgba(255,255,255,.25);
      background:rgba(255,255,255,.04);
      text-align:center;
      opacity:.88;
    }

    .helper{
      margin-top:8px;
      font-size:14px;
      opacity:.8;
    }

    .section-block{
      margin-bottom:22px;
    }

    .grid-title{
      margin:0 0 12px 0;
      font-family:'Orbitron', sans-serif;
      font-size:18px;
      letter-spacing:1px;
    }

    .month-chip,
    .month-day-chip{
      position:relative;
    }

    .month-chip input,
    .month-day-chip input{
      position:absolute;
      opacity:0;
      pointer-events:none;
    }

    .month-chip span,
    .month-day-chip span{
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:46px;
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

    .month-chip input:checked + span,
    .month-day-chip input:checked + span{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 18px rgba(255,255,255,.45);
      border-color:#fff;
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

    .advanced-box{
      display:block;
      background:rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.14);
      border-radius:18px;
      padding:18px;
      margin-bottom:20px;
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

    .danger-btn:hover{
      background:#ffdddd;
      color:#8a1f1f;
      border-color:#ffdddd;
      box-shadow:0 0 18px rgba(255,220,220,.45);
    }

    @media (max-width: 760px){
      .time-row{
        grid-template-columns:1fr;
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

      .table-wrap th,
      .table-wrap td{
        padding:14px 12px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top-actions">
      <div>
        <h1 class="page-title" style="margin-bottom:8px;">ADD HOLIDAY</h1>
        <p class="page-subtitle">Create one named Holiday and add multiple holiday entries inside it.</p>
      </div>

      <div class="bottom-actions" style="margin-top:0;">
        <a href="/time_condition.php?tab=holiday" class="btn">Back</a>
      </div>
    </div>

    <div class="inner-box">
      <?php if ($success_message !== ''): ?>
        <div class="msg"><?php echo htmlspecialchars($success_message); ?></div>
      <?php endif; ?>

      <?php if ($error_message !== ''): ?>
        <div class="msg error"><?php echo htmlspecialchars($error_message); ?></div>
      <?php endif; ?>

      <form method="POST" id="holidayForm">
        <input type="hidden" name="entries_json" id="entries_json">

        <div class="builder-card">
          <h2 class="builder-title">HOLIDAY INFO</h2>

          <div class="field-row">
            <div class="field">
              <label class="label">Holiday Name <span class="req">*</span></label>
              <input type="text" name="holiday_name" id="holiday_name" class="input" placeholder="Example: Eid 2026">
            </div>
          </div>

          <h2 class="builder-title" style="margin-top:26px;">ADD HOLIDAY ENTRY</h2>

          <div class="advanced-box">
            <div class="section-block">
              <label class="label">Year</label>
              <select id="year_select" class="input">
                <option value="">All Years</option>
                <?php $current_year = (int)date('Y'); ?>
                <?php for ($y = $current_year - 1; $y <= $current_year + 8; $y++): ?>
                  <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                <?php endfor; ?>
              </select>
              <div class="helper">Choose one year or leave it on All Years.</div>
            </div>

            <div class="section-block">
              <h3 class="grid-title">Month</h3>
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
            </div>

            <div class="section-block">
              <h3 class="grid-title">Day</h3>
              <div class="month-days-grid">
                <?php for ($i = 1; $i <= 31; $i++): ?>
                  <label class="month-day-chip">
                    <input type="checkbox" value="<?php echo $i; ?>">
                    <span><?php echo $i; ?></span>
                  </label>
                <?php endfor; ?>
              </div>
            </div>

            <div class="time-row">
              <div class="field">
                <label class="label">From Time</label>
                <input type="time" id="time_from" class="input" value="00:00">
              </div>

              <div class="field">
                <label class="label">To Time</label>
                <input type="time" id="time_to" class="input" value="23:59">
              </div>
            </div>
          </div>

          <div class="builder-actions">
            <button type="button" id="addEntryBtn" class="btn">Add Holiday Entry</button>
            <button type="button" id="clearBuilderBtn" class="btn">Clear Current Inputs</button>
          </div>
        </div>

        <div class="entries-card">
          <div class="entries-head">
            <h2 class="entries-title">HOLIDAY ENTRIES</h2>
            <span id="entryCount" class="pill">0 Entries</span>
          </div>

          <div id="emptyState" class="empty-box">
            No holiday entries added yet.
          </div>

          <div id="tableWrap" class="table-shell hidden">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th style="width:80px;">#</th>
                    <th style="width:180px;">Year</th>
                    <th>Month</th>
                    <th>Day</th>
                    <th style="width:140px;">From</th>
                    <th style="width:140px;">To</th>
                    <th style="width:140px;">Action</th>
                  </tr>
                </thead>
                <tbody id="entriesBody"></tbody>
              </table>
            </div>
          </div>

          <div class="bottom-actions">
            <button type="submit" class="btn">Save Holiday</button>
            <button type="button" id="clearAllBtn" class="btn">Clear All Entries</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      const form = document.getElementById('holidayForm');
      const entriesJsonInput = document.getElementById('entries_json');

      const yearSelect = document.getElementById('year_select');
      const timeFrom = document.getElementById('time_from');
      const timeTo = document.getElementById('time_to');

      const addEntryBtn = document.getElementById('addEntryBtn');
      const clearBuilderBtn = document.getElementById('clearBuilderBtn');
      const clearAllBtn = document.getElementById('clearAllBtn');

      const entriesBody = document.getElementById('entriesBody');
      const tableWrap = document.getElementById('tableWrap');
      const emptyState = document.getElementById('emptyState');
      const entryCount = document.getElementById('entryCount');

      let entries = [];

      function getCheckedValues(selector) {
        return Array.from(document.querySelectorAll(selector + ':checked')).map(function (el) {
          return el.value;
        });
      }

      function resetBuilderInputs() {
        yearSelect.value = '';

        document.querySelectorAll('.month-chip input, .month-day-chip input').forEach(function (el) {
          el.checked = false;
        });

        timeFrom.value = '00:00';
        timeTo.value = '23:59';
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
            '<td>' + (entry.year || 'All Years') + '</td>' +
            '<td class="cell-days">' + buildBadges(entry.months, 'Default') + '</td>' +
            '<td class="cell-days">' + buildBadges(entry.monthDays, 'Default') + '</td>' +
            '<td>' + (entry.from || '00:00') + '</td>' +
            '<td>' + (entry.to || '23:59') + '</td>' +
            '<td><button type="button" class="action-btn danger-btn" data-index="' + index + '">DELETE</button></td>';

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

      addEntryBtn.addEventListener('click', function () {
        const year = yearSelect.value.trim();
        const months = getCheckedValues('.month-chip input');
        const monthDays = getCheckedValues('.month-day-chip input');
        const from = timeFrom.value.trim();
        const to = timeTo.value.trim();

        if (from && to && from >= to) {
          alert('From time must be less than To time.');
          return;
        }

        if (year === '' && months.length === 0 && monthDays.length === 0) {
          alert('Please choose Year or Month or Day before adding the entry.');
          return;
        }

        entries.push({
          year: year,
          months: months,
          monthDays: monthDays,
          from: from,
          to: to
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

        if (!confirm('Are you sure you want to clear all holiday entries?')) {
          return;
        }

        entries = [];
        renderTable();
      });

      form.addEventListener('submit', function (e) {
        const holidayName = document.getElementById('holiday_name').value.trim();

        if (holidayName === '') {
          e.preventDefault();
          alert('Please enter Holiday Name.');
          return;
        }

        if (entries.length === 0) {
          e.preventDefault();
          alert('Please add at least one holiday entry before saving.');
          return;
        }

        entriesJsonInput.value = JSON.stringify(entries);
      });

      renderTable();
    })();
  </script>
</body>
</html>