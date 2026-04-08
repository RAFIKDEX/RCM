<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

const RCM_QUEUE_STATE = '/etc/asterisk/rcm_queues.json';
const RCM_QUEUE_PROMPT_DIR = '/var/lib/asterisk/sounds/en/rcm/queue';
const RCM_APPLY_SCRIPT = '/usr/local/bin/rcm_apply_queues.sh';

function fail_now(string $msg): void {
    http_response_code(400);
    die($msg);
}
function postv(string $k, string $default=''): string {
    return trim((string)($_POST[$k] ?? $default));
}
function yn_onoff(string $v, array $allow): string {
    return in_array($v, $allow, true) ? $v : $allow[0];
}
function load_state(): array {
    if (!file_exists(RCM_QUEUE_STATE)) return ['queues' => []];
    $data = json_decode(file_get_contents(RCM_QUEUE_STATE), true);
    return is_array($data) ? $data : ['queues' => []];
}
function save_state(array $state): void {
    file_put_contents(RCM_QUEUE_STATE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function prompt_token_from_uploaded(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) fail_now('Prompt upload failed');

    $orig = $file['name'] ?? '';
    $base = basename($orig);
    if (!preg_match('/^[A-Za-z0-9._ -]+\.wav$/i', $base)) {
        fail_now('Prompt must be .wav and clean filename only');
    }

    if (!is_dir(RCM_QUEUE_PROMPT_DIR) && !mkdir(RCM_QUEUE_PROMPT_DIR, 0755, true)) {
        fail_now('Cannot create prompt directory');
    }

    $dest = rtrim(RCM_QUEUE_PROMPT_DIR, '/') . '/' . $base;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        fail_now('Failed to save uploaded prompt');
    }
    chmod($dest, 0644);

    $token = preg_replace('/\.wav$/i', '', $base);
    return 'rcm/queue/' . $token;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_now('Invalid request');
}

$mode = postv('mode', 'add');
$originalQueueNumber = postv('original_queue_number');

$name = postv('name');
$queueNumber = postv('queue_number');

if (!preg_match('/^[0-9]{2,6}$/', $queueNumber)) fail_now('Invalid queue number');
if (!preg_match('/^[A-Za-z0-9 _-]{2,40}$/', $name)) fail_now('Invalid queue name');

$allowedStrategies = ['ringall','rrmemory','leastrecent','fewestcalls','random'];
$strategy = postv('strategy', 'rrmemory');
if (!in_array($strategy, $allowedStrategies, true)) fail_now('Invalid strategy');

$musicOnHold = postv('music_on_hold', 'default');
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $musicOnHold)) fail_now('Invalid MOH class');

$maxQueueLength = postv('max_queue_length', '10');
$wrapupTime = postv('wrapup_time', '5');
$retryTime = postv('retry_time', '2');
$ringTime = postv('ring_time', '5');
$maxWaitTime = postv('max_wait_time', '20');
foreach ([$maxQueueLength,$wrapupTime,$retryTime,$ringTime,$maxWaitTime] as $n) {
    if ($n !== '' && !preg_match('/^[0-9]+$/', $n)) fail_now('Invalid numeric value');
}

$autoRecord = yn_onoff(postv('auto_record', 'off'), ['off','on']);
$enableWelcomePrompt = yn_onoff(postv('enable_welcome_prompt', 'off'), ['off','on']);
$welcomeMode = postv('welcome_mode', 'before');
if (!in_array($welcomeMode, ['before','periodic','bridge'], true)) fail_now('Invalid welcome mode');

$customPrompt = postv('custom_prompt');
$uploadedPrompt = prompt_token_from_uploaded($_FILES['custom_prompt_file'] ?? []);
if ($uploadedPrompt !== '') $customPrompt = $uploadedPrompt;

$destinationType = postv('destination_type', 'hangup');
if (!in_array($destinationType, ['hangup','extension','ivr','announcement','queue'], true)) fail_now('Invalid destination type');
$destinationValue = postv('destination_value');
if ($destinationType !== 'hangup' && $destinationValue === '') fail_now('Destination value required');

