<?php
require_once __DIR__ . '/_admin.php';
auth_require_permission('users.manage');

$q = trim(isset($_GET['q']) ? $_GET['q'] : '');
$params = array();
$where = '';
if ($q !== '') {
    $where = "WHERE u.Username LIKE ? OR u.DisplayName LIKE ? OR u.Email LIKE ?";
    $like = '%' . $q . '%';
    $params = array($like, $like, $like);
}
$users = auth_query_all("
    SELECT u.Id, u.Username, u.DisplayName, u.Email, u.IsActive, u.MustChangePassword, u.LastLoginAt,
        STUFF((SELECT ', ' + r.Name FROM dbo.WebReportUserRoles ur INNER JOIN dbo.WebReportRoles r ON r.Id = ur.RoleId WHERE ur.UserId = u.Id ORDER BY r.Name FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), 1, 2, '') AS RoleNames
    FROM dbo.WebReportUsers u
    $where
    ORDER BY u.DisplayName
", $params);

admin_layout_start('User Management', 'users');
?>
<section class="admin-panel">
    <div class="admin-actions" style="justify-content:space-between;margin-bottom:14px">
        <form method="get" action="./users.php" class="admin-actions">
            <input style="height:40px;border:1px solid #cfd9e6;border-radius:8px;padding:0 10px" type="search" name="q" value="<?php echo admin_h($q); ?>" placeholder="Search users">
            <button class="admin-btn admin-secondary" type="submit">Search</button>
        </form>
        <a class="admin-btn admin-primary" href="./user-edit.php">Create User</a>
    </div>
    <table class="admin-table">
        <thead><tr><th>User</th><th>Email</th><th>Roles</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach (($users === false ? array() : $users) as $user) { ?>
            <tr>
                <td><strong><?php echo admin_h($user['DisplayName']); ?></strong><br><span class="admin-muted"><?php echo admin_h($user['Username']); ?></span></td>
                <td><?php echo admin_h($user['Email']); ?></td>
                <td><?php echo admin_h($user['RoleNames']); ?></td>
                <td><span class="admin-badge <?php echo !empty($user['IsActive']) ? '' : 'off'; ?>"><?php echo !empty($user['IsActive']) ? 'Active' : 'Inactive'; ?></span><?php echo !empty($user['MustChangePassword']) ? ' <span class="admin-badge">Must change password</span>' : ''; ?></td>
                <td><?php echo admin_h($user['LastLoginAt'] instanceof DateTime ? $user['LastLoginAt']->format('Y-m-d H:i') : $user['LastLoginAt']); ?></td>
                <td><a class="admin-btn admin-secondary" href="./user-edit.php?id=<?php echo (int)$user['Id']; ?>">Edit</a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</section>
<?php admin_layout_end(); ?>
