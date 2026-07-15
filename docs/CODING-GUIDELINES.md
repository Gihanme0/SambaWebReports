# SambaWebReports Coding Guidelines

## PHP

- Use clear helper names such as `kx_h`, `kx_money`, `inv_fetch_all`, and `inv_money`.
- Add PHPDoc for important helpers that encode business rules, database access, or output formatting.
- Use parameterized SQL through `sqlsrv_query($conn, $sql, $params)`.
- Escape dynamic output with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Keep business logic out of dense markup where practical.
- Avoid duplicating helper functions across new reports; consider a shared helper only when it reduces real duplication.
- Do not run SQL inside row-rendering loops.
- Do not show sensitive SQL details or server paths to ordinary users.
- If export headers are sent, buffer or suppress connection banners before output.

## SQL

- Comment major queries when the business logic is not obvious.
- Use half-open datetime filters: `Date >= ? AND Date < ?`.
- Verify table relationships before using them.
- Avoid `SELECT *` in report queries.
- Prefer IDs and verified document/type relationships over string matching.
- Avoid unnecessary full scans and scalar subqueries per row.
- Validate quantity signs, multipliers, unit conversions, warehouse direction, and cost semantics.
- Never invent columns.
- Never silently convert broken joins to zero; only default values after the relationship is verified and the fallback is intentional.

## CSS

- Reuse Kynix classes from `css/vanzari.css`: `kx-page`, `kx-shell`, `kx-hero`, `kx-panel`, `kx-filter-panel`, `kx-btn`, `kx-stats`, `kx-stat-card`, `kx-table`, and related table/search/chart classes.
- Scope page-specific CSS under a body/page class such as `.kx-inventory-page`.
- Avoid random class names and one-off visual systems.
- Keep spacing, color, radius, shadows, and typography consistent with Kynix tokens.
- Maintain Bootstrap 3 compatibility because the project still ships Bootstrap 3 assets.
- Support dark mode and print mode when a page participates in the Kynix UI system.
- Mobile behavior must be designed intentionally, not left to table overflow.

## JavaScript

- Use descriptive function names such as `invApplyTableState` and `kxExportInventoryPdf`.
- Avoid unnecessary globals; namespace page-specific functions by report prefix when possible.
- Do not add external CDN dependencies without approval.
- Provide graceful empty and error states.
- Preserve keyboard/focus behavior for buttons, detail toggles, and filters.
- Avoid long-running loops over large tables when server-side filtering would be more appropriate.
- Reuse shared search, sort, pagination, and export patterns when practical.

## Comments

- Explain why a decision exists, not obvious syntax.
- Add TODO comments only with context, expected owner/action, or target version.
- Mark deprecated logic clearly when legacy pages still depend on it.
- Add `FIXED` comments only when historically useful.
- Do not add decorative headers or comments on every line.
