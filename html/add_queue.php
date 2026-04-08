<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

const RCM_QUEUE_STATE = '/etc/asterisk/rcm_queues.json';
const RCM_MEDIA_CENTER_FILE = '/etc/asterisk/rcm_media_center.json';
const RCM_EP_FILE = '/etc/asterisk/pjsip.gui.endpoint.conf';

function q_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function q_load_state(): array {
    if (!file_exists(RCM_QUEUE_STATE)) return ['queues' => []];
    $raw = file_get_contents(RCM_QUEUE_STATE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['queues' => []];
}

function q_find_queue(string $number): ?array {
    $state = q_load_state();
    foreach (($state['queues'] ?? []) as $q) {
        if (($q['queue_number'] ?? '') === $number) return $q;
    }
    return null;
}

function q_list_extensions(): array {
    $exts = [];
    if (file_exists(RCM_EP_FILE)) {
        $lines = file(RCM_EP_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\[(\d{2,6})\]$/', $line, $m)) {
                $exts[$m[1]] = $m[1];
            }
        }
    }
    natsort($exts);
    return array_values($exts);
}

function q_load_media_center(): array {
    if (!file_exists(RCM_MEDIA_CENTER_FILE)) {
        return [
            'prompts' => [],
            'moh_classes' => [],
        ];
    }

    $raw = file_get_contents(RCM_MEDIA_CENTER_FILE);
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [
            'prompts' => [],
            'moh_classes' => [],
        ];
    }

    return [
        'prompts' => is_array($data['prompts'] ?? null) ? $data['prompts'] : [],
        'moh_classes' => is_array($data['moh_classes'] ?? null) ? $data['moh_classes'] : [],
    ];
}

