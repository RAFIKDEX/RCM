<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function fail($m){
  echo "<!doctype html><html><head><meta charset='utf-8'><link href='https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap' rel='stylesheet'><link rel='stylesheet' href='assets/rcm.css'><title>Bulk Result</title></head><body><div class='wrap'><h1 class='page-title'>FAILED</h1><div class='pill' style='text-align:center'>".h($m)."</div><div style='margin-top:16px;text-align:center;'><a class='btn' href='bulk_add_extensions.php'>BACK</a></div></div></body></html>";
  exit;
}

$start = intval($_POST["start_ext"] ?? 0);
$count = intval($_POST["count"] ?? 0);
$step  = intval($_POST["step"] ?? 1);

$context = trim($_POST["context"] ?? "internal");
$maxc    = intval($_POST["max_contacts"] ?? 10);
$allow   = trim($_POST["allow"] ?? "alaw,ulaw");
$rec     = trim($_POST["record_mode"] ?? "noo");
$vm      = trim($_POST["vm_mode"] ?? "Off");

$passMode= trim($_POST["pass_mode"] ?? "unified");
$passVal = trim($_POST["pass_value"] ?? "");
$skip    = trim($_POST["skip_existing"] ?? "yes");

$cidNameMode  = trim($_POST['callerid_name_mode'] ?? 'prefix_ext');
$cidNameValue = trim($_POST['callerid_name_value'] ?? 'Extension');
$cidNumMode   = trim($_POST['callerid_number_mode'] ?? 'same_ext');
$cidNumValue  = trim($_POST['callerid_number_value'] ?? '');

if ($start < 10 || $start > 999999) fail("Invalid start");
if ($count < 1 || $count > 2000) fail("Invalid count");
if ($step < 1 || $step > 1000) fail("Invalid step");
if ($context !== "internal") fail("Context must be internal");
if ($maxc < 1 || $maxc > 50) fail("Invalid max_contacts");
if (!preg_match('/^[a-zA-Z0-9,]+$/', $allow)) fail("Invalid allow codecs");
if (!in_array($rec, ["in","out","noo","all"], true)) fail("Invalid record_mode");
if (!in_array($vm, ["On","Off"], true)) fail("Invalid vm_mode");
if ($passVal === "") fail("Password/prefix empty");
if (!in_array($passMode, ["unified","prefix_ext"], true)) fail("Invalid pass_mode");
if (!in_array($skip, ["yes","no"], true)) fail("Invalid skip_existing");
if (!in_array($cidNameMode, ['prefix_ext', 'fixed'], true)) fail('Invalid callerid_name_mode');
if (!in_array($cidNumMode, ['same_ext', 'prefix_ext', 'fixed'], true)) fail('Invalid callerid_number_mode');
if ($cidNameValue === '') fail('Caller ID name value cannot be empty');
if (($cidNumMode === 'prefix_ext' || $cidNumMode === 'fixed') && $cidNumValue === '') fail('Caller ID number value cannot be empty for selected mode');

$EP_FILE="/etc/asterisk/pjsip.gui.endpoint.conf";

function exists_in_gui($ext, $epFile){
  if (!is_readable($epFile)) return false;
  $data = file_get_contents($epFile);
  if ($data === false) return false;
  if (strpos($data, "[".$ext."]\n") === 0) return true;
  return (strpos($data, "\n[".$ext."]\n") !== false);
}

function build_bulk_callerid_name(string $mode, string $value, string $ext): string {
  if ($mode === 'fixed') return trim($value);
  return trim($value . ' ' . $ext);
}

function build_bulk_callerid_number(string $mode, string $value, string $ext): string {
  if ($mode === 'same_ext') return $ext;
  if ($mode === 'fixed') return trim($value);
  return trim($value . $ext);
}

$created = [];
$skipped = [];
$failed  = [];

