<?php
require_once __DIR__ . '/auth/auth.php';
auth_require_permission('stock.view');
?>
<!DOCTYPE html>
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Stock</title>
	<link href="./css/vanzari.css" rel="stylesheet" media="screen">
	<link href="./css/print.css" rel="stylesheet" media="print">
    <link href="./bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
	<link href="./css/bootstrap-datetimepicker.min.css" rel="stylesheet" media="screen">
	<script type="text/javascript" src="./jquery/jquery-1.8.3.min.js" charset="UTF-8"></script>
	<script type="text/javascript" src="./bootstrap/js/bootstrap.min.js"></script>
	<script type="text/javascript" src="./js/bootstrap-datetimepicker.js" charset="UTF-8"></script>
	
</head>
<body class="PaginaStoc">
<?php require_once __DIR__ . '/config.php';?>
<?php $reportName = "Current Stock ".$BusinessName;?>
<div class="col-md-12 top-container">
	<fieldset>
        <?php include 'header.php';?>
    </fieldset>
</div>

		<?php include './reportSQL/raport1.php';?>

</div>

<script type="text/javascript" src="./js/reportJS.js" charset="UTF-8"></script>
</body>
</html>
