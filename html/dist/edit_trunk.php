<?php
// /var/www/html/edit_trunk.php
// RCM - Edit trunk (type can't be changed)

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

function read_file_safe($path){
  return file_exists($path) ? file_get_contents($path) : "";
}

function parse_marker_blocks($content){
  $blocks = [];
  if(!$content) return $blocks;
  $re = '/^\s*;\s*---\s*RCM-TRUNK:\s*([A-Za-z0-9_\-]+)\s*BEGIN\s*---\s*$([\s\S]*?)^\s*;\s*---\s*RCM-TRUNK:\s*\1\s*END\s*---\s*$/m';
  if (preg_match_all($re, $content, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
      $blocks[$hit[1]] = $hit[2];
    }
  }
  return $blocks;
}

function kv_from_block($block, $key){
  $re = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+?)\s*$/m';
  if (preg_match($re, $block, $m)) return trim($m[1]);
  return "";
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

function extract_host_port_from_sip($uri){
  $uri = trim((string)$uri);
  if ($uri === "") return ["",""];
  $uri = trim($uri, "<> \t\n\r\0\x0B");
  $uri = preg_split('/[;\?]/', $uri, 2)[0];
  $uri = preg_replace('/^\s*sips?:/i', '', $uri);
  if (strpos($uri, '@') !== false) {
    $parts = explode('@', $uri);
    $uri = end($parts);
  }
  if (preg_match('/^\[(.+)\](?::(\d+))?$/', $uri, $m)) {
    return [trim($m[1]), isset($m[2]) ? trim($m[2]) : ""];
  }
  if (preg_match('/^(.+):(\d+)$/', $uri, $m)) {
    return [trim($m[1]), trim($m[2])];
  }
  return [trim($uri), ""];
}

function do_reload(){
  @shell_exec("sudo /usr/local/bin/rcm_pjsip_reload.sh");
}

/* ===== Load trunk by name ===== */
$name = sanitize_id($_GET["name"] ?? "");
if($name === ""){
  header("Location: /trunks.php");
  exit;
}

$endpoint_blocks  = parse_marker_blocks(read_file_safe($F_ENDPOINT));
$aor_blocks       = parse_marker_blocks(read_file_safe($F_AOR));
$auth_blocks      = parse_marker_blocks(read_file_safe($F_AUTH));
$reg_blocks       = parse_marker_blocks(read_file_safe($F_REG));
$identify_blocks  = parse_marker_blocks(read_file_safe($F_IDENTIFY));

$endpoint_block = $endpoint_blocks[$name] ?? "";
$aor_block      = $aor_blocks[$name] ?? ($aor_blocks[$name . "-aor"] ?? "");
$auth_block     = $auth_blocks[$name] ?? ($auth_blocks[$name . "-auth"] ?? "");
$reg_block      = $reg_blocks[$name] ?? ($reg_blocks[$name . "-reg"] ?? "");
$id_block       = $identify_blocks[$name] ?? ($identify_blocks[$name . "_identify"] ?? "");

$type = "peer";
$register_mode = "client";

if($reg_block !== ""){
  $type = "register";
  $register_mode = "client";
} else {
  $auth_in_ep = kv_from_block($endpoint_block, "auth");
  if($auth_in_ep !== "" || $auth_block !== ""){
    $type = "register";
    $register_mode = "server";
  } else {
    $type = "peer";
    $register_mode = "client";
  }
}

$enabled = 1;
$keepalive = 60;
$transport = kv_from_block($endpoint_block, "transport") ?: "transport-udp";

$server_addr = "";
$server_port = "5060";

$outproxy_addr = "";
$outproxy_port = "0";

$username = $name;
$auth_id  = $name;
$password_existing = "";

$from_user = "";
$from_domain = "";
$identify_by_ui = "auth_user";

$qual = kv_from_block($aor_block, "qualify_frequency");
if($qual !== "" && ctype_digit($qual)) $keepalive = (int)$qual;

if($type === "peer"){
  $contact = kv_from_block($aor_block, "contact");
  [$h,$p] = extract_host_port_from_sip($contact);
  if($h !== "") $server_addr = $h;
  if($p !== "") $server_port = $p;
}

