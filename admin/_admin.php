<?php
/*
 * Shared admin panel helpers for WebReports authentication management.
 */
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/auth/csrf.php';

function admin_h($value) {
    return auth_h($value);
}

function admin_layout_start($title, $active) {
    $user = auth_current_user();
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo admin_h($title); ?> - WebReports Admin</title>
    <link href="../css/vanzari.css" rel="stylesheet" media="screen">
    <style>
        body.admin-page{margin:0;background:#eef3f8;color:#14243a;font-family:Arial,sans-serif}.admin-topbar{background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 24px;position:sticky;top:0;z-index:20}.admin-brand{display:flex;align-items:center;gap:12px}.admin-brand img{width:42px;height:42px;background:#fff;border-radius:10px;padding:4px}.admin-brand strong{display:block;font-size:17px}.admin-brand span{display:block;font-size:12px;color:#cbd5e1}.admin-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.admin-nav a{color:#cbd5e1;text-decoration:none;font-weight:800;font-size:13px;padding:10px 12px;border-radius:10px}.admin-nav a.active,.admin-nav a:hover{background:#1e293b;color:#fff}.admin-shell{width:min(1180px,calc(100% - 32px));margin:24px auto}.admin-hero{background:#fff;border:1px solid #dbe4ef;border-radius:12px;padding:20px 22px;margin-bottom:18px;box-shadow:0 12px 32px rgba(15,35,60,.08)}.admin-hero h1{margin:0;font-size:25px}.admin-hero p{margin:7px 0 0;color:#64748b}.admin-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.admin-card,.admin-panel{background:#fff;border:1px solid #dbe4ef;border-radius:12px;box-shadow:0 12px 32px rgba(15,35,60,.08)}.admin-card{padding:18px}.admin-card span{display:block;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.5px}.admin-card strong{display:block;font-size:27px;margin-top:7px}.admin-panel{padding:18px;margin-bottom:18px}.admin-panel h2{margin:0 0 14px;font-size:19px}.admin-table{width:100%;border-collapse:collapse}.admin-table th{background:#0f172a;color:#fff;text-align:left;padding:11px;font-size:12px;text-transform:uppercase}.admin-table td{border-bottom:1px solid #e5e7eb;padding:11px;vertical-align:top}.admin-table tr:nth-child(even){background:#f8fafc}.admin-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.admin-btn{border:0;border-radius:8px;padding:10px 13px;font-weight:900;text-decoration:none;display:inline-block;cursor:pointer}.admin-primary{background:#185abc;color:#fff}.admin-secondary{background:#e8eef7;color:#17395f}.admin-danger{background:#dc2626;color:#fff}.admin-muted{color:#64748b}.admin-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.admin-field{margin-bottom:14px}.admin-field label{display:block;font-weight:900;margin-bottom:6px}.admin-field input,.admin-field select,.admin-field textarea{width:100%;box-sizing:border-box;border:1px solid #cfd9e6;border-radius:8px;padding:10px 11px;font-size:14px}.admin-check-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.admin-check{display:flex;gap:8px;align-items:flex-start;background:#f8fafc;border:1px solid #e5e7eb;border-radius:9px;padding:10px}.admin-check input{margin-top:2px}.admin-alert{border-radius:9px;padding:12px 14px;margin-bottom:14px}.admin-alert.error{background:#fff1f1;color:#9b1c1c;border:1px solid #f2c2c2}.admin-alert.ok{background:#eefbf4;color:#17683a;border:1px solid #bde8cb}.admin-badge{display:inline-block;border-radius:999px;background:#e8eef7;color:#17395f;padding:5px 8px;font-size:12px;font-weight:900}.admin-badge.off{background:#fee2e2;color:#991b1b}@media(max-width:900px){.admin-topbar{align-items:flex-start;flex-direction:column}.admin-grid,.admin-form-grid,.admin-check-grid{grid-template-columns:1fr}.admin-table{min-width:760px}.admin-panel{overflow:auto}}
    </style>
</head>
<body class="admin-page">
<header class="admin-topbar">
    <div class="admin-brand">
        <img src="../img/logo.png" alt="Kynix Technologies">
        <div><strong>WebReports Admin</strong><span><?php echo $user ? admin_h($user['DisplayName']) : ''; ?></span></div>
    </div>
    <nav class="admin-nav">
        <a class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="./index.php">Dashboard</a>
        <?php if (auth_has_permission('users.manage')) { ?><a class="<?php echo $active === 'users' ? 'active' : ''; ?>" href="./users.php">Users</a><?php } ?>
        <?php if (auth_has_permission('roles.manage')) { ?><a class="<?php echo $active === 'roles' ? 'active' : ''; ?>" href="./roles.php">Roles</a><?php } ?>
        <?php if (auth_has_permission('audit.view')) { ?><a class="<?php echo $active === 'audit' ? 'active' : ''; ?>" href="./audit.php">Audit</a><?php } ?>
        <a href="../index.php">Reports</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>
<main class="admin-shell">
    <section class="admin-hero"><h1><?php echo admin_h($title); ?></h1><p>Database-driven users, roles, permissions, and audit controls.</p></section>
    <?php
}

function admin_layout_end() {
    echo '</main></body></html>';
}

function admin_all_roles() {
    $rows = auth_query_all("SELECT Id, Name, Description, IsSystemRole FROM dbo.WebReportRoles ORDER BY Name");
    return $rows === false ? array() : $rows;
}

function admin_all_permissions() {
    $rows = auth_query_all("SELECT Id, PermissionKey, Name, Description, Category FROM dbo.WebReportPermissions ORDER BY Category, Name");
    return $rows === false ? array() : $rows;
}

function admin_group_permissions($permissions) {
    $grouped = array();
    foreach ($permissions as $permission) {
        $category = $permission['Category'];
        if (!isset($grouped[$category])) {
            $grouped[$category] = array();
        }
        $grouped[$category][] = $permission;
    }
    return $grouped;
}

function admin_user_role_ids($userId) {
    $rows = auth_query_all("SELECT RoleId FROM dbo.WebReportUserRoles WHERE UserId = ?", array($userId));
    if ($rows === false) {
        return array();
    }
    $ids = array();
    foreach ($rows as $row) {
        $ids[] = (int)$row['RoleId'];
    }
    return $ids;
}

function admin_role_permission_ids($roleId) {
    $rows = auth_query_all("SELECT PermissionId FROM dbo.WebReportRolePermissions WHERE RoleId = ?", array($roleId));
    if ($rows === false) {
        return array();
    }
    $ids = array();
    foreach ($rows as $row) {
        $ids[] = (int)$row['PermissionId'];
    }
    return $ids;
}
?>
