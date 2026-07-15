<?php
/*
 * Shared WebReports authentication and authorization helpers.
 * Responsibilities: secure sessions, login/logout, permission checks, password policy, and audit logging.
 */
require_once __DIR__ . '/permissions.php';

define('AUTH_SESSION_TIMEOUT', 1800);

function auth_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function auth_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function auth_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = auth_is_https();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ));
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_name('SambaWebReportsAuth');
    session_start();
}

function auth_db() {
    static $loaded = false;
    global $conn, $BusinessName, $serverHost, $databaseName, $userName, $password;

    if (!$loaded) {
        ob_start();
        require_once dirname(__DIR__) . '/config.php';
        ob_end_clean();
        $loaded = true;
    }

    return isset($conn) ? $conn : null;
}

function auth_query_all($sql, $params = array()) {
    $conn = auth_db();
    if (!$conn) {
        return false;
    }
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        return false;
    }
    $rows = array();
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function auth_query_one($sql, $params = array()) {
    $rows = auth_query_all($sql, $params);
    if ($rows === false || empty($rows)) {
        return $rows === false ? false : null;
    }
    return $rows[0];
}

function auth_execute($sql, $params = array()) {
    $conn = auth_db();
    if (!$conn) {
        return false;
    }
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        return false;
    }
    sqlsrv_free_stmt($stmt);
    return true;
}

function auth_tables_ready() {
    $row = auth_query_one("SELECT OBJECT_ID('dbo.WebReportUsers', 'U') AS UsersTable, OBJECT_ID('dbo.WebReportRoles', 'U') AS RolesTable");
    return is_array($row) && !empty($row['UsersTable']) && !empty($row['RolesTable']);
}

function auth_current_user_id() {
    auth_session_start();
    return isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
}

function auth_is_authenticated() {
    return auth_current_user_id() !== null;
}

function auth_is_session_expired() {
    auth_session_start();
    return isset($_SESSION['auth_last_activity']) && (time() - (int)$_SESSION['auth_last_activity']) > AUTH_SESSION_TIMEOUT;
}

function auth_touch_session() {
    auth_session_start();
    $_SESSION['auth_last_activity'] = time();
}

