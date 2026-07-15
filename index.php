<?php
/*
 * Kynix Report Center Dashboard.
 * Purpose: main landing page for sales, payments, inventory, purchases, and operational report access.
 * Data sources: Orders, Payments, PaymentTypes, Tickets, Calculations, InventoryTransactions, Recipes, RecipeItems, InventoryItems, and Warehouses.
 * Work-period cutoff: current business day uses 06:00 inclusive to next-day 06:00 exclusive.
 * Maintenance: use summary queries only; do not load full report datasets or expose raw SQL errors.
 */
require_once __DIR__ . '/auth/auth.php';
auth_require_permission('dashboard.view');
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    auth_require_export_permission('excel');
}
ob_start();
require_once __DIR__ . '/config.php';
$dashboardConfigOutput = ob_get_clean();
$reportName = 'Kynix Report Center Dashboard';
$canExportExcel = auth_has_permission('exports.excel');
$canExportPdf = auth_has_permission('exports.pdf');
$canExportPrint = auth_has_permission('exports.print');

function dash_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function dash_money($value) { return 'Rs. ' . number_format((float)$value, 2); }
function dash_num($value, $decimals = 3) { return number_format((float)$value, $decimals); }
function dash_pct_width($value, $max) { return $max > 0 ? max(3, min(100, ((float)$value / (float)$max) * 100)) : 0; }
function dash_payment_bucket($name) {
    $n = strtolower(trim((string)$name));
    if (strpos($n, 'cash') !== false) return 'cash';
    if (strpos($n, 'card') !== false || strpos($n, 'visa') !== false || strpos($n, 'master') !== false) return 'card';
    if (strpos($n, 'advance') !== false) return 'advance';
    if (strpos($n, 'voucher') !== false) return 'voucher';
    if (strpos($n, 'credit') !== false || strpos($n, 'custom') !== false || strpos($n, 'account') !== false) return 'credit';
    return 'other';
}
function dash_calc_bucket($name, $amount) {
    $n = strtolower(trim((string)$name));
    if (strpos($n, 'service') !== false || strpos($n, 'sc') === 0 || strpos($n, 'service charge') !== false) return 'service_charge';
    if (strpos($n, 'discount') !== false || strpos($n, 'disc') !== false || (float)$amount < 0) return 'discount';
    return 'other';
}
function dash_fetch_all($conn, $sql, $params = array(), &$errors = array(), $label = 'Query') {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors[] = $label . ' is unavailable.';
        return array();
    }
    $rows = array();
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}
function dash_fetch_one($conn, $sql, $params = array(), &$errors = array(), $label = 'Query') {
    $rows = dash_fetch_all($conn, $sql, $params, $errors, $label);
    return !empty($rows) ? $rows[0] : array();
}
function dash_icon($name) {
    $paths = array(
        'sales' => '<path d="M4 15h16"/><path d="M7 15V9"/><path d="M12 15V5"/><path d="M17 15v-3"/>',
        'payments' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 14h4"/>',
        'inventory' => '<path d="M4 7l8-4 8 4-8 4-8-4z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>',
        'purchase' => '<path d="M6 7h15l-2 8H8L6 7z"/><path d="M6 7l-1-3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
        'reports' => '<path d="M6 3h9l3 3v15H6z"/><path d="M15 3v4h4"/><path d="M9 13h6"/><path d="M9 17h6"/>',
        'alert' => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 9v5"/><path d="M12 17h.01"/>',
        'print' => '<path d="M7 8V3h10v5"/><path d="M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7z"/>',
        'pdf' => '<path d="M6 3h9l3 3v15H6z"/><path d="M15 3v4h4"/><path d="M8 14h2a2 2 0 0 0 0-4H8v7"/><path d="M13 10v7"/><path d="M16 10h3"/><path d="M16 13h2"/>',
        'excel' => '<path d="M5 3h10l4 4v14H5z"/><path d="M15 3v5h4"/><path d="M8 11l6 6"/><path d="M14 11l-6 6"/>',
        'analytics' => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15l3-4 3 2 4-7"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/>'
    );
    $body = isset($paths[$name]) ? $paths[$name] : $paths['reports'];
    return '<svg class="dash-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

$now = new DateTime();
$periodStart = clone $now;
$periodStart->setTime(6, 0, 0);
if ($now < $periodStart) {
    $periodStart->modify('-1 day');
}
$periodEnd = clone $periodStart;
$periodEnd->modify('+1 day');
$periodStartSql = $periodStart->format('Y-m-d') . 'T06:00:00.000';
$periodEndSql = $periodEnd->format('Y-m-d') . 'T06:00:00.000';
$periodLabel = $periodStart->format('Y-m-d H:i') . ' to ' . $periodEnd->format('Y-m-d H:i');

$errors = array();
$sales = array(
    'item_sales' => 0, 'net_sales' => 0, 'payment_total' => 0, 'ticket_count' => 0, 'avg_bill' => 0,
    'cash' => 0, 'card' => 0, 'service_charge' => 0, 'discount' => 0
);
$inventory = array(
    'total_items' => 0, 'items_used' => 0, 'current_value' => 0, 'negative_stock' => 0,
    'low_stock' => 0, 'zero_stock' => 0, 'usage_qty' => 0, 'no_movement' => 0
);
$paymentRows = array();
$topItems = array();
$hourRows = array();
$groupRows = array();
$topUsageRows = array();
$stockHealthRows = array();
$valueGroupRows = array();
$noMovementRows = array();

$itemSalesRow = dash_fetch_one($conn, "
    SELECT
        SUM(CAST(o.[Price] AS decimal(18,2)) * CAST(o.[Quantity] AS decimal(18,3))) AS ItemSales,
        SUM(CAST(o.[Quantity] AS decimal(18,3))) AS Qty
    FROM [Orders] o
    WHERE o.[CreatedDateTime] >= ?
      AND o.[CreatedDateTime] < ?
      AND o.[CalculatePrice] <> 0
", array($periodStartSql, $periodEndSql), $errors, 'Today sales');
$sales['item_sales'] = isset($itemSalesRow['ItemSales']) ? (float)$itemSalesRow['ItemSales'] : 0;

$paymentRows = dash_fetch_all($conn, "
    SELECT ISNULL(p.[Name], pt.[Name]) AS PaymentName, SUM(CAST(p.[Amount] AS decimal(18,2))) AS TotalAmount
    FROM [Payments] p
    LEFT JOIN [PaymentTypes] pt ON pt.[Id] = p.[PaymentTypeId]
    WHERE p.[Date] >= ? AND p.[Date] < ?
    GROUP BY ISNULL(p.[Name], pt.[Name])
    ORDER BY TotalAmount DESC
", array($periodStartSql, $periodEndSql), $errors, 'Payments');
foreach ($paymentRows as $row) {
    $amount = isset($row['TotalAmount']) ? (float)$row['TotalAmount'] : 0;
    $sales['payment_total'] += $amount;
    $bucket = dash_payment_bucket(isset($row['PaymentName']) ? $row['PaymentName'] : '');
    if ($bucket === 'cash' || $bucket === 'card') {
        $sales[$bucket] += $amount;
    }
}

$ticketRow = dash_fetch_one($conn, "
    SELECT COUNT(DISTINCT p.[TicketId]) AS TicketCount
    FROM [Payments] p
    WHERE p.[Date] >= ? AND p.[Date] < ?
", array($periodStartSql, $periodEndSql), $errors, 'Tickets');
$sales['ticket_count'] = isset($ticketRow['TicketCount']) ? (int)$ticketRow['TicketCount'] : 0;
$sales['avg_bill'] = $sales['ticket_count'] > 0 ? $sales['payment_total'] / $sales['ticket_count'] : 0;

$calcRows = dash_fetch_all($conn, "
    SELECT ISNULL(ct.[Name], c.[Name]) AS CalculationName, SUM(CAST(c.[CalculationAmount] AS decimal(18,2))) AS TotalAmount
    FROM [Calculations] c
    LEFT JOIN [CalculationTypes] ct ON ct.[Id] = c.[CalculationTypeId]
    LEFT JOIN [Tickets] t ON t.[Id] = c.[TicketId]
    WHERE t.[Date] >= ? AND t.[Date] < ?
    GROUP BY ISNULL(ct.[Name], c.[Name])
", array($periodStartSql, $periodEndSql), $errors, 'Service charge and discount');
foreach ($calcRows as $row) {
    $name = isset($row['CalculationName']) ? $row['CalculationName'] : '';
    $amount = isset($row['TotalAmount']) ? (float)$row['TotalAmount'] : 0;
    $bucket = dash_calc_bucket($name, $amount);
    if ($bucket === 'service_charge') {
        $sales['service_charge'] += abs($amount);
    } elseif ($bucket === 'discount') {
        $sales['discount'] += abs($amount);
    }
}
$sales['net_sales'] = $sales['item_sales'] + $sales['service_charge'] - $sales['discount'];

$topItems = dash_fetch_all($conn, "
    SELECT TOP 5 o.[MenuItemName] AS ItemName, SUM(CAST(o.[Quantity] AS decimal(18,3))) AS Qty,
           SUM(CAST(o.[Price] AS decimal(18,2)) * CAST(o.[Quantity] AS decimal(18,3))) AS Amount
    FROM [Orders] o
    WHERE o.[CreatedDateTime] >= ? AND o.[CreatedDateTime] < ? AND o.[CalculatePrice] <> 0
    GROUP BY o.[MenuItemName]
    ORDER BY Qty DESC, Amount DESC
", array($periodStartSql, $periodEndSql), $errors, 'Top selling items');

$hourRows = dash_fetch_all($conn, "
    SELECT DATEPART(HOUR, p.[Date]) AS SalesHour, SUM(CAST(p.[Amount] AS decimal(18,2))) AS TotalAmount
    FROM [Payments] p
    WHERE p.[Date] >= ? AND p.[Date] < ?
    GROUP BY DATEPART(HOUR, p.[Date])
    ORDER BY DATEPART(HOUR, p.[Date])
", array($periodStartSql, $periodEndSql), $errors, 'Hourly sales');

$groupRows = dash_fetch_all($conn, "
    SELECT TOP 5 ISNULL(m.[GroupCode], 'Uncategorized') AS GroupName,
           SUM(CAST(o.[Price] AS decimal(18,2)) * CAST(o.[Quantity] AS decimal(18,3))) AS Amount
    FROM [Orders] o
    LEFT JOIN [MenuItems] m ON m.[Id] = o.[MenuItemId]
    WHERE o.[CreatedDateTime] >= ? AND o.[CreatedDateTime] < ? AND o.[CalculatePrice] <> 0
    GROUP BY ISNULL(m.[GroupCode], 'Uncategorized')
    ORDER BY Amount DESC
", array($periodStartSql, $periodEndSql), $errors, 'Sales by group');

$inventorySql = <<<'SQL'
DECLARE @StartDate datetime = ?;
DECLARE @EndDate datetime = ?;
DECLARE @LowStockThreshold decimal(18,6) = 1;

WITH TxExpanded AS (
    SELECT it.InventoryItem_Id AS ItemCode, it.Date, it.TargetWarehouseId AS WarehouseId,
           CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6)) AS Qty,
           ISNULL(itt.Name,'') AS TransactionTypeName, ISNULL(itd.Name,'') AS DocumentName,
           'IN' AS Direction, it.SourceWarehouseId, it.TargetWarehouseId
    FROM InventoryTransactions it
    LEFT JOIN InventoryTransactionTypes itt ON itt.Id = it.InventoryTransactionTypeId
    LEFT JOIN InventoryTransactionDocuments itd ON itd.Id = it.InventoryTransactionDocumentId
    WHERE it.TargetWarehouseId <> 0 AND it.InventoryItem_Id IS NOT NULL
    UNION ALL
    SELECT it.InventoryItem_Id, it.Date, it.SourceWarehouseId,
           -CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6)),
           ISNULL(itt.Name,''), ISNULL(itd.Name,''), 'OUT', it.SourceWarehouseId, it.TargetWarehouseId
    FROM InventoryTransactions it
    LEFT JOIN InventoryTransactionTypes itt ON itt.Id = it.InventoryTransactionTypeId
    LEFT JOIN InventoryTransactionDocuments itd ON itd.Id = it.InventoryTransactionDocumentId
    WHERE it.SourceWarehouseId <> 0 AND it.InventoryItem_Id IS NOT NULL
),
TxClassified AS (
    SELECT tx.*,
        CASE
            WHEN Direction = 'IN' AND SourceWarehouseId = 0 AND TargetWarehouseId <> 0 AND (TransactionTypeName LIKE '%Purchase%' OR DocumentName LIKE '%Purchase%') THEN 'Purchase'
            WHEN Direction = 'IN' THEN 'Other In'
            ELSE 'Other Out'
        END AS MovementClass
    FROM TxExpanded tx
),
RecipeUsage AS (
    SELECT ri.InventoryItem_Id AS ItemCode, o.CreatedDateTime AS Date, o.WarehouseId,
           CAST(o.Quantity * ri.Quantity AS decimal(18,6)) AS UsedQty
    FROM Orders o
    JOIN MenuItemPortions mip ON mip.MenuItemId = o.MenuItemId AND mip.Name = o.PortionName
    JOIN Recipes r ON r.Portion_Id = mip.Id
    JOIN RecipeItems ri ON ri.RecipeId = r.Id
    WHERE o.DecreaseInventory = 1 AND o.CalculatePrice <> 0
      AND NOT EXISTS (
          SELECT 1 FROM InventoryTransactions posted
          WHERE posted.InventoryItem_Id = ri.InventoryItem_Id
            AND posted.SourceWarehouseId = o.WarehouseId
            AND posted.TargetWarehouseId = 0
            AND posted.Date >= DATEADD(minute, -10, o.CreatedDateTime)
            AND posted.Date < DATEADD(minute, 10, o.CreatedDateTime)
            AND ABS(CAST(CASE WHEN posted.Multiplier = 0 THEN posted.Quantity ELSE posted.Quantity * posted.Multiplier END AS decimal(18,6)) - CAST(o.Quantity * ri.Quantity AS decimal(18,6))) < 0.0001
      )
),
Ledger AS (
    SELECT ItemCode, Date, WarehouseId, MovementClass,
           CASE WHEN Qty > 0 THEN Qty ELSE 0 END AS QtyIn,
           CASE WHEN Qty < 0 THEN ABS(Qty) ELSE 0 END AS QtyOut,
           Qty AS NetQty
    FROM TxClassified
    UNION ALL
    SELECT ItemCode, Date, WarehouseId, 'Recipe Consumption', 0, UsedQty, -UsedQty
    FROM RecipeUsage
),
CurrentAgg AS (
    SELECT ItemCode, SUM(NetQty) AS CurrentQty
    FROM Ledger
    WHERE Date < SYSDATETIME()
    GROUP BY ItemCode
),
PeriodAgg AS (
    SELECT ItemCode, SUM(QtyOut) AS UsageQty, COUNT(*) AS MovementCount
    FROM Ledger
    WHERE Date >= @StartDate AND Date < @EndDate
    GROUP BY ItemCode
),
PurchaseCost AS (
    SELECT it.InventoryItem_Id AS ItemCode,
           SUM(it.TotalPrice) / NULLIF(SUM(CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6))), 0) AS UnitCost
    FROM InventoryTransactions it
    LEFT JOIN InventoryTransactionTypes itt ON itt.Id = it.InventoryTransactionTypeId
    LEFT JOIN InventoryTransactionDocuments itd ON itd.Id = it.InventoryTransactionDocumentId
    WHERE it.SourceWarehouseId = 0 AND it.TargetWarehouseId <> 0 AND it.InventoryItem_Id IS NOT NULL
      AND it.TotalPrice > 0 AND (ISNULL(itt.Name,'') LIKE '%Purchase%' OR ISNULL(itd.Name,'') LIKE '%Purchase%')
    GROUP BY it.InventoryItem_Id
),
ItemValue AS (
    SELECT ii.Id, ii.Name, ISNULL(NULLIF(ii.GroupCode,''),'Ungrouped') AS GroupCode,
           ISNULL(ca.CurrentQty, 0) AS CurrentQty,
           ISNULL(pa.UsageQty, 0) AS UsageQty,
           COALESCE(pc.UnitCost, NULLIF(ii.DefaultBaseUnitCost, 0), 0) AS UnitCost,
           ISNULL(pa.MovementCount, 0) AS MovementCount
    FROM InventoryItems ii
    LEFT JOIN CurrentAgg ca ON ca.ItemCode = ii.Id
    LEFT JOIN PeriodAgg pa ON pa.ItemCode = ii.Id
    LEFT JOIN PurchaseCost pc ON pc.ItemCode = ii.Id
)
SELECT
    COUNT(*) AS TotalItems,
    SUM(CASE WHEN UsageQty > 0 THEN 1 ELSE 0 END) AS ItemsUsed,
    SUM(CurrentQty * UnitCost) AS CurrentStockValue,
    SUM(CASE WHEN CurrentQty < 0 THEN 1 ELSE 0 END) AS NegativeStock,
    SUM(CASE WHEN CurrentQty > 0 AND CurrentQty <= @LowStockThreshold THEN 1 ELSE 0 END) AS LowStock,
    SUM(CASE WHEN CurrentQty = 0 THEN 1 ELSE 0 END) AS ZeroStock,
    SUM(UsageQty) AS UsageQty,
    SUM(CASE WHEN MovementCount = 0 THEN 1 ELSE 0 END) AS NoMovement
FROM ItemValue;
SQL;
$inventoryRow = dash_fetch_one($conn, $inventorySql, array($periodStartSql, $periodEndSql), $errors, 'Inventory summary');
foreach ($inventory as $key => $value) {
    $map = array(
        'total_items' => 'TotalItems', 'items_used' => 'ItemsUsed', 'current_value' => 'CurrentStockValue',
        'negative_stock' => 'NegativeStock', 'low_stock' => 'LowStock', 'zero_stock' => 'ZeroStock',
        'usage_qty' => 'UsageQty', 'no_movement' => 'NoMovement'
    );
    $inventory[$key] = isset($inventoryRow[$map[$key]]) ? (float)$inventoryRow[$map[$key]] : 0;
}

$topUsageRows = dash_fetch_all($conn, "
    SELECT TOP 5 ii.Name AS ItemName, SUM(CAST(o.Quantity * ri.Quantity AS decimal(18,6))) AS UsageQty
    FROM Orders o
    JOIN MenuItemPortions mip ON mip.MenuItemId = o.MenuItemId AND mip.Name = o.PortionName
    JOIN Recipes r ON r.Portion_Id = mip.Id
    JOIN RecipeItems ri ON ri.RecipeId = r.Id
    JOIN InventoryItems ii ON ii.Id = ri.InventoryItem_Id
    WHERE o.CreatedDateTime >= ? AND o.CreatedDateTime < ? AND o.DecreaseInventory = 1 AND o.CalculatePrice <> 0
    GROUP BY ii.Name
    ORDER BY UsageQty DESC
", array($periodStartSql, $periodEndSql), $errors, 'Top inventory usage');

$valueGroupRows = dash_fetch_all($conn, "
    WITH CurrentAgg AS (
        SELECT ItemCode, SUM(NetQty) AS CurrentQty
        FROM (
            SELECT it.InventoryItem_Id AS ItemCode, it.TargetWarehouseId AS WarehouseId, it.Date,
                   CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6)) AS NetQty
            FROM InventoryTransactions it WHERE it.TargetWarehouseId <> 0 AND it.InventoryItem_Id IS NOT NULL
            UNION ALL
            SELECT it.InventoryItem_Id, it.SourceWarehouseId, it.Date,
                   -CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6))
            FROM InventoryTransactions it WHERE it.SourceWarehouseId <> 0 AND it.InventoryItem_Id IS NOT NULL
        ) x
        WHERE Date < SYSDATETIME()
        GROUP BY ItemCode
    ),
    PurchaseCost AS (
        SELECT InventoryItem_Id AS ItemCode, SUM(TotalPrice) / NULLIF(SUM(CAST(CASE WHEN Multiplier = 0 THEN Quantity ELSE Quantity * Multiplier END AS decimal(18,6))), 0) AS UnitCost
        FROM InventoryTransactions
        WHERE SourceWarehouseId = 0 AND TargetWarehouseId <> 0 AND InventoryItem_Id IS NOT NULL AND TotalPrice > 0
        GROUP BY InventoryItem_Id
    )
    SELECT TOP 5 ISNULL(NULLIF(ii.GroupCode,''),'Ungrouped') AS GroupName,
           SUM(ISNULL(ca.CurrentQty,0) * COALESCE(pc.UnitCost, NULLIF(ii.DefaultBaseUnitCost,0), 0)) AS StockValue
    FROM InventoryItems ii
    LEFT JOIN CurrentAgg ca ON ca.ItemCode = ii.Id
    LEFT JOIN PurchaseCost pc ON pc.ItemCode = ii.Id
    GROUP BY ISNULL(NULLIF(ii.GroupCode,''),'Ungrouped')
    ORDER BY StockValue DESC
