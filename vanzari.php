<?php
/*
 * Daily Sales report.
 * Purpose: one business-day Kynix dashboard for sales, payments, ticket count, service charge, discounts, and hourly sales.
 * Data sources: Orders, MenuItems, Payments, PaymentTypes, Tickets, Calculations, and CalculationTypes.
 * Date cutoff: SambaPOS work day is reported as 06:00 inclusive to next-day 06:00 exclusive.
 * Maintenance: keep SQL parameterized, escape output, and verify CalculationAmount before changing financial totals.
 */
require_once __DIR__ . '/auth/auth.php';
auth_require_permission('daily_sales.view');
require_once __DIR__ . '/config.php';
$reportName = "Daily Sales " . $BusinessName;
$canExportExcel = auth_has_permission('exports.excel');
$canExportPdf = auth_has_permission('exports.pdf');
$canExportPrint = auth_has_permission('exports.print');

$selectedDate = isset($_POST['texttoshow']) ? trim($_POST['texttoshow']) : '';
$hasReport = ($selectedDate !== '');
$selectedTs = $hasReport ? strtotime($selectedDate) : false;

$periodStartSql = '';
$periodEndSql = '';
$displayStart = '';
$displayEnd = '';
$summary = array(
    'item_sales' => 0, 'total_qty' => 0, 'item_count' => 0,
    'payment_total' => 0, 'ticket_count' => 0, 'avg_bill' => 0,
    'cash' => 0, 'card' => 0, 'credit' => 0, 'advance' => 0, 'voucher' => 0, 'other' => 0,
    'service_charge' => 0, 'discount' => 0, 'calculation_total' => 0, 'net_after_calc' => 0
);
$paymentRows = array();
$itemRows = array();
$calculationRows = array();
$hourRows = array();
$errors = array();

/** Format a report amount using the project currency prefix. */
function kx_money($amount) { return 'Rs. ' . number_format((float)$amount, 2); }
/** Escape dynamic report output for HTML. */
function kx_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function kx_payment_bucket($name) {
    $n = strtolower(trim((string)$name));
    if (strpos($n, 'cash') !== false) return 'cash';
    if (strpos($n, 'card') !== false || strpos($n, 'visa') !== false || strpos($n, 'master') !== false) return 'card';
    if (strpos($n, 'advance') !== false) return 'advance';
    if (strpos($n, 'voucher') !== false) return 'voucher';
    if (strpos($n, 'credit') !== false || strpos($n, 'custom') !== false || strpos($n, 'account') !== false) return 'credit';
    return 'other';
}
function kx_calc_bucket($name, $amount) {
    $n = strtolower(trim((string)$name));
    if (strpos($n, 'service') !== false || strpos($n, 'sc') === 0 || strpos($n, 'service charge') !== false) return 'service_charge';
    if (strpos($n, 'discount') !== false || strpos($n, 'disc') !== false || (float)$amount < 0) return 'discount';
    return 'other';
}

