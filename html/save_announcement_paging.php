<?php
require_once __DIR__ . '/auth.php';
rcm_require_login();

const ANN_JSON   = '/etc/asterisk/rcm_announcement_paging.json';
const ANN_GEN    = '/usr/local/bin/rcm_gen_announcement_paging.sh';
const MEDIA_DB   = '/etc/asterisk/rcm_media_center.json';

function clean($v){ return trim((string)$v); }

function rcm_load_ann(string $path): array {
    if (!file_exists($path)) return ["items" => []];
    $raw = file_get_contents($path);
    $j = json_decode($raw, true);
    if (!is_array($j)) return ["items" => []];
    if (isset($j["items"]) && is_array($j["items"])) return $j;
    if (array_is_list($j)) return ["items" => $j];
    return ["items" => []];
}

function rcm_save_ann(array $data): bool {
    $data["updated_at"] = date("c");
    $out = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($out === false) return false;
    return file_put_contents(ANN_JSON, $out . "\n") !== false;
}

function rcm_run_gen(): void {
    shell_exec("sudo -n /bin/bash " . escapeshellarg(ANN_GEN) . " 2>&1");
}

function mc_load_db(string $file): array {
    if (!file_exists($file)) return ['prompts' => []];
    $raw = @file_get_contents($file);
    if (!$raw) return ['prompts' => []];
    $db = json_decode($raw, true);
    if (!is_array($db)) return ['prompts' => []];
    if (!isset($db['prompts'])) $db['prompts'] = [];
    return $db;
}

function bad(string $msg): void {
    $_SESSION["ann_paging_old"] = [
        "id"         => $_POST["id"]         ?? "",
        "name"       => $_POST["name"]       ?? "",
        "prompt_id"  => $_POST["prompt_id"]  ?? "",
        "members"    => is_array($_POST["members"] ?? null) ? $_POST["members"] : [],
        "play_count" => $_POST["play_count"] ?? 1,
        "days"       => is_array($_POST["days"] ?? null) ? $_POST["days"] : [],
        "time"       => $_POST["time"]       ?? "08:00",
        "enabled"    => !empty($_POST["enabled"]),
    ];
    header("Location: /add_announcement_paging.php?err=" . urlencode($msg));
    exit;
}

function ok_redirect(string $msg): void {
    header("Location: /announcement_paging.php?msg=" . urlencode($msg));
    exit;
}

/* DELETE */
$is_delete = (isset($_GET["delete"]) && $_GET["delete"] == "1") ||
             (isset($_POST["delete"]) && $_POST["delete"] == "1");

if ($is_delete) {
    $del_id = clean($_GET["id"] ?? $_POST["id"] ?? "");
    if ($del_id === "") ok_redirect("Missing id");

    $data  = rcm_load_ann(ANN_JSON);
    $items = $data["items"] ?? [];

    $items = array_values(array_filter($items, function($g) use ($del_id){
        return (string)($g["id"] ?? "") !== $del_id;
    }));

    $data["items"] = $items;
    if (!rcm_save_ann($data)) ok_redirect("Delete failed");

    rcm_run_gen();
    ok_redirect("Deleted successfully");
}

/* UPSERT */
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    ok_redirect("Invalid request");
}

$id         = clean($_POST["id"]        ?? "");
$name       = clean($_POST["name"]      ?? "");
$prompt_id  = clean($_POST["prompt_id"] ?? "");
$play_count = max(1, (int)($_POST["play_count"] ?? 1));
$time       = clean($_POST["time"]      ?? "08:00");
$enabled    = !empty($_POST["enabled"]);

if ($id === "")   bad("ID is required");
if ($name === "") bad("Name is required");

/* days */
$days = $_POST["days"] ?? [];
if (!is_array($days)) $days = [];
$validDays = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
$days = array_values(array_filter($days, fn($d) => in_array($d, $validDays)));
if (empty($days)) bad("Select at least one day");

/* validate time */
if (!preg_match('/^\d{2}:\d{2}$/', $time)) bad("Invalid time format");

/* members */
$members = $_POST["members"] ?? [];
if (!is_array($members)) $members = [];
$cleanMembers = [];
foreach ($members as $m) {
    $m = preg_replace('/\D+/', '', (string)$m);
    if ($m === "" || in_array($m, $cleanMembers, true)) continue;
    $cleanMembers[] = $m;
}
if (empty($cleanMembers)) bad("Select at least 1 member");

/* validate prompt */
$prompt_name = "";
$prompt_path = "";
if ($prompt_id !== "") {
    $mediaDb = mc_load_db(MEDIA_DB);
    $found = false;
    foreach ($mediaDb["prompts"] as $p) {
        if (clean($p["id"] ?? "") === $prompt_id) {
            $found = true;
            $prompt_name = clean($p["name"] ?? "");
            $full_path   = clean($p["path"] ?? "");
            if ($full_path !== "") {
                $no_ext      = preg_replace('/\.[^.]+$/', '', $full_path);
                $prompt_path = preg_replace('|^.*/sounds/en/|', '', $no_ext);
            }
            break;
        }
    }
    if (!$found) bad("Selected prompt not found in Media Center");
}

$item = [
    "id"          => $id,
    "name"        => $name,
    "prompt_id"   => $prompt_id,
    "prompt_name" => $prompt_name,
    "prompt_path" => $prompt_path,
    "members"     => $cleanMembers,
    "play_count"  => $play_count,
    "days"        => $days,
    "time"        => $time,
    "enabled"     => $enabled,
];

$data  = rcm_load_ann(ANN_JSON);
$items = $data["items"] ?? [];
if (!is_array($items)) $items = [];

$found = false;
for ($i = 0; $i < count($items); $i++) {
    if ((string)($items[$i]["id"] ?? "") === $id) {
        $items[$i] = $item;
        $found = true;
        break;
    }
}
if (!$found) $items[] = $item;

$data["items"] = $items;
if (!rcm_save_ann($data)) bad("Write failed");

rcm_run_gen();
ok_redirect("Saved: $name");
