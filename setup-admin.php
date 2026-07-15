<?php
/*
 * One-time first Super Admin setup page.
 */
require_once __DIR__ . '/auth/auth.php';
require_once __DIR__ . '/auth/csrf.php';

$errors = array();
$success = false;
$tablesReady = auth_tables_ready();
$disabled = !$tablesReady || auth_first_super_admin_exists();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$disabled) {
    csrf_require_valid();
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $displayName = trim(isset($_POST['display_name']) ? $_POST['display_name'] : '');
    $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
    $passwordValue = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    if ($username === '') { $errors[] = 'Username is required.'; }
    if ($displayName === '') { $errors[] = 'Display name is required.'; }
    if ($passwordValue !== $confirm) { $errors[] = 'Passwords do not match.'; }
    $errors = array_merge($errors, auth_password_policy_errors($passwordValue));

    if (empty($errors)) {
        $role = auth_query_one("SELECT Id FROM dbo.WebReportRoles WHERE Name = 'Super Admin'");
        if (!is_array($role)) {
            $errors[] = 'Super Admin role was not found. Re-run the installation script.';
        } else {
            $createdId = auth_create_user($username, $displayName, $email, $passwordValue, false, true, array((int)$role['Id']));
            if ($createdId === false) {
                $errors[] = 'Could not create the administrator. Check for duplicate username or email.';
            } else {
                auth_audit($createdId, 'first_admin_create', 'User', $username, 'First Super Admin created.');
                $success = true;
                $disabled = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create First Super Admin</title>
    <link href="./css/vanzari.css" rel="stylesheet" media="screen">
    <style>
        body.setup-page { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #eef3f8; font-family: Arial, sans-serif; color: #14243a; }
        .setup-card { width: min(560px, calc(100% - 32px)); background: #fff; border: 1px solid #dbe4ef; border-radius: 12px; padding: 28px; box-shadow: 0 16px 40px rgba(16, 38, 72, .12); }
        .setup-card h1 { margin: 0 0 8px; font-size: 24px; }
        .setup-card p { margin: 0 0 18px; color: #64748b; line-height: 1.45; }
        .setup-field { margin-bottom: 14px; }
        .setup-field label { display: block; font-weight: 800; margin-bottom: 6px; }
        .setup-field input { width: 100%; box-sizing: border-box; border: 1px solid #cfd9e6; border-radius: 8px; padding: 11px 12px; font-size: 15px; }
        .setup-alert { border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
        .setup-alert.error { background: #fff1f1; color: #9b1c1c; border: 1px solid #f2c2c2; }
        .setup-alert.ok { background: #eefbf4; color: #17683a; border: 1px solid #bde8cb; }
        .setup-btn { border: 0; border-radius: 8px; background: #185abc; color: #fff; padding: 12px 16px; font-weight: 800; cursor: pointer; }
        .setup-link { color: #185abc; font-weight: 800; text-decoration: none; }
    </style>
</head>
<body class="setup-page">
<main class="setup-card">
    <h1>Create first Super Admin</h1>
    <p>This page disables itself after an active Super Admin exists. It does not create a default password.</p>
    <?php if (!$tablesReady) { ?><div class="setup-alert error">Authentication tables are not installed. Run <code>database/webreports-auth-install.sql</code> first.</div><?php } ?>
    <?php if ($disabled && $tablesReady && !$success) { ?><div class="setup-alert ok">A Super Admin already exists. Use the sign-in page.</div><?php } ?>
    <?php if ($success) { ?><div class="setup-alert ok">Super Admin created. <a class="setup-link" href="./login.php">Sign in now</a>.</div><?php } ?>
    <?php if (!empty($errors)) { ?><div class="setup-alert error"><?php foreach ($errors as $error) { echo '<div>' . auth_h($error) . '</div>'; } ?></div><?php } ?>
    <?php if (!$disabled) { ?>
    <form method="post" action="./setup-admin.php">
        <?php echo csrf_field(); ?>
        <div class="setup-field"><label for="username">Username</label><input id="username" name="username" type="text" required autofocus></div>
        <div class="setup-field"><label for="display_name">Display name</label><input id="display_name" name="display_name" type="text" required></div>
        <div class="setup-field"><label for="email">Email</label><input id="email" name="email" type="email"></div>
        <div class="setup-field"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
        <div class="setup-field"><label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" required></div>
        <button class="setup-btn" type="submit">Create Super Admin</button>
    </form>
    <?php } ?>
</main>
</body>
</html>
