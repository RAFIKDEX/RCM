<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();
$DB_FILE = "/etc/asterisk/rcm_media_center.json";
$MOH_BASE = "/var/lib/asterisk/moh/rcm";
$MOH_CONF = "/etc/asterisk/musiconhold.conf";
function back($k,$v){ header("Location: /media_center.php?{$k}=" . urlencode($v)); exit; }
$name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($_GET['name'] ?? '')));
if ($name === '') back('err', 'Missing class name');
$db = json_decode(@file_get_contents($DB_FILE), true);
if (!is_array($db)) back('err', 'Media DB not found');
$new = [];
$found = false;
foreach (($db['moh_classes'] ?? []) as $c){
  if (($c['name'] ?? '') === $name) { $found = true; continue; }
  $new[] = $c;
}
if (!$found) back('err', 'MOH class not found');
$db['moh_classes'] = $new;
file_put_contents($DB_FILE, json_encode($db, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$dir = $MOH_BASE . '/' . $name;
if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
if (file_exists($MOH_CONF)) {
  $cur = file_get_contents($MOH_CONF);
  $cur = preg_replace('/;\s*---\s*RCM MOH\s+' . preg_quote($name, '/') . '\s*---.*?(?=(?:;\s*---\s*RCM MOH)|\z)/s', '', $cur);
  file_put_contents($MOH_CONF, $cur);
}
@exec('sudo /usr/sbin/asterisk -rx ' . escapeshellarg('moh reload') . ' 2>&1');
back('msg', 'MOH class deleted');
