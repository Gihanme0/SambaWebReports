<?php
/*
 * Shared Kynix report navigation.
 * Purpose: renders the project topbar, logo, mobile menu trigger, and active report links.
 * Maintenance: update links here when adding or renaming a report; keep labels short for mobile.
 */
require_once __DIR__ . '/auth/auth.php';
if (!isset($reportName)) { $reportName = 'Report'; }
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$authUser = auth_current_user();
$authRoleLabel = auth_current_role_label();
?>
<header class="kx-topbar">
    <div class="kx-brand">
        <a href="./index.php" class="kx-logo-wrap" aria-label="Kynix Technologies Home">
            <img src="./img/logo.png" alt="Kynix Technologies" class="kx-logo">
        </a>
        <div>
            <div class="kx-brand-title">Kynix Technologies</div>
            <div class="kx-brand-subtitle">Smart POS Report Viewer</div>
        </div>
    </div>

    <button type="button" class="kx-menu-btn" id="kxMenuBtn" aria-label="Open Menu">
        <span></span><span></span><span></span>
    </button>

    <nav class="kx-nav" id="kxNav">
        <?php foreach (auth_report_nav_items() as $navItem) { ?>
            <?php if (auth_has_permission($navItem['permission'])) { ?>
                <a class="<?php echo ($currentPage == $navItem['page']) ? 'active' : ''; ?>" href="<?php echo auth_h($navItem['href']); ?>"><?php echo auth_h($navItem['label']); ?></a>
            <?php } ?>
        <?php } ?>
        <?php if (auth_has_any_permission(array('users.manage', 'roles.manage', 'audit.view'))) { ?>
            <a href="./admin/index.php">Admin</a>
        <?php } ?>
        <?php if ($authUser) { ?>
            <span class="kx-user-chip" title="<?php echo auth_h($authRoleLabel); ?>"><?php echo auth_h($authUser['DisplayName']); ?><?php echo $authRoleLabel !== '' ? ' · ' . auth_h($authRoleLabel) : ''; ?></span>
            <a href="./change-password.php">Password</a>
            <a href="./logout.php">Logout</a>
        <?php } ?>
    </nav>
</header>


