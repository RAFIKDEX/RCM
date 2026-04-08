<?php
require_once __DIR__ . "/auth.php";
rcm_require_login();

$EP_FILE = "/etc/asterisk/pjsip.gui.endpoint.conf";

$nextExt = "1000";
$defaultSecret = "Abc@1234";

function rcm_read_file_safe(string $path): string {
    return file_exists($path) ? (file_get_contents($path) ?: "") : "";
}

function rcm_strip_trunk_blocks(string $txt): string {
    return preg_replace('/^\s*;\s*---\s*RCM-TRUNK:\s*.*?\s*BEGIN\s*---\s*$.*?^\s*;\s*---\s*RCM-TRUNK:\s*.*?\s*END\s*---\s*$/ms', '', $txt) ?? $txt;
}

$txt = rcm_read_file_safe($EP_FILE);
if ($txt !== "") {
    $txt = rcm_strip_trunk_blocks($txt);

    if (preg_match_all('/^\[(\d{2,6})\]$/m', $txt, $m)) {
        $nums = array_map('intval', $m[1] ?? []);
        if (!empty($nums)) {
            $nextExt = (string)(max($nums) + 1);
        }
    }
}

$defaultCallerIdNumber = $nextExt;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Add Extension</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">

  <style>
    .wrap{ width:min(760px, 95vw); }

    .page-subtitle{
      margin:0 0 22px 0;
      text-align:center;
      font-family:'Orbitron', sans-serif;
      font-weight:900;
      letter-spacing:6px;
      text-transform:uppercase;
      font-size:34px;
      text-shadow: 0 0 18px rgba(255,255,255,.35);
    }

    .panel-box{
      margin-top:0;
    }

    form{
      width:100%;
    }

    .row{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:16px;
    }

    .field{
      margin-bottom:16px;
    }

    label{
      display:block;
      margin:0 0 8px 0;
      font-weight:800;
      font-size:15px;
      color:#fff;
    }

    input,
    select{
      width:100%;
      box-sizing:border-box;
      min-height:46px;
      padding:10px 14px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.28);
      background:rgba(255,255,255,.10);
      color:#fff;
      font-size:16px;
      outline:none;
    }

    input::placeholder{
      color:rgba(255,255,255,.70);
    }

    select{
      cursor:pointer;
    }

    .actions{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:16px;
      margin-top:22px;
    }

    .hint{
      margin-top:18px;
      text-align:center;
      opacity:.92;
      font-weight:800;
      letter-spacing:1px;
      font-size:12px;
      line-height:1.8;
    }

    @media (max-width: 700px){
      .row,
      .actions{
        grid-template-columns: 1fr;
      }

      .wrap{
        width:min(94vw, 760px);
      }
    }
  </style>
</head>

<body>
  <div class="wrap">
    <div class="inner-box" style="margin-top:0;">
      <h1 class="page-subtitle">ADD EXTENSION</h1>

      <div class="panel-box">
        <form method="post" action="/save_extension.php" autocomplete="off">

          <div class="row">
            <div class="field">
              <label>Extension</label>
              <input
                name="ext"
                required
                pattern="[0-9]{2,6}"
                placeholder="e.g. 3001"
                value="<?= htmlspecialchars($nextExt) ?>"
              >
            </div>

            <div class="field">
              <label>Password</label>
              <input
                name="secret"
                required
                placeholder="e.g. Abc@1234"
                value="<?= htmlspecialchars($defaultSecret) ?>"
              >
            </div>
          </div>

          <div class="row">
            <div class="field">
              <label>Caller ID Number</label>
              <input
                name="callerid_number"
                required
                pattern="[0-9+*#]{2,20}"
                placeholder="e.g. 3001"
                value="<?= htmlspecialchars($defaultCallerIdNumber) ?>"
              >
            </div>

            <div class="field">
              <label>Caller ID Name</label>
              <input
                name="callerid_name"
                maxlength="80"
                placeholder="e.g. Rafik "
              >
            </div>
          </div>

          <div class="row">
            <div class="field">
              <label>Context</label>
              <select name="context">
                <option value="internal">internal</option>
              </select>
            </div>

            <div class="field">
              <label>Max Contacts</label>
              <select name="max_contacts">
                <option value="3">3</option>
                <option value="5">5</option>
                <option value="10" selected>10</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label>Allow Codecs</label>
            <input name="allow" required value="alaw,ulaw">
          </div>

          <div class="row">
            <div class="field">
              <label>Recording</label>
              <select name="record_mode">
                <option value="noo" selected>noo</option>
                <option value="in">in</option>
                <option value="out">out</option>
                <option value="all">all</option>
              </select>
            </div>

            <div class="field">
              <label>Voicemail</label>
              <select name="vm_mode">
                <option value="Off" selected>Off</option>
                <option value="On">On</option>
              </select>
            </div>
          </div>

          <div class="actions">
            <a class="btn" href="/extensions.php">CANCEL</a>
            <button class="btn" type="submit">SAVE</button>
          </div>

          <div class="hint">
            * Extension is suggested automatically from the last GUI extension.<br>
            * Password defaults to <b>Abc@1234</b> and you can change it.<br>
            * Caller ID Name is optional. If left empty, system will use the extension number.<br>
            * System writes <b>callerid=&quot;Name&quot; &lt;Number&gt;</b> inside the endpoint automatically.
          </div>

        </form>
      </div>
    </div>
  </div>

  <script>
    const extInput = document.querySelector('input[name="ext"]');
    const cidNumInput = document.querySelector('input[name="callerid_number"]');

    if (extInput && cidNumInput) {
      extInput.addEventListener('input', () => {
        if (!cidNumInput.dataset.userEdited) {
          cidNumInput.value = extInput.value;
        }
      });

      cidNumInput.addEventListener('input', () => {
        cidNumInput.dataset.userEdited = '1';
      });
    }
  </script>
</body>
</html>