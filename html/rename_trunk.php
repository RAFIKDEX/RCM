<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ASTERISK_DIR = "/etc/asterisk";
$F_ENDPOINT  = "$ASTERISK_DIR/pjsip.gui.endpoint.conf";
$F_AOR       = "$ASTERISK_DIR/pjsip.gui.aor.conf";
$F_AUTH      = "$ASTERISK_DIR/pjsip.gui.auth.conf";
$F_REG       = "$ASTERISK_DIR/pjsip.gui.reg.conf";
$F_IDENTIFY  = "$ASTERISK_DIR/pjsip.gui.identify.conf";

$ALL = [$F_ENDPOINT,$F_AOR,$F_AUTH,$F_REG,$F_IDENTIFY];

function sanitize_id($s){
  $s = trim((string)$s);
  return preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
}

function ensure_file($path){
  if(!file_exists($path)) file_put_contents($path, "");
}
foreach($ALL as $f) ensure_file($f);

function trunk_exists_anywhere($name, $files){
  foreach($files as $f){
    $c = file_get_contents($f);
    if(preg_match('/^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*BEGIN\s*---/m', $c)) return true;
  }
  return false;
}

function get_marker_block($content, $name){
  $re = '/^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*BEGIN\s*---\s*$([\s\S]*?)^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*END\s*---\s*$/m';
  if(preg_match($re, $content, $m)) return $m[1];
  return "";
}

function remove_marker_block($content, $name){
  $re = '/^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*BEGIN\s*---\s*$([\s\S]*?)^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*END\s*---\s*$\s*/m';
  return preg_replace($re, '', $content);
}

function upsert_marker_block($content, $name, $block){
  $begin = "; --- RCM-TRUNK: $name BEGIN ---";
  $end   = "; --- RCM-TRUNK: $name END ---";
  $newBlock = $begin . "\n" . rtrim($block) . "\n" . $end . "\n";

  // append at end
  $content = rtrim($content) . "\n\n" . $newBlock;
  $content = preg_replace("/\n{4,}/", "\n\n\n", $content);
  return $content;
}

function do_reload(){
  @shell_exec("sudo /usr/local/bin/rcm_pjsip_reload.sh");
}

$old = sanitize_id($_GET["old"] ?? "");
if($old === ""){
  header("Location: /trunks.php");
  exit;
}

$err = "";
if($_SERVER["REQUEST_METHOD"] === "POST"){
  $new = sanitize_id($_POST["new_name"] ?? "");
  if($new === "") $err = "New name is required";
  else if($new === $old) $err = "New name must be different";
  else if(trunk_exists_anywhere($new, $ALL)) $err = "New name already exists";
  else {

    // load blocks
    $epC = file_get_contents($F_ENDPOINT);
    $aorC= file_get_contents($F_AOR);
    $auC = file_get_contents($F_AUTH);
    $rgC = file_get_contents($F_REG);
    $idC = file_get_contents($F_IDENTIFY);

    $epB = get_marker_block($epC, $old);
    $aorB= get_marker_block($aorC, $old);
    $auB = get_marker_block($auC, $old);
    $rgB = get_marker_block($rgC, $old);
    $idB = get_marker_block($idC, $old);

    // remove old marker blocks
    $epC = remove_marker_block($epC, $old);
    $aorC= remove_marker_block($aorC,$old);
    $auC = remove_marker_block($auC, $old);
    $rgC = remove_marker_block($rgC, $old);
    $idC = remove_marker_block($idC, $old);

    // rewrite references inside each block (safe replacement for section names + known keys)
    $map = [
      "[$old]" => "[$new]",
      "[$old-aor]" => "[$new-aor]",
      "[$old-auth]" => "[$new-auth]",
      "[$old-reg]" => "[$new-reg]",
      "[{$old}_identify]" => "[{$new}_identify]",

      "aors=$old" => "aors=$new",
      "aors={$old}-aor" => "aors={$new}-aor",
      "auth={$old}-auth" => "auth={$new}-auth",
      "outbound_auth={$old}-auth" => "outbound_auth={$new}-auth",
      "endpoint=$old" => "endpoint=$new",
    ];

    foreach($map as $k=>$v){
      $epB = str_replace($k,$v,$epB);
      $aorB= str_replace($k,$v,$aorB);
      $auB = str_replace($k,$v,$auB);
      $rgB = str_replace($k,$v,$rgB);
      $idB = str_replace($k,$v,$idB);
    }

    // also replace any leftover exact old tokens in very common lines
    $epB = preg_replace('/\b' . preg_quote($old,'/') . '\b/', $new, $epB);
    $aorB= preg_replace('/\b' . preg_quote($old,'/') . '\b/', $new, $aorB);
    $auB = preg_replace('/\b' . preg_quote($old,'/') . '\b/', $new, $auB);
    $rgB = preg_replace('/\b' . preg_quote($old,'/') . '\b/', $new, $rgB);
    $idB = preg_replace('/\b' . preg_quote($old,'/') . '\b/', $new, $idB);

    // write back new marker blocks under new name (only if existed)
    if(trim($epB) !== "") $epC = upsert_marker_block($epC, $new, $epB);
    if(trim($aorB)!== "") $aorC= upsert_marker_block($aorC,$new, $aorB);
    if(trim($auB) !== "") $auC = upsert_marker_block($auC, $new, $auB);
    if(trim($rgB) !== "") $rgC = upsert_marker_block($rgC, $new, $rgB);
    if(trim($idB) !== "") $idC = upsert_marker_block($idC, $new, $idB);

    file_put_contents($F_ENDPOINT, $epC);
    file_put_contents($F_AOR, $aorC);
    file_put_contents($F_AUTH, $auC);
    file_put_contents($F_REG, $rgC);
    file_put_contents($F_IDENTIFY, $idC);

    do_reload();
    header("Location: /trunks.php");
    exit;
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>RENAME TRUNK</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <style>
    .wrap{ width:min(900px,96vw); }
    label{ display:block; font-size:12px; opacity:.9; margin:2px 0 6px; }
    input{
      width:100%; padding:12px 14px; border-radius:18px;
      border:1px solid rgba(255,255,255,.18);
      background: rgba(0,0,0,.18); color:#fff;
    }
    .err{ margin:0 0 14px; padding:12px 14px; border-radius:18px;
      border:1px solid rgba(239,68,68,.35); background: rgba(239,68,68,.12);
    }
    .btnrow{ display:flex; gap:12px; margin-top:14px; }
  </style>
</head>
<body>
<div class="wrap">
  <h1 class="page-title">RENAME TRUNK</h1>
  <div class="inner-box">
    <div class="top">
      <a class="btn" href="/trunks.php">BACK</a>
    </div>
    <div class="panel-box">
      <?php if($err): ?><div class="err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <label>Old Name (locked)</label>
        <input value="<?php echo htmlspecialchars($old); ?>" readonly>

        <label style="margin-top:12px;">New Name</label>
        <input name="new_name" placeholder="NEW_NAME" required>

        <div class="btnrow">
          <button class="btn" type="submit">RENAME</button>
          <a class="btn" href="/trunks.php">CANCEL</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
