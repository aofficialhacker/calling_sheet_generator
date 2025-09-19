<?php
require_once 'db_config.php';

$conn = getDBConnection();
$result = $conn->query('SELECT COUNT(*) as total FROM lv_final_call_logs');
$total = $result->fetch_assoc()['total'];

echo "Total records available: $total\n";

if ($total >= 10000) {
    echo "✓ System has sufficient data for 10K+ testing\n";
} else {
    echo "! System has $total records (less than 10K for full testing)\n";
}

$conn->close();
?>