for ($i=0; $i<$count; $i++){
  $extInt = $start + ($i*$step);
  if ($extInt > 999999){ $failed[] = ["ext"=>(string)$extInt, "err"=>"Out of range"]; break; }
  $ext = (string)$extInt;

  if ($skip === "yes" && exists_in_gui($ext, $EP_FILE)){
    $skipped[] = $ext;
    continue;
  }

  $secret = ($passMode === "unified") ? $passVal : ($passVal . $ext);
  $calleridName = build_bulk_callerid_name($cidNameMode, $cidNameValue, $ext);
  $calleridNumber = build_bulk_callerid_number($cidNumMode, $cidNumValue, $ext);

  if ($calleridName === '') {
    $failed[] = ['ext' => $ext, 'err' => 'Caller ID name generated empty'];
    if ($skip === 'no') break;
    continue;
  }

  if (!preg_match('/^[0-9+*#]{2,20}$/', $calleridNumber)) {
    $failed[] = ['ext' => $ext, 'err' => 'Invalid generated Caller ID number: ' . $calleridNumber];
    if ($skip === 'no') break;
    continue;
  }

  $cmd = "sudo /usr/local/bin/rcm_add_ext_bulk.sh "
       . escapeshellarg($ext) . " "
       . escapeshellarg($secret) . " "
       . escapeshellarg($context) . " "
       . escapeshellarg((string)$maxc) . " "
       . escapeshellarg($allow) . " "
       . escapeshellarg($rec) . " "
       . escapeshellarg($vm) . " "
       . escapeshellarg($calleridName) . " "
       . escapeshellarg($calleridNumber)
       . " 2>&1";

  $out = shell_exec($cmd) ?? "";

  if (strpos($out, "OK:") === 0){
    $created[] = $ext . ' => ' . $calleridName . ' <' . $calleridNumber . '>';
  } else {
    $failed[] = ["ext"=>$ext, "err"=>$out];
    if ($skip === "no") break;
  }
}

$reload1 = shell_exec("sudo /usr/sbin/asterisk -rx " . escapeshellarg("pjsip reload") . " 2>&1") ?? "";
$reload2 = shell_exec("sudo /usr/sbin/asterisk -rx " . escapeshellarg("dialplan reload") . " 2>&1") ?? "";
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Bulk Result</title>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/rcm.css">
</head>
<body>
  <div class="wrap">
    <h1 class="page-title">RESULT</h1>

    <table>
      <thead>
        <tr>
          <th>Created</th>
          <th>Skipped</th>
          <th>Failed</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><span class="pill"><?=count($created)?></span></td>
          <td><span class="pill"><?=count($skipped)?></span></td>
          <td><span class="pill"><?=count($failed)?></span></td>
        </tr>
      </tbody>
    </table>

    <h3 style="font-family:'Orbitron',sans-serif;letter-spacing:2px;margin-top:18px;">Created List</h3>
    <div class="pill" style="width:100%;box-sizing:border-box;overflow-wrap:anywhere;white-space:pre-wrap;"><?=h(implode("\n", $created))?></div>

    <h3 style="font-family:'Orbitron',sans-serif;letter-spacing:2px;margin-top:18px;">Skipped List</h3>
    <div class="pill" style="width:100%;box-sizing:border-box;overflow-wrap:anywhere;"><?=h(implode(", ", $skipped))?></div>

    <h3 style="font-family:'Orbitron',sans-serif;letter-spacing:2px;margin-top:18px;">Failed Details</h3>
    <div class="pill" style="width:100%;box-sizing:border-box;white-space:pre-wrap;">
<?php
if (!$failed) {
  echo "None";
} else {
  foreach($failed as $f){
    echo "EXT ".$f["ext"]." => ".$f["err"]."\n";
  }
}
?>
    </div>

    <h3 style="font-family:'Orbitron',sans-serif;letter-spacing:2px;margin-top:18px;">Reload Output</h3>
    <div class="pill" style="width:100%;box-sizing:border-box;white-space:pre-wrap;"><?=h($reload1."\n".$reload2)?></div>

    <div style="margin-top:18px; display:flex; gap:12px; flex-wrap:wrap;">
      <a class="btn" href="bulk_add_extensions.php">BACK</a>
      <a class="btn" href="extensions.php">EXTENSIONS</a>
    </div>
  </div>
</body>
</html>