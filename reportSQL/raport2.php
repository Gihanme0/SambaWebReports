<?php
$data = isset($_POST["texttoshow"]) ? trim($_POST["texttoshow"]) : '';
$timestamp = strtotime($data);

if (!$timestamp) {
    echo "<tr><td colspan='3' class='kx-empty-row'>Invalid date selected.</td></tr>";
    return;
}

$dataInceput = date('Y-m-d', $timestamp);
$dataFinal = date('Y-m-d', strtotime('+1 day', $timestamp));

$sql = "
    DECLARE @dat_WorkPeriod_Beg DATETIME = '" . $dataInceput . "T06:00:00.000'
    DECLARE @dat_WorkPeriod_End DATETIME = '" . $dataFinal . "T06:00:00.000'

    SELECT
        [GroupCode] AS [Group],
        [MenuItemName] AS [Item],
        CONVERT(INT, SUM([Quantity])) AS [Qty],
        [Price] * SUM([Quantity]) AS [TAmt]
    FROM [Orders] o
    LEFT JOIN [MenuItems] m ON m.[Id] = o.[MenuItemId]
    WHERE [CreatedDateTime] >= @dat_WorkPeriod_Beg
      AND [CreatedDateTime] <= @dat_WorkPeriod_End
      AND DecreaseInventory = 1
      AND CalculatePrice <> 0
    GROUP BY m.[GroupCode], [MenuItemName], [Price]
    ORDER BY m.[GroupCode], [MenuItemName]
";

$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    echo "<tr><td colspan='3' class='kx-empty-row'>Report SQL error. Please check database connection.</td></tr>";
    echo "<tr class='kx-debug-row'><td colspan='3'><pre>" . htmlspecialchars(print_r(sqlsrv_errors(), true)) . "</pre></td></tr>";
    return;
}

$totalAmount = 0;
$totalQty = 0;
$itemCount = 0;

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
    $group = htmlspecialchars((string)$row[0], ENT_QUOTES, 'UTF-8');
    $item = htmlspecialchars((string)$row[1], ENT_QUOTES, 'UTF-8');
    $qty = (int)$row[2];
    $amt = (float)$row[3];

    $totalAmount += $amt;
    $totalQty += $qty;
    $itemCount++;

    echo "<tr class='kx-item-row' data-group='{$group}' data-qty='{$qty}' data-amount='{$amt}'>
            <td>
                <div class='kx-item-name'>{$item}</div>
                <div class='kx-item-group'>{$group}</div>
            </td>
            <td class='kx-num'>" . number_format($qty) . "</td>
            <td class='kx-money'>" . number_format($amt, 2) . "</td>
          </tr>";
}

if ($itemCount === 0) {
    echo "<tr><td colspan='3' class='kx-empty-row'>No sales found for selected date.</td></tr>";
}

sqlsrv_free_stmt($stmt);
?>
