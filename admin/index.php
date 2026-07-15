<?php
require_once __DIR__ . '/_admin.php';
auth_require_any_permission(array('users.manage', 'roles.manage', 'audit.view'));

$totalUsers = auth_query_one("SELECT COUNT(*) AS Cnt FROM dbo.WebReportUsers");
$activeUsers = auth_query_one("SELECT COUNT(*) AS Cnt FROM dbo.WebReportUsers WHERE IsActive = 1");
$roles = auth_query_one("SELECT COUNT(*) AS Cnt FROM dbo.WebReportRoles");
$recentLogins = auth_query_one("SELECT COUNT(*) AS Cnt FROM dbo.WebReportAuditLogs WHERE Action = 'login_success' AND CreatedAt >= DATEADD(day, -7, GETDATE())");
$recentActions = auth_query_all("
    SELECT TOP 10 a.Action, a.EntityType, a.EntityId, a.Details, a.CreatedAt, u.DisplayName
    FROM dbo.WebReportAuditLogs a
    LEFT JOIN dbo.WebReportUsers u ON u.Id = a.UserId
    ORDER BY a.CreatedAt DESC
");

admin_layout_start('Admin Dashboard', 'dashboard');
?>
<section class="admin-grid">
    <article class="admin-card"><span>Total Users</span><strong><?php echo admin_h(isset($totalUsers['Cnt']) ? $totalUsers['Cnt'] : 0); ?></strong></article>
    <article class="admin-card"><span>Active Users</span><strong><?php echo admin_h(isset($activeUsers['Cnt']) ? $activeUsers['Cnt'] : 0); ?></strong></article>
    <article class="admin-card"><span>Roles</span><strong><?php echo admin_h(isset($roles['Cnt']) ? $roles['Cnt'] : 0); ?></strong></article>
    <article class="admin-card"><span>Recent Logins</span><strong><?php echo admin_h(isset($recentLogins['Cnt']) ? $recentLogins['Cnt'] : 0); ?></strong></article>
</section>
<section class="admin-panel">
    <h2>Recent Admin Activity</h2>
    <table class="admin-table">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach (($recentActions === false ? array() : $recentActions) as $row) { ?>
            <tr>
                <td><?php echo admin_h($row['CreatedAt'] instanceof DateTime ? $row['CreatedAt']->format('Y-m-d H:i') : $row['CreatedAt']); ?></td>
                <td><?php echo admin_h($row['DisplayName']); ?></td>
                <td><?php echo admin_h($row['Action']); ?></td>
                <td><?php echo admin_h(trim($row['EntityType'] . ' ' . $row['EntityId'])); ?></td>
                <td><?php echo admin_h($row['Details']); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</section>
<?php admin_layout_end(); ?>
