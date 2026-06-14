<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

const DP_FILE = '/etc/asterisk/extensions_gui.conf';
const MEDIA_DB_FILE = '/etc/asterisk/rcm_media_center.json';
const IVR_UPLOAD_SCRIPT = '/usr/local/bin/rcm_upload_ivr_prompt.sh';
const IVR_ADD_SCRIPT = '/usr/local/bin/rcm_add_ivr.sh';
const IVR_PROMPT_DIR = '/var/lib/asterisk/sounds/en/rcm/ivr';
const PJSIP_GUI_FILE = '/etc/asterisk/pjsip.gui.endpoint.conf';
const QUEUE_JSON_FILE = '/etc/asterisk/rcm_queues.json';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function clean($s){ return trim((string)$s); }

function load_media_prompts(){
    $db = json_decode(@file_get_contents(MEDIA_DB_FILE), true);
    if (!is_array($db) || !isset($db['prompts']) || !is_array($db['prompts'])) return [];
    $out = [];
    foreach ($db['prompts'] as $p) {
        $name = clean($p['name'] ?? '');
        $type = strtolower(clean($p['type'] ?? 'general'));
        $path = clean($p['path'] ?? '');
        if ($name === '' || $path === '' || !is_file($path)) continue;
        if (!in_array($type, ['ivr', 'general'], true)) continue;
        $out[$name] = ['id'=>clean($p['id']??''),'name'=>$name,'type'=>$type,'path'=>$path];
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function load_extensions_real(): array {
    $out = [];
    if (!file_exists(PJSIP_GUI_FILE)) return [];
    $lines = file(PJSIP_GUI_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line)
        if (preg_match('/^\[(\d{2,6})\]$/', trim($line), $m)) $out[$m[1]] = $m[1];
    natsort($out);
    return array_values($out);
}

function load_queues_real(): array {
    if (!file_exists(QUEUE_JSON_FILE)) return [];
    $data = json_decode(file_get_contents(QUEUE_JSON_FILE), true);
    if (!is_array($data) || !isset($data['queues'])) return [];
    $out = [];
    foreach ($data['queues'] as $q) {
        $num  = clean($q['queue_number'] ?? '');
        $name = clean($q['name'] ?? '');
        if ($num === '' && $name === '') continue;
        $value = $num !== '' ? $num : $name;
        $label = ($num !== '' && $name !== '') ? ($num . ' - ' . $name) : $value;
        $out[$value] = ['value'=>$value,'label'=>$label];
    }
    uasort($out, fn($a,$b) => strnatcasecmp($a['label'],$b['label']));
    return array_values($out);
}

function load_ivrs_real(): array {
    if (!file_exists(DP_FILE)) return [];
    $lines = file(DP_FILE, FILE_IGNORE_NEW_LINES) ?: [];
    $out = []; $pendingName = null;
    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/^;\s*---\s*IVR GUI:\s*([a-zA-Z0-9_-]+)\s*---/', $t, $m)){ $pendingName=$m[1]; continue; }
        if (preg_match('/^\[ivr-(\d{2,6})\]$/', $t, $m)){
            $num=$m[1]; $name=$pendingName??('ivr-'.$num);
            $out[$num]=['value'=>$num,'label'=>$num.' - '.$name]; $pendingName=null;
        }
    }
    uasort($out, fn($a,$b)=>strnatcasecmp($a['label'],$b['label']));
    return array_values($out);
}

function load_announcements_real(): array {
    if (!file_exists(DP_FILE)) return [];
    $lines = file(DP_FILE, FILE_IGNORE_NEW_LINES) ?: [];
    $out = []; $pendingName = null;
    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/^;\s*---\s*ANN GUI:\s*([a-zA-Z0-9_-]+)\s*---/', $t, $m)){ $pendingName=$m[1]; continue; }
        if (preg_match('/^\[ann-(\d{2,6})\]$/', $t, $m)){
            $num=$m[1]; $name=$pendingName??('ann-'.$num);
            $out[$num]=['value'=>$num,'label'=>$num.' - '.$name]; $pendingName=null;
        }
    }
    uasort($out, fn($a,$b)=>strnatcasecmp($a['label'],$b['label']));
    return array_values($out);
}

