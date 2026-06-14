<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$CFG_FILE = "/etc/asterisk/extensions_gui.conf";

function parse_speed_dial_line($line){
  $t = trim($line);
  if ($t === "") return null;

  if (str_starts_with($t, ";")) {
    $t = ltrim(substr($t, 1));
  }

  if (!preg_match('/^exten\s*=>\s*(\d+),1,Goto\(([^,]+),([^,]+),1\)$/i', $t, $m)) {
    return null;
  }

  return [
    "extension" => $m[1],
    "context"   => $m[2],
    "value"     => $m[3]
  ];
}

function delete_speed_dial_from_context($file, $extension){
  if (!file_exists($file)) return false;

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $newLines = [];
  $inContext = false;
  $deleted = false;

  foreach ($lines as $line) {
    $t = trim($line);

    if (preg_match('/^\[speed-dials\]$/i', $t)) {
      $inContext = true;
      $newLines[] = $line;
      continue;
    }

    if ($inContext && preg_match('/^\[[^]]+\]$/', $t)) {
      $inContext = false;
      $newLines[] = $line;
      continue;
    }

    if ($inContext) {
      $parsed = parse_speed_dial_line($line);
      if ($parsed && $parsed["extension"] == $extension) {
        $deleted = true;
        continue;
      }
    }

    $newLines[] = $line;
  }

  if (!$deleted) return false;

  $final = implode("\n", $newLines);
  return file_put_contents($file, $final) !== false;
}

$extension = trim($_GET["ext"] ?? $_POST["ext"] ?? "");

if ($extension === "") {
  die("Missing extension.");
}

if (delete_speed_dial_from_context($CFG_FILE, $extension)) {

  exec("sudo /usr/sbin/asterisk -rx 'core reload' 2>&1");
  
  header("Location: /speed_dials.php");
  exit;
}

die("Failed to delete Speed Dial.");
