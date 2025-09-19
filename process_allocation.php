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
    $stmt = $conn->prepare("SELECT vendor_id FROM lv_file_batches WHERE id = ? AND admin_id = ?");
    $stmt->bind_param("ss", $original_batch_id, $adminId);
    $stmt->execute();
    $original_batch = $stmt->get_result()->fetch_assoc();
    if (!$original_batch) {
        throw new Exception("Original batch not found or you don't have permission.");
    }
    $vendorId = $original_batch['vendor_id'];
    $stmt->close();

    // 2. Get the product code for the target product
    $stmt = $conn->prepare("SELECT product_code FROM lv_products WHERE id = ?");
    $stmt->bind_param("i", $target_product_id);
    $stmt->execute();
    $target_product = $stmt->get_result()->fetch_assoc();
    if (!$target_product) {
        throw new Exception("Target product not found.");
    }
    $targetProductCode = $target_product['product_code'];
    $stmt->close();

    // 3. Check for duplicate mobile numbers in target product
    $duplicateCheckSql = "
        SELECT COUNT(DISTINCT fcl1.mobile_no) as duplicate_count
        FROM lv_final_call_logs fcl1
        WHERE fcl1.batch_id = ?
        AND EXISTS (
            SELECT 1 FROM lv_final_call_logs fcl2
            INNER JOIN lv_file_batches fb ON fcl2.batch_id = fb.id
            WHERE fcl2.mobile_no = fcl1.mobile_no
            AND fb.product_code = ?
            AND fb.admin_id = ?
        )
    ";
    $stmt = $conn->prepare($duplicateCheckSql);
    $stmt->bind_param("sss", $original_batch_id, $targetProductCode, $adminId);
    $stmt->execute();
    $duplicateResult = $stmt->get_result()->fetch_assoc();
    $duplicateCount = $duplicateResult['duplicate_count'];
    $stmt->close();

    // 4. Get total count of records in original batch
    $stmt = $conn->prepare("SELECT COUNT(*) as total_count FROM lv_final_call_logs WHERE batch_id = ?");
    $stmt->bind_param("s", $original_batch_id);
    $stmt->execute();
    $totalResult = $stmt->get_result()->fetch_assoc();
    $totalCount = $totalResult['total_count'];
    $stmt->close();

    // Check if all records are duplicates
    if ($duplicateCount >= $totalCount) {
        throw new Exception("Cannot allocate: All mobile numbers in this batch already exist in the target product.");
    }

    // 5. Generate a new batch ID
    $new_batch_id = generateBatchId($targetProductCode, $vendorId, $adminId, $conn);

    // 6. Insert the new batch record
    $stmt = $conn->prepare("INSERT INTO lv_file_batches (id, admin_id, vendor_id, product_code, original_batch_id, original_filename) 
                           SELECT ?, ?, ?, ?, ?, CONCAT('Allocated from ', ?) FROM lv_file_batches WHERE id = ?");
    $stmt->bind_param("sssssss", $new_batch_id, $adminId, $vendorId, $targetProductCode, $original_batch_id, $original_batch_id, $original_batch_id);
    $stmt->execute();
    $stmt->close();

    // 7. Fetch all non-duplicate records from the original batch
    $fetchSql = "
        SELECT fcl.* 
        FROM lv_final_call_logs fcl
        WHERE fcl.batch_id = ?
        AND NOT EXISTS (
            SELECT 1 FROM lv_final_call_logs fcl2
            INNER JOIN lv_file_batches fb ON fcl2.batch_id = fb.id
            WHERE fcl2.mobile_no = fcl.mobile_no
            AND fb.product_code = ?
            AND fb.admin_id = ?
        )
    ";
    $stmt = $conn->prepare($fetchSql);
    $stmt->bind_param("sss", $original_batch_id, $targetProductCode, $adminId);
    $stmt->execute();
    $original_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 8. Prepare the statement for inserting new log records
    $insert_log_sql = "INSERT INTO lv_final_call_logs (id, batch_id, mobile_no, title, name, policy_number, pan, dob, age, expiry, address, city, state, country, pincode, plan, premium, sum_insured, status, extra_data) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_log_sql);

    $rowCounter = 1;
    $allocatedCount = 0;
    foreach ($original_logs as $log) {
        $new_log_id = generateLogRowId($new_batch_id, $rowCounter);
        
        $insert_stmt->bind_param("sssssssssissssssssss", 
            $new_log_id, $new_batch_id, $log['mobile_no'], $log['title'], $log['name'], 
            $log['policy_number'], $log['pan'], $log['dob'], $log['age'], $log['expiry'], 
            $log['address'], $log['city'], $log['state'], $log['country'], $log['pincode'], 
            $log['plan'], $log['premium'], $log['sum_insured'], $log['status'], $log['extra_data']
        );
        $insert_stmt->execute();
        $rowCounter++;
        $allocatedCount++;
    }
    $insert_stmt->close();

    $conn->commit();
    
    $skippedCount = $totalCount - $allocatedCount;
    $message = "Successfully allocated batch {$original_batch_id} to new batch {$new_batch_id}. ";
    $message .= "Allocated: {$allocatedCount} records.";
    if ($skippedCount > 0) {
        $message .= " Skipped: {$skippedCount} duplicate records.";
    }
    
    $_SESSION['flash_message'] = ['type' => 'success', 'text' => $message];

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Allocation failed: ' . $e->getMessage()];
} finally {
    if ($conn) $conn->close();
    header("Location: allocation.php");
    exit();
}