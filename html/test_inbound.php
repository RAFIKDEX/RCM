<?php
require_once 'db_time.php';
date_default_timezone_set('Africa/Cairo');

$inbound_name = 'FM_Detection_Route';

$now_time = date('H:i:s');
$day_name = date('D');
$month = date('M');
$day = date('j');

$sql = "
SELECT * FROM inbound_time_conditions
WHERE inbound_name = ?
ORDER BY priority_order ASC
";

$stmt = $conn_time->prepare($sql);
$stmt->bind_param("s", $inbound_name);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    // === OFFICE TIME ===
    if ($row['condition_type'] === 'office_time') {

        $stmt2 = $conn_time->prepare("
            SELECT * FROM office_time_entries
            WHERE office_time_id = ?
        ");
        $stmt2->bind_param("i", $row['office_time_id']);
        $stmt2->execute();
        $res2 = $stmt2->get_result();

        while ($r = $res2->fetch_assoc()) {

            if ($now_time < $r['time_from'] || $now_time > $r['time_to']) continue;

            if ($r['week_days']) {
                if (!in_array($day_name, explode(',', $r['week_days']))) continue;
            }

            if ($r['months']) {
                if (!in_array($month, explode(',', $r['months']))) continue;
            }

            if ($r['month_days']) {
                if (!in_array($day, explode(',', $r['month_days']))) continue;
            }

            echo "MATCHED: OFFICE TIME → " . $row['destination_value'];
            exit;
        }
    }

    // === HOLIDAY ===
    if ($row['condition_type'] === 'holiday') {

        $stmt2 = $conn_time->prepare("
            SELECT * FROM holiday_entries
            WHERE holiday_id = ?
        ");
        $stmt2->bind_param("i", $row['holiday_id']);
        $stmt2->execute();
        $res2 = $stmt2->get_result();

        while ($r = $res2->fetch_assoc()) {

            if ($r['months']) {
                if (!in_array($month, explode(',', $r['months']))) continue;
            }

            if ($r['month_days']) {
                if (!in_array($day, explode(',', $r['month_days']))) continue;
            }

            echo "MATCHED: HOLIDAY → " . $row['destination_value'];
            exit;
        }
    }

}

echo "NO MATCH → DEFAULT DESTINATION";
