<?php
/*
 * WebReports permission catalog and page mapping.
 * Keep permission keys synchronized with database/webreports-auth-install.sql.
 */

function auth_permission_catalog() {
    return array(
        'dashboard.view' => array('name' => 'View Dashboard', 'category' => 'Dashboard'),
        'daily_sales.view' => array('name' => 'View Daily Sales', 'category' => 'Sales'),
        'periodic_sales.view' => array('name' => 'View Periodic Sales', 'category' => 'Sales'),
        'inventory_analytics.view' => array('name' => 'View Inventory Analytics', 'category' => 'Inventory'),
        'purchase_history.view' => array('name' => 'View Purchase History', 'category' => 'Purchasing'),
        'consumption.view' => array('name' => 'View Consumption', 'category' => 'Inventory'),
        'stock.view' => array('name' => 'View Stock', 'category' => 'Inventory'),
        'users.manage' => array('name' => 'Manage Users', 'category' => 'Administration'),
        'roles.manage' => array('name' => 'Manage Roles', 'category' => 'Administration'),
        'permissions.manage' => array('name' => 'Manage Permissions', 'category' => 'Administration'),
        'audit.view' => array('name' => 'View Audit Log', 'category' => 'Administration'),
        'settings.manage' => array('name' => 'Manage Settings', 'category' => 'Administration'),
        'exports.excel' => array('name' => 'Export Excel', 'category' => 'Exports'),
        'exports.pdf' => array('name' => 'Export PDF', 'category' => 'Exports'),
        'exports.print' => array('name' => 'Print Reports', 'category' => 'Exports')
    );
}

function auth_page_permission_map() {
    return array(
        'index.php' => 'dashboard.view',
        'vanzari.php' => 'daily_sales.view',
        'vanzariPerioada.php' => 'periodic_sales.view',
        'inventoryDaily.php' => 'inventory_analytics.view',
        'nir.php' => 'purchase_history.view',
        'consum.php' => 'consumption.view',
        'stoc.php' => 'stock.view'
    );
}

function auth_report_nav_items() {
    return array(
        array('label' => 'Home', 'href' => './index.php', 'page' => 'index', 'permission' => 'dashboard.view'),
        array('label' => 'Periodic Sales', 'href' => './vanzariPerioada.php', 'page' => 'vanzariPerioada', 'permission' => 'periodic_sales.view'),
        array('label' => 'Daily Sales', 'href' => './vanzari.php', 'page' => 'vanzari', 'permission' => 'daily_sales.view'),
        array('label' => 'Purchase History', 'href' => './nir.php', 'page' => 'nir', 'permission' => 'purchase_history.view'),
        array('label' => 'Consumption', 'href' => './consum.php', 'page' => 'consum', 'permission' => 'consumption.view'),
        array('label' => 'Stock', 'href' => './stoc.php', 'page' => 'stoc', 'permission' => 'stock.view'),
        array('label' => 'Inventory Daily', 'href' => './inventoryDaily.php', 'page' => 'inventoryDaily', 'permission' => 'inventory_analytics.view')
    );
}

function auth_admin_nav_items() {
    return array(
        array('label' => 'Admin', 'href' => './admin/index.php', 'permission' => 'users.manage'),
        array('label' => 'Users', 'href' => './admin/users.php', 'permission' => 'users.manage'),
        array('label' => 'Roles', 'href' => './admin/roles.php', 'permission' => 'roles.manage'),
        array('label' => 'Audit', 'href' => './admin/audit.php', 'permission' => 'audit.view')
    );
}

function auth_permission_name($permissionKey) {
    $catalog = auth_permission_catalog();
    return isset($catalog[$permissionKey]) ? $catalog[$permissionKey]['name'] : $permissionKey;
}
?>
