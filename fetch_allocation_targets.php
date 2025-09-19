<?php
require_once 'db_config.php';
requireAdmin();

header('Content-Type: application/json');

$batchId = $_GET['batch_id'] ?? '';
if (!$batchId) {
    echo json_encode(['allowed' => []]);
    exit();
}

$conn = getDBConnection();

// Total distinct mobiles in the batch
$totalStmt = $conn->prepare("SELECT COUNT(DISTINCT mobile_no) as total FROM lv_final_call_logs WHERE batch_id = ?");
$totalStmt->bind_param("s", $batchId);
$totalStmt->execute();
$total = $totalStmt->get_result()->fetch_assoc()['total'] ?? 0;
$totalStmt->close();

if ($total == 0) {
    $conn->close();
    echo json_encode(['allowed' => []]);
    exit();
}

// Current product code of the batch
$prodStmt = $conn->prepare("SELECT product_code FROM lv_file_batches WHERE id = ?");
$prodStmt->bind_param("s", $batchId);
$prodStmt->execute();
$currentProduct = $prodStmt->get_result()->fetch_assoc()['product_code'] ?? '';
$prodStmt->close();

$allowed = [];
$products = $conn->prepare("SELECT id, product_code FROM lv_products WHERE is_active = 1 AND product_code != ?");
$products->bind_param("s", $currentProduct);
$products->execute();
$res = $products->get_result();
while ($prod = $res->fetch_assoc()) {
    $dupStmt = $conn->prepare("SELECT COUNT(DISTINCT fl.mobile_no) as cnt FROM lv_final_call_logs fl JOIN lv_file_batches fb ON fl.batch_id = fb.id WHERE fb.product_code = ? AND fl.mobile_no IN (SELECT mobile_no FROM lv_final_call_logs WHERE batch_id = ?)");
    $dupStmt->bind_param("ss", $prod['product_code'], $batchId);
    $dupStmt->execute();
    $dup = $dupStmt->get_result()->fetch_assoc()['cnt'] ?? 0;
    $dupStmt->close();
    if (($total - $dup) > 0) {
        $allowed[] = (string)$prod['id'];
    }
}
$products->close();
$conn->close();

echo json_encode(['allowed' => $allowed]);