function load_ivr($file, $num){
    if (!file_exists($file)) return null;
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return null;
    $cur=null; $pendingName=null; $ivr=null;
    $limitToLoops = fn($l) => (((int)$l)<=1)?1:((int)$l-1);
    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/^;\s*---\s*IVR GUI:\s*([a-zA-Z0-9_-]+)\s*---/', $t, $m)){ $pendingName=$m[1]; continue; }
        if (preg_match('/^\[ivr-(\d{2,6})\]$/', $t, $m)){
            if($m[1]===$num){ $cur=$num; $ivr=['num'=>$num,'name'=>$pendingName??('ivr-'.$num),'loops'=>3,'fail_mode'=>'goto','fail_ext'=>'2222','map'=>[]]; }
            else $cur=null;
            $pendingName=null; continue;
        }
        if($cur!==$num||!$ivr) continue;
        if(preg_match('/^exten\s*=>\s*([^,]+),1,Goto\(internal,(\d{2,6}),1\)\s*$/',$t,$m)){ $ivr['map'][]=['key'=>trim($m[1]),'dest_type'=>'extension','dest_value'=>$m[2]]; continue; }
        if(preg_match('/^exten\s*=>\s*([^,]+),1,Goto\(ivr-(\d{2,6}),(\d{2,6}),1\)\s*$/',$t,$m)){ $ivr['map'][]=['key'=>trim($m[1]),'dest_type'=>'ivr','dest_value'=>$m[2]]; continue; }
        if(preg_match('/^exten\s*=>\s*([^,]+),1,Goto\(ann-(\d{2,6}),s,1\)\s*$/',$t,$m)){ $ivr['map'][]=['key'=>trim($m[1]),'dest_type'=>'announcement','dest_value'=>$m[2]]; continue; }
        if(preg_match('/^exten\s*=>\s*([^,]+),1,Goto\(ext-queues,([a-zA-Z0-9_-]+),1\)\s*$/',$t,$m)){ $ivr['map'][]=['key'=>trim($m[1]),'dest_type'=>'queue','dest_value'=>$m[2]]; continue; }
        if(preg_match('/^exten\s*=>\s*([^,]+),1,Queue\(([a-zA-Z0-9_-]+)\)\s*$/',$t,$m)){ $ivr['map'][]=['key'=>trim($m[1]),'dest_type'=>'queue','dest_value'=>$m[2]]; continue; }
        if(preg_match('/GotoIf\(\$\[\$\{test\}\s*<\s*(\d+)\]\?/',$t,$m)){ $ivr['loops']=$limitToLoops($m[1]); continue; }
        if(preg_match('/Goto\(internal,(\d{2,6}),1\)/',$t,$m)){ $ivr['fail_mode']='goto'; $ivr['fail_ext']=$m[1]; continue; }
        if(stripos($t,'Hangup()')!==false){ $ivr['fail_mode']='hangup'; continue; }
        if(preg_match('/^\[[^\]]+\]$/',$t)&&!preg_match('/^\[ivr-(\d{2,6})\]$/',$t)) $cur=null;
    }
    return $ivr;
}

function create_map_file(array $mapRows){
    $tmp = tempnam('/tmp', 'ivrmap_');
    if ($tmp === false) return false;

    $lines = '';
    foreach ($mapRows as $row) {
        $lines .= $row['key'] . ' ' . $row['dest_value'] . "\n";
    }

    if (@file_put_contents($tmp, $lines) === false) {
        @unlink($tmp);
        return false;
    }

    return $tmp;
}

function run_prompt_upload_from_path($sourcePath,$ivrNum,&$output){
    $cmd='sudo '.escapeshellarg(IVR_UPLOAD_SCRIPT).' '.escapeshellarg($sourcePath).' '.escapeshellarg($ivrNum).' '.escapeshellarg('welcome').' 2>&1';
    $output=shell_exec($cmd)??'';
    return strpos($output,'OK:')===0;
}

$n = clean($_GET['n'] ?? '');
if (!preg_match('/^\d{2,6}$/', $n)) { header('Location: /ivr.php'); exit; }
$ivr = load_ivr(DP_FILE, $n);
if (!$ivr) { header('Location: /ivr.php'); exit; }

$mediaPrompts        = load_media_prompts();
$currentPromptPath   = IVR_PROMPT_DIR . '/ivr_' . $n . '_welcome.wav';
$currentPromptExists = is_file($currentPromptPath);

$destinationOptions = [
    'extension'    => array_map(fn($x)=>['value'=>$x,'label'=>$x], load_extensions_real()),
    'queue'        => load_queues_real(),
    'ivr'          => load_ivrs_real(),
    'announcement' => load_announcements_real(),
];

