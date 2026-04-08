<?php
// Bulk Add Extensions - UI only
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bulk Add Extensions</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/rcm.css">

  <style>
    .grid { display: grid; gap: 14px; }
    .grid-2 { grid-template-columns: 1fr 1fr; }
    .grid-3 { grid-template-columns: 1fr 1fr 1fr; }

    .panel-box {
      background: rgba(0,0,0,0.20);
      border-radius: 18px;
      padding: 22px;
      margin-top: 18px;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.07);
    }

    .panel-box label {
      display: block;
      margin-bottom: 8px;
      font-size: 13px;
      font-family: 'Orbitron', sans-serif;
      letter-spacing: 1px;
      text-transform: uppercase;
      opacity: 0.85;
    }

    .panel-box input[type="text"],
    .panel-box input[type="number"],
    .panel-box input[type="password"],
    .panel-box select,
    .panel-box textarea {
      width: 100%;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.22);
      background: rgba(255,255,255,0.08);
      color: #fff;
      font-size: 15px;
      outline: none;
      box-sizing: border-box;
      font-family: Arial, sans-serif;
      transition: border-color .2s, background .2s;
    }

    .panel-box input[type="text"]:focus,
    .panel-box input[type="number"]:focus,
    .panel-box select:focus {
      border-color: rgba(255,255,255,0.55);
      background: rgba(255,255,255,0.13);
    }

    .panel-box input[readonly] {
      opacity: 0.55;
      cursor: not-allowed;
    }

    .panel-box select option {
      background: #1e3a5f;
      color: #fff;
    }

    .panel-box input::placeholder {
      color: rgba(255,255,255,0.45);
      font-size: 13px;
    }

    .section-divider {
      border: none;
      border-top: 1px solid rgba(255,255,255,0.10);
      margin: 20px 0;
    }

    .top {
      margin-bottom: 6px;
    }

    .hint {
      font-size: 13px;
      opacity: 0.60;
      align-self: center;
      line-height: 1.5;
    }

    @media (max-width: 900px) {
      .grid-3, .grid-2 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1 class="page-title">BULK EXTENSIONS</h1>

    <div class="inner-box">

      <div class="top">
        <a class="btn" href="extensions.php">CANCEL</a>
      </div>

      <div class="panel-box">
        <form action="save_bulk_extensions.php" method="post" autocomplete="off">

          <!-- Row 1: Range -->
          <div class="grid grid-3">
            <div>
              <label>Start Extension Number</label>
              <input type="number" name="start_ext" min="10" max="999999" placeholder="e.g. 2000" required>
            </div>
            <div>
              <label>Count</label>
              <input type="number" name="count" min="1" max="2000" placeholder="e.g. 50" required>
            </div>
            <div>
              <label>Extension Incrementation</label>
              <input type="number" name="step" min="1" max="1000" value="1" required>
            </div>
          </div>

          <hr class="section-divider">

          <!-- Row 2: Password -->
          <div class="grid grid-2">
            <div>
              <label>Password Mode</label>
              <select name="pass_mode" required>
                <option value="unified">Unified Password</option>
                <option value="prefix_ext">Prefix + Extension</option>
              </select>
            </div>
            <div>
              <label>Password / Prefix</label>
              <input type="text" name="pass_value" value="Abc@1234" placeholder="Unified: Abc@1234 | Prefix: Abc@" required>
            </div>
          </div>

          <hr class="section-divider">

          <!-- Row 3: Caller ID Name -->
          <div class="grid grid-2">
            <div>
              <label>Caller ID Name Mode</label>
              <select name="callerid_name_mode" required>
                <option value="prefix_ext">Prefix + Extension</option>
                <option value="fixed">Fixed Name</option>
              </select>
            </div>
            <div>
              <label>Caller ID Name Value</label>
              <input type="text" name="callerid_name_value" value="Extension" placeholder="e.g. Agent or Support Desk" required>
            </div>
          </div>

          <!-- Row 4: Caller ID Number -->
          <div class="grid grid-2" style="margin-top:14px;">
            <div>
              <label>Caller ID Number Mode</label>
              <select name="callerid_number_mode" required>
                <option value="same_ext">Same as Extension</option>
                <option value="prefix_ext">Prefix + Extension</option>
                <option value="fixed">Fixed Number</option>
              </select>
            </div>
            <div>
              <label>Caller ID Number Value</label>
              <input type="text" name="callerid_number_value" placeholder="blank = same | Prefix: 02 | Fixed: 12345">
            </div>
          </div>

          <hr class="section-divider">

          <!-- Row 5: Codecs & Contacts -->
          <div class="grid grid-2">
            <div>
              <label>Allow Codecs</label>
              <input type="text" name="allow" value="alaw,ulaw" required>
            </div>
            <div>
              <label>Max Contacts</label>
              <input type="number" name="max_contacts" min="1" max="50" value="10" required>
            </div>
          </div>

          <!-- Row 6: Recording & Voicemail -->
          <div class="grid grid-2" style="margin-top:14px;">
            <div>
              <label>Recording</label>
              <select name="record_mode" required>
                <option value="noo">noo</option>
                <option value="in">in</option>
                <option value="out">out</option>
                <option value="all">all</option>
              </select>
            </div>
            <div>
              <label>Voicemail</label>
              <select name="vm_mode" required>
                <option value="Off">Off</option>
                <option value="On">On</option>
              </select>
            </div>
          </div>

          <hr class="section-divider">

          <!-- Row 7: Context & Skip -->
          <div class="grid grid-2">
            <div>
              <label>Context</label>
              <input type="text" name="context" value="internal" readonly>
            </div>
            <div>
              <label>Skip if Extension Exists?</label>
              <select name="skip_existing" required>
                <option value="yes">Yes (recommended)</option>
                <option value="no">No (stop on duplicates)</option>
              </select>
            </div>
          </div>

          <hr class="section-divider">

          <!-- Submit -->
          <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center;">
            <button class="btn" type="submit">CREATE</button>
            <span class="hint">* Bulk mode generates caller ID name/number automatically for every extension.</span>
          </div>

        </form>
      </div><!-- /panel-box -->

    </div><!-- /inner-box -->
  </div><!-- /wrap -->
</body>
</html>