<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();
$DB_FILE = "/etc/asterisk/rcm_media_center.json";
$MOH_BASE = "/var/lib/asterisk/moh/rcm";
$MOH_CONF = "/etc/asterisk/musiconhold.conf";
function back($k,$v){ header("Location: /media_center.php?{$k}=" . urlencode($v)); exit; }
function clean_name($s){ return preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($s))); }
function load_db($f){ $db = json_decode(@file_get_contents($f), true); if (!is_array($db)) $db=["prompts"=>[],"moh_classes"=>[]]; $db['prompts']=$db['prompts']??[]; $db['moh_classes']=$db['moh_classes']??[]; return $db; }
function save_db($f,$db){ $dir=dirname($f); if(!is_dir($dir)) @mkdir($dir,0755,true); file_put_contents($f,json_encode($db,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }
function ensure_moh_conf($class, $dir, $mode, $file){
  $mode = in_array($mode,['files','sortalpha','random'],true) ? $mode : 'files';
  $block = "; --- RCM MOH {$class} ---\n[{$class}]\nmode={$mode}\ndirectory={$dir}\n\n";
  $cur = file_exists($file) ? file_get_contents($file) : '';
  if (strpos($cur, "; --- RCM MOH {$class} ---") !== false) {
    $cur = preg_replace('/;\s*---\s*RCM MOH\s+' . preg_quote($class, '/') . '\s*---.*?(?=(?:;\s*---\s*RCM MOH)|\z)/s', $block, $cur);
  } else {
    $cur .= "\n" . $block;
  }
  file_put_contents($file, $cur);
}
function save_track($tmp, $dest){
  $cmd = null;
  $ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
  if ($ext !== 'wav') return false;
  if (trim(shell_exec('command -v sox 2>/dev/null') ?? '') !== '') {
    $cmd = 'sox ' . escapeshellarg($tmp) . ' -r 8000 -c 1 -b 16 -e signed-integer ' . escapeshellarg($dest) . ' 2>&1';
  } else {
    $cmd = 'ffmpeg -y -i ' . escapeshellarg($tmp) . ' -ar 8000 -ac 1 -c:a pcm_s16le ' . escapeshellarg($dest) . ' 2>&1';
  }
  exec($cmd, $o, $r);
  return $r === 0;
}
$db = load_db($DB_FILE);
$appendTo = clean_name($_POST['append_to'] ?? '');
if ($appendTo !== '') {
  $class = $appendTo;
  $mode = 'files';
  foreach ($db['moh_classes'] as $c) if (($c['name'] ?? '') === $class) $mode = $c['mode'] ?? 'files';
} else {
  $class = clean_name($_POST['name'] ?? '');
  $mode = trim($_POST['mode'] ?? 'files');
  if ($class === '' || !preg_match('/^[a-z0-9_-]{2,60}$/', $class)) back('err', 'Invalid class name');
  foreach ($db['moh_classes'] as $c){ if (($c['name'] ?? '') === $class) back('err', 'MOH class already exists'); }
  $db['moh_classes'][] = ['name'=>$class,'mode'=>$mode,'dir'=>$MOH_BASE . '/' . $class,'created_at'=>date('c')];
}
$dir = $MOH_BASE . '/' . $class;
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) back('err', 'Cannot create MOH folder');
if (!isset($_FILES['tracks'])) { save_db($DB_FILE,$db); ensure_moh_conf($class,$dir,$mode,$MOH_CONF); back('msg', 'MOH class saved'); }
$names = $_FILES['tracks']['name'] ?? [];
$tmpNames = $_FILES['tracks']['tmp_name'] ?? [];
$errors = $_FILES['tracks']['error'] ?? [];
for ($i=0; $i<count($names); $i++){
  if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
  $base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($names[$i], PATHINFO_FILENAME));
  $dest = $dir . '/' . $base . '.wav';
  $src = '/tmp/moh_' . uniqid() . '_' . basename($names[$i]);
  if (!move_uploaded_file($tmpNames[$i], $src)) continue;
  save_track($src, $dest);
  @unlink($src);
  @chmod($dest, 0644);
}
save_db($DB_FILE,$db);
ensure_moh_conf($class,$dir,$mode,$MOH_CONF);
@exec('sudo /usr/sbin/asterisk -rx ' . escapeshellarg('moh reload') . ' 2>&1');
back('msg', $appendTo !== '' ? 'Tracks added to MOH class' : 'MOH class created');
