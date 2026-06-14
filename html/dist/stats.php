<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'callcenter';
const DB_USER = 'dexter';
const DB_PASS = 'admin';

const CDR_DB_NAME = 'asteriskcdr';
const CDR_TABLE   = 'cdr';

const RECORDING_DIRS = [
    '/var/spool/asterisk/monitor',
    '/var/spool/asterisk/monitor/queue',
    '/var/spool/asterisk/monitor/rcm',
    '/var/spool/asterisk/monitor/mixmonitor',
];

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fmt_dt($v): string {
    if (!$v) return '';
    $ts = strtotime((string)$v);
    if (!$ts) return (string)$v;
    return date('n/j/Y g:i:s A', $ts);
}

function fmt_seconds($sec): string {
    $sec = (int)round((float)$sec);
    if ($sec < 0) $sec = 0;
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function valid_date($v): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function db_raw(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function qlog_event_label(string $event): string {
    $map = [
        'ENTERQUEUE'      => 'Enter Queue',
        'CONNECT'         => 'Connected',
        'COMPLETEAGENT'   => 'Answered / Agent End',
        'COMPLETECALLER'  => 'Answered / Caller End',
        'ABANDON'         => 'Abandoned',
        'EXITWITHTIMEOUT' => 'Timeout',
        'RINGNOANSWER'    => 'No Answer',
        'RINGCANCELED'    => 'Canceled',
        'ADDMEMBER'       => 'Login',
        'REMOVEMEMBER'    => 'Logout',
        'PAUSE'           => 'Pause',
        'UNPAUSE'         => 'Unpause',
    ];
    return $map[$event] ?? $event;
}

function badge_class(string $event): string {
    $event = strtoupper($event);
    if (in_array($event, ['CONNECT', 'COMPLETEAGENT', 'COMPLETECALLER'], true)) return 'ok';
    if (in_array($event, ['ABANDON', 'EXITWITHTIMEOUT'], true)) return 'bad';
    if (in_array($event, ['RINGNOANSWER', 'RINGCANCELED'], true)) return 'warn';
    if ($event === 'ENTERQUEUE') return 'info';
    if (in_array($event, ['ADDMEMBER', 'PAUSE'], true)) return 'ok';
    if (in_array($event, ['REMOVEMEMBER', 'UNPAUSE'], true)) return 'info';
    return 'muted';
}

function parse_clid_parts(string $clid): array {
    $clid = trim($clid);
    if ($clid === '') return ['', ''];

    if (preg_match('/^"([^"]*)"\s*<([^>]+)>$/', $clid, $m)) {
        return [trim($m[1]), trim($m[2])];
    }

    if (preg_match('/<([^>]+)>$/', $clid, $m)) {
        return ['', trim($m[1])];
    }

    return ['', $clid];
}

function caller_display(string $name, string $number): string {
    $name = trim($name);
    $number = trim($number);

    if ($number !== '' && $name !== '' && $name !== $number) {
        return $name . ' <' . $number . '>';
    }
    if ($number !== '') return $number;
    if ($name !== '') return $name;
    return '';
}

function existing_column_map(PDO $pdo, string $schema, string $table): array {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = :table
    ");
    $stmt->execute([
        ':schema' => $schema,
        ':table' => $table,
    ]);
    $cols = [];
    foreach ($stmt->fetchAll() as $row) {
        $cols[strtolower((string)$row['COLUMN_NAME'])] = true;
    }
    return $cols;
}

function cdr_table_exists(PDO $pdo, string $schema, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = :table
    ");
    $stmt->execute([
        ':schema' => $schema,
        ':table' => $table,
    ]);
    $row = $stmt->fetch();
    return ((int)($row['c'] ?? 0)) > 0;
}

function normalize_recording_path(string $value): string {
    $value = trim($value);
    if ($value === '') return '';

    if ($value[0] === '/') {
        return $value;
    }

    foreach (RECORDING_DIRS as $dir) {
        $candidate = rtrim($dir, '/') . '/' . ltrim($value, '/');
        if (is_file($candidate)) {
            return $candidate;
        }

        foreach (['.wav', '.WAV', '.gsm', '.mp3', '.ulaw', '.alaw'] as $ext) {
            if (is_file($candidate . $ext)) {
                return $candidate . $ext;
            }
        }
    }

    return $value;
}

function find_recording_file(string $seed): string {
    $seed = trim($seed);
    if ($seed === '') return '';

    $direct = normalize_recording_path($seed);
    if ($direct !== '' && is_file($direct)) {
        return $direct;
    }

    foreach (RECORDING_DIRS as $dir) {
        if (!is_dir($dir)) continue;

        $patterns = [
            $dir . '/' . $seed,
            $dir . '/' . $seed . '.*',
            $dir . '/*' . $seed . '*',
            $dir . '/*/*' . $seed . '*',
            $dir . '/*/*/*' . $seed . '*',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (!$matches) continue;
            foreach ($matches as $m) {
                if (is_file($m)) return $m;
            }
        }
    }

    return '';
}

function recording_token(string $absPath): string {
    return rtrim(strtr(base64_encode($absPath), '+/', '-_'), '=');
}

function recording_path_from_token(string $token): string {
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if (!is_string($raw) || $raw === '') return '';
    $real = realpath($raw);
    if ($real === false || !is_file($real)) return '';

    foreach (RECORDING_DIRS as $base) {
        $baseReal = realpath($base);
        if ($baseReal !== false && str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) {
            return $real;
        }
    }
    return '';
}

function stream_recording_if_requested(): void {
    if (empty($_GET['stream_recording'])) return;

    $path = recording_path_from_token((string)$_GET['stream_recording']);
    if ($path === '') {
        http_response_code(404);
        exit('Recording not found');
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'gsm' => 'audio/gsm',
        'ogg' => 'audio/ogg',
        default => 'application/octet-stream',
    };

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

stream_recording_if_requested();

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to'] ?? date('Y-m-d');
$queueFilter = trim((string)($_GET['queue'] ?? ''));
$agentFilter = trim((string)($_GET['agent'] ?? ''));

if (!valid_date($from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!valid_date($to))   $to   = date('Y-m-d');

$fromDt = $from . ' 00:00:00';
$toDt   = $to . ' 23:59:59';

$error = '';
$queueOptions = [];
$agentOptions = [];
$rows = [];

try {
    $pdo = db();

    $queueOptions = $pdo->query("
        SELECT DISTINCT queuename
        FROM queue_log
        WHERE queuename IS NOT NULL
          AND queuename <> ''
          AND queuename <> 'NONE'
        ORDER BY queuename
    ")->fetchAll();

    $agentOptions = $pdo->query("
        SELECT DISTINCT agent
        FROM queue_log
        WHERE agent IS NOT NULL
          AND agent <> ''
          AND agent <> 'NONE'
        ORDER BY agent
    ")->fetchAll();

    $where = ["time BETWEEN :from_dt AND :to_dt"];
    $params = [
        ':from_dt' => $fromDt,
        ':to_dt'   => $toDt,
    ];

    if ($queueFilter !== '') {
        $where[] = "queuename = :queue_name";
        $params[':queue_name'] = $queueFilter;
    }

    if ($agentFilter !== '') {
        $where[] = "agent = :agent_name";
        $params[':agent_name'] = $agentFilter;
    }

    $sql = "
        SELECT id, time, callid, queuename, agent, event, data1, data2, data3, data4, data5
        FROM queue_log
        WHERE " . implode(' AND ', $where) . "
        ORDER BY time ASC, id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* =========================================================
   BUILD DATASETS
========================================================= */

$queueStats = [];
$callDetails = [];
$queueDetails = [];
$loginDetails = [];
$pauseDetails = [];

$totalCalls = 0;
$answeredCalls = 0;
$abandonedCalls = 0;
$timeoutCalls = 0;
$avgWaitTime = 0;
$avgTalkTime = 0;
$longestCall = 0;
$shortestCall = null;
$maxCallsQueue = ['queue' => '-', 'count' => 0];
$longestCallBy = ['agent' => '-', 'duration' => 0];
$shortestCallBy = ['agent' => '-', 'duration' => null];
$longestBreakBy = ['agent' => '-', 'duration' => 0];

$waitSum = 0;
$waitCount = 0;
$talkSum = 0;
$talkCount = 0;

$callSessions = [];   // callid => session
$loginOpen = [];      // queue|agent => current open login
$pauseOpen = [];      // queue|agent => current open pause

$LOGIN_EVENTS   = ['ADDMEMBER'];
$LOGOUT_EVENTS  = ['REMOVEMEMBER'];
$PAUSE_EVENTS   = ['PAUSE'];
$UNPAUSE_EVENTS = ['UNPAUSE'];

$CALL_EVENTS = [
    'ENTERQUEUE',
    'CONNECT',
    'COMPLETEAGENT',
    'COMPLETECALLER',
    'ABANDON',
    'EXITWITHTIMEOUT',
    'RINGNOANSWER',
    'RINGCANCELED',
];

foreach ($rows as $r) {
    $event  = strtoupper(trim((string)$r['event']));
    $queue  = trim((string)$r['queuename']);
    $agent  = trim((string)$r['agent']);
    $callid = trim((string)$r['callid']);
    $time   = (string)$r['time'];

    /* ---------------- Queue statistics ---------------- */
    if ($queue !== '' && $queue !== 'NONE') {
        if (!isset($queueStats[$queue])) {
            $queueStats[$queue] = [
                'queue' => $queue,
                'total_calls' => 0,
                'answered_calls' => 0,
                'abandoned_calls' => 0,
                'avg_talk_times' => [],
                'avg_wait_times' => [],
                'longest_talk_time' => 0,
                'shortest_talk_time' => null,
                'longest_wait_time' => 0,
                'shortest_wait_time' => null,
            ];
        }

        if ($event === 'ENTERQUEUE') {
            $queueStats[$queue]['total_calls']++;
            $totalCalls++;
        }

        if ($event === 'CONNECT') {
            $queueStats[$queue]['answered_calls']++;
            $answeredCalls++;

            $wait = (int)($r['data1'] !== '' ? $r['data1'] : 0);
            $queueStats[$queue]['avg_wait_times'][] = $wait;
            $queueStats[$queue]['longest_wait_time'] = max($queueStats[$queue]['longest_wait_time'], $wait);

            if ($queueStats[$queue]['shortest_wait_time'] === null || $wait < $queueStats[$queue]['shortest_wait_time']) {
                $queueStats[$queue]['shortest_wait_time'] = $wait;
            }

            $waitSum += $wait;
            $waitCount++;
        }

        if ($event === 'ABANDON') {
            $queueStats[$queue]['abandoned_calls']++;
            $abandonedCalls++;

            $wait = (int)($r['data3'] !== '' ? $r['data3'] : 0);
            $queueStats[$queue]['avg_wait_times'][] = $wait;
            $queueStats[$queue]['longest_wait_time'] = max($queueStats[$queue]['longest_wait_time'], $wait);

            if ($queueStats[$queue]['shortest_wait_time'] === null || $wait < $queueStats[$queue]['shortest_wait_time']) {
                $queueStats[$queue]['shortest_wait_time'] = $wait;
            }

            $waitSum += $wait;
            $waitCount++;
        }

        if ($event === 'EXITWITHTIMEOUT') {
            $timeoutCalls++;

            $wait = (int)($r['data3'] !== '' ? $r['data3'] : 0);
            $queueStats[$queue]['avg_wait_times'][] = $wait;
            $queueStats[$queue]['longest_wait_time'] = max($queueStats[$queue]['longest_wait_time'], $wait);

            if ($queueStats[$queue]['shortest_wait_time'] === null || $wait < $queueStats[$queue]['shortest_wait_time']) {
                $queueStats[$queue]['shortest_wait_time'] = $wait;
            }

            $waitSum += $wait;
            $waitCount++;
        }

        if (in_array($event, ['COMPLETEAGENT', 'COMPLETECALLER'], true)) {
            $talk = (int)($r['data2'] !== '' ? $r['data2'] : 0);

            $queueStats[$queue]['avg_talk_times'][] = $talk;
            $queueStats[$queue]['longest_talk_time'] = max($queueStats[$queue]['longest_talk_time'], $talk);

            if ($queueStats[$queue]['shortest_talk_time'] === null || $talk < $queueStats[$queue]['shortest_talk_time']) {
                $queueStats[$queue]['shortest_talk_time'] = $talk;
            }

            $talkSum += $talk;
            $talkCount++;
            $longestCall = max($longestCall, $talk);

            if ($shortestCall === null || $talk < $shortestCall) {
                $shortestCall = $talk;
            }

            if ($agent !== '' && $agent !== 'NONE') {
                if ($talk >= $longestCallBy['duration']) {
                    $longestCallBy = ['agent' => $agent, 'duration' => $talk];
                }
                if ($shortestCallBy['duration'] === null || $talk < $shortestCallBy['duration']) {
                    $shortestCallBy = ['agent' => $agent, 'duration' => $talk];
                }
            }
        }
    }

    /* ---------------- Call sessions / queue details ---------------- */
    if ($callid !== '' && $callid !== 'NONE' && in_array($event, $CALL_EVENTS, true)) {
        if (!isset($callSessions[$callid])) {
            $callSessions[$callid] = [
                'callid' => $callid,
                'queue' => $queue,
                'caller' => '',
                'caller_name' => '',
                'caller_number' => '',
                'caller_display' => '',
                'time' => $time,
                'agent' => '',
                'position' => '',
                'waiting' => 0,
                'duration' => 0,
                'hold' => '',
                'hangup_by' => '',
                'abandoned' => 'no',
                'status' => '',
                'started' => false,
                'recording_file' => '',
                'events' => [],
            ];
        }

        $callSessions[$callid]['events'][] = $r;

        if ($event === 'ENTERQUEUE') {
            $callSessions[$callid]['queue'] = $queue;
            $callSessions[$callid]['time'] = $time;
            $callSessions[$callid]['caller_number'] = trim((string)($r['data2'] ?? ''));
            $callSessions[$callid]['caller'] = $callSessions[$callid]['caller_number'];
            $callSessions[$callid]['caller_display'] = $callSessions[$callid]['caller_number'];
            $callSessions[$callid]['position'] = (string)($r['data3'] ?? '');
            $callSessions[$callid]['status'] = 'ENTERQUEUE';
            $callSessions[$callid]['started'] = true;
        }

        if ($event === 'CONNECT') {
            $callSessions[$callid]['agent'] = $agent;
            $callSessions[$callid]['waiting'] = (int)($r['data1'] !== '' ? $r['data1'] : 0);
            $callSessions[$callid]['status'] = 'CONNECTED';
        }

        if ($event === 'ABANDON') {
            $callSessions[$callid]['abandoned'] = 'yes';
            $callSessions[$callid]['status'] = 'ABANDON';
            $callSessions[$callid]['position'] = (string)($r['data1'] ?? $callSessions[$callid]['position']);
            $callSessions[$callid]['waiting'] = (int)($r['data3'] !== '' ? $r['data3'] : $callSessions[$callid]['waiting']);
            $callSessions[$callid]['hangup_by'] = 'Caller';
        }

        if ($event === 'EXITWITHTIMEOUT') {
            $callSessions[$callid]['status'] = 'TIMEOUT';
            $callSessions[$callid]['position'] = (string)($r['data1'] ?? $callSessions[$callid]['position']);
            $callSessions[$callid]['waiting'] = (int)($r['data3'] !== '' ? $r['data3'] : $callSessions[$callid]['waiting']);
            $callSessions[$callid]['hangup_by'] = 'Timeout';
        }

        if ($event === 'COMPLETEAGENT') {
            $callSessions[$callid]['duration'] = (int)($r['data2'] !== '' ? $r['data2'] : 0);
            $callSessions[$callid]['hangup_by'] = 'Agent';
            $callSessions[$callid]['status'] = 'COMPLETEAGENT';
        }

        if ($event === 'COMPLETECALLER') {
            $callSessions[$callid]['duration'] = (int)($r['data2'] !== '' ? $r['data2'] : 0);
            $callSessions[$callid]['hangup_by'] = 'Caller';
            $callSessions[$callid]['status'] = 'COMPLETECALLER';
        }

        /* ---------------- Call details (event rows) ---------------- */
        $sessionCaller = $callSessions[$callid]['caller_display'] ?: ($callSessions[$callid]['caller'] ?? '');

        $positionValue = '';
        if ($event === 'ENTERQUEUE') {
            $positionValue = (string)($r['data3'] ?? '');
        } elseif ($event === 'ABANDON') {
            $positionValue = (string)($r['data1'] ?? '');
        } elseif ($event === 'EXITWITHTIMEOUT') {
            $positionValue = (string)($r['data1'] ?? '');
        }

        $waitingValue = 0;
        if ($event === 'CONNECT') {
            $waitingValue = (int)($r['data1'] !== '' ? $r['data1'] : 0);
        } elseif ($event === 'ABANDON' || $event === 'EXITWITHTIMEOUT') {
            $waitingValue = (int)($r['data3'] !== '' ? $r['data3'] : 0);
        }

        $durationValue = 0;
        if ($event === 'COMPLETEAGENT' || $event === 'COMPLETECALLER') {
            $durationValue = (int)($r['data2'] !== '' ? $r['data2'] : 0);
        }

        $hangupBy = '';
        if ($event === 'COMPLETEAGENT') {
            $hangupBy = 'Agent';
        } elseif ($event === 'COMPLETECALLER' || $event === 'ABANDON') {
            $hangupBy = 'Caller';
        } elseif ($event === 'EXITWITHTIMEOUT') {
            $hangupBy = 'Timeout';
        }

        $callDetails[] = [
            'callid'     => $callid,
            'queue'      => $queue,
            'caller'     => $sessionCaller,
            'time'       => $time,
            'status'     => $event,
            'agent'      => $agent,
            'position'   => $positionValue,
            'waiting'    => $waitingValue,
            'duration'   => $durationValue,
            'hangup_by'  => $hangupBy,
            'recording_file' => '',
        ];
    }

    /* ---------------- Login session pairing ---------------- */
    if ($queue !== '' && $queue !== 'NONE' && $agent !== '' && $agent !== 'NONE') {
        $loginKey = $queue . '|' . $agent;

        if (in_array($event, $LOGIN_EVENTS, true)) {
            if (!isset($loginOpen[$loginKey])) {
                $loginOpen[$loginKey] = [];
            }
            $loginOpen[$loginKey][] = [
                'queue' => $queue,
                'agent' => $agent,
                'login_time' => $time,
                'logout_time' => '',
                'duration' => 0,
                'status' => 'OPEN',
            ];
        }

        if (in_array($event, $LOGOUT_EVENTS, true)) {
            if (!empty($loginOpen[$loginKey])) {
                $sess = array_shift($loginOpen[$loginKey]);
                $sess['logout_time'] = $time;
                $sess['duration'] = max(0, strtotime($time) - strtotime($sess['login_time']));
                $sess['status'] = 'CLOSED';
                $loginDetails[] = $sess;
            } else {
                $loginDetails[] = [
                    'queue' => $queue,
                    'agent' => $agent,
                    'login_time' => '',
                    'logout_time' => $time,
                    'duration' => 0,
                    'status' => 'LOGOUT ONLY',
                ];
            }
        }
    }

    /* ---------------- Pause session pairing ---------------- */
    if ($queue !== '' && $queue !== 'NONE' && $agent !== '' && $agent !== 'NONE') {
        $pauseKey = $queue . '|' . $agent;

        if (in_array($event, $PAUSE_EVENTS, true)) {
            if (!isset($pauseOpen[$pauseKey])) {
                $pauseOpen[$pauseKey] = [];
            }

            $reason = trim((string)($r['data1'] ?? ''));
            if ($reason === '' && trim((string)($r['data2'] ?? '')) !== '') {
                $reason = trim((string)$r['data2']);
            }

            $pauseOpen[$pauseKey][] = [
                'queue' => $queue,
                'agent' => $agent,
                'pause_time' => $time,
                'unpause_time' => '',
                'duration' => 0,
                'reason' => $reason,
                'status' => 'OPEN',
            ];
        }

        if (in_array($event, $UNPAUSE_EVENTS, true)) {
            if (!empty($pauseOpen[$pauseKey])) {
                $sess = array_shift($pauseOpen[$pauseKey]);
                $sess['unpause_time'] = $time;
                $sess['duration'] = max(0, strtotime($time) - strtotime($sess['pause_time']));
                $sess['status'] = 'CLOSED';
                $pauseDetails[] = $sess;

                if ($sess['duration'] >= $longestBreakBy['duration']) {
                    $longestBreakBy = [
                        'agent' => $agent,
                        'duration' => $sess['duration'],
                    ];
                }
            } else {
                $pauseDetails[] = [
                    'queue' => $queue,
                    'agent' => $agent,
                    'pause_time' => '',
                    'unpause_time' => $time,
                    'duration' => 0,
                    'reason' => '',
                    'status' => 'UNPAUSE ONLY',
                ];
            }
        }
    }
}

/* close open login sessions */
foreach ($loginOpen as $openList) {
    foreach ($openList as $sess) {
        $sess['status'] = 'STILL LOGGED IN';
        $sess['duration'] = max(0, strtotime($toDt) - strtotime($sess['login_time']));
        $loginDetails[] = $sess;
    }
}

/* close open pause sessions */
foreach ($pauseOpen as $openList) {
    foreach ($openList as $sess) {
        $sess['status'] = 'STILL PAUSED';
        $sess['duration'] = max(0, strtotime($toDt) - strtotime($sess['pause_time']));
        $pauseDetails[] = $sess;

        if ($sess['duration'] >= $longestBreakBy['duration']) {
            $longestBreakBy = [
                'agent' => $sess['agent'],
                'duration' => $sess['duration'],
            ];
        }
    }
}

/* =========================================================
   Optional CDR enrichment
========================================================= */
$cdrError = '';
if ($error === '' && !empty($callSessions)) {
    try {
        $rawPdo = db_raw();

        if (cdr_table_exists($rawPdo, CDR_DB_NAME, CDR_TABLE)) {
            $cdrCols = existing_column_map($rawPdo, CDR_DB_NAME, CDR_TABLE);

            $selectParts = [];
            foreach (['uniqueid', 'linkedid', 'clid', 'src', 'recordingfile'] as $col) {
                if (isset($cdrCols[$col])) {
                    $selectParts[] = $col;
                }
            }

            if (!empty($selectParts) && isset($cdrCols['uniqueid'])) {
                $callIds = array_values(array_unique(array_keys($callSessions)));
                $placeholders = implode(',', array_fill(0, count($callIds), '?'));

                $sqlCdr = "
                    SELECT " . implode(', ', $selectParts) . "
                    FROM " . CDR_DB_NAME . "." . CDR_TABLE . "
                    WHERE uniqueid IN ($placeholders)
                ";

                if (isset($cdrCols['linkedid'])) {
                    $sqlCdr .= " OR linkedid IN ($placeholders)";
                    $paramsCdr = array_merge($callIds, $callIds);
                } else {
                    $paramsCdr = $callIds;
                }

                $stmtCdr = $rawPdo->prepare($sqlCdr);
                $stmtCdr->execute($paramsCdr);
                $cdrRows = $stmtCdr->fetchAll();

                foreach ($cdrRows as $cdr) {
                    $keys = [];
                    if (!empty($cdr['uniqueid'])) $keys[] = (string)$cdr['uniqueid'];
                    if (!empty($cdr['linkedid'])) $keys[] = (string)$cdr['linkedid'];

                    $clid = (string)($cdr['clid'] ?? '');
                    $src  = trim((string)($cdr['src'] ?? ''));
                    [$clidName, $clidNum] = parse_clid_parts($clid);
                    $number = $src !== '' ? $src : $clidNum;
                    $display = caller_display($clidName, $number);

                    $recFile = trim((string)($cdr['recordingfile'] ?? ''));
                    $resolvedRec = $recFile !== '' ? find_recording_file($recFile) : '';

                    foreach ($keys as $key) {
                        if (!isset($callSessions[$key])) continue;

                        if ($callSessions[$key]['caller_display'] === '' && $display !== '') {
                            $callSessions[$key]['caller_name'] = $clidName;
                            $callSessions[$key]['caller_number'] = $number;
                            $callSessions[$key]['caller'] = $number !== '' ? $number : $display;
                            $callSessions[$key]['caller_display'] = $display;
                        }

                        if ($callSessions[$key]['caller_display'] === '' && $callSessions[$key]['caller'] !== '') {
                            $callSessions[$key]['caller_display'] = $callSessions[$key]['caller'];
                        }

                        if ($callSessions[$key]['recording_file'] === '' && $resolvedRec !== '') {
                            $callSessions[$key]['recording_file'] = $resolvedRec;
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $cdrError = $e->getMessage();
    }
}

/* finalize queue details */
foreach ($callSessions as $sess) {
    if (empty($sess['started'])) {
        continue;
    }

    $callerDisplay = $sess['caller_display'] !== '' ? $sess['caller_display'] : $sess['caller'];
    if ($callerDisplay === '') {
        continue;
    }

    $queueDetails[] = [
        'callid' => $sess['callid'],
        'queue' => $sess['queue'],
        'caller' => $callerDisplay,
        'time' => $sess['time'],
        'abandoned_last_position' => ($sess['abandoned'] === 'yes'
            ? 'yes / ' . ($sess['position'] !== '' ? $sess['position'] : '-')
            : 'no'),
        'agent' => $sess['agent'],
        'position' => $sess['position'],
        'waiting' => $sess['waiting'],
        'duration' => $sess['duration'],
        'hold' => '',
        'hangup_by' => $sess['hangup_by'],
        'recording_file' => $sess['recording_file'],
        'status' => $sess['status'],
    ];
}

/* sync call details with enriched caller/recording */
foreach ($callDetails as &$row) {
    $cid = $row['callid'] ?? '';
    if ($cid !== '' && isset($callSessions[$cid])) {
        $sess = $callSessions[$cid];
        $row['caller'] = $sess['caller_display'] !== '' ? $sess['caller_display'] : ($row['caller'] ?: $sess['caller']);
        $row['recording_file'] = $sess['recording_file'] ?? '';
    }
}
unset($row);

/* finalize queue stats */
foreach ($queueStats as &$st) {
    $st['avg_talk_time'] = count($st['avg_talk_times']) ? array_sum($st['avg_talk_times']) / count($st['avg_talk_times']) : 0;
    $st['avg_wait_time'] = count($st['avg_wait_times']) ? array_sum($st['avg_wait_times']) / count($st['avg_wait_times']) : 0;
    if ($st['shortest_talk_time'] === null) $st['shortest_talk_time'] = 0;
    if ($st['shortest_wait_time'] === null) $st['shortest_wait_time'] = 0;
    $st['answer_rate'] = $st['total_calls'] > 0 ? ($st['answered_calls'] / $st['total_calls']) * 100 : 0;

    if ($st['total_calls'] >= $maxCallsQueue['count']) {
        $maxCallsQueue = ['queue' => $st['queue'], 'count' => $st['total_calls']];
    }
}
unset($st);

$avgWaitTime = $waitCount > 0 ? $waitSum / $waitCount : 0;
$avgTalkTime = $talkCount > 0 ? $talkSum / $talkCount : 0;
$answerRate  = $totalCalls > 0 ? ($answeredCalls / $totalCalls) * 100 : 0;
$abandonRate = $totalCalls > 0 ? ($abandonedCalls / $totalCalls) * 100 : 0;

if ($shortestCall === null) $shortestCall = 0;
if ($shortestCallBy['duration'] === null) $shortestCallBy = ['agent' => '-', 'duration' => 0];

/* sorting */
uasort($queueStats, fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);
usort($queueDetails, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
usort($callDetails, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
usort($loginDetails, function($a, $b){
    return strtotime($b['login_time'] ?: $b['logout_time']) <=> strtotime($a['login_time'] ?: $a['logout_time']);
});
usort($pauseDetails, function($a, $b){
    return strtotime($b['pause_time'] ?: $b['unpause_time']) <=> strtotime($a['pause_time'] ?: $a['unpause_time']);
});

$chartMax = 0;
foreach ($queueStats as $st) {
    if ($st['total_calls'] > $chartMax) $chartMax = $st['total_calls'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Queue Statistics</title>

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/dist/assets/rcm.css">
<link rel="icon" type="image/png" href="/assets/rcm/logo.png">

<style>
html, body{ height:auto !important; }
body{
  display:block !important;
  align-items:initial !important;
  justify-content:initial !important;
}
.wrap{
  width:min(1600px, 96vw);
  margin:0 auto 60px auto;
}
.topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:10px;
}
.filters-grid{
  display:grid;
  grid-template-columns:repeat(4, minmax(0,1fr));
  gap:14px;
}
@media (max-width: 1100px){
  .filters-grid{ grid-template-columns:1fr 1fr; }
}
@media (max-width: 700px){
  .filters-grid{ grid-template-columns:1fr; }
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
.cards{
  display:grid;
  grid-template-columns:1.4fr 1fr 1fr;
  gap:14px;
  margin-top:14px;
}
@media (max-width: 1200px){
  .cards{ grid-template-columns:1fr; }
}
.ring-card{
  display:grid;
  grid-template-columns:180px 1fr;
  gap:16px;
  align-items:center;
}
.ring{
  width:150px;
  height:150px;
  border-radius:50%;
  margin:auto;
  background:
    conic-gradient(#8fd175 0deg <?php echo $totalCalls > 0 ? round(($answeredCalls / $totalCalls) * 360, 2) : 0; ?>deg,
                   #f2a082 <?php echo $totalCalls > 0 ? round(($answeredCalls / $totalCalls) * 360, 2) : 0; ?>deg 360deg);
  position:relative;
}
.ring::after{
  content:'';
  position:absolute;
  inset:14px;
  border-radius:50%;
  background:#f5f6fb;
}
.ring-inner{
  position:absolute;
  inset:0;
  display:flex;
  align-items:center;
  justify-content:center;
  flex-direction:column;
  z-index:2;
  color:#333;
  text-align:center;
}
.ring-title{
  font-size:18px;
  font-weight:700;
  color:#b7b7ca;
}
.ring-total{
  font-size:38px;
  font-weight:900;
  color:#2e2e38;
  margin-top:6px;
}
.split-stats{
  display:grid;
  grid-template-columns:1fr 140px;
  gap:8px;
}
.split-line{
  padding:10px 0;
  border-bottom:1px solid rgba(0,0,0,.08);
}
.split-line:last-child{ border-bottom:0; }
.ok-text{ color:#8fd175; font-weight:700; }
.bad-text{ color:#f2a082; font-weight:700; }
.percent-col{
  display:flex;
  flex-direction:column;
  justify-content:center;
  gap:22px;
  text-align:right;
}
.percent-big{
  font-size:42px;
  font-weight:900;
  line-height:1;
}
.small-cards{
  display:grid;
  grid-template-columns:1fr;
  gap:14px;
}
.small-card{
  border:1px solid rgba(255,255,255,.14);
  border-radius:18px;
  background:rgba(255,255,255,.04);
  padding:18px;
}
.small-card .k{
  font-family:'Orbitron',sans-serif;
  font-size:14px;
  letter-spacing:1px;
  opacity:.8;
}
.small-card .v{
  font-family:'Orbitron',sans-serif;
  font-size:28px;
  margin-top:10px;
}
.legend-note{
  font-size:12px;
  opacity:.8;
}
.chart-wrap{
  padding:20px 10px 10px;
}
.queue-bars{
  display:flex;
  gap:18px;
  align-items:flex-end;
  min-height:220px;
  overflow:auto;
  padding:10px 8px 0;
}
.bar-col{
  min-width:120px;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:10px;
}
.bar{
  width:100%;
  border-radius:14px 14px 0 0;
  border:1px solid rgba(255,255,255,.16);
  background:rgba(180,220,140,.25);
}
.bar-label{
  text-align:center;
  font-size:13px;
  line-height:1.3;
}
.bar-value{
  font-family:'Orbitron',sans-serif;
  font-size:13px;
}
.tabs{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  margin-top:14px;
}
.tab-btn.active{
  box-shadow:0 0 18px rgba(255,255,255,.20);
  border-color:rgba(255,255,255,.45);
}
.tab-pane{ display:none; }
.tab-pane.active{ display:block; }
.table-tools{
  display:flex;
  justify-content:flex-end;
  align-items:center;
  gap:10px;
  margin:10px 0 14px;
  flex-wrap:wrap;
}
.table-tools input{
  max-width:220px;
}
.badge{
  display:inline-flex;
  align-items:center;
  padding:4px 8px;
  border-radius:7px;
  font-size:11px;
  font-weight:700;
}
.badge.ok{
  background:rgba(80,220,120,.15);
  color:#3cd267;
}
.badge.bad{
  background:rgba(255,100,100,.15);
  color:#ff6666;
}
.badge.warn{
  background:rgba(255,180,80,.15);
  color:#ff9b2f;
}
.badge.info{
  background:rgba(80,180,255,.15);
  color:#59b1ff;
}
.badge.muted{
  background:rgba(255,255,255,.10);
  color:#ddd;
}
.error-box{
  border:1px solid rgba(255,100,100,.35);
  background:rgba(255,80,80,.08);
  border-radius:18px;
  padding:18px;
  margin-top:14px;
}
.error-title{
  font-family:'Orbitron',sans-serif;
  margin-bottom:10px;
}
.error-msg{
  white-space:pre-wrap;
  word-break:break-word;
  font-size:14px;
}
.play-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:74px;
}
.audio-wrap{
  display:flex;
  flex-direction:column;
  gap:8px;
  align-items:flex-start;
}
audio{
  max-width:220px;
  height:32px;
}
.note-warn{
  margin-top:10px;
  font-size:12px;
  opacity:.8;
}
</style>

<script>
function activateTab(name){
  document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById('tab_' + name).classList.add('active');
  document.getElementById('btn_' + name).classList.add('active');
}

function filterTable(inputId, tableId){
  const v = (document.getElementById(inputId).value || '').toLowerCase();
  document.querySelectorAll('#' + tableId + ' tbody tr[data-row]').forEach(tr => {
    const txt = (tr.getAttribute('data-row') || '').toLowerCase();
    tr.style.display = txt.includes(v) ? '' : 'none';
  });
}

function toggleAudio(id){
  const el = document.getElementById(id);
  if (!el) return;
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

window.addEventListener('DOMContentLoaded', () => {
  activateTab('queue_stats');
});
</script>
</head>
<body>

<div class="wrap">

  <div class="topbar">
    <a class="btn" href="/dashboard.php">BACK</a>
    <h1 class="page-title" style="margin:0;font-size:54px;">QUEUE STATS</h1>
    <div class="btn-row">
      <a class="btn" href="/queues.php">QUEUES</a>
      <a class="btn" href="/queue.php">QUEUE MONITOR</a>
    </div>
  </div>

  <form method="get" class="inner-box" style="margin-top:0;">
    <div class="top">
      <span class="pill">FILTERS</span>
      <span class="pill">SOURCE: callcenter.queue_log</span>
    </div>

    <div class="filters-grid" style="margin-top:14px;">
      <div class="field">
        <label>FROM</label>
        <input type="date" name="from" value="<?php echo h($from); ?>">
      </div>

      <div class="field">
        <label>TO</label>
        <input type="date" name="to" value="<?php echo h($to); ?>">
      </div>

      <div class="field">
        <label>QUEUE</label>
        <select name="queue">
          <option value="">ALL QUEUES</option>
          <?php foreach ($queueOptions as $row): ?>
            <?php $q = (string)($row['queuename'] ?? ''); ?>
            <option value="<?php echo h($q); ?>" <?php echo $queueFilter === $q ? 'selected' : ''; ?>>
              <?php echo h($q); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label>AGENT</label>
        <select name="agent">
          <option value="">ALL AGENTS</option>
          <?php foreach ($agentOptions as $row): ?>
            <?php $a = (string)($row['agent'] ?? ''); ?>
            <option value="<?php echo h($a); ?>" <?php echo $agentFilter === $a ? 'selected' : ''; ?>>
              <?php echo h($a); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="btn-row" style="margin-top:18px;">
      <button type="submit" class="btn">SEARCH</button>
      <a class="btn" href="/stats.php">RESET</a>
    </div>

    <?php if ($cdrError !== ''): ?>
      <div class="note-warn">CDR enrichment warning: <?php echo h($cdrError); ?></div>
    <?php endif; ?>
  </form>

  <?php if ($error !== ''): ?>
    <div class="inner-box">
      <div class="error-box">
        <div class="error-title">DATABASE CONNECTION / QUERY ERROR</div>
        <div class="error-msg"><?php echo h($error); ?></div>
      </div>
    </div>
  <?php else: ?>

    <div class="cards">
      <div class="small-card ring-card">
        <div class="ring">
          <div class="ring-inner">
            <div class="ring-title">Total Calls</div>
            <div class="ring-total"><?php echo (int)$totalCalls; ?></div>
          </div>
        </div>

        <div class="split-stats">
          <div>
            <div class="split-line">
              <div class="ok-text">ANSWERED CALLS</div>
              <div style="font-size:22px;font-weight:900;"><?php echo (int)$answeredCalls; ?></div>
            </div>
            <div class="split-line">
              <div class="bad-text">ABANDONED CALLS</div>
              <div style="font-size:22px;font-weight:900;"><?php echo (int)$abandonedCalls; ?></div>
            </div>
          </div>

          <div class="percent-col">
            <div>
              <div class="ok-text" style="font-size:18px;">%</div>
              <div class="percent-big ok-text"><?php echo number_format($answerRate, 2); ?></div>
            </div>
            <div>
              <div class="bad-text" style="font-size:18px;">%</div>
              <div class="percent-big bad-text"><?php echo number_format($abandonRate, 2); ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="small-cards">
        <div class="small-card">
          <div class="k">AVERAGE TALKING TIME</div>
          <div class="v"><?php echo h(fmt_seconds($avgTalkTime)); ?></div>
        </div>
        <div class="small-card">
          <div class="k">AVERAGE WAITING TIME</div>
          <div class="v"><?php echo h(fmt_seconds($avgWaitTime)); ?></div>
        </div>
        <div class="small-card">
          <div class="k">LONGEST BREAK BY</div>
          <div class="v"><?php echo h(fmt_seconds($longestBreakBy['duration'])); ?></div>
          <div class="legend-note"><?php echo h($longestBreakBy['agent']); ?></div>
        </div>
      </div>

      <div class="small-cards">
        <div class="small-card">
          <div class="k">LONGEST CALL</div>
          <div class="v"><?php echo h(fmt_seconds($longestCall)); ?></div>
        </div>
        <div class="small-card">
          <div class="k">SHORTEST CALL</div>
          <div class="v"><?php echo h(fmt_seconds($shortestCall)); ?></div>
        </div>
        <div class="small-card">
          <div class="k">MAXIMUM CALLS BY</div>
          <div class="v"><?php echo (int)$maxCallsQueue['count']; ?> Calls</div>
          <div class="legend-note"><?php echo h($maxCallsQueue['queue']); ?></div>
        </div>
      </div>
    </div>

    <div class="inner-box">
      <div class="top">
        <span class="pill">QUEUE CALLS CHART</span>
      </div>

      <div class="chart-wrap">
        <div class="queue-bars">
          <?php if (!$queueStats): ?>
            <div class="legend-note">No queue data for current filter.</div>
          <?php else: ?>
            <?php foreach ($queueStats as $st): ?>
              <?php $height = $chartMax > 0 ? max(12, round(($st['total_calls'] / $chartMax) * 180)) : 12; ?>
              <div class="bar-col">
                <div class="bar-value"><?php echo (int)$st['total_calls']; ?></div>
                <div class="bar" style="height:<?php echo (int)$height; ?>px;"></div>
                <div class="bar-label"><?php echo h($st['queue']); ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="inner-box">
      <div class="tabs">
        <button type="button" class="btn tab-btn active" id="btn_queue_stats" onclick="activateTab('queue_stats')">Queues statistic</button>
        <button type="button" class="btn tab-btn" id="btn_queue_details" onclick="activateTab('queue_details')">Queue details</button>
        <button type="button" class="btn tab-btn" id="btn_call_details" onclick="activateTab('call_details')">Call details</button>
        <button type="button" class="btn tab-btn" id="btn_login_details" onclick="activateTab('login_details')">Logining details</button>
        <button type="button" class="btn tab-btn" id="btn_pause_details" onclick="activateTab('pause_details')">Pauseing details</button>
      </div>

      <div class="tab-pane active" id="tab_queue_stats">
        <div class="table-tools">
          <label>Search</label>
          <input id="search_queue_stats" type="text" oninput="filterTable('search_queue_stats','table_queue_stats')">
        </div>

        <div class="panel-box">
          <div class="table-wrap">
            <table id="table_queue_stats">
              <thead>
                <tr>
                  <th>QUEUE</th>
                  <th>TOTAL CALLS</th>
                  <th>ANSWERED CALLS</th>
                  <th>ABANDONED CALLS</th>
                  <th>AVG TALK TIME</th>
                  <th>LONGEST TALK TIME</th>
                  <th>SHORTEST TALK TIME</th>
                  <th>AVG WAIT TIME</th>
                  <th>LONGEST WAIT TIME</th>
                  <th>SHORTEST WAIT TIME</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$queueStats): ?>
                <tr><td colspan="10" class="muted">No queue stats found.</td></tr>
              <?php else: ?>
                <?php foreach ($queueStats as $st): ?>
                  <?php $rowText = implode(' ', [$st['queue'], $st['total_calls'], $st['answered_calls'], $st['abandoned_calls']]); ?>
                  <tr data-row="<?php echo h($rowText); ?>">
                    <td><?php echo h($st['queue']); ?></td>
                    <td><?php echo (int)$st['total_calls']; ?></td>
                    <td><?php echo (int)$st['answered_calls']; ?></td>
                    <td><?php echo (int)$st['abandoned_calls']; ?></td>
                    <td><?php echo h(fmt_seconds($st['avg_talk_time'])); ?></td>
                    <td><?php echo h(fmt_seconds($st['longest_talk_time'])); ?></td>
                    <td><?php echo h(fmt_seconds($st['shortest_talk_time'])); ?></td>
                    <td><?php echo h(fmt_seconds($st['avg_wait_time'])); ?></td>
                    <td><?php echo h(fmt_seconds($st['longest_wait_time'])); ?></td>
                    <td><?php echo h(fmt_seconds($st['shortest_wait_time'])); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="tab-pane" id="tab_queue_details">
        <div class="table-tools">
          <label>Search</label>
          <input id="search_queue_details" type="text" oninput="filterTable('search_queue_details','table_queue_details')">
        </div>

        <div class="panel-box">
          <div class="table-wrap">
            <table id="table_queue_details">
              <thead>
                <tr>
                  <th>QUEUE</th>
                  <th>CALLER</th>
                  <th>TIME</th>
                  <th>ABANDONED/LAST POSITION</th>
                  <th>AGENT</th>
                  <th>POSITION</th>
                  <th>WAITING</th>
                  <th>DURATION</th>
                  <th>HOLD</th>
                  <th>HANGUP BY/TRANSFER</th>
                  <th>PLAY CALL</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$queueDetails): ?>
                <tr><td colspan="11" class="muted">No queue details found.</td></tr>
              <?php else: ?>
                <?php foreach ($queueDetails as $i => $row): ?>
                  <?php
                    $rowText = implode(' ', [$row['queue'], $row['caller'], $row['time'], $row['agent'], $row['hangup_by'], $row['status']]);
                    $audioId = 'qa_' . $i;
                  ?>
                  <tr data-row="<?php echo h($rowText); ?>">
                    <td><?php echo h($row['queue']); ?></td>
                    <td><?php echo h($row['caller']); ?></td>
                    <td><?php echo h(fmt_dt($row['time'])); ?></td>
                    <td><?php echo h($row['abandoned_last_position']); ?></td>
                    <td><?php echo h($row['agent']); ?></td>
                    <td><?php echo h($row['position']); ?></td>
                    <td><?php echo h(fmt_seconds($row['waiting'])); ?></td>
                    <td><?php echo h(fmt_seconds($row['duration'])); ?></td>
                    <td><?php echo h($row['hold']); ?></td>
                    <td><?php echo h($row['hangup_by']); ?></td>
                    <td>
                      <?php if (!empty($row['recording_file'])): ?>
                        <div class="audio-wrap">
                          <button type="button" class="btn sm play-btn" onclick="toggleAudio('<?php echo h($audioId); ?>')">PLAY</button>
                          <audio id="<?php echo h($audioId); ?>" controls style="display:none;">
                            <source src="?stream_recording=<?php echo urlencode(recording_token($row['recording_file'])); ?>" type="audio/wav">
                          </audio>
                        </div>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="tab-pane" id="tab_call_details">
        <div class="table-tools">
          <label>Search</label>
          <input id="search_call_details" type="text" oninput="filterTable('search_call_details','table_call_details')">
        </div>

        <div class="panel-box">
          <div class="table-wrap">
            <table id="table_call_details">
              <thead>
                <tr>
                  <th>QUEUE</th>
                  <th>CALLER</th>
                  <th>TIME</th>
                  <th>STATUS/LAST POSITION</th>
                  <th>AGENT</th>
                  <th>POSITION</th>
                  <th>WAITING</th>
                  <th>DURATION</th>
                  <th>HANGUP BY/TRANSFER</th>
                  <th>PLAY CALL</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$callDetails): ?>
                <tr><td colspan="10" class="muted">No call details found.</td></tr>
              <?php else: ?>
                <?php foreach ($callDetails as $i => $row): ?>
                  <?php
                    $rowText = implode(' ', [$row['queue'], $row['caller'], $row['time'], $row['status'], $row['agent'], $row['hangup_by']]);
                    $audioId = 'ca_' . $i;
                  ?>
                  <tr data-row="<?php echo h($rowText); ?>">
                    <td><?php echo h($row['queue']); ?></td>
                    <td><?php echo h($row['caller']); ?></td>
                    <td><?php echo h(fmt_dt($row['time'])); ?></td>
                    <td><span class="badge <?php echo h(badge_class($row['status'])); ?>"><?php echo h(qlog_event_label($row['status'])); ?></span></td>
                    <td><?php echo h($row['agent']); ?></td>
                    <td><?php echo h($row['position']); ?></td>
                    <td><?php echo h(fmt_seconds($row['waiting'])); ?></td>
                    <td><?php echo h(fmt_seconds($row['duration'])); ?></td>
                    <td><?php echo h($row['hangup_by']); ?></td>
                    <td>
                      <?php if (!empty($row['recording_file'])): ?>
                        <div class="audio-wrap">
                          <button type="button" class="btn sm play-btn" onclick="toggleAudio('<?php echo h($audioId); ?>')">PLAY</button>
                          <audio id="<?php echo h($audioId); ?>" controls style="display:none;">
                            <source src="?stream_recording=<?php echo urlencode(recording_token($row['recording_file'])); ?>" type="audio/wav">
                          </audio>
                        </div>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="tab-pane" id="tab_login_details">
        <div class="table-tools">
          <label>Search</label>
          <input id="search_login_details" type="text" oninput="filterTable('search_login_details','table_login_details')">
        </div>

        <div class="panel-box">
          <div class="table-wrap">
            <table id="table_login_details">
              <thead>
                <tr>
                  <th>QUEUE</th>
                  <th>AGENT</th>
                  <th>FIRST LOGIN</th>
                  <th>LAST LOGOUT</th>
                  <th>DURATION</th>
                  <th>STATUS</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$loginDetails): ?>
                <tr><td colspan="6" class="muted">No login sessions found.</td></tr>
              <?php else: ?>
                <?php foreach ($loginDetails as $row): ?>
                  <?php $rowText = implode(' ', [$row['queue'], $row['agent'], $row['login_time'], $row['logout_time'], $row['status']]); ?>
                  <tr data-row="<?php echo h($rowText); ?>">
                    <td><?php echo h($row['queue']); ?></td>
                    <td><?php echo h($row['agent']); ?></td>
                    <td><?php echo h($row['login_time'] ? fmt_dt($row['login_time']) : '-'); ?></td>
                    <td><?php echo h($row['logout_time'] ? fmt_dt($row['logout_time']) : 'Still Logged In'); ?></td>
                    <td><?php echo h(fmt_seconds($row['duration'])); ?></td>
                    <td><?php echo h($row['status']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="tab-pane" id="tab_pause_details">
        <div class="table-tools">
          <label>Search</label>
          <input id="search_pause_details" type="text" oninput="filterTable('search_pause_details','table_pause_details')">
        </div>

        <div class="panel-box">
          <div class="table-wrap">
            <table id="table_pause_details">
              <thead>
                <tr>
                  <th>QUEUE</th>
                  <th>AGENT</th>
                  <th>FIRST PAUSE</th>
                  <th>LAST UNPAUSE</th>
                  <th>DURATION</th>
                  <th>REASON</th>
                  <th>STATUS</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$pauseDetails): ?>
                <tr><td colspan="7" class="muted">No pause sessions found.</td></tr>
              <?php else: ?>
                <?php foreach ($pauseDetails as $row): ?>
                  <?php $rowText = implode(' ', [$row['queue'], $row['agent'], $row['pause_time'], $row['unpause_time'], $row['reason'], $row['status']]); ?>
                  <tr data-row="<?php echo h($rowText); ?>">
                    <td><?php echo h($row['queue']); ?></td>
                    <td><?php echo h($row['agent']); ?></td>
                    <td><?php echo h($row['pause_time'] ? fmt_dt($row['pause_time']) : '-'); ?></td>
                    <td><?php echo h($row['unpause_time'] ? fmt_dt($row['unpause_time']) : 'Still Paused'); ?></td>
                    <td><?php echo h(fmt_seconds($row['duration'])); ?></td>
                    <td><?php echo h($row['reason'] !== '' ? $row['reason'] : '-'); ?></td>
                    <td><?php echo h($row['status']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>

  <?php endif; ?>

</div>

</body>
</html>