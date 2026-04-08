<?php
// /var/www/html/add_trunk.php
// RCM - Add trunk (files-only) + markers + UI logic:
// - Peer: Server Address + Port visible, no Auth fields required
// - Register + Client Mode: Server Address + Port visible + (User Name + Auth ID editable)
// - Register + Server Mode: Server Address + Port hidden (NOT required) + (User Name + Auth ID = Name, readonly)

require_once __DIR__ . "/auth.php";
rcm_require_login();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$ASTERISK_DIR = "/etc/asterisk";

$F_ENDPOINT  = "$ASTERISK_DIR/pjsip.gui.endpoint.conf";
$F_AOR       = "$ASTERISK_DIR/pjsip.gui.aor.conf";
$F_AUTH      = "$ASTERISK_DIR/pjsip.gui.auth.conf";
$F_REG       = "$ASTERISK_DIR/pjsip.gui.reg.conf";
$F_IDENTIFY  = "$ASTERISK_DIR/pjsip.gui.identify.conf";

function ensure_file($path){
  if(!file_exists($path)) file_put_contents($path, "");
}
foreach([$F_ENDPOINT,$F_AOR,$F_AUTH,$F_REG,$F_IDENTIFY] as $f) ensure_file($f);

function sanitize_id($s){
  $s = trim((string)$s);
  return preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
}

function upsert_block($file, $name, $block){
  $content = file_exists($file) ? file_get_contents($file) : "";
  $begin = "; --- RCM-TRUNK: $name BEGIN ---";
  $end   = "; --- RCM-TRUNK: $name END ---";

  $pattern = '/^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*BEGIN\s*---\s*$([\s\S]*?)^\s*;\s*---\s*RCM-TRUNK:\s*' . preg_quote($name,'/') . '\s*END\s*---\s*$/m';

  $newBlock = $begin . "\n" . rtrim($block) . "\n" . $end . "\n";

  if(preg_match($pattern, $content)){
    $content = preg_replace($pattern, $newBlock, $content, 1);
  } else {
    $content = rtrim($content) . "\n\n" . $newBlock;
  }
  file_put_contents($file, $content);
}

function trunk_exists_anywhere($name, $files){
  foreach($files as $f){
    $c = file_get_contents($f);
    if(preg_match('/^\s*\[' . preg_quote($name,'/') . '\]\s*$/m', $c)) return true;
    if(preg_match('/^\s*\[' . preg_quote($name.'-reg','/') . '\]\s*$/m', $c)) return true;
    if(preg_match('/^\s*\[' . preg_quote($name.'-aor','/') . '\]\s*$/m', $c)) return true;
    if(preg_match('/^\s*\[' . preg_quote($name.'-auth','/') . '\]\s*$/m', $c)) return true;
    if(preg_match('/^\s*\[' . preg_quote($name.'_identify','/') . '\]\s*$/m', $c)) return true;
  }
  return false;
}

function do_reload(){
  @shell_exec("sudo /usr/local/bin/rcm_pjsip_reload.sh");
}

$err = "";

