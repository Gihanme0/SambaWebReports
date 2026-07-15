/*
    SambaWebReports authentication and RBAC installation script.

    Safe to run more than once:
    - Creates project-specific WebReport* tables only when missing.
    - Seeds permissions and default roles idempotently.
    - Does not create a default administrator password.

    After running this script, open setup-admin.php to create the first Super Admin.
*/

SET NOCOUNT ON;

IF OBJECT_ID('dbo.WebReportUsers', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WebReportUsers (
        Id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_WebReportUsers PRIMARY KEY,
        Username NVARCHAR(80) NOT NULL,
        DisplayName NVARCHAR(160) NOT NULL,
        Email NVARCHAR(254) NULL,
        PasswordHash NVARCHAR(255) NOT NULL,
        IsActive BIT NOT NULL CONSTRAINT DF_WebReportUsers_IsActive DEFAULT (1),
        MustChangePassword BIT NOT NULL CONSTRAINT DF_WebReportUsers_MustChangePassword DEFAULT (0),
        LastLoginAt DATETIME NULL,
        CreatedAt DATETIME NOT NULL CONSTRAINT DF_WebReportUsers_CreatedAt DEFAULT (GETDATE()),
        UpdatedAt DATETIME NOT NULL CONSTRAINT DF_WebReportUsers_UpdatedAt DEFAULT (GETDATE())
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WebReportUsers_Username' AND object_id = OBJECT_ID('dbo.WebReportUsers'))
BEGIN
    CREATE UNIQUE INDEX UX_WebReportUsers_Username ON dbo.WebReportUsers(Username);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WebReportUsers_Email' AND object_id = OBJECT_ID('dbo.WebReportUsers'))
BEGIN
    CREATE UNIQUE INDEX UX_WebReportUsers_Email ON dbo.WebReportUsers(Email) WHERE Email IS NOT NULL;
END;

IF OBJECT_ID('dbo.WebReportRoles', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WebReportRoles (
        Id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_WebReportRoles PRIMARY KEY,
        Name NVARCHAR(80) NOT NULL,
        Description NVARCHAR(255) NULL,
        IsSystemRole BIT NOT NULL CONSTRAINT DF_WebReportRoles_IsSystemRole DEFAULT (0),
        CreatedAt DATETIME NOT NULL CONSTRAINT DF_WebReportRoles_CreatedAt DEFAULT (GETDATE()),
        UpdatedAt DATETIME NOT NULL CONSTRAINT DF_WebReportRoles_UpdatedAt DEFAULT (GETDATE())
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WebReportRoles_Name' AND object_id = OBJECT_ID('dbo.WebReportRoles'))
BEGIN
    CREATE UNIQUE INDEX UX_WebReportRoles_Name ON dbo.WebReportRoles(Name);
END;

IF OBJECT_ID('dbo.WebReportPermissions', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WebReportPermissions (
        Id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_WebReportPermissions PRIMARY KEY,
        PermissionKey NVARCHAR(120) NOT NULL,
        Name NVARCHAR(120) NOT NULL,
        Description NVARCHAR(255) NULL,
        Category NVARCHAR(80) NOT NULL
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_WebReportPermissions_Key' AND object_id = OBJECT_ID('dbo.WebReportPermissions'))
BEGIN
    CREATE UNIQUE INDEX UX_WebReportPermissions_Key ON dbo.WebReportPermissions(PermissionKey);
END;

IF OBJECT_ID('dbo.WebReportUserRoles', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WebReportUserRoles (
        UserId INT NOT NULL,
        RoleId INT NOT NULL,
        CONSTRAINT PK_WebReportUserRoles PRIMARY KEY (UserId, RoleId),
        CONSTRAINT FK_WebReportUserRoles_User FOREIGN KEY (UserId) REFERENCES dbo.WebReportUsers(Id) ON DELETE CASCADE,
        CONSTRAINT FK_WebReportUserRoles_Role FOREIGN KEY (RoleId) REFERENCES dbo.WebReportRoles(Id) ON DELETE CASCADE
    );
END;

IF OBJECT_ID('dbo.WebReportRolePermissions', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WebReportRolePermissions (
        RoleId INT NOT NULL,
        PermissionId INT NOT NULL,
        CONSTRAINT PK_WebReportRolePermissions PRIMARY KEY (RoleId, PermissionId),
        CONSTRAINT FK_WebReportRolePermissions_Role FOREIGN KEY (RoleId) REFERENCES dbo.WebReportRoles(Id) ON DELETE CASCADE,
        CONSTRAINT FK_WebReportRolePermissions_Permission FOREIGN KEY (PermissionId) REFERENCES dbo.WebReportPermissions(Id) ON DELETE CASCADE
    );
END;

IF OBJECT_ID('dbo.WebReportAuditLogs', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WebReportAuditLogs (
        Id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_WebReportAuditLogs PRIMARY KEY,
        UserId INT NULL,
        Action NVARCHAR(120) NOT NULL,
        EntityType NVARCHAR(80) NULL,
        EntityId NVARCHAR(80) NULL,
        Details NVARCHAR(1000) NULL,
        IpAddress NVARCHAR(64) NULL,
        CreatedAt DATETIME NOT NULL CONSTRAINT DF_WebReportAuditLogs_CreatedAt DEFAULT (GETDATE()),
        CONSTRAINT FK_WebReportAuditLogs_User FOREIGN KEY (UserId) REFERENCES dbo.WebReportUsers(Id) ON DELETE SET NULL
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WebReportAuditLogs_CreatedAt' AND object_id = OBJECT_ID('dbo.WebReportAuditLogs'))
BEGIN
    CREATE INDEX IX_WebReportAuditLogs_CreatedAt ON dbo.WebReportAuditLogs(CreatedAt DESC);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WebReportAuditLogs_Action' AND object_id = OBJECT_ID('dbo.WebReportAuditLogs'))
BEGIN
    CREATE INDEX IX_WebReportAuditLogs_Action ON dbo.WebReportAuditLogs(Action);
END;

DECLARE @Permissions TABLE (
    PermissionKey NVARCHAR(120) NOT NULL,
    Name NVARCHAR(120) NOT NULL,
    Description NVARCHAR(255) NULL,
    Category NVARCHAR(80) NOT NULL
);

INSERT INTO @Permissions (PermissionKey, Name, Description, Category)
VALUES
('dashboard.view', 'View Dashboard', 'Open the Kynix Report Center dashboard.', 'Dashboard'),
('daily_sales.view', 'View Daily Sales', 'Open the Daily Sales report.', 'Sales'),
('periodic_sales.view', 'View Periodic Sales', 'Open the Periodic Sales report.', 'Sales'),
('inventory_analytics.view', 'View Inventory Analytics', 'Open the Inventory Analytics report.', 'Inventory'),
('purchase_history.view', 'View Purchase History', 'Open Purchase History / Goods Receipt Notes.', 'Purchasing'),
('consumption.view', 'View Consumption', 'Open Consumption Vouchers.', 'Inventory'),
('stock.view', 'View Stock', 'Open Current Stock.', 'Inventory'),
('users.manage', 'Manage Users', 'Create, edit, deactivate, and assign user roles.', 'Administration'),
('roles.manage', 'Manage Roles', 'Create and edit WebReports roles.', 'Administration'),
('permissions.manage', 'Manage Permissions', 'Assign permissions to roles.', 'Administration'),
('audit.view', 'View Audit Log', 'View authentication and administration audit logs.', 'Administration'),
('settings.manage', 'Manage Settings', 'Reserved for future WebReports settings.', 'Administration'),
('exports.excel', 'Export Excel', 'Use Excel export actions.', 'Exports'),
('exports.pdf', 'Export PDF', 'Use PDF summary actions.', 'Exports'),
('exports.print', 'Print Reports', 'Use print actions.', 'Exports');

MERGE dbo.WebReportPermissions AS target
USING @Permissions AS source
ON target.PermissionKey = source.PermissionKey
WHEN MATCHED THEN
    UPDATE SET Name = source.Name, Description = source.Description, Category = source.Category
WHEN NOT MATCHED THEN
    INSERT (PermissionKey, Name, Description, Category)
    VALUES (source.PermissionKey, source.Name, source.Description, source.Category);

DECLARE @Roles TABLE (
    Name NVARCHAR(80) NOT NULL,
    Description NVARCHAR(255) NULL,
    IsSystemRole BIT NOT NULL
);

INSERT INTO @Roles (Name, Description, IsSystemRole)
VALUES
('Super Admin', 'Full system access for WebReports administration.', 1),
('Admin', 'Administrative access without reserved settings control.', 1),
('Manager', 'Business overview, sales, purchasing, inventory, and exports.', 1),
('Accountant', 'Sales, purchasing, stock, and Excel export access.', 1),
('Inventory User', 'Inventory, stock, consumption, and purchasing access.', 1),
('Viewer', 'Read-only dashboard and report access with no exports.', 1);

MERGE dbo.WebReportRoles AS target
USING @Roles AS source
ON target.Name = source.Name
WHEN MATCHED THEN
    UPDATE SET Description = source.Description, IsSystemRole = source.IsSystemRole, UpdatedAt = GETDATE()
WHEN NOT MATCHED THEN
    INSERT (Name, Description, IsSystemRole)
    VALUES (source.Name, source.Description, source.IsSystemRole);

DELETE rp
FROM dbo.WebReportRolePermissions rp
INNER JOIN dbo.WebReportRoles r ON r.Id = rp.RoleId
WHERE r.Name IN ('Super Admin', 'Admin', 'Manager', 'Accountant', 'Inventory User', 'Viewer');

INSERT INTO dbo.WebReportRolePermissions (RoleId, PermissionId)
SELECT r.Id, p.Id
FROM dbo.WebReportRoles r
CROSS JOIN dbo.WebReportPermissions p
WHERE r.Name = 'Super Admin';

INSERT INTO dbo.WebReportRolePermissions (RoleId, PermissionId)
SELECT r.Id, p.Id
FROM dbo.WebReportRoles r
INNER JOIN dbo.WebReportPermissions p ON p.PermissionKey IN (
    'dashboard.view', 'daily_sales.view', 'periodic_sales.view', 'inventory_analytics.view',
    'purchase_history.view', 'consumption.view', 'stock.view', 'users.manage', 'roles.manage',
    'permissions.manage', 'audit.view', 'exports.excel', 'exports.pdf', 'exports.print'
)
WHERE r.Name = 'Admin';

INSERT INTO dbo.WebReportRolePermissions (RoleId, PermissionId)
SELECT r.Id, p.Id
FROM dbo.WebReportRoles r
INNER JOIN dbo.WebReportPermissions p ON p.PermissionKey IN (
    'dashboard.view', 'daily_sales.view', 'periodic_sales.view', 'inventory_analytics.view',
    'purchase_history.view', 'consumption.view', 'stock.view', 'exports.excel', 'exports.pdf', 'exports.print'
)
WHERE r.Name = 'Manager';

INSERT INTO dbo.WebReportRolePermissions (RoleId, PermissionId)
SELECT r.Id, p.Id
FROM dbo.WebReportRoles r
INNER JOIN dbo.WebReportPermissions p ON p.PermissionKey IN (
    'dashboard.view', 'daily_sales.view', 'periodic_sales.view', 'purchase_history.view',
    'stock.view', 'exports.excel', 'exports.print'
)
WHERE r.Name = 'Accountant';

INSERT INTO dbo.WebReportRolePermissions (RoleId, PermissionId)
SELECT r.Id, p.Id
FROM dbo.WebReportRoles r
INNER JOIN dbo.WebReportPermissions p ON p.PermissionKey IN (
    'dashboard.view', 'inventory_analytics.view', 'purchase_history.view', 'consumption.view',
    'stock.view', 'exports.excel', 'exports.print'
)
WHERE r.Name = 'Inventory User';

INSERT INTO dbo.WebReportRolePermissions (RoleId, PermissionId)
SELECT r.Id, p.Id
FROM dbo.WebReportRoles r
INNER JOIN dbo.WebReportPermissions p ON p.PermissionKey IN (
    'dashboard.view', 'daily_sales.view', 'periodic_sales.view', 'inventory_analytics.view',
    'purchase_history.view', 'consumption.view', 'stock.view'
)
WHERE r.Name = 'Viewer';

PRINT 'SambaWebReports authentication tables and RBAC seed data are ready.';
