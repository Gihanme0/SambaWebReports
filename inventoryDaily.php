<?php
require 'config.php';
$reportName = "Inventory Analytics " . $BusinessName;

function inv_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function inv_num($value, $decimals = 3) { return number_format((float)$value, $decimals); }
function inv_money($value) { return 'Rs. ' . number_format((float)$value, 2); }
function inv_bool($name, $default = false) { return isset($_GET[$name]) ? ($_GET[$name] === '1') : $default; }
function inv_value($row, $key, $default = 0) { return isset($row[$key]) ? $row[$key] : $default; }
function inv_date_value($value) { return $value instanceof DateTime ? $value->format('Y-m-d H:i:s') : (string)$value; }
function inv_icon($name) { return '<span class="inv-icon inv-icon-' . inv_h($name) . '" aria-hidden="true"></span>'; }

$today = date('Y-m-d');
$fromInput = isset($_GET['fromDate']) && $_GET['fromDate'] !== '' ? trim($_GET['fromDate']) : $today;
$toInput = isset($_GET['toDate']) && $_GET['toDate'] !== '' ? trim($_GET['toDate']) : $today;
$fromTs = strtotime($fromInput);
$toTs = strtotime($toInput);
if (!$fromTs) { $fromTs = strtotime($today); $fromInput = $today; }
if (!$toTs) { $toTs = $fromTs; $toInput = date('Y-m-d', $toTs); }
if ($toTs < $fromTs) { $toTs = $fromTs; $toInput = date('Y-m-d', $toTs); }

$startDate = date('Y-m-d', $fromTs) . "T06:00:00.000";
$endDate = date('Y-m-d', strtotime('+1 day', $toTs)) . "T06:00:00.000";
$warehouseId = isset($_GET['warehouseId']) && $_GET['warehouseId'] !== '' ? (int)$_GET['warehouseId'] : null;
$itemId = isset($_GET['itemId']) && $_GET['itemId'] !== '' ? (int)$_GET['itemId'] : null;
$groupCode = isset($_GET['groupCode']) && $_GET['groupCode'] !== '' ? trim($_GET['groupCode']) : null;
$movementType = isset($_GET['movementType']) && $_GET['movementType'] !== '' ? trim($_GET['movementType']) : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$showZeroBalance = inv_bool('showZeroBalance', true);
$showZeroMovement = inv_bool('showZeroMovement', true);
$showNegativeOnly = inv_bool('showNegativeOnly', false);
$showLowStock = inv_bool('showLowStock', false);
$lowStockThreshold = isset($_GET['lowStockThreshold']) && $_GET['lowStockThreshold'] !== '' ? (float)$_GET['lowStockThreshold'] : 1;

$errors = array();
$rows = array();
$ledgerRows = array();
$soldRows = array();
$warehouses = array();
$items = array();
$groups = array();
$summary = array(
    'total_items' => 0,
    'items_used' => 0,
    'no_movement' => 0,
    'opening_value' => 0,
    'closing_value' => 0,
    'current_value' => 0,
    'usage_qty' => 0,
    'usage_cost' => 0,
    'negative_stock' => 0,
    'low_stock' => 0,
    'zero_balance' => 0,
    'purchases' => 0,
    'waste' => 0,
    'adjustments' => 0,
    'production' => 0
);
$tableTotals = array(
    'opening' => 0,
    'purchase' => 0,
    'usage' => 0,
    'waste' => 0,
    'adjustment' => 0,
    'closing' => 0,
    'current' => 0,
    'variance' => 0,
    'current_value' => 0
);

function inv_fetch_all($conn, $sql, $params = array()) {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        return array('error' => print_r(sqlsrv_errors(), true), 'rows' => array());
    }
    $rows = array();
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return array('error' => null, 'rows' => $rows);
}

$profile = inv_fetch_all($conn, "
    SELECT
        (SELECT COUNT(*) FROM InventoryItems) AS InventoryItems,
        (SELECT COUNT(*) FROM InventoryTransactions) AS InventoryTransactions,
        (SELECT COUNT(*) FROM Recipes) AS Recipes,
        (SELECT COUNT(*) FROM RecipeItems) AS RecipeItems,
        (SELECT COUNT(*) FROM PeriodicConsumptionItems) AS PeriodicConsumptionItems
");
if ($profile['error']) {
    $errors[] = $profile['error'];
}
$profileRow = !empty($profile['rows']) ? $profile['rows'][0] : array();

$lookups = inv_fetch_all($conn, "SELECT Id, Name FROM Warehouses ORDER BY Name");
if (!$lookups['error']) { $warehouses = $lookups['rows']; }
$lookups = inv_fetch_all($conn, "SELECT Id, Name FROM InventoryItems ORDER BY Name");
if (!$lookups['error']) { $items = $lookups['rows']; }
$lookups = inv_fetch_all($conn, "SELECT DISTINCT ISNULL(NULLIF(GroupCode,''),'Ungrouped') AS GroupCode FROM InventoryItems ORDER BY GroupCode");
if (!$lookups['error']) { $groups = $lookups['rows']; }

$reportSql = "
DECLARE @StartDate datetime = ?;
DECLARE @EndDate datetime = ?;
DECLARE @WarehouseId int = ?;
DECLARE @ItemId int = ?;
DECLARE @GroupCode nvarchar(100) = ?;
DECLARE @Search nvarchar(120) = ?;
DECLARE @MovementType nvarchar(60) = ?;
DECLARE @ShowZeroBalance bit = ?;
DECLARE @ShowZeroMovement bit = ?;
DECLARE @ShowNegativeOnly bit = ?;
DECLARE @ShowLowStock bit = ?;
DECLARE @LowStockThreshold decimal(18,6) = ?;

WITH Items AS (
    SELECT
        ii.Id AS ItemCode,
        ii.Name AS ItemName,
        ISNULL(NULLIF(ii.GroupCode,''),'Ungrouped') AS InventoryGroup,
        ii.BaseUnit,
        ii.DefaultBaseUnitCost
    FROM InventoryItems ii
    WHERE (@ItemId IS NULL OR ii.Id = @ItemId)
      AND (@GroupCode IS NULL OR ISNULL(NULLIF(ii.GroupCode,''),'Ungrouped') = @GroupCode)
      AND (@Search IS NULL OR @Search = '' OR CAST(ii.Id AS nvarchar(20)) LIKE '%' + @Search + '%' OR ii.Name LIKE '%' + @Search + '%' OR ISNULL(ii.GroupCode,'') LIKE '%' + @Search + '%')
),
TxExpanded AS (
    SELECT
        it.InventoryItem_Id AS ItemCode,
        it.Date,
        it.TargetWarehouseId AS WarehouseId,
        CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6)) AS Qty,
        it.TotalPrice,
        itt.Name AS TransactionTypeName,
        itd.Name AS DocumentName,
        itd.Description,
        'IN' AS Direction,
        it.SourceWarehouseId,
        it.TargetWarehouseId,
        'InventoryTransactions' AS SourceTable,
        CAST(it.Id AS nvarchar(30)) AS SourceId
    FROM InventoryTransactions it
    LEFT JOIN InventoryTransactionTypes itt ON itt.Id = it.InventoryTransactionTypeId
    LEFT JOIN InventoryTransactionDocuments itd ON itd.Id = it.InventoryTransactionDocumentId
    WHERE it.TargetWarehouseId <> 0

    UNION ALL

    SELECT
        it.InventoryItem_Id AS ItemCode,
        it.Date,
        it.SourceWarehouseId AS WarehouseId,
        -CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6)) AS Qty,
        it.TotalPrice,
        itt.Name AS TransactionTypeName,
        itd.Name AS DocumentName,
        itd.Description,
        'OUT' AS Direction,
        it.SourceWarehouseId,
        it.TargetWarehouseId,
        'InventoryTransactions' AS SourceTable,
        CAST(it.Id AS nvarchar(30)) AS SourceId
    FROM InventoryTransactions it
    LEFT JOIN InventoryTransactionTypes itt ON itt.Id = it.InventoryTransactionTypeId
    LEFT JOIN InventoryTransactionDocuments itd ON itd.Id = it.InventoryTransactionDocumentId
    WHERE it.SourceWarehouseId <> 0
),
TxClassified AS (
    SELECT
        tx.*,
        CASE
            WHEN Direction = 'IN' AND SourceWarehouseId = 0 AND TransactionTypeName LIKE '%Purchase%' THEN 'Purchase'
            WHEN Direction = 'OUT' AND TargetWarehouseId = 0 AND TransactionTypeName LIKE '%Purchase%' THEN 'Purchase Return'
            WHEN Direction = 'IN' AND SourceWarehouseId <> 0 AND TargetWarehouseId <> 0 THEN 'Transfer In'
            WHEN Direction = 'OUT' AND SourceWarehouseId <> 0 AND TargetWarehouseId <> 0 THEN 'Transfer Out'
            WHEN Direction = 'IN' AND (TransactionTypeName LIKE '%Production%' OR DocumentName LIKE '%Production%') THEN 'Production'
            WHEN Direction = 'OUT' AND (TransactionTypeName LIKE '%Waste%' OR DocumentName LIKE '%Waste%') THEN 'Waste'
            WHEN Direction = 'IN' AND (TransactionTypeName LIKE '%Adjust%' OR DocumentName LIKE '%Adjust%') THEN 'Adjustment +'
            WHEN Direction = 'OUT' AND (TransactionTypeName LIKE '%Adjust%' OR DocumentName LIKE '%Adjust%') THEN 'Adjustment -'
            ELSE 'Inventory Movement'
        END AS MovementClass
    FROM TxExpanded tx
    WHERE (@WarehouseId IS NULL OR tx.WarehouseId = @WarehouseId)
),
RecipeUsage AS (
    SELECT
        ri.InventoryItem_Id AS ItemCode,
        o.CreatedDateTime AS Date,
        o.WarehouseId,
        CAST(o.Quantity * ri.Quantity AS decimal(18,6)) AS UsedQty,
        o.MenuItemName,
        o.PortionName,
        CAST(o.Id AS nvarchar(30)) AS SourceId
    FROM Orders o
    JOIN MenuItemPortions mip ON mip.MenuItemId = o.MenuItemId AND mip.Name = o.PortionName
    JOIN Recipes r ON r.Portion_Id = mip.Id
    JOIN RecipeItems ri ON ri.RecipeId = r.Id
    WHERE o.DecreaseInventory = 1
      AND o.CalculatePrice <> 0
      AND (@WarehouseId IS NULL OR o.WarehouseId = @WarehouseId)
),
UnifiedLedger AS (
    SELECT
        ItemCode,
        Date,
        WarehouseId,
        MovementClass,
        CASE WHEN Qty > 0 THEN Qty ELSE 0 END AS QtyIn,
        CASE WHEN Qty < 0 THEN ABS(Qty) ELSE 0 END AS QtyOut,
        Qty AS NetQty,
        TransactionTypeName AS TransactionType,
        ISNULL(DocumentName, '') AS Reference,
        ISNULL(Description, '') AS DocumentText,
        SourceTable,
        SourceId
    FROM TxClassified

    UNION ALL

    SELECT
        ItemCode,
        Date,
        WarehouseId,
        'Recipe Consumption' AS MovementClass,
        CAST(0 AS decimal(18,6)) AS QtyIn,
        UsedQty AS QtyOut,
        -UsedQty AS NetQty,
        'Sales Recipe Usage' AS TransactionType,
        MenuItemName + ' / ' + PortionName AS Reference,
        '' AS DocumentText,
        'Orders + Recipes' AS SourceTable,
        SourceId
    FROM RecipeUsage
),
Opening AS (
    SELECT ItemCode, SUM(NetQty) AS OpeningQty
    FROM UnifiedLedger
    WHERE Date < @StartDate
    GROUP BY ItemCode
),
PeriodAgg AS (
    SELECT
        ItemCode,
        SUM(CASE WHEN MovementClass = 'Purchase' THEN QtyIn ELSE 0 END) AS PurchaseQty,
        SUM(CASE WHEN MovementClass = 'Production' THEN QtyIn ELSE 0 END) AS ProductionQty,
        SUM(CASE WHEN MovementClass = 'Transfer In' THEN QtyIn ELSE 0 END) AS TransferInQty,
        SUM(CASE WHEN MovementClass = 'Recipe Consumption' THEN QtyOut ELSE 0 END) AS RecipeUsageQty,
        SUM(CASE WHEN MovementClass = 'Inventory Movement' THEN QtyOut ELSE 0 END) AS DirectUsageQty,
        SUM(CASE WHEN MovementClass = 'Waste' THEN QtyOut ELSE 0 END) AS WasteQty,
        SUM(CASE WHEN MovementClass = 'Adjustment +' THEN QtyIn ELSE 0 END) AS AdjustmentPlusQty,
        SUM(CASE WHEN MovementClass = 'Adjustment -' THEN QtyOut ELSE 0 END) AS AdjustmentMinusQty,
        SUM(CASE WHEN MovementClass = 'Transfer Out' THEN QtyOut ELSE 0 END) AS TransferOutQty,
        SUM(QtyIn) AS StockInQty,
        SUM(QtyOut) AS StockOutQty,
        SUM(NetQty) AS PeriodNetQty,
        COUNT(*) AS MovementCount
    FROM UnifiedLedger
    WHERE Date >= @StartDate AND Date < @EndDate
      AND (@MovementType IS NULL OR MovementClass = @MovementType)
    GROUP BY ItemCode
),
CurrentAgg AS (
    SELECT ItemCode, SUM(NetQty) AS CurrentQty
    FROM UnifiedLedger
    WHERE Date < SYSDATETIME()
    GROUP BY ItemCode
),
Cost AS (
    SELECT
        i.ItemCode,
        COALESCE(
            (SELECT TOP 1 it.TotalPrice / NULLIF(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END, 0)
             FROM InventoryTransactions it
             WHERE it.InventoryItem_Id = i.ItemCode
               AND it.TotalPrice <> 0
             ORDER BY it.Date DESC, it.Id DESC),
            i.DefaultBaseUnitCost,
            0
        ) AS UnitCost
    FROM Items i
)
SELECT
    i.ItemCode,
    i.ItemName,
    i.InventoryGroup,
    COALESCE(w.Name, 'All Warehouses') AS Warehouse,
    i.BaseUnit,
    CAST(ISNULL(o.OpeningQty, 0) AS decimal(18,3)) AS OpeningQty,
    CAST(ISNULL(pa.PurchaseQty, 0) AS decimal(18,3)) AS PurchaseQty,
    CAST(ISNULL(pa.ProductionQty, 0) AS decimal(18,3)) AS ProductionQty,
    CAST(ISNULL(pa.TransferInQty, 0) AS decimal(18,3)) AS TransferInQty,
    CAST(ISNULL(pa.RecipeUsageQty, 0) AS decimal(18,3)) AS RecipeUsageQty,
    CAST(ISNULL(pa.DirectUsageQty, 0) AS decimal(18,3)) AS DirectUsageQty,
    CAST(ISNULL(pa.WasteQty, 0) AS decimal(18,3)) AS WasteQty,
    CAST(ISNULL(pa.AdjustmentPlusQty, 0) AS decimal(18,3)) AS AdjustmentPlusQty,
    CAST(ISNULL(pa.AdjustmentMinusQty, 0) AS decimal(18,3)) AS AdjustmentMinusQty,
    CAST(ISNULL(pa.TransferOutQty, 0) AS decimal(18,3)) AS TransferOutQty,
    CAST(ISNULL(o.OpeningQty, 0) + ISNULL(pa.PeriodNetQty, 0) AS decimal(18,3)) AS ClosingBalance,
    CAST(ISNULL(ca.CurrentQty, 0) AS decimal(18,3)) AS CurrentBalance,
    CAST(cost.UnitCost AS decimal(18,2)) AS UnitCost,
    CAST((ISNULL(pa.RecipeUsageQty, 0) + ISNULL(pa.DirectUsageQty, 0)) * cost.UnitCost AS decimal(18,2)) AS UsageCost,
    CAST(ISNULL(ca.CurrentQty, 0) * cost.UnitCost AS decimal(18,2)) AS CurrentStockValue,
    CAST(ISNULL(ca.CurrentQty, 0) - (ISNULL(o.OpeningQty, 0) + ISNULL(pa.PeriodNetQty, 0)) AS decimal(18,3)) AS Variance,
    ISNULL(pa.MovementCount, 0) AS MovementCount,
    CAST(ISNULL(pa.StockInQty, 0) AS decimal(18,3)) AS StockInQty,
    CAST(ISNULL(pa.StockOutQty, 0) AS decimal(18,3)) AS StockOutQty
