<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/auth.php";
rcm_require_login();

require_once 'db_time.php';

/* =========================
   Asterisk Sources
========================= */
$ASTERISK_DIR = "/etc/asterisk";
$CFG_FILE = $ASTERISK_DIR . "/extensions_gui.conf";
$F_ENDPOINT = $ASTERISK_DIR . "/pjsip.gui.endpoint.conf";
$JSON_OUTBOUND = $ASTERISK_DIR . "/rcm_outbound_routes.json";

/* =========================
   Helpers
========================= */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function read_file_safe($path) {
    return file_exists($path) ? file_get_contents($path) : "";
}

function parse_marker_blocks($content) {
    $blocks = [];
    if (!$content) return $blocks;

    $re = '/^\s*;\s*---\s*RCM-TRUNK:\s*([A-Za-z0-9_\-]+)\s*BEGIN\s*---\s*$([\s\S]*?)^\s*;\s*---\s*RCM-TRUNK:\s*\1\s*END\s*---\s*$/m';
    if (preg_match_all($re, $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $blocks[$hit[1]] = $hit[2];
        }
    }

    return $blocks;
}

function get_context_lines($file, $contextName) {
    if (!file_exists($file)) return [];

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $result = [];
    $inContext = false;

    foreach ($lines as $line) {
        $t = trim($line);

        if (preg_match('/^\[' . preg_quote($contextName, '/') . '\]$/i', $t)) {
            $inContext = true;
            continue;
        }

        if ($inContext && preg_match('/^\[[^]]+\]$/', $t)) {
            break;
        }

        if ($inContext) {
            $result[] = $line;
        }
    }

    return $result;
}

function get_all_context_names($file) {
    if (!file_exists($file)) return [];

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $names = [];

    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/^\[([^\]]+)\]$/', $t, $m)) {
            $names[] = trim($m[1]);
        }
    }

    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function parse_values_from_context($file, $contextName, $mode = "same") {
    $lines = get_context_lines($file, $contextName);
    $values = [];

    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === "" || str_starts_with($t, ";")) continue;

        if ($mode === "same") {
            if (preg_match('/^exten\s*=>\s*(\d+)\s*,/i', $t, $m)) {
                $values[] = $m[1];
            }
        } else {
            if (preg_match('/^exten\s*=>\s*(\d+)\s*,1,Goto\(([^,]+),/i', $t, $m)) {
                $values[] = $m[1];
            }
        }
    }

    $values = array_values(array_unique($values));
    natsort($values);
    return array_values($values);
}

function get_extension_values($file) {
    if (!file_exists($file)) return [];

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $values = [];
    $inInternal = false;

    foreach ($lines as $line) {
        $t = trim($line);

        if (preg_match('/^\[internal\]$/i', $t)) {
            $inInternal = true;
            continue;
        }

        if ($inInternal && preg_match('/^\[[^]]+\]$/', $t)) {
            break;
        }

        if (!$inInternal) continue;
        if ($t === "" || str_starts_with($t, ";")) continue;

        if (preg_match('/^exten\s*=>\s*(\d+)\s*,1,Goto\(dexter,\$\{EXTEN\},1\)$/i', $t, $m)) {
            $values[] = $m[1];
        }
    }

    $values = array_values(array_unique($values));
    natsort($values);
    return array_values($values);
}

function rcm_load_json(string $jsonFile): array {
    if (!file_exists($jsonFile)) return ["routes" => []];

    $raw = file_get_contents($jsonFile);
    $j = json_decode($raw, true);

    if (!is_array($j)) return ["routes" => []];
    if (isset($j["routes"]) && is_array($j["routes"])) return $j;
    if (array_is_list($j)) return ["routes" => $j];

    return ["routes" => []];
}

/* =========================
   Load Sources
========================= */

/* Trunks from pjsip.gui.endpoint.conf */
$endpoint_content = read_file_safe($F_ENDPOINT);
$endpoint_blocks = parse_marker_blocks($endpoint_content);
$trunkList = array_keys($endpoint_blocks);
$trunkList = array_values(array_unique(array_filter($trunkList)));
natsort($trunkList);
$trunkList = array_values($trunkList);

/* Destinations from extensions_gui.conf */
$extensionList = get_extension_values($CFG_FILE);
$allContexts = get_all_context_names($CFG_FILE);

$queueList = [];
$ivrList = [];
$announcementList = [];

foreach ($allContexts as $ctx) {
    if (preg_match('/^queue-\d+$/i', $ctx)) {
        $queueList[] = preg_replace('/^queue-/i', '', $ctx);
    }

    if (preg_match('/^ivr-\d+$/i', $ctx)) {
        $ivrList[] = preg_replace('/^ivr-/i', '', $ctx);
    }

    if (preg_match('/^ann-\d+$/i', $ctx)) {
        $announcementList[] = preg_replace('/^ann-/i', '', $ctx);
    }
}

