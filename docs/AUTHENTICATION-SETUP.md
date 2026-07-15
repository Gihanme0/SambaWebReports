# WebReports Authentication Setup

This project uses database-driven users, roles, permissions, and audit logs. It does not include a hardcoded administrator password.

## Install

1. Confirm `config.php` points to the SambaPOS SQL Server database.
2. Run `database/webreports-auth-install.sql` against the same database.
3. Open:

   ```text
   http://localhost/SambaWebReports/setup-admin.php
   ```

4. Create the first Super Admin with a strong password.
5. Sign in at:

   ```text
   http://localhost/SambaWebReports/login.php
   ```

The setup page disables itself after an active Super Admin exists.

## Permission Mapping

- `index.php` requires `dashboard.view`.
- `vanzari.php` requires `daily_sales.view`.
- `vanzariPerioada.php` requires `periodic_sales.view`.
- `inventoryDaily.php` requires `inventory_analytics.view`.
- `nir.php` requires `purchase_history.view`.
- `consum.php` requires `consumption.view`.
- `stoc.php` requires `stock.view`.
- Excel exports require `exports.excel`.
- PDF actions require `exports.pdf` where the action is shown.
- Print actions require `exports.print` where the action is shown.
- `db_explorer.php` requires `settings.manage`.

## Default Roles

- Super Admin: all permissions.
- Admin: report, export, user, role, permission, and audit access.
- Manager: dashboard, sales, inventory, purchasing, and export access.
- Accountant: dashboard, sales, purchasing, stock, Excel, and print access.
- Inventory User: dashboard, inventory, consumption, stock, purchasing, Excel, and print access.
- Viewer: read-only dashboard and report access.

## Add A User

1. Sign in as a user with `users.manage`.
2. Open `Admin > Users`.
3. Create the user with username, display name, optional email, password, active status, and roles.
4. Enable `Force password change` when issuing a temporary password.

## Assign Role Permissions

1. Sign in as a user with `roles.manage`.
2. Open `Admin > Roles`.
3. Edit a role and choose grouped permissions.
4. Save the role.

Super Admin always keeps every permission. The admin panel also prevents removing the last active Super Admin account.

## Password Policy

Passwords must include:

- at least 10 characters;
- uppercase;
- lowercase;
- number;
- special character.

Passwords are stored with PHP `password_hash()` and verified with `password_verify()`.

## Security Controls

- PHP sessions use HttpOnly cookies, SameSite=Lax, and Secure when HTTPS is detected.
- Session ID regenerates after successful login.
- Inactivity timeout is 30 minutes.
- Admin state-changing forms use CSRF tokens and constant-time validation.
- Direct report URLs enforce permissions server-side.
- Navigation hides links the signed-in user cannot open.
- Audit logs record login, logout, user, role, permission, and password reset events.

## Troubleshooting

- If login says authentication is not installed, run `database/webreports-auth-install.sql`.
- If setup is disabled, an active Super Admin already exists.
- If an administrator is locked out, restore access directly in SQL Server by assigning an active user to the Super Admin role.
- If report pages redirect to login, confirm the user is active and has the matching permission.

## Rollback Considerations

Do not drop the WebReport tables if audit history is needed. To disable the feature during development, revert the related commits on the feature branch instead of editing tables manually.

Project-specific tables:

- `WebReportUsers`
- `WebReportRoles`
- `WebReportPermissions`
- `WebReportUserRoles`
- `WebReportRolePermissions`
- `WebReportAuditLogs`
