<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$EXT_GUI_FILE = "/etc/asterisk/extensions_gui.conf";
$PJSIP_FILE   = "/etc/asterisk/pjsip.gui.endpoint.conf";

function redirect_back(){
  header("Location: /pickup_groups.php");
  exit;
}

function write_lines($file, array $lines){
  $content = implode(PHP_EOL, $lines);
  if ($content !== "" && !str_ends_with($content, PHP_EOL)) {
    $content .= PHP_EOL;
  }
  return file_put_contents($file, $content, LOCK_EX) !== false;
}

function reload_asterisk(){
  @exec('sudo /usr/sbin/asterisk -rx "core reload" 2>&1', $out, $rc);

  return [
    "ok"  => ($rc === 0),
    "out" => $out,
    "rc"  => $rc
  ];
}

function normalize_group_list($value){
  if (is_array($value)) {
    $parts = $value;
  } else {
    $parts = explode(',', (string)$value);
  }

  $parts = array_map('trim', $parts);
  $parts = array_values(array_filter($parts, fn($x) => $x !== ""));
  $parts = array_values(array_unique($parts, SORT_STRING));
  usort($parts, fn($a, $b) => strnatcmp($a, $b));

  return $parts;
}

function remove_group_value($existingValue, $groupNumber){
  $parts = normalize_group_list($existingValue);
  $g = (string)$groupNumber;

  $parts = array_values(array_filter($parts, fn($x) => $x !== $g));
  usort($parts, fn($a, $b) => strnatcmp($a, $b));

  return implode(',', $parts);
}

function parse_pickup_group_by_number($file, $groupNumber){
  if (!file_exists($file)) return null;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $cur = null;
  $group = null;
  $pendingName = null;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^;\s*---\s*PICKUP GROUP GUI:\s*(.+?)\s*---$/i', $t, $m)) {
      $pendingName = trim($m[1]);
      continue;
    }

    if (preg_match('/^\[pickup-(\d{1,10})\]$/i', $t, $m)) {
      $num = $m[1];
      $cur = $num;

      if ((string)$num === (string)$groupNumber) {
        $group = [
          "group"   => $num,
          "name"    => $pendingName ?: ("pickup-" . $num),
          "members" => []
        ];
      }

      $pendingName = null;
      continue;
    }

    if (!$cur) continue;

    if ((string)$cur !== (string)$groupNumber) {
      if (preg_match('/^\[[^]]+\]$/', $t) && !preg_match('/^\[pickup-(\d{1,10})\]$/i', $t)) {
        $cur = null;
      }
      continue;
    }

    if ($group && preg_match('/^name\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $group["name"] = trim($m[1]);
      continue;
    }

    if ($group && preg_match('/^members\s*=\s*(.*?)\s*$/i', $t, $m)) {
      $raw = trim($m[1]);
      if ($raw !== "") {
        $group["members"] = array_values(
          array_filter(array_map('trim', explode(',', $raw)), fn($x) => $x !== "")
        );
      }
      continue;
    }

    if (preg_match('/^\[[^]]+\]$/', $t) && !preg_match('/^\[pickup-(\d{1,10})\]$/i', $t)) {
      $cur = null;
      continue;
    }
  }

  return $group;
}

function remove_pickup_group_section($file, $groupNumber){
  if (!file_exists($file)) return false;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $out = [];
  $skip = false;

  for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $t = trim($line);

    if (preg_match('/^;\s*---\s*PICKUP GROUP GUI:\s*(.+?)\s*---$/i', $t)) {
      $next = trim($lines[$i + 1] ?? "");
      if (preg_match('/^\[pickup-' . preg_quote((string)$groupNumber, '/') . '\]$/i', $next)) {
        $skip = true;
        continue;
      }
    }

    if (preg_match('/^\[pickup-(\d{1,10})\]$/i', $t, $m)) {
      if ((string)$m[1] === (string)$groupNumber) {
        $skip = true;
        continue;
      }
    }

    if ($skip) {
      if (preg_match('/^\[[^]]+\]$/', $t)) {
        $skip = false;
        $out[] = $line;
      }
      continue;
    }

    $out[] = $line;
  }

  return write_lines($file, $out);
}

function rebuild_pjsip_after_delete($file, $groupNumber, $members){
  if (!file_exists($file)) return false;

  $membersLookup = array_fill_keys($members, true);
  $lines = file($file, FILE_IGNORE_NEW_LINES);

  $sections = [];
  $currentHeader = null;
  $currentBody = [];

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[([^\]]+)\]$/', $t)) {
      if ($currentHeader !== null) {
        $sections[] = [
          "header" => $currentHeader,
          "body"   => $currentBody
        ];
      }
      $currentHeader = $line;
      $currentBody = [];
      continue;
    }

    if ($currentHeader === null) {
      $sections[] = [
        "header" => null,
        "body"   => [$line]
      ];
      continue;
    }

    $currentBody[] = $line;
  }

  if ($currentHeader !== null) {
    $sections[] = [
      "header" => $currentHeader,
      "body"   => $currentBody
    ];
  }

  $out = [];

  foreach ($sections as $section) {
    if ($section["header"] === null) {
      foreach ($section["body"] as $line) {
        $out[] = $line;
      }
      continue;
    }

    $headerTrim = trim($section["header"]);
    preg_match('/^\[([^\]]+)\]$/', $headerTrim, $m);
    $sectionName = $m[1] ?? '';

    $out[] = $section["header"];

    if (!isset($membersLookup[$sectionName])) {
      foreach ($section["body"] as $line) {
        $out[] = $line;
      }
      continue;
    }

    $existingCall = '';
    $existingPickup = '';
    $cleanBody = [];

    foreach ($section["body"] as $line) {
      $t = trim($line);

      if (preg_match('/^call_group\s*=\s*(.*?)\s*$/i', $t, $mm)) {
        $existingCall = $mm[1];
        continue;
      }

      if (preg_match('/^pickup_group\s*=\s*(.*?)\s*$/i', $t, $mm)) {
        $existingPickup = $mm[1];
        continue;
      }

      $cleanBody[] = $line;
    }

    $finalCall = remove_group_value($existingCall, $groupNumber);
    $finalPickup = remove_group_value($existingPickup, $groupNumber);

    $inserted = false;

    foreach ($cleanBody as $line) {
      $out[] = $line;

      if (!$inserted && preg_match('/^;?\s*direct_media\s*=/i', trim($line))) {
        if ($finalCall !== "") {
          $out[] = "call_group=" . $finalCall;
        }
        if ($finalPickup !== "") {
          $out[] = "pickup_group=" . $finalPickup;
        }
        $inserted = true;
      }
    }

    if (!$inserted) {
      if ($finalCall !== "") {
        $out[] = "call_group=" . $finalCall;
      }
      if ($finalPickup !== "") {
        $out[] = "pickup_group=" . $finalPickup;
      }
    }
  }

  return write_lines($file, $out);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  redirect_back();
}

$groupNumber = trim($_POST["g"] ?? "");
if ($groupNumber === "" || !preg_match('/^\d{1,10}$/', $groupNumber)) {
  redirect_back();
}

$group = parse_pickup_group_by_number($EXT_GUI_FILE, $groupNumber);
if (!$group) {
  redirect_back();
}

$ok1 = remove_pickup_group_section($EXT_GUI_FILE, $groupNumber);
$ok2 = rebuild_pjsip_after_delete($PJSIP_FILE, $groupNumber, $group["members"]);

reload_asterisk();
redirect_back();