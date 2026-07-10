<?php
$datai = $_POST["startdate"];
$dataf = $_POST["enddate"];
$dataInceput = date('Y-m-d', strtotime($datai));
$dataFinal = date('Y-m-d', strtotime($dataf));
$monthC = date("m", strtotime($dataInceput));
$yearC = date("y", strtotime($dataInceput));
?>

<div class="infoTitlu">
    <div class="titluRaport">Monthly Sales</div>
    <div class="subtitluRaport">S.C. RockCaffe Grecu SRL-D</div>
</div>
<div class="nrDoc">
    <div class="nrRaport">Doc. No.</div>
    <div class="nrEfectivRaport">VZ<?php echo $monthC . $yearC; ?></div>
</div>
<div class="infoFirma">
    <div class="CUI"><b>VAT ID:</b> 33873931</div>
    <div class="nrregcom"><b>Trade Registry:</b> J17/1297/2014</div>
</div>
<div class="dataCrt">Sales Made Between Date: <?php echo $dataInceput; ?> Time: 6:00 and Date: <?php echo $dataFinal; ?> Time: 6:00 are Displayed</div>

<?php
$sql1 = "
DECLARE @dat_WorkPeriod_Beg DATETIME = '{$dataInceput}T06:00:00.000'
DECLARE @dat_WorkPeriod_End DATETIME = '{$dataFinal}T06:00:00.000'

SELECT [MenuItemName], m.[Id]
FROM [Orders] o
LEFT JOIN [MenuItems] m ON m.[Id] = o.[MenuItemId]
WHERE [CreatedDateTime] >= @dat_WorkPeriod_Beg
  AND [CreatedDateTime] <= @dat_WorkPeriod_End
  AND DecreaseInventory = 1
  AND CalculatePrice <> 0
GROUP BY [MenuItemName], m.[Id]
ORDER BY [MenuItemName]
";

$stmt1 = sqlsrv_query($conn, $sql1);
if ($stmt1 === false) {
    die(print_r(sqlsrv_errors(), true));
}

$produse = array();
while ($row1 = sqlsrv_fetch_array($stmt1, SQLSRV_FETCH_NUMERIC)) {
    $produse[] = $row1;
}

$sql3 = "
SELECT SUM([Quantity]) AS Cantitate, SUM([TotalPrice]) AS pret, [InventoryItem_Id]
FROM [InventoryTransactions]
GROUP BY [InventoryItem_Id]
ORDER BY [InventoryItem_Id]
";

$stmt3 = sqlsrv_query($conn, $sql3);
if ($stmt3 === false) {
    die(print_r(sqlsrv_errors(), true));
}

$relux = array();
while ($row3 = sqlsrv_fetch_array($stmt3, SQLSRV_FETCH_NUMERIC)) {
    $relux[] = $row3;
}

function multidimensional_search($parents, $searched) {
    if (empty($searched) || empty($parents)) return false;
    foreach ($parents as $key => $value) {
        $exists = true;
        foreach ($searched as $skey => $svalue) {
            $exists = ($exists && isset($parents[$key][$skey]) && $parents[$key][$skey] == $svalue);
        }
        if ($exists) return $key;
    }
    return false;
}

function sumArray($array, $min, $max) {
    $sum = 0;
    foreach ($array as $k => $a) {
        if ($k >= $min && $k <= $max) {
            $sum += $a;
        }
    }
    return $sum;
}
?>

<table id='sum_table_consummx'>
    <tr class="randTitlux">
        <th>Product Name</th>
        <th>Code</th>
        <th>Unit</th>
        <th>Qty.</th>
        <th>Unit Price excl VAT</th>
        <th>Unit Price incl VAT</th>
        <th>Unit Sale Price</th>
        <th>Total Price incl VAT</th>
        <th>Total Sale Amount</th>
        <th>Profit</th>
    </tr>

<?php
$echoo = array();
$echoo2 = array();
$pretvanzarearr = array();

