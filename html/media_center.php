<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$DB_FILE     = "/etc/asterisk/rcm_media_center.json";
$PROMPT_BASE = "/var/lib/asterisk/sounds/en/rcm/media";
$MOH_BASE    = "/var/lib/asterisk/moh/rcm";

function mc_default_db(){
    return [
        "prompts" => [],
        "moh_classes" => []
    ];
}

function mc_load_db($file){
    if (!file_exists($file)) return mc_default_db();

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return mc_default_db();

    $db = json_decode($raw, true);
    if (!is_array($db)) return mc_default_db();

    if (!isset($db['prompts']) || !is_array($db['prompts'])) $db['prompts'] = [];
    if (!isset($db['moh_classes']) || !is_array($db['moh_classes'])) $db['moh_classes'] = [];

    return $db;
}

function mc_format_bytes($bytes){
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

function mc_read_duration($path){
    if (!is_file($path)) return '-';

    $cmd = "ffprobe -v error -show_entries format=duration -of default=nk=1:nw=1 " . escapeshellarg($path) . " 2>/dev/null";
    $out = trim(shell_exec($cmd) ?? '');
    if ($out === '' || !is_numeric($out)) return '-';

    $sec = (int) round((float)$out);
    $m   = floor($sec / 60);
    $s   = $sec % 60;

    return sprintf('%02d:%02d', $m, $s);
}

function mc_count_tracks($dir){
    if (!is_dir($dir)) return 0;

    $n = 0;
    foreach (scandir($dir) as $f){
        if ($f === '.' || $f === '..') continue;
        if (is_file($dir . '/' . $f) && preg_match('/\.(wav|mp3|gsm|ogg|ulaw|alaw)$/i', $f)) {
            $n++;
        }
    }
    return $n;
}

$db = mc_load_db($DB_FILE);

$q    = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$tab  = trim($_GET['tab'] ?? 'prompts');
if (!in_array($tab, ['prompts', 'moh'], true)) $tab = 'prompts';

$prompts = $db['prompts'];
if ($q !== '') {
    $prompts = array_filter($prompts, function($p) use ($q){
        $hay = strtolower(
            ($p['name'] ?? '') . ' ' .
            ($p['type'] ?? '')
        );
        return strpos($hay, strtolower($q)) !== false;
    });
}
if ($type !== '') {
    $prompts = array_filter($prompts, function($p) use ($type){
        return ($p['type'] ?? '') === $type;
    });
}
usort($prompts, function($a, $b){
    return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$mohClasses = $db['moh_classes'];
usort($mohClasses, function($a, $b){
    return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$flash = trim($_GET['msg'] ?? '');
$error = trim($_GET['err'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Media Center</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/rcm.css">
    <link rel="icon" type="image/png" href="/assets/rcm/logo.png">

    <style>
        body{
            display:block !important;
        }

        .wrap{
            width:min(1250px, 96vw);
            margin:40px auto 60px auto;
        }

        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }

        .topbar .center-title{
            flex:1;
            min-width:220px;
            text-align:center;
            font-family:'Orbitron', sans-serif;
            font-weight:900;
            letter-spacing:5px;
            text-transform:uppercase;
            font-size:40px;
            text-shadow:0 0 18px rgba(255,255,255,.25);
            pointer-events:none;
        }

        .topbar-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .notice{
            margin-bottom:14px;
            padding:12px 14px;
            border-radius:16px;
            font-weight:800;
        }

        .notice.ok{
            background:rgba(56, 197, 117, .15);
            border:1px solid rgba(56, 197, 117, .35);
        }

        .notice.bad{
            background:rgba(255, 90, 90, .12);
            border:1px solid rgba(255, 90, 90, .35);
        }

        .stats{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:12px;
            margin-bottom:16px;
        }

        .stat{
            background:rgba(255,255,255,0.08);
            border:1px solid rgba(255,255,255,0.18);
            border-radius:18px;
            padding:14px 16px;
        }

        .stat .k{
            opacity:.85;
            font-weight:800;
            letter-spacing:1px;
        }

        .stat .v{
            font-family:'Orbitron', sans-serif;
            font-size:26px;
            margin-top:6px;
        }

        .toolbar{
            display:flex;
            align-items:end;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }

        .toolbar form{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:end;
            flex:1;
        }

        .grow{
            flex:1;
            min-width:240px;
        }

        .small-input{
            min-width:150px;
        }

        .tabs{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:16px;
        }

        .tab-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:180px;
            padding:12px 18px;
            border-radius:999px;
            border:1px solid rgba(255,255,255,.18);
            background:rgba(255,255,255,.07);
            color:#fff;
            text-decoration:none;
            font-weight:800;
            letter-spacing:.5px;
            transition:.2s ease;
        }

        .tab-btn:hover{
            transform:translateY(-1px);
            background:rgba(255,255,255,.12);
        }

        .tab-btn.active{
            background:linear-gradient(135deg, rgba(0,174,255,.35), rgba(0,114,255,.22));
            border-color:rgba(102, 194, 255, .55);
            box-shadow:0 0 18px rgba(0,174,255,.18);
        }

        .section-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }

        .sub{
            opacity:.82;
            font-size:13px;
        }

        .acts{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:center;
        }

        .btn.sm{
            padding:9px 12px;
            min-width:auto;
            border-radius:999px;
        }

        .rename-form{
            display:flex;
            gap:6px;
            flex-wrap:wrap;
            align-items:center;
        }

        .rename-form input{
            width:130px;
        }

        .modal-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
        }

        .muted-small{
            opacity:.76;
            font-size:12px;
        }

        audio{
            width:200px;
            max-width:100%;
            height:34px;
        }

        .modal-overlay{
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.55);
            z-index:9998;
            display:none;
            align-items:center;
            justify-content:center;
            padding:20px;
        }

        .modal-overlay.open{
            display:flex;
        }

        .modal-box{
            width:min(760px, 96vw);
            max-height:90vh;
            overflow:auto;
            background:rgba(18, 23, 34, .97);
            border:1px solid rgba(255,255,255,.14);
            border-radius:24px;
            box-shadow:0 30px 80px rgba(0,0,0,.35);
            padding:18px;
        }

        .modal-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
        }

        .modal-title{
            font-family:'Orbitron', sans-serif;
            font-size:22px;
            letter-spacing:2px;
        }

        .modal-form{
            display:grid;
            gap:12px;
        }

        @media (max-width: 980px){
            .topbar .center-title{
                font-size:28px;
            }

            .stats{
                grid-template-columns:1fr 1fr;
            }

            .modal-grid{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 700px){
            .stats{
                grid-template-columns:1fr;
            }

            .topbar{
                justify-content:center;
            }

            .topbar .center-title{
                order:-1;
                width:100%;
            }

            .tab-btn{
                width:100%;
            }
        }
    </style>
</head>
<body>

<div class="wrap">

    <div class="topbar">
        <a class="btn" href="/dashboard.php">BACK</a>

        <div class="center-title">MEDIA CENTER</div>

        <div class="topbar-actions">
            <button type="button" class="btn" onclick="openModal('promptModal')">ADD PROMPT</button>
            <button type="button" class="btn" onclick="openModal('mohModal')">ADD MUSIC ON HOLD</button>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="notice ok"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice bad"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="inner-box">

        <div class="panel-box" style="margin-top:0;">
            <div class="stats">
                <div class="stat">
                    <div class="k">TOTAL PROMPTS</div>
                    <div class="v"><?php echo count($db['prompts']); ?></div>
                </div>
                <div class="stat">
                    <div class="k">IVR / ANN / QUEUE</div>
                    <div class="v"><?php
                        $linkedCount = 0;
                        foreach ($db['prompts'] as $p) {
                            $pt = strtolower($p['type'] ?? '');
                            if (in_array($pt, ['ivr', 'announcement', 'queue'], true)) $linkedCount++;
                        }
                        echo $linkedCount;
                    ?></div>
                </div>
                <div class="stat">
                    <div class="k">MOH CLASSES</div>
                    <div class="v"><?php echo count($db['moh_classes']); ?></div>
                </div>
                <div class="stat">
                    <div class="k">TOTAL TRACKS</div>
                    <div class="v"><?php
                        $trackTotal = 0;
                        foreach ($db['moh_classes'] as $c){
                            $dir = $c['dir'] ?? ($MOH_BASE . '/' . ($c['name'] ?? ''));
                            $trackTotal += mc_count_tracks($dir);
                        }
                        echo $trackTotal;
                    ?></div>
                </div>
            </div>

            <div class="toolbar">
                <form method="get">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">

                    <div class="grow">
                        <label>Search</label>
                        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="name / type">
                    </div>

                    <div>
                        <label>Type</label>
                        <select name="type" class="small-input">
                            <option value="">ALL</option>
                            <option value="ivr" <?php if ($type === 'ivr') echo 'selected'; ?>>IVR</option>
                            <option value="announcement" <?php if ($type === 'announcement') echo 'selected'; ?>>ANNOUNCEMENT</option>
                            <option value="queue" <?php if ($type === 'queue') echo 'selected'; ?>>QUEUE</option>
                            <option value="general" <?php if ($type === 'general') echo 'selected'; ?>>GENERAL</option>
                        </select>
                    </div>

                    <div>
                        <button class="btn" type="submit">FILTER</button>
                    </div>
                </form>
            </div>

            <div class="tabs">
                <a class="tab-btn <?php echo $tab === 'prompts' ? 'active' : ''; ?>"
                   href="?tab=prompts&q=<?php echo urlencode($q); ?>&type=<?php echo urlencode($type); ?>">
                    PROMPTS
                </a>

                <a class="tab-btn <?php echo $tab === 'moh' ? 'active' : ''; ?>"
                   href="?tab=moh&q=<?php echo urlencode($q); ?>&type=<?php echo urlencode($type); ?>">
                    MUSIC ON HOLD
                </a>
            </div>

            <?php if ($tab === 'prompts'): ?>
                <div class="section-head">
                    <div>
                        <div class="pill">PROMPTS LIBRARY</div>
                        <div class="sub">Upload, play, rename, and delete system prompts from one place.</div>
                    </div>
                    <button type="button" class="btn sm" onclick="openModal('promptModal')">+ ADD PROMPT</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Format</th>
                            <th>Duration</th>
                            <th>Size</th>
                            <th>Play</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$prompts): ?>
                        <tr>
                            <td colspan="7" class="muted">No prompts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($prompts as $p):
                            $path = $p['path'] ?? '';
                            $id   = $p['id'] ?? '';
                            $fmt  = strtoupper(pathinfo($path, PATHINFO_EXTENSION));
                            $dur  = mc_read_duration($path);
                            $size = is_file($path) ? mc_format_bytes(filesize($path)) : '-';
                            $web  = '/media_proxy.php?kind=prompt&id=' . urlencode($id);
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($p['name'] ?? '-'); ?></strong>
                                </td>

                                <td>
                                    <span class="pill"><?php echo htmlspecialchars(strtoupper($p['type'] ?? 'GENERAL')); ?></span>
                                </td>

                                <td><?php echo htmlspecialchars($fmt ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($dur); ?></td>
                                <td><?php echo htmlspecialchars($size); ?></td>

                                <td>
                                    <?php if (is_file($path)): ?>
                                        <audio controls preload="none" src="<?php echo htmlspecialchars($web); ?>"></audio>
                                    <?php else: ?>
                                        <span class="muted">Missing file</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="acts">
                                        <form class="rename-form" method="post" action="/rename_prompt.php">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                                            <input type="text" name="new_name" placeholder="new name">
                                            <button class="btn sm" type="submit">RENAME</button>
                                        </form>

                                        <a class="btn sm"
                                           href="/delete_prompt.php?id=<?php echo urlencode($id); ?>"
                                           onclick="return confirm('Delete this prompt?')">
                                            DELETE
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

            <?php else: ?>
                <div class="section-head">
                    <div>
                        <div class="pill">MUSIC ON HOLD CLASSES</div>
                        <div class="sub">Create classes, upload tracks, and open any class to manage its recordings.</div>
                    </div>
                    <button type="button" class="btn sm" onclick="openModal('mohModal')">+ ADD MUSIC ON HOLD</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Class Name</th>
                            <th>Mode</th>
                            <th>Tracks</th>
                            <th>Manage</th>
                            <th>Upload More</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$mohClasses): ?>
                        <tr>
                            <td colspan="6" class="muted">No music on hold classes found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mohClasses as $c):
                            $name  = $c['name'] ?? '';
                            $mode  = $c['mode'] ?? 'files';
                            $dir   = $c['dir'] ?? ($MOH_BASE . '/' . $name);
                            $count = mc_count_tracks($dir);
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                                <td><span class="pill"><?php echo htmlspecialchars($mode); ?></span></td>
                                <td><?php echo (int)$count; ?></td>

                                <td>
                                    <a class="btn sm" href="/moh_tracks.php?class=<?php echo urlencode($name); ?>">
                                        VIEW TRACKS
                                    </a>
                                </td>

                                <td>
                                    <form method="post" action="/save_moh_class.php" enctype="multipart/form-data" class="acts">
                                        <input type="hidden" name="append_to" value="<?php echo htmlspecialchars($name); ?>">
                                        <input type="file" name="tracks[]" accept=".wav,.mp3,.gsm,.ogg" multiple>
                                        <button class="btn sm" type="submit">ADD TRACKS</button>
                                    </form>
                                </td>

                                <td>
                                    <a class="btn sm"
                                       href="/delete_moh_class.php?name=<?php echo urlencode($name); ?>"
                                       onclick="return confirm('Delete this music on hold class and all tracks?')">
                                        DELETE
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
    </div>
