<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

const RCM_QUEUE_STATE = '/etc/asterisk/rcm_queues.json';
const RCM_APPLY_SCRIPT = '/usr/local/bin/rcm_apply_queues.sh';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$q = isset($_GET['queue']) ? trim((string)$_GET['queue']) : '';
if (!preg_match('/^[0-9]{2,6}$/', $q)) {
    http_response_code(400);
    die('Invalid queue');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $state = ['queues' => []];
    if (file_exists(RCM_QUEUE_STATE)) {
        $tmp = json_decode(file_get_contents(RCM_QUEUE_STATE), true);
        if (is_array($tmp)) $state = $tmp;
    }

    $new = [];
    foreach (($state['queues'] ?? []) as $row) {
        if (($row['queue_number'] ?? '') !== $q) $new[] = $row;
    }
    $state['queues'] = $new;
    file_put_contents(RCM_QUEUE_STATE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    shell_exec('sudo ' . escapeshellarg(RCM_APPLY_SCRIPT) . ' 2>&1');
    header('Location: /queues.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Delete Queue</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/rcm.css">
<style>
html, body{ height:auto !important; }
body{
  display:block !important;
  align-items:initial !important;
  justify-content:initial !important;
}
.wrap{ width:min(760px, 96vw); margin:0 auto 60px auto; }
.q-topbar{
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; flex-wrap:wrap; margin-bottom:10px;
}
</style>
</head>
<body>

<div class="wrap">
  <div class="q-topbar">
    <a class="btn" href="/queues.php">BACK</a>
    <h1 class="page-title" style="margin:0;font-size:44px;">DELETE QUEUE</h1>
    <a class="btn" href="/queue.php">MONITOR</a>
  </div>

  <div class="inner-box" style="margin-top:0;">
    <div class="panel-box">
      <p>Are you sure you want to delete queue <strong><?php echo h($q); ?></strong> ?</p>
      <form method="post">
        <div class="btn-row">
          <button type="submit" class="btn">YES, DELETE</button>
          <a class="btn" href="/queues.php">CANCEL</a>
        </div>
      </form>
    </div>
  </div>
</div>

</body>
</html>