if($type === "register" && $register_mode === "client"){
  $auth_id = kv_from_block($auth_block, "username") ?: $name;
  $password_existing = kv_from_block($auth_block, "password");

  $server_uri = kv_from_block($reg_block, "server_uri");
  [$h,$p] = extract_host_port_from_sip($server_uri);
  if($h !== "") $server_addr = $h;
  if($p !== "") $server_port = $p;

  $client_uri = kv_from_block($reg_block, "client_uri");
  $cu = trim((string)$client_uri);
  $cu = preg_replace('/^\s*sips?:/i', '', $cu);
  $u = "";
  if(strpos($cu,'@') !== false){
    $u = explode('@',$cu)[0];
  }
  $username = sanitize_id($u ?: $name);

  $from_user = kv_from_block($reg_block, "from_user");
  $from_domain = kv_from_block($reg_block, "from_domain");

  $obp = kv_from_block($reg_block, "outbound_proxy");
  if($obp !== ""){
    [$oh,$op] = extract_host_port_from_sip($obp);
    if($oh !== "") $outproxy_addr = $oh;
    if($op !== "") $outproxy_port = $op;
  }

  $identify_by = kv_from_block($endpoint_block, "identify_by");
  if(strtolower($identify_by) === "username") $identify_by_ui = "username";
  else $identify_by_ui = "auth_user";
}

if($type === "register" && $register_mode === "server"){
  $auth_id = kv_from_block($auth_block, "username") ?: $name;
  $password_existing = kv_from_block($auth_block, "password");
  $username = $name;
  $auth_id  = $name;
}

$err = "";