$values = [
    'ivr_name'        => $ivr['name'],
    'loop_count'      => (string)$ivr['loops'],
    'fail_mode'       => $ivr['fail_mode'],
    'fail_ext'        => $ivr['fail_mode']==='goto' ? $ivr['fail_ext'] : '2222',
    'prompt_mode'     => 'keep',
    'existing_prompt' => '',
];
$mapValues = $ivr['map'];
$errors=[]; $out='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach ($values as $k=>$v) if(isset($_POST[$k])) $values[$k]=clean($_POST[$k]);

    if(!preg_match('/^[a-zA-Z0-9_-]{2,30}$/',$values['ivr_name'])) $errors[]='Invalid IVR name';
    if(!preg_match('/^[1-9]$/',$values['loop_count']))              $errors[]='Loop count must be 1..9';
    if(!in_array($values['fail_mode'],['goto','hangup'],true))      $errors[]='Invalid fail mode';
    if($values['fail_mode']==='goto'&&!preg_match('/^\d{2,6}$/',$values['fail_ext'])) $errors[]='Invalid fail extension';
    if(!in_array($values['prompt_mode'],['keep','media','upload'],true)) $errors[]='Invalid prompt mode';

    $keys=$_POST['key']??[]; $destTypes=$_POST['dest_type']??[];
    $destValues=$_POST['dest_value']??[]; $destCustomValues=$_POST['dest_value_custom']??[];
    $mapRows=[]; $mapValues=[];
    $rowCount=max(count($keys),count($destTypes),count($destValues),count($destCustomValues));
    $validDestinationValues=[];
    foreach($destinationOptions as $type=>$items)
        $validDestinationValues[$type]=array_map(fn($x)=>(string)$x['value'],$items);

    for($i=0;$i<$rowCount;$i++){
        $k=clean($keys[$i]??''); $type=clean($destTypes[$i]??'');
        $val=clean($destValues[$i]??''); $cust=clean($destCustomValues[$i]??'');
        $finalValue=($type==='custom_number')?$cust:$val;
        if($k===''&&$type===''&&$finalValue==='') continue;
        $mapValues[]=['key'=>$k,'dest_type'=>$type,'dest_value'=>$finalValue];
        $isSimple=preg_match('/^[1-9]$|^\*$|^#$/',$k);
        $isPattern=preg_match('/^_[0-9XZN\.\!\#\*]+$/',$k);
        if(!$isSimple&&!$isPattern){ $errors[]='Invalid key: '.$k; continue; }
        if(!in_array($type,['extension','announcement','ivr','queue','custom_number'],true)){ $errors[]='Invalid destination type for key '.$k; continue; }
        if($type==='custom_number'){
            if(!preg_match('/^\d{2,20}$/',$finalValue)){ $errors[]='Invalid custom number for key '.$k; continue; }
        } else {
            if(!in_array((string)$finalValue,$validDestinationValues[$type]??[],true)){ $errors[]='Invalid destination value for key '.$k; continue; }
        }
        $mapRows[]=['key'=>$k,'dest_type'=>$type,'dest_value'=>$finalValue];
    }
    if(!$mapRows) $errors[]='Add at least one mapping row';

    $selectedPromptPath='';
    if($values['prompt_mode']==='media'){
        if($values['existing_prompt']==='') $errors[]='Please choose a prompt from Media Center';
        elseif(!isset($mediaPrompts[$values['existing_prompt']])) $errors[]='Selected prompt was not found in Media Center';
        else $selectedPromptPath=$mediaPrompts[$values['existing_prompt']]['path'];
    } elseif($values['prompt_mode']==='upload'){
        if(!isset($_FILES['prompt'])||($_FILES['prompt']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
            $errors[]='Please upload a prompt file';
    }

    if(!$errors&&$values['prompt_mode']!=='keep'){
        if($values['prompt_mode']==='upload'){
            $tmpUpload='/tmp/ivr_'.$n.'_'.uniqid('',true).'_'
                .preg_replace('/[^a-zA-Z0-9._-]/','_',basename($_FILES['prompt']['name']));
            if(!move_uploaded_file($_FILES['prompt']['tmp_name'],$tmpUpload))
                $errors[]='Cannot move uploaded prompt';
            elseif(!run_prompt_upload_from_path($tmpUpload,$n,$promptOut))
                $errors[]=$promptOut!==''?$promptOut:'Prompt upload failed';
            @unlink($tmpUpload);
        } else {
            if(!run_prompt_upload_from_path($selectedPromptPath,$n,$promptOut))
                $errors[]=$promptOut!==''?$promptOut:'Prompt copy from Media Center failed';
        }
    }

    if(!$errors){
        $mapFile=create_map_file($mapRows);
        if($mapFile===false){ $errors[]='Cannot create temporary mapping file'; }
        else {
            $cmd='sudo '.escapeshellarg(IVR_ADD_SCRIPT)
                .' '.escapeshellarg($n)
                .' '.escapeshellarg($values['ivr_name'])
                .' '.escapeshellarg($values['loop_count'])
                .' '.escapeshellarg($values['fail_mode'])
                .' '.escapeshellarg($values['fail_mode']==='goto'?$values['fail_ext']:'2222')
                .' '.escapeshellarg($mapFile).' 2>&1';
            $out=shell_exec($cmd)??''; @unlink($mapFile);
            if(strpos($out,'OK:')===0){ header('Location: /ivr.php'); exit; }
            $errors[]=$out!==''?$out:'Dialplan write failed';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit IVR</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <style>
    html, body { height: auto !important; }
    body { display: block !important; align-items: initial !important; justify-content: initial !important; }
    .wrap { width: min(980px, 96vw); margin: 0 auto 60px auto; }
    .top { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }

    .panel-box {
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 18px;
      padding: 22px;
      margin-top: 18px;
    }
    .section-divider {
      border: none;
      border-top: 1px solid rgba(255,255,255,0.10);
      margin: 20px 0;
    }
    .section-label {
      font-family: 'Orbitron', sans-serif;
      font-size: 13px; letter-spacing: 1px;
      text-transform: uppercase; opacity: .85; margin-bottom: 12px;
    }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 6px; }
    @media (max-width: 760px) { .grid-2 { grid-template-columns: 1fr; } }
    .actions-inline { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
    .btnrow { display: flex; gap: 12px; margin-top: 22px; flex-wrap: wrap; align-items: center; }
    .small { opacity: .9; font-size: 12px; margin-top: 6px; }
    ul { margin: 10px 0 0 18px; }
    .hidden { display: none !important; }

    .panel-box label {
      display: block; font-size: 13px; font-family: 'Orbitron', sans-serif;
      letter-spacing: 1px; text-transform: uppercase; opacity: 0.85; margin-bottom: 8px;
    }
    .panel-box input[type="text"],
    .panel-box input[type="number"],
    .panel-box input:not([type]),
    .panel-box input[type="file"],
    .panel-box select {
      width: 100%; padding: 12px 14px; border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.22);
      background: rgba(255,255,255,0.08);
      color: #fff; font-size: 15px; outline: none; box-sizing: border-box;
      font-family: Arial, sans-serif; transition: border-color .2s, background .2s;
    }
    .panel-box input[type="file"] { padding: 8px 14px; }
    .panel-box input:focus, .panel-box select:focus {
      border-color: rgba(255,255,255,0.55);
      background: rgba(255,255,255,0.13);
    }
    .panel-box select option { background: #1e3a5f; color: #fff; }
    .panel-box input::placeholder { color: rgba(255,255,255,0.40); font-size: 13px; }

    .map-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .map-table th, .map-table td {
      border: 2px solid rgba(255,255,255,0.45);
      padding: 10px 12px; text-align: center; vertical-align: middle;
    }
    .map-table th {
      background: rgba(255,255,255,0.10);
      font-family: 'Orbitron', sans-serif;
      font-size: 13px; letter-spacing: 1px; text-transform: uppercase;
    }
    .map-table td input, .map-table td select {
      width: 100%; padding: 9px 12px; border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.22);
      background: rgba(255,255,255,0.08);
      color: #fff; font-size: 14px; outline: none;
      box-sizing: border-box; font-family: Arial, sans-serif;
    }
    .map-table td select option { background: #1e3a5f; color: #fff; }
    .map-table td input::placeholder { color: rgba(255,255,255,0.40); }
    .map-table tbody tr:hover { background: rgba(255,255,255,0.04); }
    .map-table .type-col { width: 200px; }

    .remove-btn {
      width: 36px; height: 36px; border-radius: 10px;
      border: 2px solid rgba(239,68,68,0.6);
      background: rgba(239,68,68,0.12);
      color: #fca5a5; font-size: 18px; cursor: pointer; transition: .2s; line-height: 1;
    }
    .remove-btn:hover {
      background: rgba(239,68,68,0.35);
      border-color: rgba(239,68,68,1); color: #fff;
    }
  </style>

  <script>
    const destinationOptions = <?php echo json_encode($destinationOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    function escapeHtml(v){
      return String(v)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function buildOptions(type, selectedValue){
      const items = destinationOptions[type] || [];
      let html = '<option value="">-- Select --</option>';
      items.forEach(item => {
        const sel = String(item.value)===String(selectedValue) ? ' selected' : '';
        html += `<option value="${escapeHtml(item.value)}"${sel}>${escapeHtml(item.label)}</option>`;
      });
      return html;
    }

    function syncDestinationRow(row){
      const typeEl     = row.querySelector('.dest-type');
      const selectWrap = row.querySelector('.dest-select-wrap');
      const inputWrap  = row.querySelector('.dest-input-wrap');
      const selectEl   = row.querySelector('.dest-select');
      const inputEl    = row.querySelector('.dest-input');
      const type       = typeEl.value;

      if (type === 'custom_number'){
        selectWrap.style.display = 'none';
        inputWrap.style.display  = 'block';
        selectEl.disabled = true;
        inputEl.disabled  = false;
        return;
      }
      inputWrap.style.display  = 'none';
      selectWrap.style.display = 'block';
      inputEl.disabled  = true;
      selectEl.disabled = false;
      selectEl.innerHTML = buildOptions(type, selectEl.dataset.selected || selectEl.value || '');
    }

    function addRow(keyVal, destType, destValue){
      keyVal    = keyVal    || '';
      destType  = destType  || 'extension';
      destValue = destValue || '';

      const tb = document.getElementById('mapBody');
      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td><input name="key[]" value="' + escapeHtml(keyVal) + '" placeholder="1  *  #  _X." style="width:90px;"></td>'
        + '<td class="type-col">'
        +   '<select name="dest_type[]" class="dest-type" onchange="syncDestinationRow(this.closest(\'tr\'))">'
        +     '<option value="extension"'    + (destType==='extension'    ?' selected':'') + '>Extension</option>'
        +     '<option value="announcement"' + (destType==='announcement' ?' selected':'') + '>Announcement</option>'
        +     '<option value="ivr"'          + (destType==='ivr'          ?' selected':'') + '>IVR</option>'
        +     '<option value="queue"'        + (destType==='queue'        ?' selected':'') + '>Queue</option>'
        +     '<option value="custom_number"'+ (destType==='custom_number'?' selected':'') + '>Custom Number</option>'
        +   '</select>'
        + '</td>'
        + '<td>'
        +   '<div class="dest-select-wrap">'
        +     '<select name="dest_value[]" class="dest-select" data-selected="' + escapeHtml(destValue) + '"></select>'
        +   '</div>'
        +   '<div class="dest-input-wrap" style="display:none;">'
        +     '<input name="dest_value_custom[]" class="dest-input" value="' + escapeHtml(destValue) + '" placeholder="Custom number">'
        +   '</div>'
        + '</td>'
        + '<td><button type="button" class="remove-btn" onclick="this.closest(\'tr\').remove()"></button></td>';
      tb.appendChild(tr);
      syncDestinationRow(tr);
    }

    function syncPromptMode(){
      const mode = document.querySelector('input[name="prompt_mode"]:checked')?.value || 'keep';
      document.getElementById('promptMediaBox').classList.toggle('hidden', mode !== 'media');
      document.getElementById('promptUploadBox').classList.toggle('hidden', mode !== 'upload');
    }

    window.addEventListener('DOMContentLoaded', function(){ syncPromptMode(); });
  </script>
</head>
<body>
<div class="wrap">
  <h1 class="page-title">EDIT IVR</h1>
  <div class="inner-box">

    <div class="top">
      <span class="pill">IVR: <?php echo h($n); ?></span>
      <a class="btn" href="/ivr.php">BACK</a>
    </div>

    <?php if ($errors): ?>
      <div class="panel-box" style="margin-top:12px;">
        <div class="pill">FAILED</div>
        <ul><?php foreach($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <div class="panel-box">
      <form method="post" enctype="multipart/form-data" autocomplete="off">

        <!-- IVR Name + Loop Count -->
        <div class="grid-2">
          <div>
            <label>IVR Name</label>
            <input name="ivr_name" required
                   value="<?php echo h($values['ivr_name']); ?>"
                   placeholder="e.g. main-menu">
          </div>
          <div>
            <label>Loop Count</label>
            <select name="loop_count">
              <?php for($i=1;$i<=9;$i++): ?>
                <option value="<?php echo $i; ?>"
                  <?php echo (string)$i===$values['loop_count']?'selected':''; ?>>
                  <?php echo $i; ?>
                </option>
              <?php endfor; ?>
            </select>
            <div class="small">Retry count before fail action.</div>
          </div>
        </div>

        <hr class="section-divider">

        <!-- Voice Prompt -->
        <label>Voice Prompt</label>
        <div class="actions-inline">
          <label>
            <input type="radio" name="prompt_mode" value="keep"
              <?php echo $values['prompt_mode']==='keep'?'checked':''; ?>
              onclick="syncPromptMode()"> Keep Current
          </label>
          <label>
            <input type="radio" name="prompt_mode" value="media"
              <?php echo $values['prompt_mode']==='media'?'checked':''; ?>
              onclick="syncPromptMode()"> Use Media Center
          </label>
          <label>
            <input type="radio" name="prompt_mode" value="upload"
              <?php echo $values['prompt_mode']==='upload'?'checked':''; ?>
              onclick="syncPromptMode()"> Upload New
          </label>
        </div>
        <div class="small">
          Current file:
          <strong><?php echo $currentPromptExists ? h(basename($currentPromptPath)) : 'not found'; ?></strong>
        </div>

        <div id="promptMediaBox" class="hidden" style="margin-top:12px;">
          <label>Select Prompt</label>
          <select name="existing_prompt">
            <option value="">-- Select IVR Prompt --</option>
            <?php foreach($mediaPrompts as $prompt): ?>
              <option value="<?php echo h($prompt['name']); ?>"
                <?php echo $values['existing_prompt']===$prompt['name']?'selected':''; ?>>
                <?php echo h($prompt['name']); ?> (<?php echo h(strtoupper($prompt['type'])); ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="promptUploadBox" class="hidden" style="margin-top:12px;">
          <label>Upload Prompt File</label>
          <input type="file" name="prompt" accept=".wav,.mp3,.gsm,.ogg,.ulaw,.alaw">
        </div>

        <hr class="section-divider">

        <!-- Fail Mode -->
        <div class="grid-2">
          <div>
            <label>On Invalid / Timeout After Loops</label>
            <select name="fail_mode"
              onchange="document.getElementById('failExtBox').style.display=(this.value==='goto'?'block':'none')">
              <option value="goto"
                <?php echo $values['fail_mode']==='goto'?'selected':''; ?>>Goto Extension</option>
              <option value="hangup"
                <?php echo $values['fail_mode']==='hangup'?'selected':''; ?>>Hangup</option>
            </select>
          </div>
          <div id="failExtBox"
               style="<?php echo $values['fail_mode']==='goto'?'':'display:none;'; ?>">
            <label>Fail Extension</label>
            <input name="fail_ext" placeholder="2222"
                   value="<?php echo h($values['fail_ext']); ?>">
          </div>
        </div>

        <hr class="section-divider">

        <!-- Key Map -->
        <div class="section-label">Key Map</div>
        <table class="map-table">
          <thead>
            <tr>
              <th>Key</th>
              <th class="type-col">Type</th>
              <th>Value</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="mapBody"></tbody>
        </table>

        <div class="actions-inline">
          <button type="button" class="btn" onclick="addRow()">+ ADD ROW</button>
          <button type="button" class="btn" onclick="addRow('_X.','extension','')">ANY DIGIT</button>
        </div>

        <hr class="section-divider">

        <div class="btnrow">
          <a class="btn" href="/ivr.php">CANCEL</a>
          <button class="btn" type="submit">SAVE</button>
        </div>

      </form>
    </div><!-- /panel-box -->
  </div><!-- /inner-box -->
</div><!-- /wrap -->

<script>
  const existingRows = <?php echo json_encode($mapValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  if (Array.isArray(existingRows) && existingRows.length){
    existingRows.forEach(r => addRow(r.key||'', r.dest_type||'extension', r.dest_value||''));
  } else {
    addRow('1','extension','');
  }
</script>
</body>
</html>