</div>

<div class="modal-overlay" id="promptModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">ADD PROMPT</div>
            <button type="button" class="btn sm" onclick="closeModal('promptModal')">CLOSE</button>
        </div>

        <div class="panel-box" style="margin-top:0;">
            <form class="modal-form" method="post" action="/save_prompt.php" enctype="multipart/form-data">
                <div class="modal-grid">
                    <div>
                        <label>Name</label>
                        <input type="text" name="name" required placeholder="support_welcome">
                    </div>

                    <div>
                        <label>Type</label>
                        <select name="type" required>
                            <option value="general">GENERAL</option>
                            <option value="ivr">IVR</option>
                            <option value="announcement">ANNOUNCEMENT</option>
                            <option value="queue">QUEUE</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label>Audio File</label>
                    <input type="file" name="audio" accept=".wav,.mp3,.gsm,.ogg" required>
                </div>

                <div class="muted-small">
                    Use one clean name only. It will be used for display, storage, and linking.
                </div>

                <div class="acts">
                    <button class="btn" type="submit">UPLOAD PROMPT</button>
                    <button type="button" class="btn" onclick="closeModal('promptModal')">CANCEL</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="mohModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">ADD MUSIC ON HOLD</div>
            <button type="button" class="btn sm" onclick="closeModal('mohModal')">CLOSE</button>
        </div>

        <div class="panel-box" style="margin-top:0;">
            <form class="modal-form" method="post" action="/save_moh_class.php" enctype="multipart/form-data">
                <div class="modal-grid">
                    <div>
                        <label>Class Name</label>
                        <input type="text" name="name" required placeholder="support_moh">
                    </div>

                    <div>
                        <label>Playback Mode</label>
                        <select name="mode" required>
                            <option value="files">Manual Order</option>
                            <option value="sortalpha">Alphabetical</option>
                            <option value="random">Random</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label>Tracks</label>
                    <input type="file" name="tracks[]" accept=".wav,.mp3,.gsm,.ogg" multiple>
                </div>

                <div class="muted-small">
                    Create a class first, then open it later to listen, rename, or delete individual tracks.
                </div>

                <div class="acts">
                    <button class="btn" type="submit">CREATE MOH CLASS</button>
                    <button type="button" class="btn" onclick="closeModal('mohModal')">CANCEL</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id){
    var el = document.getElementById(id);
    if (el) el.classList.add('open');
}

function closeModal(id){
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
        closeModal('promptModal');
        closeModal('mohModal');
    }
});

document.querySelectorAll('.modal-overlay').forEach(function(overlay){
    overlay.addEventListener('click', function(e){
        if (e.target === overlay) {
            overlay.classList.remove('open');
        }
    });
});
</script>

</body>
</html>