foreach ($produse as $value) {
    $idprod = $value[1];
    $numeprod = $value[0];

    $sql = "
    DECLARE @dat_WorkPeriod_Beg DATETIME = '{$dataInceput}T06:00:00.000'
    DECLARE @dat_WorkPeriod_End DATETIME = '{$dataFinal}T06:00:00.000'

    SELECT [GroupCode] AS [Group], [MenuItemName] AS [Item], CONVERT(INT, SUM([Quantity])) AS [Qty],
           [Price]*SUM([Quantity]) AS [TAmt], m.[Id], [PortionName], [Price]
    FROM [Orders] o
    LEFT JOIN [MenuItems] m ON m.[Id] = o.[MenuItemId]
    WHERE m.[Id] = {$idprod}
      AND [CreatedDateTime] >= @dat_WorkPeriod_Beg
      AND [CreatedDateTime] <= @dat_WorkPeriod_End
      AND DecreaseInventory = 1
      AND CalculatePrice <> 0
    GROUP BY m.[GroupCode], [MenuItemName], [Price], m.[Id], [PortionName]
    ORDER BY [MenuItemName]
    ";

    $numeprod2 = str_replace("'", "''", $numeprod);

    $sql2 = "
    SELECT Recipes.Name, Recipes.Id, 
           RecipeItems.MenuItemPortion_Id, 
           RecipeItems.InventoryItem_Id, 
           RecipeItems.MenuItemPortion_Id, 
           RecipeItems.Quantity, 
           InventoryItems.BaseUnit, 
           InventoryItems.Name AS Expr1, 
           InventoryItems.DefaultBaseUnitCost, 
           InventoryItems.DefaultTransactionUnitCost
    FROM InventoryItems
    INNER JOIN RecipeItems ON InventoryItems.Id = RecipeItems.InventoryItem_Id
    INNER JOIN Recipes ON RecipeItems.RecipeId = Recipes.Id
    WHERE Recipes.Name = '{$numeprod2}'
    ORDER BY Recipes.Name
    ";

    $stmt2 = sqlsrv_query($conn, $sql2, array(), array("Scrollable" => 'static'));
    if ($stmt2 === false) die(print_r(sqlsrv_errors(), true));

    $stmt = sqlsrv_query($conn, $sql, array(), array("Scrollable" => 'static'));
    if ($stmt === false) die(print_r(sqlsrv_errors(), true));

    $relu = array();
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
        echo "<tr><th class='center'>{$row[1]}</th><th class='center'>ROCK{$row[4]}</th><th class='center'>{$row[5]}</th><th class='center'>{$row[2]}</th>";
        $relu[] = $row;
        $pretvanzarearr[] = $row[3];
    }

    $dimarray = sizeof($relu);
    if ($dimarray == 0) continue;

    $crtArray = $dimarray - 1;
    $cantitate = $relu[$crtArray][2];
    $pretpeunitate = $relu[$crtArray][6];
    $pretpxtotal = $relu[$crtArray][3];

    $priceTOT = array();      
    $priceTOTTVA = array();  
    $priceTOTsg = array();    
    $priceTOTTVAsg = array(); 

    while ($row2 = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_NUMERIC)) {
        $findarray = multidimensional_search($relux, array('2' => $row2[3]));
        $pretunit = $findarray ? $relux[$findarray][1] / $relux[$findarray][0] : $row2[9];
        $pretRETcuTVA = $pretunit * $row2[8] + $pretunit;

        $priceTOT[] = $pretunit * $row2[5] * $cantitate;
        $priceTOTTVA[] = $pretRETcuTVA * $row2[5] * $cantitate;
        $priceTOTsg[] = $pretunit * $row2[5];
        $priceTOTTVAsg[] = $pretRETcuTVA * $row2[5];
    }

    $row_count = sqlsrv_num_rows($stmt2);
    $dimarray2 = sizeof($priceTOT);
    $crtArray2 = $dimarray2 - 1;
    $RandMin = ($dimarray2 == $row_count) ? 0 : max(0, $dimarray2 - $row_count);
    $RandMax = $dimarray2;

    $pricedivision = sumArray($priceTOTTVA, $RandMin, $RandMax);
    $rataprofit = ($pricedivision != 0) ? ($pretpeunitate * $cantitate / $pricedivision) * 100 : 1;

    echo "<th class='totalul'>Rs. " . number_format(sumArray($priceTOTsg, $RandMin, $RandMax), 2) . "</th>";
    echo "<th class='totalul'>Rs. " . number_format(sumArray($priceTOTTVAsg, $RandMin, $RandMax), 2) . "</th>";
    echo "<th>Rs. " . number_format($pretpeunitate, 2) . "</th>";
    echo "<th class='totalul'>Rs. " . number_format(sumArray($priceTOTTVA, $RandMin, $RandMax), 2) . "</th>";
    echo "<th>Rs. " . number_format($pretpeunitate * $cantitate, 2) . "</th>";
    echo "<th>" . number_format($rataprofit, 2) . "%</th></tr>";

    $echoo[] = sumArray($priceTOT, $RandMin, $RandMax);
    $echoo2[] = sumArray($priceTOTTVA, $RandMin, $RandMax);
    sqlsrv_free_stmt($stmt);
}
?>

</table>

<table class="totalTabelVanzari">
<tr class="totalRand">
    <th class="tablettile ep1" rowspan="2">Total Entry Price</th>
    <th class="th1TOTAL2">(excl. VAT)</th>
    <th colspan="5" class="th1TOTAL1 totalulgen"><?php echo 'Rs. ' . number_format(array_sum($echoo), 2); ?></th>
</tr>
<tr class="totalRand">
    <th class="th1TOTAL2">(incl. VAT)</th>
    <th colspan="5" class="th1TOTAL1 totalulgen"><?php echo 'Rs. ' . number_format(array_sum($echoo2), 2); ?></th>
</tr>
<tr class="totalRand">
    <th class="tablettile" rowspan="1">Total Sale Price</th>
    <th class="th1TOTAL1">(excl. VAT)</th>
    <th colspan="5" class="th1TOTAL1 totalulgen"><?php echo 'Rs. ' . number_format(array_sum($pretvanzarearr), 2); ?></th>
</tr>
</table>
