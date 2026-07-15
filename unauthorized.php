<?php
require_once __DIR__ . '/auth/auth.php';

auth_require_login();
$permission = isset($_GET['permission']) ? $_GET['permission'] : '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized</title>
    <link href="./css/vanzari.css" rel="stylesheet" media="screen">
    <style>
        body.auth-message { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #eef3f8; font-family: Arial, sans-serif; color: #152238; }
        .auth-message-card { width: min(520px, calc(100% - 32px)); background: #fff; border: 1px solid #dbe4ef; border-radius: 12px; padding: 28px; box-shadow: 0 16px 38px rgba(16, 38, 72, .12); }
        .auth-message-card h1 { margin: 0 0 10px; font-size: 24px; }
        .auth-message-card p { color: #617187; line-height: 1.5; }
        .auth-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
        .auth-actions a { text-decoration: none; border-radius: 8px; padding: 11px 14px; font-weight: 800; }
        .auth-primary { background: #185abc; color: #fff; }
        .auth-secondary { background: #e9eef6; color: #17395f; }
    </style>
</head>
<body class="auth-message">
<section class="auth-message-card">
    <h1>Access not approved</h1>
    <p>Your account is signed in, but it does not have the required permission<?php echo $permission !== '' ? ': ' . auth_h(auth_permission_name($permission)) : '.'; ?></p>
    <div class="auth-actions">
        <a class="auth-primary" href="./index.php">Back to Dashboard</a>
        <a class="auth-secondary" href="./logout.php">Sign Out</a>
    </div>
</section>
</body>
</html>