", array(), $errors, 'Inventory value by group');

$noMovementRows = dash_fetch_all($conn, "
    SELECT TOP 5 ii.Name AS ItemName
    FROM InventoryItems ii
    WHERE NOT EXISTS (
        SELECT 1 FROM InventoryTransactions it
        WHERE it.InventoryItem_Id = ii.Id AND it.Date >= ? AND it.Date < ?
    )
    AND NOT EXISTS (
        SELECT 1
        FROM Orders o
        JOIN MenuItemPortions mip ON mip.MenuItemId = o.MenuItemId AND mip.Name = o.PortionName
        JOIN Recipes r ON r.Portion_Id = mip.Id
        JOIN RecipeItems ri ON ri.RecipeId = r.Id
        WHERE ri.InventoryItem_Id = ii.Id AND o.CreatedDateTime >= ? AND o.CreatedDateTime < ?
    )
    ORDER BY ii.Name
", array($periodStartSql, $periodEndSql, $periodStartSql, $periodEndSql), $errors, 'No movement items');

$alerts = array();
if ($inventory['negative_stock'] > 0) { $alerts[] = number_format($inventory['negative_stock']) . ' inventory items have negative stock.'; }
if ($inventory['low_stock'] > 0) { $alerts[] = number_format($inventory['low_stock']) . ' inventory items are at or below the low-stock threshold.'; }
if ($inventory['zero_stock'] > 0) { $alerts[] = number_format($inventory['zero_stock']) . ' inventory items currently have zero stock.'; }
if ($sales['item_sales'] > 0 && $sales['payment_total'] <= 0) { $alerts[] = 'Sales exist for the work period, but no payment total was found.'; }
if (abs($sales['net_sales'] - $sales['payment_total']) > 0.01 && $sales['payment_total'] > 0) { $alerts[] = 'Net sales and payment totals differ; review payments and calculations.'; }
foreach ($errors as $error) { $alerts[] = $error; }

$reportGroups = array(
    'Sales Reports' => array(
        array('Daily Sales', 'Single work-period sales dashboard.', 'Modern', 'vanzari.php', 'sales', 'daily_sales.view'),
        array('Periodic Sales', 'Multi-day sales performance report.', 'Modern', 'vanzariPerioada.php', 'analytics', 'periodic_sales.view'),
        array('Payment Summary', 'Payment totals by type.', 'In Progress', 'vanzari.php', 'payments', 'daily_sales.view'),
        array('Service Charge & Discount', 'CalculationAmount-based charges and discounts.', 'In Progress', 'vanzari.php', 'reports', 'daily_sales.view'),
        array('Item Sales', 'Menu item quantity and amount analysis.', 'In Progress', 'vanzari.php', 'sales', 'daily_sales.view'),
        array('Group Sales', 'Sales grouped by menu category.', 'In Progress', 'vanzariPerioada.php', 'analytics', 'periodic_sales.view')
    ),
    'Inventory Reports' => array(
        array('Inventory Analytics', 'Warehouse-aware inventory dashboard.', 'Modern', 'inventoryDaily.php', 'inventory', 'inventory_analytics.view'),
        array('Current Stock', 'Legacy current stock report.', 'Legacy', 'stoc.php', 'inventory', 'stock.view'),
        array('Consumption', 'Legacy consumption voucher report.', 'Legacy', 'consum.php', 'inventory', 'consumption.view'),
        array('Stock Ledger', 'Item movement ledger inside Inventory Analytics.', 'In Progress', 'inventoryDaily.php', 'reports', 'inventory_analytics.view'),
        array('Stock Movement', 'Movement analysis by inventory transaction.', 'In Progress', 'inventoryDaily.php', 'analytics', 'inventory_analytics.view'),
        array('Low / Negative Stock', 'Attention view for stock exceptions.', 'In Progress', 'inventoryDaily.php?showLowStock=1', 'alert', 'inventory_analytics.view')
    ),
    'Purchasing' => array(
        array('Purchase History', 'Goods receipt and purchase history.', 'Legacy', 'nir.php', 'purchase', 'purchase_history.view'),
        array('Supplier Purchases', 'Supplier-level purchasing analysis.', 'Planned', '', 'purchase', 'purchase_history.view'),
        array('Purchase Returns', 'Returned purchase movement report.', 'Planned', '', 'purchase', 'purchase_history.view'),
        array('GRN Summary', 'Goods receipt summary dashboard.', 'Planned', '', 'reports', 'purchase_history.view')
    ),
    'Business Analytics' => array(
        array('Top Selling Items', 'Today top item ranking.', 'In Progress', 'vanzari.php', 'analytics', 'daily_sales.view'),
        array('Hourly Sales', 'Sales trend by hour.', 'In Progress', 'vanzari.php', 'clock', 'daily_sales.view'),
        array('Payment Analysis', 'Payment mix and settlement review.', 'In Progress', 'vanzariPerioada.php', 'payments', 'periodic_sales.view'),
        array('Inventory Value', 'Current stock value by group.', 'In Progress', 'inventoryDaily.php', 'inventory', 'inventory_analytics.view'),
        array('Sales vs Usage', 'Compare menu sales with recipe usage.', 'In Progress', 'inventoryDaily.php', 'analytics', 'inventory_analytics.view'),
        array('Future Dashboard', 'Reserved for upcoming analytics.', 'Planned', '', 'reports', 'dashboard.view')
    )
);

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=kynix-report-center-' . date('Ymd-His') . '.xls');
    echo "\xEF\xBB\xBF";
    echo "<table border='1'>";
    echo "<tr><th colspan='4'>Kynix Report Center Dashboard</th></tr>";
    echo "<tr><td>Business</td><td>" . dash_h($BusinessName) . "</td><td>Generated</td><td>" . dash_h(date('Y-m-d H:i:s')) . "</td></tr>";
    echo "<tr><td>Work Period</td><td colspan='3'>" . dash_h($periodLabel) . "</td></tr>";
    echo "<tr><th colspan='4'>Sales KPIs</th></tr>";
    foreach (array('Today Sales' => dash_money($sales['item_sales']), 'Net Sales' => dash_money($sales['net_sales']), 'Ticket Count' => number_format($sales['ticket_count']), 'Average Bill' => dash_money($sales['avg_bill']), 'Cash' => dash_money($sales['cash']), 'Card' => dash_money($sales['card']), 'Service Charge' => dash_money($sales['service_charge']), 'Discount' => dash_money($sales['discount'])) as $label => $value) {
        echo "<tr><td>" . dash_h($label) . "</td><td colspan='3'>" . dash_h($value) . "</td></tr>";
    }
    echo "<tr><th colspan='4'>Inventory Summary</th></tr>";
    foreach (array('Total Inventory Items' => number_format($inventory['total_items']), 'Items Used Today' => number_format($inventory['items_used']), 'Current Stock Value' => dash_money($inventory['current_value']), 'Negative Stock Count' => number_format($inventory['negative_stock']), 'Low Stock Count' => number_format($inventory['low_stock']), 'Today Usage Quantity' => dash_num($inventory['usage_qty'])) as $label => $value) {
        echo "<tr><td>" . dash_h($label) . "</td><td colspan='3'>" . dash_h($value) . "</td></tr>";
    }
    echo "<tr><th colspan='4'>Payment Summary</th></tr>";
    foreach ($paymentRows as $row) { echo "<tr><td>" . dash_h($row['PaymentName']) . "</td><td colspan='3'>" . dash_h(dash_money($row['TotalAmount'])) . "</td></tr>"; }
    echo "<tr><th colspan='4'>Top Selling Items</th></tr>";
    foreach ($topItems as $row) { echo "<tr><td>" . dash_h($row['ItemName']) . "</td><td>" . dash_h($row['Qty']) . "</td><td colspan='2'>" . dash_h(dash_money($row['Amount'])) . "</td></tr>"; }
    echo "<tr><th colspan='4'>Alerts</th></tr>";
    if (empty($alerts)) { echo "<tr><td colspan='4'>No urgent issues detected.</td></tr>"; } else { foreach ($alerts as $alert) { echo "<tr><td colspan='4'>" . dash_h($alert) . "</td></tr>"; } }
    echo "</table>";
    exit;
}

