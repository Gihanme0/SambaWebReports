# Changelog

This project follows a Keep-a-Changelog-style structure.

## Unreleased

### Added

- Development standards documentation for SambaWebReports.
- Kynix report design system documentation.
- Project session map for continuing work across reports.
- Kynix Report Center Dashboard as the main `index.php` landing page.
- Database-driven WebReports authentication, roles, permissions, audit logs, setup flow, and admin panel.

### Changed

- README expanded with setup, workflow, and testing guidance.
- Home navigation now opens the dashboard explicitly.
- Report navigation is now role-aware and report entry pages require authenticated permissions.

### Fixed

- Tracked example configuration no longer demonstrates printing raw SQL Server connection errors to the browser.

### Performance

- None yet in this documentation milestone.

### Documentation

- Added repository-specific coding, Git, database, testing, and release documentation.
- Added authentication setup documentation and RBAC testing guidance.

### Known Issues

- Legacy Purchase History, Consumption, and Stock pages are not fully modernized to the Kynix report layout.
- Login rate limiting and account lockout are not implemented yet.

## Inventory Analytics Milestones

### `10e4f4f` - Inventory Data Accuracy

- Corrected inventory analytics data logic for warehouse-keyed balances, duplicate-safe recipe usage, current balance, and valuation.

### `07159de` - Dashboard Redesign

- Redesigned Inventory Analytics around a commercial ERP analytics dashboard hierarchy.

### `1770b9c` - Design System Refinement

- Refined Inventory Analytics design system integration.

### `c0cf00a` - Initial Modern UI

- Modernized Inventory Analytics UI.

### `238c06a` - Initial Module

- Added the initial Inventory Analytics module.
