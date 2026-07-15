<?php
/*
 * CSRF helpers for state-changing WebReports forms.
 */
require_once __DIR__ . '/auth.php';

function csrf_token() {
    auth_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . auth_h(csrf_token()) . '">';
}

function csrf_validate($token) {
    auth_session_start();
    if (!isset($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require_valid() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!csrf_validate($token)) {
            http_response_code(403);
            auth_audit(auth_current_user_id(), 'csrf_rejected', 'Security', null, 'Invalid CSRF token.');
            echo 'Invalid request token.';
            exit;
        }
    }
}
?>
