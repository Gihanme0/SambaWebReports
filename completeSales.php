<?php
require_once __DIR__ . '/auth/auth.php';
auth_require_permission('periodic_sales.view');
header('Location: ./vanzariPerioada.php');
exit;
?>