$ringGroupList = parse_values_from_context($CFG_FILE, "rcm-ring-groups", "same");

$extensionList = array_values(array_unique(array_filter($extensionList)));
$queueList = array_values(array_unique(array_filter($queueList)));
$ivrList = array_values(array_unique(array_filter($ivrList)));
$announcementList = array_values(array_unique(array_filter($announcementList)));
$ringGroupList = array_values(array_unique(array_filter($ringGroupList)));

natsort($extensionList);
natsort($queueList);
natsort($ivrList);
natsort($announcementList);
natsort($ringGroupList);

$extensionList = array_values($extensionList);
$queueList = array_values($queueList);
$ivrList = array_values($ivrList);
$announcementList = array_values($announcementList);
$ringGroupList = array_values($ringGroupList);

/* Outbound Routes from JSON */
$outboundData = rcm_load_json($JSON_OUTBOUND);
$outboundRoutes = [];

foreach (($outboundData["routes"] ?? []) as $route) {
    $name = trim((string)($route["name"] ?? ""));
    if ($name !== "") {
        $outboundRoutes[] = $name;
    }
}

$outboundRoutes = array_values(array_unique(array_filter($outboundRoutes)));
natsort($outboundRoutes);
$outboundRoutes = array_values($outboundRoutes);

/* Office Times / Holidays from DB */
$office_times = [];
$holidays = [];

$office_result = $conn_time->query("SELECT id, name FROM office_times ORDER BY name ASC");
if ($office_result) {
    while ($row = $office_result->fetch_assoc()) {
        $office_times[] = $row;
    }
}

$holiday_result = $conn_time->query("SELECT id, name FROM holidays ORDER BY name ASC");
if ($holiday_result) {
    while ($row = $holiday_result->fetch_assoc()) {
        $holidays[] = $row;
    }
}

