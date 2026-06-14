<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$DB_FILE = "/etc/asterisk/rcm_media_center.json";
$MOH_BASE = "/var/lib/asterisk/moh/rcm";

function send_audio($path){
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'wav'  => 'audio/wav',
        'mp3'  => 'audio/mpeg',
        'gsm'  => 'audio/gsm',
        'ogg'  => 'audio/ogg',
        'ulaw' => 'audio/basic',
        'alaw' => 'audio/basic',
    ];

    header('Content-Type: ' . ($map[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Accept-Ranges: bytes');
    readfile($path);
    exit;
}

$db = json_decode(@file_get_contents($DB_FILE), true);
if (!is_array($db)) $db = ["prompts"=>[],"moh_classes"=>[]];

$kind = trim($_GET['kind'] ?? '');

if ($kind === 'prompt') {
    $id = trim($_GET['id'] ?? '');
    if ($id === '') {
        http_response_code(404);
        exit;
    }

    foreach (($db['prompts'] ?? []) as $p) {
        if (($p['id'] ?? '') !== $id) continue;
        send_audio($p['path'] ?? '');
    }

    http_response_code(404);
    exit;
}

if ($kind === 'moh_track') {
    $class = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($_GET['class'] ?? '')));
    $file  = basename(trim($_GET['file'] ?? ''));

    if ($class === '' || $file === '') {
        http_response_code(404);
        exit;
    }

    $path = $MOH_BASE . '/' . $class . '/' . $file;
    send_audio($path);
}

http_response_code(404);
exit;