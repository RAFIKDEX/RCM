<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$DP_FILE = "/etc/asterisk/extensions_gui.conf";
$ANN_PROMPTS_DIR = "/var/lib/asterisk/sounds/en/rcm/ann";
$MEDIA_DB = "/etc/asterisk/rcm_media_center.json";

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function load_media_db($file){
    if (!is_file($file)) return ["prompts" => [], "moh_classes" => []];
    $db = json_decode((string)@file_get_contents($file), true);
    if (!is_array($db)) $db = ["prompts" => [], "moh_classes" => []];
    if (!isset($db['prompts']) || !is_array($db['prompts'])) $db['prompts'] = [];
    if (!isset($db['moh_classes']) || !is_array($db['moh_classes'])) $db['moh_classes'] = [];
    return $db;
}

function list_media_prompts($file){
    $db = load_media_db($file);
    $out = [];
    foreach ($db['prompts'] as $p){
        $name = trim((string)($p['name'] ?? ''));
        $type = strtolower(trim((string)($p['type'] ?? 'general')));
        $path = trim((string)($p['path'] ?? ''));
        if ($name === '' || $path === '' || !is_file($path)) continue;
        if (!in_array($type, ['announcement', 'general'], true)) continue;
        $out[] = [
            'id' => (string)($p['id'] ?? $name),
            'name' => $name,
            'type' => $type,
            'path' => $path,
        ];
    }
    usort($out, function($a, $b){ return strnatcasecmp($a['name'], $b['name']); });
    return $out;
}

function list_ann_prompts($dir){
    $out = [];
    foreach (glob(rtrim($dir, '/') . "/ann_*.wav") ?: [] as $f){
        if (preg_match('/ann_(\d{2,6})\.wav$/', $f, $m)){
            $out[] = ['num' => $m[1], 'path' => $f];
        }
    }
    usort($out, function($a, $b){ return intval($a['num']) <=> intval($b['num']); });
    return $out;
}

function load_queues_from_file($file){
    $queues = [];
    if (!is_file($file)) return $queues;
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line){
        $t = trim($line);
        if (preg_match('/^\[(?!general\]|globals\]|default\]|from-)([a-zA-Z0-9_-]{2,40})\]$/', $t, $m)){
            $ctx = $m[1];
            if (stripos($ctx, 'ann-') === 0 || stripos($ctx, 'ivr-') === 0 || stripos($ctx, 'ext-') === 0) continue;
        }
        if (preg_match('/Queue\(([a-zA-Z0-9_-]{2,40})\)/', $t, $m)){
            $queues[$m[1]] = $m[1];
        }
    }
    ksort($queues, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($queues);
}

function load_one($file, $num){
    if (!is_file($file)) return null;
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $cur = null;
    $pending = null;
    $ann = null;

    foreach ($lines as $line){
        $t = trim($line);

        if (preg_match('/^;\s*---\s*ANN GUI:\s*([a-zA-Z0-9_-]+)\s*---/', $t, $m)){
            $pending = $m[1];
            continue;
        }
        if (preg_match('/^\[ann-(\d{2,6})\]$/', $t, $m)){
            $cur = $m[1];
            if ($cur === $num){
                $ann = [
                    'num' => $num,
                    'name' => $pending ?? ('ann-' . $num),
                    'dest_type' => 'hangup',
                    'dest_val' => '',
                ];
            }
            $pending = null;
            continue;
        }
        if (!$ann || $cur !== $num) continue;

        if (preg_match('/Goto\(internal,(\d{2,6}),1\)/', $t, $m)){
            $ann['dest_type'] = 'extension';
            $ann['dest_val'] = $m[1];
        } elseif (preg_match('/Goto\(ivr-(\d{2,6}),(\d{2,6}),1\)/', $t, $m)){
            $ann['dest_type'] = 'ivr';
            $ann['dest_val'] = $m[1];
        } elseif (preg_match('/Queue\(([a-zA-Z0-9_-]{2,40})\)/', $t, $m)){
            $ann['dest_type'] = 'queue';
            $ann['dest_val'] = $m[1];
        } elseif (stripos($t, 'Hangup()') !== false) {
            $ann['dest_type'] = 'hangup';
            $ann['dest_val'] = '';
        }
    }

    return $ann;
}

