<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

const MEDIA_DB_FILE    = '/etc/asterisk/rcm_media_center.json';
const IVR_UPLOAD_SCRIPT = '/usr/local/bin/rcm_upload_ivr_prompt.sh';
const IVR_ADD_SCRIPT   = '/usr/local/bin/rcm_add_ivr.sh';

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
        if ($name==='' || $path==='' || !is_file($path)) continue;
        if (!in_array($type, ['ivr','general'], true)) continue;
        $out[$name] = ['id'=>clean($p['id']??''),'name'=>$name,'type'=>$type,'path'=>$path];
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function load_extensions(){
    $file='/etc/asterisk/pjsip.gui.endpoint.conf'; $out=[];
    if(file_exists($file)) foreach(file($file) as $l)
        if(preg_match('/^\[(\d{2,6})\]/',$l,$m)) $out[]=$m[1];
    sort($out); return $out;
}

function load_queues(){
    $f='/etc/asterisk/rcm_queues.json'; $out=[];
    if(file_exists($f)){
        $j=json_decode(file_get_contents($f),true);
        if(isset($j['queues'])) foreach($j['queues'] as $q) $out[]=$q['name'];
    }
    sort($out); return $out;
}

function load_ivrs(){
    $file='/etc/asterisk/extensions_gui.conf'; $out=[];
    if(file_exists($file)) foreach(file($file) as $l)
        if(preg_match('/\[ivr-(\d+)\]/',$l,$m)) $out[]=$m[1];
    sort($out); return $out;
}

function load_announcements(){
    $file = '/etc/asterisk/extensions_gui.conf';
    $out = [];
    $pendingName = null;

    if (!file_exists($file)) return [];

    $lines = file($file, FILE_IGNORE_NEW_LINES);

    foreach ($lines as $line) {
        $t = trim($line);

        // ??? ?????????? ?? ???????
        if (preg_match('/^;\s*---\s*ANN GUI:\s*([a-zA-Z0-9_-]+)\s*---/', $t, $m)) {
            $pendingName = $m[1];
            continue;
        }

        // ??? ??????????
        if (preg_match('/^\[ann-(\d{2,6})\]$/', $t, $m)) {
            $num = $m[1];
            $name = $pendingName ?? ('ann-' . $num);

            $out[$num] = $num . ' - ' . $name;
            $pendingName = null;
        }
    }

    // ????? ??????
    natcasesort($out);

    // ???? values ?? (???? JS ???? ????? array ????)
    return array_keys($out);
}

function create_map_file(array $mapRows){
    $tmp=tempnam('/tmp','ivrmap_'); if($tmp===false) return false;
    $lines=''; foreach($mapRows as $row) $lines.=$row['key'].' '.$row['dest']."\n";
    if(@file_put_contents($tmp,$lines)===false){ @unlink($tmp); return false; }
    return $tmp;
}

function run_prompt_upload_from_path($sourcePath,$ivrNum,&$output){
    $cmd='sudo '.escapeshellarg(IVR_UPLOAD_SCRIPT).' '
        .escapeshellarg($sourcePath).' '
        .escapeshellarg($ivrNum).' '
        .escapeshellarg('welcome').' 2>&1';
    $output=shell_exec($cmd)??'';
    return strpos($output,'OK:')===0;
}

$mediaPrompts = load_media_prompts();
$exts         = load_extensions();
$queues       = load_queues();
$ivrs         = load_ivrs();
$anns         = load_announcements();

