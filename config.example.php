<?php
/* Server Configuration */
$serverHost = "YOUR_SERVER\\SQLEXPRESS";
$databaseName = "sambapos5";
$userName = "YOUR_DB_USER";
$password = "YOUR_DB_PASSWORD";
/* End Server Configuration */

/* Business Configuration Strings */
$BusinessName = 'Your Business Name';
/* End Business Configuration Strings */

$connInfo = array(
    "Database" => $databaseName,
    "UID" => $userName,
    "PWD" => $password,
    "TrustServerCertificate" => true
);

$conn = sqlsrv_connect($serverHost, $connInfo);
if ($conn) {
    echo "<div class='connected'>Connected.</div>";
} else {
    echo "<div class='unconnected'>Database connection could not be opened.</div>";
    error_log(print_r(sqlsrv_errors(), true));
    die('Database connection could not be opened.');
}
?>
