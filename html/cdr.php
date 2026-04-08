<?php
// /var/www/html/cdr.php

date_default_timezone_set('Africa/Cairo');

/* =========================================================
   CONFIG
========================================================= */
$dbHost = "127.0.0.1";
$dbName = "asteriskcdr";
$dbUser = "dexter";
$dbPass = "admin";

$PER_PAGE = 50;
$RECORD_BASE_DIR = "/var/spool/asterisk/monitor";
$CEL_CSV_FILE    = "/var/log/asterisk/cel-custom/Master.csv";

/* =========================================================
   DB
========================================================= */
try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB Connection Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

/* =========================================================
   HELPERS
========================================================= */
function g(string $k, string $default=''): string {
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $default;
}

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function hms($sec): string {
    $sec = max(0, (int)$sec);
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    $s = $sec % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function qs_with(array $arr): string {
    $base = $_GET;
    foreach ($arr as $k => $v) {
        if ($v === null) unset($base[$k]);
        else $base[$k] = $v;
    }
    return http_build_query($base);
}

function badge_class(string $disp): string {
    $d = strtoupper(trim($disp));
    return match ($d) {
        'ANSWERED'  => 'ok',
        'BUSY'      => 'warn',
        'FAILED'    => 'bad',
        'NO ANSWER' => 'muted',
        default     => 'muted',
    };
}

function status_icon_html(string $disp): string {
    $d = strtoupper(trim($disp));

    return match ($d) {
        'ANSWERED'  => '<span class="st-ico ok">✓</span>',
        'BUSY'      => '<span class="st-ico warn">!</span>',
        'FAILED'    => '<span class="st-ico bad">✕</span>',
        'NO ANSWER' => '<span class="st-ico muted">−</span>',
        default     => '<span class="st-ico muted">•</span>',
    };
}

function read_tail_lines(string $file, int $maxLines = 12000): array {
    if (!is_file($file) || !is_readable($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    if (count($lines) <= $maxLines) return $lines;
    return array_slice($lines, -$maxLines);
}

function parse_csv_line_safe(string $line): array {
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $line);
    rewind($fp);
    $row = fgetcsv($fp);
    fclose($fp);
    return is_array($row) ? $row : [];
}

function normalize_stage_label(string $label): string {
    $label = trim($label);
    $label = preg_replace('/\s+/', ' ', $label);
    return $label;
}

function unique_stage_push(array &$stages, string $label): void {
    $label = normalize_stage_label($label);
    if ($label === '') return;
    if (!in_array($label, $stages, true)) {
        $stages[] = $label;
    }
}

function maybe_extract_ext_from_dial(string $dial): string {
    if (preg_match('/PJSIP\/([0-9A-Za-z_\-]+)/', $dial, $m)) {
        return $m[1];
    }
    return '';
}

function recursive_find_recording(string $baseDir, array $tokens): ?string {
    if (!is_dir($baseDir)) return null;

    $tokens = array_values(array_filter(array_unique(array_map(function($v){
        return trim((string)$v);
    }, $tokens))));

    if (!$tokens) return null;

    $exts = ['wav','WAV','mp3','MP3','gsm','GSM','ogg','OGG'];
    $found = [];

    foreach ($tokens as $token) {
        foreach ($exts as $ext) {
            $patterns = [
                rtrim($baseDir, '/') . '/*' . $token . '*.' . $ext,
                rtrim($baseDir, '/') . '/*/*' . $token . '*.' . $ext,
                rtrim($baseDir, '/') . '/*/*/*' . $token . '*.' . $ext,
            ];
            foreach ($patterns as $p) {
                $matches = glob($p);
                if ($matches) {
                    foreach ($matches as $m) {
                        if (is_file($m)) $found[] = $m;
                    }
                }
            }
        }
    }

    if (!$found) return null;

    usort($found, fn($a,$b) => filemtime($b) <=> filemtime($a));
    return $found[0] ?? null;
}

function detect_recording_tokens(array $row): array {
    $tokens = [];

    $src      = trim((string)($row['src'] ?? ''));
    $dst      = trim((string)($row['dst'] ?? ''));
    $calldate = trim((string)($row['calldate'] ?? ''));
    $userfield = trim((string)($row['userfield'] ?? ''));
    $uniqueid  = trim((string)($row['uniqueid'] ?? ''));

    if ($uniqueid !== '') {
        $tokens[] = $uniqueid;
    }

    if ($userfield !== '') {
        $tokens[] = $userfield;
        if (preg_match_all('/[A-Za-z0-9._-]+/', $userfield, $m)) {
            foreach ($m[0] as $t) {
                if (strlen($t) >= 6) $tokens[] = $t;
            }
        }
    }

    if ($src !== '' && $dst !== '' && $calldate !== '') {
        $ts = strtotime($calldate);
        if ($ts) {
            for ($delta = -20; $delta <= 20; $delta++) {
                $stamp = date('Ymd-His', $ts + $delta);
                $tokens[] = $dst . '-' . $src . '-' . $stamp;
                $tokens[] = $src . '-' . $dst . '-' . $stamp;
            }
        }
    }

    return array_values(array_unique(array_filter($tokens)));
}

function recording_mime(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'gsm' => 'audio/gsm',
        'ogg' => 'audio/ogg',
        default => 'application/octet-stream',
    };
}

/* =========================================================
   FILTERS
========================================================= */
$from   = g('from');
$to     = g('to');
$src    = g('src');
$dst    = g('dst');
$clid   = g('clid');
$disp   = g('disp');
$minDur = g('mindur');
$maxDur = g('maxdur');
$q      = g('q');
$page   = max(1, (int)g('page', '1'));
$offset = ($page - 1) * $PER_PAGE;

$where = [];
$params = [];

if ($from !== '') {
    $where[] = "calldate >= :from_dt";
    $params[':from_dt'] = $from . " 00:00:00";
}
if ($to !== '') {
    $where[] = "calldate <= :to_dt";
    $params[':to_dt'] = $to . " 23:59:59";
}
if ($src !== '') {
    $where[] = "src LIKE :src";
    $params[':src'] = "%{$src}%";
}
if ($dst !== '') {
    $where[] = "dst LIKE :dst";
    $params[':dst'] = "%{$dst}%";
}
if ($clid !== '') {
    $where[] = "clid LIKE :clid";
    $params[':clid'] = "%{$clid}%";
}
if ($disp !== '') {
    $where[] = "disposition = :disp";
    $params[':disp'] = $disp;
}
if ($minDur !== '' && ctype_digit($minDur)) {
    $where[] = "duration >= :mindur";
    $params[':mindur'] = (int)$minDur;
}
if ($maxDur !== '' && ctype_digit($maxDur)) {
    $where[] = "duration <= :maxdur";
    $params[':maxdur'] = (int)$maxDur;
}
if ($q !== '') {
    $where[] = "(src LIKE :q OR dst LIKE :q OR clid LIKE :q OR uniqueid LIKE :q OR dcontext LIKE :q OR lastapp LIKE :q OR lastdata LIKE :q)";
    $params[':q'] = "%{$q}%";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* =========================================================
   EXPORT CSV
========================================================= */
if (g('export') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cdr_export.csv"');

    $sql = "
        SELECT calldate, clid, src, dst, dcontext, lastapp, lastdata, duration, billsec, disposition, uniqueid, userfield
        FROM cdr
        {$whereSql}
        ORDER BY calldate DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = fopen('php://output', 'w');
    fputcsv($out, ['calldate','clid','src','dst','dcontext','lastapp','lastdata','duration','billsec','disposition','uniqueid','userfield']);
    while ($r = $st->fetch()) {
        fputcsv($out, [
            $r['calldate'] ?? '',
            $r['clid'] ?? '',
            $r['src'] ?? '',
            $r['dst'] ?? '',
            $r['dcontext'] ?? '',
            $r['lastapp'] ?? '',
            $r['lastdata'] ?? '',
            $r['duration'] ?? '',
            $r['billsec'] ?? '',
            $r['disposition'] ?? '',
            $r['uniqueid'] ?? '',
            $r['userfield'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* =========================================================
   PLAY RECORDING
========================================================= */
if (g('action') === 'play') {
    $uniqueid = g('uniqueid');
    if ($uniqueid === '') {
        http_response_code(404);
        exit('Recording not found');
    }

    $st = $pdo->prepare("
        SELECT calldate, src, dst, uniqueid, userfield
        FROM cdr
        WHERE uniqueid = :uid
        ORDER BY calldate DESC
        LIMIT 1
    ");
    $st->execute([':uid' => $uniqueid]);
    $row = $st->fetch();

    if (!$row) {
        http_response_code(404);
        exit('Recording not found');
    }

    $recPath = recursive_find_recording($RECORD_BASE_DIR, detect_recording_tokens($row));
    if (!$recPath || !is_file($recPath)) {
        http_response_code(404);
        exit('Recording not found');
    }

    header('Content-Type: ' . recording_mime($recPath));
    header('Content-Length: ' . filesize($recPath));
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="' . basename($recPath) . '"');
    readfile($recPath);
    exit;
}

/* =========================================================
   DETAILS AJAX
========================================================= */
if (g('action') === 'details') {
    $uniqueid = g('uniqueid');

    if ($uniqueid === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'html' => '<div class="detail-error">Missing uniqueid.</div>']);
        exit;
    }

    $st = $pdo->prepare("
        SELECT calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata,
               duration, billsec, disposition, amaflags, accountcode, uniqueid, userfield
        FROM cdr
        WHERE uniqueid = :uid
        ORDER BY calldate ASC
    ");
    $st->execute([':uid' => $uniqueid]);
    $callRows = $st->fetchAll();

    if (!$callRows) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'html' => '<div class="detail-error">Call not found.</div>']);
        exit;
    }

    $main = $callRows[0];
    $last = $callRows[count($callRows) - 1];

    $journey = [];

    $celLines = read_tail_lines($GLOBALS['CEL_CSV_FILE'], 12000);
    foreach ($celLines as $line) {
        if (strpos($line, $uniqueid) === false) continue;

        $c = parse_csv_line_safe($line);
        if (count($c) < 16) continue;

        $eventtype = trim((string)($c[0] ?? ''));
        $context   = trim((string)($c[8] ?? ''));
        $appname   = trim((string)($c[10] ?? ''));
        $appdata   = trim((string)($c[11] ?? ''));
        $csv_uid   = trim((string)($c[14] ?? ''));

        if ($csv_uid !== $uniqueid) continue;

        if ($context !== '' && stripos($context, 'ivr-') === 0) {
            if (preg_match('/^ivr-([0-9A-Za-z_\-]+)/', $context, $m)) {
                unique_stage_push($journey, "IVR[" . $m[1] . "]");
            } else {
                unique_stage_push($journey, "IVR");
            }
        }

        if (strcasecmp($appname, 'Queue') === 0) {
            $qname = trim($appdata);
            unique_stage_push($journey, $qname !== '' ? "QUEUE[" . $qname . "]" : "QUEUE");
        }

        if (strcasecmp($appname, 'Dial') === 0) {
            $ext = maybe_extract_ext_from_dial($appdata);
            unique_stage_push($journey, $ext !== '' ? "DIAL[" . $ext . "]" : "DIAL");
        }

        if ($context !== '' && stripos($context, 'recording') !== false) {
            unique_stage_push($journey, "RECORDING");
        }

        if ($eventtype === 'ANSWER' && !$journey) {
            if ($context !== '' && preg_match('/^ivr-([0-9A-Za-z_\-]+)/', $context, $m)) {
                unique_stage_push($journey, "IVR[" . $m[1] . "]");
            }
        }
    }

    if (!$journey) {
        foreach ($callRows as $r) {
            $ctx = trim((string)($r['dcontext'] ?? ''));
            $app = trim((string)($r['lastapp'] ?? ''));
            $data = trim((string)($r['lastdata'] ?? ''));

            if ($ctx !== '' && stripos($ctx, 'ivr-') === 0) {
                if (preg_match('/^ivr-([0-9A-Za-z_\-]+)/', $ctx, $m)) {
                    unique_stage_push($journey, "IVR[" . $m[1] . "]");
                } else {
                    unique_stage_push($journey, "IVR");
                }
            }

            if (strcasecmp($app, 'Queue') === 0) {
                unique_stage_push($journey, $data !== '' ? "QUEUE[" . $data . "]" : "QUEUE");
            }

            if (strcasecmp($app, 'Dial') === 0) {
                $ext = maybe_extract_ext_from_dial($data);
                unique_stage_push($journey, $ext !== '' ? "DIAL[" . $ext . "]" : "DIAL");
            }

            if ($ctx !== '' && stripos($ctx, 'recording') !== false) {
                unique_stage_push($journey, "RECORDING");
            }
        }
    }

    if (!$journey) {
        $ctx = trim((string)($last['dcontext'] ?? ''));
        $app = trim((string)($last['lastapp'] ?? ''));
        if ($ctx !== '') unique_stage_push($journey, strtoupper($ctx));
        if ($app !== '') unique_stage_push($journey, strtoupper($app));
    }

    $recPath = recursive_find_recording($GLOBALS['RECORD_BASE_DIR'], detect_recording_tokens($last));
    $recUrl = $recPath ? ('?action=play&uniqueid=' . rawurlencode($uniqueid)) : null;

    ob_start();
    ?>
    <div class="detail-wrap">
      <div class="detail-grid">
        <div class="detail-card">
          <div class="detail-title">Call Info</div>
          <div class="detail-line"><span>Caller ID</span><b><?=esc($last['clid'] ?: '-')?></b></div>
          <div class="detail-line"><span>Call From</span><b><?=esc($last['src'] ?: '-')?></b></div>
          <div class="detail-line"><span>Call To</span><b><?=esc($last['dst'] ?: '-')?></b></div>
          <div class="detail-line"><span>Start Time</span><b><?=esc($main['calldate'] ?: '-')?></b></div>
          <div class="detail-line"><span>Status</span><b><?=esc($last['disposition'] ?: '-')?></b></div>
          <div class="detail-line"><span>Call Time</span><b><?=hms((int)($last['duration'] ?? 0))?></b></div>
          <div class="detail-line"><span>Talk Time</span><b><?=hms((int)($last['billsec'] ?? 0))?></b></div>
        </div>

        <div class="detail-card">
          <div class="detail-title">Technical</div>
          <div class="detail-line"><span>Context</span><b><?=esc($last['dcontext'] ?: '-')?></b></div>
          <div class="detail-line"><span>Action Type</span><b><?=esc($last['lastapp'] ?: '-')?></b></div>
          <div class="detail-line"><span>Action Data</span><b><?=esc($last['lastdata'] ?: '-')?></b></div>
          <div class="detail-line"><span>Channel</span><b><?=esc($last['channel'] ?: '-')?></b></div>
          <div class="detail-line"><span>DstChannel</span><b><?=esc($last['dstchannel'] ?: '-')?></b></div>
          <div class="detail-line"><span>UniqueID</span><b class="mono"><?=esc($last['uniqueid'] ?: '-')?></b></div>
          <?php if (!empty($last['accountcode'])): ?>
            <div class="detail-line"><span>Account Code</span><b><?=esc($last['accountcode'])?></b></div>
          <?php endif; ?>
          <?php if (!empty($last['userfield'])): ?>
            <div class="detail-line"><span>User Field</span><b><?=esc($last['userfield'])?></b></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="detail-card" style="margin-top:14px;">
        <div class="detail-title">Call Journey</div>
        <?php if ($journey): ?>
          <div class="journey-chain">
            <?php foreach ($journey as $i => $stage): ?>
              <span class="journey-pill"><?=esc($stage)?></span>
              <?php if ($i < count($journey) - 1): ?>
                <span class="journey-arrow">→</span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="detail-empty">No journey data found.</div>
        <?php endif; ?>
      </div>

      <div class="detail-card" style="margin-top:14px;">
        <div class="detail-title">Recording</div>
        <?php if ($recUrl): ?>
          <audio controls preload="none" style="width:100%;">
            <source src="<?=esc($recUrl)?>">
          </audio>
          <div class="detail-note"><?=esc(basename($recPath))?></div>
        <?php else: ?>
          <div class="detail-empty">No recording found for this call.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'html' => ob_get_clean(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* =========================================================
   COUNT DISTINCT CALLS
========================================================= */
$countSql = "SELECT COUNT(DISTINCT uniqueid) AS c FROM cdr {$whereSql}";
$st = $pdo->prepare($countSql);
$st->execute($params);
$total = (int)($st->fetch()['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

/* =========================================================
   LIST DISTINCT CALLS
========================================================= */
$sql = "
    SELECT c.*
    FROM cdr c
    INNER JOIN (
        SELECT uniqueid, MAX(calldate) AS max_calldate
        FROM cdr
        {$whereSql}
        GROUP BY uniqueid
        ORDER BY max_calldate DESC
        LIMIT :limit OFFSET :offset
    ) x
      ON c.uniqueid = x.uniqueid
     AND c.calldate = x.max_calldate
    ORDER BY c.calldate DESC
";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':limit', $PER_PAGE, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

foreach ($rows as &$r) {
    $r['_rec'] = recursive_find_recording($RECORD_BASE_DIR, detect_recording_tokens($r));
}
unset($r);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>CDR</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

  <style>
    html, body { height:auto !important; }
    body{
      display:block !important;
      align-items:initial !important;
      justify-content:initial !important;
    }

    .wrap{
      width:min(1450px, 96vw);
      margin:0 auto 60px auto;
    }

    .card{
      background: rgba(0,0,0,0.22);
      border: 2px solid rgba(255,255,255,0.10);
      border-radius: 22px;
      padding: 22px;
      margin-bottom: 14px;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 10px 25px rgba(0,0,0,0.25);
    }

    .topbar{
      display:flex;
      gap:12px;
      align-items:center;
      justify-content:space-between;
      flex-wrap:wrap;
      margin-bottom:14px;
    }

    .row{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:end;
    }

    input, select{
      width:auto;
      padding:12px 14px;
      border-radius:40px;
      border:1px solid rgba(255,255,255,0.25);
      background:rgba(255,255,255,0.10);
      color:#fff;
      outline:none;
    }

    input{ min-width:140px; }
    input[type="date"]{ min-width:170px; }

    select{ color-scheme: dark; }
    select option{ background:#2f5875; color:#fff; }

    .muted{ opacity:.85; }
    .tiny{ font-size:12px; }
    .nowrap{ white-space:nowrap; }
    .mono{
      font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }

    .pill{
      display:inline-block;
      padding:6px 12px;
      border-radius:20px;
      border:1px solid rgba(255,255,255,.35);
      background:rgba(255,255,255,.10);
      font-size:12px;
    }
    .pill.ok{ background:rgba(0,180,90,.18); border-color:rgba(0,180,90,.45); }
    .pill.warn{ background:rgba(255,165,0,.18); border-color:rgba(255,165,0,.45); }
    .pill.bad{ background:rgba(220,40,40,.18); border-color:rgba(220,40,40,.45); }
    .pill.muted{ background:rgba(255,255,255,.10); border-color:rgba(255,255,255,.25); }

    .summary-bar{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:10px;
    }

    .table-wrap{
      overflow:auto;
    }

    table{
      width:100%;
      border-collapse:separate;
      border-spacing:0;
    }

    th, td{
      border-bottom:1px solid rgba(255,255,255,0.14);
      padding:12px 10px;
      text-align:left;
      font-size:13px;
      vertical-align:middle;
    }

    th{
      background: rgba(255,255,255,0.10);
      font-family:'Orbitron',sans-serif;
      letter-spacing:1px;
      position:sticky;
      top:0;
      z-index:2;
    }

    .cdr-row:hover{
      background: rgba(255,255,255,0.05);
    }

    .expander{
      width:36px;
      text-align:center;
    }

    .expand-btn{
      border:none;
      background:transparent;
      color:#fff;
      cursor:pointer;
      font-size:15px;
      line-height:1;
      opacity:.9;
    }

    .st-ico{
      display:inline-flex;
      width:18px;
      height:18px;
      align-items:center;
      justify-content:center;
      border-radius:50%;
      font-size:12px;
      font-weight:900;
      margin-right:8px;
      border:1px solid rgba(255,255,255,.20);
    }
    .st-ico.ok{
      background:rgba(0,180,90,.18);
      color:#7CFFB2;
    }
    .st-ico.warn{
      background:rgba(255,165,0,.18);
      color:#FFD27A;
    }
    .st-ico.bad{
      background:rgba(220,40,40,.18);
      color:#FF8C8C;
    }
    .st-ico.muted{
      background:rgba(255,255,255,.10);
      color:#D5DCE5;
    }

    .rec-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:34px;
      height:34px;
      padding:0 10px;
      border-radius:999px;
      text-decoration:none;
      border:1px solid rgba(255,255,255,.22);
      font-size:12px;
      font-weight:900;
      letter-spacing:.5px;
    }

    .rec-btn.has-rec{
      background:rgba(0,180,90,.18);
      color:#7CFFB2;
    }

    .rec-btn.no-rec{
      background:rgba(220,40,40,.18);
      color:#FF8C8C;
    }

    .rec-btn.disabled{
      opacity:.9;
      pointer-events:none;
      cursor:default;
    }

    .detail-row{
      display:none;
      background: rgba(255,255,255,.04);
    }

    .detail-row.open{
      display:table-row;
    }

    .detail-cell{
      padding:0 !important;
      border-bottom:1px solid rgba(255,255,255,0.18);
    }

    .loading-box{
      padding:18px;
      opacity:.85;
    }

    .detail-wrap{
      padding:16px;
      background: rgba(255,255,255,0.03);
    }

    .detail-grid{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap:14px;
    }

    .detail-card{
      background: rgba(0,0,0,.18);
      border:1px solid rgba(255,255,255,.12);
      border-radius:16px;
      padding:14px;
    }

    .detail-title{
      font-family:'Orbitron',sans-serif;
      font-size:14px;
      margin-bottom:10px;
      letter-spacing:1px;
      text-transform:uppercase;
    }

    .detail-line{
      display:flex;
      gap:10px;
      justify-content:space-between;
      align-items:flex-start;
      padding:6px 0;
      border-bottom:1px dashed rgba(255,255,255,.08);
    }

    .detail-line:last-child{
      border-bottom:none;
    }

    .detail-line span{
      opacity:.8;
      min-width:110px;
    }

    .detail-line b{
      text-align:right;
      word-break:break-word;
    }

    .journey-chain{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
    }

    .journey-pill{
      display:inline-block;
      padding:8px 12px;
      border-radius:999px;
      background:rgba(255,255,255,.08);
      border:1px solid rgba(255,255,255,.16);
      font-weight:700;
      font-size:12px;
    }

    .journey-arrow{
      opacity:.7;
      font-weight:900;
    }

    .detail-empty, .detail-note, .detail-error{
      opacity:.8;
      font-size:13px;
    }

    .pager{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      margin-top:16px;
    }

    @media (max-width: 980px){
      th:nth-child(5), td:nth-child(5),
      th:nth-child(8), td:nth-child(8){
        display:none;
      }
    }
  </style>
</head>
<body>

<div class="wrap">
  <h1 class="page-title">CDR</h1>

  <div class="inner-box" style="margin-top:0;">
    <div class="topbar">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn sm" href="/dashboard.php">BACK</a>
      </div>
    </div>

    <div class="card">
      <form method="get">
        <div class="row">
          <div>
            <div class="muted tiny">From</div>
            <input type="date" name="from" value="<?=esc($from)?>">
          </div>
          <div>
            <div class="muted tiny">To</div>
            <input type="date" name="to" value="<?=esc($to)?>">
          </div>
          <div>
            <div class="muted tiny">SRC</div>
            <input name="src" placeholder="770" value="<?=esc($src)?>">
          </div>
          <div>
            <div class="muted tiny">DST</div>
            <input name="dst" placeholder="100" value="<?=esc($dst)?>">
          </div>
          <div>
            <div class="muted tiny">CLID</div>
            <input name="clid" placeholder="Caller ID" value="<?=esc($clid)?>">
          </div>
          <div>
            <div class="muted tiny">Disposition</div>
            <select name="disp">
              <option value="">All</option>
              <?php foreach (['ANSWERED','NO ANSWER','BUSY','FAILED'] as $d): ?>
                <option value="<?=$d?>" <?=($disp===$d?'selected':'')?> ><?=$d?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <div class="muted tiny">Min Dur</div>
            <input name="mindur" style="width:90px" value="<?=esc($minDur)?>">
          </div>
          <div>
            <div class="muted tiny">Max Dur</div>
            <input name="maxdur" style="width:90px" value="<?=esc($maxDur)?>">
          </div>
          <div>
            <div class="muted tiny">Search</div>
            <input name="q" placeholder="free text" value="<?=esc($q)?>">
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn" type="submit">Apply</button>
            <a class="btn btn2" href="?<?=esc(qs_with(['export'=>'1','page'=>null]))?>">Export CSV</a>
            <a class="btn btn2" href="cdr.php">Reset</a>
          </div>
        </div>

        <div class="summary-bar">
          <span class="pill">Total: <?= (int)$total ?> calls</span>
          <span class="pill">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="expander"></th>
              <th>Status</th>
              <th>Call From</th>
              <th>Call To</th>
              <th>Action Type</th>
              <th>Start Time</th>
              <th>Call Time</th>
              <th>Talk Time</th>
              <th>Account Code</th>
              <th>Options</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="10" class="muted">No results</td></tr>
            <?php else: foreach ($rows as $i => $r): ?>
              <?php
                $rowId = 'cdr_' . $i;
                $recUrl = $r['_rec'] ? ('?action=play&uniqueid=' . rawurlencode((string)$r['uniqueid'])) : '#';
              ?>
              <tr class="cdr-row">
                <td class="expander">
                  <button
                    type="button"
                    class="expand-btn"
                    data-detail-id="<?=esc($rowId)?>"
                    data-uniqueid="<?=esc((string)$r['uniqueid'])?>"
                    aria-label="Expand details">▾</button>
                </td>
                <td class="nowrap">
                  <?= status_icon_html((string)$r['disposition']) ?>
                  <span class="pill <?=esc(badge_class((string)$r['disposition']))?>"><?=esc((string)$r['disposition'])?></span>
                </td>
                <td>
                  <div><?=esc($r['clid'] ?: $r['src'])?></div>
                  <?php if (!empty($r['src'])): ?>
                    <div class="muted tiny"><?=esc($r['src'])?></div>
                  <?php endif; ?>
                </td>
                <td><?=esc($r['dst'])?></td>
                <td><?=esc($r['lastapp'] ?: '-')?></td>
                <td class="nowrap"><?=esc($r['calldate'])?></td>
                <td><?=hms((int)$r['duration'])?></td>
                <td><?=hms((int)$r['billsec'])?></td>
                <td><?=esc($r['accountcode'] ?: '-')?></td>
                <td class="nowrap">
                  <?php if ($r['_rec']): ?>
                    <a class="rec-btn has-rec" href="<?=esc($recUrl)?>" target="_blank" title="Play recording">REC</a>
                  <?php else: ?>
                    <span class="rec-btn no-rec disabled" title="No recording">✕</span>
                  <?php endif; ?>
                </td>
              </tr>

              <tr class="detail-row" id="<?=esc($rowId)?>">
                <td colspan="10" class="detail-cell">
                  <div class="loading-box">Loading details...</div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pager">
        <?php
          $start = max(1, $page - 4);
          $end = min($totalPages, $page + 4);

          if ($page > 1) {
            echo '<a class="btn btn2" href="?' . esc(qs_with(['page'=>$page-1])) . '">Prev</a>';
          }
          if ($start > 1) {
            echo '<a class="btn btn2" href="?' . esc(qs_with(['page'=>1])) . '">1</a>';
            if ($start > 2) echo '<span class="muted" style="padding:10px 6px">…</span>';
          }
          for ($p=$start; $p<=$end; $p++) {
            if ($p === $page) echo '<span class="btn btn2" style="background:rgba(255,255,255,0.10)">' . $p . '</span>';
            else echo '<a class="btn btn2" href="?' . esc(qs_with(['page'=>$p])) . '">' . $p . '</a>';
          }
          if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="muted" style="padding:10px 6px">…</span>';
            echo '<a class="btn btn2" href="?' . esc(qs_with(['page'=>$totalPages])) . '">' . $totalPages . '</a>';
          }
          if ($page < $totalPages) {
            echo '<a class="btn btn2" href="?' . esc(qs_with(['page'=>$page+1])) . '">Next</a>';
          }
        ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('click', async function(e){
  const btn = e.target.closest('.expand-btn');
  if (!btn) return;

  const detailId = btn.getAttribute('data-detail-id');
  const uniqueid = btn.getAttribute('data-uniqueid') || '';
  const row = document.getElementById(detailId);
  if (!row) return;

  const isOpen = row.classList.contains('open');

  document.querySelectorAll('.detail-row.open').forEach(function(r){
    if (r !== row) r.classList.remove('open');
  });

  if (isOpen) {
    row.classList.remove('open');
    return;
  }

  row.classList.add('open');

  if (row.dataset.loaded === '1') return;

  row.querySelector('.detail-cell').innerHTML = '<div class="loading-box">Loading details...</div>';

  try {
    const res = await fetch('?action=details&uniqueid=' + encodeURIComponent(uniqueid), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (data && data.ok) {
      row.querySelector('.detail-cell').innerHTML = data.html;
      row.dataset.loaded = '1';
    } else {
      row.querySelector('.detail-cell').innerHTML = '<div class="detail-wrap"><div class="detail-error">Failed to load details.</div></div>';
    }
  } catch (err) {
    row.querySelector('.detail-cell').innerHTML = '<div class="detail-wrap"><div class="detail-error">Error loading details.</div></div>';
  }
});
</script>

</body>
</html>