$values = [
    'ivr_num'         => '',
    'ivr_name'        => '',
    'loop_count'      => '3',
    'fail_mode'       => 'goto',
    'fail_ext'        => '2222',
    'prompt_source'   => 'media',
    'existing_prompt' => '',
];
$mapValues = [
    ['key'=>'1','dest_type'=>'extension','dest'=>''],
    ['key'=>'2','dest_type'=>'extension','dest'=>''],
];
$errors=[]; $ok=false; $out='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach($values as $k=>$v) if(isset($_POST[$k])) $values[$k]=clean($_POST[$k]);

    if(!preg_match('/^\d{2,6}$/',$values['ivr_num']))               $errors[]='Invalid IVR number (2–6 digits)';
    if(!preg_match('/^[a-zA-Z0-9_-]{2,30}$/',$values['ivr_name'])) $errors[]='Invalid IVR name (2–30 chars, a-z 0-9 _ -)';
    if(!preg_match('/^[1-9]$/',$values['loop_count']))              $errors[]='Loop count must be 1–9';
    if(!in_array($values['fail_mode'],['goto','hangup'],true))      $errors[]='Invalid fail mode';
    if($values['fail_mode']==='goto' && !preg_match('/^\d{2,6}$/',$values['fail_ext'])) $errors[]='Invalid fail extension';
    if(!in_array($values['prompt_source'],['media','upload'],true)) $errors[]='Invalid prompt source';

    $keys=$_POST['key']??[]; $destTypes=$_POST['dest_type']??[]; $destVals=$_POST['dest_value']??[];
    $mapValues=[]; $mapRows=[]; $rowCount=max(count($keys),count($destVals));
    for($i=0;$i<$rowCount;$i++){
        $k=clean($keys[$i]??''); $t=clean($destTypes[$i]??''); $v=clean($destVals[$i]??'');
        if($k===''&&$v==='') continue;
        $mapValues[]=['key'=>$k,'dest_type'=>$t,'dest'=>$v];
        $isSimple  = preg_match('/^[1-9]$|^\*$|^#$/',$k);
        $isPattern = preg_match('/^_[0-9XZN\.\!\#\*]+$/',$k);
        if(!$isSimple&&!$isPattern){ $errors[]='Invalid key: '.h($k); continue; }
        if(!preg_match('/^\d{2,6}$/',$v)){ $errors[]='Invalid destination for key '.h($k); continue; }
        $mapRows[]=['key'=>$k,'dest'=>$v];
    }
    if(!$mapRows) $errors[]='Add at least one valid mapping row';

    $selectedPromptPath='';
    if($values['prompt_source']==='media'){
        if($values['existing_prompt']==='')                          $errors[]='Please choose a prompt from Media Center';
        elseif(!isset($mediaPrompts[$values['existing_prompt']]))    $errors[]='Selected prompt not found in Media Center';
        else $selectedPromptPath=$mediaPrompts[$values['existing_prompt']]['path'];
    } else {
        if(!isset($_FILES['prompt'])||($_FILES['prompt']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
            $errors[]='Please upload a prompt file';
    }

    if(!$errors){
        if($values['prompt_source']==='upload'){
            $tmpUpload='/tmp/ivr_'.$values['ivr_num'].'_'.uniqid('',true).'_'
                      .preg_replace('/[^a-zA-Z0-9._-]/','_',basename($_FILES['prompt']['name']));
            if(!move_uploaded_file($_FILES['prompt']['tmp_name'],$tmpUpload))
                $errors[]='Cannot move uploaded prompt';
            elseif(!run_prompt_upload_from_path($tmpUpload,$values['ivr_num'],$promptOut))
                $errors[]=$promptOut!==''?$promptOut:'Prompt upload failed';
            @unlink($tmpUpload);
        } else {
            if(!run_prompt_upload_from_path($selectedPromptPath,$values['ivr_num'],$promptOut))
                $errors[]=$promptOut!==''?$promptOut:'Prompt copy from Media Center failed';
        }
    }

    if(!$errors){
        $mapFile=create_map_file($mapRows);
        if($mapFile===false){ $errors[]='Cannot create mapping file'; }
        else {
            $cmd='sudo '.escapeshellarg(IVR_ADD_SCRIPT).' '
                .escapeshellarg($values['ivr_num']).' '
                .escapeshellarg($values['ivr_name']).' '
                .escapeshellarg($values['loop_count']).' '
                .escapeshellarg($values['fail_mode']).' '
                .escapeshellarg($values['fail_mode']==='goto'?$values['fail_ext']:'2222').' '
                .escapeshellarg($mapFile).' 2>&1';
            $out=shell_exec($cmd)??''; @unlink($mapFile);
            if(strpos($out,'OK:')===0) $ok=true;
            else $errors[]=$out!==''?$out:'Unknown error while creating IVR';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create IVR</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <style>
    html,body{height:auto!important;}
    body{display:block!important;align-items:initial!important;justify-content:initial!important;}
    .wrap{width:min(1100px,96vw);margin:0 auto 60px;}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    @media(max-width:760px){.row{grid-template-columns:1fr;}}
    .actions-inline{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
    .small{opacity:.9;font-size:12px;margin-top:6px;}
    .hidden{display:none!important;}
    ul{margin:10px 0 0 18px;}
    .top{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:6px;}

    .panel-box{background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.10);border-radius:18px;padding:22px;margin-top:18px;}
    .section-divider{border:none;border-top:1px solid rgba(255,255,255,0.10);margin:20px 0;}
    .section-label{font-family:'Orbitron',sans-serif;font-size:13px;letter-spacing:1px;text-transform:uppercase;opacity:.85;margin-bottom:12px;}

    .panel-box label{display:block;font-size:13px;font-family:'Orbitron',sans-serif;letter-spacing:1px;text-transform:uppercase;opacity:0.85;margin-bottom:8px;}
    .panel-box input,.panel-box select{width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,0.22);background:rgba(255,255,255,0.08);color:#fff;font-size:15px;outline:none;box-sizing:border-box;transition:border-color .2s,background .2s;}
    .panel-box input[type="file"]{padding:8px;}
    .panel-box input:focus,.panel-box select:focus{border-color:rgba(255,255,255,0.55);background:rgba(255,255,255,0.13);}
    .panel-box select option{background:#1e3a5f;color:#fff;}
    .panel-box input::placeholder{color:rgba(255,255,255,0.40);font-size:13px;}

    .map-table{width:100%;border-collapse:collapse;margin-top:4px;}
    .map-table th,.map-table td{border:2px solid rgba(255,255,255,0.45);padding:10px 12px;text-align:center;vertical-align:middle;}
    .map-table th{background:rgba(255,255,255,0.10);font-family:'Orbitron',sans-serif;font-size:13px;letter-spacing:1px;text-transform:uppercase;}
    .map-table td input,.map-table td select{width:100%;padding:9px 12px;border-radius:10px;border:1px solid rgba(255,255,255,0.22);background:rgba(255,255,255,0.08);color:#fff;font-size:14px;outline:none;box-sizing:border-box;}
    .map-table td select option{background:#1e3a5f;color:#fff;}
    .map-table td input::placeholder{color:rgba(255,255,255,0.40);}
    .map-table tbody tr:hover{background:rgba(255,255,255,0.04);}

    .remove-btn{width:36px;height:36px;border-radius:10px;border:2px solid rgba(239,68,68,0.6);background:rgba(239,68,68,0.12);color:#fca5a5;font-size:18px;cursor:pointer;transition:.2s;line-height:1;}
    .remove-btn:hover{background:rgba(239,68,68,0.35);border-color:rgba(239,68,68,1);color:#fff;}
  </style>

  <script>
    const EXT = <?php echo json_encode($exts,   JSON_UNESCAPED_SLASHES); ?>;
    const QUE = <?php echo json_encode($queues, JSON_UNESCAPED_SLASHES); ?>;
    const IVR = <?php echo json_encode($ivrs,   JSON_UNESCAPED_SLASHES); ?>;
    const ANN = <?php echo json_encode($anns,   JSON_UNESCAPED_SLASHES); ?>;

    function escapeHtml(v){
      return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    function buildSelect(arr, selected){
      let h='<select name="dest_value[]">';
      arr.forEach(v=>{ h+=`<option value="${escapeHtml(v)}"${v==selected?' selected':''}>${escapeHtml(v)}</option>`; });
      return h+'</select>';
    }
    function changeDest(sel, selectedVal){
      const cell=sel.closest('tr').querySelector('.dest-cell'), t=sel.value;
      if     (t==='extension')    cell.innerHTML=buildSelect(EXT,selectedVal);
      else if(t==='queue')        cell.innerHTML=buildSelect(QUE,selectedVal);
      else if(t==='ivr')          cell.innerHTML=buildSelect(IVR,selectedVal);
      else if(t==='announcement') cell.innerHTML=buildSelect(ANN,selectedVal);
      else cell.innerHTML=`<input name="dest_value[]" placeholder="extension number" value="${escapeHtml(selectedVal||'')}">`;
    }
    function addRow(keyVal,destType,destVal){
      keyVal=keyVal||''; destType=destType||'extension'; destVal=destVal||'';
      const tb=document.getElementById('mapBody'), tr=document.createElement('tr');
      tr.innerHTML=`
        <td><input name="key[]" placeholder="1 · * · # · _X." value="${escapeHtml(keyVal)}" style="width:90px;"></td>
        <td><select name="dest_type[]" onchange="changeDest(this,'')">
          <option value="extension"    ${destType==='extension'?'selected':''}>Extension</option>
          <option value="queue"        ${destType==='queue'?'selected':''}>Queue</option>
          <option value="ivr"          ${destType==='ivr'?'selected':''}>IVR</option>
          <option value="announcement" ${destType==='announcement'?'selected':''}>Announcement</option>
          <option value="custom"       ${destType==='custom'?'selected':''}>Custom</option>
        </select></td>
        <td class="dest-cell"></td>
        <td><button type="button" class="remove-btn" onclick="this.closest('tr').remove()">×</button></td>`;
      tb.appendChild(tr);
      changeDest(tr.querySelector('select[name="dest_type[]"]'), destVal);
    }
    function syncPromptSource(){
      const mode=document.querySelector('input[name="prompt_source"]:checked')?.value||'media';
      document.getElementById('promptMediaBox').classList.toggle('hidden',mode!=='media');
      document.getElementById('promptUploadBox').classList.toggle('hidden',mode!=='upload');
    }
    window.addEventListener('DOMContentLoaded',function(){
      syncPromptSource();
      const rows=<?php echo json_encode($mapValues,JSON_UNESCAPED_SLASHES); ?>;
      if(Array.isArray(rows)&&rows.length) rows.forEach(r=>addRow(r.key||'',r.dest_type||'extension',r.dest||''));
      else addRow('1','extension','');
    });
  </script>
</head>
<body>
<div class="wrap">
  <h1 class="page-title">CREATE IVR</h1>
  <div class="inner-box">

    <div class="top"><a class="btn" href="/ivr.php">BACK</a></div>

    <?php if($errors): ?>
      <div class="panel-box" style="margin-top:0;">
        <div class="pill">FAILED</div>
        <ul><?php foreach($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul>
      </div>
    <?php elseif($_SERVER['REQUEST_METHOD']==='POST'): ?>
      <div class="panel-box" style="margin-top:0;">
        <div class="pill"><?php echo $ok?'SUCCESS':'FAILED'; ?></div>
        <div class="small"><?php echo h($out); ?></div>
      </div>
    <?php endif; ?>

    <div class="panel-box">
      <form method="post" enctype="multipart/form-data" autocomplete="off">

        <div class="row">
          <div>
            <label>IVR Number</label>
            <input name="ivr_num" required placeholder="e.g. 7000"
                   value="<?php echo h($values['ivr_num']); ?>">
          </div>
          <div>
            <label>IVR Name</label>
            <input name="ivr_name" required placeholder="e.g. main-menu"
                   value="<?php echo h($values['ivr_name']); ?>">
          </div>
        </div>

        <hr class="section-divider">

        <label>Voice Prompt Source</label>
        <div class="actions-inline">
          <label><input type="radio" name="prompt_source" value="media"
            <?php echo $values['prompt_source']==='media'?'checked':''; ?>
            onclick="syncPromptSource()"> Media Center</label>
          <label><input type="radio" name="prompt_source" value="upload"
            <?php echo $values['prompt_source']==='upload'?'checked':''; ?>
            onclick="syncPromptSource()"> Upload New</label>
        </div>

        <div id="promptMediaBox" style="margin-top:12px;">
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
          <div class="small">Shows prompts from Media Center (IVR &amp; GENERAL). Only verified files shown.</div>
        </div>

        <div id="promptUploadBox" class="hidden" style="margin-top:12px;">
          <label>Upload Prompt File</label>
          <input type="file" name="prompt" accept=".wav,.mp3,.gsm,.ogg,.ulaw,.alaw">
        </div>

        <hr class="section-divider">

        <div class="row">
          <div>
            <label>Loop Count</label>
            <select name="loop_count">
              <?php for($i=1;$i<=9;$i++): ?>
                <option value="<?php echo $i; ?>"
                  <?php echo (string)$i===$values['loop_count']?'selected':''; ?>><?php echo $i; ?></option>
              <?php endfor; ?>
            </select>
            <div class="small">Retry count before fail action.</div>
          </div>
          <div>
            <label>On Invalid / Timeout</label>
            <select name="fail_mode"
              onchange="document.getElementById('failExtBox').style.display=(this.value==='goto'?'block':'none')">
              <option value="goto"   <?php echo $values['fail_mode']==='goto'  ?'selected':''; ?>>Goto Extension</option>
              <option value="hangup" <?php echo $values['fail_mode']==='hangup'?'selected':''; ?>>Hangup</option>
            </select>
            <div id="failExtBox"
                 style="margin-top:10px;<?php echo $values['fail_mode']==='goto'?'':'display:none;'; ?>">
              <input name="fail_ext" placeholder="2222"
                     value="<?php echo h($values['fail_ext']); ?>">
            </div>
          </div>
        </div>

        <hr class="section-divider">

        <div class="section-label">Key Map</div>
        <table class="map-table">
          <thead>
            <tr><th>Key</th><th>Type</th><th>Destination</th><th></th></tr>
          </thead>
          <tbody id="mapBody"></tbody>
        </table>
        <div class="actions-inline" style="margin-top:12px;">
          <button type="button" class="btn" onclick="addRow()">+ ADD ROW</button>
          <button type="button" class="btn" onclick="addRow('_X.','custom','2222')">ANY DIGIT</button>
        </div>

        <hr class="section-divider">

        <div class="actions">
          <a class="btn" href="/ivr.php">CANCEL</a>
          <button class="btn" type="submit">CREATE IVR</button>
        </div>

      </form>
    </div>
  </div>
</div>
</body>
</html>