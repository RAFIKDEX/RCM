<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$ANN_PROMPTS_DIR = "/var/lib/asterisk/sounds/en/rcm/ann";
$MEDIA_DB = "/etc/asterisk/rcm_media_center.json";

function go_back($editing, $num, $key, $msg){
    $url = '/add_announcement.php';
    if ($editing && preg_match('/^\d{2,6}$/', $num)) {
        $url .= '?edit=' . urlencode($num) . '&' . $key . '=' . urlencode($msg);
    } else {
        $url .= '?' . $key . '=' . urlencode($msg);
    }
    header('Location: ' . $url);
    exit;
}

function load_media_db($file){
    if (!is_file($file)) return ["prompts" => [], "moh_classes" => []];
    $db = json_decode((string)@file_get_contents($file), true);
    if (!is_array($db)) $db = ["prompts" => [], "moh_classes" => []];
    if (!isset($db['prompts']) || !is_array($db['prompts'])) $db['prompts'] = [];
    if (!isset($db['moh_classes']) || !is_array($db['moh_classes'])) $db['moh_classes'] = [];
    return $db;
}

function find_media_prompt($file, $needle){
    $db = load_media_db($file);
    foreach ($db['prompts'] as $p){
        $id = (string)($p['id'] ?? '');
        $name = (string)($p['name'] ?? '');
        $type = strtolower((string)($p['type'] ?? 'general'));
        $path = (string)($p['path'] ?? '');
        if (!in_array($type, ['announcement', 'general'], true)) continue;
        if ($path === '' || !is_file($path)) continue;
        if ($needle === $id || $needle === $name){
            return $p;
        }
    }
    return null;
}

function upload_prompt_file($sourcePath, $num, &$error){
    if (!is_file($sourcePath)){
        $error = 'Selected prompt file not found';
        return false;
    }
    $cmd = 'sudo /usr/local/bin/rcm_upload_ann_prompt.sh ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($num) . ' 2>&1';
    $out = shell_exec($cmd) ?? '';
    if (strpos($out, 'OK:') !== 0){
        $error = trim($out) !== '' ? trim($out) : 'Upload failed';
        return false;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /announcements.php');
    exit;
}

$num = trim((string)($_POST['num'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$destType = trim((string)($_POST['dest_type'] ?? 'hangup'));
$destVal = trim((string)($_POST['dest_val'] ?? ''));
$promptSource = trim((string)($_POST['prompt_source'] ?? 'media'));
$useExistingPrompt = trim((string)($_POST['use_existing_prompt'] ?? ''));
$useMediaPrompt = trim((string)($_POST['use_media_prompt'] ?? ''));
$editing = (trim((string)($_POST['is_edit'] ?? '0')) === '1');

if (!preg_match('/^\d{2,6}$/', $num)) go_back($editing, $num, 'err', 'Invalid number');
if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]{2,30}$/', $name)) go_back($editing, $num, 'err', 'Invalid name');
if (!in_array($destType, ['hangup','extension','ivr','queue'], true)) go_back($editing, $num, 'err', 'Invalid destination type');
if (!in_array($promptSource, $editing ? ['keep','media','existing','upload'] : ['media','existing','upload'], true)) go_back($editing, $num, 'err', 'Invalid prompt source');

if ($destType === 'extension' || $destType === 'ivr') {
    if (!preg_match('/^\d{2,6}$/', $destVal)) go_back($editing, $num, 'err', 'Destination must be a valid number');
} elseif ($destType === 'queue') {
    if (!preg_match('/^[a-zA-Z0-9_-]{2,40}$/', $destVal)) go_back($editing, $num, 'err', 'Invalid queue name');
} else {
    $destVal = '';
}

$hasFile = isset($_FILES['prompt']) && ($_FILES['prompt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

if (!$editing && $promptSource === 'media' && $useMediaPrompt === '') go_back($editing, $num, 'err', 'Choose a Media Center prompt');
if (!$editing && $promptSource === 'existing' && !preg_match('/^\d{2,6}$/', $useExistingPrompt)) go_back($editing, $num, 'err', 'Choose an existing announcement prompt');
if (!$editing && $promptSource === 'upload' && !$hasFile) go_back($editing, $num, 'err', 'Please upload an audio file');
if ($editing && $promptSource === 'media' && $useMediaPrompt === '') go_back($editing, $num, 'err', 'Choose a Media Center prompt');
if ($editing && $promptSource === 'existing' && !preg_match('/^\d{2,6}$/', $useExistingPrompt)) go_back($editing, $num, 'err', 'Choose an existing announcement prompt');

if ($promptSource === 'media') {
    $selected = find_media_prompt($MEDIA_DB, $useMediaPrompt);
    if (!$selected) go_back($editing, $num, 'err', 'Selected Media Center prompt not found');
    $err = '';
    if (!upload_prompt_file((string)$selected['path'], $num, $err)) go_back($editing, $num, 'err', $err);
}

if ($promptSource === 'existing') {
    $src = $ANN_PROMPTS_DIR . '/ann_' . $useExistingPrompt . '.wav';
    $err = '';
    if (!upload_prompt_file($src, $num, $err)) go_back($editing, $num, 'err', $err);
}

if ($promptSource === 'upload') {
    $orig = (string)($_FILES['prompt']['name'] ?? '');
    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['wav','mp3','gsm','ogg'], true)) go_back($editing, $num, 'err', 'Only wav/mp3/gsm/ogg allowed');
    $tmp = '/tmp/ann_' . $num . '_' . uniqid('', true) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($orig));
    if (!move_uploaded_file($_FILES['prompt']['tmp_name'], $tmp)) go_back($editing, $num, 'err', 'Cannot move uploaded file');
    $err = '';
    $ok = upload_prompt_file($tmp, $num, $err);
    @unlink($tmp);
    if (!$ok) go_back($editing, $num, 'err', $err);
}

$cmd = 'sudo /usr/local/bin/rcm_add_ann.sh '
    . escapeshellarg($num) . ' '
    . escapeshellarg($name) . ' '
    . escapeshellarg($destType) . ' '
    . escapeshellarg($destVal === '' ? '-' : $destVal) . ' 2>&1';
$out = shell_exec($cmd) ?? '';
if (strpos($out, 'OK:') !== 0) {
    go_back($editing, $num, 'err', trim($out) !== '' ? trim($out) : 'Failed to save announcement');
}

header('Location: /announcements.php?msg=' . urlencode('Announcement saved successfully'));
exit;
