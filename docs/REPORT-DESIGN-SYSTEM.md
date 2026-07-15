# Kynix Report Design System

The current report UI standard is based on `vanzari.php`, `vanzariPerioada.php`, `inventoryDaily.php`, `css/vanzari.css`, and `css/print.css`.

## Shared Layout

- Top navigation: `header.php` renders `.kx-topbar`, `.kx-brand`, `.kx-menu-btn`, and `.kx-nav`.
- Page shell: use `.kx-shell` for page width and outer spacing.
- Hero: use `.kx-hero`, `.kx-eyebrow`, and `.kx-hero-badge` for report identity and period metadata.
- Panels: use `.kx-panel` and specialized variants such as `.kx-filter-panel`, `.kx-table-panel`, and `.kx-chart-panel`.
- Footer: use `.kx-footer`.

## Filters

- Date filters use Bootstrap datetimepicker or native date controls depending on page generation.
- Shared filter panels should use `.kx-filter-grid`, `.kx-field`, `.kx-date-picker`, and `.kx-actions`.
- Inventory Analytics uses scoped `.inv-*` classes for advanced filters; future advanced filters should follow that scoped approach.

## KPI Cards

- Use `.kx-stats` and `.kx-stat-card` for standard reports.
- Use scoped cards only when a report has a specialized dashboard hierarchy.
- KPI labels must be short and business-facing.

## Buttons

Required button language:

- Primary action: blue, `.kx-btn-primary`
- Excel: green, `.kx-btn-success`
- PDF: red, `.kx-btn-danger`
- Print: dark, `.kx-btn-dark`
- Reset/secondary: neutral/dark secondary treatment

Buttons should use consistent labels: `Show Report`, `Update Dashboard`, `Reset`, `Excel`, `PDF`, and `Print`.

## Tables

- Use `.kx-table-panel`, `.kx-table-toolbar`, `.kx-table-wrap`, and `.kx-table`.
- Numeric columns use `.kx-num`; money columns use `.kx-money`.
- Empty rows use `.kx-empty-row`; full empty states use `.kx-empty-state`.
- Sorting and pagination must not shift layout or hide totals.

## Search, Sorting, And Pagination

- Search inputs use `.kx-search-wrap`.
- Sort labels should be clear but visually quiet.
- Pagination controls should be keyboard-accessible and should not appear in print.

## Mobile Cards

- Dense tables should provide mobile cards or a deliberate compact layout.
- Mobile pages must avoid forced horizontal scrolling except for legacy reports that have not yet been modernized.
- Touch targets should remain readable at 390px width.

## Empty, Error, And Loading States

- Use `.kx-alert` for warnings and `.kx-alert-error` for errors.
- Avoid raw SQL or PHP errors in production-facing markup.
- Empty states should explain that no data matched the filters.
- Loading states are currently limited; future reports should add lightweight progress or disabled states for expensive exports.

## Dark Mode

- Kynix reports support `body.kx-dark-mode`.
- Page-specific elements must include dark-mode rules when they introduce new surfaces.

## Icons

- Legacy Bootstrap glyphicons are available, but new report-specific icons should be minimal and consistent.
- Icon-only buttons require accessible labels or tooltips.

## Print And Exports

- `css/print.css` contains legacy print rules for stock, consumption, NIR, and sales pages.
- Kynix pages may include scoped print rules when needed.
- Excel/PDF/Print controls should not appear in print output.
- Export output must not include connection banners or debug rows.

## Responsive Breakpoints

Current shared breakpoints:

- `900px`: navigation and layout compression.
- `760px`: extended KPI grids and two-column report panels collapse.
- `520px`: phone layout, tighter typography, one-column action stacks.

All future reports must reuse the shared design system instead of creating a new theme.
