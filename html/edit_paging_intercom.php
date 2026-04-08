<?php
// edit_paging_intercom.php

require_once 'auth.php';
require_once 'rcm_config.php';
require_once 'rcm_functions.php';

if (!isset($_GET['id'])) {
    header('Location: paging_intercom.php');
    exit;
}

$id = $_GET['id'];
$entry = rcm_get_paging_intercom_by_id($id);
if (!$entry) {
    header('Location: paging_intercom.php');
    exit;
}

$welcomePrompts = rcm_get_media_prompts();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Edit Paging / Intercom - RCM</title>
    <link rel="stylesheet" href="rcm.css">
</head>
<body>

<?php include 'header.php'; ?>
<?php include 'menu.php'; ?>

<div class="content">
    <h1>Edit Paging / Intercom</h1>

    <form method="post" action="save_paging_intercom.php">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($entry['id']); ?>">

        <div class="form-row">
            <label for="name">Name:</label>
            <input type="text" name="name" id="name"
                   value="<?php echo htmlspecialchars($entry['name']); ?>" required>
        </div>

        <div class="form-row">
            <label for="type">Type:</label>
            <select name="type" id="type" required>
                <option value="paging" <?php if ($entry['type'] === 'paging') echo 'selected'; ?>>Paging</option>
                <option value="intercom" <?php if ($entry['type'] === 'intercom') echo 'selected'; ?>>Intercom</option>
            </select>
        </div>

        <div class="form-row">
            <label for="ext_number">Ext Number:</label>
            <input type="text" name="ext_number" id="ext_number"
                   value="<?php echo htmlspecialchars($entry['ext_number']); ?>" required>
        </div>

        <div class="form-row">
            <label for="ring_to">Ring To (ext list):</label>
            <input type="text" name="ring_to" id="ring_to"
                   value="<?php echo htmlspecialchars($entry['ring_to']); ?>" required>
        </div>

        <div class="form-row">
            <label for="allowed_callers">Allowed Callers:</label>
            <input type="text" name="allowed_callers" id="allowed_callers"
                   value="<?php echo htmlspecialchars($entry['allowed_callers']); ?>">
        </div>

        <div class="form-row">
            <label for="welcome_prompt">Welcome Prompt (optional):</label>
            <select name="welcome_prompt" id="welcome_prompt">
                <option value="">None</option>
                <?php foreach ($welcomePrompts as $p): ?>
                    <option value="<?php echo htmlspecialchars($p['name']); ?>"
                        <?php if ($entry['welcome_prompt'] === $p['name']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($p['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="button">Save</button>
            <a href="paging_intercom.php" class="button button-secondary">Cancel</a>
        </div>
    </form>
</div>

</body>
</html>
