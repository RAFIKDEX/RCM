<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$DB_FILE = "/etc/asterisk/rcm_media_center.json";
$PROMPT_BASE = "/var/lib/asterisk/sounds/en/rcm/media";

function fail_back($msg){
    header("Location: /media_center.php?tab=prompts&err=" . urlencode($msg));
    exit;
}

function ok_back($msg){
    header("Location: /media_center.php?tab=prompts&msg=" . urlencode($msg));
    exit;
}

function load_db($file){
    if (!file_exists($file)) return ["prompts"=>[],"moh_classes"=>[]];
    $db = json_decode(@file_get_contents($file), true);
    if (!is_array($db)) $db = ["prompts"=>[],"moh_classes"=>[]];
    $db['prompts'] = $db['prompts'] ?? [];
    $db['moh_classes'] = $db['moh_classes'] ?? [];
    return $db;
}

function save_db($file, $db){
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

function clean_name($s){
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($s)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail_back('Invalid request');

$name = clean_name($_POST['name'] ?? '');
$type = trim($_POST['type'] ?? 'general');

if ($name === '' || !preg_match('/^[a-z0-9_-]{2,60}$/', $name)) fail_back('Invalid prompt name');
if (!in_array($type, ['ivr','announcement','queue','general'], true)) fail_back('Invalid type');
if (!isset($_FILES['audio']) || ($_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail_back('Please choose an audio file');

$db = load_db($DB_FILE);
foreach ($db['prompts'] as $p){
    if (($p['name'] ?? '') === $name) fail_back('Prompt name already exists');
}

$ext = strtolower(pathinfo($_FILES['audio']['name'] ?? '', PATHINFO_EXTENSION));
if (!in_array($ext, ['wav','mp3','gsm','ogg'], true)) fail_back('Only wav/mp3/gsm/ogg allowed');

$dir = $PROMPT_BASE . '/' . $type;
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail_back('Cannot create prompt folder');

$dest = $dir . '/' . $name . '.wav';
$tmp = $_FILES['audio']['tmp_name'];

if ($ext === 'wav') {
    if (!move_uploaded_file($tmp, $dest)) fail_back('Cannot save wav file');
} else {
    $src = '/tmp/mc_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['audio']['name']));
    if (!move_uploaded_file($tmp, $src)) fail_back('Cannot move upload');

    if (trim(shell_exec('command -v sox 2>/dev/null') ?? '') !== '') {
        $cmd = 'sox ' . escapeshellarg($src) . ' -r 8000 -c 1 -b 16 -e signed-integer ' . escapeshellarg($dest) . ' 2>&1';
    } else {
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($src) . ' -ar 8000 -ac 1 -c:a pcm_s16le ' . escapeshellarg($dest) . ' 2>&1';
    }

    exec($cmd, $out, $ret);
    @unlink($src);

    if ($ret !== 0) fail_back('Audio conversion failed');
}

@chmod($dest, 0644);

$id = 'p_' . bin2hex(random_bytes(6));
$db['prompts'][] = [
    'id' => $id,
    'name' => $name,
    'type' => $type,
    'path' => $dest,
    'created_at' => date('c')
];

if (save_db($DB_FILE, $db) === false) fail_back('Cannot save metadata');

ok_back('Prompt uploaded successfully');