<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ASTERISK_DIR = "/etc/asterisk";
$FILES = [
  "$ASTERISK_DIR/pjsip.gui.endpoint.conf",
  "$ASTERISK_DIR/pjsip.gui.aor.conf",
  "$ASTERISK_DIR/pjsip.gui.auth.conf",
  "$ASTERISK_DIR/pjsip.gui.reg.conf",
  "$ASTERISK_DIR/pjsip.gui.identify.conf",
];

function sanitize_id($s){
  $s = trim((string)$s);
  return preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
}

function remove_marker_block($content, $name){
  // remove:
  // ; --- RCM-TRUNK: NAME BEGIN ---
  // ...
  // ; --- RCM-TRUNK: NAME END ---
  $re = '/^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*BEGIN\s*---\s*$([\s\S]*?)^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*END\s*---\s*$\s*/m';
  return preg_replace($re, '', $content);
}

function do_reload(){
  @shell_exec("sudo /usr/local/bin/rcm_pjsip_reload.sh");
}

$name = sanitize_id($_GET["name"] ?? "");
if($name === ""){
  header("Location: /trunks.php");
  exit;
}

$changed = false;
foreach($FILES as $f){
  if(!file_exists($f)) continue;
  $c = file_get_contents($f);

  $new = remove_marker_block($c, $name);

  // clean extra blank lines (optional)
  $new = preg_replace("/\n{4,}/", "\n\n\n", $new);

  if($new !== $c){
    file_put_contents($f, $new);
    $changed = true;
  }
}

if($changed){
  do_reload();
}

header("Location: /trunks.php");
exit;