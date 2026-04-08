<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$DB_FILE  = "/etc/asterisk/rcm_media_center.json";
$MOH_BASE = "/var/lib/asterisk/moh/rcm";

function load_db($file){
    $db = json_decode(@file_get_contents($file), true);
    if (!is_array($db)) $db = ["prompts"=>[],"moh_classes"=>[]];
    $db['prompts'] = $db['prompts'] ?? [];
    $db['moh_classes'] = $db['moh_classes'] ?? [];
    return $db;
}

function read_duration($path){
    if (!is_file($path)) return '-';
    $cmd = "ffprobe -v error -show_entries format=duration -of default=nk=1:nw=1 " . escapeshellarg($path) . " 2>/dev/null";
    $out = trim(shell_exec($cmd) ?? '');
    if ($out === '' || !is_numeric($out)) return '-';
    $sec = (int) round((float)$out);
    return sprintf('%02d:%02d', floor($sec / 60), $sec % 60);
}

function format_bytes($bytes){
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

$class = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(trim($_GET['class'] ?? '')));
if ($class === '') {
    header("Location: /media_center.php?tab=moh&err=" . urlencode("Missing class name"));
    exit;
}

$db = load_db($DB_FILE);

$found = null;
foreach (($db['moh_classes'] ?? []) as $c) {
    if (($c['name'] ?? '') === $class) {
        $found = $c;
        break;
    }
}

if (!$found) {
    header("Location: /media_center.php?tab=moh&err=" . urlencode("MOH class not found"));
    exit;
}

$dir = $found['dir'] ?? ($MOH_BASE . '/' . $class);
$tracks = [];

if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        if (!is_file($path)) continue;
        if (!preg_match('/\.(wav|mp3|gsm|ogg|ulaw|alaw)$/i', $f)) continue;

        $tracks[] = [
            'name' => $f,
            'size' => format_bytes(filesize($path)),
            'duration' => read_duration($path),
            'path' => $path,
        ];
    }
}

usort($tracks, function($a, $b){
    return strnatcasecmp($a['name'], $b['name']);
});

$msg = trim($_GET['msg'] ?? '');
$err = trim($_GET['err'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MOH Tracks - <?php echo htmlspecialchars($class); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/rcm.css">
    <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
    <style>
        body{ display:block !important; }
        .wrap{ width:min(1200px, 96vw); margin:30px auto 60px auto; }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:16px;
        }
        .title{
            font-family:'Orbitron', sans-serif;
            font-size:34px;
            letter-spacing:3px;
            text-transform:uppercase;
        }
        .sub{ opacity:.82; margin-top:6px; }
        .notice{
            margin-bottom:14px;
            padding:12px 14px;
            border-radius:14px;
            font-weight:800;
        }
        .ok{ background:rgba(56,197,117,.15); border:1px solid rgba(56,197,117,.35); }
        .bad{ background:rgba(255,90,90,.12); border:1px solid rgba(255,90,90,.35); }
        .acts{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .btn.sm{ padding:8px 12px; min-width:auto; border-radius:999px; }
        audio{ width:200px; max-width:100%; height:34px; }
        .rename-form{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .rename-form input{ width:150px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <div class="title">MOH TRACKS</div>
            <div class="sub">Class: <strong><?php echo htmlspecialchars($class); ?></strong> | Mode: <strong><?php echo htmlspecialchars($found['mode'] ?? 'files'); ?></strong></div>
        </div>

        <div class="acts">
            <a class="btn" href="/media_center.php?tab=moh">BACK TO MEDIA CENTER</a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="notice ok"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div class="notice bad"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <div class="inner-box">
        <div class="section-title" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
                <div class="pill">ADD TRACKS</div>
                <div class="sub">ارفع ملفات جديدة للكلاس ده من هنا.</div>
            </div>
        </div>

        <div class="panel-box" style="margin-bottom:16px;">
            <form method="post" action="/save_moh_class.php" enctype="multipart/form-data" class="acts">
                <input type="hidden" name="append_to" value="<?php echo htmlspecialchars($class); ?>">
                <input type="file" name="tracks[]" accept=".wav,.mp3,.gsm,.ogg" multiple>
                <button class="btn" type="submit">ADD TRACKS</button>
            </form>
        </div>

        <div class="section-title" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
                <div class="pill">TRACKS</div>
                <div class="sub">تقدر تسمع وتغير اسم وتمسح أي تراك من هنا.</div>
            </div>
        </div>

        <div class="panel-box">
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Duration</th>
                        <th>Size</th>
                        <th>Play</th>
                        <th>Rename</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$tracks): ?>
                    <tr>
                        <td colspan="6" class="muted">No tracks found in this class.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tracks as $t): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($t['duration']); ?></td>
                            <td><?php echo htmlspecialchars($t['size']); ?></td>
                            <td>
                                <audio controls preload="none" src="/media_proxy.php?kind=moh_track&class=<?php echo urlencode($class); ?>&file=<?php echo urlencode($t['name']); ?>"></audio>
                            </td>
                            <td>
                                <form class="rename-form" method="post" action="/rename_moh_track.php">
                                    <input type="hidden" name="class" value="<?php echo htmlspecialchars($class); ?>">
                                    <input type="hidden" name="old_file" value="<?php echo htmlspecialchars($t['name']); ?>">
                                    <input type="text" name="new_name" placeholder="new name">
                                    <button class="btn sm" type="submit">RENAME</button>
                                </form>
                            </td>
                            <td>
                                <a class="btn sm"
                                   href="/delete_moh_track.php?class=<?php echo urlencode($class); ?>&file=<?php echo urlencode($t['name']); ?>"
                                   onclick="return confirm('Delete this track?')">
                                    DELETE
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