/* =========================
   Save
========================= */
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trunk_name = trim($_POST['trunk_name'] ?? '');
    $did_pattern = trim($_POST['did_pattern'] ?? '');
    $route_name = trim($_POST['route_name'] ?? '');
    $callerid_pattern = trim($_POST['callerid_pattern'] ?? '');
    $auto_record = isset($_POST['auto_record']) ? 1 : 0;
    $is_disabled = isset($_POST['is_disabled']) ? 1 : 0;
    $default_destination_type = trim($_POST['default_destination_type'] ?? '');
    $default_destination_value = trim($_POST['default_destination_value'] ?? '');

    $rules_json = $_POST['rules_json'] ?? '[]';
    $rules = json_decode($rules_json, true);

    if ($route_name === '') {
        $error_message = 'Please enter Route Name.';
    } elseif ($trunk_name === '') {
        $error_message = 'Please select Trunk.';
    } elseif ($default_destination_type === '' || $default_destination_value === '') {
        $error_message = 'Please complete Default Destination.';
    } elseif (!is_array($rules)) {
        $error_message = 'Rules data is invalid.';
    } else {
        $conn_time->begin_transaction();

        try {
            $stmt = $conn_time->prepare("
                INSERT INTO inbound_routes
                (
                    trunk_name,
                    did_pattern,
                    route_name,
                    callerid_pattern,
                    auto_record,
                    is_disabled,
                    default_destination_type,
                    default_destination_value
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new Exception('Prepare failed for inbound_routes: ' . $conn_time->error);
            }

            $stmt->bind_param(
                "ssssiiss",
                $trunk_name,
                $did_pattern,
                $route_name,
                $callerid_pattern,
                $auto_record,
                $is_disabled,
                $default_destination_type,
                $default_destination_value
            );

            if (!$stmt->execute()) {
                throw new Exception('Execute failed for inbound_routes: ' . $stmt->error);
            }

            $inbound_id = (int)$stmt->insert_id;
            $stmt->close();

            if (count($rules) > 0) {
                $rule_stmt = $conn_time->prepare("
                    INSERT INTO inbound_time_conditions
                    (
                        inbound_id,
                        inbound_name,
                        priority_order,
                        condition_type,
                        office_time_id,
                        holiday_id,
                        custom_time_from,
                        custom_time_to,
                        custom_week_days,
                        custom_months,
                        custom_days,
                        destination_type,
                        destination_value
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$rule_stmt) {
                    throw new Exception('Prepare failed for inbound_time_conditions: ' . $conn_time->error);
                }

                foreach ($rules as $index => $rule) {
                    $priority_order = $index + 1;
                    $condition_type = trim((string)($rule['condition_type'] ?? ''));
                    $destination_type = trim((string)($rule['destination_type'] ?? ''));
                    $destination_value = trim((string)($rule['destination_value'] ?? ''));

                    $office_time_id = null;
                    $holiday_id = null;
                    $custom_time_from = null;
                    $custom_time_to = null;
                    $custom_week_days = null;
                    $custom_months = null;
                    $custom_days = null;

                    if ($condition_type === '') {
                        throw new Exception('One rule has empty condition type.');
                    }

                    if ($destination_type === '' || $destination_value === '') {
                        throw new Exception('One rule has empty destination.');
                    }

                    if ($condition_type === 'office_time' || $condition_type === 'out_of_office_time') {
                        $office_time_id = isset($rule['office_time_id']) && $rule['office_time_id'] !== '' ? (int)$rule['office_time_id'] : null;
                        if (!$office_time_id) {
                            throw new Exception('Office Time rule requires Office Time selection.');
                        }
                    }

                    if ($condition_type === 'holiday' || $condition_type === 'out_of_holiday') {
                        $holiday_id = isset($rule['holiday_id']) && $rule['holiday_id'] !== '' ? (int)$rule['holiday_id'] : null;
                        if (!$holiday_id) {
                            throw new Exception('Holiday rule requires Holiday selection.');
                        }
                    }

                    if ($condition_type === 'specific_time') {
                        $custom_time_from = trim((string)($rule['custom_time_from'] ?? ''));
                        $custom_time_to = trim((string)($rule['custom_time_to'] ?? ''));

                        if ($custom_time_from === '' || $custom_time_to === '') {
                            throw new Exception('Specific Time rule requires From and To time.');
                        }

                        if ($custom_time_from >= $custom_time_to) {
                            throw new Exception('Specific Time From must be less than To.');
                        }

                        $week_days_array = isset($rule['custom_week_days']) && is_array($rule['custom_week_days']) ? $rule['custom_week_days'] : [];
                        $months_array = isset($rule['custom_months']) && is_array($rule['custom_months']) ? $rule['custom_months'] : [];
                        $days_array = isset($rule['custom_days']) && is_array($rule['custom_days']) ? $rule['custom_days'] : [];

                        $custom_week_days = count($week_days_array) > 0 ? implode(',', $week_days_array) : null;
                        $custom_months = count($months_array) > 0 ? implode(',', $months_array) : null;
                        $custom_days = count($days_array) > 0 ? implode(',', $days_array) : null;
                    }

                    $rule_stmt->bind_param(
                        "isisiisssssss",
                        $inbound_id,
                        $route_name,
                        $priority_order,
                        $condition_type,
                        $office_time_id,
                        $holiday_id,
                        $custom_time_from,
                        $custom_time_to,
                        $custom_week_days,
                        $custom_months,
                        $custom_days,
                        $destination_type,
                        $destination_value
                    );

                    if (!$rule_stmt->execute()) {
                        throw new Exception('Execute failed for inbound_time_conditions: ' . $rule_stmt->error);
                    }
                }

                $rule_stmt->close();
            }

            $conn_time->commit();
            header("Location: /add_inbound.php?success=1");
            exit;
        } catch (Throwable $e) {
            $conn_time->rollback();
            $error_message = $e->getMessage();
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = 'Inbound Route saved successfully.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Inbound</title>

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
    .rules-card{
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
    .rules-title{
      margin:0 0 18px 0;
      font-family:'Orbitron', sans-serif;
      letter-spacing:2px;
      font-size:24px;
    }

    .field-row{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:18px;
      margin-bottom:20px;
    }

    .checkbox-row{
      display:flex;
      gap:18px;
      flex-wrap:wrap;
      margin-top:6px;
      margin-bottom:18px;
    }

    .check-item{
      display:flex;
      align-items:center;
      gap:10px;
      padding:10px 14px;
      border:1px solid rgba(255,255,255,.20);
      border-radius:14px;
      background:rgba(255,255,255,.06);
    }

    .check-item input{
      width:18px;
      height:18px;
    }

    .rule-builder{
      background:rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.14);
      border-radius:18px;
      padding:18px;
      margin-bottom:22px;
    }

    .sub-title{
      margin:0 0 16px 0;
      font-family:'Orbitron', sans-serif;
      font-size:18px;
      letter-spacing:1px;
    }

    .days-grid,
    .month-grid,
    .month-days-grid{
      display:grid;
      gap:10px;
      margin-top:10px;
    }

    .days-grid{
      grid-template-columns:repeat(7, minmax(80px, 1fr));
    }

    .month-grid{
      grid-template-columns:repeat(4, minmax(90px, 1fr));
      max-width:520px;
    }

    .month-days-grid{
      grid-template-columns:repeat(7, minmax(60px, 1fr));
      max-width:520px;
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

    .day-chip input:checked + span,
    .month-chip input:checked + span,
    .month-day-chip input:checked + span{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 18px rgba(255,255,255,.45);
      border-color:#fff;
    }

    .builder-actions,
    .bottom-actions{
      display:flex;
      gap:14px;
      flex-wrap:wrap;
      margin-top:8px;
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

    .empty-box{
      padding:22px;
      border-radius:16px;
      border:1px dashed rgba(255,255,255,.25);
      background:rgba(255,255,255,.04);
      text-align:center;
      opacity:.88;
    }

    .hidden{
      display:none;
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

    .helper{
      margin-top:8px;
      font-size:14px;
      opacity:.8;
    }

    select.input option{
      color:#000;
    }

    @media (max-width: 900px){
      .field-row{
        grid-template-columns:1fr;
      }

      .days-grid{
        grid-template-columns:repeat(4, minmax(80px, 1fr));
      }

      .month-grid{
        grid-template-columns:repeat(2, minmax(90px, 1fr));
      }

      .month-days-grid{
        grid-template-columns:repeat(4, minmax(60px, 1fr));
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top-actions">
      <div>
        <h1 class="page-title" style="margin-bottom:8px;">ADD INBOUND</h1>
        <p class="page-subtitle">Create inbound route with default destination and time condition rules.</p>
      </div>

      <div class="bottom-actions" style="margin-top:0;">
        <a href="/dashboard.php" class="btn">Back</a>
      </div>
    </div>

    <div class="inner-box">
      <?php if ($success_message !== ''): ?>
        <div class="msg"><?php echo h($success_message); ?></div>
      <?php endif; ?>

      <?php if ($error_message !== ''): ?>
        <div class="msg error"><?php echo h($error_message); ?></div>
      <?php endif; ?>

      <form method="POST" id="inboundForm">
        <input type="hidden" name="rules_json" id="rules_json">
        <input type="hidden" name="default_destination_value" id="default_destination_value">

        <div class="builder-card">
          <h2 class="builder-title">INBOUND INFO</h2>

          <div class="field-row">
            <div class="field">
              <label class="label">Inbound Route Name <span class="req">*</span></label>
              <input type="text" name="route_name" id="route_name" class="input" placeholder="Example: FM_Detection_Route">
            </div>

            <div class="field">
              <label class="label">Trunk <span class="req">*</span></label>
              <select name="trunk_name" id="trunk_name" class="input">
                <option value="">Select Trunk</option>
                <?php foreach ($trunkList as $trunk): ?>
                  <option value="<?php echo h($trunk); ?>"><?php echo h($trunk); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field-row">
            <div class="field">
              <label class="label">DID Pattern</label>
              <input type="text" name="did_pattern" id="did_pattern" class="input" placeholder="Example: _X.">
            </div>

            <div class="field">
              <label class="label">CallerID Pattern</label>
              <input type="text" name="callerid_pattern" id="callerid_pattern" class="input" placeholder="Optional">
            </div>
          </div>

          <div class="checkbox-row">
            <label class="check-item">
              <input type="checkbox" name="auto_record" value="1">
              <span>Auto Record</span>
            </label>

            <label class="check-item">
              <input type="checkbox" name="is_disabled" value="1">
              <span>Disable Route</span>
            </label>
          </div>

          <div class="field-row">
            <div class="field">
              <label class="label">Default Destination Type <span class="req">*</span></label>
              <select name="default_destination_type" id="default_destination_type" class="input">
                <option value="">Select Destination Type</option>
                <option value="extension">Extension</option>
                <option value="queue">Queue</option>
                <option value="ring_group">Ring Group</option>
                <option value="announcement">Announcement</option>
                <option value="ivr">IVR</option>
                <option value="dial_trunk">Dial Trunk</option>
              </select>
            </div>

            <div class="field" id="default_extension_wrap" style="display:none;">
              <label class="label">Default Extension <span class="req">*</span></label>
              <select id="default_extension_value" class="input">
                <option value="">Select Extension</option>
                <?php foreach ($extensionList as $x): ?>
                  <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" id="default_queue_wrap" style="display:none;">
              <label class="label">Default Queue <span class="req">*</span></label>
              <select id="default_queue_value" class="input">
                <option value="">Select Queue</option>
                <?php foreach ($queueList as $x): ?>
                  <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" id="default_ring_group_wrap" style="display:none;">
              <label class="label">Default Ring Group <span class="req">*</span></label>
              <select id="default_ring_group_value" class="input">
                <option value="">Select Ring Group</option>
                <?php foreach ($ringGroupList as $x): ?>
                  <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" id="default_announcement_wrap" style="display:none;">
              <label class="label">Default Announcement <span class="req">*</span></label>
              <select id="default_announcement_value" class="input">
                <option value="">Select Announcement</option>
                <?php foreach ($announcementList as $x): ?>
                  <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" id="default_ivr_wrap" style="display:none;">
              <label class="label">Default IVR <span class="req">*</span></label>
              <select id="default_ivr_value" class="input">
                <option value="">Select IVR</option>
                <?php foreach ($ivrList as $x): ?>
                  <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" id="default_dial_trunk_wrap" style="display:none;">
              <label class="label">Default Dial Trunk <span class="req">*</span></label>
              <select id="default_dial_trunk_value" class="input">
                <option value="">Select Outbound Route</option>
                <?php foreach ($outboundRoutes as $x): ?>
                  <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="rules-card">
          <div class="entries-head">
            <h2 class="rules-title">TIME CONDITION RULES</h2>
            <span id="ruleCount" class="pill">0 Rules</span>
          </div>

          <div class="rule-builder">
            <h3 class="sub-title">ADD RULE</h3>

            <div class="field-row">
              <div class="field">
                <label class="label">Condition Type <span class="req">*</span></label>
                <select id="condition_type" class="input">
                  <option value="">Select Condition Type</option>
                  <option value="office_time">Office Time</option>
                  <option value="out_of_office_time">Out Of Office Time</option>
                  <option value="holiday">Holiday</option>
                  <option value="out_of_holiday">Out Of Holiday</option>
                  <option value="specific_time">Specific Time</option>
                </select>
              </div>

              <div class="field" id="office_time_wrap" style="display:none;">
                <label class="label">Office Time</label>
                <select id="office_time_id" class="input">
                  <option value="">Select Office Time</option>
                  <?php foreach ($office_times as $office_time): ?>
                    <option value="<?php echo (int)$office_time['id']; ?>"><?php echo h($office_time['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" id="holiday_wrap" style="display:none;">
                <label class="label">Holiday</label>
                <select id="holiday_id" class="input">
                  <option value="">Select Holiday</option>
                  <?php foreach ($holidays as $holiday): ?>
                    <option value="<?php echo (int)$holiday['id']; ?>"><?php echo h($holiday['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div id="specific_time_wrap" style="display:none;">
              <div class="field-row">
                <div class="field">
                  <label class="label">From Time</label>
                  <input type="time" id="custom_time_from" class="input" value="09:00">
                </div>

                <div class="field">
                  <label class="label">To Time</label>
                  <input type="time" id="custom_time_to" class="input" value="17:00">
                </div>
              </div>

              <div class="field">
                <label class="label">Days Of Week</label>
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

              <div class="field">
                <label class="label">Months</label>
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

              <div class="field">
                <label class="label">Days</label>
                <div class="month-days-grid">
                  <?php for ($i = 1; $i <= 31; $i++): ?>
                    <label class="month-day-chip">
                      <input type="checkbox" value="<?php echo $i; ?>">
                      <span><?php echo $i; ?></span>
                    </label>
                  <?php endfor; ?>
                </div>
              </div>
            </div>

            <div class="field-row">
              <div class="field">
                <label class="label">Destination Type <span class="req">*</span></label>
                <select id="rule_destination_type" class="input">
                  <option value="">Select Destination Type</option>
                  <option value="extension">Extension</option>
                  <option value="queue">Queue</option>
                  <option value="ring_group">Ring Group</option>
                  <option value="announcement">Announcement</option>
                  <option value="ivr">IVR</option>
                  <option value="dial_trunk">Dial Trunk</option>
                </select>
              </div>

              <div class="field" id="rule_extension_wrap" style="display:none;">
                <label class="label">Rule Extension <span class="req">*</span></label>
                <select id="rule_extension_value" class="input">
                  <option value="">Select Extension</option>
                  <?php foreach ($extensionList as $x): ?>
                    <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" id="rule_queue_wrap" style="display:none;">
                <label class="label">Rule Queue <span class="req">*</span></label>
                <select id="rule_queue_value" class="input">
                  <option value="">Select Queue</option>
                  <?php foreach ($queueList as $x): ?>
                    <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" id="rule_ring_group_wrap" style="display:none;">
                <label class="label">Rule Ring Group <span class="req">*</span></label>
                <select id="rule_ring_group_value" class="input">
                  <option value="">Select Ring Group</option>
                  <?php foreach ($ringGroupList as $x): ?>
                    <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" id="rule_announcement_wrap" style="display:none;">
                <label class="label">Rule Announcement <span class="req">*</span></label>
                <select id="rule_announcement_value" class="input">
                  <option value="">Select Announcement</option>
                  <?php foreach ($announcementList as $x): ?>
                    <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" id="rule_ivr_wrap" style="display:none;">
                <label class="label">Rule IVR <span class="req">*</span></label>
                <select id="rule_ivr_value" class="input">
                  <option value="">Select IVR</option>
                  <?php foreach ($ivrList as $x): ?>
                    <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" id="rule_dial_trunk_wrap" style="display:none;">
                <label class="label">Rule Dial Trunk <span class="req">*</span></label>
                <select id="rule_dial_trunk_value" class="input">
                  <option value="">Select Outbound Route</option>
                  <?php foreach ($outboundRoutes as $x): ?>
                    <option value="<?php echo h($x); ?>"><?php echo h($x); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="builder-actions">
              <button type="button" id="addRuleBtn" class="btn">Add Rule</button>
              <button type="button" id="clearRuleBtn" class="btn">Clear Current Rule</button>
            </div>
          </div>

          <div id="emptyState" class="empty-box">
            No rules added yet.
          </div>

          <div id="tableWrap" class="table-shell hidden">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th style="width:80px;">#</th>
                    <th style="width:220px;">Condition</th>
                    <th>Reference / Specific</th>
                    <th style="width:180px;">Destination</th>
                    <th style="width:220px;">Value</th>
                    <th style="width:220px;">Actions</th>
                  </tr>
                </thead>
                <tbody id="rulesBody"></tbody>
              </table>
            </div>
          </div>

          <div class="bottom-actions">
            <button type="submit" class="btn">Save Inbound</button>
            <button type="button" id="clearAllRulesBtn" class="btn">Clear All Rules</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      const form = document.getElementById('inboundForm');
      const rulesJsonInput = document.getElementById('rules_json');

      const defaultDestinationType = document.getElementById('default_destination_type');
      const defaultDestinationValue = document.getElementById('default_destination_value');

      const defaultWrappers = {
        extension: document.getElementById('default_extension_wrap'),
        queue: document.getElementById('default_queue_wrap'),
        ring_group: document.getElementById('default_ring_group_wrap'),
        announcement: document.getElementById('default_announcement_wrap'),
        ivr: document.getElementById('default_ivr_wrap'),
        dial_trunk: document.getElementById('default_dial_trunk_wrap')
      };

      const defaultInputs = {
        extension: document.getElementById('default_extension_value'),
        queue: document.getElementById('default_queue_value'),
        ring_group: document.getElementById('default_ring_group_value'),
        announcement: document.getElementById('default_announcement_value'),
        ivr: document.getElementById('default_ivr_value'),
        dial_trunk: document.getElementById('default_dial_trunk_value')
      };

      const conditionType = document.getElementById('condition_type');
      const officeTimeWrap = document.getElementById('office_time_wrap');
      const holidayWrap = document.getElementById('holiday_wrap');
      const specificTimeWrap = document.getElementById('specific_time_wrap');

      const officeTimeId = document.getElementById('office_time_id');
      const holidayId = document.getElementById('holiday_id');
      const customTimeFrom = document.getElementById('custom_time_from');
      const customTimeTo = document.getElementById('custom_time_to');

      const ruleDestinationType = document.getElementById('rule_destination_type');

      const ruleWrappers = {
        extension: document.getElementById('rule_extension_wrap'),
        queue: document.getElementById('rule_queue_wrap'),
        ring_group: document.getElementById('rule_ring_group_wrap'),
        announcement: document.getElementById('rule_announcement_wrap'),
        ivr: document.getElementById('rule_ivr_wrap'),
        dial_trunk: document.getElementById('rule_dial_trunk_wrap')
      };

      const ruleInputs = {
        extension: document.getElementById('rule_extension_value'),
        queue: document.getElementById('rule_queue_value'),
        ring_group: document.getElementById('rule_ring_group_value'),
        announcement: document.getElementById('rule_announcement_value'),
        ivr: document.getElementById('rule_ivr_value'),
        dial_trunk: document.getElementById('rule_dial_trunk_value')
      };

      const addRuleBtn = document.getElementById('addRuleBtn');
      const clearRuleBtn = document.getElementById('clearRuleBtn');
      const clearAllRulesBtn = document.getElementById('clearAllRulesBtn');

      const ruleCount = document.getElementById('ruleCount');
      const emptyState = document.getElementById('emptyState');
      const tableWrap = document.getElementById('tableWrap');
      const rulesBody = document.getElementById('rulesBody');

      let rules = [];

      const officeTimeMap = {
        <?php
        $officePairs = [];
        foreach ($office_times as $office_time) {
            $officePairs[] = (int)$office_time['id'] . ': ' . json_encode($office_time['name']);
        }
        echo implode(",\n", $officePairs);
        ?>
      };

      const holidayMap = {
        <?php
        $holidayPairs = [];
        foreach ($holidays as $holiday) {
            $holidayPairs[] = (int)$holiday['id'] . ': ' . json_encode($holiday['name']);
        }
        echo implode(",\n", $holidayPairs);
        ?>
      };

      function hideAllDefaultDestinationInputs() {
        Object.keys(defaultWrappers).forEach(function (key) {
          defaultWrappers[key].style.display = 'none';
        });
      }

      function hideAllRuleDestinationInputs() {
        Object.keys(ruleWrappers).forEach(function (key) {
          ruleWrappers[key].style.display = 'none';
        });
      }

      function updateDefaultDestinationFields() {
        hideAllDefaultDestinationInputs();
        const type = defaultDestinationType.value;
        if (type && defaultWrappers[type]) {
          defaultWrappers[type].style.display = 'block';
        }
        syncDefaultDestinationValue();
      }

      function syncDefaultDestinationValue() {
        const type = defaultDestinationType.value;
        if (!type || !defaultInputs[type]) {
          defaultDestinationValue.value = '';
          return;
        }
        defaultDestinationValue.value = defaultInputs[type].value || '';
      }

      function updateConditionFields() {
        const type = conditionType.value;

        officeTimeWrap.style.display = 'none';
        holidayWrap.style.display = 'none';
        specificTimeWrap.style.display = 'none';

        if (type === 'office_time' || type === 'out_of_office_time') {
          officeTimeWrap.style.display = 'block';
        } else if (type === 'holiday' || type === 'out_of_holiday') {
          holidayWrap.style.display = 'block';
        } else if (type === 'specific_time') {
          specificTimeWrap.style.display = 'block';
        }
      }

      function updateRuleDestinationFields() {
        hideAllRuleDestinationInputs();
        const type = ruleDestinationType.value;
        if (type && ruleWrappers[type]) {
          ruleWrappers[type].style.display = 'block';
        }
      }

      function getRuleDestinationValue() {
        const type = ruleDestinationType.value;
        if (!type || !ruleInputs[type]) {
          return '';
        }
        return ruleInputs[type].value || '';
      }

      function getCheckedValues(selector) {
        return Array.from(document.querySelectorAll(selector + ':checked')).map(function (el) {
          return el.value;
        });
      }

      function clearSpecificInputs() {
        customTimeFrom.value = '09:00';
        customTimeTo.value = '17:00';

        document.querySelectorAll('.day-chip input, .month-chip input, .month-day-chip input').forEach(function (el) {
          el.checked = false;
        });
      }

      function clearCurrentRule() {
        conditionType.value = '';
        officeTimeId.value = '';
        holidayId.value = '';
        ruleDestinationType.value = '';

        Object.keys(ruleInputs).forEach(function (key) {
          if (ruleInputs[key]) {
            ruleInputs[key].value = '';
          }
        });

        clearSpecificInputs();
        updateConditionFields();
        updateRuleDestinationFields();
      }

      function updateRuleCounter() {
        ruleCount.textContent = rules.length + (rules.length === 1 ? ' Rule' : ' Rules');
      }

      function buildBadges(values, fallback) {
        if (!values || values.length === 0) {
          return fallback;
        }

        return values.map(function (value) {
          return '<span class="badge">' + value + '</span>';
        }).join('');
      }

      function getRuleReferenceText(rule) {
        if (rule.condition_type === 'office_time' || rule.condition_type === 'out_of_office_time') {
          return officeTimeMap[rule.office_time_id] || '-';
        }

        if (rule.condition_type === 'holiday' || rule.condition_type === 'out_of_holiday') {
          return holidayMap[rule.holiday_id] || '-';
        }

        if (rule.condition_type === 'specific_time') {
          return ''
            + '<div class="cell-days"><strong>Time:</strong> ' + rule.custom_time_from + ' → ' + rule.custom_time_to + '</div>'
            + '<div class="cell-days"><strong>Week:</strong> ' + buildBadges(rule.custom_week_days, 'All Week') + '</div>'
            + '<div class="cell-days"><strong>Month:</strong> ' + buildBadges(rule.custom_months, 'All Months') + '</div>'
            + '<div class="cell-days"><strong>Days:</strong> ' + buildBadges(rule.custom_days, 'All Days') + '</div>';
        }

        return '-';
      }

      function renderRules() {
        rulesBody.innerHTML = '';

        if (rules.length === 0) {
          emptyState.classList.remove('hidden');
          tableWrap.classList.add('hidden');
          updateRuleCounter();
          return;
        }

        emptyState.classList.add('hidden');
        tableWrap.classList.remove('hidden');

        rules.forEach(function (rule, index) {
          const tr = document.createElement('tr');

          tr.innerHTML =
            '<td>' + (index + 1) + '</td>' +
            '<td>' + rule.condition_type + '</td>' +
            '<td class="cell-days">' + getRuleReferenceText(rule) + '</td>' +
            '<td>' + rule.destination_type + '</td>' +
            '<td>' + rule.destination_value + '</td>' +
            '<td>' +
              '<button type="button" class="action-btn move-up-btn" data-index="' + index + '">UP</button> ' +
              '<button type="button" class="action-btn move-down-btn" data-index="' + index + '">DOWN</button> ' +
              '<button type="button" class="action-btn danger-btn delete-btn" data-index="' + index + '">DELETE</button>' +
            '</td>';

          rulesBody.appendChild(tr);
        });

        document.querySelectorAll('.delete-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const index = parseInt(this.getAttribute('data-index'), 10);
            rules.splice(index, 1);
            renderRules();
          });
        });

        document.querySelectorAll('.move-up-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const index = parseInt(this.getAttribute('data-index'), 10);
            if (index > 0) {
              const temp = rules[index - 1];
              rules[index - 1] = rules[index];
              rules[index] = temp;
              renderRules();
            }
          });
        });

        document.querySelectorAll('.move-down-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const index = parseInt(this.getAttribute('data-index'), 10);
            if (index < rules.length - 1) {
              const temp = rules[index + 1];
              rules[index + 1] = rules[index];
              rules[index] = temp;
              renderRules();
            }
          });
        });

        updateRuleCounter();
      }

      conditionType.addEventListener('change', updateConditionFields);
      ruleDestinationType.addEventListener('change', updateRuleDestinationFields);
      defaultDestinationType.addEventListener('change', updateDefaultDestinationFields);

      Object.keys(defaultInputs).forEach(function (key) {
        defaultInputs[key].addEventListener('change', syncDefaultDestinationValue);
      });

      addRuleBtn.addEventListener('click', function () {
        const type = conditionType.value;
        const destinationType = ruleDestinationType.value;
        const destinationValue = getRuleDestinationValue();

        if (type === '') {
          alert('Please select Condition Type.');
          return;
        }

        if (destinationType === '' || destinationValue === '') {
          alert('Please complete Rule Destination.');
          return;
        }

        const rule = {
          condition_type: type,
          office_time_id: '',
          holiday_id: '',
          custom_time_from: '',
          custom_time_to: '',
          custom_week_days: [],
          custom_months: [],
          custom_days: [],
          destination_type: destinationType,
          destination_value: destinationValue
        };

        if (type === 'office_time' || type === 'out_of_office_time') {
          if (officeTimeId.value === '') {
            alert('Please select Office Time.');
            return;
          }
          rule.office_time_id = officeTimeId.value;
        }

        if (type === 'holiday' || type === 'out_of_holiday') {
          if (holidayId.value === '') {
            alert('Please select Holiday.');
            return;
          }
          rule.holiday_id = holidayId.value;
        }

        if (type === 'specific_time') {
          const from = customTimeFrom.value.trim();
          const to = customTimeTo.value.trim();
          const weekDays = getCheckedValues('.day-chip input');
          const months = getCheckedValues('.month-chip input');
          const days = getCheckedValues('.month-day-chip input');

          if (!from || !to) {
            alert('Please select Specific Time range.');
            return;
          }

          if (from >= to) {
            alert('Specific Time From must be less than To.');
            return;
          }

          rule.custom_time_from = from;
          rule.custom_time_to = to;
          rule.custom_week_days = weekDays;
          rule.custom_months = months;
          rule.custom_days = days;
        }

        rules.push(rule);
        renderRules();
        clearCurrentRule();
      });

      clearRuleBtn.addEventListener('click', function () {
        clearCurrentRule();
      });

      clearAllRulesBtn.addEventListener('click', function () {
        if (rules.length === 0) return;

        if (!confirm('Are you sure you want to clear all rules?')) {
          return;
        }

        rules = [];
        renderRules();
      });

      form.addEventListener('submit', function (e) {
        const routeName = document.getElementById('route_name').value.trim();
        const trunkName = document.getElementById('trunk_name').value.trim();
        const defaultType = defaultDestinationType.value;

        syncDefaultDestinationValue();
        const defaultValue = defaultDestinationValue.value.trim();

        if (routeName === '') {
          e.preventDefault();
          alert('Please enter Route Name.');
          return;
        }

        if (trunkName === '') {
          e.preventDefault();
          alert('Please select Trunk.');
          return;
        }

        if (defaultType === '' || defaultValue === '') {
          e.preventDefault();
          alert('Please complete Default Destination.');
          return;
        }

        rulesJsonInput.value = JSON.stringify(rules);
      });

      renderRules();
      updateConditionFields();
      updateRuleDestinationFields();
      updateDefaultDestinationFields();
    })();
  </script>
</body>
</html>