<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();
$DB_FILE = "/etc/asterisk/rcm_media_center.json";
function back($k,$v){ header("Location: /media_center.php?{$k}=" . urlencode($v)); exit; }
function clean_name($s){ return preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($s))); }
$db = json_decode(@file_get_contents($DB_FILE), true);
if (!is_array($db)) back('err','Media DB not found');
$id = trim($_POST['id'] ?? '');
$new = clean_name($_POST['new_name'] ?? '');
if ($id === '' || $new === '') back('err', 'Invalid rename request');
foreach (($db['prompts'] ?? []) as $p){ if (($p['name'] ?? '') === $new) back('err', 'Name already exists'); }
$found = false;
foreach ($db['prompts'] as &$p){
  if (($p['id'] ?? '') !== $id) continue;
  $oldPath = $p['path'] ?? '';
  $dir = dirname($oldPath);
  $newPath = $dir . '/' . $new . '.wav';
  if (is_file($oldPath) && !@rename($oldPath, $newPath)) back('err', 'Cannot rename file');
  $p['name'] = $new;
  $p['path'] = $newPath;
  $found = true;
  break;
}
unset($p);
if (!$found) back('err', 'Prompt not found');
file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
back('msg', 'Prompt renamed');
