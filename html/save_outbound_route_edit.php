<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$JSON_FILE = "/etc/asterisk/rcm_outbound_routes.json";

function rcm_load_json(string $jsonFile): array {
  if (!file_exists($jsonFile)) return ["routes" => []];
  $raw = file_get_contents($jsonFile);
  $j = json_decode($raw, true);
  if (!is_array($j)) return ["routes" => []];
  if (isset($j["routes"]) && is_array($j["routes"])) return $j;
  if (array_is_list($j)) return ["routes" => $j];
  return ["routes" => []];
}

function rcm_save_json(string $jsonFile, array $data): void {
  $data["updated_at"] = date("c");
  $out = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($out === false) throw new Exception("JSON encode failed");
  // FIX: \n ?? \\n
  file_put_contents($jsonFile, $out . "\n", LOCK_EX);
}

function rcm_clean_lines(string $s): array {
  // FIX: \r\n ?? \\r\\n
  $lines = preg_split("/\r\n|\r|\n/", $s);
  $out = [];
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln !== "") $out[] = $ln;
  }
  return array_values(array_unique($out));
}

function rcm_norm_pattern(string $p): string {
  $p = trim($p);
  if ($p === "") return "";
  if ($p[0] !== "_") $p = "_" . $p;
  $p = str_replace(["x","n","z"], ["X","N","Z"], $p);
  return $p;
}

function rcm_run_cmd(string $cmd, &$log): bool {
  // FIX: \n ?? \\n
  $log .= "CMD: $cmd\n";
  $out = shell_exec($cmd . " 2>&1");
  $log .= ($out ?? "") . "\n";
  return true;
}

// -- DELETE --------------------------------------------------
if (isset($_GET["delete"]) && $_GET["delete"] == "1") {
  $ctx = trim((string)($_GET["context"] ?? ""));
  if ($ctx === "") {
    header("Location: /outbound_routes.php?msg=" . urlencode("Missing context"));
    exit;
  }
  $data   = rcm_load_json($JSON_FILE);
  $routes = array_values(array_filter(
    $data["routes"] ?? [],
    fn($r) => (string)($r["context"] ?? "") !== $ctx
  ));
  $data["routes"] = $routes;
  rcm_save_json($JSON_FILE, $data);

  $log = "";
  rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_gen_outbound_routes.sh",         $log);
  rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_apply_outbound_includes.sh",     $log);
  rcm_run_cmd("sudo /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload"), $log);

  header("Location: /outbound_routes.php?msg=" . urlencode("Deleted: $ctx"));
  exit;
}

// -- UPSERT (EDIT) --------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: /outbound_routes.php?msg=" . urlencode("Invalid request"));
  exit;
}

$old_context    = trim((string)($_POST["old_context"]    ?? ""));
$slug           = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string)($_POST["slug"] ?? "")));

if ($slug === "") {
  header("Location: /outbound_routes.php?msg=" . urlencode("Route Name (slug) is required"));
  exit;
}

$context        = "rcm-out-" . $slug;
$name           = trim((string)($_POST["name"]           ?? ""));
$mode           = (string)($_POST["mode"]                ?? "whitelist");
$patterns_raw   = (string)($_POST["patterns"]            ?? "");
$timeout        = (int)($_POST["timeout"]                ?? 30);
$time_limit_sec = (int)($_POST["time_limit_sec"]         ?? 0);
$strip          = (int)($_POST["strip"]                  ?? 0);
$prepend        = trim((string)($_POST["prepend"]        ?? ""));
$pin            = trim((string)($_POST["pin"]            ?? ""));
$enabled        = isset($_POST["enabled"]);
$record         = isset($_POST["record"]);

// -- trunks --
$trunks = isset($_POST["trunks"]) && is_array($_POST["trunks"]) ? $_POST["trunks"] : [];
$trunks = array_values(array_unique(array_filter(array_map("strval", $trunks), fn($x) => trim($x) !== "")));

// -- whitelist --
$whitelist = isset($_POST["whitelist"]) && is_array($_POST["whitelist"]) ? $_POST["whitelist"] : [];
$whitelist = array_values(array_unique(array_map("strval", $whitelist)));
sort($whitelist, SORT_NATURAL);

// -- groups --
$groups = isset($_POST["groups"]) && is_array($_POST["groups"]) ? $_POST["groups"] : [];
$groups = array_values(array_unique(array_filter(array_map("strval", $groups), fn($x) => trim($x) !== "")));
sort($groups, SORT_NATURAL);

// -- patterns --
$patterns = [];
foreach (rcm_clean_lines($patterns_raw) as $p) {
  $np = rcm_norm_pattern($p);
  if ($np !== "") $patterns[] = $np;
}
$patterns = array_values(array_unique($patterns));

if (empty($patterns)) {
  header("Location: /outbound_routes.php?msg=" . urlencode("At least 1 pattern is required"));
  exit;
}
if (empty($trunks)) {
  header("Location: /outbound_routes.php?msg=" . urlencode("At least 1 trunk is required"));
  exit;
}

if ($timeout        < 1) $timeout        = 30;
if ($time_limit_sec < 0) $time_limit_sec = 0;
if ($strip          < 0) $strip          = 0;

$route = [
  "name"           => $name,
  "context"        => $context,
  "enabled"        => $enabled,
  "mode"           => ($mode === "groups") ? "groups" : "whitelist",
  "patterns"       => $patterns,
  "whitelist"      => ($mode === "whitelist") ? $whitelist : [],
  "groups"         => ($mode === "groups")    ? $groups    : [],
  "trunks"         => $trunks,
  "timeout"        => $timeout,
  "time_limit_sec" => $time_limit_sec,
  "strip"          => $strip,
  "prepend"        => $prepend,
  "pin"            => $pin,
  "record"         => $record,
];

$data   = rcm_load_json($JSON_FILE);
$routes = $data["routes"] ?? [];
if (!is_array($routes)) $routes = [];

// ?? ??? slug ?????: ???? ??? context ??????
if ($old_context !== "" && $old_context !== $context) {
  $routes = array_values(array_filter(
    $routes,
    fn($r) => (string)($r["context"] ?? "") !== $old_context
  ));
}

// upsert
$found = false;
for ($i = 0; $i < count($routes); $i++) {
  if ((string)($routes[$i]["context"] ?? "") === $context) {
    $routes[$i] = $route;
    $found = true;
    break;
  }
}
if (!$found) $routes[] = $route;

$data["routes"] = $routes;
rcm_save_json($JSON_FILE, $data);

$log = "";
rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_gen_outbound_routes.sh",         $log);
rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_apply_outbound_includes.sh",     $log);
rcm_run_cmd("sudo /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload"), $log);

header("Location: /outbound_routes.php?msg=" . urlencode("Saved: $context"));
exit;