if ($hasReport && $selectedTs) {
    $displayStart = date('Y-m-d', $selectedTs);
    $displayEnd = date('Y-m-d', $selectedTs);
    $periodStartSql = date('Y-m-d', $selectedTs) . "T06:00:00.000";
    $periodEndSql = date('Y-m-d', strtotime('+1 day', $selectedTs)) . "T06:00:00.000";

    $itemSql = "
        SELECT
            ISNULL(m.[GroupCode], 'Uncategorized') AS [GroupName],
            o.[MenuItemName] AS [ItemName],
            SUM(CAST(o.[Quantity] AS DECIMAL(18,3))) AS [Qty],
            SUM(CAST(o.[Price] AS DECIMAL(18,2)) * CAST(o.[Quantity] AS DECIMAL(18,3))) AS [Amount]
        FROM [Orders] o
        LEFT JOIN [MenuItems] m ON m.[Id] = o.[MenuItemId]
        WHERE o.[CreatedDateTime] >= ?
          AND o.[CreatedDateTime] < ?
          AND o.[CalculatePrice] <> 0
        GROUP BY ISNULL(m.[GroupCode], 'Uncategorized'), o.[MenuItemName]
        ORDER BY ISNULL(m.[GroupCode], 'Uncategorized'), o.[MenuItemName]
    ";
    $stmt = sqlsrv_query($conn, $itemSql, array($periodStartSql, $periodEndSql));
    if ($stmt === false) {
        $errors[] = 'Item sales data is unavailable.';
    } else {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $qty = isset($r['Qty']) ? (float)$r['Qty'] : 0;
            $amt = isset($r['Amount']) ? (float)$r['Amount'] : 0;
            $itemRows[] = array('group' => $r['GroupName'], 'item' => $r['ItemName'], 'qty' => $qty, 'amount' => $amt);
            $summary['total_qty'] += $qty;
            $summary['item_sales'] += $amt;
        }
        sqlsrv_free_stmt($stmt);
        $summary['item_count'] = count($itemRows);
    }

    $paySql = "
        SELECT ISNULL(p.[Name], pt.[Name]) AS PaymentName, SUM(CAST(p.[Amount] AS DECIMAL(18,2))) AS TotalAmount
        FROM [Payments] p
        LEFT JOIN [PaymentTypes] pt ON pt.[Id] = p.[PaymentTypeId]
        WHERE p.[Date] >= ? AND p.[Date] < ?
        GROUP BY ISNULL(p.[Name], pt.[Name])
        ORDER BY ISNULL(p.[Name], pt.[Name])
    ";
    $stmt = sqlsrv_query($conn, $paySql, array($periodStartSql, $periodEndSql));
    if ($stmt === false) {
        $errors[] = 'Payment data is unavailable.';
    } else {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $name = isset($r['PaymentName']) ? $r['PaymentName'] : 'Other';
            $amt = isset($r['TotalAmount']) ? (float)$r['TotalAmount'] : 0;
            $paymentRows[] = array('name' => $name, 'amount' => $amt);
            $summary['payment_total'] += $amt;
            $bucket = kx_payment_bucket($name);
            $summary[$bucket] += $amt;
        }
        sqlsrv_free_stmt($stmt);
    }

    $ticketSql = "
        SELECT COUNT(DISTINCT p.[TicketId]) AS TicketCount
        FROM [Payments] p
        WHERE p.[Date] >= ? AND p.[Date] < ?
    ";
    $stmt = sqlsrv_query($conn, $ticketSql, array($periodStartSql, $periodEndSql));
    if ($stmt !== false) {
        $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $summary['ticket_count'] = isset($r['TicketCount']) ? (int)$r['TicketCount'] : 0;
        sqlsrv_free_stmt($stmt);
    }
    $summary['avg_bill'] = $summary['ticket_count'] > 0 ? $summary['payment_total'] / $summary['ticket_count'] : 0;

    $calcSql = "
        SELECT ISNULL(ct.[Name], c.[Name]) AS CalculationName, SUM(CAST(c.[CalculationAmount] AS DECIMAL(18,2))) AS TotalAmount
        FROM [Calculations] c
        LEFT JOIN [CalculationTypes] ct ON ct.[Id] = c.[CalculationTypeId]
        LEFT JOIN [Tickets] t ON t.[Id] = c.[TicketId]
        WHERE t.[Date] >= ? AND t.[Date] < ?
        GROUP BY ISNULL(ct.[Name], c.[Name])
        ORDER BY ISNULL(ct.[Name], c.[Name])
    ";
    $stmt = sqlsrv_query($conn, $calcSql, array($periodStartSql, $periodEndSql));
    if ($stmt === false) {
        $errors[] = 'Calculation SQL warning: Service charge/discount table may be empty or columns may differ.';
    } else {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $name = isset($r['CalculationName']) ? $r['CalculationName'] : 'Other Calculation';
            $amt = isset($r['TotalAmount']) ? (float)$r['TotalAmount'] : 0;
            $calculationRows[] = array('name' => $name, 'amount' => $amt);
            $summary['calculation_total'] += $amt;
            $bucket = kx_calc_bucket($name, $amt);
            if ($bucket === 'service_charge') {
                $summary['service_charge'] += abs($amt);
            } elseif ($bucket === 'discount') {
                $summary['discount'] += abs($amt);
            }
        }
        sqlsrv_free_stmt($stmt);
    }
    $summary['net_after_calc'] = $summary['item_sales'] + $summary['service_charge'] - $summary['discount'];

    $hourSql = "
        SELECT DATEPART(HOUR, p.[Date]) AS SalesHour, SUM(CAST(p.[Amount] AS DECIMAL(18,2))) AS TotalAmount
        FROM [Payments] p
        WHERE p.[Date] >= ? AND p.[Date] < ?
        GROUP BY DATEPART(HOUR, p.[Date])
        ORDER BY DATEPART(HOUR, p.[Date])
    ";
    $stmt = sqlsrv_query($conn, $hourSql, array($periodStartSql, $periodEndSql));
    if ($stmt !== false) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $hourRows[] = array('hour' => (int)$r['SalesHour'], 'amount' => (float)$r['TotalAmount']);
        }
        sqlsrv_free_stmt($stmt);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo kx_h($reportName); ?></title>
    <link href="./bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <link href="./css/bootstrap-datetimepicker.min.css" rel="stylesheet" media="screen">
    <link href="./css/vanzari.css" rel="stylesheet" media="screen">
    <link href="./css/print.css" rel="stylesheet" media="print">
    <script src="./jquery/jquery-1.8.3.min.js" charset="UTF-8"></script>
    <script src="./bootstrap/js/bootstrap.min.js"></script>
    <script src="./js/bootstrap-datetimepicker.js" charset="UTF-8"></script>