function upload_prompt_file($sourcePath, $num, &$error){
    if (!is_file($sourcePath)){
        $error = 'Selected prompt file not found';
        return false;
    }
    $cmd = 'sudo /usr/local/bin/rcm_upload_ann_prompt.sh ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($num) . ' 2>&1';
    $out = shell_exec($cmd) ?? '';
    if (strpos($out, 'OK:') !== 0){
        $error = 'Upload failed: ' . trim($out);
        return false;
    }
    return true;
}

$queues = load_queues_from_file($DP_FILE);
if (!$queues) $queues = ['support'];
$existingPrompts = list_ann_prompts($ANN_PROMPTS_DIR);
$mediaPrompts = list_media_prompts($MEDIA_DB);

$editNum = trim((string)($_GET['edit'] ?? ''));
$editing = preg_match('/^\d{2,6}$/', $editNum) === 1;
$prefill = $editing ? load_one($DP_FILE, $editNum) : null;

$errors = [];
$num = $prefill['num'] ?? '';
$name = $prefill['name'] ?? '';
$destType = $prefill['dest_type'] ?? 'hangup';
$destVal = $prefill['dest_val'] ?? '';
$promptSource = $editing ? 'keep' : 'media';
$useExistingPrompt = '';
$useMediaPrompt = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $num = trim((string)($_POST['num'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $destType = trim((string)($_POST['dest_type'] ?? 'hangup'));
    $destVal = trim((string)($_POST['dest_val'] ?? ''));
    $promptSource = trim((string)($_POST['prompt_source'] ?? ($editing ? 'keep' : 'media')));
    $useExistingPrompt = trim((string)($_POST['use_existing_prompt'] ?? ''));
    $useMediaPrompt = trim((string)($_POST['use_media_prompt'] ?? ''));

    if (!preg_match('/^\d{2,6}$/', $num)) $errors[] = 'Invalid number';
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]{2,30}$/', $name)) $errors[] = 'Invalid name';
    if (!in_array($destType, ['hangup', 'extension', 'ivr', 'queue'], true)) $errors[] = 'Invalid destination type';
    if (!in_array($promptSource, $editing ? ['keep','media','existing','upload'] : ['media','existing','upload'], true)) $errors[] = 'Invalid prompt source';

    if ($destType === 'extension' || $destType === 'ivr'){
        if (!preg_match('/^\d{2,6}$/', $destVal)) $errors[] = 'Destination must be a valid number';
    } elseif ($destType === 'queue') {
        if (!preg_match('/^[a-zA-Z0-9_-]{2,40}$/', $destVal)) $errors[] = 'Invalid queue name';
    } else {
        $destVal = '';
    }

    $hasFile = isset($_FILES['prompt']) && ($_FILES['prompt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if (!$editing && $promptSource === 'upload' && !$hasFile) $errors[] = 'Please upload an audio file';
    if (!$editing && $promptSource === 'existing' && !preg_match('/^\d{2,6}$/', $useExistingPrompt)) $errors[] = 'Choose an existing announcement prompt';
    if (!$editing && $promptSource === 'media' && $useMediaPrompt === '') $errors[] = 'Choose a Media Center prompt';
    if ($editing && $promptSource === 'existing' && !preg_match('/^\d{2,6}$/', $useExistingPrompt)) $errors[] = 'Choose an existing announcement prompt';
    if ($editing && $promptSource === 'media' && $useMediaPrompt === '') $errors[] = 'Choose a Media Center prompt';

    if (!$errors && $promptSource === 'media'){
        $selected = null;
        foreach ($mediaPrompts as $p){
            if ($p['id'] === $useMediaPrompt || $p['name'] === $useMediaPrompt){
                $selected = $p;
                break;
            }
        }
        if (!$selected){
            $errors[] = 'Selected Media Center prompt not found';
        } else {
            $uploadError = '';
            if (!upload_prompt_file($selected['path'], $num, $uploadError)) $errors[] = $uploadError;
        }
    }

    if (!$errors && $promptSource === 'existing'){
        $srcExisting = $ANN_PROMPTS_DIR . '/ann_' . $useExistingPrompt . '.wav';
        $uploadError = '';
        if (!upload_prompt_file($srcExisting, $num, $uploadError)) $errors[] = $uploadError;
    }

    if (!$errors && $promptSource === 'upload'){
        $orig = (string)($_FILES['prompt']['name'] ?? '');
        $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['wav','mp3','gsm','ogg'], true)){
            $errors[] = 'Only wav/mp3/gsm/ogg allowed';
        } else {
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($orig));
            $tmp = '/tmp/ann_' . $num . '_' . uniqid('', true) . '_' . $safeName;
            if (!move_uploaded_file($_FILES['prompt']['tmp_name'], $tmp)){
                $errors[] = 'Cannot move uploaded file';
            } else {
                $uploadError = '';
                if (!upload_prompt_file($tmp, $num, $uploadError)) $errors[] = $uploadError;
                @unlink($tmp);
            }
        }
    }

    if (!$errors){
        $cmd = 'sudo /usr/local/bin/rcm_add_ann.sh '
            . escapeshellarg($num) . ' '
            . escapeshellarg($name) . ' '
            . escapeshellarg($destType) . ' '
            . escapeshellarg($destVal === '' ? '-' : $destVal) . ' 2>&1';

        $out = shell_exec($cmd) ?? '';
        if (strpos($out, 'OK:') === 0){
            header('Location: /announcements.php');
            exit;
        }
        $errors[] = trim($out) !== '' ? trim($out) : 'Failed to save announcement';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?php echo $editing ? 'Edit' : 'Add'; ?> Announcement</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .errors{ margin:0; padding-left:18px; }
    .note{ display:block; margin-top:6px; opacity:.9; }
    .stack{ display:grid; gap:10px; }
    .source-box{ margin-top:10px; }
    .source-panel{ display:none; margin-top:10px; }
    .source-panel.active{ display:block; }
    .radio-row{ display:flex; gap:18px; flex-wrap:wrap; align-items:center; }
    @media (max-width: 760px){ .grid-2{ grid-template-columns:1fr; } }
  </style>

  <script>
    function onDestChange(){
      const t = document.getElementById('dest_type').value;
      const box = document.getElementById('dest_val_box');
      const lab = document.getElementById('dest_val_lab');
      const inp = document.getElementById('dest_val');
      const sel = document.getElementById('queue_sel');
      if (t === 'hangup') {
        box.style.display = 'none';
        sel.style.display = 'none';
      } else if (t === 'queue') {
        box.style.display = 'none';
        sel.style.display = 'block';
      } else {
        sel.style.display = 'none';
        box.style.display = 'block';
        lab.textContent = (t === 'ivr' ? 'IVR Number' : 'Extension Number');
        inp.placeholder = (t === 'ivr' ? '7000' : '2222');
      }
    }

    function onPromptSourceChange(){
      const checked = document.querySelector('input[name="prompt_source"]:checked');
      const source = checked ? checked.value : 'media';
      document.querySelectorAll('.source-panel').forEach(function(el){ el.classList.remove('active'); });
      const target = document.getElementById('source_' + source);
      if (target) target.classList.add('active');
    }

    document.addEventListener('DOMContentLoaded', function(){
      onDestChange();
      onPromptSourceChange();
      document.querySelectorAll('input[name="prompt_source"]').forEach(function(el){
        el.addEventListener('change', onPromptSourceChange);
      });
      document.getElementById('dest_type').addEventListener('change', onDestChange);
    });
  </script>
</head>
<body>
<div class="wrap">
  <h1 class="page-title"><?php echo $editing ? 'EDIT' : 'ADD'; ?> ANNOUNCEMENT</h1>

  <div class="inner-box">
    <?php if ($errors): ?>
      <div class="panel-box" style="margin-top:0;">
        <div class="pill" style="margin-bottom:10px;">FAILED</div>
        <ul class="errors">
          <?php foreach ($errors as $e): ?>
            <li><?php echo h($e); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="panel-box">
      <form method="post" enctype="multipart/form-data">
        <div class="grid-2">
          <div>
            <label>Number</label>
            <input name="num" value="<?php echo h($num); ?>" <?php echo $editing ? 'readonly' : ''; ?> required>
          </div>
          <div>
            <label>Name</label>
            <input name="name" value="<?php echo h($name); ?>" required>
          </div>
        </div>

        <div class="source-box">
          <label>Prompt Source</label>
          <div class="radio-row">
            <?php if ($editing): ?>
              <label><input type="radio" name="prompt_source" value="keep" <?php echo $promptSource === 'keep' ? 'checked' : ''; ?>> Keep Current</label>
            <?php endif; ?>
            <label><input type="radio" name="prompt_source" value="media" <?php echo $promptSource === 'media' ? 'checked' : ''; ?>> Media Center</label>
            <label><input type="radio" name="prompt_source" value="existing" <?php echo $promptSource === 'existing' ? 'checked' : ''; ?>> Existing Announcement Prompt</label>
            <label><input type="radio" name="prompt_source" value="upload" <?php echo $promptSource === 'upload' ? 'checked' : ''; ?>> Upload New</label>
          </div>

          <?php if ($editing): ?>
            <div id="source_keep" class="source-panel <?php echo $promptSource === 'keep' ? 'active' : ''; ?>">
              <small class="note">هيحتفظ بالملف الحالي بدون أي تغيير.</small>
            </div>
          <?php endif; ?>

          <div id="source_media" class="source-panel <?php echo $promptSource === 'media' ? 'active' : ''; ?>">
            <label>Media Center Prompt</label>
            <select name="use_media_prompt">
              <option value="">-- Select prompt from Media Center --</option>
              <?php foreach ($mediaPrompts as $p): ?>
                <?php $value = $p['id'] !== '' ? $p['id'] : $p['name']; ?>
                <option value="<?php echo h($value); ?>" <?php echo $useMediaPrompt === $value ? 'selected' : ''; ?>>
                  <?php echo h($p['name']); ?> (<?php echo h(strtoupper($p['type'])); ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <small class="note">بيسحب البرومبت من Media Center ويحوّله لملف الإعلان الحالي.</small>
          </div>

          <div id="source_existing" class="source-panel <?php echo $promptSource === 'existing' ? 'active' : ''; ?>">
            <label>Existing Announcement Prompt</label>
            <select name="use_existing_prompt">
              <option value="">-- Select existing announcement prompt --</option>
              <?php foreach ($existingPrompts as $p): ?>
                <option value="<?php echo h($p['num']); ?>" <?php echo $useExistingPrompt === (string)$p['num'] ? 'selected' : ''; ?>>
                  ann_<?php echo h($p['num']); ?>.wav
                </option>
              <?php endforeach; ?>
            </select>
            <small class="note">ينسخ من إعلان موجود عندك بدل ما ترفع ملف جديد.</small>
          </div>

          <div id="source_upload" class="source-panel <?php echo $promptSource === 'upload' ? 'active' : ''; ?>">
            <label>Upload Audio File</label>
            <input type="file" name="prompt" accept=".wav,.mp3,.gsm,.ogg">
            <small class="note">الصيغ المدعومة: WAV / MP3 / GSM / OGG</small>
          </div>
        </div>

        <div class="grid-2" style="margin-top:16px;">
          <div>
            <label>Default Destination</label>
            <select id="dest_type" name="dest_type">
              <option value="hangup" <?php echo $destType === 'hangup' ? 'selected' : ''; ?>>Hangup</option>
              <option value="extension" <?php echo $destType === 'extension' ? 'selected' : ''; ?>>Extension</option>
              <option value="ivr" <?php echo $destType === 'ivr' ? 'selected' : ''; ?>>IVR</option>
              <option value="queue" <?php echo $destType === 'queue' ? 'selected' : ''; ?>>Queue</option>
            </select>
          </div>

          <div>
            <div id="dest_val_box">
              <label id="dest_val_lab">Extension Number</label>
              <input id="dest_val" name="dest_val" value="<?php echo h($destVal); ?>">
            </div>

            <div id="queue_sel" style="display:none;">
              <label>Queue</label>
              <select name="dest_val">
                <?php foreach ($queues as $q): ?>
                  <option value="<?php echo h($q); ?>" <?php echo $destVal === $q ? 'selected' : ''; ?>><?php echo h($q); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="btn-row" style="justify-content:space-between; margin-top:18px;">
          <a class="btn" href="/announcements.php">CANCEL</a>
          <button class="btn" type="submit"><?php echo $editing ? 'SAVE' : 'CREATE'; ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
