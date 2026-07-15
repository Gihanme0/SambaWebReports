# SambaWebReports

SambaWebReports is a PHP reporting interface for SambaPOS running on a local WAMP/PHP/SQL Server environment. The default `index.php` page is the Kynix Report Center Dashboard, with quick access to legacy Bootstrap reports and newer Kynix dashboard reports for sales, inventory, purchases, consumption, and stock.

## Requirements

- SambaPOS 3, 4, or 5 database on Microsoft SQL Server.
- WampServer or equivalent PHP/Apache stack.
- Microsoft Drivers for PHP for SQL Server (`sqlsrv`).
- Microsoft ODBC Driver for SQL Server.
- Git for branch-based development.

## Local Setup

1. Clone the repository into the WAMP web root, for example:

   ```powershell
   C:\wamp64\www\SambaWebReports
   ```

2. Copy the example configuration:

   ```powershell
   copy config.example.php config.php
   ```

3. Edit `config.php` locally with your SQL Server host, database name, username, password, and business name.

4. Install WebReports authentication:

   ```text
   database/webreports-auth-install.sql
   ```

5. Create the first Super Admin:

   ```text
   http://localhost/SambaWebReports/setup-admin.php
   ```

6. Open the reports through Apache:

   ```text
   http://localhost/SambaWebReports/
   ```

`config.php` is intentionally ignored by Git. Do not commit real database credentials.

## Main Reports

- `vanzari.php` - Daily Sales
- `vanzariPerioada.php` - Periodic Sales
- `inventoryDaily.php` - Inventory Analytics
- `index.php` - Kynix Report Center Dashboard
- `nir.php` - Purchase History / Goods Receipt Notes
- `consum.php` - Consumption Vouchers
- `stoc.php` - Current Stock
- `completeSales.php` - Redirect to Periodic Sales

## Folder Structure

- `css/` - Kynix report styles, print styles, and datepicker styles.
- `js/` - Report JavaScript and Bootstrap datetimepicker assets.
- `bootstrap/` - Bundled Bootstrap 3 assets.
- `jquery/` - Bundled jQuery.
- `img/` - Logo and image assets.
- `reportSQL/` - Legacy included SQL report fragments.
- `auth/` - Shared authentication, CSRF, and permission helpers.
- `admin/` - User, role, permission, and audit management pages.
- `database/` - Installation scripts for WebReports-owned tables.
- `docs/` - Development standards, workflow, database notes, testing checklists, and session documentation.

## Git Workflow

- Work on `feature/*`, `hotfix/*`, or another approved branch.
- Never develop directly on `main`.
- Pull before editing.
- Validate before committing.
- Use small logical commits.
- Push stable milestones to the remote branch.
- Use pull requests before merging to `main`.

See [docs/GIT-WORKFLOW.md](docs/GIT-WORKFLOW.md).

## Testing Commands

Run PHP syntax checks on modified PHP files:

```powershell
php -l vanzari.php
php -l vanzariPerioada.php
php -l inventoryDaily.php
php -l header.php
```

Smoke-test the main local reports:

```text
http://localhost/SambaWebReports/vanzari.php
http://localhost/SambaWebReports/vanzariPerioada.php
http://localhost/SambaWebReports/inventoryDaily.php
```

Use [docs/TESTING-CHECKLIST.md](docs/TESTING-CHECKLIST.md) before committing report changes.

## Documentation

- [Development Standards](docs/DEVELOPMENT-STANDARDS.md)
- [Coding Guidelines](docs/CODING-GUIDELINES.md)
- [Git Workflow](docs/GIT-WORKFLOW.md)
- [Report Design System](docs/REPORT-DESIGN-SYSTEM.md)
- [Database Notes](docs/DATABASE-NOTES.md)
- [Testing Checklist](docs/TESTING-CHECKLIST.md)
- [Project Sessions](docs/PROJECT-SESSIONS.md)
- [Authentication Setup](docs/AUTHENTICATION-SETUP.md)
- [Changelog](docs/CHANGELOG.md)
- [Release Notes Template](docs/RELEASE-NOTES.md)

## Security Notes

- Do not commit `config.php`, `.env`, database backups, exported reports, logs, or backup PHP files.
- Do not expose raw SQL Server credentials or private business data in documentation.
- Avoid showing raw SQL errors in user-facing report pages.
- Keep `config.example.php` generic.
- Run `database/webreports-auth-install.sql` and create the first Super Admin before exposing reports to users.
- Manage report access with roles and permissions; do not hardcode users into report pages.

## Contribution Workflow

1. Inspect the relevant report, CSS, JavaScript, SQL, and documentation.
2. Verify SambaPOS schema/data relationships before changing report logic.
3. Plan the smallest safe change.
4. Modify only necessary files.
5. Run syntax checks and local HTTP smoke tests.
6. Review the diff.
7. Commit with a clear prefix such as `docs:`, `fix:`, `feat:`, or `audit:`.
8. Push to the active branch.

For project-specific rules, start with [docs/DEVELOPMENT-STANDARDS.md](docs/DEVELOPMENT-STANDARDS.md).
