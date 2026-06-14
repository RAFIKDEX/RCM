<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$JSON_FILE = "/etc/asterisk/rcm_outbound_routes.json";

// -- Helpers --------------------------------------------------

function rcm_load_json(string $jsonFile): array {
    if (!file_exists($jsonFile)) return ["routes" => []];
    $raw = file_get_contents($jsonFile);
    if ($raw === false || $raw === "") return ["routes" => []];
    $j = json_decode($raw, true);
    if (!is_array($j)) return ["routes" => []];
    if (isset($j["routes"]) && is_array($j["routes"])) return $j;
    if (array_is_list($j)) return ["routes" => $j];
    return ["routes" => []];
}

function rcm_save_json(string $jsonFile, array $data): void {
    $data["updated_at"] = date("c");
    $out = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($out === false) throw new RuntimeException("JSON encode failed: " . json_last_error_msg());
    // ???? ?? ??? ???? ????? ?? rename  atomic write
    $tmp = $jsonFile . ".tmp." . getmypid();
    if (file_put_contents($tmp, $out . "\n", LOCK_EX) === false) {
        throw new RuntimeException("Cannot write to $tmp");
    }
    rename($tmp, $jsonFile);
}

function rcm_run_cmd(string $cmd, string &$log): void {
    $log .= "CMD: $cmd\n";
    $out  = shell_exec($cmd . " 2>&1");
    $log .= ($out ?? "(no output)") . "\n";
}

function rcm_clean_lines(string $s): array {
    $lines = preg_split("/\r\n|\r|\n/", $s);
    $out   = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln !== "") $out[] = $ln;
    }
    return array_values(array_unique($out));
}

function rcm_norm_pattern(string $p): string {
    $p = trim($p);
    if ($p === "") return "";
    // uppercase wildcard letters
    $p = str_replace(["x", "n", "z"], ["X", "N", "Z"], $p);
    // ensure leading underscore
    if ($p[0] !== "_") $p = "_" . $p;
    return $p;
}

function rcm_sanitize_slug(string $s): string {
    // ???? ?????? ?????? ????? ???? ????? ???
    return preg_replace('/[^A-Za-z0-9_\-]/', '', trim($s));
}

function rcm_redirect(string $msg): never {
    header("Location: /outbound_routes.php?msg=" . urlencode($msg));
    exit;
}

// -- DELETE ---------------------------------------------------

if (isset($_GET["delete"]) && (string)$_GET["delete"] === "1") {
    $ctx = trim((string)($_GET["context"] ?? ""));
    if ($ctx === "") rcm_redirect("Missing context");

    $data           = rcm_load_json($JSON_FILE);
    $data["routes"] = array_values(array_filter(
        $data["routes"] ?? [],
        fn($r) => (string)($r["context"] ?? "") !== $ctx
    ));
    rcm_save_json($JSON_FILE, $data);

    $log = "";
    rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_gen_outbound_routes.sh",         $log);
    rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_apply_outbound_includes.sh",     $log);
    rcm_run_cmd("sudo /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload"), $log);

    rcm_redirect("Deleted: $ctx");
}

// -- UPSERT (ADD / EDIT) --------------------------------------

if ($_SERVER["REQUEST_METHOD"] !== "POST") rcm_redirect("Invalid request");

// -- slug / context --
$old_context = trim((string)($_POST["old_context"] ?? ""));
$slug        = rcm_sanitize_slug((string)($_POST["slug"] ?? ""));

if ($slug === "") rcm_redirect("Route Name (slug) is required");

$context = "rcm-out-" . $slug;

// -- scalar fields --
$name           = trim((string)($_POST["name"]           ?? ""));
$mode           = (string)($_POST["mode"]                ?? "whitelist");
$patterns_raw   = (string)($_POST["patterns"]            ?? "");
$timeout        = max(1,  (int)($_POST["timeout"]        ?? 30));
$time_limit_sec = max(0,  (int)($_POST["time_limit_sec"] ?? 0));
$strip          = max(0,  (int)($_POST["strip"]          ?? 0));
$prepend        = trim((string)($_POST["prepend"]        ?? ""));
$pin            = trim((string)($_POST["pin"]            ?? ""));
$enabled        = isset($_POST["enabled"]);
$record         = isset($_POST["record"]);

// -- array fields helper --
$getArr = static function(string $key): array {
    $v = $_POST[$key] ?? [];
    if (!is_array($v)) $v = [];
    return array_values(array_unique(
        array_filter(array_map("strval", $v), fn($x) => trim($x) !== "")
    ));
};

$trunks = $getArr("trunks");

$whitelist = isset($_POST["whitelist"]) && is_array($_POST["whitelist"])
    ? array_values(array_unique(array_map("strval", $_POST["whitelist"])))
    : [];
sort($whitelist, SORT_NATURAL);

$groups = $getArr("groups");
sort($groups, SORT_NATURAL);

// -- patterns --
$patterns = [];
foreach (rcm_clean_lines($patterns_raw) as $p) {
    $np = rcm_norm_pattern($p);
    if ($np !== "") $patterns[] = $np;
}
$patterns = array_values(array_unique($patterns));

// -- validation --
if (empty($patterns)) rcm_redirect("At least 1 pattern is required");
if (empty($trunks))   rcm_redirect("At least 1 trunk is required");

// -- build route object --
$route = [
    "name"           => $name,
    "context"        => $context,
    "enabled"        => $enabled,
    "mode"           => $mode === "groups" ? "groups" : "whitelist",
    "patterns"       => $patterns,
    "whitelist"      => $mode === "whitelist" ? $whitelist : [],
    "groups"         => $mode === "groups"    ? $groups    : [],
    "trunks"         => $trunks,
    "timeout"        => $timeout,
    "time_limit_sec" => $time_limit_sec,
    "strip"          => $strip,
    "prepend"        => $prepend,
    "pin"            => $pin,
    "record"         => $record,
];

// -- load & mutate routes array --
$data   = rcm_load_json($JSON_FILE);
$routes = is_array($data["routes"] ?? null) ? $data["routes"] : [];

// ?? ??? slug ????? ????? EDIT ? ???? ??? context ??????
if ($old_context !== "" && $old_context !== $context) {
    $routes = array_values(array_filter(
        $routes,
        fn($r) => (string)($r["context"] ?? "") !== $old_context
    ));
}

// ?? ADD ???? ???? ????? ?? ????? ? ??? suffix ????
if ($old_context === "") {
    $existing = array_column($routes, "context");
    if (in_array($context, $existing, true)) {
        $suffix           = substr(md5(uniqid("", true)), 0, 6);
        $context          = $context . "-" . $suffix;
        $route["context"] = $context;
    }
}

// upsert
$found = false;
foreach ($routes as $i => $r) {
    if ((string)($r["context"] ?? "") === $context) {
        $routes[$i] = $route;
        $found      = true;
        break;
    }
}
if (!$found) $routes[] = $route;

$data["routes"] = array_values($routes);
rcm_save_json($JSON_FILE, $data);

// -- apply --
$log = "";
rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_gen_outbound_routes.sh",         $log);
rcm_run_cmd("sudo /bin/bash /usr/local/bin/rcm_apply_outbound_includes.sh",     $log);
rcm_run_cmd("sudo /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload"), $log);

rcm_redirect("Saved: $context");