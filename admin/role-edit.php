<?php
require_once __DIR__ . '/_admin.php';
auth_require_permission('roles.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$role = $isEdit ? auth_query_one("SELECT Id, Name, Description, IsSystemRole FROM dbo.WebReportRoles WHERE Id = ?", array($id)) : null;
if ($isEdit && !is_array($role)) {
    auth_redirect('./roles.php');
}
$permissions = admin_all_permissions();
$selectedPermissions = $isEdit ? admin_role_permission_ids($id) : array();
$errors = array();
$saved = false;
$isSuperAdminRole = $isEdit && $role['Name'] === 'Super Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
    $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
    $selectedPermissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : array();
    if ($name === '') { $errors[] = 'Role name is required.'; }

    if ($isSuperAdminRole) {
        $selectedPermissions = array();
        foreach ($permissions as $permission) {
            $selectedPermissions[] = (int)$permission['Id'];
        }
    }

    if (empty($errors)) {
        if ($isEdit) {
            $ok = auth_execute("UPDATE dbo.WebReportRoles SET Name = ?, Description = ?, UpdatedAt = GETDATE() WHERE Id = ?", array($name, $description, $id));
        } else {
            $ok = auth_execute("INSERT INTO dbo.WebReportRoles (Name, Description, IsSystemRole) VALUES (?, ?, 0)", array($name, $description));
            $newRole = $ok ? auth_query_one("SELECT Id FROM dbo.WebReportRoles WHERE Name = ?", array($name)) : null;
            if (is_array($newRole)) { $id = (int)$newRole['Id']; $isEdit = true; }
        }
        if (!empty($ok) && $id > 0) {
            auth_execute("DELETE FROM dbo.WebReportRolePermissions WHERE RoleId = ?", array($id));
            foreach ($selectedPermissions as $permissionId) {
                auth_execute("INSERT INTO dbo.WebReportRolePermissions (RoleId, PermissionId) VALUES (?, ?)", array($id, (int)$permissionId));
            }
            auth_audit(auth_current_user_id(), 'role_update', 'Role', $name, 'Role and permissions updated.');
            auth_redirect('./role-edit.php?id=' . (int)$id . '&saved=1');
        } else {
            $errors[] = 'Role could not be saved. Check for duplicate role name.';
        }
    }
    $role = array('Name' => $name, 'Description' => $description, 'IsSystemRole' => $isSuperAdminRole ? 1 : 0);
}
if (isset($_GET['saved'])) { $saved = true; }
$grouped = admin_group_permissions($permissions);

admin_layout_start($isEdit ? 'Edit Role' : 'Create Role', 'roles');
?>
<section class="admin-panel">
    <?php if ($saved) { ?><div class="admin-alert ok">Role saved.</div><?php } ?>
    <?php if (!empty($errors)) { ?><div class="admin-alert error"><?php foreach ($errors as $error) { echo '<div>' . admin_h($error) . '</div>'; } ?></div><?php } ?>
    <form method="post" action="./role-edit.php<?php echo $isEdit ? '?id=' . (int)$id : ''; ?>">
        <?php echo csrf_field(); ?>
        <div class="admin-form-grid">
            <div class="admin-field"><label>Role name</label><input name="name" value="<?php echo admin_h($isEdit || $_SERVER['REQUEST_METHOD'] === 'POST' ? $role['Name'] : ''); ?>" <?php echo $isSuperAdminRole ? 'readonly' : ''; ?> required></div>
            <div class="admin-field"><label>Description</label><input name="description" value="<?php echo admin_h($isEdit || $_SERVER['REQUEST_METHOD'] === 'POST' ? $role['Description'] : ''); ?>"></div>
        </div>
        <?php if ($isSuperAdminRole) { ?><div class="admin-alert ok">Super Admin always keeps every permission.</div><?php } ?>
        <?php foreach ($grouped as $category => $items) { ?>
            <h2><?php echo admin_h($category); ?></h2>
            <div class="admin-check-grid" style="margin-bottom:16px">
                <?php foreach ($items as $permission) { ?>
                    <label class="admin-check"><input type="checkbox" name="permissions[]" value="<?php echo (int)$permission['Id']; ?>" <?php echo in_array((int)$permission['Id'], $selectedPermissions, true) ? 'checked' : ''; ?> <?php echo $isSuperAdminRole ? 'disabled' : ''; ?>> <span><strong><?php echo admin_h($permission['Name']); ?></strong><br><small><?php echo admin_h($permission['PermissionKey']); ?></small></span></label>
                <?php } ?>
            </div>
        <?php } ?>
        <div class="admin-actions">
            <button class="admin-btn admin-primary" type="submit">Save Role</button>
            <a class="admin-btn admin-secondary" href="./roles.php">Back</a>
        </div>
    </form>
</section>
<?php admin_layout_end(); ?>
