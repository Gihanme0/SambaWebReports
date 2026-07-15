<?php
/*
 * Kynix WebReports sign-in page.
 */
require_once __DIR__ . '/auth/auth.php';
require_once __DIR__ . '/auth/csrf.php';

auth_session_start();
$error = '';
$notice = '';
$returnUrl = isset($_GET['return']) ? auth_safe_return_url($_GET['return']) : './index.php';

if (isset($_GET['reason']) && $_GET['reason'] === 'expired') {
    $notice = 'Your session expired. Please sign in again.';
}

if (auth_is_authenticated() && !auth_is_session_expired()) {
    auth_redirect($returnUrl);
}

$tablesReady = auth_tables_ready();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $returnUrl = isset($_POST['return']) ? auth_safe_return_url($_POST['return']) : './index.php';
    if (!$tablesReady) {
        $error = 'Authentication is not installed yet. Run the database installer first.';
    } elseif (auth_login(isset($_POST['username']) ? $_POST['username'] : '', isset($_POST['password']) ? $_POST['password'] : '')) {
        $user = auth_current_user();
        if ($user && !empty($user['MustChangePassword'])) {
            auth_redirect('./change-password.php');
        }
        auth_redirect($returnUrl);
    } else {
        $error = 'Sign in failed. Check your username and password.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kynix WebReports Sign In</title>
    <link href="./css/vanzari.css" rel="stylesheet" media="screen">
    <style>
        body.kx-login-page { min-height: 100vh; margin: 0; background: #eef3f8; color: #132238; font-family: Arial, sans-serif; }
        .login-wrap { min-height: 100vh; display: grid; place-items: center; padding: 28px; }
        .login-card { width: min(430px, 100%); background: #fff; border: 1px solid #dbe4ef; border-radius: 12px; box-shadow: 0 18px 45px rgba(15, 35, 60, .14); padding: 30px; }
        .login-brand { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
        .login-brand img { width: 54px; height: auto; }
        .login-brand h1 { font-size: 22px; margin: 0; letter-spacing: 0; }
        .login-brand p { margin: 3px 0 0; color: #65758a; font-size: 13px; }
        .login-field { margin-bottom: 16px; }
        .login-field label { display: block; margin-bottom: 7px; font-weight: 700; color: #26384f; }
        .login-field input { width: 100%; box-sizing: border-box; border: 1px solid #cfd9e6; border-radius: 8px; padding: 12px 13px; font-size: 15px; }
        .password-row { position: relative; }
        .password-row button { position: absolute; right: 8px; top: 8px; border: 0; background: transparent; color: #1e65b7; font-weight: 700; cursor: pointer; padding: 6px; }
        .login-alert { border-radius: 8px; padding: 11px 13px; margin-bottom: 16px; font-size: 14px; }
        .login-alert.error { background: #fff1f1; color: #9b1c1c; border: 1px solid #f5c2c2; }
        .login-alert.notice { background: #eef6ff; color: #124d83; border: 1px solid #bddcff; }
        .login-btn { width: 100%; border: 0; border-radius: 8px; background: #185abc; color: #fff; padding: 13px 16px; font-size: 15px; font-weight: 800; cursor: pointer; }
        .login-btn:disabled { opacity: .7; cursor: wait; }
        .setup-link { display: block; margin-top: 14px; text-align: center; color: #185abc; font-weight: 700; text-decoration: none; }
        @media (prefers-color-scheme: dark) {
            body.kx-login-page { background: #101722; color: #e9eef6; }
            .login-card { background: #162131; border-color: #26384f; }
            .login-brand p, .login-field label { color: #b7c5d8; }
            .login-field input { background: #101722; border-color: #384b65; color: #f4f7fb; }
        }
    </style>
</head>
<body class="kx-login-page">
<main class="login-wrap">
    <form class="login-card" method="post" action="./login.php" id="loginForm" novalidate>
        <div class="login-brand">
            <img src="./img/logo.png" alt="Kynix Technologies">
            <div>
                <h1>WebReports Sign In</h1>
                <p>Secure access to business reports</p>
            </div>
        </div>
        <?php if ($notice !== '') { ?><div class="login-alert notice"><?php echo auth_h($notice); ?></div><?php } ?>
        <?php if ($error !== '') { ?><div class="login-alert error"><?php echo auth_h($error); ?></div><?php } ?>
        <?php if (!$tablesReady) { ?><div class="login-alert notice">Authentication tables are not installed. Run <code>database/webreports-auth-install.sql</code>, then create the first admin.</div><?php } ?>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="return" value="<?php echo auth_h($returnUrl); ?>">
        <div class="login-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" required autofocus>
        </div>
        <div class="login-field">
            <label for="password">Password</label>
            <div class="password-row">
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button type="button" id="togglePassword" aria-label="Show password">Show</button>
            </div>
        </div>
        <button class="login-btn" type="submit" id="loginSubmit"<?php echo $tablesReady ? '' : ' disabled'; ?>>Sign In</button>
        <?php if ($tablesReady && !auth_first_super_admin_exists()) { ?><a class="setup-link" href="./setup-admin.php">Create first Super Admin</a><?php } ?>
    </form>
</main>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    var input = document.getElementById('password');
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.textContent = show ? 'Hide' : 'Show';
});
document.getElementById('loginForm').addEventListener('submit', function () {
    var btn = document.getElementById('loginSubmit');
    if (!btn.disabled) { btn.disabled = true; btn.textContent = 'Signing in...'; }
});
</script>
</body>
</html>
