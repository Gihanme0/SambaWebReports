# Report Testing Checklist

Use this checklist for every report change.

## PHP

- [ ] `php -l <file>.php` passed.
- [ ] No warnings or notices.
- [ ] No raw SQL errors exposed in normal UI.
- [ ] Required includes load correctly.

## Database

- [ ] Query executes with real local SambaPOS database.
- [ ] Date filters use verified work-period boundaries.
- [ ] Empty data is handled gracefully.
- [ ] Sample rows reconcile to source transactions.
- [ ] No double counting.
- [ ] Warehouse filters verified where relevant.
- [ ] Item/group filters verified where relevant.

## Desktop

- [ ] 1440px layout checked.
- [ ] 1366px layout checked.
- [ ] KPI cards, filters, charts, and tables fit without overlap.

## Tablet

- [ ] 768px layout checked.
- [ ] Navigation collapses correctly.
- [ ] Tables or cards remain usable.

## Mobile

- [ ] 390px layout checked.
- [ ] No forced horizontal scrolling on modern Kynix reports.
- [ ] Touch targets are readable.
- [ ] Text does not overflow buttons or cards.

## Features

- [ ] Search.
- [ ] Sorting.
- [ ] Pagination.
- [ ] Filters.
- [ ] Reset.
- [ ] Dark mode.
- [ ] Details/expanded rows.
- [ ] Empty state.
- [ ] Error state.

## Exports

- [ ] Excel export opens with expected report rows.
- [ ] PDF export opens/prints without debug output.
- [ ] Browser print view is readable.
- [ ] Numeric values remain numeric where practical.
- [ ] Repeated print headers are correct for long tables.
- [ ] Export does not include connection banners.

## Regression

- [ ] Daily Sales still works.
- [ ] Periodic Sales still works.
- [ ] Inventory Analytics still works.
- [ ] Purchase History, Consumption, and Stock are unaffected unless intentionally changed.

## Authentication And Authorization

- [ ] Unauthenticated report URLs redirect to login.
- [ ] Valid login regenerates the session ID.
- [ ] Invalid login shows a generic error.
- [ ] Inactive users cannot sign in.
- [ ] Session timeout returns the user to login.
- [ ] Direct report URLs enforce the matching permission.
- [ ] Navigation hides unauthorized report links.
- [ ] Admin pages require admin permissions.
- [ ] CSRF rejection works on admin POST forms.
- [ ] Passwords are stored as hashes, never plaintext.
- [ ] Audit entries are created for login, logout, user, role, and password actions.