/* ===== Save (POST) ===== */
if($_SERVER["REQUEST_METHOD"] === "POST"){
  $enabled_post = isset($_POST["enabled"]) ? 1 : 0;

  $type_post = $_POST["type_locked"] ?? $type;
  $mode_post = $_POST["register_mode_locked"] ?? $register_mode;
  $name_post = sanitize_id($_POST["name_locked"] ?? $name);

  $keepalive_post = intval($_POST["keepalive"] ?? $keepalive);
  $transport_post = trim((string)($_POST["transport"] ?? $transport));

  $outproxy_addr_post = trim((string)($_POST["outproxy_addr"] ?? ""));
  $outproxy_port_post = intval($_POST["outproxy_port"] ?? 0);

  $server_addr_post = trim((string)($_POST["server_addr"] ?? ""));
  $server_port_post = intval($_POST["server_port"] ?? 5060);

  $password_post = trim((string)($_POST["password"] ?? ""));

  $username_post = sanitize_id($_POST["username"] ?? "");
  $auth_id_post  = sanitize_id($_POST["auth_id"] ?? "");

  $from_user_post = trim((string)($_POST["from_user"] ?? ""));
  $from_domain_post = trim((string)($_POST["from_domain"] ?? ""));
  $identify_by_ui_post = trim((string)($_POST["identify_by"] ?? "auth_user"));
  $identify_by_post = ($identify_by_ui_post === "username") ? "username" : "auth_username";

  $server_required = ($type_post === "peer") || ($type_post === "register" && $mode_post === "client");
  $user_auth_editable = ($type_post === "register" && $mode_post === "client");

  if(!$enabled_post) $err = "Enable the trunk first";
  else if($keepalive_post < 1) $err = "Keep-alive invalid";
  else if($server_required && $server_addr_post === "") $err = "Server Address is required";
  else if($server_required && ($server_port_post < 1 || $server_port_post > 65535)) $err = "Port invalid";
  else if($type_post === "register" && $password_post === "" && $password_existing === "") $err = "Password is required for register trunks";
  else if($user_auth_editable && $username_post === "") $err = "User Name is required in Client Mode";
  else if($user_auth_editable && $auth_id_post === "") $err = "Auth ID is required in Client Mode";

  if($err === ""){
    if($type_post === "register" && $password_post === ""){
      $password_post = $password_existing;
    }
    if(!$user_auth_editable){
      $username_post = $name_post;
      $auth_id_post  = $name_post;
    }

    $outbound_proxy_line = "";
    if($outproxy_addr_post !== "" && $outproxy_port_post > 0){
      $outbound_proxy_line = "outbound_proxy=sip:$outproxy_addr_post:$outproxy_port_post\\;lr\n";
    }

    if($type_post === "peer"){
      $endpoint_new = "[$name_post]\ntype=endpoint\ntransport=$transport_post\ncontext=from-pri\ndisallow=all\nallow=ulaw,alaw\naors=$name_post\n";
      $aor_new = "[$name_post]\ntype=aor\ncontact=sip:$server_addr_post:$server_port_post\nqualify_frequency=$keepalive_post\n";
      $identify_new = "[{$name_post}_identify]\ntype=identify\nendpoint=$name_post\nmatch=$server_addr_post\n";

      upsert_block($F_ENDPOINT, $name_post, $endpoint_new);
      upsert_block($F_AOR, $name_post, $aor_new);
      upsert_block($F_IDENTIFY, $name_post, $identify_new);

    } else {
      if($mode_post === "client"){
        $endpoint_new = "[$name_post]\ntype=endpoint\ntransport=$transport_post\ncontext=from-trunk\ndisallow=all\nallow=alaw,ulaw\naors={$name_post}-aor\noutbound_auth={$name}-auth\nidentify_by=$identify_by_post\n";
        $auth_new = "[{$name_post}-auth]\ntype=auth\nauth_type=userpass\nusername=$auth_id_post\npassword=$password_post\n";
        $aor_new = "[{$name_post}-aor]\ntype=aor\ncontact=sip:$username_post@$server_addr_post:$server_port_post\nqualify_frequency=$keepalive_post\n";
        $reg_new = "[{$name_post}-reg]\ntype=registration\noutbound_auth={$name_post}-auth\nserver_uri=sip:$server_addr_post:$server_port_post\nclient_uri=sip:$username_post@$server_addr_post\nexpiration=3600\n";

        if($from_user_post !== "")   $reg_new .= "from_user=$from_user_post\n";
        if($from_domain_post !== "") $reg_new .= "from_domain=$from_domain_post\n";
        if($outbound_proxy_line !== "") $reg_new .= $outbound_proxy_line;

        upsert_block($F_ENDPOINT, $name_post, $endpoint_new);
        upsert_block($F_AUTH, $name_post, $auth_new);
        upsert_block($F_AOR, $name_post, $aor_new);
        upsert_block($F_REG, $name_post, $reg_new);

      } else {
        $endpoint_new = "[$name_post]\ntype=endpoint\ntransport=$transport_post\ncontext=from-trunk\ndisallow=all\nallow=alaw,ulaw\naors=$name_post\nauth={$name_post}-auth\noutbound_auth={$name}-auth\nrewrite_contact=yes\n";
        $aor_new = "[$name_post]\ntype=aor\nmax_contacts=1\nremove_existing=yes\nqualify_frequency=$keepalive_post\n";
        $auth_new = "[{$name_post}-auth]\ntype=auth\nauth_type=userpass\nusername=$auth_id_post\npassword=$password_post\n";

        upsert_block($F_ENDPOINT, $name_post, $endpoint_new);
        upsert_block($F_AOR, $name_post, $aor_new);
        upsert_block($F_AUTH, $name_post, $auth_new);
      }
    }

    do_reload();
    header("Location: /trunks.php");
    exit;
  }

  // If error, reflect posted values back
  $enabled = $enabled_post;
  $keepalive = $keepalive_post;
  $transport = $transport_post;
  $outproxy_addr = $outproxy_addr_post;
  $outproxy_port = (string)$outproxy_port_post;

  if($server_required){
    $server_addr = $server_addr_post;
    $server_port = (string)$server_port_post;
  }
  if($type_post === "register" && $mode_post === "client"){
    $username = $username_post;
    $auth_id  = $auth_id_post;
    $from_user = $from_user_post;
    $from_domain = $from_domain_post;
    $identify_by_ui = $identify_by_ui_post;
  } else {
    $username = $name;
    $auth_id  = $name;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>EDIT TRUNK</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/dist/assets/rcm.css">

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

    .panel-box input[readonly],
    .panel-box select:disabled {
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

    .locked-badge {
      display: inline-block;
      font-size: 11px;
      font-family: 'Orbitron', sans-serif;
      letter-spacing: 1px;
      opacity: 0.55;
      margin-left: 6px;
      vertical-align: middle;
    }

    .btnrow {
      display: flex;
      gap: 12px;
      margin-top: 22px;
      flex-wrap: wrap;
    }

    .top { margin-bottom: 6px; }

    @media (max-width: 900px) {
      .grid { grid-template-columns: 1fr; }
      .full { grid-column: 1; }
    }
  </style>
</head>

<body>
<div class="wrap">

  <h1 class="page-title">EDIT TRUNK</h1>

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
          <input type="checkbox" id="enabled" name="enabled" <?php echo $enabled ? "checked" : ""; ?>>
          <label for="enabled">Enable Trunk</label>
        </div>

        <!-- LOCKED hidden inputs -->
        <input type="hidden" name="type_locked" value="<?php echo htmlspecialchars($type); ?>">
        <input type="hidden" name="register_mode_locked" value="<?php echo htmlspecialchars($register_mode); ?>">
        <input type="hidden" name="name_locked" value="<?php echo htmlspecialchars($name); ?>">

        <!-- Row 1: Type / Mode / Name -->
        <div class="grid">
          <div>
            <label>Type <span class="locked-badge">[LOCKED]</span></label>
            <select disabled>
              <option <?php echo $type==="peer" ? "selected" : ""; ?>>Peer Trunk</option>
              <option <?php echo $type==="register" ? "selected" : ""; ?>>Register Trunk</option>
            </select>
          </div>

          <div id="regModeWrap" style="<?php echo ($type==="register") ? "" : "display:none;"; ?>">
            <label>Register Mode <span class="locked-badge">[LOCKED]</span></label>
            <select disabled>
              <option <?php echo $register_mode==="client" ? "selected" : ""; ?>>Client Mode</option>
              <option <?php echo $register_mode==="server" ? "selected" : ""; ?>>Server Mode</option>
            </select>
          </div>

          <div>
            <label>Name <span class="locked-badge">[LOCKED]</span></label>
            <input type="text" value="<?php echo htmlspecialchars($name); ?>" readonly>
          </div>
        </div>

        <hr class="section-divider">

        <!-- Row 2: Username / Auth ID / Password -->
        <div class="grid">
          <div id="userWrap">
            <label>User Name</label>
            <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($username); ?>">
            <div class="hint" id="userHint"></div>
          </div>

          <div id="authIdWrap">
            <label>Auth ID</label>
            <input type="text" name="auth_id" id="auth_id" value="<?php echo htmlspecialchars($auth_id); ?>">
            <div class="hint" id="authHint"></div>
          </div>

          <div id="passwordWrap" style="<?php echo ($type==="register") ? "" : "display:none;"; ?>">
            <label>Password <?php echo ($type==="register" ? "(leave blank to keep)" : ""); ?></label>
            <input type="password" name="password" id="password"
              placeholder="<?php echo ($type==="register" ? "Leave blank to keep existing" : ""); ?>">
          </div>
        </div>

        <hr class="section-divider">

        <!-- Row 3: Server / Port -->
        <div class="grid" id="serverWrap" style="<?php echo ($type==="register" && $register_mode==="server") ? "display:none;" : ""; ?>">
          <div>
            <label>Server Address</label>
            <input type="text" name="server_addr" id="server_addr"
              value="<?php echo htmlspecialchars($server_addr); ?>"
              placeholder="e.g. sip.provider.com">
          </div>
          <div>
            <label>Port</label>
            <input type="number" name="server_port" id="server_port"
              value="<?php echo htmlspecialchars($server_port); ?>"
              min="1" max="65535">
          </div>
        </div>

        <!-- Row 4: Keep-alive / Transport -->
        <div class="grid" style="margin-top:14px;">
          <div>
            <label>Keep-alive Frequency (sec)</label>
            <input type="number" name="keepalive" value="<?php echo (int)$keepalive; ?>" min="1" required>
          </div>
          <div>
            <label>Transport</label>
            <select name="transport" id="transport">
              <option value="transport-udp" <?php echo $transport==="transport-udp" ? "selected" : ""; ?>>UDP</option>
              <option value="transport-tcp" <?php echo $transport==="transport-tcp" ? "selected" : ""; ?>>TCP</option>
              <option value="transport-tls" <?php echo $transport==="transport-tls" ? "selected" : ""; ?>>TLS</option>
            </select>
          </div>
        </div>

        <!-- Row 5: Outbound Proxy -->
        <div class="grid" style="margin-top:14px;">
          <div>
            <label>Out Proxy Server <span class="locked-badge">(optional)</span></label>
            <input type="text" name="outproxy_addr"
              value="<?php echo htmlspecialchars($outproxy_addr); ?>"
              placeholder="e.g. proxy.provider.com">
          </div>
          <div>
            <label>Out Proxy Port <span class="locked-badge">(optional)</span></label>
            <input type="number" name="outproxy_port"
              value="<?php echo htmlspecialchars($outproxy_port); ?>"
              min="0" max="65535">
          </div>
        </div>

        <!-- Register Client Extra Fields -->
        <div class="full" id="regExtra" style="<?php echo ($type==="register" && $register_mode==="client") ? "" : "display:none;"; ?>">
          <hr class="section-divider">
          <div class="grid">
            <div>
              <label>From User <span class="locked-badge">(optional)</span></label>
              <input type="text" name="from_user" id="from_user"
                value="<?php echo htmlspecialchars($from_user); ?>"
                placeholder="From User">
            </div>
            <div>
              <label>From Domain <span class="locked-badge">(optional)</span></label>
              <input type="text" name="from_domain" id="from_domain"
                value="<?php echo htmlspecialchars($from_domain); ?>"
                placeholder="From Domain">
            </div>
            <div>
              <label>Identify By</label>
              <select name="identify_by" id="identify_by">
                <option value="auth_user" <?php echo ($identify_by_ui==="auth_user") ? "selected" : ""; ?>>Auth User</option>
                <option value="username"  <?php echo ($identify_by_ui==="username")  ? "selected" : ""; ?>>User Name</option>
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
  const TRUNK_TYPE = "<?php echo htmlspecialchars($type, ENT_QUOTES); ?>";
  const REG_MODE   = "<?php echo htmlspecialchars($register_mode, ENT_QUOTES); ?>";
  const TRUNK_NAME = "<?php echo htmlspecialchars($name, ENT_QUOTES); ?>";

  const usernameEl = document.getElementById('username');
  const authIdEl   = document.getElementById('auth_id');
  const userHint   = document.getElementById('userHint');
  const authHint   = document.getElementById('authHint');

  function sanitizeVal(v){
    return (v || '').replace(/[^A-Za-z0-9_-]/g,'');
  }

  function setRequired(el, req){
    if (!el) return;
    if (req) el.setAttribute('required','required');
    else el.removeAttribute('required');
  }

  function refreshUI(){
    const isReg    = (TRUNK_TYPE === "register");
    const isClient = isReg && (REG_MODE === "client");

    const serverRequired = (!isReg) || isClient;
    const serverAddr = document.getElementById('server_addr');
    const serverPort = document.getElementById('server_port');
    if (serverAddr) setRequired(serverAddr, serverRequired);
    if (serverPort) setRequired(serverPort, serverRequired);

    if (!isClient){
      usernameEl.readOnly = true;
      authIdEl.readOnly = true;
      usernameEl.value = TRUNK_NAME;
      authIdEl.value = TRUNK_NAME;
      userHint.textContent = "Auto = Name (locked by trunk mode)";
      authHint.textContent = "Auto = Name (locked by trunk mode)";
    } else {
      usernameEl.readOnly = false;
      authIdEl.readOnly = false;
      usernameEl.value = sanitizeVal(usernameEl.value);
      authIdEl.value = sanitizeVal(authIdEl.value);
      userHint.textContent = "Editable (can differ from Name)";
      authHint.textContent = "Editable (can differ from Name)";
      setRequired(usernameEl, true);
      setRequired(authIdEl, true);
    }
  }

  usernameEl.addEventListener('input', () => usernameEl.value = sanitizeVal(usernameEl.value));
  authIdEl.addEventListener('input',   () => authIdEl.value   = sanitizeVal(authIdEl.value));

  refreshUI();
</script>
</body>
</html>