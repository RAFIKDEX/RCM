<?php
require_once __DIR__ . '/auth.php';
rcm_require_login();

const JSON_FILE   = '/etc/asterisk/rcm_paging_intercom.json';
const GEN_SCRIPT  = '/usr/local/bin/rcm_gen_paging_intercom.sh';
const MEDIA_DB    = '/etc/asterisk/rcm_media_center.json';

function clean($v){ return trim((string)$v); }

function rcm_load_pi(string $path): array {
    if (!file_exists($path)) return ["items" => []];
    $raw = file_get_contents($path);
    $j = json_decode($raw, true);
    if (!is_array($j)) return ["items" => []];
    if (isset($j["items"]) && is_array($j["items"])) return $j;
    if (array_is_list($j)) return ["items" => $j];
    return ["items" => []];
}

function rcm_save_pi(array $data): bool {
    $data["updated_at"] = date("c");
    $out = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($out === false) return false;
    return file_put_contents(JSON_FILE, $out . "\n") !== false;
}

function rcm_run_gen(): void {
    shell_exec("sudo -n /bin/bash " . escapeshellarg(GEN_SCRIPT) . " 2>&1");
    shell_exec("sudo -n /usr/sbin/asterisk -rx 'dialplan reload' 2>&1");
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
    $_SESSION["paging_intercom_old"] = [
        "id"              => $_POST["id"]             ?? $_GET["id"] ?? "",
        "name"            => $_POST["name"]           ?? "",
        "type"            => $_POST["type"]           ?? "paging",
        "members"         => is_array($_POST["members"]         ?? null) ? $_POST["members"]         : [],
        "allowed_callers" => is_array($_POST["allowed_callers"] ?? null) ? $_POST["allowed_callers"] : [],
        "welcome_prompt"  => $_POST["welcome_prompt"] ?? "",
        "enabled"         => !empty($_POST["enabled"]),
    ];
    header("Location: /add_paging_intercom.php?err=" . urlencode($msg));
    exit;
}

function ok_redirect(string $msg): void {
    header("Location: /paging_intercom.php?msg=" . urlencode($msg));
    exit;
}

/* ======== DELETE ======== */
$delete_id = clean($_GET["id"] ?? $_POST["id"] ?? "");
$is_delete = (isset($_GET["delete"]) && $_GET["delete"] == "1") ||
             (isset($_POST["delete"]) && $_POST["delete"] == "1");

if ($is_delete) {
    if ($delete_id === "") ok_redirect("Missing id");

    $data  = rcm_load_pi(JSON_FILE);
    $items = $data["items"] ?? [];

    $items = array_values(array_filter($items, function($g) use ($delete_id){
        return (string)($g["id"] ?? "") !== $delete_id;
    }));

    $data["items"] = $items;

    if (!rcm_save_pi($data)) ok_redirect("Delete failed");

    rcm_run_gen();
    ok_redirect("Deleted: $delete_id");
}

/* ======== UPSERT ======== */
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    ok_redirect("Invalid request");
}

$id      = preg_replace('/\s+/', '', (string)($_POST["id"] ?? ""));
$name    = clean($_POST["name"]           ?? "");
$type    = clean($_POST["type"]           ?? "paging");
$prompt  = clean($_POST["welcome_prompt"] ?? "");
$enabled = !empty($_POST["enabled"]);

if ($id === "")                                 bad("Extension is required");
if (!preg_match('/^\d{2,6}$/', $id))            bad("Extension must be 2 to 6 digits");
if ($type !== "paging" && $type !== "intercom") $type = "paging";

/* members */
$members = $_POST["members"] ?? [];
if (!is_array($members)) $members = [];
$cleanMembers = [];
foreach ($members as $m) {
    $m = preg_replace('/\D+/', '', (string)$m);
    if ($m === "" || in_array($m, $cleanMembers, true)) continue;
    $cleanMembers[] = $m;
}
if (empty($cleanMembers)) bad("Select at least 1 member extension");

/* allowed callers */
$allowed = $_POST["allowed_callers"] ?? [];
if (!is_array($allowed)) $allowed = [];
$cleanAllowed = [];
foreach ($allowed as $m) {
    $m = preg_replace('/\D+/', '', (string)$m);
    if ($m === "" || in_array($m, $cleanAllowed, true)) continue;
    $cleanAllowed[] = $m;
}

/* validate prompt */
if ($prompt !== "") {
    $mediaDb = mc_load_db(MEDIA_DB);
    $found = false;
    foreach ($mediaDb["prompts"] as $p) {
        if (clean($p["id"] ?? "") === $prompt) { $found = true; break; }
    }
    if (!$found) bad("Selected prompt not found in Media Center");
}

$item = [
    "id"              => $id,
    "name"            => $name,
    "type"            => $type,
    "members"         => $cleanMembers,
    "allowed_callers" => $cleanAllowed,
    "welcome_prompt"  => $prompt,
    "enabled"         => $enabled,
];

$data  = rcm_load_pi(JSON_FILE);
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

if (!rcm_save_pi($data)) bad("Write failed");

rcm_run_gen();
ok_redirect("Saved: $id");
