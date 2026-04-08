<?php
require_once __DIR__ . '/auth.php';
rcm_require_login();

// --- Cache headers ------------------------------------------------------------
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// --- Constants ----------------------------------------------------------------
const PI_JSON = '/etc/asterisk/rcm_paging_intercom.json';

// --- CSRF helpers -------------------------------------------------------------
function rcm_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function rcm_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(rcm_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// --- Flash message (session-based, not GET-based) -----------------------------
function rcm_flash_get(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $msg = (string)($_SESSION['flash_msg'] ?? '');
    unset($_SESSION['flash_msg']);
    return $msg;
}

// --- Data helpers -------------------------------------------------------------
function rcm_load_pi(string $path): array {
    if (!file_exists($path)) {
        return ['items' => []];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['items' => []];
    }

    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['items' => []];
    }

    if (isset($j['items']) && is_array($j['items'])) {
        return $j;
    }

    if (array_is_list($j)) {          // PHP 8.1+
        return ['items' => $j];
    }

    return ['items' => []];
}

/**
 * Normalise a value that may be an array or a comma-separated string
 * into a clean array of trimmed, non-empty strings.
 */
function rcm_to_array(mixed $value): array {
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), 'strlen'));
    }
    return array_values(array_filter(array_map('trim', explode(',', (string)$value)), 'strlen'));
}

// --- Load & sort data ---------------------------------------------------------
$data  = rcm_load_pi(PI_JSON);
$items = $data['items'] ?? [];

usort($items, static function (array $a, array $b): int {
    return strnatcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
});

$msg = rcm_flash_get();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paging / Intercom</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/rcm.css">
  <link rel="icon" type="image/png" href="/assets/rcm/logo.png">
  <style>
    .ext-pill {
      font-family: 'Orbitron', sans-serif;
      letter-spacing: 2px;
    }

    /* -- Fix Actions column clipping -- */
    table {
      width: 100%;
      table-layout: auto;        /* let columns breathe */
    }

    th:last-child,
    td.actions {
      white-space: nowrap;       /* keep buttons on one line */
      min-width: 160px;          /* enough room for EDIT + DELETE */
      width: 1%;                 /* shrink to content, don't stretch */
    }

    td.actions {
      padding-right: 1rem;       /* small breathing room on the right edge */
    }

    td.actions form {
      display: inline;
    }
  </style>
</head>
<body>
<div class="wrap">
  <h1 class="page-title">Paging / Intercom</h1>

  <div class="inner-box">

    <!-- -- Top Row -------------------------------------------------------- -->
    <div class="top">
      <div class="btn-row">
        <a class="btn" href="dashboard.php">BACK</a>
        <a class="btn" href="add_paging_intercom.php">ADD</a>
      </div>

      <?php if ($msg !== ''): ?>
        <span class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
    </div>

    <!-- -- Main Panel ----------------------------------------------------- -->
    <div class="panel-box">
      <table>
        <thead>
          <tr>
            <th>Extension</th>
            <th>Name</th>
            <th>Type</th>
            <th>Members</th>
            <th>Details</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>

          <?php if (empty($items)): ?>
            <tr>
              <td colspan="6" class="muted">No paging / intercom entries yet.</td>
            </tr>

          <?php else: ?>
            <?php foreach ($items as $x): ?>
              <?php
                $id      = (string)($x['id']      ?? '');
                $name    = (string)($x['name']    ?? '');
                $type    = (string)($x['type']    ?? 'paging');
                $ext     = (string)($x['ext']     ?? $id);
                $enabled = (bool)  ($x['enabled'] ?? true);
                $prompt  = (string)($x['welcome_prompt'] ?? '');

                $members = rcm_to_array($x['members']         ?? []);
                $allowed = rcm_to_array($x['allowed_callers'] ?? []);
              ?>
              <tr>
                <td>
                  <span class="pill ext-pill">
                    <?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>

                <td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td>

                <td>
                  <span class="pill">
                    <?= htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>

                <td class="muted">
                  <?= htmlspecialchars(implode(', ', $members), ENT_QUOTES, 'UTF-8') ?>
                </td>

                <td class="muted">
                  Allowed&nbsp;:
                  <?= empty($allowed)
                        ? 'ANY'
                        : htmlspecialchars(implode(', ', $allowed), ENT_QUOTES, 'UTF-8') ?>
                  <br>
                  Prompt&nbsp;:
                  <?= $prompt !== ''
                        ? htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8')
                        : '–' ?>
                  <br>
                  Status&nbsp;:
                  <span class="pill">
                    <?= $enabled ? 'ENABLED' : 'DISABLED' ?>
                  </span>
                </td>

                <td class="actions">
                  <!-- EDIT — safe GET link -->
                  <a class="btn sm"
                     href="add_paging_intercom.php?id=<?= urlencode($id) ?>">
                    EDIT
                  </a>

                  <!-- DELETE — POST form with CSRF token (never a plain GET link) -->
                  <form method="POST"
                        action="save_paging_intercom.php"
                        style="display:inline"
                        onsubmit="return confirm('Delete <?= htmlspecialchars(json_encode($id), ENT_QUOTES, 'UTF-8') ?> ?')">
                    <?= rcm_csrf_field() ?>
                    <input type="hidden" name="delete" value="1">
                    <input type="hidden" name="id"     value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn sm">DELETE</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>

        </tbody>
      </table>
    </div><!-- /.panel-box -->
  </div><!-- /.inner-box -->
</div><!-- /.wrap -->
</body>
</html>