function q_list_prompts(): array {
    $media = q_load_media_center();
    $items = [];

    foreach (($media['prompts'] ?? []) as $prompt) {
        if (!is_array($prompt)) continue;

        $name = trim((string)($prompt['name'] ?? ''));
        $type = strtolower(trim((string)($prompt['type'] ?? '')));

        if ($name === '') continue;
        if (!in_array($type, ['queue', 'general'], true)) continue;

        $items[$name] = [
            'label' => $name,
            'value' => $name,
        ];
    }

    $items = array_values($items);
    usort($items, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
    return $items;
}

function q_list_moh_classes(): array {
    $media = q_load_media_center();
    $classes = [];

    foreach (($media['moh_classes'] ?? []) as $class) {
        if (is_array($class)) {
            $name = trim((string)($class['name'] ?? ''));
        } else {
            $name = trim((string)$class);
        }

        if ($name === '') continue;
        $classes[$name] = $name;
    }

    if (!$classes) $classes['default'] = 'default';

    natsort($classes);
    return array_values($classes);
}

$queueNumber = isset($_GET['queue']) ? trim((string)$_GET['queue']) : '';
$isEdit = $queueNumber !== '';
$queue = $isEdit ? q_find_queue($queueNumber) : null;
if ($isEdit && !$queue) {
    http_response_code(404);
    die('Queue not found');
}

$defaults = [
    'name' => '',
    'queue_number' => '',
    'strategy' => 'rrmemory',
    'music_on_hold' => 'default',
    'max_queue_length' => '10',
    'wrapup_time' => '5',
    'retry_time' => '2',
    'ring_time' => '5',
    'auto_record' => 'off',

    'enable_welcome_prompt' => 'off',
    'custom_prompt' => '',
    'welcome_mode' => 'before',

    'max_wait_time' => '20',
    'destination_type' => 'hangup',
    'destination_value' => '',

    'position_announcement' => 'off',
    'announcement_frequency' => '15',
    'periodic_announcement' => '',
    'periodic_announcement_frequency' => '30',
    'caller_bridge_announcement' => '',

    'leave_when_empty' => 'yes',
    'dial_in_empty_queue' => 'yes',

    'report_hold_time' => 'off',
    'replace_display_name' => 'off',
    'display_name_value' => '',
    'skip_busy_agent' => 'on',
    'auto_fill' => 'off',
    'auto_pause' => 'off',
    'agent_bridge_announcement' => '',

    'static_agents' => [],
];

$data = $defaults;
if ($queue) {
    $data = array_merge($defaults, $queue);
}

$extensions = q_list_extensions();
$prompts = q_list_prompts();
$mohClasses = q_list_moh_classes();

$selectedAgents = array_values(array_unique(array_filter($data['static_agents'] ?? [])));
$availableAgents = array_values(array_diff($extensions, $selectedAgents));

$pageTitle = $isEdit ? 'EDIT QUEUE' : 'ADD QUEUE';
$saveLabel = $isEdit ? 'SAVE CHANGES' : 'CREATE QUEUE';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo q_h($pageTitle); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/rcm.css">
<link rel="icon" type="image/png" href="/assets/rcm/logo.png">
<style>
html, body{ height:auto !important; }
body{
  display:block !important;
  align-items:initial !important;
  justify-content:initial !important;
}
.wrap{ width:min(1280px, 96vw); margin:0 auto 60px auto; }
.q-topbar{
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; flex-wrap:wrap; margin-bottom:10px;
}
.tab-row{
  display:flex; gap:10px; flex-wrap:wrap; margin:14px 0 18px;
}
.tab-btn.active{
  box-shadow:0 0 18px rgba(255,255,255,.20);
  border-color:rgba(255,255,255,.45);
}
.tab-pane{ display:none; }
.tab-pane.active{ display:block; }

.grid-2{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:14px;
}
.grid-3{
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:14px;
}
@media (max-width: 980px){
  .grid-2, .grid-3{ grid-template-columns:1fr; }
}
.field{
  display:flex;
  flex-direction:column;
  gap:8px;
}
.field label{
  font-family:'Orbitron',sans-serif;
  letter-spacing:1px;
  font-size:13px;
}
.field small{ opacity:.75; }

.dual{
  display:grid;
  grid-template-columns:minmax(0,1fr) 90px minmax(0,1fr);
  gap:14px;
  align-items:center;
}
@media (max-width: 980px){
  .dual{ grid-template-columns:1fr; }
}
select[multiple]{
  min-height:260px;
}
.mid-actions{
  display:flex; flex-direction:column; gap:10px; justify-content:center;
}
.switch-row{
  display:flex; gap:12px; flex-wrap:wrap; align-items:center;
}
.kv{
  display:grid;
  grid-template-columns:220px 1fr;
  gap:10px 14px;
}
@media (max-width: 700px){
  .kv{ grid-template-columns:1fr; }
}
.code-pill{
  display:inline-flex;
  align-items:center;
  padding:10px 14px;
  border:1px solid rgba(255,255,255,.18);
  border-radius:14px;
  background:rgba(255,255,255,.06);
  font-family:'Orbitron',sans-serif;
  letter-spacing:1px;
  margin:6px 10px 0 0;
}
.note{
  font-size:12px;
  opacity:.8;
}
.hide{ display:none !important; }
</style>
<script>
function showTab(name){
  document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById('tab_' + name).classList.add('active');
  document.getElementById('btn_' + name).classList.add('active');
}
function moveSelected(fromId, toId){
  const from = document.getElementById(fromId);
  const to = document.getElementById(toId);
  [...from.options].filter(o => o.selected).forEach(o => {
    o.selected = false;
    to.add(o);
  });
  sortSelect(from);
  sortSelect(to);
}
function sortSelect(sel){
  const items = [...sel.options].sort((a,b)=>a.text.localeCompare(b.text, undefined, {numeric:true, sensitivity:'base'}));
  sel.innerHTML = '';
  items.forEach(o => sel.add(o));
}
function selectAllAgents(){
  const sel = document.getElementById('selected_agents');
  [...sel.options].forEach(o => o.selected = true);
}
function toggleWelcomeFields(){
  const enabled = document.getElementById('enable_welcome_prompt').value === 'on';
  document.querySelectorAll('.welcome-only').forEach(el => el.classList.toggle('hide', !enabled));
}
function toggleDisplayNameField(){
  const enabled = document.getElementById('replace_display_name').value === 'on';
  document.getElementById('display_name_wrap').classList.toggle('hide', !enabled);
}
window.addEventListener('DOMContentLoaded', ()=>{
  showTab('basic');
  toggleWelcomeFields();
  toggleDisplayNameField();
});
</script>
</head>
<body>

<div class="wrap">
  <div class="q-topbar">
    <a class="btn" href="/queues.php">BACK</a>
    <h1 class="page-title" style="margin:0;font-size:54px;"><?php echo q_h($pageTitle); ?></h1>
    <a class="btn" href="/queue.php">QUEUE MONITOR</a>
  </div>

  <form method="post" action="/save_queue.php" onsubmit="selectAllAgents()">
    <input type="hidden" name="mode" value="<?php echo $isEdit ? 'edit' : 'add'; ?>">
    <input type="hidden" name="original_queue_number" value="<?php echo q_h($data['queue_number']); ?>">

    <div class="inner-box" style="margin-top:0;">
      <div class="top">
        <span class="pill"><?php echo $isEdit ? 'EDIT MODE' : 'NEW QUEUE'; ?></span>
        <span class="pill">BASIC + ADVANCED + STATIC AGENTS + FEATURE CODES</span>
        <span class="pill">MEDIA CENTER READY</span>
      </div>

      <div class="tab-row">
        <button type="button" class="btn tab-btn active" id="btn_basic" onclick="showTab('basic')">BASIC</button>
        <button type="button" class="btn tab-btn" id="btn_advanced" onclick="showTab('advanced')">ADVANCED</button>
        <button type="button" class="btn tab-btn" id="btn_agents" onclick="showTab('agents')">STATIC AGENTS</button>
        <button type="button" class="btn tab-btn" id="btn_codes" onclick="showTab('codes')">FEATURE CODES</button>
      </div>

      <div class="panel-box tab-pane active" id="tab_basic">
        <div class="grid-3">
          <div class="field">
            <label>NAME</label>
            <input type="text" name="name" required maxlength="40" value="<?php echo q_h($data['name']); ?>" placeholder="support">
          </div>
          <div class="field">
            <label>QUEUE NUMBER</label>
            <input type="text" name="queue_number" required pattern="[0-9]{2,6}" value="<?php echo q_h($data['queue_number']); ?>" placeholder="7000">
          </div>
          <div class="field">
            <label>STRATEGY</label>
            <select name="strategy">
              <?php
              $strategies = ['ringall','rrmemory','leastrecent','fewestcalls','random'];
              foreach ($strategies as $s):
              ?>
              <option value="<?php echo q_h($s); ?>" <?php echo $data['strategy'] === $s ? 'selected' : ''; ?>><?php echo q_h($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid-3" style="margin-top:14px;">
          <div class="field">
            <label>MUSIC ON HOLD</label>
            <select name="music_on_hold">
              <?php foreach ($mohClasses as $cls): ?>
              <option value="<?php echo q_h($cls); ?>" <?php echo $data['music_on_hold'] === $cls ? 'selected' : ''; ?>><?php echo q_h($cls); ?></option>
              <?php endforeach; ?>
            </select>
            <small>Loaded from Media Center MOH Classes.</small>
          </div>
          <div class="field">
            <label>MAX QUEUE LENGTH</label>
            <input type="number" min="0" name="max_queue_length" value="<?php echo q_h($data['max_queue_length']); ?>">
          </div>
          <div class="field">
            <label>WRAP UP TIME (SECONDS)</label>
            <input type="number" min="0" name="wrapup_time" value="<?php echo q_h($data['wrapup_time']); ?>">
          </div>
        </div>

        <div class="grid-3" style="margin-top:14px;">
          <div class="field">
            <label>RETRY TIME (SECONDS)</label>
            <input type="number" min="0" name="retry_time" value="<?php echo q_h($data['retry_time']); ?>">
          </div>
          <div class="field">
            <label>RING TIME (SECONDS)</label>
            <input type="number" min="0" name="ring_time" value="<?php echo q_h($data['ring_time']); ?>">
          </div>
          <div class="field">
            <label>AUTO RECORD</label>
            <select name="auto_record">
              <option value="off" <?php echo $data['auto_record']==='off'?'selected':''; ?>>OFF</option>
              <option value="on" <?php echo $data['auto_record']==='on'?'selected':''; ?>>ON</option>
            </select>
          </div>
        </div>

        <div class="hr"></div>

        <div class="grid-3">
          <div class="field">
            <label>ENABLE WELCOME PROMPT</label>
            <select id="enable_welcome_prompt" name="enable_welcome_prompt" onchange="toggleWelcomeFields()">
              <option value="off" <?php echo $data['enable_welcome_prompt']==='off'?'selected':''; ?>>OFF</option>
              <option value="on" <?php echo $data['enable_welcome_prompt']==='on'?'selected':''; ?>>ON</option>
            </select>
          </div>

          <div class="field welcome-only">
            <label>WELCOME PROMPT</label>
            <select name="custom_prompt">
              <option value="">-- SELECT --</option>
              <?php foreach ($prompts as $p): ?>
              <option value="<?php echo q_h($p['value']); ?>" <?php echo $data['custom_prompt']===$p['value']?'selected':''; ?>>
                <?php echo q_h($p['label']); ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small>Loaded from Media Center prompts (queue / general only).</small>
          </div>

          <div class="field welcome-only">
            <label>WELCOME MODE</label>
            <select name="welcome_mode">
              <option value="before" <?php echo $data['welcome_mode']==='before'?'selected':''; ?>>PLAY FULL PROMPT THEN ENTER QUEUE</option>
              <option value="periodic" <?php echo $data['welcome_mode']==='periodic'?'selected':''; ?>>PLAY PROMPT WHILE CALLER IS WAITING</option>
              <option value="bridge" <?php echo $data['welcome_mode']==='bridge'?'selected':''; ?>>PLAY TO CALLER BEFORE AGENT BRIDGE</option>
            </select>
          </div>
        </div>

        <div class="grid-3 welcome-only" style="margin-top:14px;">
          <div class="field">
            <label>MAX WAIT TIME (SECONDS)</label>
            <input type="number" min="0" name="max_wait_time" value="<?php echo q_h($data['max_wait_time']); ?>">
          </div>
          <div class="field">
            <label>DESTINATION</label>
            <div class="grid-2">
              <select name="destination_type">
                <option value="hangup" <?php echo $data['destination_type']==='hangup'?'selected':''; ?>>HANGUP</option>
                <option value="extension" <?php echo $data['destination_type']==='extension'?'selected':''; ?>>EXTENSION</option>
                <option value="ivr" <?php echo $data['destination_type']==='ivr'?'selected':''; ?>>IVR</option>
                <option value="announcement" <?php echo $data['destination_type']==='announcement'?'selected':''; ?>>ANNOUNCEMENT</option>
                <option value="queue" <?php echo $data['destination_type']==='queue'?'selected':''; ?>>QUEUE</option>
              </select>
              <input type="text" name="destination_value" value="<?php echo q_h($data['destination_value']); ?>" placeholder="destination number / queue">
            </div>
          </div>
          <div class="field">
            <label>LEAVE WHEN EMPTY</label>
            <select name="leave_when_empty">
              <option value="yes" <?php echo $data['leave_when_empty']==='yes'?'selected':''; ?>>YES</option>
              <option value="no" <?php echo $data['leave_when_empty']==='no'?'selected':''; ?>>NO</option>
            </select>
          </div>
        </div>
      </div>

      <div class="panel-box tab-pane" id="tab_advanced">
        <div class="top"><span class="pill">CALLER ANNOUNCEMENT</span></div>
        <div class="grid-3" style="margin-top:14px;">
          <div class="field">
            <label>POSITION ANNOUNCEMENT</label>
            <select name="position_announcement">
              <option value="off" <?php echo $data['position_announcement']==='off'?'selected':''; ?>>OFF</option>
              <option value="on" <?php echo $data['position_announcement']==='on'?'selected':''; ?>>ON</option>
            </select>
          </div>
          <div class="field">
            <label>ANNOUNCEMENT FREQUENCY (SECONDS)</label>
            <input type="number" min="1" name="announcement_frequency" value="<?php echo q_h($data['announcement_frequency']); ?>">
          </div>
          <div class="field">
            <label>PERIODIC ANNOUNCEMENT</label>
            <select name="periodic_announcement">
              <option value="">-- NONE --</option>
              <?php foreach ($prompts as $p): ?>
              <option value="<?php echo q_h($p['value']); ?>" <?php echo $data['periodic_announcement']===$p['value']?'selected':''; ?>>
                <?php echo q_h($p['label']); ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small>Loaded from Media Center prompts (queue / general only).</small>
          </div>
        </div>

        <div class="grid-3" style="margin-top:14px;">
          <div class="field">
            <label>PERIODIC ANNOUNCEMENT FREQUENCY (SECONDS)</label>
            <input type="number" min="1" name="periodic_announcement_frequency" value="<?php echo q_h($data['periodic_announcement_frequency']); ?>">
          </div>
          <div class="field">
            <label>CALLER BRIDGE ANNOUNCEMENT</label>
            <select name="caller_bridge_announcement">
              <option value="">-- NONE --</option>
              <?php foreach ($prompts as $p): ?>
              <option value="<?php echo q_h($p['value']); ?>" <?php echo $data['caller_bridge_announcement']===$p['value']?'selected':''; ?>>
                <?php echo q_h($p['label']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>AGENT BRIDGE ANNOUNCEMENT</label>
            <select name="agent_bridge_announcement">
              <option value="">-- NONE --</option>
              <?php foreach ($prompts as $p): ?>
              <option value="<?php echo q_h($p['value']); ?>" <?php echo $data['agent_bridge_announcement']===$p['value']?'selected':''; ?>>
                <?php echo q_h($p['label']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="hr"></div>

        <div class="top"><span class="pill">EMPTY QUEUE</span></div>
        <div class="grid-2" style="margin-top:14px;">
          <div class="field">
            <label>DIAL IN EMPTY QUEUE</label>
            <select name="dial_in_empty_queue">
              <option value="yes" <?php echo $data['dial_in_empty_queue']==='yes'?'selected':''; ?>>YES</option>
              <option value="no" <?php echo $data['dial_in_empty_queue']==='no'?'selected':''; ?>>NO</option>
            </select>
          </div>
          <div class="field">
            <label>REPORT HOLD TIME</label>
            <select name="report_hold_time">
              <option value="off" <?php echo $data['report_hold_time']==='off'?'selected':''; ?>>OFF</option>
              <option value="on" <?php echo $data['report_hold_time']==='on'?'selected':''; ?>>ON</option>
            </select>
          </div>
        </div>

        <div class="hr"></div>

        <div class="top"><span class="pill">OTHER SETTINGS</span></div>
        <div class="grid-3" style="margin-top:14px;">
          <div class="field">
            <label>REPLACE DISPLAY NAME</label>
            <select id="replace_display_name" name="replace_display_name" onchange="toggleDisplayNameField()">
              <option value="off" <?php echo $data['replace_display_name']==='off'?'selected':''; ?>>OFF</option>
              <option value="on" <?php echo $data['replace_display_name']==='on'?'selected':''; ?>>ON</option>
            </select>
          </div>
          <div class="field <?php echo $data['replace_display_name']==='on' ? '' : 'hide'; ?>" id="display_name_wrap">
            <label>DISPLAY NAME VALUE</label>
            <input type="text" name="display_name_value" maxlength="40" value="<?php echo q_h($data['display_name_value']); ?>" placeholder="SUPPORT">
          </div>
          <div class="field">
            <label>SKIP BUSY AGENT</label>
            <select name="skip_busy_agent">
              <option value="on" <?php echo $data['skip_busy_agent']==='on'?'selected':''; ?>>ON</option>
              <option value="off" <?php echo $data['skip_busy_agent']==='off'?'selected':''; ?>>OFF</option>
            </select>
          </div>
        </div>

        <div class="grid-3" style="margin-top:14px;">
          <div class="field">
            <label>AUTO FILL</label>
            <select name="auto_fill">
              <option value="off" <?php echo $data['auto_fill']==='off'?'selected':''; ?>>OFF</option>
              <option value="on" <?php echo $data['auto_fill']==='on'?'selected':''; ?>>ON</option>
            </select>
          </div>
          <div class="field">
            <label>AUTO PAUSE</label>
            <select name="auto_pause">
              <option value="off" <?php echo $data['auto_pause']==='off'?'selected':''; ?>>OFF</option>
              <option value="on" <?php echo $data['auto_pause']==='on'?'selected':''; ?>>ON</option>
            </select>
          </div>
        </div>
      </div>

      <div class="panel-box tab-pane" id="tab_agents">
        <div class="top">
          <span class="pill">STATIC AGENTS</span>
          <span class="pill">AVAILABLE → SELECTED</span>
        </div>

        <div class="dual" style="margin-top:14px;">
          <div class="field">
            <label>AVAILABLE EXTENSIONS</label>
            <select id="available_agents" multiple>
              <?php foreach ($availableAgents as $ext): ?>
              <option value="<?php echo q_h($ext); ?>"><?php echo q_h($ext); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mid-actions">
            <button type="button" class="btn" onclick="moveSelected('available_agents','selected_agents')">&gt;&gt;</button>
            <button type="button" class="btn" onclick="moveSelected('selected_agents','available_agents')">&lt;&lt;</button>
          </div>

          <div class="field">
            <label>SELECTED STATIC AGENTS</label>
            <select id="selected_agents" name="static_agents[]" multiple>
              <?php foreach ($selectedAgents as $ext): ?>
              <option value="<?php echo q_h($ext); ?>"><?php echo q_h($ext); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="panel-box tab-pane" id="tab_codes">
        <div class="top"><span class="pill">FEATURE CODES</span></div>
        <div style="margin-top:14px;">
          <?php $n = $data['queue_number'] ?: 'XXXX'; ?>
          <span class="code-pill">LOGIN: *71<?php echo q_h($n); ?></span>
          <span class="code-pill">LOGOUT: *72<?php echo q_h($n); ?></span>
          <span class="code-pill">PAUSE: *73<?php echo q_h($n); ?></span>
          <span class="code-pill">UNPAUSE: *74<?php echo q_h($n); ?></span>
          <div class="note" style="margin-top:12px;">الأكواد dynamic per-queue، وبتعتمد على رقم الكيو نفسه.</div>
        </div>
      </div>

      <div class="btn-row" style="margin-top:18px;">
        <button type="submit" class="btn"><?php echo q_h($saveLabel); ?></button>
        <a class="btn" href="/queues.php">CANCEL</a>
      </div>
    </div>
  </form>
</div>

</body>
</html>