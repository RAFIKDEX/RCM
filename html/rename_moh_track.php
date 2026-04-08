<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$MOH_BASE = "/var/lib/asterisk/moh/rcm";

function back($class, $k, $v){
    header("Location: /moh_tracks.php?class=" . urlencode($class) . "&{$k}=" . urlencode($v));
    exit;
}

$class   = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($_POST['class'] ?? '')));
$oldFile = basename(trim($_POST['old_file'] ?? ''));
$newName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($_POST['new_name'] ?? '')));

if ($class === '') back('unknown', 'err', 'Missing class name');
if ($oldFile === '') back($class, 'err', 'Missing old file');
if ($newName === '') back($class, 'err', 'Invalid new name');

$oldPath = $MOH_BASE . '/' . $class . '/' . $oldFile;
if (!is_file($oldPath)) back($class, 'err', 'Track not found');

$ext = strtolower(pathinfo($oldFile, PATHINFO_EXTENSION));
if ($ext === '') $ext = 'wav';

$newFile = $newName . '.' . $ext;
$newPath = $MOH_BASE . '/' . $class . '/' . $newFile;

if (is_file($newPath)) back($class, 'err', 'Another track with the same name already exists');

if (!@rename($oldPath, $newPath)) {
    back($class, 'err', 'Cannot rename track');
}

@chmod($newPath, 0644);
back($class, 'msg', 'Track renamed successfully');