</head>
<body class="PaginaVanzari kx-page kx-daily-sales-page">
<?php include 'header.php'; ?>
<main class="kx-shell">
    <section class="kx-hero">
        <div>
            <div class="kx-eyebrow">Stage 2</div>
            <h1><?php echo kx_h($reportName); ?></h1>
            <p>Daily sales dashboard with items, payments, tickets, service charge, discounts and hourly sales.</p>
        </div>
        <div class="kx-hero-badge">
            <span>Generated</span>
            <strong id="kxGeneratedAt">--</strong>
            <button type="button" class="kx-theme-toggle" id="kxThemeToggle" title="Toggle dark mode">🌙</button>
        </div>
    </section>

    <section class="kx-panel kx-filter-panel">
        <form action="vanzari.php" method="post" role="form" id="kxReportForm">
            <div class="kx-filter-grid">
                <div class="kx-field">
                    <label>Select Date</label>
                    <div class="input-group date form_datetime_raport1 kx-date-picker" data-date-format="dd-mm-yyyy">
                        <input class="form-control" size="16" type="text" placeholder="Select sales date" name="texttoshow" value="<?php echo kx_h($selectedDate); ?>" readonly>
                        <span class="input-group-addon"><span class="glyphicon glyphicon-remove"></span></span>
                    </div>
                    <input type="hidden" id="dtp_input1">
                </div>
                <div class="kx-actions">
                    <button type="submit" class="kx-btn kx-btn-primary">Show Report</button>
                    <?php if ($hasReport) { ?>
                        <?php if ($canExportExcel) { ?>
                        <button type="button" class="kx-btn kx-btn-success" onclick="kxExportExcel('complete_sales_export','Daily_Sales_Report')">Excel</button>
                        <?php } ?>
                        <?php if ($canExportPdf) { ?>
                        <button type="button" class="kx-btn kx-btn-danger" onclick="kxExportPdf('Daily Sales Report','Daily_Sales_Report')">PDF</button>
                        <?php } ?>
                        <?php if ($canExportPrint) { ?>
                        <button type="button" class="kx-btn kx-btn-dark" onclick="window.print()">Print</button>
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>
        </form>
    </section>

    <?php if ($hasReport && $selectedTs) { ?>
        <section class="kx-alert">
            <span class="kx-dot"></span>
            Sales Made Between: <strong><?php echo kx_h($displayStart); ?> 06:00</strong> to <strong><?php echo kx_h(date('Y-m-d', strtotime('+1 day', $selectedTs))); ?> 06:00</strong>
        </section>

        <?php foreach ($errors as $e) { ?>
            <section class="kx-alert kx-alert-error"><?php echo kx_h($e); ?></section>
        <?php } ?>

        <section class="kx-stats kx-stats-extended kx-complete-stats">
            <div class="kx-stat-card"><div class="kx-stat-icon">💰</div><span>Item Gross Sales</span><strong><?php echo kx_money($summary['item_sales']); ?></strong></div>
            <div class="kx-stat-card"><div class="kx-stat-icon">➕</div><span>Service Charge</span><strong><?php echo kx_money($summary['service_charge']); ?></strong></div>
            <div class="kx-stat-card"><div class="kx-stat-icon">🏷️</div><span>Discount</span><strong><?php echo kx_money($summary['discount']); ?></strong></div>
            <div class="kx-stat-card"><div class="kx-stat-icon">📈</div><span>Net After Calc</span><strong><?php echo kx_money($summary['net_after_calc']); ?></strong></div>
            <div class="kx-stat-card kx-payment-card"><div class="kx-stat-icon">💵</div><span>Cash</span><strong><?php echo kx_money($summary['cash']); ?></strong></div>
            <div class="kx-stat-card kx-payment-card"><div class="kx-stat-icon">💳</div><span>Card</span><strong><?php echo kx_money($summary['card']); ?></strong></div>
            <div class="kx-stat-card kx-payment-card"><div class="kx-stat-icon">🏦</div><span>Credit / Account</span><strong><?php echo kx_money($summary['credit']); ?></strong></div>
            <div class="kx-stat-card kx-payment-card"><div class="kx-stat-icon">🎟️</div><span>Voucher</span><strong><?php echo kx_money($summary['voucher']); ?></strong></div>
            <div class="kx-stat-card"><div class="kx-stat-icon">🧾</div><span>Payment Total</span><strong><?php echo kx_money($summary['payment_total']); ?></strong></div>
            <div class="kx-stat-card"><div class="kx-stat-icon">🧾</div><span>Tickets</span><strong><?php echo number_format($summary['ticket_count']); ?></strong></div>
            <div class="kx-stat-card"><div class="kx-stat-icon">📊</div><span>Average Bill</span><strong><?php echo kx_money($summary['avg_bill']); ?></strong></div>
            <div class="kx-stat-card"><div class="kx-stat-icon">📦</div><span>Total Qty</span><strong><?php echo number_format($summary['total_qty'], 3); ?></strong></div>
        </section>

        <section class="kx-panel kx-chart-panel">
            <div class="kx-chart-header"><div><h2>Payment Breakdown</h2><p>Cash / Card / Credit / Voucher / Other</p></div><span>Payments</span></div>
            <div id="kxPaymentChart" class="kx-bar-chart">
                <?php
                $maxPay = max(1, $summary['payment_total']);
                foreach (array('cash'=>'Cash','card'=>'Card','credit'=>'Credit / Account','advance'=>'Advance','voucher'=>'Voucher','other'=>'Other') as $key => $label) {
                    $amt = $summary[$key];
                    if ($amt <= 0) continue;
                    $width = max(4, ($amt / $maxPay) * 100);
                    echo '<div class="kx-bar-row"><div class="kx-bar-info"><strong>'.kx_h($label).'</strong><span>'.kx_money($amt).'</span></div><div class="kx-bar-track"><div class="kx-bar-fill" style="width:'.$width.'%"></div></div></div>';
                }
                ?>
            </div>
        </section>

        <section class="kx-panel kx-chart-panel">
            <div class="kx-chart-header"><div><h2>Hourly Sales</h2><p>Payment total grouped by hour</p></div><span>Hours</span></div>
            <div class="kx-hour-grid">
                <?php if (empty($hourRows)) { echo '<div class="kx-chart-empty">No hourly payment data.</div>'; }
                $maxHour = 1; foreach ($hourRows as $h) { if ($h['amount'] > $maxHour) $maxHour = $h['amount']; }
                foreach ($hourRows as $h) {
                    $height = max(8, ($h['amount'] / $maxHour) * 120);
                    echo '<div class="kx-hour-col"><div class="kx-hour-bar" style="height:'.$height.'px" title="'.kx_money($h['amount']).'"></div><span>'.str_pad($h['hour'],2,'0',STR_PAD_LEFT).'</span></div>';
                }
                ?>
            </div>
        </section>

        <section class="kx-panel kx-table-panel">
            <div class="kx-table-toolbar"><div><h2>Item Sales Details</h2><p><?php echo count($itemRows); ?> items</p></div><div class="kx-search-wrap"><input type="text" id="kxSearch" placeholder="Search item or group..." onkeyup="kxSearchTable()"></div></div>
            <div class="kx-table-wrap">
                <table id="sum_table_raport1" class="kx-table">
                    <thead><tr><th onclick="kxSortTable(0)">Product Name <span>↕</span></th><th>Group</th><th onclick="kxSortTable(2)" class="kx-num">Quantity <span>↕</span></th><th onclick="kxSortTable(3)" class="kx-money">Amount <span>↕</span></th></tr></thead>
                    <tbody id="kxTableBody">
                    <?php foreach ($itemRows as $row) { ?>
                        <tr class="kx-item-row" data-qty="<?php echo (float)$row['qty']; ?>" data-amount="<?php echo (float)$row['amount']; ?>">
                            <td><div class="kx-item-name"><?php echo kx_h($row['item']); ?></div></td>
                            <td><div class="kx-item-group"><?php echo kx_h($row['group']); ?></div></td>
                            <td class="kx-num"><?php echo number_format($row['qty'], 3); ?></td>
                            <td class="kx-money"><?php echo number_format($row['amount'], 2); ?></td>
                        </tr>
                    <?php } ?>
                    <tr id="kxNoRows" style="display:none"><td colspan="4" class="kx-empty-row">No matching items found.</td></tr>
                    </tbody>
                    <tfoot><tr><th colspan="2">Total</th><th id="kxFooterQty" class="kx-num"><?php echo number_format($summary['total_qty'], 3); ?></th><th id="kxFooterTotal" class="kx-money"><?php echo kx_money($summary['item_sales']); ?></th></tr></tfoot>
                </table>
            </div>
        </section>

        <section class="kx-panel kx-two-col-panel">
            <div class="kx-mini-table-card">
                <h2>Payment Types</h2>
                <table class="kx-table kx-mini-table" id="payment_table"><thead><tr><th>Payment Type</th><th class="kx-money">Amount</th></tr></thead><tbody>
                <?php if (empty($paymentRows)) echo '<tr><td colspan="2" class="kx-empty-row">No payments found.</td></tr>'; ?>
                <?php foreach ($paymentRows as $p) { echo '<tr><td>'.kx_h($p['name']).'</td><td class="kx-money">'.kx_money($p['amount']).'</td></tr>'; } ?>
                </tbody></table>
            </div>
            <div class="kx-mini-table-card">
                <h2>Calculations</h2>
                <table class="kx-table kx-mini-table" id="calculation_table"><thead><tr><th>Calculation</th><th class="kx-money">Amount</th></tr></thead><tbody>
                <?php if (empty($calculationRows)) echo '<tr><td colspan="2" class="kx-empty-row">No service charge / discount records found.</td></tr>'; ?>
                <?php foreach ($calculationRows as $c) { echo '<tr><td>'.kx_h($c['name']).'</td><td class="kx-money">'.kx_money($c['amount']).'</td></tr>'; } ?>
                </tbody></table>
            </div>
        </section>

        <div id="complete_sales_export" style="display:none">
            <h2><?php echo kx_h($reportName); ?></h2>
            <p><?php echo kx_h($displayStart); ?> 06:00 to <?php echo kx_h(date('Y-m-d', strtotime('+1 day', $selectedTs))); ?> 06:00</p>
            <table border="1"><tr><th>Metric</th><th>Value</th></tr>
                <?php foreach ($summary as $k=>$v) { echo '<tr><td>'.kx_h($k).'</td><td>'.kx_h($v).'</td></tr>'; } ?>
            </table>
            <br>
            <table border="1"><tr><th>Item</th><th>Group</th><th>Qty</th><th>Amount</th></tr>
                <?php foreach ($itemRows as $row) { echo '<tr><td>'.kx_h($row['item']).'</td><td>'.kx_h($row['group']).'</td><td>'.kx_h($row['qty']).'</td><td>'.kx_h($row['amount']).'</td></tr>'; } ?>
            </table>
            <br>
            <table border="1"><tr><th>Payment Type</th><th>Amount</th></tr>
                <?php foreach ($paymentRows as $p) { echo '<tr><td>'.kx_h($p['name']).'</td><td>'.kx_h($p['amount']).'</td></tr>'; } ?>
            </table>
            <br>
            <table border="1"><tr><th>Calculation</th><th>Amount</th></tr>
                <?php foreach ($calculationRows as $c) { echo '<tr><td>'.kx_h($c['name']).'</td><td>'.kx_h($c['amount']).'</td></tr>'; } ?>
            </table>
        </div>
    <?php } else { ?>
        <section class="kx-empty-state"><div class="kx-empty-icon">📊</div><h2>Select a date</h2><p>Daily sales report will show items, payments, tickets, service charge, discounts, charts and Excel export.</p></section>
    <?php } ?>
</main>
<footer class="kx-footer">Powered by Kynix Technologies</footer>
<script src="./js/reportJS.js" charset="UTF-8"></script>
<script>
(function(){
    var generated=document.getElementById('kxGeneratedAt'); if(generated) generated.innerHTML=new Date().toLocaleString();
    var menuBtn=document.getElementById('kxMenuBtn'), nav=document.getElementById('kxNav'); if(menuBtn&&nav){menuBtn.onclick=function(){nav.classList.toggle('open');};}
    var themeBtn=document.getElementById('kxThemeToggle'); var savedTheme=localStorage.getItem('kxTheme'); if(savedTheme==='dark') document.body.classList.add('kx-dark-mode');
    if(themeBtn){themeBtn.innerHTML=document.body.classList.contains('kx-dark-mode')?'☀️':'🌙'; themeBtn.onclick=function(){document.body.classList.toggle('kx-dark-mode'); var dark=document.body.classList.contains('kx-dark-mode'); localStorage.setItem('kxTheme',dark?'dark':'light'); themeBtn.innerHTML=dark?'☀️':'🌙';};}
})();
function kxParseNumber(value){if(!value)return 0; return parseFloat(String(value).replace(/Rs\./gi,'').replace(/,/g,'').trim())||0;}
function kxFormatMoney(value){return 'Rs. '+value.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function kxSearchTable(){var input=document.getElementById('kxSearch');var filter=input?input.value.toLowerCase():'';var rows=document.querySelectorAll('#kxTableBody tr.kx-item-row');var visible=0,qty=0,total=0;rows.forEach(function(row){var text=row.innerText.toLowerCase();var matched=text.indexOf(filter)>-1;row.style.display=matched?'':'none';if(matched){visible++;qty+=kxParseNumber(row.getAttribute('data-qty'));total+=kxParseNumber(row.getAttribute('data-amount'));}});var noRows=document.getElementById('kxNoRows');if(noRows)noRows.style.display=visible===0?'':'none';if(document.getElementById('kxFooterQty'))document.getElementById('kxFooterQty').innerHTML=qty.toLocaleString(undefined,{minimumFractionDigits:3,maximumFractionDigits:3});if(document.getElementById('kxFooterTotal'))document.getElementById('kxFooterTotal').innerHTML=kxFormatMoney(total);}
var kxSortDirection={};function kxSortTable(columnIndex){var tbody=document.getElementById('kxTableBody');if(!tbody)return;var rows=Array.prototype.slice.call(tbody.querySelectorAll('tr.kx-item-row'));var direction=kxSortDirection[columnIndex]==='asc'?'desc':'asc';kxSortDirection[columnIndex]=direction;rows.sort(function(a,b){var av=a.cells[columnIndex].innerText.trim();var bv=b.cells[columnIndex].innerText.trim();var an=kxParseNumber(av);var bn=kxParseNumber(bv);if(columnIndex>1)return direction==='asc'?an-bn:bn-an;return direction==='asc'?av.localeCompare(bv):bv.localeCompare(av);});rows.forEach(function(row){tbody.appendChild(row);});var noRows=document.getElementById('kxNoRows');if(noRows)tbody.appendChild(noRows);}
function kxExportExcel(elementId, filename){var element=document.getElementById(elementId)||document.getElementById('sum_table_raport1');if(!element)return;var html='<html><head><meta charset="UTF-8"></head><body>'+element.innerHTML+'</body></html>';var blob=new Blob(['\ufeff',html],{type:'application/vnd.ms-excel'});var link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=(filename||'Sales_Report')+'.xls';document.body.appendChild(link);link.click();document.body.removeChild(link);}

function kxGetCleanTableHtml(tableId){
    var table=document.getElementById(tableId);
    if(!table) return '';
    var clone=table.cloneNode(true);
    var noRows=clone.querySelector('#kxNoRows');
    if(noRows) noRows.parentNode.removeChild(noRows);
    clone.removeAttribute('id');
    return clone.outerHTML;
}
function kxEscape(text){
    return String(text||'').replace(/[&<>'\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','\"':'&quot;'}[c];});
}
function kxGetMetricRows(){
    var cards=document.querySelectorAll('.kx-stat-card');
    var rows='';
    cards.forEach(function(card){
        var label=(card.querySelector('span')||{}).innerText||'';
        var value=(card.querySelector('strong')||{}).innerText||'';
        if(label && value){ rows+='<tr><td>'+kxEscape(label)+'</td><td class="right">'+kxEscape(value)+'</td></tr>'; }
    });
    return rows;
}
function kxExportPdf(reportTitle, fileName){
    var periodText='';
    var alertBox=document.querySelector('.kx-alert');
    if(alertBox) periodText=alertBox.innerText.replace(/\s+/g,' ').trim();
    var generated=new Date().toLocaleString();
    var metricRows=kxGetMetricRows();
    var itemTable=kxGetCleanTableHtml('sum_table_raport1');
    var payTable=kxGetCleanTableHtml('payment_table');
    var calcTable=kxGetCleanTableHtml('calculation_table');
    var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'+kxEscape(reportTitle)+'</title>'+
    '<style>'+
    '@page{size:A4;margin:12mm}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#111827;margin:0;background:#fff;font-size:11px}.pdf-page{width:100%}.pdf-header{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #0f172a;padding-bottom:12px;margin-bottom:14px}.brand{display:flex;align-items:center;gap:12px}.brand img{width:54px;height:54px;object-fit:contain}.brand h1{margin:0;font-size:20px;color:#0f172a;letter-spacing:.2px}.brand p{margin:3px 0 0;color:#64748b;font-size:11px}.meta{text-align:right;color:#334155;font-size:11px}.meta strong{display:block;color:#0f172a;font-size:13px;margin-bottom:3px}.title-box{background:#0f172a;color:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px}.title-box h2{margin:0 0 5px;font-size:18px}.title-box p{margin:0;color:#dbeafe;font-size:11px}.section{margin-top:14px;page-break-inside:avoid}.section h3{font-size:13px;margin:0 0 7px;color:#0f172a;border-left:4px solid #2563eb;padding-left:7px}.report-table,.kx-table{width:100%;border-collapse:collapse;margin-top:6px}.report-table th,.kx-table th{background:#111827!important;color:#fff!important;text-align:left;padding:7px 6px;font-size:10px;border:1px solid #111827}.report-table td,.kx-table td{padding:6px;border:1px solid #e5e7eb;font-size:10px}.report-table tbody tr:nth-child(even),.kx-table tbody tr:nth-child(even){background:#f8fafc}.report-table tfoot th,.kx-table tfoot th{background:#e2e8f0!important;color:#0f172a!important;border:1px solid #cbd5e1}.right,.kx-money,.kx-num{text-align:right}.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}.footer{border-top:1px solid #e5e7eb;margin-top:18px;padding-top:8px;color:#64748b;font-size:10px;display:flex;justify-content:space-between}.watermark{position:fixed;right:15mm;bottom:15mm;color:#e5e7eb;font-size:34px;font-weight:800;z-index:-1}.print-actions{position:fixed;top:10px;right:10px}.print-actions button{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 14px;font-weight:700}.metric-table td:first-child{font-weight:700;color:#475569}.metric-table td:last-child{font-weight:800;color:#0f172a}@media print{.print-actions{display:none}.section{break-inside:avoid}.watermark{display:block}}'+
    '</style></head><body><div class="print-actions"><button onclick="window.print()">Save / Print PDF</button></div><div class="watermark">KYNIX</div><div class="pdf-page">'+
    '<div class="pdf-header"><div class="brand"><img src="./img/logo.png"><div><h1>Kynix Technologies</h1><p>SambaPOS WebReports</p></div></div><div class="meta"><strong>'+kxEscape(reportTitle)+'</strong><div>Generated: '+kxEscape(generated)+'</div></div></div>'+
    '<div class="title-box"><h2>'+kxEscape(reportTitle)+'</h2><p>'+kxEscape(periodText || 'Sales report')+'</p></div>'+
    '<div class="section"><h3>Executive Summary</h3><table class="report-table metric-table"><tbody>'+metricRows+'</tbody></table></div>'+
    '<div class="section"><h3>Item Sales Details</h3>'+itemTable+'</div>'+
    '<div class="two-col"><div class="section"><h3>Payment Types</h3>'+payTable+'</div><div class="section"><h3>Calculations</h3>'+calcTable+'</div></div>'+
    '<div class="footer"><span>Powered by Kynix Technologies</span><span>'+kxEscape(fileName||'Sales_Report')+'</span></div></div></body></html>';
    var win=window.open('','_blank');
    if(!win){alert('Please allow popups to export PDF.');return;}
    win.document.open();
    win.document.write(html);
    win.document.close();
    setTimeout(function(){win.focus();win.print();},700);
}

</script>
</body>
</html>
