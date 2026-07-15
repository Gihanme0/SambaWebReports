<?php
require_once __DIR__ . '/auth/auth.php';
require_once __DIR__ . '/auth/csrf.php';

auth_require_login();
$user = auth_current_user();
$errors = array();
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $current = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $new = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $stored = auth_query_one("SELECT PasswordHash FROM dbo.WebReportUsers WHERE Id = ?", array((int)$user['Id']));

    if (!is_array($stored) || !password_verify($current, $stored['PasswordHash'])) {
        $errors[] = 'Current password is not correct.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }
    $errors = array_merge($errors, auth_password_policy_errors($new));

    if (empty($errors)) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        if (auth_execute("UPDATE dbo.WebReportUsers SET PasswordHash = ?, MustChangePassword = 0, UpdatedAt = GETDATE() WHERE Id = ?", array($hash, (int)$user['Id']))) {
            auth_audit((int)$user['Id'], 'password_change', 'User', $user['Username'], 'User changed own password.');
            $success = true;
        } else {
            $errors[] = 'Password could not be updated.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link href="./css/vanzari.css" rel="stylesheet" media="screen">
    <style>
        body.password-page { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #eef3f8; font-family: Arial, sans-serif; color: #14243a; }
        .password-card { width: min(520px, calc(100% - 32px)); background: #fff; border: 1px solid #dbe4ef; border-radius: 12px; padding: 28px; box-shadow: 0 16px 40px rgba(16, 38, 72, .12); }
        .password-card h1 { margin: 0 0 8px; font-size: 24px; }
        .password-card p { margin: 0 0 18px; color: #64748b; }
        .password-field { margin-bottom: 14px; }
        .password-field label { display: block; font-weight: 800; margin-bottom: 6px; }
        .password-field input { width: 100%; box-sizing: border-box; border: 1px solid #cfd9e6; border-radius: 8px; padding: 11px 12px; font-size: 15px; }
        .password-alert { border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
        .password-alert.error { background: #fff1f1; color: #9b1c1c; border: 1px solid #f2c2c2; }
        .password-alert.ok { background: #eefbf4; color: #17683a; border: 1px solid #bde8cb; }
        .password-btn { border: 0; border-radius: 8px; background: #185abc; color: #fff; padding: 12px 16px; font-weight: 800; cursor: pointer; }
        .password-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .password-actions a { color: #185abc; font-weight: 800; text-decoration: none; }
    </style>
</head>
<body class="password-page">
<main class="password-card">
    <h1>Change password</h1>
    <p>Use a strong password before continuing to reports.</p>
    <?php if ($success) { ?><div class="password-alert ok">Password updated. You can continue to the dashboard.</div><?php } ?>
    <?php if (!empty($errors)) { ?><div class="password-alert error"><?php foreach ($errors as $error) { echo '<div>' . auth_h($error) . '</div>'; } ?></div><?php } ?>
    <form method="post" action="./change-password.php">
        <?php echo csrf_field(); ?>
        <div class="password-field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required></div>
        <div class="password-field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" required></div>
        <div class="password-field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" required></div>
        <div class="password-actions">
            <button class="password-btn" type="submit">Update Password</button>
            <a href="./index.php">Dashboard</a>
            <a href="./logout.php">Sign Out</a>
        </div>
    </form>
</main>
</body>
</html>
