# Project Sessions

Use these sessions to continue focused work later with ChatGPT or Codex.

## S01 - Legacy PHP Report Reference

- Purpose: Preserve knowledge of the original reportSQL-driven pages.
- Current status: Active legacy reference.
- Main files: `reportSQL/*.php`, `stoc.php`, `consum.php`, `nir.php`.
- Completed work: Original SambaPOS report queries and print rules exist.
- Pending work: Reduce raw SQL errors, modernize query patterns, document each query.
- Shared dependencies: `config.php`, Bootstrap 3, `js/reportJS.js`, `css/print.css`.
- Future review notes: Do not rewrite without reconciling output against SambaPOS.

## S02 - Shared Kynix UI Design System

- Purpose: Maintain shared visual language for modern reports.
- Current status: Used by Daily Sales, Periodic Sales, and Inventory Analytics.
- Main files: `css/vanzari.css`, `header.php`, `css/print.css`.
- Completed work: Topbar, shell, hero, panels, KPI cards, tables, charts, dark mode.
- Pending work: Consolidate repeated report-specific styles over time.
- Shared dependencies: Bootstrap 3, jQuery, logo assets.
- Future review notes: Future reports must reuse Kynix classes.

## S03 - Daily Sales

- Purpose: 06:00-to-06:00 sales dashboard for a single business day.
- Current status: Modern Kynix report.
- Main files: `vanzari.php`.
- Completed work: Item sales, payments, tickets, service charge, discount, hourly sales.
- Pending work: Safer production error handling and shared helper extraction.
- Shared dependencies: `Orders`, `Payments`, `PaymentTypes`, `Calculations`, `Tickets`.
- Future review notes: Preserve `CalculationAmount` handling.

## S04 - Periodic Sales

- Purpose: Multi-day sales dashboard using the same business rules as Daily Sales.
- Current status: Modern Kynix report.
- Main files: `vanzariPerioada.php`.
- Completed work: Date range filters, item/payment/calculation/hour summaries.
- Pending work: Shared helper extraction with Daily Sales.
- Shared dependencies: Same as Daily Sales.
- Future review notes: Keep half-open date filters.

## S05 - Inventory Analytics

- Purpose: Inventory balance, movement, recipe usage, valuation, and ledger dashboard.
- Current status: Modern Kynix report with corrected data logic.
- Main files: `inventoryDaily.php`.
- Completed work: Dashboard UI, warehouse-keyed balances, duplicate-safe recipe usage, current balance, valuation.
- Pending work: Validate transfer, waste, adjustment, production, physical count, and negative-stock samples when available.
- Shared dependencies: `InventoryTransactions`, `Orders`, `MenuItemPortions`, `Recipes`, `RecipeItems`, `InventoryItems`, `Warehouses`.
- Future review notes: Do not change formulas without schema/sample verification.

## S06 - Purchase History / GRN

- Purpose: Goods receipt and purchase history reporting.
- Current status: Legacy Bootstrap/reportSQL page.
- Main files: `nir.php`, `reportSQL/raport3.php`.
- Completed work: Existing date range report and print support.
- Pending work: Modernize to Kynix UI, parameterize and document SQL if needed.
- Shared dependencies: Inventory/purchase transaction tables.
- Future review notes: Validate purchase document semantics first.

## S07 - Consumption

- Purpose: Consumption voucher reporting.
- Current status: Legacy Bootstrap/reportSQL page.
- Main files: `consum.php`, `reportSQL/raport4.php`, `reportSQL/raport5.php`.
- Completed work: Existing date range report and print support.
- Pending work: Reduce raw SQL errors, document warehouse/consumption assumptions.
- Shared dependencies: Inventory consumption tables.
- Future review notes: Check whether periodic consumption tables are populated.

## S08 - Current Stock

- Purpose: Current stock report.
- Current status: Legacy Bootstrap/reportSQL page.
- Main files: `stoc.php`, `reportSQL/raport1.php`.
- Completed work: Existing stock table and print rules.
- Pending work: Reconcile with Inventory Analytics current balance method.
- Shared dependencies: Inventory item and movement tables.
- Future review notes: Avoid conflicting balance definitions.

## S09 - Stock Ledger

- Purpose: Item-level movement ledger and running balance.
- Current status: Implemented inside Inventory Analytics details, not as a standalone page.
- Main files: `inventoryDaily.php`.
- Completed work: Expanded ledger rows for the selected period.
- Pending work: Standalone ledger view if requested.
- Shared dependencies: Inventory movement CTEs.
- Future review notes: Keep item+warehouse grain.

## S10 - Service Charge, Discount & Payment Accuracy

- Purpose: Keep sales financial summaries accurate.
- Current status: Implemented in Daily and Periodic Sales.
- Main files: `vanzari.php`, `vanzariPerioada.php`.
- Completed work: `CalculationAmount` used for calculations.
- Pending work: More payment type mapping validation if new payment names appear.
- Shared dependencies: `Payments`, `PaymentTypes`, `Calculations`, `CalculationTypes`, `Tickets`.
- Future review notes: Do not use calculation rate as monetary amount.

## S11 - Excel, PDF & Print

- Purpose: Keep exports professional and accurate.
- Current status: Mixed; Inventory has custom Excel/PDF, legacy pages use print CSS.
- Main files: `inventoryDaily.php`, `css/print.css`, report pages.
- Completed work: Inventory Excel/PDF/print support.
- Pending work: Shared export strategy and print QA across all reports.
- Shared dependencies: Browser print, table markup, Kynix classes.
- Future review notes: Exports must not include debug/connection output.

## S12 - Mobile Responsive System

- Purpose: Ensure reports work on phone and tablet screens.
- Current status: Strongest in Kynix reports, weaker in legacy pages.
- Main files: `css/vanzari.css`, `inventoryDaily.php`.
- Completed work: Responsive topbar, KPI grids, mobile inventory cards.
- Pending work: Mobile modernization for legacy pages.
- Shared dependencies: Kynix layout classes and Bootstrap 3.
- Future review notes: Check 390px and 768px before release.

## S13 - Navigation & Report Center

- Purpose: Keep report navigation clear and provide the main Kynix dashboard landing page.
- Current status: Dashboard implemented in `index.php`; shared top navigation in `header.php`.
- Main files: `header.php`, `index.php`, `completeSales.php`.
- Completed work: Report Center Dashboard with sales KPIs, inventory KPIs, alerts, insights, quick actions, grouped report cards, localStorage recent reports, Excel export, and PDF/print summary.
- Pending work: Browser-based visual QA screenshots and deeper future report modules for planned cards.
- Shared dependencies: Kynix topbar and logo.
- Future review notes: Keep active state based on current page, avoid broken links for planned reports, and keep dashboard queries summary-only.