FROM Items i
LEFT JOIN Opening o ON o.ItemCode = i.ItemCode
LEFT JOIN PeriodAgg pa ON pa.ItemCode = i.ItemCode
LEFT JOIN CurrentAgg ca ON ca.ItemCode = i.ItemCode
LEFT JOIN Cost cost ON cost.ItemCode = i.ItemCode
LEFT JOIN Warehouses w ON w.Id = @WarehouseId
WHERE (@ShowZeroMovement = 1 OR ISNULL(pa.MovementCount, 0) > 0)
  AND (@ShowZeroBalance = 1 OR ISNULL(ca.CurrentQty, 0) <> 0)
  AND (@ShowNegativeOnly = 0 OR ISNULL(ca.CurrentQty, 0) < 0)
  AND (@ShowLowStock = 0 OR (ISNULL(ca.CurrentQty, 0) > 0 AND ISNULL(ca.CurrentQty, 0) <= @LowStockThreshold))
ORDER BY i.ItemName;
";

$params = array(
    $startDate,
    $endDate,
    $warehouseId,
    $itemId,
    $groupCode,
    $search,
    $movementType,
    $showZeroBalance ? 1 : 0,
    $showZeroMovement ? 1 : 0,
    $showNegativeOnly ? 1 : 0,
    $showLowStock ? 1 : 0,
    $lowStockThreshold
);

$report = inv_fetch_all($conn, $reportSql, $params);
if ($report['error']) {
    $errors[] = $report['error'];
} else {
    $rows = $report['rows'];
}

$ledgerSql = "
DECLARE @StartDate datetime = ?;
DECLARE @EndDate datetime = ?;
DECLARE @WarehouseId int = ?;
DECLARE @ItemId int = ?;
DECLARE @GroupCode nvarchar(100) = ?;
DECLARE @Search nvarchar(120) = ?;

WITH Items AS (
    SELECT
        ii.Id AS ItemCode,
        ii.Name AS ItemName
    FROM InventoryItems ii
    WHERE (@ItemId IS NULL OR ii.Id = @ItemId)
      AND (@GroupCode IS NULL OR ISNULL(NULLIF(ii.GroupCode,''),'Ungrouped') = @GroupCode)
      AND (@Search IS NULL OR @Search = '' OR CAST(ii.Id AS nvarchar(20)) LIKE '%' + @Search + '%' OR ii.Name LIKE '%' + @Search + '%' OR ISNULL(ii.GroupCode,'') LIKE '%' + @Search + '%')
),
TxExpanded AS (
    SELECT it.InventoryItem_Id AS ItemCode, it.Date, it.TargetWarehouseId AS WarehouseId,
           CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6)) AS Qty,
           itt.Name AS TransactionTypeName, itd.Name AS DocumentName, itd.Description,
           'IN' AS Direction, it.SourceWarehouseId, it.TargetWarehouseId,
           'InventoryTransactions' AS SourceTable, CAST(it.Id AS nvarchar(30)) AS SourceId
    FROM InventoryTransactions it
    LEFT JOIN InventoryTransactionTypes itt ON itt.Id = it.InventoryTransactionTypeId
    LEFT JOIN InventoryTransactionDocuments itd ON itd.Id = it.InventoryTransactionDocumentId
    WHERE it.TargetWarehouseId <> 0
    UNION ALL
    SELECT it.InventoryItem_Id AS ItemCode, it.Date, it.SourceWarehouseId AS WarehouseId,
           -CAST(CASE WHEN it.Multiplier = 0 THEN it.Quantity ELSE it.Quantity * it.Multiplier END AS decimal(18,6)) AS Qty,
           itt.Name AS TransactionTypeName, itd.Name AS DocumentName, itd.Description,
           'OUT' AS Direction, it.SourceWarehouseId, it.TargetWarehouseId,
           'InventoryTransactions' AS SourceTable, CAST(it.Id AS nvarchar(30)) AS SourceId
    FROM InventoryTransactions it
    LEFT JOIN InventoryTransactionTypes itt ON itt.Id = it.InventoryTransactionTypeId
    LEFT JOIN InventoryTransactionDocuments itd ON itd.Id = it.InventoryTransactionDocumentId
    WHERE it.SourceWarehouseId <> 0
),
TxClassified AS (
    SELECT tx.*,
        CASE
            WHEN Direction = 'IN' AND SourceWarehouseId = 0 AND TransactionTypeName LIKE '%Purchase%' THEN 'Purchase'
            WHEN Direction = 'OUT' AND TargetWarehouseId = 0 AND TransactionTypeName LIKE '%Purchase%' THEN 'Purchase Return'
            WHEN Direction = 'IN' AND SourceWarehouseId <> 0 AND TargetWarehouseId <> 0 THEN 'Transfer In'
            WHEN Direction = 'OUT' AND SourceWarehouseId <> 0 AND TargetWarehouseId <> 0 THEN 'Transfer Out'
            WHEN Direction = 'IN' AND (TransactionTypeName LIKE '%Production%' OR DocumentName LIKE '%Production%') THEN 'Production'
            WHEN Direction = 'OUT' AND (TransactionTypeName LIKE '%Waste%' OR DocumentName LIKE '%Waste%') THEN 'Waste'
            WHEN Direction = 'IN' AND (TransactionTypeName LIKE '%Adjust%' OR DocumentName LIKE '%Adjust%') THEN 'Adjustment +'
            WHEN Direction = 'OUT' AND (TransactionTypeName LIKE '%Adjust%' OR DocumentName LIKE '%Adjust%') THEN 'Adjustment -'
            ELSE 'Inventory Movement'
        END AS MovementClass
    FROM TxExpanded tx
    WHERE (@WarehouseId IS NULL OR tx.WarehouseId = @WarehouseId)
),
RecipeUsage AS (
    SELECT ri.InventoryItem_Id AS ItemCode, o.CreatedDateTime AS Date, o.WarehouseId,
           CAST(o.Quantity * ri.Quantity AS decimal(18,6)) AS UsedQty,
           o.MenuItemName, o.PortionName, CAST(o.Id AS nvarchar(30)) AS SourceId
    FROM Orders o
    JOIN MenuItemPortions mip ON mip.MenuItemId = o.MenuItemId AND mip.Name = o.PortionName
    JOIN Recipes r ON r.Portion_Id = mip.Id
    JOIN RecipeItems ri ON ri.RecipeId = r.Id
    WHERE o.DecreaseInventory = 1 AND o.CalculatePrice <> 0
      AND (@WarehouseId IS NULL OR o.WarehouseId = @WarehouseId)
),
UnifiedLedger AS (
    SELECT ItemCode, Date, WarehouseId, MovementClass,
           CASE WHEN Qty > 0 THEN Qty ELSE 0 END AS QtyIn,
           CASE WHEN Qty < 0 THEN ABS(Qty) ELSE 0 END AS QtyOut,
           Qty AS NetQty, TransactionTypeName AS TransactionType, ISNULL(DocumentName,'') AS Reference,
           ISNULL(Description,'') AS DocumentText, SourceTable, SourceId
    FROM TxClassified
    UNION ALL
    SELECT ItemCode, Date, WarehouseId, 'Recipe Consumption', 0, UsedQty, -UsedQty,
           'Sales Recipe Usage', MenuItemName + ' / ' + PortionName, '', 'Orders + Recipes', SourceId
    FROM RecipeUsage
)
SELECT
    ul.ItemCode, i.ItemName, ul.Date, ul.MovementClass, ISNULL(w.Name,'') AS Warehouse,
    ul.QtyIn, ul.QtyOut, ul.NetQty, ul.TransactionType, ul.Reference, ul.DocumentText, ul.SourceTable, ul.SourceId
FROM UnifiedLedger ul
JOIN Items i ON i.ItemCode = ul.ItemCode
LEFT JOIN Warehouses w ON w.Id = ul.WarehouseId
WHERE ul.Date >= @StartDate AND ul.Date < @EndDate
ORDER BY ul.ItemCode, ul.Date, ul.SourceTable, ul.SourceId;
";

$ledger = inv_fetch_all($conn, $ledgerSql, array($startDate, $endDate, $warehouseId, $itemId, $groupCode, $search));
if ($ledger['error']) {
    $errors[] = $ledger['error'];
} else {
    $ledgerRows = $ledger['rows'];
}

$soldSql = "
DECLARE @StartDate datetime = ?;
DECLARE @EndDate datetime = ?;
DECLARE @WarehouseId int = ?;
DECLARE @ItemId int = ?;
DECLARE @GroupCode nvarchar(100) = ?;
DECLARE @Search nvarchar(120) = ?;
DECLARE @MovementType nvarchar(60) = ?;

SELECT
    o.MenuItemName,
    o.PortionName,
    SUM(CAST(o.Quantity AS decimal(18,3))) AS SoldQty,
    'Recipe #' + CAST(r.Id AS nvarchar(30)) AS RecipeName,
    ii.Id AS ItemCode,
    ii.Name AS IngredientName,
    ii.BaseUnit AS IngredientUnit,
    CAST(ri.Quantity AS decimal(18,6)) AS UsagePerItem,
    CAST(SUM(o.Quantity * ri.Quantity) AS decimal(18,6)) AS TotalIngredientUsage
