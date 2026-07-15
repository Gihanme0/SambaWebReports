<?php
/*
 * Shared Kynix report navigation.
 * Purpose: renders the project topbar, logo, mobile menu trigger, and active report links.
 * Maintenance: update links here when adding or renaming a report; keep labels short for mobile.
 */
if (!isset($reportName)) { $reportName = 'Report'; }
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
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
        <a class="<?php echo ($currentPage == 'vanzariPerioada') ? 'active' : ''; ?>" href="./vanzariPerioada.php">Periodic Sales</a>
        <a class="<?php echo ($currentPage == 'vanzari') ? 'active' : ''; ?>" href="./vanzari.php">Daily Sales</a>
        <a class="<?php echo ($currentPage == 'nir') ? 'active' : ''; ?>" href="./nir.php">Purchase History</a>
        <a class="<?php echo ($currentPage == 'consum') ? 'active' : ''; ?>" href="./consum.php">Consumption</a>
        <a class="<?php echo ($currentPage == 'stoc') ? 'active' : ''; ?>" href="./stoc.php">Stock</a>
        <a class="<?php echo ($currentPage == 'inventoryDaily') ? 'active' : ''; ?>" href="./inventoryDaily.php">Inventory Daily</a>
    </nav>
</header>


