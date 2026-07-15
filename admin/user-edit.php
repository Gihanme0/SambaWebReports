<?php
require_once __DIR__ . '/_admin.php';
auth_require_permission('users.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errors = array();
$saved = false;
$roles = admin_all_roles();
$user = $isEdit ? auth_query_one("SELECT Id, Username, DisplayName, Email, IsActive, MustChangePassword FROM dbo.WebReportUsers WHERE Id = ?", array($id)) : null;
if ($isEdit && !is_array($user)) {
    auth_redirect('./users.php');
}
$selectedRoles = $isEdit ? admin_user_role_ids($id) : array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $displayName = trim(isset($_POST['display_name']) ? $_POST['display_name'] : '');
    $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
    $isActive = isset($_POST['is_active']);
    $mustChange = isset($_POST['must_change_password']);
    $passwordValue = isset($_POST['password']) ? $_POST['password'] : '';
    $selectedRoles = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('intval', $_POST['roles']) : array();

    if ($username === '') { $errors[] = 'Username is required.'; }
    if ($displayName === '') { $errors[] = 'Display name is required.'; }
    if (!$isEdit && $passwordValue === '') { $errors[] = 'Password is required for new users.'; }
    if ($passwordValue !== '') { $errors = array_merge($errors, auth_password_policy_errors($passwordValue)); }
    if ($isEdit && $id === auth_current_user_id() && !$isActive) { $errors[] = 'You cannot deactivate your own account.'; }
    if ($isEdit) {
        $superRole = auth_query_one("SELECT Id FROM dbo.WebReportRoles WHERE Name = 'Super Admin'");
        if (is_array($superRole)) {
            $superRoleId = (int)$superRole['Id'];
            $hadSuperAdmin = in_array($superRoleId, admin_user_role_ids($id), true);
            $willRemainActiveSuperAdmin = $isActive && in_array($superRoleId, $selectedRoles, true);
            if ($hadSuperAdmin && !$willRemainActiveSuperAdmin) {
                $otherSuperAdmins = auth_query_one("
                    SELECT COUNT(*) AS Cnt
                    FROM dbo.WebReportUsers u
                    INNER JOIN dbo.WebReportUserRoles ur ON ur.UserId = u.Id
                    WHERE u.IsActive = 1 AND ur.RoleId = ? AND u.Id <> ?
                ", array($superRoleId, $id));
                if (!is_array($otherSuperAdmins) || (int)$otherSuperAdmins['Cnt'] < 1) {
                    $errors[] = 'At least one active Super Admin must remain.';
                }
            }
        }
    }

    if (empty($errors)) {
        if ($isEdit) {
            $ok = auth_execute("UPDATE dbo.WebReportUsers SET Username = ?, DisplayName = ?, Email = ?, IsActive = ?, MustChangePassword = ?, UpdatedAt = GETDATE() WHERE Id = ?", array($username, $displayName, $email !== '' ? $email : null, $isActive ? 1 : 0, $mustChange ? 1 : 0, $id));
            if ($ok && $passwordValue !== '') {
                $ok = auth_execute("UPDATE dbo.WebReportUsers SET PasswordHash = ?, MustChangePassword = 1, UpdatedAt = GETDATE() WHERE Id = ?", array(password_hash($passwordValue, PASSWORD_DEFAULT), $id));
                auth_audit(auth_current_user_id(), 'password_reset', 'User', $username, 'Admin reset user password.');
            }
            if ($ok) {
                auth_set_user_roles($id, $selectedRoles);
                auth_audit(auth_current_user_id(), 'user_update', 'User', $username, 'User updated.');
                $saved = true;
            } else {
                $errors[] = 'User could not be updated. Check for duplicate username or email.';
            }
        } else {
            $newId = auth_create_user($username, $displayName, $email, $passwordValue, $mustChange, $isActive, $selectedRoles);
            if ($newId === false) {
                $errors[] = 'User could not be created. Check for duplicate username or email.';
            } else {
                auth_redirect('./user-edit.php?id=' . (int)$newId . '&saved=1');
            }
        }
    }
    $user = array('Username' => $username, 'DisplayName' => $displayName, 'Email' => $email, 'IsActive' => $isActive ? 1 : 0, 'MustChangePassword' => $mustChange ? 1 : 0);
}
if (isset($_GET['saved'])) { $saved = true; }

admin_layout_start($isEdit ? 'Edit User' : 'Create User', 'users');
?>
<section class="admin-panel">
    <?php if ($saved) { ?><div class="admin-alert ok">User saved.</div><?php } ?>
    <?php if (!empty($errors)) { ?><div class="admin-alert error"><?php foreach ($errors as $error) { echo '<div>' . admin_h($error) . '</div>'; } ?></div><?php } ?>
    <form method="post" action="./user-edit.php<?php echo $isEdit ? '?id=' . (int)$id : ''; ?>">
        <?php echo csrf_field(); ?>
        <div class="admin-form-grid">
            <div class="admin-field"><label>Username</label><input name="username" value="<?php echo admin_h($isEdit || $_SERVER['REQUEST_METHOD'] === 'POST' ? $user['Username'] : ''); ?>" required></div>
            <div class="admin-field"><label>Display name</label><input name="display_name" value="<?php echo admin_h($isEdit || $_SERVER['REQUEST_METHOD'] === 'POST' ? $user['DisplayName'] : ''); ?>" required></div>
            <div class="admin-field"><label>Email</label><input type="email" name="email" value="<?php echo admin_h($isEdit || $_SERVER['REQUEST_METHOD'] === 'POST' ? $user['Email'] : ''); ?>"></div>
            <div class="admin-field"><label><?php echo $isEdit ? 'Set new password' : 'Password'; ?></label><input type="password" name="password" <?php echo $isEdit ? '' : 'required'; ?>></div>
        </div>
        <div class="admin-actions" style="margin-bottom:14px">
            <label class="admin-check"><input type="checkbox" name="is_active" <?php echo (!$isEdit || !empty($user['IsActive'])) ? 'checked' : ''; ?>> Active</label>
            <label class="admin-check"><input type="checkbox" name="must_change_password" <?php echo (!empty($user['MustChangePassword'])) ? 'checked' : ''; ?>> Force password change</label>
        </div>
        <h2>Roles</h2>
        <div class="admin-check-grid">
            <?php foreach ($roles as $role) { ?>
                <label class="admin-check"><input type="checkbox" name="roles[]" value="<?php echo (int)$role['Id']; ?>" <?php echo in_array((int)$role['Id'], $selectedRoles, true) ? 'checked' : ''; ?>> <span><strong><?php echo admin_h($role['Name']); ?></strong><br><small><?php echo admin_h($role['Description']); ?></small></span></label>
            <?php } ?>
        </div>
        <div class="admin-actions" style="margin-top:18px">
            <button class="admin-btn admin-primary" type="submit">Save User</button>
            <a class="admin-btn admin-secondary" href="./users.php">Back</a>
        </div>
    </form>
</section>
<?php admin_layout_end(); ?>