FROM Orders o
JOIN MenuItemPortions mip ON mip.MenuItemId = o.MenuItemId AND mip.Name = o.PortionName
JOIN Recipes r ON r.Portion_Id = mip.Id
JOIN RecipeItems ri ON ri.RecipeId = r.Id
JOIN InventoryItems ii ON ii.Id = ri.InventoryItem_Id
WHERE o.CreatedDateTime >= @StartDate
  AND o.CreatedDateTime < @EndDate
  AND o.DecreaseInventory = 1
  AND o.CalculatePrice <> 0
  AND (@WarehouseId IS NULL OR o.WarehouseId = @WarehouseId)
  AND (@ItemId IS NULL OR ii.Id = @ItemId)
  AND (@GroupCode IS NULL OR ISNULL(NULLIF(ii.GroupCode,''),'Ungrouped') = @GroupCode)
  AND (@Search IS NULL OR @Search = '' OR CAST(ii.Id AS nvarchar(20)) LIKE '%' + @Search + '%' OR ii.Name LIKE '%' + @Search + '%' OR o.MenuItemName LIKE '%' + @Search + '%' OR ISNULL(ii.GroupCode,'') LIKE '%' + @Search + '%')
  AND (@MovementType IS NULL OR @MovementType = 'Recipe Consumption')
GROUP BY o.MenuItemName, o.PortionName, r.Id, ii.Id, ii.Name, ii.BaseUnit, ri.Quantity
ORDER BY o.MenuItemName, o.PortionName, ii.Name;
";
$sold = inv_fetch_all($conn, $soldSql, array($startDate, $endDate, $warehouseId, $itemId, $groupCode, $search, $movementType));
if ($sold['error']) {
    $errors[] = $sold['error'];
} else {
    $soldRows = $sold['rows'];
}

$openingMap = array();
foreach ($rows as $row) {
    $itemKey = (string)$row['ItemCode'];
    $openingMap[$itemKey] = (float)$row['OpeningQty'];
    $summary['total_items']++;
    $usageQty = (float)$row['RecipeUsageQty'] + (float)$row['DirectUsageQty'];
    $adjustmentQty = (float)$row['AdjustmentPlusQty'] - (float)$row['AdjustmentMinusQty'];
    if ($usageQty > 0) { $summary['items_used']++; }
    if ((int)$row['MovementCount'] === 0) { $summary['no_movement']++; }
    if ((float)$row['CurrentBalance'] < 0) { $summary['negative_stock']++; }
    if ((float)$row['CurrentBalance'] == 0) { $summary['zero_balance']++; }
    if ((float)$row['CurrentBalance'] > 0 && (float)$row['CurrentBalance'] <= $lowStockThreshold) { $summary['low_stock']++; }
    $summary['opening_value'] += (float)$row['OpeningQty'] * (float)$row['UnitCost'];
    $summary['closing_value'] += (float)$row['ClosingBalance'] * (float)$row['UnitCost'];
    $summary['current_value'] += (float)$row['CurrentStockValue'];
    $summary['usage_qty'] += $usageQty;
    $summary['usage_cost'] += (float)$row['UsageCost'];
    $summary['purchases'] += (float)$row['PurchaseQty'];
    $summary['waste'] += (float)$row['WasteQty'];
    $summary['adjustments'] += (float)$row['AdjustmentPlusQty'] + (float)$row['AdjustmentMinusQty'];
    $summary['production'] += (float)$row['ProductionQty'];
    $tableTotals['opening'] += (float)$row['OpeningQty'];
    $tableTotals['purchase'] += (float)$row['PurchaseQty'];
    $tableTotals['usage'] += $usageQty;
    $tableTotals['waste'] += (float)$row['WasteQty'];
    $tableTotals['adjustment'] += $adjustmentQty;
    $tableTotals['closing'] += (float)$row['ClosingBalance'];
    $tableTotals['current'] += (float)$row['CurrentBalance'];
    $tableTotals['variance'] += (float)$row['Variance'];
    $tableTotals['current_value'] += (float)$row['CurrentStockValue'];
}

$ledgerByItem = array();
$running = $openingMap;
foreach ($ledgerRows as $movement) {
    $itemKey = (string)$movement['ItemCode'];
    if (!isset($running[$itemKey])) { $running[$itemKey] = 0; }
    $running[$itemKey] += (float)$movement['NetQty'];
    $movement['RunningBalance'] = $running[$itemKey];
    if (!isset($ledgerByItem[$itemKey])) { $ledgerByItem[$itemKey] = array(); }
    $ledgerByItem[$itemKey][] = $movement;
}

$chartLabels = array();
$chartUsage = array();
$chartValue = array();
$chartIn = 0;
$chartOut = 0;
$chartNegative = 0;
$chartZero = 0;
$valueByGroup = array();
$soldByMenu = array();
$topSoldMenu = array();
$topUsage = $rows;
usort($topUsage, function($a, $b) {
    $au = (float)$a['RecipeUsageQty'] + (float)$a['DirectUsageQty'];
    $bu = (float)$b['RecipeUsageQty'] + (float)$b['DirectUsageQty'];
    if ($au == $bu) { return 0; }
    return ($au < $bu) ? 1 : -1;
});
foreach (array_slice($topUsage, 0, 10) as $row) {
    $chartLabels[] = $row['ItemName'];
    $chartUsage[] = (float)$row['RecipeUsageQty'] + (float)$row['DirectUsageQty'];
    $chartValue[] = (float)$row['CurrentStockValue'];
}
foreach ($rows as $row) {
    $chartIn += (float)$row['StockInQty'];
    $chartOut += (float)$row['StockOutQty'];
    if ((float)$row['CurrentBalance'] < 0) { $chartNegative++; }
    if ((float)$row['CurrentBalance'] == 0) { $chartZero++; }
    $groupKey = (string)$row['InventoryGroup'];
    if (!isset($valueByGroup[$groupKey])) { $valueByGroup[$groupKey] = 0; }
    $valueByGroup[$groupKey] += (float)$row['CurrentStockValue'];
}
$chartHealthy = max(0, $summary['total_items'] - $chartNegative - $chartZero);
arsort($valueByGroup);

foreach ($soldRows as $soldRow) {
    $soldKey = $soldRow['MenuItemName'] . ' / ' . $soldRow['PortionName'];
    if (!isset($soldByMenu[$soldKey])) {
        $soldByMenu[$soldKey] = array(
            'menu' => $soldRow['MenuItemName'],
            'portion' => $soldRow['PortionName'],
            'sold_qty' => (float)$soldRow['SoldQty'],
            'usage' => 0,
            'rows' => array()
        );
    }
    $soldByMenu[$soldKey]['usage'] += (float)$soldRow['TotalIngredientUsage'];
    $soldByMenu[$soldKey]['rows'][] = $soldRow;
}
$topSoldMenu = $soldByMenu;
uasort($topSoldMenu, function($a, $b) {
    if ($a['sold_qty'] == $b['sold_qty']) { return 0; }
    return ($a['sold_qty'] < $b['sold_qty']) ? 1 : -1;
});
$topSoldKey = '';
foreach ($topSoldMenu as $topKey => $topValue) { $topSoldKey = $topKey; break; }
$riskItems = (int)$summary['negative_stock'] + (int)$summary['low_stock'];
$riskPercent = $summary['total_items'] > 0 ? round(($riskItems / $summary['total_items']) * 100, 1) : 0;
$movementPercent = $summary['total_items'] > 0 ? round(($summary['items_used'] / $summary['total_items']) * 100, 1) : 0;
$valueDelta = (float)$summary['current_value'] - (float)$summary['opening_value'];
$flowBalance = $chartIn - $chartOut;
$flowBalanceLabel = $flowBalance >= 0 ? 'Net stock gain' : 'Net stock drain';
$topUsageQty = !empty($topUsage) ? ((float)$topUsage[0]['RecipeUsageQty'] + (float)$topUsage[0]['DirectUsageQty']) : 0;
$topSoldQty = 0;
foreach ($topSoldMenu as $topSoldValue) {
    $topSoldQty = (float)$topSoldValue['sold_qty'];
    break;
}
$topGroupLabel = 'No stock value';
$topGroupValue = 0;
foreach ($valueByGroup as $groupName => $groupValue) {
    $topGroupLabel = $groupName;
    $topGroupValue = $groupValue;
    break;
}
$ownerHeadline = $riskItems > 0 ? number_format($riskItems) . ' inventory items need attention' : 'Inventory is operationally stable';
$ownerSubline = $riskItems > 0 ? 'Review negative and low-stock items before the next service period.' : 'No negative or low-stock exceptions are visible in the selected scope.';

$selectedWarehouseName = '';
foreach ($warehouses as $w) {
    if ($warehouseId !== null && (int)$w['Id'] === $warehouseId) { $selectedWarehouseName = $w['Name']; break; }
}
$selectedItemName = '';
foreach ($items as $it) {
    if ($itemId !== null && (int)$it['Id'] === $itemId) { $selectedItemName = $it['Name']; break; }
}
$appliedFilters = array();
$appliedFilters[] = 'From ' . $fromInput;
$appliedFilters[] = 'To ' . $toInput;
if ($selectedWarehouseName !== '') { $appliedFilters[] = 'Warehouse: ' . $selectedWarehouseName; }
if ($groupCode !== null) { $appliedFilters[] = 'Group: ' . $groupCode; }
if ($selectedItemName !== '') { $appliedFilters[] = 'Item: ' . $selectedItemName; }
if ($movementType !== null) { $appliedFilters[] = 'Movement: ' . $movementType; }
if ($search !== '') { $appliedFilters[] = 'Search: ' . $search; }
if (!$showZeroBalance) { $appliedFilters[] = 'Hide zero balance'; }
if (!$showZeroMovement) { $appliedFilters[] = 'Hide zero movement'; }
if ($showNegativeOnly) { $appliedFilters[] = 'Negative stock only'; }
if ($showLowStock) { $appliedFilters[] = 'Low stock only'; }
$filterSummary = implode(' | ', $appliedFilters);
$summaryLabels = array(
    'total_items' => 'Total Inventory Items',
    'items_used' => 'Items Used',
    'no_movement' => 'No Movement',
    'opening_value' => 'Opening Stock Value',
    'closing_value' => 'Closing Stock Value',
    'current_value' => 'Current Stock Value',
    'usage_qty' => 'Usage Quantity',
    'usage_cost' => 'Usage Cost',
    'negative_stock' => 'Negative Stock',
    'low_stock' => 'Low Stock',
    'zero_balance' => 'Zero Balance',
    'purchases' => 'Purchases',
    'waste' => 'Waste',
    'adjustments' => 'Adjustments',
    'production' => 'Production'
);

