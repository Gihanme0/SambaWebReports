<?php
require_once __DIR__ . '/_admin.php';
auth_require_permission('audit.view');

$userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$action = trim(isset($_GET['action']) ? $_GET['action'] : '');
$from = trim(isset($_GET['from']) ? $_GET['from'] : '');
$to = trim(isset($_GET['to']) ? $_GET['to'] : '');
$where = array();
$params = array();
if ($userId) { $where[] = 'a.UserId = ?'; $params[] = $userId; }
if ($action !== '') { $where[] = 'a.Action LIKE ?'; $params[] = '%' . $action . '%'; }
if ($from !== '') { $where[] = 'a.CreatedAt >= ?'; $params[] = $from . 'T00:00:00'; }
if ($to !== '') { $where[] = 'a.CreatedAt < ?'; $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . 'T00:00:00'; }
$whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

$logs = auth_query_all("
    SELECT TOP 200 a.Action, a.EntityType, a.EntityId, a.Details, a.IpAddress, a.CreatedAt, u.DisplayName
    FROM dbo.WebReportAuditLogs a
    LEFT JOIN dbo.WebReportUsers u ON u.Id = a.UserId
    $whereSql
    ORDER BY a.CreatedAt DESC
", $params);
$users = auth_query_all("SELECT Id, DisplayName FROM dbo.WebReportUsers ORDER BY DisplayName");

admin_layout_start('Audit Log', 'audit');
?>
<section class="admin-panel">
    <form class="admin-form-grid" method="get" action="./audit.php">
        <div class="admin-field"><label>User</label><select name="user_id"><option value="">All users</option><?php foreach (($users === false ? array() : $users) as $user) { ?><option value="<?php echo (int)$user['Id']; ?>" <?php echo $userId === (int)$user['Id'] ? 'selected' : ''; ?>><?php echo admin_h($user['DisplayName']); ?></option><?php } ?></select></div>
        <div class="admin-field"><label>Action</label><input name="action" value="<?php echo admin_h($action); ?>"></div>
        <div class="admin-field"><label>From</label><input type="date" name="from" value="<?php echo admin_h($from); ?>"></div>
        <div class="admin-field"><label>To</label><input type="date" name="to" value="<?php echo admin_h($to); ?>"></div>
        <div class="admin-actions"><button class="admin-btn admin-primary" type="submit">Filter</button><a class="admin-btn admin-secondary" href="./audit.php">Reset</a></div>
    </form>
</section>
<section class="admin-panel">
    <h2>Recent Events</h2>
    <table class="admin-table">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach (($logs === false ? array() : $logs) as $log) { ?>
            <tr>
                <td><?php echo admin_h($log['CreatedAt'] instanceof DateTime ? $log['CreatedAt']->format('Y-m-d H:i:s') : $log['CreatedAt']); ?></td>
                <td><?php echo admin_h($log['DisplayName']); ?></td>
                <td><?php echo admin_h($log['Action']); ?></td>
                <td><?php echo admin_h(trim($log['EntityType'] . ' ' . $log['EntityId'])); ?></td>
                <td><?php echo admin_h($log['IpAddress']); ?></td>
                <td><?php echo admin_h($log['Details']); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</section>
<?php admin_layout_end(); ?>
