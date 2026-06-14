<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();
$DB_FILE = "/etc/asterisk/rcm_media_center.json";
function back($k,$v){ header("Location: /media_center.php?{$k}=" . urlencode($v)); exit; }
$id = trim($_GET['id'] ?? '');
if ($id === '') back('err', 'Missing prompt id');
$db = json_decode(@file_get_contents($DB_FILE), true);
if (!is_array($db)) back('err', 'Media DB not found');
$new = [];
$deleted = false;
foreach (($db['prompts'] ?? []) as $p){
  if (($p['id'] ?? '') === $id) {
    $path = $p['path'] ?? '';
    if (is_file($path)) @unlink($path);
    $deleted = true;
    continue;
  }
  $new[] = $p;
}
if (!$deleted) back('err', 'Prompt not found');
$db['prompts'] = $new;
file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
back('msg', 'Prompt deleted');