if($_SERVER["REQUEST_METHOD"] === "POST"){
  $enabled = isset($_POST["enabled"]) ? 1 : 0;

  $type = $_POST["type"] ?? "peer";
  $mode = $_POST["register_mode"] ?? "client";

  $name = sanitize_id($_POST["name"] ?? "");

  $server = trim((string)($_POST["server_addr"] ?? ""));
  $port   = intval($_POST["server_port"] ?? 5060);

  $keepalive = intval($_POST["keepalive"] ?? 60);
  $transport = trim((string)($_POST["transport"] ?? "transport-udp"));

  $outproxy_addr = trim((string)($_POST["outproxy_addr"] ?? ""));
  $outproxy_port = intval($_POST["outproxy_port"] ?? 0);

  $password = trim((string)($_POST["password"] ?? ""));

  if($type === "register" && $mode === "client"){
    $username = sanitize_id($_POST["username"] ?? "");
    $auth_id  = sanitize_id($_POST["auth_id"] ?? "");
  } else {
    $username = $name;
    $auth_id  = $name;
  }

  $from_user      = trim((string)($_POST["from_user"] ?? ""));
  $from_domain    = trim((string)($_POST["from_domain"] ?? ""));
  $identify_by_ui = trim((string)($_POST["identify_by"] ?? "auth_user"));
  $identify_by    = ($identify_by_ui === "username") ? "username" : "auth_username";

  $server_required = ($type === "peer") || ($type === "register" && $mode === "client");

  if($name === "")                                                            $err = "Name is required";
  else if(!$enabled)                                                          $err = "Enable the trunk first";
  else if($server_required && $server === "")                                 $err = "Server Address is required";
  else if($server_required && ($port < 1 || $port > 65535))                  $err = "Port invalid";
  else if($keepalive < 1)                                                     $err = "Keep-alive invalid";
  else if($type === "register" && $password === "")                           $err = "Password is required for register trunks";
  else if($type === "register" && $mode === "client" && $username === "")    $err = "User Name is required in Client Mode";
  else if($type === "register" && $mode === "client" && $auth_id === "")     $err = "Auth ID is required in Client Mode";

  if($err === ""){
    if(trunk_exists_anywhere($name, [$F_ENDPOINT,$F_AOR,$F_AUTH,$F_REG,$F_IDENTIFY])){
      $err = "Trunk name already exists: $name";
    } else {

      $outbound_proxy_line = "";
      if($outproxy_addr !== "" && $outproxy_port > 0){
        $outbound_proxy_line = "outbound_proxy=sip:$outproxy_addr:$outproxy_port\\;lr\n";
      }

      if($type === "peer"){
        $endpoint = "[$name]\ntype=endpoint\ntransport=$transport\ncontext=from-pri\ndisallow=all\nallow=ulaw,alaw\naors=$name\n";
        $aor      = "[$name]\ntype=aor\ncontact=sip:$server:$port\nqualify_frequency=$keepalive\n";
        $identify = "[{$name}_identify]\ntype=identify\nendpoint=$name\nmatch=$server\n";

        upsert_block($F_ENDPOINT, $name, $endpoint);
        upsert_block($F_AOR, $name, $aor);
        upsert_block($F_IDENTIFY, $name, $identify);

      } else {
        if($mode === "client"){
          $endpoint = "[$name]\ntype=endpoint\ntransport=$transport\ncontext=from-trunk\ndisallow=all\nallow=alaw,ulaw\naors={$name}-aor\noutbound_auth={$name}-auth\nidentify_by=$identify_by\n";
          $auth     = "[{$name}-auth]\ntype=auth\nauth_type=userpass\nusername=$auth_id\npassword=$password\n";
          $aor      = "[{$name}-aor]\ntype=aor\ncontact=sip:$username@$server:$port\nqualify_frequency=$keepalive\n";
          $reg      = "[{$name}-reg]\ntype=registration\noutbound_auth={$name}-auth\nserver_uri=sip:$server:$port\nclient_uri=sip:$username@$server\nexpiration=3600\n";

          if($from_user !== "")          $reg .= "from_user=$from_user\n";
          if($from_domain !== "")        $reg .= "from_domain=$from_domain\n";
          if($outbound_proxy_line !== "") $reg .= $outbound_proxy_line;

          upsert_block($F_ENDPOINT, $name, $endpoint);
          upsert_block($F_AUTH, $name, $auth);
          upsert_block($F_AOR, $name, $aor);
          upsert_block($F_REG, $name, $reg);

        } else {
          $endpoint = "[$name]\ntype=endpoint\ntransport=$transport\ncontext=from-trunk\ndisallow=all\nallow=alaw,ulaw\naors=$name\nauth={$name}-auth\noutbound_auth={$name}-auth\nrewrite_contact=yes\n";
          $aor      = "[$name]\ntype=aor\nmax_contacts=1\nremove_existing=yes\nqualify_frequency=$keepalive\n";
          $auth     = "[{$name}-auth]\ntype=auth\nauth_type=userpass\nusername=$auth_id\npassword=$password\n";

          upsert_block($F_ENDPOINT, $name, $endpoint);
          upsert_block($F_AOR, $name, $aor);
          upsert_block($F_AUTH, $name, $auth);
        }
      }

      do_reload();
      header("Location: /trunks.php");
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>ADD TRUNK</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">

  <style>
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    .full { grid-column: 1 / -1; }

    .panel-box {
      background: rgba(0,0,0,0.20);
      border-radius: 18px;
      padding: 22px;
      margin-top: 18px;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.07);
    }

    .panel-box label {
      display: block;
      font-size: 13px;
      font-family: 'Orbitron', sans-serif;
      letter-spacing: 1px;
      text-transform: uppercase;
      opacity: 0.85;
      margin-bottom: 8px;
    }

    .panel-box input,
    .panel-box select {
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

    .panel-box input:focus,
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
      color: rgba(255,255,255,0.40);
      font-size: 13px;
    }

    .section-divider {
      border: none;
      border-top: 1px solid rgba(255,255,255,0.10);
      margin: 20px 0;
    }

    .hint {
      font-size: 12px;
      opacity: 0.60;
      margin-top: 6px;
      line-height: 1.5;
    }

    .toggle {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px 0 14px;
    }
    .toggle input {
      width: auto;
      transform: scale(1.2);
      accent-color: #5398d7;
    }
    .toggle label {
      margin: 0;
      font-family: 'Orbitron', sans-serif;
      font-size: 14px;
      letter-spacing: 1px;
      text-transform: uppercase;
      opacity: 1;
    }

    .opt-badge {
      font-size: 11px;
      font-family: 'Orbitron', sans-serif;
      letter-spacing: 1px;
      opacity: 0.50;
      margin-left: 6px;
      vertical-align: middle;
    }

    .top { margin-bottom: 6px; }

    .btnrow {
      display: flex;
      gap: 12px;
      margin-top: 22px;
      flex-wrap: wrap;
    }

    @media (max-width: 900px) {
      .grid { grid-template-columns: 1fr; }
      .full { grid-column: 1; }
    }
  </style>
</head>

<body>
<div class="wrap">

  <h1 class="page-title">ADD TRUNK</h1>

  <div class="inner-box">

    <div class="top">
      <a class="btn" href="/trunks.php">BACK</a>
    </div>

    <div class="panel-box">

      <?php if($err): ?>
        <div class="msg error"><?php echo htmlspecialchars($err); ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">

        <!-- Enable toggle -->
        <div class="toggle">
          <input type="checkbox" id="enabled" name="enabled" checked>
          <label for="enabled">Enable Trunk</label>
        </div>

        <!-- Row 1: Type / Mode / Name -->
        <div class="grid">
          <div>
            <label>Type</label>
            <select name="type" id="type">
              <option value="peer">Peer Trunk</option>
              <option value="register">Register Trunk</option>
            </select>
          </div>

          <div id="regModeWrap" style="display:none;">
            <label>Register Mode</label>
            <select name="register_mode" id="register_mode">
              <option value="client">Client Mode</option>
              <option value="server">Server Mode</option>
            </select>
          </div>

          <div>
            <label>Name</label>
            <input type="text" name="name" id="name" placeholder="e.g. my_provider" required>
            <div class="hint">Trunk label used in the system</div>
          </div>
        </div>

        <hr class="section-divider">

        <!-- Row 2: Username / Auth ID / Password -->
        <div class="grid">
          <div id="userWrap">
            <label>User Name</label>
            <input type="text" name="username" id="username" placeholder="User Name">
            <div class="hint" id="userHint">Auto = Name (except Client Mode)</div>
          </div>

          <div id="authIdWrap">
            <label>Auth ID</label>
            <input type="text" name="auth_id" id="auth_id" placeholder="Auth ID">
            <div class="hint" id="authHint">Auto = Name (except Client Mode)</div>
          </div>

          <div id="passwordWrap" style="display:none;">
            <label>Password</label>
            <input type="password" name="password" id="password" placeholder="Password">
          </div>
        </div>

        <hr class="section-divider">

        <!-- Row 3: Server / Port -->
        <div class="grid" id="serverWrap">
          <div>
            <label>Server Address</label>
            <input type="text" name="server_addr" id="server_addr" placeholder="e.g. sip.provider.com">
          </div>
          <div>
            <label>Port</label>
            <input type="number" name="server_port" id="server_port" value="5060" min="1" max="65535">
          </div>
        </div>

        <!-- Row 4: Keep-alive / Transport -->
        <div class="grid" style="margin-top:14px;">
          <div>
            <label>Keep-alive Frequency (sec)</label>
            <input type="number" name="keepalive" value="60" min="1" required>
          </div>
          <div>
            <label>Transport</label>
            <select name="transport" id="transport">
              <option value="transport-udp">UDP</option>
              <option value="transport-tcp">TCP</option>
              <option value="transport-tls">TLS</option>
            </select>
          </div>
        </div>

        <!-- Row 5: Outbound Proxy -->
        <div class="grid" style="margin-top:14px;">
          <div>
            <label>Out Proxy Server <span class="opt-badge">(optional)</span></label>
            <input type="text" name="outproxy_addr" placeholder="e.g. proxy.provider.com">
          </div>
          <div>
            <label>Out Proxy Port <span class="opt-badge">(optional)</span></label>
            <input type="number" name="outproxy_port" value="0" min="0" max="65535">
          </div>
        </div>

        <!-- Register Client Extra Fields -->
        <div class="full" id="regExtra" style="display:none;">
          <hr class="section-divider">
          <div class="grid">
            <div>
              <label>From User <span class="opt-badge">(optional)</span></label>
              <input type="text" name="from_user" id="from_user" placeholder="From User">
            </div>
            <div>
              <label>From Domain <span class="opt-badge">(optional)</span></label>
              <input type="text" name="from_domain" id="from_domain" placeholder="From Domain">
            </div>
            <div>
              <label>Identify By</label>
              <select name="identify_by" id="identify_by">
                <option value="auth_user">Auth User</option>
                <option value="username">User Name</option>
              </select>
            </div>
          </div>
        </div>

        <hr class="section-divider">

        <!-- Submit -->
        <div class="btnrow">
          <button class="btn" type="submit">SAVE</button>
          <a class="btn" href="/trunks.php">CANCEL</a>
        </div>

      </form>
    </div><!-- /panel-box -->
  </div><!-- /inner-box -->
</div><!-- /wrap -->

<script>
  const typeEl      = document.getElementById('type');
  const regModeWrap = document.getElementById('regModeWrap');
  const regModeEl   = document.getElementById('register_mode');
  const regExtra    = document.getElementById('regExtra');
  const passwordWrap = document.getElementById('passwordWrap');
  const serverWrap  = document.getElementById('serverWrap');
  const serverAddr  = document.getElementById('server_addr');
  const serverPort  = document.getElementById('server_port');
  const nameEl      = document.getElementById('name');
  const usernameEl  = document.getElementById('username');
  const authIdEl    = document.getElementById('auth_id');
  const userHint    = document.getElementById('userHint');
  const authHint    = document.getElementById('authHint');

  function sanitizeVal(v){
    return (v || '').replace(/[^A-Za-z0-9_-]/g,'');
  }

  function setRequired(el, req){
    if (!el) return;
    if (req) el.setAttribute('required','required');
    else el.removeAttribute('required');
  }

  function syncName(){
    const n = sanitizeVal(nameEl.value);
    if (nameEl.value !== n) nameEl.value = n;

    const isReg    = typeEl.value === 'register';
    const isClient = isReg && regModeEl.value === 'client';

    if (!isClient){
      usernameEl.value = n;
      authIdEl.value   = n;
      usernameEl.readOnly = true;
      authIdEl.readOnly   = true;
      userHint.textContent = "Auto = Name";
      authHint.textContent = "Auto = Name";
    } else {
      usernameEl.readOnly = false;
      authIdEl.readOnly   = false;
      usernameEl.value = sanitizeVal(usernameEl.value);
      authIdEl.value   = sanitizeVal(authIdEl.value);
      userHint.textContent = "Editable (can differ from Name)";
      authHint.textContent = "Editable (can differ from Name)";
    }

    const fu = document.getElementById('from_user');
    if (fu && !fu.value) fu.value = n;
  }

  function refreshUI(){
    const isReg        = typeEl.value === 'register';
    const isServerMode = isReg && (regModeEl.value === 'server');
    const isClientMode = isReg && (regModeEl.value === 'client');

    regModeWrap.style.display  = isReg ? '' : 'none';
    regExtra.style.display     = isClientMode ? '' : 'none';
    passwordWrap.style.display = isReg ? '' : 'none';
    serverWrap.style.display   = isServerMode ? 'none' : '';

    const serverRequired = (!isReg) || isClientMode;
    setRequired(serverAddr, serverRequired);
    setRequired(serverPort, serverRequired);
    setRequired(document.getElementById('password'), isReg);
    setRequired(usernameEl, isClientMode);
    setRequired(authIdEl,   isClientMode);

    syncName();
  }

  nameEl.addEventListener('input',     syncName);
  usernameEl.addEventListener('input', syncName);
  authIdEl.addEventListener('input',   syncName);
  typeEl.addEventListener('change',    refreshUI);
  regModeEl.addEventListener('change', refreshUI);

  refreshUI();
</script>
</body>
</html>