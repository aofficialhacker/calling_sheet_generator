<?php
require_once 'db_config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['original_batch_id']) || empty($_POST['target_product_id'])) {
    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Invalid request.'];
    header("Location: allocation.php");
    exit();
}

$original_batch_id = $_POST['original_batch_id'];
$target_product_id = $_POST['target_product_id'];
$adminId = $_SESSION['admin_id'];

$conn = getDBConnection();

try {
    $conn->begin_transaction();

    // 1. Get details of the original batch
    $stmt = $conn->prepare("SELECT vendor_id FROM file_batches WHERE id = ? AND admin_id = ?");
    $stmt->bind_param("ss", $original_batch_id, $adminId);
    $stmt->execute();
    $original_batch = $stmt->get_result()->fetch_assoc();
    if (!$original_batch) {
        throw new Exception("Original batch not found or you don't have permission.");
    }
    $vendorId = $original_batch['vendor_id'];
    $stmt->close();

    // 2. Get the product code for the target product
    $stmt = $conn->prepare("SELECT product_code FROM products WHERE id = ?");
    $stmt->bind_param("i", $target_product_id);
    $stmt->execute();
    $target_product = $stmt->get_result()->fetch_assoc();
    if (!$target_product) {
        throw new Exception("Target product not found.");
    }
    $targetProductCode = $target_product['product_code'];
    $stmt->close();

    // 3. Generate a new batch ID
    $new_batch_id = generateBatchId($targetProductCode, $vendorId, $adminId, $conn);

    // 4. Insert the new batch record
    $stmt = $conn->prepare("INSERT INTO file_batches (id, admin_id, vendor_id, product_code, original_batch_id, original_filename)
                           SELECT ?, ?, ?, ?, ?, CONCAT('Allocated from ', ?) FROM file_batches WHERE id = ?");
    $stmt->bind_param("sssssss", $new_batch_id, $adminId, $vendorId, $targetProductCode, $original_batch_id, $original_batch_id, $original_batch_id);
    $stmt->execute();
    $stmt->close();

    // 5. Fetch all records from the original batch
    $stmt = $conn->prepare("SELECT * FROM final_call_logs WHERE batch_id = ?");
    $stmt->bind_param("s", $original_batch_id);
    $stmt->execute();
    $original_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Existing mobile numbers for the target product
    $stmt = $conn->prepare("SELECT fl.mobile_no FROM final_call_logs fl JOIN file_batches fb ON fl.batch_id = fb.id WHERE fb.product_code = ?");
    $stmt->bind_param("s", $targetProductCode);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $existingMobiles = [];
    foreach ($existing as $row) { $existingMobiles[$row['mobile_no']] = true; }

    // 6. Prepare insert statement
    $insert_log_sql = "INSERT INTO final_call_logs (id, batch_id, mobile_no, title, name, policy_number, pan, dob, age, expiry, address, city, state, country, pincode, plan, premium, sum_insured, status, extra_data)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_log_sql);

    $rowCounter = 1;
    $insertedCount = 0;
    foreach ($original_logs as $log) {
        if (isset($existingMobiles[$log['mobile_no']])) {
            continue;
        }
        $existingMobiles[$log['mobile_no']] = true;
        $new_log_id = generateLogRowId($new_batch_id, $rowCounter);

        $insert_stmt->bind_param("sssssssssissssssssss",
            $new_log_id, $new_batch_id, $log['mobile_no'], $log['title'], $log['name'],
            $log['policy_number'], $log['pan'], $log['dob'], $log['age'], $log['expiry'],
            $log['address'], $log['city'], $log['state'], $log['country'], $log['pincode'],
            $log['plan'], $log['premium'], $log['sum_insured'], $log['status'], $log['extra_data']
        );
        $insert_stmt->execute();
        $rowCounter++;
        $insertedCount++;
    }
    $insert_stmt->close();

    if ($insertedCount == 0) {
        throw new Exception("No unique entries found for allocation.");
    }

    $conn->commit();
    $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Successfully allocated batch {$original_batch_id} to new batch {$new_batch_id} with {$insertedCount} unique records."];

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Allocation failed: ' . $e->getMessage()];
} finally {
    if ($conn) $conn->close();
    header("Location: allocation.php");
    exit();
}
?>