$positionAnnouncement = yn_onoff(postv('position_announcement', 'off'), ['off','on']);
$announcementFrequency = postv('announcement_frequency', '15');
$periodicAnnouncement = postv('periodic_announcement');
$periodicAnnouncementFrequency = postv('periodic_announcement_frequency', '30');
$callerBridgeAnnouncement = postv('caller_bridge_announcement');
$leaveWhenEmpty = yn_onoff(postv('leave_when_empty', 'yes'), ['yes','no']);
$dialInEmptyQueue = yn_onoff(postv('dial_in_empty_queue', 'yes'), ['yes','no']);
$reportHoldTime = yn_onoff(postv('report_hold_time', 'off'), ['off','on']);
$replaceDisplayName = yn_onoff(postv('replace_display_name', 'off'), ['off','on']);
$displayNameValue = postv('display_name_value');
$skipBusyAgent = yn_onoff(postv('skip_busy_agent', 'on'), ['off','on']);
$autoFill = yn_onoff(postv('auto_fill', 'off'), ['off','on']);
$autoPause = yn_onoff(postv('auto_pause', 'off'), ['off','on']);
$agentBridgeAnnouncement = postv('agent_bridge_announcement');

$staticAgents = $_POST['static_agents'] ?? [];
if (!is_array($staticAgents)) $staticAgents = [];
$cleanAgents = [];
foreach ($staticAgents as $a) {
    $a = trim((string)$a);
    if ($a !== '' && preg_match('/^[0-9]{2,6}$/', $a)) $cleanAgents[$a] = $a;
}
$staticAgents = array_values($cleanAgents);

$record = [
    'name' => $name,
    'queue_number' => $queueNumber,
    'strategy' => $strategy,
    'music_on_hold' => $musicOnHold,
    'max_queue_length' => $maxQueueLength,
    'wrapup_time' => $wrapupTime,
    'retry_time' => $retryTime,
    'ring_time' => $ringTime,
    'auto_record' => $autoRecord,
    'enable_welcome_prompt' => $enableWelcomePrompt,
    'custom_prompt' => $customPrompt,
    'welcome_mode' => $welcomeMode,
    'max_wait_time' => $maxWaitTime,
    'destination_type' => $destinationType,
    'destination_value' => $destinationValue,
    'position_announcement' => $positionAnnouncement,
    'announcement_frequency' => $announcementFrequency,
    'periodic_announcement' => $periodicAnnouncement,
    'periodic_announcement_frequency' => $periodicAnnouncementFrequency,
    'caller_bridge_announcement' => $callerBridgeAnnouncement,
    'leave_when_empty' => $leaveWhenEmpty,
    'dial_in_empty_queue' => $dialInEmptyQueue,
    'report_hold_time' => $reportHoldTime,
    'replace_display_name' => $replaceDisplayName,
    'display_name_value' => $displayNameValue,
    'skip_busy_agent' => $skipBusyAgent,
    'auto_fill' => $autoFill,
    'auto_pause' => $autoPause,
    'agent_bridge_announcement' => $agentBridgeAnnouncement,
    'static_agents' => $staticAgents,
];

$state = load_state();
$queues = $state['queues'] ?? [];

/* منع duplicate queue number */
foreach ($queues as $q) {
    $existing = (string)($q['queue_number'] ?? '');
    if ($existing === $queueNumber && !($mode === 'edit' && $existing === $originalQueueNumber)) {
        fail_now('Queue number already exists');
    }
}

$updated = [];
$found = false;

foreach ($queues as $q) {
    $existing = (string)($q['queue_number'] ?? '');
    if ($mode === 'edit' && $existing === $originalQueueNumber) {
        $updated[] = $record;
        $found = true;
    } else {
        $updated[] = $q;
    }
}

if ($mode === 'add' || !$found) {
    $updated[] = $record;
}

$state['queues'] = $updated;
save_state($state);

$out = shell_exec('sudo ' . escapeshellarg(RCM_APPLY_SCRIPT) . ' 2>&1');
if ($out !== null && stripos($out, 'ERROR:') !== false) {
    fail_now(nl2br(htmlspecialchars($out, ENT_QUOTES, 'UTF-8')));
}

header('Location: /queues.php');
exit;