$maxTopItem = 0; foreach ($topItems as $row) { if ((float)$row['Qty'] > $maxTopItem) { $maxTopItem = (float)$row['Qty']; } }
$maxPayment = 0; foreach ($paymentRows as $row) { if ((float)$row['TotalAmount'] > $maxPayment) { $maxPayment = (float)$row['TotalAmount']; } }
$maxHour = 0; foreach ($hourRows as $row) { if ((float)$row['TotalAmount'] > $maxHour) { $maxHour = (float)$row['TotalAmount']; } }
$maxGroup = 0; foreach ($groupRows as $row) { if ((float)$row['Amount'] > $maxGroup) { $maxGroup = (float)$row['Amount']; } }
$maxUsage = 0; foreach ($topUsageRows as $row) { if ((float)$row['UsageQty'] > $maxUsage) { $maxUsage = (float)$row['UsageQty']; } }
$maxValueGroup = 0; foreach ($valueGroupRows as $row) { if ((float)$row['StockValue'] > $maxValueGroup) { $maxValueGroup = (float)$row['StockValue']; } }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo dash_h($reportName); ?></title>
    <link href="./css/vanzari.css" rel="stylesheet" media="screen">
    <link href="./css/print.css" rel="stylesheet" media="print">
    <link href="./bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <script src="./jquery/jquery-1.8.3.min.js" charset="UTF-8"></script>
    <script src="./bootstrap/js/bootstrap.min.js"></script>
    <style>
        .kx-dashboard-page .kx-shell{width:min(1360px,calc(100% - 40px))}
        .dash-hero-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;position:relative;z-index:1}
        .dash-hero-meta div{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:14px;padding:11px 12px}
        .dash-hero-meta span{display:block;color:#bfdbfe;font-size:11px;font-weight:900;text-transform:uppercase}
        .dash-hero-meta strong{display:block;color:#fff;margin-top:4px;font-size:13px}
        .dash-grid-8{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
        .dash-grid-6{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px}
        .dash-kpi{min-height:132px}
        .dash-kpi small{display:block;color:#94a3b8;font-weight:800;margin-top:8px}
        .dash-section-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
        .dash-wide-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:20px;margin-bottom:20px}
        .dash-panel{padding:22px 24px}
        .dash-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
        .dash-panel-head h2{margin:0;font-size:20px;font-weight:900}
        .dash-panel-head p{margin:4px 0 0;color:#64748b;font-weight:700}
        .dash-badge{display:inline-block;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:900;white-space:nowrap}
        .dash-badge-modern{background:#dcfce7;color:#166534}.dash-badge-legacy{background:#fef3c7;color:#92400e}.dash-badge-progress{background:#dbeafe;color:#1e40af}.dash-badge-planned{background:#f1f5f9;color:#475569}
        .dash-report-groups{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:20px}
        .dash-report-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .dash-report-card{min-height:154px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:15px;display:flex;flex-direction:column;gap:10px}
        .dash-report-card h3{margin:0;font-size:16px;font-weight:900;color:#0f172a}.dash-report-card p{margin:0;color:#64748b;font-size:12px;font-weight:700;line-height:1.4}
        .dash-report-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.dash-report-action{margin-top:auto;font-weight:900;color:#2563eb;text-decoration:none}.dash-report-disabled{color:#94a3b8}
        .dash-svg{width:22px;height:22px;stroke:currentColor;stroke-width:2;fill:none;stroke-linecap:round;stroke-linejoin:round;color:#2563eb;flex:0 0 22px}
        .dash-actions{display:flex;gap:10px;flex-wrap:wrap}.dash-actions .kx-btn{display:inline-flex;align-items:center;justify-content:center}
        .dash-bar{margin:12px 0}.dash-bar-label{display:flex;justify-content:space-between;gap:10px;margin-bottom:7px}.dash-bar-label strong{color:#0f172a;font-size:13px}.dash-bar-label span{font-size:12px;color:#64748b;font-weight:900;text-align:right}.dash-bar-track{height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden}.dash-bar-fill{height:100%;border-radius:999px;background:#2563eb}.dash-bar-fill.green{background:#16a34a}.dash-bar-fill.red{background:#dc2626}.dash-bar-fill.dark{background:#0f172a}
        .dash-hour-grid{display:flex;align-items:flex-end;gap:8px;min-height:120px;overflow:hidden}.dash-hour-col{flex:1;min-width:20px;text-align:center;color:#64748b;font-size:10px;font-weight:900}.dash-hour-bar{height:20px;background:#2563eb;border-radius:999px 999px 4px 4px;margin-bottom:6px}
        .dash-alert-list{display:grid;gap:10px}.dash-alert-item{display:flex;gap:10px;align-items:flex-start;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:14px;padding:12px;font-weight:800}.dash-alert-ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
        .dash-recent-list{display:grid;gap:10px}.dash-recent-row{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #e5e7eb;border-radius:12px;padding:11px 12px}.dash-recent-row strong{color:#0f172a}.dash-recent-row span{display:block;color:#64748b;font-size:12px;font-weight:700}
        .dash-print-title{display:none}
        body.kx-dark-mode .dash-panel,body.kx-dark-mode .dash-report-card,body.kx-dark-mode .dash-recent-row{background:#0f172a;border-color:#1e293b;color:#e5e7eb}
        body.kx-dark-mode .dash-report-card h3,body.kx-dark-mode .dash-bar-label strong,body.kx-dark-mode .dash-panel-head h2,body.kx-dark-mode .dash-recent-row strong{color:#f8fafc}
        body.kx-dark-mode .dash-bar-track{background:#1e293b}
        @media(max-width:1180px){.dash-grid-8{grid-template-columns:repeat(2,1fr)}.dash-grid-6{grid-template-columns:repeat(3,1fr)}.dash-wide-grid,.dash-report-groups{grid-template-columns:1fr}}
        @media(max-width:768px){.kx-dashboard-page .kx-shell{width:calc(100% - 24px)}.dash-hero-meta,.dash-section-grid,.dash-grid-8,.dash-grid-6,.dash-report-list{grid-template-columns:1fr}.dash-actions{display:grid;grid-template-columns:1fr}.dash-actions .kx-btn{width:100%}.dash-panel{padding:18px}.dash-report-card{min-height:0}.dash-hour-grid{overflow-x:auto}.dash-hour-col{min-width:34px}}
        @media print{.kx-topbar,.dash-actions,.kx-theme-toggle,.dash-report-groups,.dash-recent-list{display:none!important}.dash-print-title{display:block;border-bottom:3px solid #0f172a;margin-bottom:12px}.kx-dashboard-page .kx-shell{width:100%;margin:0}.kx-hero,.kx-panel,.kx-stat-card{box-shadow:none!important;background:#fff!important;color:#0f172a!important;border:1px solid #d1d5db}.kx-hero h1,.kx-hero p,.dash-hero-meta strong{color:#0f172a!important}.dash-grid-8,.dash-grid-6,.dash-section-grid,.dash-wide-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}@page{size:A4 landscape;margin:10mm}}
    </style>
</head>
<body class="kx-page kx-dashboard-page">
<?php echo $dashboardConfigOutput; ?>
<?php include 'header.php'; ?>
<main class="kx-shell">
    <div class="dash-print-title"><h1>Kynix Report Center Dashboard</h1><p><?php echo dash_h($periodLabel); ?></p></div>
    <section class="kx-hero">
        <div>
            <div class="kx-eyebrow">BUSINESS REPORT CENTER</div>
            <h1>Kynix Report Center Dashboard</h1>
            <p>Monitor sales, payments, inventory, purchases, and operational performance from one place.</p>
        </div>
        <div class="dash-hero-meta">
            <div><span>Date</span><strong><?php echo dash_h($now->format('Y-m-d')); ?></strong></div>
            <div><span>Time</span><strong id="dashClock"><?php echo dash_h($now->format('H:i:s')); ?></strong></div>
            <div><span>Business</span><strong><?php echo dash_h($BusinessName); ?></strong></div>
            <div><span>Database</span><strong>Connected</strong></div>
            <button type="button" class="kx-theme-toggle" id="kxThemeToggle" title="Toggle dark mode">Dark</button>
        </div>
    </section>

    <section class="dash-grid-8">
        <?php foreach (array(
            array('Today Sales', dash_money($sales['item_sales']), 'Orders item total'),
            array('Net Sales', dash_money($sales['net_sales']), 'Sales + service - discount'),
            array('Ticket Count', number_format($sales['ticket_count']), 'Paid tickets'),
            array('Average Bill', dash_money($sales['avg_bill']), 'Payments / tickets'),
            array('Cash', dash_money($sales['cash']), 'Cash payments'),
            array('Card', dash_money($sales['card']), 'Card payments'),
            array('Service Charge', dash_money($sales['service_charge']), 'CalculationAmount'),
            array('Discount', dash_money($sales['discount']), 'CalculationAmount')
        ) as $card) { ?>
            <div class="kx-stat-card dash-kpi"><span><?php echo dash_h($card[0]); ?></span><strong><?php echo dash_h($card[1]); ?></strong><small><?php echo dash_h($card[2]); ?></small></div>
        <?php } ?>
    </section>

    <section class="dash-grid-6">
        <?php foreach (array(
            array('Total Inventory Items', number_format($inventory['total_items'])),
            array('Items Used Today', number_format($inventory['items_used'])),
            array('Current Stock Value', dash_money($inventory['current_value'])),
            array('Negative Stock Count', number_format($inventory['negative_stock'])),
            array('Low Stock Count', number_format($inventory['low_stock'])),
            array('Today Usage Quantity', dash_num($inventory['usage_qty']))
        ) as $card) { ?>
            <div class="kx-stat-card dash-kpi"><span><?php echo dash_h($card[0]); ?></span><strong><?php echo dash_h($card[1]); ?></strong></div>
        <?php } ?>
    </section>

    <section class="kx-panel dash-panel">
        <div class="dash-panel-head"><div><h2>Quick Actions</h2><p><?php echo dash_h($periodLabel); ?></p></div></div>
        <div class="dash-actions">
            <?php if (auth_has_permission('daily_sales.view')) { ?><a class="kx-btn kx-btn-primary dash-track" data-report-name="Daily Sales" href="./vanzari.php"><?php echo dash_icon('sales'); ?>Open Daily Sales</a><?php } ?>
            <?php if (auth_has_permission('periodic_sales.view')) { ?><a class="kx-btn kx-btn-dark dash-track" data-report-name="Periodic Sales" href="./vanzariPerioada.php">Open Periodic Sales</a><?php } ?>
            <?php if (auth_has_permission('inventory_analytics.view')) { ?><a class="kx-btn kx-btn-dark dash-track" data-report-name="Inventory Analytics" href="./inventoryDaily.php">Open Inventory Analytics</a><?php } ?>
            <?php if (auth_has_permission('purchase_history.view')) { ?><a class="kx-btn kx-btn-dark dash-track" data-report-name="Purchase History" href="./nir.php">Open Purchase History</a><?php } ?>
            <?php if ($canExportPdf || $canExportPrint) { ?><button class="kx-btn kx-btn-danger" type="button" onclick="window.print()"><?php echo dash_icon('pdf'); ?>PDF / Print Summary</button><?php } ?>
            <?php if ($canExportExcel) { ?><a class="kx-btn kx-btn-success" href="./index.php?export=excel"><?php echo dash_icon('excel'); ?>Export Today Summary</a><?php } ?>
        </div>
    </section>

    <section class="dash-wide-grid">
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Attention Required</h2><p>Only verified data-driven alerts are shown.</p></div><?php echo dash_icon('alert'); ?></div>
            <div class="dash-alert-list">
                <?php if (empty($alerts)) { ?><div class="dash-alert-item dash-alert-ok">No urgent issues detected for the current work period.</div><?php } ?>
                <?php foreach ($alerts as $alert) { ?><div class="dash-alert-item"><?php echo dash_icon('alert'); ?><span><?php echo dash_h($alert); ?></span></div><?php } ?>
            </div>
        </div>
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Recently Opened</h2><p>Stored in this browser only.</p></div><?php echo dash_icon('clock'); ?></div>
            <div class="dash-recent-list" id="dashRecentList"><div class="dash-recent-row"><strong>No recent reports yet</strong><span>Open a report to start tracking.</span></div></div>
        </div>
    </section>

    <section class="dash-section-grid">
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Top 5 Selling Items Today</h2><p>Quantity sold in the current work period.</p></div><?php echo dash_icon('sales'); ?></div>
            <?php if (empty($topItems)) { ?><div class="kx-chart-empty">No sales today.</div><?php } ?>
            <?php foreach ($topItems as $row) { $pct = dash_pct_width($row['Qty'], $maxTopItem); ?>
                <div class="dash-bar"><div class="dash-bar-label"><strong><?php echo dash_h($row['ItemName']); ?></strong><span><?php echo dash_num($row['Qty']); ?> / <?php echo dash_money($row['Amount']); ?></span></div><div class="dash-bar-track"><div class="dash-bar-fill" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Payment Breakdown</h2><p>Payment mix by tender type.</p></div><?php echo dash_icon('payments'); ?></div>
            <?php if (empty($paymentRows)) { ?><div class="kx-chart-empty">No payments today.</div><?php } ?>
            <?php foreach ($paymentRows as $row) { $pct = dash_pct_width($row['TotalAmount'], $maxPayment); ?>
                <div class="dash-bar"><div class="dash-bar-label"><strong><?php echo dash_h($row['PaymentName']); ?></strong><span><?php echo dash_money($row['TotalAmount']); ?></span></div><div class="dash-bar-track"><div class="dash-bar-fill green" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Hourly Sales Trend</h2><p>Payment totals by hour.</p></div><?php echo dash_icon('clock'); ?></div>
            <?php if (empty($hourRows)) { ?><div class="kx-chart-empty">No hourly payment data today.</div><?php } else { ?>
            <div class="dash-hour-grid"><?php foreach ($hourRows as $row) { $height = dash_pct_width($row['TotalAmount'], $maxHour); ?><div class="dash-hour-col"><div class="dash-hour-bar" style="height:<?php echo max(18, $height); ?>px"></div><?php echo (int)$row['SalesHour']; ?></div><?php } ?></div>
            <?php } ?>
        </div>
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Sales by Group</h2><p>Top menu groups by amount.</p></div><?php echo dash_icon('analytics'); ?></div>
            <?php if (empty($groupRows)) { ?><div class="kx-chart-empty">No group sales today.</div><?php } ?>
            <?php foreach ($groupRows as $row) { $pct = dash_pct_width($row['Amount'], $maxGroup); ?>
                <div class="dash-bar"><div class="dash-bar-label"><strong><?php echo dash_h($row['GroupName']); ?></strong><span><?php echo dash_money($row['Amount']); ?></span></div><div class="dash-bar-track"><div class="dash-bar-fill dark" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
    </section>

    <section class="dash-section-grid">
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Top Used Inventory Items</h2><p>Recipe usage for today.</p></div><?php echo dash_icon('inventory'); ?></div>
            <?php if (empty($topUsageRows)) { ?><div class="kx-chart-empty">No inventory usage today.</div><?php } ?>
            <?php foreach ($topUsageRows as $row) { $pct = dash_pct_width($row['UsageQty'], $maxUsage); ?>
                <div class="dash-bar"><div class="dash-bar-label"><strong><?php echo dash_h($row['ItemName']); ?></strong><span><?php echo dash_num($row['UsageQty']); ?></span></div><div class="dash-bar-track"><div class="dash-bar-fill red" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Stock Health Breakdown</h2><p>Current stock status.</p></div><?php echo dash_icon('alert'); ?></div>
            <?php foreach (array('Negative' => $inventory['negative_stock'], 'Low' => $inventory['low_stock'], 'Zero' => $inventory['zero_stock'], 'No Movement' => $inventory['no_movement']) as $label => $value) { $pct = dash_pct_width($value, max(1, $inventory['total_items'])); ?>
                <div class="dash-bar"><div class="dash-bar-label"><strong><?php echo dash_h($label); ?></strong><span><?php echo number_format($value); ?></span></div><div class="dash-bar-track"><div class="dash-bar-fill <?php echo $label === 'Negative' ? 'red' : ''; ?>" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Current Stock Value by Group</h2><p>Weighted purchase cost where available.</p></div><?php echo dash_icon('analytics'); ?></div>
            <?php if (empty($valueGroupRows)) { ?><div class="kx-chart-empty">No stock value available.</div><?php } ?>
            <?php foreach ($valueGroupRows as $row) { $pct = dash_pct_width($row['StockValue'], $maxValueGroup); ?>
                <div class="dash-bar"><div class="dash-bar-label"><strong><?php echo dash_h($row['GroupName']); ?></strong><span><?php echo dash_money($row['StockValue']); ?></span></div><div class="dash-bar-track"><div class="dash-bar-fill dark" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="kx-panel dash-panel">
            <div class="dash-panel-head"><div><h2>Items With No Movement</h2><p>Sample of inventory items with no activity today.</p></div><?php echo dash_icon('reports'); ?></div>
            <?php if (empty($noMovementRows)) { ?><div class="kx-chart-empty">All inventory items had movement or usage today.</div><?php } ?>
            <?php foreach ($noMovementRows as $row) { ?><div class="dash-recent-row"><strong><?php echo dash_h($row['ItemName']); ?></strong><span>No movement in current work period</span></div><?php } ?>
        </div>
    </section>

    <section class="dash-report-groups">
        <?php foreach ($reportGroups as $groupName => $reports) { ?>
            <div class="kx-panel dash-panel">
                <div class="dash-panel-head"><div><h2><?php echo dash_h($groupName); ?></h2><p>Available and planned report modules.</p></div><?php echo dash_icon('reports'); ?></div>
                <div class="dash-report-list">
                    <?php foreach ($reports as $report) { $statusClass = strtolower(str_replace(' ', '-', $report[2])); $canOpenReport = $report[3] !== '' && auth_has_permission($report[5]); ?>
                        <article class="dash-report-card">
                            <div class="dash-report-top"><div><?php echo dash_icon($report[4]); ?></div><span class="dash-badge dash-badge-<?php echo dash_h($statusClass === 'in-progress' ? 'progress' : $statusClass); ?>"><?php echo dash_h($report[2]); ?></span></div>
                            <h3><?php echo dash_h($report[0]); ?></h3>
                            <p><?php echo dash_h($report[1]); ?></p>
                            <?php if ($canOpenReport) { ?><a class="dash-report-action dash-track" data-report-name="<?php echo dash_h($report[0]); ?>" href="./<?php echo dash_h($report[3]); ?>">Open Report</a><?php } elseif ($report[3] !== '') { ?><span class="dash-report-action dash-report-disabled">Not permitted</span><?php } else { ?><span class="dash-report-action dash-report-disabled">Coming Soon</span><?php } ?>
                        </article>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    </section>
</main>
<footer class="kx-footer">Powered by Kynix Technologies</footer>
<script>
(function(){
    var generatedClock=document.getElementById('dashClock');
    if(generatedClock){ setInterval(function(){ generatedClock.innerHTML=new Date().toLocaleTimeString(); },1000); }
    var menuBtn=document.getElementById('kxMenuBtn'), nav=document.getElementById('kxNav');
    if(menuBtn&&nav){ menuBtn.onclick=function(){ nav.classList.toggle('open'); }; }
    var themeBtn=document.getElementById('kxThemeToggle');
    var savedTheme=localStorage.getItem('kxTheme');
    if(savedTheme==='dark'){ document.body.classList.add('kx-dark-mode'); }
    function setThemeText(){ if(themeBtn){ themeBtn.innerHTML=document.body.classList.contains('kx-dark-mode')?'Light':'Dark'; } }
    setThemeText();
    if(themeBtn){ themeBtn.onclick=function(){ document.body.classList.toggle('kx-dark-mode'); localStorage.setItem('kxTheme',document.body.classList.contains('kx-dark-mode')?'dark':'light'); setThemeText(); }; }
    var recentKey='kxRecentReports';
    function readRecent(){ try{return JSON.parse(localStorage.getItem(recentKey)||'[]');}catch(e){return [];} }
    function writeRecent(items){ localStorage.setItem(recentKey,JSON.stringify(items.slice(0,5))); }
    document.querySelectorAll('.dash-track').forEach(function(link){
        link.addEventListener('click',function(){
            var items=readRecent();
            var href=link.getAttribute('href');
            var name=link.getAttribute('data-report-name')||link.innerText;
            items=items.filter(function(item){return item.href!==href;});
            items.unshift({name:name,href:href,time:new Date().toLocaleString()});
            writeRecent(items);
        });
    });
    var list=document.getElementById('dashRecentList');
    if(list){
        var items=readRecent();
        if(items.length){
            list.innerHTML='';
            items.forEach(function(item){
                var row=document.createElement('div');
                row.className='dash-recent-row';
                row.innerHTML='<div><strong></strong><span></span></div><a class="dash-report-action" href=""></a>';
                row.querySelector('strong').innerText=item.name;
                row.querySelector('span').innerText=item.time;
                row.querySelector('a').setAttribute('href',item.href);
                row.querySelector('a').innerText='Open again';
                list.appendChild(row);
            });
        }
    }
})();
</script>
</body>
</html>
