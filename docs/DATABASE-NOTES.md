# SambaPOS Database Notes

This document records verified database findings used by SambaWebReports. Do not add passwords, server names, private customer data, or sensitive business data here.

## Verified Facts

- Sales item reporting uses `Orders` joined to `MenuItems` for menu item names/groups where needed.
- Payment totals use `Payments` and `PaymentTypes`.
- Service charge and discount calculations must sum `Calculations.CalculationAmount`.
- `Calculations.Amount` may represent a percentage or rate and must not be treated as the final monetary amount without verification.
- Daily and Periodic Sales use 06:00-to-06:00 work periods.
- Inventory recipe usage follows this verified path:
  - `Orders`
  - `MenuItemPortions`
  - `Recipes.Portion_Id`
  - `RecipeItems`
  - `InventoryItems`
- `PeriodicConsumptionItems` and `WarehouseConsumptions` may be empty and must not be assumed to be the only balance source.
- Inventory balance logic must be verified by inventory item and warehouse.
- Closing Balance and Current Balance are separate concepts.

## Current Implementation Choices

- Daily Sales and Periodic Sales use half-open date filters and parameterized SQL.
- Inventory Analytics currently calculates Current Balance from verified historical movement when no reliable snapshot table is populated.
- Inventory Analytics prevents duplicate recipe usage by checking whether a matching inventory-out transaction already exists near the order time.
- Inventory stock value uses weighted purchase unit cost when verified purchase transactions are available, falling back to `InventoryItems.DefaultBaseUnitCost` when purchase cost is unavailable.

## Known Uncertainties

- Production, waste, adjustment, transfer, physical count, sales return, and direct usage classifications need more live SambaPOS sample rows before they can be considered fully verified.
- Historical stock valuation may require a SambaPOS-specific costing source if period-accurate cost snapshots are added later.
- Some legacy reportSQL files expose raw SQL errors and use older query patterns.

## Future Validation Requirements

- Confirm transaction classification with sample rows for every movement type before changing inventory formulas.
- Verify whether recipe usage is posted to `InventoryTransactions` in each database before relying on `Orders + Recipes`.
- Reconcile at least one item per warehouse for warehouse-specific inventory reports.
- Confirm unit conversion rules before supporting non-base inventory units.
- Do not use string-based transaction type matching when a reliable type/document ID mapping has been verified.
