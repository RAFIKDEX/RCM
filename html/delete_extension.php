<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$ext = trim($_POST["ext"] ?? $_GET["ext"] ?? "");
$errors = [];
$out = "";
$ok = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (!preg_match('/^\d{2,6}$/', $ext)) $errors[] = "Invalid extension";

  if (!$errors) {
    $cmd = "sudo /usr/local/bin/rcm_del_ext.sh " . escapeshellarg($ext) . " 2>&1";
    $out = shell_exec($cmd) ?? "";
    $ok = (strpos($out, "DONE:") !== false);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Delete Extension</title>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <style>
    html,body{height:100%;margin:0}
    body{
      font-family: Arial, sans-serif;
      background:
        radial-gradient(circle at 1px 1px, rgba(255,255,255,0.15) 1px, transparent 0),
        #5398d7;
      background-size:18px 18px;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
      color:#fff;
    }
    .wrap{
      width:min(720px, 94vw);
      background:rgba(0,0,0,0.25);
      border:2px solid rgba(255,255,255,0.2);
      border-radius:22px;
      box-shadow:0 20px 50px rgba(0,0,0,.35);
      padding:26px 24px;
      backdrop-filter: blur(6px);
    }
    h1{
      margin:0 0 14px 0;
      text-align:center;
      font-family:'Orbitron', sans-serif;
      font-weight:900;
      letter-spacing:6px;
      text-transform:uppercase;
      font-size:30px;
      text-shadow:0 0 18px rgba(255,255,255,.35);
    }
    label{
      display:block;
      margin:14px 0 8px 0;
      font-weight:900;
      letter-spacing:1px;
      opacity:.95;
    }
    input{
      width:100%;
      box-sizing:border-box;
      padding:14px 18px;
      border-radius:40px;
      border:2px solid rgba(255,255,255,0.30);
      background:rgba(255,255,255,0.08);
      color:#fff;
      outline:none;
      font-family:'Orbitron', sans-serif;
      font-size:16px;
      letter-spacing:1px;
      text-align:center;
      transition:.2s;
    }
    input:focus{
      border-color:#fff;
      background:rgba(255,255,255,0.15);
    }
    .actions{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:12px;
      margin-top:18px;
    }
    .btn{
      display:inline-block;
      padding:14px 22px;
      border-radius:40px;
      border:2px solid #fff;
      background:transparent;
      color:#fff;
      font-weight:900;
      letter-spacing:2px;
      text-decoration:none;
      transition:.2s;
      text-align:center;
      cursor:pointer;
    }
    .btn:hover{
      background:#fff;
      color:#5398d7;
      box-shadow:0 0 22px rgba(255,255,255,.6);
    }
    .box{
      background:rgba(255,255,255,0.10);
      border:1px solid rgba(255,255,255,0.18);
      border-radius:18px;
      padding:14px;
      margin-top:14px;
      white-space:pre-wrap;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      font-size:13px;
      opacity:.95;
    }
    .ok{color:#b8ffcc;font-weight:900;text-align:center}
    .bad{color:#ffd0d0;font-weight:900;text-align:center}
    ul{margin:10px 0 0 18px}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>DELETE EXTENSION</h1>

    <?php if ($_SERVER["REQUEST_METHOD"] !== "POST"): ?>
      <form method="post">
        <label>Extension</label>
        <input name="ext" required pattern="[0-9]{2,6}" placeholder="e.g. 3001" value="<?php echo htmlspecialchars($ext); ?>">
        <div class="actions">
          <a class="btn" href="/dashboard.php">CANCEL</a>
          <button class="btn" type="submit">DELETE</button>
        </div>
      </form>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="bad">FAILED</div>
        <ul>
          <?php foreach($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
        </ul>
      <?php else: ?>
        <?php if ($ok): ?><div class="ok">SUCCESS</div><?php else: ?><div class="bad">FAILED</div><?php endif; ?>
        <div class="box"><?php echo htmlspecialchars($out ?: "No output"); ?></div>
      <?php endif; ?>

      <div class="actions">
        <a class="btn" href="/delete_extension.php">DELETE ANOTHER</a>
        <a class="btn" href="/extensions.php">EXTENSIONS</a>
      </div>
    <?php endif; ?>

  </div>
</body>
</html>