$exportMode = isset($_GET['export']) ? $_GET['export'] : '';
if ($exportMode === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=inventory-analytics-' . date('Ymd-His') . '.xls');
    echo "\xEF\xBB\xBF";
    echo "<table border='1'>";
    echo "<tr><th colspan='21'>Kynix Technologies - Inventory Analytics Report</th></tr>";
    echo "<tr><td>Generated</td><td>" . inv_h(date('Y-m-d H:i:s')) . "</td><td>Date Range</td><td>" . inv_h($fromInput . ' 06:00 to ' . date('Y-m-d', strtotime('+1 day', $toTs)) . ' 06:00') . "</td></tr>";
    echo "<tr><td>Filters</td><td colspan='20'>" . inv_h($filterSummary) . "</td></tr>";
    echo "<tr><th colspan='21'>KPI Summary</th></tr>";
    foreach ($summaryLabels as $key => $label) {
        echo "<tr><td>" . inv_h($label) . "</td><td>" . inv_h($summary[$key]) . "</td></tr>";
    }
    echo "<tr><th colspan='21'>Inventory Stock Position</th></tr>";
    echo "<tr><th>Item Code</th><th>Inventory Item</th><th>Inventory Group</th><th>Warehouse</th><th>Unit</th><th>Opening Qty</th><th>Purchase Qty</th><th>Production Qty</th><th>Transfer In</th><th>Recipe Usage</th><th>Direct Usage</th><th>Waste</th><th>Adjustment +</th><th>Adjustment -</th><th>Transfer Out</th><th>Closing Balance</th><th>Current Balance</th><th>Unit Cost</th><th>Usage Cost</th><th>Current Stock Value</th><th>Variance</th></tr>";
    foreach ($rows as $row) {
        echo "<tr>";
        foreach (array('ItemCode','ItemName','InventoryGroup','Warehouse','BaseUnit','OpeningQty','PurchaseQty','ProductionQty','TransferInQty','RecipeUsageQty','DirectUsageQty','WasteQty','AdjustmentPlusQty','AdjustmentMinusQty','TransferOutQty','ClosingBalance','CurrentBalance','UnitCost','UsageCost','CurrentStockValue','Variance') as $key) {
            echo "<td>" . inv_h($row[$key]) . "</td>";
        }
        echo "</tr>";
    }
    echo "<tr><th colspan='5'>Totals</th><th>" . inv_h($tableTotals['opening']) . "</th><th>" . inv_h($tableTotals['purchase']) . "</th><th>" . inv_h($summary['production']) . "</th><th></th><th colspan='2'>" . inv_h($tableTotals['usage']) . "</th><th>" . inv_h($tableTotals['waste']) . "</th><th colspan='2'>" . inv_h($summary['adjustments']) . "</th><th></th><th>" . inv_h($tableTotals['closing']) . "</th><th>" . inv_h($tableTotals['current']) . "</th><th></th><th></th><th>" . inv_h($tableTotals['current_value']) . "</th><th>" . inv_h($tableTotals['variance']) . "</th></tr>";
    echo "<tr><th colspan='21'>Sold Items &amp; Recipe Usage</th></tr>";
    echo "<tr><th>Menu Item</th><th>Portion</th><th>Sold Qty</th><th>Recipe Name</th><th>Inventory Ingredient</th><th>Ingredient Unit</th><th>Usage Per Item</th><th>Total Ingredient Usage</th></tr>";
    foreach ($soldRows as $soldRow) {
        echo "<tr><td>" . inv_h($soldRow['MenuItemName']) . "</td><td>" . inv_h($soldRow['PortionName']) . "</td><td>" . inv_h($soldRow['SoldQty']) . "</td><td>" . inv_h($soldRow['RecipeName']) . "</td><td>" . inv_h($soldRow['IngredientName']) . "</td><td>" . inv_h($soldRow['IngredientUnit']) . "</td><td>" . inv_h($soldRow['UsagePerItem']) . "</td><td>" . inv_h($soldRow['TotalIngredientUsage']) . "</td></tr>";
    }
    echo "<tr><th colspan='21'>Movement Ledger</th></tr>";
    echo "<tr><th>Item Code</th><th>Item</th><th>Date / Time</th><th>Movement Class</th><th>Transaction Type</th><th>Warehouse</th><th>Qty In</th><th>Qty Out</th><th>Net Qty</th><th>Reference</th><th>Source Table</th><th>Source ID</th></tr>";
    foreach ($ledgerRows as $movement) {
        echo "<tr><td>" . inv_h($movement['ItemCode']) . "</td><td>" . inv_h($movement['ItemName']) . "</td><td>" . inv_h(inv_date_value($movement['Date'])) . "</td><td>" . inv_h($movement['MovementClass']) . "</td><td>" . inv_h($movement['TransactionType']) . "</td><td>" . inv_h($movement['Warehouse']) . "</td><td>" . inv_h($movement['QtyIn']) . "</td><td>" . inv_h($movement['QtyOut']) . "</td><td>" . inv_h($movement['NetQty']) . "</td><td>" . inv_h($movement['Reference']) . "</td><td>" . inv_h($movement['SourceTable']) . "</td><td>" . inv_h($movement['SourceId']) . "</td></tr>";
    }
    echo "</table>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Analytics Dashboard</title>
    <link href="./css/vanzari.css" rel="stylesheet" media="screen">
    <link href="./css/print.css" rel="stylesheet" media="print">
    <link href="./bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <link href="./css/bootstrap-datetimepicker.min.css" rel="stylesheet" media="screen">
    <script type="text/javascript" src="./jquery/jquery-1.8.3.min.js" charset="UTF-8"></script>
    <script type="text/javascript" src="./bootstrap/js/bootstrap.min.js"></script>
    <script type="text/javascript" src="./js/bootstrap-datetimepicker.js" charset="UTF-8"></script>
<style>
        .kx-inventory-page{background:#f6f8fb;color:#111827}
        .kx-inventory-page .kx-shell{width:min(1360px,calc(100% - 40px));padding-bottom:36px}
        .inv-print-title{display:none}
        .inv-icon{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;margin-right:7px;vertical-align:-3px}
        .inv-icon:before{content:"";display:block;width:10px;height:10px;border-radius:3px;background:currentColor;opacity:.82}
        .inv-icon-warning-sign:before,.inv-icon-flag:before{border-radius:999px;background:#dc2626}
        .inv-icon-download-alt:before,.inv-icon-search:before,.inv-icon-print:before,.inv-icon-file:before{background:#2563eb}
        .kx-btn .inv-icon{margin-right:8px}
        .inv-command-center{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(360px,.95fr);gap:20px;margin:22px 0 18px}
        .inv-executive-card,.inv-control-card,.inv-insight-card,.inv-density-panel{background:#fff;border:1px solid #e5eaf2;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.07)}
        .inv-executive-card{padding:28px;display:flex;flex-direction:column;justify-content:space-between;min-height:300px;background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 58%,#0f766e 100%);color:#fff;overflow:hidden;position:relative}
        .inv-executive-card:after{content:"";position:absolute;right:-80px;top:-80px;width:230px;height:230px;border:1px solid rgba(255,255,255,.18);border-radius:50%}
        .inv-eyebrow{font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#bfdbfe;margin-bottom:10px}
        .inv-executive-card h1{margin:0;max-width:760px;font-size:38px;line-height:1.08;font-weight:900;color:#fff;letter-spacing:0}
        .inv-executive-card p{margin:14px 0 0;max-width:690px;color:#dbeafe;font-size:15px;line-height:1.5}
        .inv-hero-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:30px;position:relative;z-index:1}
        .inv-hero-meta div{border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);border-radius:14px;padding:13px}
        .inv-hero-meta span,.inv-control-meta span{display:block;color:#bfdbfe;font-size:11px;font-weight:900;text-transform:uppercase}
        .inv-hero-meta strong,.inv-control-meta strong{display:block;color:#fff;font-size:13px;margin-top:4px}
        .inv-control-card{padding:22px;display:flex;flex-direction:column;gap:18px}
        .inv-control-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
        .inv-control-head h2{margin:0;color:#0f172a;font-size:18px;font-weight:900}
        .inv-control-head p{margin:5px 0 0;color:#64748b;font-size:13px;font-weight:700}
        .inv-control-meta{display:grid;gap:10px}
        .inv-control-meta div{background:#0f172a;border-radius:14px;padding:12px}
        .inv-control-card .kx-theme-toggle{align-self:flex-start;border:1px solid #cbd5e1;background:#fff;color:#0f172a;border-radius:999px;height:34px;padding:0 14px;font-weight:900}
        .inv-action-stack{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .inv-action-stack .kx-btn{width:100%;justify-content:center}
        .inv-filter-panel{padding:0;overflow:hidden}
        .inv-filter-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px;cursor:pointer;font-weight:900;color:#0f172a;list-style:none}
        .inv-filter-summary::-webkit-details-marker{display:none}
        .inv-filter-summary span{color:#64748b;font-size:12px;font-weight:800}
        .inv-filter-body{border-top:1px solid #e5eaf2;padding:20px 22px 22px}
        .inv-filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:end}
        .inv-field label,.inv-checks-title{display:block;font-weight:900;color:#334155;margin-bottom:7px;font-size:12px}
        .inv-field .form-control,.inv-field select{height:42px;border:1px solid #d7dde7;border-radius:10px;box-shadow:none;font-weight:700;color:#111827;background:#fff}
        .inv-field .form-control:focus,.inv-field select:focus,.inv-page-size:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.13);outline:0}
        .inv-checks{display:grid;grid-template-columns:1fr 1fr;gap:8px;background:#f8fafc;border:1px solid #e5eaf2;border-radius:12px;padding:11px}
        .inv-checks label{display:flex;align-items:center;gap:7px;margin:0;color:#334155;font-weight:800;font-size:12px}
        .inv-actions{grid-column:1 / -1;display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
        .inv-chip-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:15px;padding-top:15px;border-top:1px solid #e5eaf2}
        .inv-chip{background:#eef2ff;color:#3730a3;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900}
        .inv-result-count{margin-left:auto;color:#64748b;font-weight:900}
        .inv-owner-grid{display:grid;grid-template-columns:1.15fr .85fr .85fr .85fr;gap:16px;margin:18px 0}
        .inv-kpi{background:#fff;border:1px solid #e5eaf2;border-radius:16px;padding:18px;box-shadow:0 12px 34px rgba(15,23,42,.06);min-height:150px}
        .inv-kpi-primary{background:#fff;border-left:5px solid #2563eb}
        .inv-kpi span{display:block;color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase}
        .inv-kpi strong{display:block;color:#0f172a;font-size:28px;line-height:1.1;margin-top:9px;font-weight:900}
        .inv-kpi-primary strong{font-size:38px}
        .inv-kpi em{display:block;margin-top:10px;color:#64748b;font-style:normal;font-weight:800;font-size:12px}
        .inv-negative,.inv-stat-danger strong{color:#dc2626!important}
        .inv-low{color:#b45309!important}
        .inv-zero{color:#64748b!important}
        .inv-metric-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#e5eaf2;border:1px solid #e5eaf2;border-radius:16px;overflow:hidden;margin:0 0 20px}
        .inv-metric-strip div{background:#fff;padding:15px 16px}
        .inv-metric-strip span{display:block;color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase}
        .inv-metric-strip strong{display:block;margin-top:5px;color:#0f172a;font-size:18px;font-weight:900}
        .inv-insight-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;margin-bottom:20px}
        .inv-insight-card{padding:20px}
        .inv-section-label{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}
        .inv-section-label h2{margin:0;color:#0f172a;font-size:18px;font-weight:900}
        .inv-section-label p{margin:4px 0 0;color:#64748b;font-size:13px;font-weight:700}
        .inv-section-label span{color:#2563eb;font-size:11px;font-weight:900;text-transform:uppercase}
        .inv-chart-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:20px}
        .inv-chart-grid .kx-chart-panel{margin-bottom:0}
        .inv-chart-wide{grid-column:span 2}
        .inv-lite-bar{margin:12px 0}
        .inv-lite-label{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:7px}
        .inv-lite-label strong{color:#0f172a;font-size:13px;font-weight:900}
        .inv-lite-label span{color:#64748b;font-weight:900;font-size:12px;text-align:right}
        .inv-lite-track{height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden}
        .inv-lite-fill{height:100%;border-radius:999px;background:#2563eb}
        .inv-lite-fill.inv-in{background:#16a34a}
        .inv-lite-fill.inv-out{background:#dc2626}
        .inv-lite-fill.inv-value{background:#0f766e}
        .inv-health{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
        .inv-health div{border-radius:14px;padding:14px;background:#f8fafc;text-align:center;border:1px solid #e5eaf2}
        .inv-health span{display:block;color:#64748b;font-weight:900;font-size:11px;text-transform:uppercase}
        .inv-health strong{display:block;font-size:26px;color:#0f172a;margin-top:5px}
        .inv-table-meta{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        .inv-page-size{height:42px;border:1px solid #d1d5db;border-radius:12px;padding:0 10px;font-weight:800;background:#fff}
        .inv-badge{display:inline-block;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:900;white-space:nowrap}
        .inv-bad-neg{background:#fee2e2;color:#991b1b}
        .inv-bad-zero{background:#f1f5f9;color:#475569}
        .inv-bad-low{background:#fef3c7;color:#92400e}
        .inv-bad-ok{background:#dcfce7;color:#166534}
        .inv-detail-btn{border:0;border-radius:10px;background:#dbeafe;color:#1e40af;font-weight:900;padding:8px 11px}
        .inv-detail-btn:hover{background:#bfdbfe}
        .inv-sort-indicator{opacity:.72;font-size:11px}
        .inv-search-hit{background:#fef3c7;border-radius:5px;padding:0 2px;color:#92400e}
        .inv-ledger{display:none;background:#f8fafc}
        .inv-ledger.open{display:table-row}
        .inv-ledger-box{padding:18px}
        .inv-detail-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin:12px 0 16px}
        .inv-detail-grid div{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px}
        .inv-detail-grid span{display:block;color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase}
        .inv-detail-grid strong{display:block;color:#0f172a;margin-top:4px}
        .inv-mini-table{font-size:12px}
        .inv-mini-table thead th{position:static!important}
        .inv-mobile-cards{display:none}
        .inv-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.08)}
        .inv-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
        .inv-card h3{margin:0;font-size:17px;font-weight:900;color:#0f172a}
        .inv-card small{display:block;color:#64748b;font-weight:800;margin-top:3px}
        .inv-card dl{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0}
        .inv-card dt{color:#64748b;font-size:11px;text-transform:uppercase;font-weight:900}
        .inv-card dd{margin:3px 0 0;font-weight:900;color:#0f172a}
        .inv-card-details{display:none;border-top:1px solid #e5e7eb;margin-top:12px;padding-top:12px}
        .inv-card-details.open{display:block}
        .inv-card-details p{margin:8px 0;color:#64748b;font-weight:800}
        .inv-card-details p strong{display:block;color:#0f172a}
        .inv-card-details p span{display:block;font-size:12px}
        .inv-card-details p em{display:block;font-style:normal;color:#334155;margin-top:2px}
        .inv-card-ledger{margin-top:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;padding:12px}
        .inv-card-ledger strong{display:block;color:#0f172a;margin-bottom:8px}
        .inv-card-ledger p{display:grid;grid-template-columns:1fr auto;gap:4px 10px;margin:8px 0;color:#64748b;font-size:12px}
        .inv-card-ledger p span{grid-column:1 / -1}
        .inv-card-ledger p b{color:#0f172a}
        .inv-card-ledger p em{font-style:normal;font-weight:900;color:#334155}
        .inv-sold-group{display:none;background:#f8fafc}
        .inv-sold-group.open{display:table-row}
        .inv-sold-mobile{display:none}
        .inv-sold-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:15px;box-shadow:0 10px 24px rgba(15,23,42,.08)}
        .inv-sold-card h3{margin:0;font-size:16px;font-weight:900;color:#0f172a}
        .inv-sold-card p{margin:5px 0 0;color:#64748b;font-weight:800}
        .inv-sold-card dl{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0 0}
        .inv-sold-card dt{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:900}
        .inv-sold-card dd{margin:3px 0 0;color:#0f172a;font-weight:900}
        .inv-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 24px;border-top:1px solid #e5e7eb}
        .inv-pagination button{height:40px;border:0;border-radius:10px;background:#0f172a;color:#fff;font-weight:900;padding:0 14px}
        .inv-pagination button:disabled{background:#cbd5e1;color:#64748b}
        .inv-hidden-export{display:none}
        body.kx-dark-mode .inv-executive-card,body.kx-dark-mode .inv-control-card,body.kx-dark-mode .inv-insight-card,body.kx-dark-mode .inv-density-panel,body.kx-dark-mode .inv-kpi,body.kx-dark-mode .inv-metric-strip div{background:#111827;border-color:#1e293b;color:#e5e7eb}
        body.kx-dark-mode .inv-control-head h2,body.kx-dark-mode .inv-filter-summary,body.kx-dark-mode .inv-kpi strong,body.kx-dark-mode .inv-metric-strip strong,body.kx-dark-mode .inv-section-label h2{color:#f8fafc}
        body.kx-dark-mode .inv-field .form-control,body.kx-dark-mode .inv-field select,body.kx-dark-mode .inv-page-size{background:#020617;border-color:#334155;color:#e5e7eb}
        body.kx-dark-mode .inv-filter-body,body.kx-dark-mode .inv-checks,body.kx-dark-mode .inv-health div,body.kx-dark-mode .inv-detail-grid div,body.kx-dark-mode .inv-card,body.kx-dark-mode .inv-ledger,body.kx-dark-mode .inv-sold-group,body.kx-dark-mode .inv-sold-card,body.kx-dark-mode .inv-card-ledger{background:#111827;border-color:#1e293b;color:#e5e7eb}
        body.kx-dark-mode .inv-field label,body.kx-dark-mode .inv-checks-title,body.kx-dark-mode .inv-checks label,body.kx-dark-mode .inv-lite-label strong,body.kx-dark-mode .inv-health strong,body.kx-dark-mode .inv-detail-grid strong,body.kx-dark-mode .inv-card h3,body.kx-dark-mode .inv-card dd,body.kx-dark-mode .inv-sold-card h3,body.kx-dark-mode .inv-sold-card dd,body.kx-dark-mode .inv-card-details p strong,body.kx-dark-mode .inv-card-ledger strong,body.kx-dark-mode .inv-card-ledger p b{color:#f8fafc}
        body.kx-dark-mode .inv-lite-track{background:#1e293b}
        body.kx-dark-mode .inv-card-details,body.kx-dark-mode .inv-chip-row,.kx-dark-mode .inv-pagination{border-color:#1e293b}
        @media(max-width:1180px){.inv-command-center,.inv-insight-grid{grid-template-columns:1fr}.inv-owner-grid{grid-template-columns:1fr 1fr}.inv-filter-grid{grid-template-columns:repeat(2,1fr)}.inv-chart-grid{grid-template-columns:1fr 1fr}.inv-chart-wide{grid-column:span 1}.inv-detail-grid{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:768px){.kx-inventory-page .kx-shell{width:calc(100% - 24px)}.inv-executive-card{padding:22px;min-height:0}.inv-executive-card h1{font-size:28px}.inv-hero-meta,.inv-owner-grid,.inv-metric-strip,.inv-filter-grid,.inv-chart-grid{grid-template-columns:1fr}.inv-checks{grid-template-columns:1fr}.inv-action-stack,.inv-actions{display:grid;grid-template-columns:1fr}.inv-actions .kx-btn{width:100%}.inv-result-count{margin-left:0;width:100%}.inv-desktop-table{display:none}.inv-mobile-cards{display:grid;gap:12px}.inv-table-meta{display:block}.inv-table-meta .kx-search-wrap input{margin-bottom:10px}.inv-detail-grid{grid-template-columns:1fr 1fr}.inv-pagination{display:none}.inv-sold-desktop{display:none}.inv-sold-mobile{display:grid;gap:12px;padding:16px}.inv-sold-card .inv-detail-btn{width:100%;margin-top:12px}.kx-inventory-page .kx-stat-card{min-height:0}}
        @media(max-width:520px){.inv-detail-grid,.inv-card dl,.inv-health{grid-template-columns:1fr}.inv-filter-summary{align-items:flex-start;flex-direction:column}.inv-executive-card h1{font-size:24px}}
        @media print{.kx-topbar,.inv-filter-panel,.inv-actions,.kx-search-wrap,.inv-pagination,.inv-detail-btn,.inv-charts,.inv-chart-grid,.inv-mobile-cards,.inv-sold-mobile,.inv-control-card{display:none!important}.inv-print-title{display:block;border-bottom:3px solid #0f172a;margin-bottom:12px;padding-bottom:8px}.kx-inventory-page .kx-shell{width:100%;margin:0}.inv-command-center,.inv-owner-grid,.inv-insight-grid,.inv-metric-strip{display:block}.inv-executive-card,.inv-kpi,.inv-metric-strip div,.inv-insight-card{box-shadow:none!important;border:1px solid #d1d5db;margin-bottom:8px;color:#0f172a;background:#fff}.inv-executive-card h1,.inv-executive-card p,.inv-hero-meta strong{color:#0f172a}.inv-desktop-table{display:block!important}.inv-ledger{display:none!important}.kx-table{font-size:9.5px;border-collapse:collapse}.kx-table thead{display:table-header-group}.kx-table tbody td,.kx-table thead th{padding:6px;border:1px solid #e5e7eb}.kx-panel,.kx-stat-card{break-inside:avoid;box-shadow:none!important}.kx-footer:after{content:'  |  Page ' counter(page)}@page{size:A4 landscape;margin:9mm}}
    </style>
</head>
<body class="PaginaVanzari kx-page kx-inventory-page">
<?php include 'header.php'; ?>
<main class="kx-shell">
    <div class="inv-print-title"><h1>Inventory Analytics</h1><p><?php echo inv_h($fromInput); ?> to <?php echo inv_h($toInput); ?></p></div>
    <?php foreach ($errors as $error) { ?><section class="kx-alert kx-alert-error"><?php echo inv_h($error); ?></section><?php } ?>

    <section class="inv-command-center">
        <div class="inv-executive-card">
            <div>
                <div class="inv-eyebrow">Inventory Control Center</div>
                <h1><?php echo inv_h($ownerHeadline); ?></h1>
                <p><?php echo inv_h($ownerSubline); ?> Current stock value is <strong><?php echo inv_money($summary['current_value']); ?></strong> across <?php echo number_format($summary['total_items']); ?> tracked items.</p>
            </div>
            <div class="inv-hero-meta">
                <div><span>Risk exposure</span><strong><?php echo inv_h($riskPercent); ?>% of items</strong></div>
                <div><span>Movement rate</span><strong><?php echo inv_h($movementPercent); ?>% used</strong></div>
                <div><span><?php echo inv_h($flowBalanceLabel); ?></span><strong><?php echo inv_num(abs($flowBalance)); ?></strong></div>
            </div>
        </div>
        <aside class="inv-control-card">
            <div class="inv-control-head">
                <div>
                    <h2>Report Scope</h2>
                    <p><?php echo inv_h($fromInput); ?> 06:00 to <?php echo inv_h(date('Y-m-d', strtotime('+1 day', $toTs))); ?> 06:00</p>
                </div>
                <button type="button" class="kx-theme-toggle" id="kxThemeToggle" title="Toggle dark mode">Dark</button>
            </div>
            <div class="inv-control-meta">
                <div><span>Generated</span><strong id="kxGeneratedAt">--</strong></div>
                <div><span>Warehouse</span><strong><?php echo $selectedWarehouseName !== '' ? inv_h($selectedWarehouseName) : 'All Warehouses'; ?></strong></div>
                <div><span>Active filters</span><strong><?php echo number_format(count($appliedFilters)); ?> applied</strong></div>
            </div>
            <div class="inv-action-stack">
                <a class="kx-btn kx-btn-success" href="?<?php echo inv_h(http_build_query(array_merge($_GET, array('export'=>'excel')))); ?>"><?php echo inv_icon('download-alt'); ?>Excel</a>
                <button class="kx-btn kx-btn-danger" type="button" onclick="kxExportInventoryPdf()"><?php echo inv_icon('file'); ?>PDF</button>
                <button class="kx-btn kx-btn-dark" type="button" onclick="window.print()"><?php echo inv_icon('print'); ?>Print</button>
                <a class="kx-btn kx-btn-dark" href="inventoryDaily.php"><?php echo inv_icon('refresh'); ?>Reset</a>
            </div>
        </aside>
    </section>

    <section class="inv-owner-grid">
        <div class="inv-kpi inv-kpi-primary"><span>Items needing action</span><strong class="<?php echo $riskItems > 0 ? 'inv-negative' : ''; ?>"><?php echo number_format($riskItems); ?></strong><em><?php echo number_format($summary['negative_stock']); ?> negative, <?php echo number_format($summary['low_stock']); ?> low stock</em></div>
        <div class="inv-kpi"><span>Current stock value</span><strong><?php echo inv_money($summary['current_value']); ?></strong><em><?php echo $valueDelta >= 0 ? '+' : '-'; ?><?php echo inv_money(abs($valueDelta)); ?> vs opening</em></div>
        <div class="inv-kpi"><span>Consumption pressure</span><strong><?php echo inv_num($summary['usage_qty']); ?></strong><em><?php echo inv_money($summary['usage_cost']); ?> usage cost</em></div>
        <div class="inv-kpi"><span>Waste quantity</span><strong class="<?php echo $summary['waste'] > 0 ? 'inv-low' : ''; ?>"><?php echo inv_num($summary['waste']); ?></strong><em><?php echo inv_num($summary['adjustments']); ?> adjusted</em></div>
    </section>

    <section class="inv-metric-strip">
        <div><span>Total items</span><strong><?php echo number_format($summary['total_items']); ?></strong></div>
        <div><span>No movement</span><strong><?php echo number_format($summary['no_movement']); ?></strong></div>
        <div><span>Purchases</span><strong><?php echo inv_num($summary['purchases']); ?></strong></div>
        <div><span>Production</span><strong><?php echo inv_num($summary['production']); ?></strong></div>
    </section>

    <details class="kx-panel inv-filter-panel">
        <summary class="inv-filter-summary">
            <strong>Refine analysis</strong>
            <span><?php echo number_format(count($rows)); ?> results / <?php echo inv_h($filterSummary); ?></span>
        </summary>
        <form method="get" action="inventoryDaily.php" class="inv-filter-body">
            <div class="inv-filter-grid">
                <div class="inv-field"><label>From Date</label><input class="form-control" type="date" name="fromDate" value="<?php echo inv_h($fromInput); ?>"></div>
                <div class="inv-field"><label>To Date</label><input class="form-control" type="date" name="toDate" value="<?php echo inv_h($toInput); ?>"></div>
                <div class="inv-field"><label>Warehouse</label><select class="form-control" name="warehouseId"><option value="">All Warehouses</option><?php foreach($warehouses as $w){ ?><option value="<?php echo (int)$w['Id']; ?>" <?php echo $warehouseId===(int)$w['Id']?'selected':''; ?>><?php echo inv_h($w['Name']); ?></option><?php } ?></select></div>
                <div class="inv-field"><label>Inventory Group</label><select class="form-control" name="groupCode"><option value="">All Groups</option><?php foreach($groups as $g){ ?><option value="<?php echo inv_h($g['GroupCode']); ?>" <?php echo $groupCode===$g['GroupCode']?'selected':''; ?>><?php echo inv_h($g['GroupCode']); ?></option><?php } ?></select></div>
                <div class="inv-field"><label>Inventory Item</label><select class="form-control" name="itemId"><option value="">All Items</option><?php foreach($items as $it){ ?><option value="<?php echo (int)$it['Id']; ?>" <?php echo $itemId===(int)$it['Id']?'selected':''; ?>><?php echo inv_h($it['Name']); ?></option><?php } ?></select></div>
                <div class="inv-field"><label>Movement Type</label><select class="form-control" name="movementType"><option value="">All Movements</option><?php foreach(array('Purchase','Purchase Return','Recipe Consumption','Production','Transfer In','Transfer Out','Waste','Adjustment +','Adjustment -','Inventory Movement') as $m){ ?><option value="<?php echo inv_h($m); ?>" <?php echo $movementType===$m?'selected':''; ?>><?php echo inv_h($m); ?></option><?php } ?></select></div>
                <div class="inv-field"><label>Search</label><input class="form-control" type="search" name="search" value="<?php echo inv_h($search); ?>" placeholder="Item, code, group"></div>
                <div class="inv-field"><label>Low Stock Threshold</label><input class="form-control" type="number" step="0.001" name="lowStockThreshold" value="<?php echo inv_h($lowStockThreshold); ?>"></div>
                <div>
                    <span class="inv-checks-title">Options</span>
                    <div class="inv-checks">
                        <label><input type="checkbox" name="showZeroBalance" value="1" <?php echo $showZeroBalance?'checked':''; ?>> Show Zero Balance</label>
                        <label><input type="checkbox" name="showZeroMovement" value="1" <?php echo $showZeroMovement?'checked':''; ?>> Show Zero Movement</label>
                        <label><input type="checkbox" name="showNegativeOnly" value="1" <?php echo $showNegativeOnly?'checked':''; ?>> Negative Stock Only</label>
                        <label><input type="checkbox" name="showLowStock" value="1" <?php echo $showLowStock?'checked':''; ?>> Low Stock Only</label>
                    </div>
                </div>
                <div class="inv-actions">
                    <button class="kx-btn kx-btn-primary" type="submit"><?php echo inv_icon('search'); ?>Update Dashboard</button>
                    <a class="kx-btn kx-btn-dark" href="inventoryDaily.php"><?php echo inv_icon('refresh'); ?>Reset</a>
                </div>
            </div>
            <div class="inv-chip-row">
                <?php foreach ($appliedFilters as $chip) { ?><span class="inv-chip"><?php echo inv_h($chip); ?></span><?php } ?>
                <span class="inv-result-count"><?php echo number_format(count($rows)); ?> results</span>
            </div>
        </form>
    </details>

    <section class="inv-insight-grid inv-charts">
        <div class="inv-insight-card">
            <div class="inv-section-label"><div><h2>What changed inventory today?</h2><p>Stock entering, stock leaving, and the net direction of inventory movement.</p></div><span>Flow</span></div>
            <?php $flowMax = max(1, $chartIn, $chartOut); ?>
            <div class="inv-lite-bar"><div class="inv-lite-label"><strong>Stock In</strong><span><?php echo inv_num($chartIn); ?></span></div><div class="inv-lite-track"><div class="inv-lite-fill inv-in" style="width:<?php echo min(100, ($chartIn / $flowMax) * 100); ?>%"></div></div></div>
            <div class="inv-lite-bar"><div class="inv-lite-label"><strong>Stock Out</strong><span><?php echo inv_num($chartOut); ?></span></div><div class="inv-lite-track"><div class="inv-lite-fill inv-out" style="width:<?php echo min(100, ($chartOut / $flowMax) * 100); ?>%"></div></div></div>
            <div class="inv-lite-bar"><div class="inv-lite-label"><strong><?php echo inv_h($flowBalanceLabel); ?></strong><span><?php echo inv_num(abs($flowBalance)); ?></span></div><div class="inv-lite-track"><div class="inv-lite-fill inv-value" style="width:<?php echo min(100, abs($flowBalance) / $flowMax * 100); ?>%"></div></div></div>
        </div>
        <div class="inv-insight-card">
            <div class="inv-section-label"><div><h2>Where should attention go?</h2><p>Exceptions and the highest value concentration in the selected scope.</p></div><span>Action</span></div>
            <div class="inv-health"><div><span>Negative</span><strong class="inv-negative"><?php echo $chartNegative; ?></strong></div><div><span>Low</span><strong class="inv-low"><?php echo number_format($summary['low_stock']); ?></strong></div><div><span>Healthy</span><strong><?php echo $chartHealthy; ?></strong></div></div>
            <div class="inv-lite-bar"><div class="inv-lite-label"><strong><?php echo inv_h($topGroupLabel); ?></strong><span><?php echo inv_money($topGroupValue); ?></span></div><div class="inv-lite-track"><div class="inv-lite-fill inv-value" style="width:100%"></div></div></div>
        </div>
    </section>

    <section class="inv-chart-grid inv-charts">
        <div class="kx-panel kx-chart-panel">
            <div class="kx-chart-header"><div><h2>Usage Hotspots</h2><p>Top consumed inventory items</p></div><span><?php echo inv_num($topUsageQty); ?></span></div>
            <?php $maxUsage = empty($chartUsage) ? 0 : max($chartUsage); ?>
            <?php if ($maxUsage <= 0) { ?><div class="kx-chart-empty">No usage for selected period.</div><?php } ?>
            <?php foreach ($chartLabels as $idx => $label) { $val = $chartUsage[$idx]; $pct = $maxUsage > 0 ? max(3, min(100, ($val / $maxUsage) * 100)) : 0; ?>
                <div class="inv-lite-bar"><div class="inv-lite-label"><strong><?php echo inv_h($label); ?></strong><span><?php echo inv_num($val); ?></span></div><div class="inv-lite-track"><div class="inv-lite-fill" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="kx-panel kx-chart-panel">
            <div class="kx-chart-header"><div><h2>Value by Group</h2><p>Stock value concentration</p></div><span>Value</span></div>
            <?php $maxGroupValue = empty($valueByGroup) ? 0 : max($valueByGroup); ?>
            <?php if ($maxGroupValue <= 0) { ?><div class="kx-chart-empty">No stock value for selected filters.</div><?php } ?>
            <?php $groupShown = 0; foreach ($valueByGroup as $groupName => $groupValue) { if ($groupShown++ >= 8) { break; } $pct = $maxGroupValue > 0 ? max(3, min(100, ($groupValue / $maxGroupValue) * 100)) : 0; ?>
                <div class="inv-lite-bar"><div class="inv-lite-label"><strong><?php echo inv_h($groupName); ?></strong><span><?php echo inv_money($groupValue); ?></span></div><div class="inv-lite-track"><div class="inv-lite-fill inv-value" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="kx-panel kx-chart-panel">
            <div class="kx-chart-header"><div><h2>Sales Pull</h2><p>Top sold menu items driving recipe usage</p></div><span><?php echo inv_num($topSoldQty); ?></span></div>
            <?php $soldSlice = array_slice($topSoldMenu, 0, 8); $maxSold = 0; foreach ($soldSlice as $soldChart) { if ($soldChart['sold_qty'] > $maxSold) { $maxSold = $soldChart['sold_qty']; } } ?>
            <?php if ($maxSold <= 0) { ?><div class="kx-chart-empty">No sold menu recipe usage for selected period.</div><?php } ?>
            <?php foreach ($soldSlice as $soldChart) { $pct = $maxSold > 0 ? max(3, min(100, ($soldChart['sold_qty'] / $maxSold) * 100)) : 0; ?>
                <div class="inv-lite-bar"><div class="inv-lite-label"><strong><?php echo inv_h($soldChart['menu'] . ' / ' . $soldChart['portion']); ?></strong><span><?php echo inv_num($soldChart['sold_qty']); ?> sold</span></div><div class="inv-lite-track"><div class="inv-lite-fill inv-in" style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
    </section>

    <section class="kx-panel kx-table-panel">
        <div class="kx-table-toolbar">
            <div><h2>Inventory Stock Position</h2><p><span id="invVisibleCount"><?php echo count($rows); ?></span> of <?php echo count($rows); ?> items. Details include ledger movement and technical columns.</p></div>
            <div class="inv-table-meta">
                <div class="kx-search-wrap"><input id="invTableSearch" type="search" placeholder="Search inventory..."></div>
                <select class="inv-page-size" id="invPageSize"><option value="10">10 rows</option><option value="25" selected>25 rows</option><option value="50">50 rows</option><option value="999999">All rows</option></select>
            </div>
        </div>
        <div class="kx-table-wrap inv-desktop-table">
            <table class="kx-table" id="inventoryTable">
                <thead><tr><th onclick="invSortTable(0)">Item <span class="inv-sort-indicator">sort</span></th><th onclick="invSortTable(1)">Group <span class="inv-sort-indicator">sort</span></th><th>Warehouse</th><th>Unit</th><th onclick="invSortTable(4)" class="kx-num">Opening <span class="inv-sort-indicator">sort</span></th><th class="kx-num">Purchase</th><th onclick="invSortTable(6)" class="kx-num">Usage <span class="inv-sort-indicator">sort</span></th><th class="kx-num">Waste</th><th class="kx-num">Adjustment</th><th class="kx-num">Closing</th><th class="kx-num">Current</th><th class="kx-num">Variance</th><th class="kx-money">Current Value</th><th>Details</th></tr></thead>
                <tbody id="invTableBody">
                <?php foreach ($rows as $row) { $itemKey=(string)$row['ItemCode']; $usageQty=(float)$row['RecipeUsageQty'] + (float)$row['DirectUsageQty']; $adjustmentQty=(float)$row['AdjustmentPlusQty'] - (float)$row['AdjustmentMinusQty']; $status='Healthy'; $badge='inv-bad-ok'; if ((float)$row['CurrentBalance'] < 0) { $status='Negative'; $badge='inv-bad-neg'; } elseif ((float)$row['CurrentBalance'] == 0) { $status='Zero'; $badge='inv-bad-zero'; } elseif ((float)$row['CurrentBalance'] <= $lowStockThreshold) { $status='Low'; $badge='inv-bad-low'; } $rowClass = ((float)$row['CurrentBalance'] < 0 ? ' inv-negative-row' : (((float)$row['CurrentBalance'] > 0 && (float)$row['CurrentBalance'] <= $lowStockThreshold) ? ' inv-low-row' : '')); ?>
                    <tr class="inv-main-row<?php echo $rowClass; ?>" data-search="<?php echo inv_h(strtolower($row['ItemCode'].' '.$row['ItemName'].' '.$row['InventoryGroup'].' '.$row['Warehouse'].' '.$status)); ?>">
                        <td><div class="kx-item-name"><?php echo inv_h($row['ItemName']); ?></div><div class="kx-item-group">Code <?php echo (int)$row['ItemCode']; ?> <?php if ((int)$row['MovementCount'] === 0) { ?><span class="inv-badge inv-bad-zero">Zero movement</span><?php } ?></div></td>
                        <td><?php echo inv_h($row['InventoryGroup']); ?></td><td><?php echo inv_h($row['Warehouse']); ?></td><td><?php echo inv_h($row['BaseUnit']); ?></td>
                        <td class="kx-num"><?php echo inv_num($row['OpeningQty']); ?></td><td class="kx-num"><?php echo inv_num($row['PurchaseQty']); ?></td><td class="kx-num"><?php echo inv_num($usageQty); ?></td><td class="kx-num"><?php echo inv_num($row['WasteQty']); ?></td><td class="kx-num <?php echo $adjustmentQty < 0 ? 'inv-negative' : ''; ?>"><?php echo inv_num($adjustmentQty); ?></td><td class="kx-num"><?php echo inv_num($row['ClosingBalance']); ?></td><td class="kx-num <?php echo (float)$row['CurrentBalance'] < 0 ? 'inv-negative' : (((float)$row['CurrentBalance'] <= $lowStockThreshold && (float)$row['CurrentBalance'] > 0) ? 'inv-low' : ''); ?>"><?php echo inv_num($row['CurrentBalance']); ?></td><td class="kx-num <?php echo (float)$row['Variance'] < 0 ? 'inv-negative' : ''; ?>"><?php echo inv_num($row['Variance']); ?></td><td class="kx-money"><?php echo inv_money($row['CurrentStockValue']); ?></td><td><button type="button" class="inv-detail-btn" data-target="ledger-<?php echo (int)$row['ItemCode']; ?>" data-label="Details" data-icon="list-alt"><?php echo inv_icon('list-alt'); ?>Details</button><br><span class="inv-badge <?php echo $badge; ?>"><?php echo $status; ?></span></td>
                    </tr>
                    <tr class="inv-ledger" id="ledger-<?php echo (int)$row['ItemCode']; ?>"><td colspan="14"><div class="inv-ledger-box">
                        <strong>Movement Details</strong>
                        <div class="inv-detail-grid">
                            <div><span>Production</span><strong><?php echo inv_num($row['ProductionQty']); ?></strong></div><div><span>Transfer In</span><strong><?php echo inv_num($row['TransferInQty']); ?></strong></div><div><span>Transfer Out</span><strong><?php echo inv_num($row['TransferOutQty']); ?></strong></div><div><span>Recipe Usage</span><strong><?php echo inv_num($row['RecipeUsageQty']); ?></strong></div><div><span>Direct Usage</span><strong><?php echo inv_num($row['DirectUsageQty']); ?></strong></div><div><span>Unit Cost</span><strong><?php echo inv_money($row['UnitCost']); ?></strong></div>
                        </div>
                        <table class="kx-table inv-mini-table"><thead><tr><th>Date / Time</th><th>Movement Class</th><th>Transaction Type</th><th>Warehouse</th><th class="kx-num">Qty In</th><th class="kx-num">Qty Out</th><th class="kx-num">Net Qty</th><th class="kx-num">Running Balance</th><th>Reference</th><th>Source Table</th><th>Source ID</th></tr></thead><tbody>
                        <?php if (empty($ledgerByItem[$itemKey])) { ?><tr><td colspan="11" class="kx-empty-row">No period ledger movements.</td></tr><?php } else { foreach ($ledgerByItem[$itemKey] as $mv) { ?><tr><td><?php echo inv_h(inv_date_value($mv['Date'])); ?></td><td><?php echo inv_h($mv['MovementClass']); ?></td><td><?php echo inv_h($mv['TransactionType']); ?></td><td><?php echo inv_h($mv['Warehouse']); ?></td><td class="kx-num"><?php echo inv_num($mv['QtyIn']); ?></td><td class="kx-num"><?php echo inv_num($mv['QtyOut']); ?></td><td class="kx-num <?php echo (float)$mv['NetQty'] < 0 ? 'inv-negative' : ''; ?>"><?php echo inv_num($mv['NetQty']); ?></td><td class="kx-num"><?php echo inv_num($mv['RunningBalance']); ?></td><td><?php echo inv_h($mv['Reference']); ?></td><td><?php echo inv_h($mv['SourceTable']); ?></td><td><?php echo inv_h($mv['SourceId']); ?></td></tr><?php }} ?>
                        </tbody></table>
                    </div></td></tr>
                <?php } ?>
                <?php if (empty($rows)) { ?><tr><td colspan="14" class="kx-empty-row">No inventory items match the selected filters.</td></tr><?php } ?>
                </tbody>
                <tfoot><tr><th colspan="4">Totals</th><th class="kx-num"><?php echo inv_num($tableTotals['opening']); ?></th><th class="kx-num"><?php echo inv_num($tableTotals['purchase']); ?></th><th class="kx-num"><?php echo inv_num($tableTotals['usage']); ?></th><th class="kx-num"><?php echo inv_num($tableTotals['waste']); ?></th><th class="kx-num"><?php echo inv_num($tableTotals['adjustment']); ?></th><th class="kx-num"><?php echo inv_num($tableTotals['closing']); ?></th><th class="kx-num"><?php echo inv_num($tableTotals['current']); ?></th><th class="kx-num"><?php echo inv_num($tableTotals['variance']); ?></th><th class="kx-money"><?php echo inv_money($tableTotals['current_value']); ?></th><th></th></tr></tfoot>
            </table>
        </div>
        <div class="inv-mobile-cards">
            <?php foreach ($rows as $row) { $usageQty=(float)$row['RecipeUsageQty'] + (float)$row['DirectUsageQty']; $status='Healthy'; $badge='inv-bad-ok'; if ((float)$row['CurrentBalance'] < 0) { $status='Negative'; $badge='inv-bad-neg'; } elseif ((float)$row['CurrentBalance'] == 0) { $status='Zero'; $badge='inv-bad-zero'; } elseif ((float)$row['CurrentBalance'] <= $lowStockThreshold) { $status='Low'; $badge='inv-bad-low'; } ?>
                <div class="inv-card" data-search="<?php echo inv_h(strtolower($row['ItemCode'].' '.$row['ItemName'].' '.$row['InventoryGroup'].' '.$row['Warehouse'].' '.$status)); ?>">
                    <div class="inv-card-head"><div><h3><?php echo inv_h($row['ItemName']); ?></h3><small>Code <?php echo (int)$row['ItemCode']; ?> / <?php echo inv_h($row['InventoryGroup']); ?></small></div><span class="inv-badge <?php echo $badge; ?>"><?php echo $status; ?></span></div>
                    <dl><div><dt>Warehouse</dt><dd><?php echo inv_h($row['Warehouse']); ?></dd></div><div><dt>Unit</dt><dd><?php echo inv_h($row['BaseUnit']); ?></dd></div><div><dt>Opening</dt><dd><?php echo inv_num($row['OpeningQty']); ?></dd></div><div><dt>Usage</dt><dd><?php echo inv_num($usageQty); ?></dd></div><div><dt>Closing</dt><dd><?php echo inv_num($row['ClosingBalance']); ?></dd></div><div><dt>Current Balance</dt><dd><?php echo inv_num($row['CurrentBalance']); ?></dd></div><div><dt>Variance</dt><dd><?php echo inv_num($row['Variance']); ?></dd></div><div><dt>Current Value</dt><dd><?php echo inv_money($row['CurrentStockValue']); ?></dd></div></dl>
                    <button type="button" class="inv-detail-btn inv-mobile-detail-toggle" data-label="View Details" data-icon="list-alt"><?php echo inv_icon('list-alt'); ?>View Details</button>
                    <div class="inv-card-details"><dl><div><dt>Purchase</dt><dd><?php echo inv_num($row['PurchaseQty']); ?></dd></div><div><dt>Waste</dt><dd><?php echo inv_num($row['WasteQty']); ?></dd></div><div><dt>Adjustment +</dt><dd><?php echo inv_num($row['AdjustmentPlusQty']); ?></dd></div><div><dt>Adjustment -</dt><dd><?php echo inv_num($row['AdjustmentMinusQty']); ?></dd></div><div><dt>Moves</dt><dd><?php echo (int)$row['MovementCount']; ?></dd></div><div><dt>Unit Cost</dt><dd><?php echo inv_money($row['UnitCost']); ?></dd></div><div><dt>Recipe Usage</dt><dd><?php echo inv_num($row['RecipeUsageQty']); ?></dd></div><div><dt>Direct Usage</dt><dd><?php echo inv_num($row['DirectUsageQty']); ?></dd></div></dl><div class="inv-card-ledger"><strong><?php echo inv_icon('transfer'); ?>Movement Ledger</strong><?php if (empty($ledgerByItem[(string)$row['ItemCode']])) { ?><p>No period ledger movements.</p><?php } else { $mobileMvCount = 0; foreach ($ledgerByItem[(string)$row['ItemCode']] as $mv) { if ($mobileMvCount++ >= 4) { break; } ?><p><span><?php echo inv_h(inv_date_value($mv['Date'])); ?></span><b><?php echo inv_h($mv['MovementClass']); ?></b><em><?php echo inv_num($mv['NetQty']); ?></em></p><?php } } ?></div></div>
                </div>
            <?php } ?>
            <?php if (empty($rows)) { ?><div class="kx-empty-state"><h2>No inventory items</h2><p>No items match the selected filters.</p></div><?php } ?>
        </div>
        <div class="inv-pagination"><span id="invPageInfo">Showing results</span><div><button type="button" id="invPrevPage">Previous</button> <button type="button" id="invNextPage">Next</button></div></div>
    </section>

    <section class="kx-panel kx-table-panel">
        <div class="kx-table-toolbar">
            <div><h2><?php echo inv_icon('cutlery'); ?>Sold Items &amp; Recipe Usage</h2><p><?php echo count($soldRows); ?> ingredient usage rows grouped by sold menu item.</p></div>
            <div class="inv-table-meta">
                <div class="kx-search-wrap"><input id="soldUsageSearch" type="search" placeholder="Search sold item or ingredient..."></div>
                <select class="inv-page-size" id="soldUsageSort"><option value="sold-desc">Sold qty high to low</option><option value="sold-asc">Sold qty low to high</option><option value="name-asc">Menu item A to Z</option></select>
            </div>
        </div>
        <div class="kx-table-wrap inv-sold-desktop">
            <table class="kx-table" id="soldUsageTable">
                <thead><tr><th>Menu Item</th><th>Portion</th><th class="kx-num">Sold Qty</th><th class="kx-num">Total Ingredient Usage</th><th>Details</th></tr></thead>
                <tbody id="soldUsageBody">
                <?php $soldIndex = 0; foreach ($soldByMenu as $soldKey => $soldGroup) { $soldIndex++; ?>
                    <tr class="inv-sold-row" data-search="<?php echo inv_h(strtolower($soldKey . ' ' . $soldGroup['usage'])); ?>" data-sold="<?php echo (float)$soldGroup['sold_qty']; ?>" data-name="<?php echo inv_h(strtolower($soldGroup['menu'])); ?>"><td><div class="kx-item-name"><?php echo inv_h($soldGroup['menu']); ?> <?php if ($soldKey === $topSoldKey) { ?><span class="inv-badge inv-bad-ok">Top sold</span><?php } ?></div></td><td><?php echo inv_h($soldGroup['portion']); ?></td><td class="kx-num"><?php echo inv_num($soldGroup['sold_qty']); ?></td><td class="kx-num"><?php echo inv_num($soldGroup['usage']); ?></td><td><button type="button" class="inv-detail-btn" data-target="sold-<?php echo $soldIndex; ?>" data-label="Ingredients" data-icon="cutlery"><?php echo inv_icon('cutlery'); ?>Ingredients</button></td></tr>
                    <tr class="inv-sold-group" id="sold-<?php echo $soldIndex; ?>"><td colspan="5"><div class="inv-ledger-box"><table class="kx-table inv-mini-table"><thead><tr><th>Recipe Name</th><th>Inventory Ingredient</th><th>Ingredient Unit</th><th class="kx-num">Usage Per Item</th><th class="kx-num">Total Ingredient Usage</th></tr></thead><tbody>
                    <?php foreach ($soldGroup['rows'] as $soldDetail) { ?><tr><td><?php echo inv_h($soldDetail['RecipeName']); ?></td><td><?php echo inv_h($soldDetail['IngredientName']); ?></td><td><?php echo inv_h($soldDetail['IngredientUnit']); ?></td><td class="kx-num"><?php echo inv_num($soldDetail['UsagePerItem'], 6); ?></td><td class="kx-num"><?php echo inv_num($soldDetail['TotalIngredientUsage']); ?></td></tr><?php } ?>
                    </tbody></table></div></td></tr>
                <?php } ?>
                <?php if (empty($soldByMenu)) { ?><tr><td colspan="5" class="kx-empty-row">No sold menu recipe usage for selected filters.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <div class="inv-sold-mobile">
            <?php foreach ($soldByMenu as $soldKey => $soldGroup) { ?>
                <div class="inv-sold-card" data-search="<?php echo inv_h(strtolower($soldKey . ' ' . $soldGroup['usage'])); ?>" data-sold="<?php echo (float)$soldGroup['sold_qty']; ?>" data-name="<?php echo inv_h(strtolower($soldGroup['menu'])); ?>">
                    <h3><?php echo inv_h($soldGroup['menu']); ?> <?php if ($soldKey === $topSoldKey) { ?><span class="inv-badge inv-bad-ok">Top sold</span><?php } ?></h3>
                    <p><?php echo inv_h($soldGroup['portion']); ?></p>
                    <dl><div><dt>Sold Qty</dt><dd><?php echo inv_num($soldGroup['sold_qty']); ?></dd></div><div><dt>Total Usage</dt><dd><?php echo inv_num($soldGroup['usage']); ?></dd></div></dl>
                    <button type="button" class="inv-detail-btn inv-mobile-detail-toggle" data-label="View Ingredients" data-icon="cutlery"><?php echo inv_icon('cutlery'); ?>View Ingredients</button>
                    <div class="inv-card-details">
                        <?php foreach ($soldGroup['rows'] as $soldDetail) { ?><p><strong><?php echo inv_h($soldDetail['IngredientName']); ?></strong><span><?php echo inv_h($soldDetail['IngredientUnit']); ?></span><em><?php echo inv_num($soldDetail['UsagePerItem'], 6); ?> each / <?php echo inv_num($soldDetail['TotalIngredientUsage']); ?> total</em></p><?php } ?>
                    </div>
                </div>
            <?php } ?>
            <?php if (empty($soldByMenu)) { ?><div class="kx-empty-state"><h2>No sold items</h2><p>No sold menu recipe usage matches the selected filters.</p></div><?php } ?>
        </div>
    </section>

    <div id="inventory_export" class="inv-hidden-export">
        <h2>Inventory Analytics</h2>
        <p><?php echo inv_h($fromInput); ?> 06:00 to <?php echo inv_h(date('Y-m-d', strtotime('+1 day', $toTs))); ?> 06:00</p>
        <table border="1"><tr><th>Metric</th><th>Value</th></tr>
            <?php foreach ($summary as $k=>$v) { echo '<tr><td>'.inv_h($k).'</td><td>'.inv_h($v).'</td></tr>'; } ?>
        </table>
        <table border="1"><tr><th>Item</th><th>Group</th><th>Warehouse</th><th>Unit</th><th>Opening</th><th>Purchase</th><th>Usage</th><th>Waste</th><th>Adjustment</th><th>Closing</th><th>Current</th><th>Variance</th><th>Current Value</th></tr>
            <?php foreach ($rows as $row) { $usageQty=(float)$row['RecipeUsageQty'] + (float)$row['DirectUsageQty']; $adjustmentQty=(float)$row['AdjustmentPlusQty'] - (float)$row['AdjustmentMinusQty']; echo '<tr><td>'.inv_h($row['ItemName']).'</td><td>'.inv_h($row['InventoryGroup']).'</td><td>'.inv_h($row['Warehouse']).'</td><td>'.inv_h($row['BaseUnit']).'</td><td>'.inv_h($row['OpeningQty']).'</td><td>'.inv_h($row['PurchaseQty']).'</td><td>'.inv_h($usageQty).'</td><td>'.inv_h($row['WasteQty']).'</td><td>'.inv_h($adjustmentQty).'</td><td>'.inv_h($row['ClosingBalance']).'</td><td>'.inv_h($row['CurrentBalance']).'</td><td>'.inv_h($row['Variance']).'</td><td>'.inv_h($row['CurrentStockValue']).'</td></tr>'; } ?>
        </table>
    </div>
</main>
<footer class="kx-footer">Powered by Kynix Technologies</footer>
<script>
(function(){
    var generated=document.getElementById('kxGeneratedAt');
    if(generated){ generated.innerHTML=new Date().toLocaleString(); }
    var menuBtn=document.getElementById('kxMenuBtn'), nav=document.getElementById('kxNav');
    if(menuBtn&&nav){ menuBtn.onclick=function(){ nav.classList.toggle('open'); }; }
    var themeBtn=document.getElementById('kxThemeToggle');
    var savedTheme=localStorage.getItem('kxTheme');
    if(savedTheme==='dark'){ document.body.classList.add('kx-dark-mode'); }
    function setThemeText(){
        if(themeBtn){ themeBtn.innerHTML=document.body.classList.contains('kx-dark-mode')?'Light':'Dark'; }
    }
    setThemeText();
    if(themeBtn){
        themeBtn.onclick=function(){
            document.body.classList.toggle('kx-dark-mode');
            var dark=document.body.classList.contains('kx-dark-mode');
            localStorage.setItem('kxTheme',dark?'dark':'light');
            setThemeText();
        };
    }
})();

var invSortDirection={};
var invCurrentPage=1;

function invIconHtml(name){
    return '<span class="inv-icon inv-icon-'+name+'" aria-hidden="true"></span>';
}

function invParseNumber(value){
    if(!value){ return 0; }
    return parseFloat(String(value).replace(/Rs\./gi,'').replace(/,/g,'').trim())||0;
}

function invMainRows(){
    return Array.prototype.slice.call(document.querySelectorAll('#invTableBody tr.inv-main-row'));
}

function invCloseDetail(row){
    var next=row.nextElementSibling;
    if(next && next.classList.contains('inv-ledger')){
        next.classList.remove('open');
        next.style.display='none';
    }
}

function invApplyTableState(){
    var search=document.getElementById('invTableSearch');
    var q=search ? (search.value||'').toLowerCase() : '';
    var pageSizeEl=document.getElementById('invPageSize');
    var pageSize=pageSizeEl ? parseInt(pageSizeEl.value,10) : 25;
    var rows=invMainRows();
    var matched=[];
    rows.forEach(function(row){
        var ok=(row.getAttribute('data-search')||'').indexOf(q)>-1;
        row.setAttribute('data-filtered',ok?'1':'0');
        invCloseDetail(row);
        if(ok){ matched.push(row); }
    });
    var totalPages=Math.max(1,Math.ceil(matched.length/pageSize));
    if(invCurrentPage>totalPages){ invCurrentPage=totalPages; }
    var start=(invCurrentPage-1)*pageSize;
    var end=start+pageSize;
    rows.forEach(function(row){
        var ok=row.getAttribute('data-filtered')==='1';
        var idx=matched.indexOf(row);
        row.style.display=(ok && idx>=start && idx<end)?'':'none';
    });
    document.querySelectorAll('.inv-mobile-cards .inv-card').forEach(function(card){
        card.style.display=(card.getAttribute('data-search')||'').indexOf(q)>-1?'':'none';
    });
    var visibleCount=document.getElementById('invVisibleCount');
    if(visibleCount){ visibleCount.innerHTML=matched.length; }
    var pageInfo=document.getElementById('invPageInfo');
    if(pageInfo){
        var first=matched.length===0?0:start+1;
        var last=Math.min(end,matched.length);
        pageInfo.innerHTML='Showing '+first+'-'+last+' of '+matched.length+' results';
    }
    var prev=document.getElementById('invPrevPage'), next=document.getElementById('invNextPage');
    if(prev){ prev.disabled=invCurrentPage<=1; }
    if(next){ next.disabled=invCurrentPage>=totalPages; }
}

function invSortTable(columnIndex){
    var tbody=document.getElementById('invTableBody');
    if(!tbody){ return; }
    var rows=invMainRows();
    var direction=invSortDirection[columnIndex]==='asc'?'desc':'asc';
    invSortDirection[columnIndex]=direction;
    rows.sort(function(a,b){
        var av=a.cells[columnIndex].innerText.trim();
        var bv=b.cells[columnIndex].innerText.trim();
        var an=invParseNumber(av), bn=invParseNumber(bv);
        var bothNumeric=!isNaN(an)&&!isNaN(bn)&&(columnIndex>=4);
        if(bothNumeric){ return direction==='asc'?an-bn:bn-an; }
        return direction==='asc'?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(function(row){
        var detail=row.nextElementSibling;
        tbody.appendChild(row);
        if(detail && detail.classList.contains('inv-ledger')){ tbody.appendChild(detail); }
    });
    invCurrentPage=1;
    invApplyTableState();
}

function invToggleTarget(targetId, button){
    var row=document.getElementById(targetId);
    if(!row){ return; }
    var open=row.classList.toggle('open');
    row.style.display=open?'table-row':'none';
    if(button){
        var label=button.getAttribute('data-label')||'Details';
        var icon=button.getAttribute('data-icon')||'list-alt';
        button.innerHTML=invIconHtml(icon)+(open?'Hide':label);
    }
}

function invApplySoldState(){
    var search=document.getElementById('soldUsageSearch');
    var q=search ? (search.value||'').toLowerCase() : '';
    var sort=document.getElementById('soldUsageSort');
    var mode=sort ? sort.value : 'sold-desc';
    var tbody=document.getElementById('soldUsageBody');
    var rows=tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr.inv-sold-row')) : [];
    rows.sort(function(a,b){
        if(mode==='name-asc'){ return (a.getAttribute('data-name')||'').localeCompare(b.getAttribute('data-name')||''); }
        var av=invParseNumber(a.getAttribute('data-sold'));
        var bv=invParseNumber(b.getAttribute('data-sold'));
        return mode==='sold-asc' ? av-bv : bv-av;
    });
    rows.forEach(function(row){
        var detail=row.nextElementSibling;
        if(tbody){
            tbody.appendChild(row);
            if(detail && detail.classList.contains('inv-sold-group')){ tbody.appendChild(detail); }
        }
    });
    rows.forEach(function(row){
        var ok=(row.getAttribute('data-search')||'').indexOf(q)>-1;
        row.style.display=ok?'':'none';
        var detail=row.nextElementSibling;
        if(detail && detail.classList.contains('inv-sold-group')){
            if(!ok){ detail.style.display='none'; detail.classList.remove('open'); }
        }
    });
    document.querySelectorAll('.inv-sold-mobile .inv-sold-card').forEach(function(card){
        card.style.display=(card.getAttribute('data-search')||'').indexOf(q)>-1?'':'none';
    });
}

document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.inv-detail-btn[data-target]').forEach(function(btn){
        btn.addEventListener('click',function(){ invToggleTarget(btn.getAttribute('data-target'),btn); });
    });
    document.querySelectorAll('.inv-mobile-detail-toggle').forEach(function(btn){
        btn.addEventListener('click',function(){
            var panel=btn.parentNode.querySelector('.inv-card-details');
            if(!panel){ return; }
            var open=panel.classList.toggle('open');
            var defaultText=btn.getAttribute('data-label')||'View Details';
            var icon=btn.getAttribute('data-icon')||'list-alt';
            btn.innerHTML=invIconHtml(icon)+(open?'Hide':defaultText);
        });
    });
    var search=document.getElementById('invTableSearch');
    if(search){ search.addEventListener('input',function(){ invCurrentPage=1; invApplyTableState(); }); }
    var pageSize=document.getElementById('invPageSize');
    if(pageSize){ pageSize.addEventListener('change',function(){ invCurrentPage=1; invApplyTableState(); }); }
    var prev=document.getElementById('invPrevPage'), next=document.getElementById('invNextPage');
    if(prev){ prev.addEventListener('click',function(){ invCurrentPage--; invApplyTableState(); }); }
    if(next){ next.addEventListener('click',function(){ invCurrentPage++; invApplyTableState(); }); }
    var soldSearch=document.getElementById('soldUsageSearch');
    if(soldSearch){ soldSearch.addEventListener('input',invApplySoldState); }
    var soldSort=document.getElementById('soldUsageSort');
    if(soldSort){ soldSort.addEventListener('change',invApplySoldState); }
    invApplyTableState();
    invApplySoldState();
});

function invEscape(text){
    return String(text||'').replace(/[&<>'\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','\"':'&quot;'}[c];});
}

function invCleanTableHtml(tableId){
    var table=document.getElementById(tableId);
    if(!table){ return ''; }
    var clone=table.cloneNode(true);
    clone.querySelectorAll('.inv-ledger,.inv-sold-group').forEach(function(row){ row.parentNode.removeChild(row); });
    clone.querySelectorAll('button').forEach(function(btn){ btn.parentNode.removeChild(btn); });
    clone.removeAttribute('id');
    return clone.outerHTML;
}

function invMetricRows(){
    var rows='';
    document.querySelectorAll('.kx-stat-card').forEach(function(card){
        var label=(card.querySelector('span')||{}).innerText||'';
        var value=(card.querySelector('strong')||{}).innerText||'';
        if(label && value){ rows+='<tr><td>'+invEscape(label)+'</td><td class="right">'+invEscape(value)+'</td></tr>'; }
    });
    return rows;
}

function kxExportInventoryPdf(){
    var title='Inventory Analytics';
    var period='<?php echo inv_h($fromInput); ?> 06:00 to <?php echo inv_h(date('Y-m-d', strtotime('+1 day', $toTs))); ?> 06:00';
    var filters='<?php echo inv_h($filterSummary); ?>';
    var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'+title+'</title><style>'+
    '@page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#111827;font-size:10px;margin:0;background:#fff}.pdf-header{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #0f172a;padding-bottom:10px;margin-bottom:12px}.pdf-brand{display:flex;align-items:center;gap:12px}.pdf-brand img{width:48px;height:48px;object-fit:contain}.pdf-header h1{margin:0;font-size:20px;color:#0f172a}.pdf-header p{margin:4px 0 0;color:#64748b}.pdf-meta{text-align:right;color:#334155;font-weight:700}.pdf-title{background:#0f172a;color:#fff;border-radius:10px;padding:12px 14px;margin-bottom:12px}.pdf-title h2{margin:0 0 4px;font-size:18px}.pdf-title p{margin:0;color:#dbeafe}.section{margin-top:12px;page-break-inside:avoid}.section h2{font-size:13px;margin:0 0 7px;border-left:4px solid #2563eb;padding-left:7px}.kx-table,.report-table{width:100%;border-collapse:collapse}.kx-table th,.report-table th{background:#0f172a!important;color:#fff!important;text-align:left;padding:6px;border:1px solid #0f172a}.kx-table td,.report-table td{padding:5px;border:1px solid #e5e7eb}.kx-table thead{display:table-header-group}.kx-num,.kx-money,.right{text-align:right}.footer{position:fixed;left:0;right:0;bottom:0;border-top:1px solid #e5e7eb;padding-top:5px;color:#64748b;display:flex;justify-content:space-between}.footer:after{content:\"Page \" counter(page)}.print-actions{position:fixed;top:10px;right:10px}.print-actions button{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 14px;font-weight:700}@media print{.print-actions{display:none}.section{break-inside:avoid}}'+
    '</style></head><body><div class="print-actions"><button onclick="window.print()">Save / Print PDF</button></div><div class="pdf-header"><div class="pdf-brand"><img src="./img/logo.png"><div><h1>Kynix Technologies</h1><p>SambaPOS WebReports</p></div></div><div class="pdf-meta"><strong>'+title+'</strong><br>Generated: '+invEscape(new Date().toLocaleString())+'</div></div>'+
    '<div class="pdf-title"><h2>Inventory Analytics Report</h2><p>'+invEscape(period)+' | '+invEscape(filters)+'</p></div>'+
    '<div class="section"><h2>Summary</h2><table class="report-table"><tbody>'+invMetricRows()+'</tbody></table></div>'+
    '<div class="section"><h2>Inventory Stock Position</h2>'+invCleanTableHtml('inventoryTable')+'</div>'+
    '<div class="section"><h2>Sold Items &amp; Recipe Usage</h2>'+invCleanTableHtml('soldUsageTable')+'</div><div class="footer"><span>Powered by Kynix Technologies</span><span>'+title+'</span></div></body></html>';
    var win=window.open('','_blank');
    if(!win){ alert('Please allow popups to export PDF.'); return; }
    win.document.open();
    win.document.write(html);
    win.document.close();
    setTimeout(function(){ win.focus(); win.print(); },700);
}
</script>
</body>
</html>