function auth_user_permissions($userId) {
    $rows = auth_query_all("
        SELECT DISTINCT p.PermissionKey
        FROM dbo.WebReportUsers u
        INNER JOIN dbo.WebReportUserRoles ur ON ur.UserId = u.Id
        INNER JOIN dbo.WebReportRoles r ON r.Id = ur.RoleId
        INNER JOIN dbo.WebReportRolePermissions rp ON rp.RoleId = r.Id
        INNER JOIN dbo.WebReportPermissions p ON p.Id = rp.PermissionId
        WHERE u.Id = ? AND u.IsActive = 1
    ", array($userId));
    if ($rows === false) {
        return array();
    }
    $permissions = array();
    foreach ($rows as $row) {
        $permissions[] = $row['PermissionKey'];
    }
    return $permissions;
}

function auth_user_roles($userId) {
    $rows = auth_query_all("
        SELECT r.Id, r.Name
        FROM dbo.WebReportRoles r
        INNER JOIN dbo.WebReportUserRoles ur ON ur.RoleId = r.Id
        WHERE ur.UserId = ?
        ORDER BY r.Name
    ", array($userId));
    return $rows === false ? array() : $rows;
}

function auth_current_user() {
    static $cachedUser = false;
    if ($cachedUser !== false) {
        return $cachedUser;
    }

    $userId = auth_current_user_id();
    if (!$userId) {
        $cachedUser = null;
        return null;
    }

    $row = auth_query_one("
        SELECT Id, Username, DisplayName, Email, IsActive, MustChangePassword, LastLoginAt
        FROM dbo.WebReportUsers
        WHERE Id = ? AND IsActive = 1
    ", array($userId));
    if (!is_array($row)) {
        $cachedUser = null;
        return null;
    }

    $row['Permissions'] = auth_user_permissions($userId);
    $row['Roles'] = auth_user_roles($userId);
    $cachedUser = $row;
    return $cachedUser;
}

function auth_current_role_label() {
    $user = auth_current_user();
    if (!$user || empty($user['Roles'])) {
        return '';
    }
    $names = array();
    foreach ($user['Roles'] as $role) {
        $names[] = $role['Name'];
    }
    return implode(', ', $names);
}

function auth_has_permission($permissionKey) {
    $user = auth_current_user();
    if (!$user) {
        return false;
    }
    return in_array($permissionKey, $user['Permissions'], true);
}

function auth_has_any_permission($permissionKeys) {
    foreach ($permissionKeys as $permissionKey) {
        if (auth_has_permission($permissionKey)) {
            return true;
        }
    }
    return false;
}

function auth_safe_return_url($value) {
    $value = trim((string)$value);
    if ($value === '' || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        return './index.php';
    }
    $parts = parse_url($value);
    if (isset($parts['scheme']) || isset($parts['host']) || strpos($value, '//') === 0) {
        return './index.php';
    }
    if ($value[0] === '/') {
        return $value;
    }
    return './' . ltrim($value, './');
}

function auth_current_request_path() {
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'index.php';
    $base = basename(dirname($_SERVER['SCRIPT_NAME']));
    return auth_safe_return_url($uri);
}

function auth_redirect($url) {
    header('Location: ' . $url);
    exit;
}

function auth_login_url($reason = '') {
    $return = rawurlencode(auth_current_request_path());
    $url = './login.php?return=' . $return;
    if ($reason !== '') {
        $url .= '&reason=' . rawurlencode($reason);
    }
    return $url;
}

function auth_require_login() {
    auth_session_start();
    if (auth_is_authenticated() && auth_is_session_expired()) {
        auth_clear_session();
        auth_redirect(auth_login_url('expired'));
    }
    if (!auth_is_authenticated()) {
        auth_redirect(auth_login_url());
    }
    auth_touch_session();
    $user = auth_current_user();
    if (!$user) {
        auth_clear_session();
        auth_redirect(auth_login_url());
    }
    $page = basename($_SERVER['PHP_SELF']);
    if (!empty($user['MustChangePassword']) && $page !== 'change-password.php' && $page !== 'logout.php') {
        auth_redirect('./change-password.php');
    }
}

function auth_require_permission($permissionKey) {
    auth_require_login();
    if (!auth_has_permission($permissionKey)) {
        auth_redirect('./unauthorized.php?permission=' . rawurlencode($permissionKey));
    }
}

function auth_require_any_permission($permissionKeys) {
    auth_require_login();
    if (!auth_has_any_permission($permissionKeys)) {
        auth_redirect('./unauthorized.php');
    }
}

function auth_require_page_permission($pageName) {
    $map = auth_page_permission_map();
    if (isset($map[$pageName])) {
        auth_require_permission($map[$pageName]);
    } else {
        auth_require_login();
    }
}

function auth_require_export_permission($type) {
    if ($type === 'excel') {
        auth_require_permission('exports.excel');
    } elseif ($type === 'pdf') {
        auth_require_permission('exports.pdf');
    } elseif ($type === 'print') {
        auth_require_permission('exports.print');
    }
}

function auth_login($username, $passwordValue) {
    auth_session_start();
    $username = trim((string)$username);
    if ($username === '' || $passwordValue === '') {
        return false;
    }

    $user = auth_query_one("
        SELECT Id, Username, DisplayName, PasswordHash, IsActive, MustChangePassword
        FROM dbo.WebReportUsers
        WHERE Username = ?
    ", array($username));

    if (!is_array($user) || empty($user['IsActive']) || !password_verify($passwordValue, $user['PasswordHash'])) {
        auth_audit(is_array($user) ? (int)$user['Id'] : null, 'login_failed', 'User', is_array($user) ? $user['Username'] : $username, 'Failed login attempt.');
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = (int)$user['Id'];
    $_SESSION['auth_last_activity'] = time();
    auth_execute("UPDATE dbo.WebReportUsers SET LastLoginAt = GETDATE(), UpdatedAt = GETDATE() WHERE Id = ?", array((int)$user['Id']));
    auth_audit((int)$user['Id'], 'login_success', 'User', $user['Username'], 'User signed in.');
    return true;
}

function auth_clear_session() {
    auth_session_start();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function auth_logout() {
    $userId = auth_current_user_id();
    if ($userId) {
        auth_audit($userId, 'logout', 'User', (string)$userId, 'User signed out.');
    }
    auth_clear_session();
}

function auth_audit($userId, $action, $entityType = null, $entityId = null, $details = null) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    auth_execute("
        INSERT INTO dbo.WebReportAuditLogs (UserId, Action, EntityType, EntityId, Details, IpAddress)
        VALUES (?, ?, ?, ?, ?, ?)
    ", array($userId, $action, $entityType, $entityId, $details, $ip));
}

function auth_password_policy_errors($passwordValue) {
    $errors = array();
    if (strlen($passwordValue) < 10) { $errors[] = 'Use at least 10 characters.'; }
    if (!preg_match('/[A-Z]/', $passwordValue)) { $errors[] = 'Add an uppercase letter.'; }
    if (!preg_match('/[a-z]/', $passwordValue)) { $errors[] = 'Add a lowercase letter.'; }
    if (!preg_match('/[0-9]/', $passwordValue)) { $errors[] = 'Add a number.'; }
    if (!preg_match('/[^A-Za-z0-9]/', $passwordValue)) { $errors[] = 'Add a special character.'; }
    return $errors;
}

function auth_create_user($username, $displayName, $email, $passwordValue, $mustChangePassword, $isActive, $roleIds) {
    $hash = password_hash($passwordValue, PASSWORD_DEFAULT);
    $ok = auth_execute("
        INSERT INTO dbo.WebReportUsers (Username, DisplayName, Email, PasswordHash, IsActive, MustChangePassword)
        VALUES (?, ?, ?, ?, ?, ?)
    ", array($username, $displayName, $email !== '' ? $email : null, $hash, $isActive ? 1 : 0, $mustChangePassword ? 1 : 0));
    if (!$ok) {
        return false;
    }
    $row = auth_query_one("SELECT Id FROM dbo.WebReportUsers WHERE Username = ?", array($username));
    if (!is_array($row)) {
        return false;
    }
    auth_set_user_roles((int)$row['Id'], $roleIds);
    auth_audit(auth_current_user_id(), 'user_create', 'User', $username, 'User created.');
    return (int)$row['Id'];
}

function auth_set_user_roles($userId, $roleIds) {
    auth_execute("DELETE FROM dbo.WebReportUserRoles WHERE UserId = ?", array($userId));
    foreach ($roleIds as $roleId) {
        auth_execute("INSERT INTO dbo.WebReportUserRoles (UserId, RoleId) VALUES (?, ?)", array($userId, (int)$roleId));
    }
}

function auth_first_super_admin_exists() {
    $row = auth_query_one("
        SELECT TOP 1 u.Id
        FROM dbo.WebReportUsers u
        INNER JOIN dbo.WebReportUserRoles ur ON ur.UserId = u.Id
        INNER JOIN dbo.WebReportRoles r ON r.Id = ur.RoleId
        WHERE r.Name = 'Super Admin' AND u.IsActive = 1
    ");
    return is_array($row);
}
?>
