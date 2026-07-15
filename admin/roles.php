<?php
require_once __DIR__ . '/_admin.php';
auth_require_permission('roles.manage');

$roles = auth_query_all("
    SELECT r.Id, r.Name, r.Description, r.IsSystemRole,
        COUNT(DISTINCT ur.UserId) AS UserCount,
        COUNT(DISTINCT rp.PermissionId) AS PermissionCount
    FROM dbo.WebReportRoles r
    LEFT JOIN dbo.WebReportUserRoles ur ON ur.RoleId = r.Id
    LEFT JOIN dbo.WebReportRolePermissions rp ON rp.RoleId = r.Id
    GROUP BY r.Id, r.Name, r.Description, r.IsSystemRole
    ORDER BY r.Name
");

admin_layout_start('Role Management', 'roles');
?>
<section class="admin-panel">
    <div class="admin-actions" style="justify-content:flex-end;margin-bottom:14px"><a class="admin-btn admin-primary" href="./role-edit.php">Create Role</a></div>
    <table class="admin-table">
        <thead><tr><th>Role</th><th>Description</th><th>Users</th><th>Permissions</th><th>Type</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach (($roles === false ? array() : $roles) as $role) { ?>
            <tr>
                <td><strong><?php echo admin_h($role['Name']); ?></strong></td>
                <td><?php echo admin_h($role['Description']); ?></td>
                <td><?php echo (int)$role['UserCount']; ?></td>
                <td><?php echo (int)$role['PermissionCount']; ?></td>
                <td><span class="admin-badge"><?php echo !empty($role['IsSystemRole']) ? 'System' : 'Custom'; ?></span></td>
                <td><a class="admin-btn admin-secondary" href="./role-edit.php?id=<?php echo (int)$role['Id']; ?>">Edit</a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</section>
<?php admin_layout_end(); ?>
