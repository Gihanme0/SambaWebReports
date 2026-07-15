# SambaWebReports Development Standards

## Quality Goals

SambaWebReports is a local PHP reporting application for SambaPOS data. Every change should preserve report accuracy, keep the Kynix report UI consistent, and leave the repository easier to maintain than it was before.

Commercial-quality work in this project means:

- reports reconcile with verified SambaPOS tables and relationships;
- database queries are parameterized and explainable;
- UI changes reuse the existing Kynix design system in `css/vanzari.css`;
- exports are professional enough to send to an operator, accountant, or owner;
- mobile and print states are tested, not assumed;
- credentials, database backups, generated files, and local output files are never committed.

## Core Workflow

Use this workflow for every meaningful change:

1. Inspect
2. Understand
3. Plan
4. Modify
5. Validate
6. Review
7. Commit
8. Push

Do not skip directly from a request to editing. First inspect the current files and the data model that the change depends on.

## Repository Rules

- Work on `feature/*`, `hotfix/*`, or another approved branch. Never develop directly on `main`.
- Pull the latest branch before editing.
- Keep commits small and logical.
- Commit only after validation passes.
- Push after each stable milestone.
- Do not create duplicate PHP implementations, backup PHP files, or output-folder copies.
- Do not add frameworks or dependencies without approval.
- Preserve Daily Sales, Periodic Sales, Inventory Analytics, Purchase History, Consumption, and Stock behavior unless the task explicitly targets that report.

## Data Accuracy Rules

- Do not assume SambaPOS table relationships. Verify joins through schema, foreign keys, sample rows, or application behavior.
- Do not hide broken joins by defaulting missing data to zero.
- Do not invent columns or table meanings.
- Use half-open date ranges: `>= start` and `< end`.
- Balance logic must respect the correct grain, such as `InventoryItemId + WarehouseId`.
- Quantity signs and unit conversions must be verified before formulas are finalized.
- If a data source is unavailable or uncertain, document the limitation visibly in docs or a small report note.

## UI And Export Rules

- Future report UI must reuse Kynix classes and tokens from `css/vanzari.css`.
- Page-specific CSS must be scoped to the page body class.
- Reports must work at desktop, tablet, and mobile widths.
- Excel, PDF, and print output must not include connection banners, raw debug output, or hidden UI controls.
- Print layouts must avoid broken tables and must preserve readable numeric columns.

## Security And Error Handling

- `config.php` is local-only and must not be committed.
- Do not expose raw SQL errors to end users in production-facing views.
- Keep `config.example.php` free of real credentials.
- Escape all dynamic HTML output.
- Prefer concise user-safe error messages with enough diagnostic context for developers.
- Protect report entry pages with `auth_require_permission()` before loading report data.
- Use CSRF tokens for all state-changing admin forms.
- Store WebReports passwords only with `password_hash()` and verify with `password_verify()`.
- Add new report permissions to `database/webreports-auth-install.sql` and `auth/permissions.php` together.

## Documentation Expectations

- Update `docs/` when a change establishes a new rule, verified database fact, or reusable report pattern.
- Keep documentation repository-specific.
- Avoid duplicating the same rule in every file; link to the more detailed document when possible.

## Release Expectations

- A releasable milestone must include validated PHP syntax, local HTTP checks, export checks where relevant, and an updated changelog entry.
- Known limitations must be explicit.
- Merges to `main` should happen through review, not direct local work.
