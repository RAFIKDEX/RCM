<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();
?>
<!doctype html>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta charset="utf-8">
  <title>Dashboard</title>

  <!-- Orbitron -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <!-- Theme -->
  <link rel="stylesheet" href="/dist/assets/rcm.css">

  <style>
    .menu-grid{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
      gap:16px;
      margin-top:18px;
    }

    .menu-card{
      padding:24px;
      text-align:center;
      border-radius:22px;
      border:1px solid rgba(255,255,255,0.15);
      background: rgba(255,255,255,0.06);
      transition:.2s;
    }

    .menu-card:hover{
      transform:translateY(-4px);
      background: rgba(255,255,255,0.12);
    }

    .menu-card a{
      text-decoration:none;
      color:#fff;
      font-family:'Orbitron', sans-serif;
      font-weight:900;
      letter-spacing:2px;
      display:block;
    }

    /* Logout فوق على الشمال */
    .top-logout{
      position:absolute;
      top:20px;
      left:20px;
    }

    body{
      position:relative;
    }
  </style>
</head>
<body>

<a href="/logout.php" class="btn top-logout">LOGOUT</a>

<div class="wrap">

  <h1 class="page-title">DASHBOARD</h1>

  <div class="inner-box">

    <div class="panel-box">

      <div class="menu-grid">

        <div class="menu-card">
          <a href="/extensions.php">EXTENSIONS</a>
        </div>

        <div class="menu-card">
          <a href="/announcements.php">ANNOUNCEMENTS </a>
        </div>

        <div class="menu-card">
          <a href="/ivr.php"> IVR</a>
        </div>

        <div class="menu-card">
          <a href="/calls.php">ACTIVE CALLS</a>
        </div>

<div class="menu-card">
          <a href="/queues.php">QUEUE</a>
        </div>
<div class="menu-card">
          <a href="/cdr.php">CDR</a>
        </div>

<div class="menu-card">
          <a href="/info_live.php">live</a>
        </div>

<div class="menu-card">
          <a href="/trunks.php">TRUNKS</a>
        </div>
        
<div class="menu-card">
          <a href="/outbound_routes.php">OUTBOUND</a>
        </div>
        <div class="menu-card">
          <a href="/ring_groups.php">RING GROUP</a>
        </div>
        <div class="menu-card">
          <a href="/media_center.php">MEDIA</a>
        </div>
         <div class="menu-card">
          <a href="/stats.php">Q Statistics</a>
        </div>
        <div class="menu-card">
          <a href="/paging_intercom.php">PAGING.INTERCOM</a>
        </div>
    <div class="menu-card">
          <a href="/announcement_paging.php">ANN PAGING</a>
        </div>
<div class="menu-card">
  <a href="/pickup_groups.php">PICKUP GROUP</a>
</div>
<div class="menu-card">
  <a href="/speed_dials.php">SPEED DIAL</a>
</div>
<div class="menu-card">
  <a href="time_condition.php">TIME CONDITION</a>
</div>
      </div>

    </div>

  </div>

</div>

</body>
</html>
