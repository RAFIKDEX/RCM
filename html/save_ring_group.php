<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$JSON = "/etc/asterisk/rcm_ring_groups.json";
$SAVE_HELPER = "/usr/local/bin/rcm_save_ring_groups_json.sh";
$GEN_SCRIPT  = "/usr/local/bin/rcm_gen_ring_groups.sh";

function rcm_load_rg(string $path): array {
  if (!file_exists($path)) return ["groups" => []];

  $raw = file_get_contents($path);
  $j = json_decode($raw, true);

  if (!is_array($j)) return ["groups" => []];
  if (isset($j["groups"]) && is_array($j["groups"])) return $j;
  if (array_is_list($j)) return ["groups" => $j];

  return ["groups" => []];
}

function rcm_encode_rg(array $data): string {
  $data["updated_at"] = date("c");
  $out = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($out === false) {
    throw new Exception("JSON encode failed");
  }
  return $out . "\n";
}

function rcm_run_cmd(string $cmd, &$outAll = ""): int {
  $outAll .= "CMD: $cmd\n";
  $out = shell_exec($cmd . " 2>&1");
  $outAll .= ($out ?? "") . "\n";
  return 0;
}

function rcm_write_json_via_helper(string $jsonText, &$log = ""): int {
  global $SAVE_HELPER;

  if (!file_exists($SAVE_HELPER)) {
    $log .= "ERROR: helper not found: $SAVE_HELPER\n";
    return 1;
  }

  $tmp = tempnam(sys_get_temp_dir(), "rcm_rg_");
  if ($tmp === false) {
    $log .= "ERROR: tempnam failed\n";
    return 1;
  }

  $ok = file_put_contents($tmp, $jsonText);
  if ($ok === false) {
    @unlink($tmp);
    $log .= "ERROR: failed to write temp json\n";
    return 1;
  }

  $cmd = "sudo -n /bin/bash " . escapeshellarg($SAVE_HELPER) . " " . escapeshellarg($tmp);
  $out = shell_exec($cmd . " 2>&1");
  $rc = 0;

  // crude rc check: if sudo/helper fails shell_exec still returns output only
  // so verify target file changed/exists separately by output content
  $log .= "HELPER CMD: $cmd\n";
  $log .= "HELPER OUT:\n" . ($out ?? "") . "\n";

  @unlink($tmp);

  if ($out === null) {
    return 1;
  }

  if (stripos($out, "OK: wrote") === false) {
    return 1;
  }

  return $rc;
}

function bad(string $msg): void {
  $_SESSION["ring_group_old"] = [
    "id" => $_POST["id"] ?? "",
    "name" => $_POST["name"] ?? "",
    "strategy" => $_POST["strategy"] ?? "ringall",
    "timeout" => $_POST["timeout"] ?? 20,
    "per_try" => $_POST["per_try"] ?? 10,
    "enabled" => isset($_POST["enabled"]),
    "members" => is_array($_POST["members"] ?? null) ? $_POST["members"] : [],
  ];

  header("Location: /add_ring_group.php?err=" . urlencode($msg));
  exit;
}

function ok_redirect(string $msg): void {
  header("Location: /ring_groups.php?msg=" . urlencode($msg));
  exit;
}

/* DELETE */
if (isset($_GET["delete"]) && $_GET["delete"] == "1") {
  $id = trim((string)($_GET["id"] ?? ""));
  if ($id === "") ok_redirect("Missing id");

  $data = rcm_load_rg($JSON);
  $groups = $data["groups"] ?? [];
  if (!is_array($groups)) $groups = [];

  $groups = array_values(array_filter($groups, function($g) use ($id) {
    return (string)($g["id"] ?? "") !== $id;
  }));

  $data["groups"] = $groups;

  try {
    $jsonText = rcm_encode_rg($data);
  } catch (Exception $ex) {
    ok_redirect("Delete failed: " . $ex->getMessage());
  }

  $log = "";
  $rc = rcm_write_json_via_helper($jsonText, $log);
  if ($rc !== 0) ok_redirect("Delete failed");

  rcm_run_cmd("sudo -n /bin/bash " . escapeshellarg($GEN_SCRIPT), $log);
  rcm_run_cmd("sudo -n /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload"), $log);

  ok_redirect("Deleted: $id");
}

/* UPSERT */
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  ok_redirect("Invalid request");
}

$id       = preg_replace('/\s+/', '', (string)($_POST["id"] ?? ""));
$name     = trim((string)($_POST["name"] ?? ""));
$strategy = (string)($_POST["strategy"] ?? "ringall");
$timeout  = (int)($_POST["timeout"] ?? 20);
$per      = (int)($_POST["per_try"] ?? 10);
$enabled  = isset($_POST["enabled"]) ? true : false;

if ($id === "") bad("ID is required");
if (!preg_match('/^8\d{3}$/', $id)) bad("ID must be 8XXX (e.g. 8001)");
if ($strategy !== "ringall" && $strategy !== "ordered") $strategy = "ringall";
if ($timeout < 1) $timeout = 20;
if ($per < 1) $per = 10;

$members = $_POST["members"] ?? [];
if (!is_array($members)) $members = [];

$clean = [];
foreach ($members as $m) {
  $m = preg_replace('/\D+/', '', (string)$m);
  if ($m === "") continue;
  if (!in_array($m, $clean, true)) $clean[] = $m;
}
if (empty($clean)) bad("Select at least 1 member extension");

$group = [
  "id" => $id,
  "name" => $name,
  "strategy" => $strategy,
  "timeout" => $timeout,
  "per_try" => $per,
  "enabled" => $enabled,
  "members" => $clean,
];

$data = rcm_load_rg($JSON);
$groups = $data["groups"] ?? [];
if (!is_array($groups)) $groups = [];

$found = false;
for ($i = 0; $i < count($groups); $i++) {
  if ((string)($groups[$i]["id"] ?? "") === $id) {
    $groups[$i] = $group;
    $found = true;
    break;
  }
}
if (!$found) $groups[] = $group;

$data["groups"] = $groups;

try {
  $jsonText = rcm_encode_rg($data);
} catch (Exception $ex) {
  bad($ex->getMessage());
}

$log = "";
$rc = rcm_write_json_via_helper($jsonText, $log);
if ($rc !== 0) {
  bad("Write failed");
}

rcm_run_cmd("sudo -n /bin/bash " . escapeshellarg($GEN_SCRIPT), $log);
rcm_run_cmd("sudo -n /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload"), $log);

ok_redirect("Saved: $id");