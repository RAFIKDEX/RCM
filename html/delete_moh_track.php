<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$MOH_BASE = "/var/lib/asterisk/moh/rcm";

function back($class, $k, $v){
    header("Location: /moh_tracks.php?class=" . urlencode($class) . "&{$k}=" . urlencode($v));
    exit;
}

$class = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($_GET['class'] ?? '')));
$file  = basename(trim($_GET['file'] ?? ''));

if ($class === '') back('unknown', 'err', 'Missing class name');
if ($file === '') back($class, 'err', 'Missing track file');

$path = $MOH_BASE . '/' . $class . '/' . $file;
if (!is_file($path)) back($class, 'err', 'Track not found');

if (!@unlink($path)) {
    back($class, 'err', 'Cannot delete track');
}

@exec('sudo /usr/sbin/asterisk -rx ' . escapeshellarg('moh reload') . ' 2>&1');
back($class, 'msg', 'Track deleted successfully');
