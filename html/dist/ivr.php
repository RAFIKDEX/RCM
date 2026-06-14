<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$DP_FILE = "/etc/asterisk/extensions_gui.conf";

function parse_ivrs($file){
  if (!file_exists($file)) return [];

  $lines = file($file, FILE_IGNORE_NEW_LINES);
  $ivrs = [];
  $cur = null;
  $pendingName = null;

  $getLoopsFromLimit = function($limit){
    $n = (int)$limit;
    if ($n <= 1) return 1;
    return $n - 1;
  };

  foreach ($lines as $line) {
    $t = trim($line);

    // IVR name comment
    if (preg_match('/^;\s*---\s*IVR GUI:\s*([a-zA-Z0-9_-]+)\s*---/',$t,$m)){
      $pendingName = $m[1];
      continue;
    }

    // section start
    if (preg_match('/^\[ivr-(\d{2,6})\]$/',$t,$m)){
      $num = $m[1];
      $cur = $num;

      $ivrs[$cur] = [
        "num"=>$num,
        "name"=>$pendingName ?? ("ivr-".$num),
        "loops"=>3,
        "fail_mode"=>"goto",
        "fail_ext"=>"2222",
        "map"=>[]
      ];

      $pendingName = null;
      continue;
    }

    if (!$cur) continue;

    // mapping
    if (preg_match('/^exten\s*=>\s*([^,]+),1,Goto\(internal,(\d{2,6}),1\)\s*$/',$t,$m)){
      $ivrs[$cur]["map"][] = [
        "key"=>trim($m[1]),
        "dest"=>$m[2]
      ];
      continue;
    }

    // loops
    if (preg_match('/GotoIf\(\$\[\$\{test\}\s*<\s*(\d+)\]\?/',$t,$m)){
      $ivrs[$cur]["loops"] = $getLoopsFromLimit($m[1]);
      continue;
    }

    // fail
    if (preg_match('/Goto\(internal,(\d{2,6}),1\)/',$t,$m)){
      $ivrs[$cur]["fail_mode"]="goto";
      $ivrs[$cur]["fail_ext"]=$m[1];
      continue;
    }

    if (stripos($t,'Hangup()') !== false){
      $ivrs[$cur]["fail_mode"]="hangup";
      $ivrs[$cur]["fail_ext"]="-";
      continue;
    }

    // section end
    if (preg_match('/^\[[^]]+\]$/',$t) && !preg_match('/^\[ivr-(\d{2,6})\]$/',$t)){
      $cur = null;
    }
  }

  uasort($ivrs, fn($a,$b)=> strnatcmp($a["num"], $b["num"]));
  return array_values($ivrs);
}

$ivrs = parse_ivrs($DP_FILE);
?>
<!doctype html>
<html lang="en">

<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>IVR</title>

<!-- Font -->
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">

<!-- Favicon -->
<link rel="icon" type="image/png" href="/assets/rcm/logo.png">

<style>
  html,body{height:100%;margin:0}

  body{
    font-family: Arial, sans-serif;
    background:
      radial-gradient(circle at 1px 1px, #3d3d3d 1px, transparent 0),
      #5398d7;
    background-size: 10px 10px;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    color:#fff;
  }

  .wrap{
    width:min(1100px, 96vw);
    background:rgba(0,0,0,0.25);
    border:2px solid rgba(255,255,255,0.2);
    border-radius:22px;
    box-shadow:0 20px 50px rgba(0,0,0,.35);
    padding:26px 24px;
    backdrop-filter: blur(6px);
  }

  .top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
  }

  .page-title{
    width:100%;
    text-align:center;
    font-family:'Orbitron', sans-serif;
    font-weight:900;
    letter-spacing:12px;
    text-transform:uppercase;
    font-size:60px;
    margin:0 0 30px 0;
    text-shadow:
      0 0 20px rgba(255,255,255,.6),
      0 0 40px rgba(255,255,255,.3);
  }

  .btn{
    display:inline-block;
    padding:12px 20px;
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
    font-family:'Orbitron',sans-serif;
  }

  .btn:hover{
    background:#fff;
    color:#5398d7;
    box-shadow:0 0 22px rgba(255,255,255,.6);
  }

  table{
    width:100%;
    border-collapse:collapse;
    margin-top:16px;
  }

  th,td{
    border:1px solid rgba(255,255,255,0.22);
    padding:10px;
    text-align:center;
  }

  th{
    background:rgba(255,255,255,0.10);
    font-family:'Orbitron',sans-serif;
    letter-spacing:1px;
  }

  .pill{
    display:inline-block;
    padding:6px 12px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,.35);
    background:rgba(255,255,255,.10);
  }

  .actions{
    display:flex;
    gap:10px;
    justify-content:center;
    flex-wrap:wrap;
  }

  form{margin:0}

  .muted{opacity:.85}

  .inner-box{
    background: rgba(0,0,0,0.30);
    border-radius: 25px;
    padding: 24px;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
  }
</style>
</head>

<body>

<div class="wrap">

  <h1 class="page-title">IVR</h1>

  <div class="inner-box">

    <div class="top">
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn" href="/add_ivr.php">ADD IVR</a>
        <a class="btn" href="/dashboard.php">CANCEL</a>
      </div>
    </div>

    <?php if (empty($ivrs)): ?>
      <p class="muted" style="margin-top:16px">No IVR found yet.</p>
    <?php else: ?>

      <table>
        <thead>
          <tr>
            <th>IVR</th>
            <th>Name</th>
            <th>Loop</th>
            <th>Fail</th>
            <th>Events</th>
            <th>Actions</th>
          </tr>
        </thead>

        <tbody>
        <?php foreach($ivrs as $x): ?>
          <tr>
            <td><span class="pill"><?php echo htmlspecialchars($x["num"]); ?></span></td>
            <td><?php echo htmlspecialchars($x["name"]); ?></td>
            <td><?php echo (int)$x["loops"]; ?></td>

            <td>
              <?php if ($x["fail_mode"] === "hangup"): ?>
                <span class="pill">Hangup</span>
              <?php else: ?>
                <span class="pill">Goto <?php echo htmlspecialchars($x["fail_ext"]); ?></span>
              <?php endif; ?>
            </td>

            <td><?php echo count($x["map"]); ?></td>

            <td>
              <div class="actions">
                <a class="btn" href="/edit_ivr.php?n=<?php echo urlencode($x["num"]); ?>">EDIT</a>

                <form method="post" action="/delete_ivr.php"
                      onsubmit="return confirm('Delete IVR <?php echo htmlspecialchars($x["num"]); ?> ?');">
                  <input type="hidden" name="n" value="<?php echo htmlspecialchars($x["num"]); ?>">
                  <button class="btn" type="submit">DELETE</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>

      </table>

    <?php endif; ?>

  </div>

</div>

</body>
</html>