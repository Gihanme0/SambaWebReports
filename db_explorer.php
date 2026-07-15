<?php
require_once __DIR__ . '/auth/auth.php';
auth_require_permission('settings.manage');
require_once __DIR__ . '/config.php';

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function isSafeSelect($sql) {
    $clean = strtolower(trim($sql));

    if ($clean === '') return false;

    $blocked = [
        'insert ', 'update ', 'delete ', 'drop ', 'alter ', 'create ',
        'truncate ', 'exec ', 'execute ', 'merge ', 'backup ', 'restore ',
        'grant ', 'revoke ', 'deny ', 'xp_', 'sp_'
    ];

    foreach ($blocked as $word) {
        if (strpos($clean, $word) !== false) return false;
    }

    return preg_match('/^(select|with|declare)/i', $sql);
}

function runQuery($conn, $sql, $params = []) {
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return [
            'error' => 'Query could not be completed.',
            'rows' => []
        ];
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }

    sqlsrv_free_stmt($stmt);

    return [
        'error' => null,
        'rows' => $rows
    ];
}

$quickQueries = [
    'tables' => "
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ",

    'columns' => "
        SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ",

    'payments' => "
        SELECT TOP 100 *
        FROM Payments
        ORDER BY Id DESC
    ",

    'payment_summary' => "
        SELECT 
            Name AS PaymentType,
            COUNT(*) AS RecordCount,
            SUM(Amount) AS TotalAmount
        FROM Payments
        GROUP BY Name
        ORDER BY Name
    ",

    'calculation_types' => "
        SELECT TOP 100 *
        FROM CalculationTypes
        ORDER BY Id
    ",

    'calculations' => "
        SELECT TOP 100 *
        FROM Calculations
        ORDER BY Id DESC
    ",

    'calculation_summary' => "
        SELECT
            ct.Id,
            ct.Name,
            COUNT(*) AS RecordCount,
            SUM(c.Amount) AS TotalAmount
        FROM Calculations c
        LEFT JOIN CalculationTypes ct ON ct.Id = c.CalculationTypeId
        GROUP BY ct.Id, ct.Name
        ORDER BY ct.Name
    ",

    'tickets' => "
        SELECT TOP 100 *
        FROM Tickets
        ORDER BY Id DESC
    ",

    'orders' => "
        SELECT TOP 100 *
        FROM Orders
        ORDER BY Id DESC
    ",

    'service_discount_check' => "
        SELECT TOP 200
            c.*,
            ct.Name AS CalculationTypeName,
            t.TicketNumber,
            t.Date AS TicketDate
        FROM Calculations c
        LEFT JOIN CalculationTypes ct ON ct.Id = c.CalculationTypeId
        LEFT JOIN Tickets t ON t.Id = c.TicketId
        ORDER BY c.Id DESC
    "
];

$selected = $_GET['quick'] ?? '';
$sql = $_POST['sql'] ?? '';

if ($selected && isset($quickQueries[$selected])) {
    $sql = $quickQueries[$selected];
}

$result = null;

if ($sql) {
    if (isSafeSelect($sql)) {
        $result = runQuery($conn, $sql);
    } else {
        $result = [
            'error' => 'Blocked for safety. Only SELECT / WITH / DECLARE queries are allowed.',
            'rows' => []
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Kynix Database Explorer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: Segoe UI, Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            color: #111827;
        }

        .header {
            background: #0f172a;
            color: white;
            padding: 20px 30px;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .header p {
            margin: 5px 0 0;
            color: #cbd5e1;
        }

        .wrap {
            padding: 25px;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 24px rgba(15,23,42,0.08);
        }

        .buttons a {
            display: inline-block;
            background: #2563eb;
            color: white;
            text-decoration: none;
            padding: 9px 14px;
            border-radius: 8px;
            margin: 4px;
            font-weight: 600;
            font-size: 13px;
        }

        textarea {
            width: 100%;
            height: 180px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 12px;
            font-family: Consolas, monospace;
            font-size: 14px;
        }

        button {
            background: #16a34a;
            color: white;
            border: 0;
            padding: 11px 18px;
            border-radius: 9px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 10px;
            white-space: pre-wrap;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 13px;
        }

        th {
            background: #111827;
            color: white;
            padding: 10px;
            text-align: left;
            position: sticky;
            top: 0;
        }

        td {
            padding: 9px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        tr:nth-child(even) {
            background: #f9fafb;
        }

        .note {
            background: #fff7ed;
            color: #9a3412;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
        }

        @media(max-width:768px) {
            .wrap {
                padding: 12px;
            }

            .buttons a {
                width: calc(100% - 30px);
                text-align: center;
            }
        }
    </style>
</head>

<body>

<div class="header">
    <h1>Kynix Database Explorer</h1>
    <p>Safe read-only SQL tool for SambaPOS WebReports</p>
</div>

<div class="wrap">

    <div class="card note">
        This tool blocks INSERT, UPDATE, DELETE, DROP, ALTER and other dangerous commands.
        Use it only for checking SambaPOS report data.
    </div>

    <div class="card">
        <h3>Quick Checks</h3>

        <div class="buttons">
            <a href="?quick=tables">Tables</a>
            <a href="?quick=columns">Columns</a>
            <a href="?quick=payments">Latest Payments</a>
            <a href="?quick=payment_summary">Payment Summary</a>
            <a href="?quick=calculation_types">Calculation Types</a>
            <a href="?quick=calculations">Latest Calculations</a>
            <a href="?quick=calculation_summary">Calculation Summary</a>
            <a href="?quick=service_discount_check">Service / Discount Check</a>
            <a href="?quick=tickets">Latest Tickets</a>
            <a href="?quick=orders">Latest Orders</a>
        </div>
    </div>

    <div class="card">
        <h3>Custom SELECT Query</h3>

        <form method="post">
            <textarea name="sql"><?php echo h($sql); ?></textarea>
            <br>
            <button type="submit">Run Query</button>
        </form>
    </div>

    <?php if ($result): ?>
        <div class="card">
            <h3>Result</h3>

            <?php if ($result['error']): ?>
                <div class="error"><?php echo h($result['error']); ?></div>
            <?php else: ?>

                <p><strong><?php echo count($result['rows']); ?></strong> rows found.</p>

                <?php if (count($result['rows']) > 0): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <?php foreach (array_keys($result['rows'][0]) as $col): ?>
                                        <th><?php echo h($col); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($result['rows'] as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $value): ?>
                                            <td>
                                                <?php
                                                if ($value instanceof DateTime) {
                                                    echo h($value->format('Y-m-d H:i:s'));
                                                } else {
                                                    echo h($value);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
