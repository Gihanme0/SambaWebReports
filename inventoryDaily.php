<?php
require 'config.php';
$reportName = "Inventory Analytics " . $BusinessName;

function inv_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function inv_num($value, $decimals = 3) { return number_format((float)$value, $decimals); }
function inv_money($value) { return 'Rs. ' . number_format((float)$value, 2); }
function inv_bool($name, $default = false) { return isset($_GET[$name]) ? ($_GET[$name] === '1') : $default; }
function inv_value($row, $key, $default = 0) { return isset($row[$key]) ? $row[$key] : $default; }

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

$openingMap = array();
foreach ($rows as $row) {
    $itemKey = (string)$row['ItemCode'];
    $openingMap[$itemKey] = (float)$row['OpeningQty'];
    $summary['total_items']++;
    $usageQty = (float)$row['RecipeUsageQty'] + (float)$row['DirectUsageQty'];
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
}

$exportMode = isset($_GET['export']) ? $_GET['export'] : '';
if ($exportMode === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=inventory-analytics-' . date('Ymd-His') . '.xls');
    echo "<table border='1'>";
    echo "<tr><th colspan='21'>Inventory Analytics</th></tr>";
    echo "<tr><td>From</td><td>" . inv_h($fromInput) . "</td><td>To</td><td>" . inv_h($toInput) . "</td></tr>";
    echo "<tr><th>Item Code</th><th>Inventory Item</th><th>Inventory Group</th><th>Warehouse</th><th>Unit</th><th>Opening Qty</th><th>Purchase Qty</th><th>Production Qty</th><th>Transfer In</th><th>Recipe Usage</th><th>Direct Usage</th><th>Waste</th><th>Adjustment +</th><th>Adjustment -</th><th>Transfer Out</th><th>Closing Balance</th><th>Current Balance</th><th>Unit Cost</th><th>Usage Cost</th><th>Current Stock Value</th><th>Variance</th></tr>";
    foreach ($rows as $row) {
        echo "<tr>";
        foreach (array('ItemCode','ItemName','InventoryGroup','Warehouse','BaseUnit','OpeningQty','PurchaseQty','ProductionQty','TransferInQty','RecipeUsageQty','DirectUsageQty','WasteQty','AdjustmentPlusQty','AdjustmentMinusQty','TransferOutQty','ClosingBalance','CurrentBalance','UnitCost','UsageCost','CurrentStockValue','Variance') as $key) {
            echo "<td>" . inv_h($row[$key]) . "</td>";
        }
        echo "</tr>";
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
        .inv-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}.inv-filter .form-control,.inv-filter select{height:42px;border-radius:10px;border:1px solid #d1d5db;box-shadow:none}.inv-filter label{font-size:12px;text-transform:uppercase;color:#475569;font-weight:900;display:block;margin-bottom:6px}.inv-toolbar{display:flex;gap:10px;flex-wrap:wrap}.inv-btn{border:0;border-radius:10px;padding:11px 15px;font-weight:900;color:#fff;text-decoration:none;display:inline-block}.inv-blue{background:#2563eb}.inv-green{background:#16a34a}.inv-red{background:#dc2626}.inv-dark{background:#0f172a}.inv-soft{background:#e2e8f0;color:#0f172a}.inv-kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin:18px 0}.inv-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;box-shadow:0 12px 30px rgba(15,23,42,.08)}.inv-kpi span{display:block;color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase}.inv-kpi strong{display:block;margin-top:6px;font-size:20px;color:#0f172a}.inv-kpi.warn strong{color:#dc2626}.inv-charts{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:16px;margin-bottom:18px}.inv-chart{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 12px 30px rgba(15,23,42,.08);min-height:260px}.inv-chart h3{margin:0 0 12px;font-size:16px;font-weight:900}.inv-badge{display:inline-block;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900}.inv-bad-neg{background:#fee2e2;color:#991b1b}.inv-bad-zero{background:#f1f5f9;color:#475569}.inv-bad-ok{background:#dcfce7;color:#166534}.inv-ledger{display:none;background:#f8fafc}.inv-ledger.open{display:table-row}.inv-ledger-box{padding:14px}.inv-ledger table{width:100%;font-size:12px}.inv-expand{border:0;border-radius:8px;background:#dbeafe;color:#1e40af;font-weight:900;padding:6px 9px}.inv-mobile-cards{display:none}.inv-health{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.inv-health div{border-radius:12px;padding:12px;background:#f8fafc;text-align:center}.inv-health strong{display:block;font-size:24px}.inv-lite-bar{margin:12px 0}.inv-lite-label{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:#334155}.inv-lite-track{height:12px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:6px}.inv-lite-track div{height:100%;background:#2563eb;border-radius:999px}.inv-lite-track div.in{background:#16a34a}.inv-lite-track div.out{background:#dc2626}.inv-health-note{color:#64748b;font-weight:800;margin:14px 0 0}.inv-print-title{display:none}@media(max-width:1100px){.inv-grid{grid-template-columns:repeat(3,1fr)}.inv-kpis{grid-template-columns:repeat(3,1fr)}.inv-charts{grid-template-columns:1fr}}@media(max-width:680px){.kx-table-wrap{display:none}.inv-mobile-cards{display:grid;gap:12px}.inv-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,.08)}.inv-card h3{margin:0 0 8px;font-size:16px}.inv-card dl{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:0}.inv-card dt{color:#64748b;font-size:11px;text-transform:uppercase}.inv-card dd{margin:0;font-weight:900}.inv-grid,.inv-kpis{grid-template-columns:1fr}.inv-toolbar .inv-btn{width:100%;text-align:center}}@media print{.kx-topbar,.inv-filter,.inv-toolbar,.inv-expand,.inv-charts{display:none!important}.inv-print-title{display:block}.kx-shell{width:100%;margin:0}.kx-table thead th{position:static!important}.kx-table-wrap{overflow:visible}.kx-table{font-size:10px}.kx-table tbody td,.kx-table thead th{padding:7px}.inv-mobile-cards{display:none!important}@page{size:A4 landscape;margin:9mm}}
    </style>
</head>
<body class="kx-page">
<main class="kx-shell">
    <?php include 'header.php'; ?>
    <div class="inv-print-title"><h1>Inventory Analytics Dashboard</h1><p><?php echo inv_h($fromInput); ?> to <?php echo inv_h($toInput); ?></p></div>
    <section class="kx-hero">
        <div>
            <p class="kx-eyebrow">ERP Inventory Intelligence</p>
            <h1>Inventory Analytics Dashboard</h1>
            <p>Stock ledger, recipe consumption, current balance, valuation, variance, and health indicators.</p>
        </div>
        <div class="kx-hero-badge"><span>Work Period</span><strong>06:00 to next day 06:00</strong></div>
    </section>

    <?php foreach ($errors as $error) { ?><section class="kx-alert kx-alert-error"><?php echo inv_h($error); ?></section><?php } ?>
    <section class="kx-alert">
        DB profile: InventoryItems <?php echo inv_h(inv_value($profileRow, 'InventoryItems')); ?>, InventoryTransactions <?php echo inv_h(inv_value($profileRow, 'InventoryTransactions')); ?>, Recipes <?php echo inv_h(inv_value($profileRow, 'Recipes')); ?>, RecipeItems <?php echo inv_h(inv_value($profileRow, 'RecipeItems')); ?>. Current balance is calculated from ledger history, not from empty snapshot tables.
    </section>

    <section class="kx-panel kx-filter-panel inv-filter">
        <form method="get" action="inventoryDaily.php">
            <div class="inv-grid">
                <div><label>From Date</label><input class="form-control" type="date" name="fromDate" value="<?php echo inv_h($fromInput); ?>"></div>
                <div><label>To Date</label><input class="form-control" type="date" name="toDate" value="<?php echo inv_h($toInput); ?>"></div>
                <div><label>Warehouse</label><select class="form-control" name="warehouseId"><option value="">All Warehouses</option><?php foreach($warehouses as $w){ ?><option value="<?php echo (int)$w['Id']; ?>" <?php echo $warehouseId===(int)$w['Id']?'selected':''; ?>><?php echo inv_h($w['Name']); ?></option><?php } ?></select></div>
                <div><label>Group</label><select class="form-control" name="groupCode"><option value="">All Groups</option><?php foreach($groups as $g){ ?><option value="<?php echo inv_h($g['GroupCode']); ?>" <?php echo $groupCode===$g['GroupCode']?'selected':''; ?>><?php echo inv_h($g['GroupCode']); ?></option><?php } ?></select></div>
                <div><label>Item</label><select class="form-control" name="itemId"><option value="">All Items</option><?php foreach($items as $it){ ?><option value="<?php echo (int)$it['Id']; ?>" <?php echo $itemId===(int)$it['Id']?'selected':''; ?>><?php echo inv_h($it['Name']); ?></option><?php } ?></select></div>
                <div><label>Movement</label><select class="form-control" name="movementType"><option value="">All Movements</option><?php foreach(array('Purchase','Purchase Return','Recipe Consumption','Production','Transfer In','Transfer Out','Waste','Adjustment +','Adjustment -','Inventory Movement') as $m){ ?><option value="<?php echo inv_h($m); ?>" <?php echo $movementType===$m?'selected':''; ?>><?php echo inv_h($m); ?></option><?php } ?></select></div>
                <div><label>Search</label><input class="form-control" type="search" name="search" id="invLiveSearch" value="<?php echo inv_h($search); ?>" placeholder="Item, code, group"></div>
                <div><label>Low Stock Qty</label><input class="form-control" type="number" step="0.001" name="lowStockThreshold" value="<?php echo inv_h($lowStockThreshold); ?>"></div>
                <div><label><input type="checkbox" name="showZeroBalance" value="1" <?php echo $showZeroBalance?'checked':''; ?>> Show Zero Balance</label><label><input type="checkbox" name="showZeroMovement" value="1" <?php echo $showZeroMovement?'checked':''; ?>> Show Zero Movement</label></div>
                <div><label><input type="checkbox" name="showNegativeOnly" value="1" <?php echo $showNegativeOnly?'checked':''; ?>> Negative Only</label><label><input type="checkbox" name="showLowStock" value="1" <?php echo $showLowStock?'checked':''; ?>> Low Stock</label></div>
                <div class="inv-toolbar">
                    <button class="inv-btn inv-blue" type="submit">Search</button>
                    <a class="inv-btn inv-soft" href="inventoryDaily.php">Reset</a>
                    <a class="inv-btn inv-green" href="?<?php echo inv_h(http_build_query(array_merge($_GET, array('export'=>'excel')))); ?>">Excel</a>
                    <button class="inv-btn inv-red" type="button" onclick="window.print()">PDF / Print</button>
                </div>
            </div>
        </form>
    </section>

    <section class="inv-kpis">
        <div class="inv-kpi"><span>Total Items</span><strong><?php echo number_format($summary['total_items']); ?></strong></div>
        <div class="inv-kpi"><span>Items Used</span><strong><?php echo number_format($summary['items_used']); ?></strong></div>
        <div class="inv-kpi"><span>No Movement</span><strong><?php echo number_format($summary['no_movement']); ?></strong></div>
        <div class="inv-kpi"><span>Opening Value</span><strong><?php echo inv_money($summary['opening_value']); ?></strong></div>
        <div class="inv-kpi"><span>Closing Value</span><strong><?php echo inv_money($summary['closing_value']); ?></strong></div>
        <div class="inv-kpi"><span>Current Value</span><strong><?php echo inv_money($summary['current_value']); ?></strong></div>
        <div class="inv-kpi"><span>Usage Qty</span><strong><?php echo inv_num($summary['usage_qty']); ?></strong></div>
        <div class="inv-kpi"><span>Usage Cost</span><strong><?php echo inv_money($summary['usage_cost']); ?></strong></div>
        <div class="inv-kpi warn"><span>Negative Stock</span><strong><?php echo number_format($summary['negative_stock']); ?></strong></div>
        <div class="inv-kpi"><span>Low Stock</span><strong><?php echo number_format($summary['low_stock']); ?></strong></div>
        <div class="inv-kpi"><span>Zero Balance</span><strong><?php echo number_format($summary['zero_balance']); ?></strong></div>
        <div class="inv-kpi"><span>Purchases</span><strong><?php echo inv_num($summary['purchases']); ?></strong></div>
    </section>

    <section class="inv-charts inv-lite-charts">
        <div class="inv-chart">
            <h3>Top 10 Usage</h3>
            <?php $maxUsage = empty($chartUsage) ? 0 : max($chartUsage); ?>
            <?php if ($maxUsage <= 0) { ?><div class="kx-empty-row">No usage for selected period.</div><?php } ?>
            <?php foreach ($chartLabels as $idx => $label) { $val = $chartUsage[$idx]; $pct = $maxUsage > 0 ? max(3, min(100, ($val / $maxUsage) * 100)) : 0; ?>
                <div class="inv-lite-bar"><div class="inv-lite-label"><strong><?php echo inv_h($label); ?></strong><span><?php echo inv_num($val); ?></span></div><div class="inv-lite-track"><div style="width:<?php echo $pct; ?>%"></div></div></div>
            <?php } ?>
        </div>
        <div class="inv-chart">
            <h3>Stock In vs Stock Out</h3>
            <?php $flowMax = max(1, $chartIn, $chartOut); ?>
            <div class="inv-lite-bar"><div class="inv-lite-label"><strong>Stock In</strong><span><?php echo inv_num($chartIn); ?></span></div><div class="inv-lite-track"><div class="in" style="width:<?php echo min(100, ($chartIn / $flowMax) * 100); ?>%"></div></div></div>
            <div class="inv-lite-bar"><div class="inv-lite-label"><strong>Stock Out</strong><span><?php echo inv_num($chartOut); ?></span></div><div class="inv-lite-track"><div class="out" style="width:<?php echo min(100, ($chartOut / $flowMax) * 100); ?>%"></div></div></div>
        </div>
        <div class="inv-chart">
            <h3>Inventory Health</h3>
            <div class="inv-health"><div><span>Negative</span><strong><?php echo $chartNegative; ?></strong></div><div><span>Zero</span><strong><?php echo $chartZero; ?></strong></div><div><span>Low</span><strong><?php echo $summary['low_stock']; ?></strong></div></div>
            <p class="inv-health-note">Lightweight local charts. No external scripts loaded.</p>
        </div>
    </section>

    <section class="kx-panel kx-table-panel">
        <div class="kx-table-toolbar">
            <div><h2>ERP Stock Position</h2><p><?php echo count($rows); ?> inventory rows. Click + to open stock ledger.</p></div>
            <div class="kx-search-wrap"><input id="invTableSearch" type="search" placeholder="Live search"></div>
        </div>
        <div class="kx-table-wrap">
            <table class="kx-table" id="inventoryTable">
                <thead><tr><th></th><th>Item Code</th><th>Inventory Item</th><th>Group</th><th>Warehouse</th><th>Unit</th><th>Opening</th><th>Purchase</th><th>Production</th><th>Transfer In</th><th>Recipe Usage</th><th>Direct Usage</th><th>Waste</th><th>Adj +</th><th>Adj -</th><th>Transfer Out</th><th>Closing</th><th>Current</th><th>Unit Cost</th><th>Usage Cost</th><th>Current Value</th><th>Variance</th><th>Moves</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row) { $itemKey=(string)$row['ItemCode']; $status='OK'; $badge='inv-bad-ok'; if ((float)$row['CurrentBalance'] < 0) { $status='Negative'; $badge='inv-bad-neg'; } elseif ((float)$row['CurrentBalance'] == 0) { $status='Zero'; $badge='inv-bad-zero'; } ?>
                    <tr class="inv-main-row" data-search="<?php echo inv_h(strtolower($row['ItemCode'].' '.$row['ItemName'].' '.$row['InventoryGroup'].' '.$row['Warehouse'])); ?>">
                        <td><button type="button" class="inv-expand" data-target="ledger-<?php echo (int)$row['ItemCode']; ?>">+</button></td>
                        <td><?php echo (int)$row['ItemCode']; ?></td><td class="kx-item-name"><?php echo inv_h($row['ItemName']); ?></td><td><?php echo inv_h($row['InventoryGroup']); ?></td><td><?php echo inv_h($row['Warehouse']); ?></td><td><?php echo inv_h($row['BaseUnit']); ?></td>
                        <td class="kx-num"><?php echo inv_num($row['OpeningQty']); ?></td><td class="kx-num"><?php echo inv_num($row['PurchaseQty']); ?></td><td class="kx-num"><?php echo inv_num($row['ProductionQty']); ?></td><td class="kx-num"><?php echo inv_num($row['TransferInQty']); ?></td><td class="kx-num"><?php echo inv_num($row['RecipeUsageQty']); ?></td><td class="kx-num"><?php echo inv_num($row['DirectUsageQty']); ?></td><td class="kx-num"><?php echo inv_num($row['WasteQty']); ?></td><td class="kx-num"><?php echo inv_num($row['AdjustmentPlusQty']); ?></td><td class="kx-num"><?php echo inv_num($row['AdjustmentMinusQty']); ?></td><td class="kx-num"><?php echo inv_num($row['TransferOutQty']); ?></td><td class="kx-num"><?php echo inv_num($row['ClosingBalance']); ?></td><td class="kx-num"><?php echo inv_num($row['CurrentBalance']); ?></td><td class="kx-money"><?php echo inv_money($row['UnitCost']); ?></td><td class="kx-money"><?php echo inv_money($row['UsageCost']); ?></td><td class="kx-money"><?php echo inv_money($row['CurrentStockValue']); ?></td><td class="kx-num"><?php echo inv_num($row['Variance']); ?></td><td class="kx-num"><?php echo (int)$row['MovementCount']; ?></td><td><span class="inv-badge <?php echo $badge; ?>"><?php echo $status; ?></span></td>
                    </tr>
                    <tr class="inv-ledger" id="ledger-<?php echo (int)$row['ItemCode']; ?>"><td colspan="24"><div class="inv-ledger-box"><strong>Stock Ledger</strong><table><thead><tr><th>Date / Time</th><th>Type</th><th>Reference</th><th>Warehouse</th><th>Qty In</th><th>Qty Out</th><th>Running Balance</th><th>Source</th><th>Document</th></tr></thead><tbody>
                    <?php if (empty($ledgerByItem[$itemKey])) { ?><tr><td colspan="9">No period ledger movements.</td></tr><?php } else { foreach ($ledgerByItem[$itemKey] as $mv) { ?><tr><td><?php echo inv_h($mv['Date'] instanceof DateTime ? $mv['Date']->format('Y-m-d H:i:s') : $mv['Date']); ?></td><td><?php echo inv_h($mv['MovementClass']); ?></td><td><?php echo inv_h($mv['Reference']); ?></td><td><?php echo inv_h($mv['Warehouse']); ?></td><td class="kx-num"><?php echo inv_num($mv['QtyIn']); ?></td><td class="kx-num"><?php echo inv_num($mv['QtyOut']); ?></td><td class="kx-num"><?php echo inv_num($mv['RunningBalance']); ?></td><td><?php echo inv_h($mv['SourceTable'].' #'.$mv['SourceId']); ?></td><td><?php echo inv_h($mv['DocumentText']); ?></td></tr><?php }} ?>
                    </tbody></table></div></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="inv-mobile-cards">
            <?php foreach ($rows as $row) { ?>
                <div class="inv-card"><h3><?php echo inv_h($row['ItemName']); ?></h3><dl><div><dt>Opening</dt><dd><?php echo inv_num($row['OpeningQty']); ?></dd></div><div><dt>Usage</dt><dd><?php echo inv_num((float)$row['RecipeUsageQty'] + (float)$row['DirectUsageQty']); ?></dd></div><div><dt>Closing</dt><dd><?php echo inv_num($row['ClosingBalance']); ?></dd></div><div><dt>Current</dt><dd><?php echo inv_num($row['CurrentBalance']); ?></dd></div><div><dt>Value</dt><dd><?php echo inv_money($row['CurrentStockValue']); ?></dd></div><div><dt>Moves</dt><dd><?php echo (int)$row['MovementCount']; ?></dd></div></dl></div>
            <?php } ?>
        </div>
    </section>
</main>
<script>

function invFilterRows(){
    var q=(document.getElementById('invTableSearch').value||'').toLowerCase();
    document.querySelectorAll('.inv-main-row').forEach(function(row){
        var show=(row.getAttribute('data-search')||'').indexOf(q)>-1;
        row.style.display=show?'':'none';
        var next=row.nextElementSibling;
        if(!show && next && next.classList.contains('inv-ledger')) next.style.display='none';
    });
}
document.addEventListener('DOMContentLoaded',function(){
document.querySelectorAll('.inv-expand').forEach(function(btn){
        btn.addEventListener('click',function(){
            var row=document.getElementById(btn.getAttribute('data-target'));
            var open=row.classList.toggle('open');
            row.style.display=open?'table-row':'none';
            btn.textContent=open?'-':'+';
        });
    });
    var search=document.getElementById('invTableSearch');
    if(search) search.addEventListener('input',invFilterRows);
});
</script>
</body>
</html>


