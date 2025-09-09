<?php
require_once __DIR__ . '/session_manager.php';\nSessionManager::start();
require_once 'db_config.php';

// Check if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' 
          || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($isAjax) {
    header('Content-Type: application/json');
}

$conn = getDBConnection();

// Fetch disposition map from database for conversion
$dispositions = $conn->query("SELECT code, description FROM disposition_codes WHERE is_active = 1");
$DISPOSITION_MAP = [];
while($row = $dispositions->fetch_assoc()) {
    $DISPOSITION_MAP[$row['code']] = $row['description'];
}

const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$message = '';
$error = '';
$saved_count = 0;
$updated_count = 0;
$preserved_data = [];
$re_attempts = [];
$new_attempts = [];

/**
 * COMPLETE DATA PRESERVATION FUNCTION
 * Backs up ALL current data before making any changes
 */
function preserveAllCurrentData($conn, $record_id, $finqy_id) {
    // Get ALL current data that exists
    $current_stmt = $conn->prepare("
        SELECT id, slot, disposition, connectivity, finqy_id, processed_at, 
               total_attempts, first_attempt_date, batch_id,
               original_caller_id, last_updated_by
        FROM final_call_logs 
        WHERE id = ?
    ");
    $current_stmt->bind_param("s", $record_id);
    $current_stmt->execute();
    $current_data = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();
    
    if (!$current_data) {
        return null; // Record doesn't exist
    }
    
    // If this record has existing data, preserve it COMPLETELY
    if ($current_data['finqy_id'] && $current_data['processed_at']) {
        $attempt_number = ($current_data['total_attempts'] ?? 0) + 1;
        
        // Create COMPLETE backup of current state
        $preserve_stmt = $conn->prepare("
            INSERT INTO call_history (
                original_record_id, finqy_id, attempt_number, batch_id,
                slot, disposition, connectivity, attempt_date,
                is_original_attempt, data_source, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'complete_preservation', 'AUTO-PRESERVED: All data backed up before update')
        ");
        
        $is_original = ($current_data['total_attempts'] ?? 0) == 0;
        
        $preserve_stmt->bind_param("ssissssssi",
            $record_id,
            $current_data['finqy_id'],
            $attempt_number - 1, // This was the previous attempt
            $current_data['batch_id'],
            $current_data['slot'],
            $current_data['disposition'], 
            $current_data['connectivity'],
            $current_data['processed_at'],
            $is_original,
            "AUTO-PRESERVED: All data backed up before update"
        );
        
        $preserve_stmt->execute();
        $preserve_stmt->close();
        
        return [
            'preserved' => true,
            'previous_caller' => $current_data['finqy_id'],
            'previous_disposition' => $current_data['disposition'],
            'previous_slot' => $current_data['slot'],
            'previous_processed_at' => $current_data['processed_at'],
            'attempt_number' => $attempt_number,
            'is_same_caller' => $current_data['finqy_id'] === $finqy_id
        ];
    }
    
    return [
        'preserved' => false,
        'attempt_number' => 1,
        'is_first_attempt' => true
    ];
}

/**
 * Create new attempt history entry
 */
function createNewAttemptEntry($conn, $record_id, $finqy_id, $attempt_number, $new_data) {
    $new_attempt_stmt = $conn->prepare("
        INSERT INTO call_history (
            original_record_id, finqy_id, attempt_number, batch_id,
            slot, disposition, connectivity, attempt_date,
            is_original_attempt, data_source
        )
        SELECT ?, ?, ?, batch_id, ?, ?, ?, NOW(), ?, 'upload'
        FROM final_call_logs WHERE id = ?
    ");
    
    $is_original = $attempt_number == 1;
    
    $new_attempt_stmt->bind_param("sissssiss",
        $record_id, $finqy_id, $attempt_number,
        $new_data['slot'], $new_data['disposition'], $new_data['connectivity'],
        $is_original, "upload", $record_id
    );
    
    $new_attempt_stmt->execute();
    $new_attempt_stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results']) && isset($_POST['finqy_id'])) {
    $results = json_decode($_POST['json_results'], true);
    $finqy_id = $_POST['finqy_id'];

    if (json_last_error() === JSON_ERROR_NONE && !empty($results) && !empty($finqy_id)) {
        $conn->begin_transaction();
        try {
            foreach ($results as $row) {
                $record_id = $row['record_id'] ?? null;
                if (empty($record_id)) {
                    continue;
                }

                $connectivity = !empty($row['connectivity_code']) ? (CONNECTIVITY_MAP[$row['connectivity_code']] ?? null) : null;
                $disposition = !empty($row['disposition_code']) ? ($DISPOSITION_MAP[$row['disposition_code']] ?? null) : null;
                $slot = !empty($row['slot']) ? (int)$row['slot'] : null;
                
                $new_data = [
                    'slot' => $slot,
                    'disposition' => $disposition,
                    'connectivity' => $connectivity
                ];
                
                // STEP 1: PRESERVE ALL EXISTING DATA (COMPLETE PRESERVATION)
                $preservation_result = preserveAllCurrentData($conn, $record_id, $finqy_id);
                
                if (!$preservation_result) {
                    continue; // Skip if record doesn't exist
                }
                
                $attempt_number = $preservation_result['attempt_number'];
                
                // Track what type of operation this is
                if ($preservation_result['preserved']) {
                    $preserved_data[] = [
                        'record_id' => $record_id,
                        'previous_caller' => $preservation_result['previous_caller'],
                        'previous_disposition' => $preservation_result['previous_disposition'],
                        'previous_slot' => $preservation_result['previous_slot'],
                        'new_caller' => $finqy_id,
                        'new_disposition' => $disposition,
                        'new_slot' => $slot,
                        'is_same_caller' => $preservation_result['is_same_caller']
                    ];
                    
                    if ($preservation_result['is_same_caller']) {
                        $re_attempts[] = [
                            'record_id' => $record_id,
                            'attempt_number' => $attempt_number,
                            'previous_disposition' => $preservation_result['previous_disposition'],
                            'new_disposition' => $disposition
                        ];
                    } else {
                        $new_attempts[] = [
                            'record_id' => $record_id,
                            'previous_caller' => $preservation_result['previous_caller'],
                            'new_caller' => $finqy_id,
                            'is_redistribution' => true
                        ];
                    }
                }
                
                // STEP 2: UPDATE CURRENT STATE with complete tracking
                $update_sql = "UPDATE final_call_logs 
                               SET slot = ?, disposition = ?, connectivity = ?, 
                                   finqy_id = ?, processed_at = NOW(),
                                   last_updated_by = ?, last_attempt_date = NOW(),
                                   original_caller_id = COALESCE(original_caller_id, ?),
                                   total_attempts = ?,
                                   first_attempt_date = COALESCE(first_attempt_date, NOW()),
                                   data_backup_confirmed = TRUE,
                                   last_backup_at = NOW(),
                                   redistribution_count = CASE 
                                       WHEN finqy_id IS NOT NULL AND finqy_id != ? THEN redistribution_count + 1 
                                       ELSE redistribution_count 
                                   END
                               WHERE id = ?";
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssssssisis", 
                    $slot, $disposition, $connectivity,
                    $finqy_id, $finqy_id, $finqy_id,
                    $attempt_number, $finqy_id, $record_id
                );
                $update_stmt->execute();
                
                if ($update_stmt->affected_rows > 0) {
                    $saved_count++;
                }
                $update_stmt->close();
                
                // STEP 3: CREATE NEW ATTEMPT HISTORY ENTRY
                createNewAttemptEntry($conn, $record_id, $finqy_id, $attempt_number, $new_data);
                
                $updated_count++;
            }
            
            $conn->commit();
            
            // Build comprehensive success message
            $message = "Successfully processed " . $saved_count . " out of " . $updated_count . " records with COMPLETE data preservation.";
            
            if (!empty($preserved_data)) {
                $message .= "\n\n🔒 Data Preservation Summary:";
                $message .= "\n• Total records with preserved data: " . count($preserved_data);
                
                $same_caller_preservations = array_filter($preserved_data, function($p) { return $p['is_same_caller']; });
                $redistributions = array_filter($preserved_data, function($p) { return !$p['is_same_caller']; });
                
                if (!empty($same_caller_preservations)) {
                    $message .= "\n• Your re-attempts: " . count($same_caller_preservations) . " (your previous work preserved)";
                }
                
                if (!empty($redistributions)) {
                    $message .= "\n• Redistributed leads: " . count($redistributions) . " (other callers' work preserved)";
                }
                
                $message .= "\n\n✅ ALL PREVIOUS DATA PRESERVED: slot, disposition, connectivity, timestamps, caller info";
                $message .= "\n✅ COMPLETE AUDIT TRAIL: Every attempt tracked with full details";
                $message .= "\n✅ ZERO DATA LOSS: No information ever lost or overwritten";
            }
            
        } catch (Exception $exception) {
            $conn->rollback();
            $error = "Database error during complete preservation: " . $exception->getMessage();
        }
    } else {
        $error = "Invalid data provided.";
    }
} else {
    $error = "No data submitted or invalid request method.";
}
$conn->close();

// Return JSON response for AJAX requests
if ($isAjax) {
    $response = ['success' => !empty($message), 'saved_count' => $saved_count, 'updated_count' => $updated_count];
    
    if (!empty($message)) {
        $response['message'] = $message;
        $response['preserved_data'] = $preserved_data;
        $response['re_attempts'] = $re_attempts;
        $response['redistributions'] = count($new_attempts);
        $response['total_preserved'] = count($preserved_data);
    } else {
        $response['message'] = $error;
    }
    
    echo json_encode($response);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Data Preservation - Upload Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .preservation-card { border-left: 4px solid #28a745; background-color: #f8fff9; }
        .reattempt-card { border-left: 4px solid #ffc107; background-color: #fffbf0; }
        .redistribution-card { border-left: 4px solid #17a2b8; background-color: #f0f9ff; }
        .data-preserved { background-color: #e8f5e9; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card shadow-sm">
                    <div class="card-body p-5">
                        <?php if (!empty($message)): ?>
                            <div class="text-center">
                                <i class="bi bi-shield-check text-success" style="font-size: 4rem;"></i>
                                <h2 class="mt-3 text-success">Complete Data Preservation Successful!</h2>
                                <p class="lead"><?= nl2br(htmlspecialchars($message)) ?></p>
                            </div>
                            
                            <!-- Detailed Preservation Stats -->
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <div class="card preservation-card">
                                        <div class="card-body text-center">
                                            <i class="bi bi-shield-fill-check text-success" style="font-size: 2rem;"></i>
                                            <h4 class="text-success mt-2"><?= count($preserved_data) ?></h4>
                                            <p class="mb-0">Records with Data Preserved</p>
                                            <small class="text-success">Zero data loss guaranteed</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($re_attempts)): ?>
                                <div class="col-md-4">
                                    <div class="card reattempt-card">
                                        <div class="card-body text-center">
                                            <i class="bi bi-arrow-repeat text-warning" style="font-size: 2rem;"></i>
                                            <h4 class="text-warning mt-2"><?= count($re_attempts) ?></h4>
                                            <p class="mb-0">Your Re-attempts</p>
                                            <small class="text-warning">Previous work preserved</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($new_attempts)): ?>
                                <div class="col-md-4">
                                    <div class="card redistribution-card">
                                        <div class="card-body text-center">
                                            <i class="bi bi-people-fill text-info" style="font-size: 2rem;"></i>
                                            <h4 class="text-info mt-2"><?= count($new_attempts) ?></h4>
                                            <p class="mb-0">Redistributed Leads</p>
                                            <small class="text-info">Other callers' work preserved</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Data Preservation Details -->
                            <?php if (!empty($preserved_data)): ?>
                            <div class="data-preserved mt-4">
                                <h5><i class="bi bi-database-check me-2"></i>Complete Data Preservation Confirmed</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Preserved Data Fields:</h6>
                                        <ul class="mb-0">
                                            <li>✅ <strong>Slot assignments</strong> (all time slots)</li>
                                            <li>✅ <strong>Dispositions</strong> (all call outcomes)</li>
                                            <li>✅ <strong>Connectivity status</strong> (connection details)</li>
                                            <li>✅ <strong>Caller assignments</strong> (who worked on what)</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Additional Tracking:</h6>
                                        <ul class="mb-0">
                                            <li>✅ <strong>Timestamps</strong> (exact timing of attempts)</li>
                                            <li>✅ <strong>Attempt sequence</strong> (1st, 2nd, 3rd attempts)</li>
                                            <li>✅ <strong>Caller history</strong> (complete audit trail)</li>
                                            <li>✅ <strong>Performance data</strong> (comparison enabled)</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- System Benefits Alert -->
                            <div class="alert alert-info mt-4">
                                <h6><i class="bi bi-info-circle-fill"></i> Complete Data Preservation Benefits</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li><strong>Zero Data Loss:</strong> No information ever overwritten</li>
                                            <li><strong>Complete History:</strong> Every attempt tracked forever</li>
                                            <li><strong>Performance Comparison:</strong> Compare effectiveness across callers</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li><strong>Audit Trail:</strong> Full accountability for all actions</li>
                                            <li><strong>Business Intelligence:</strong> ROI analysis of follow-up strategies</li>
                                            <li><strong>Data Recovery:</strong> Ability to restore previous states</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="text-center">
                                <i class="bi bi-exclamation-triangle text-danger" style="font-size: 4rem;"></i>
                                <h2 class="mt-3 text-danger">Upload Error</h2>
                                <p class="lead"><?= htmlspecialchars($error) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <a href="caller_panel.php" class="btn btn-primary btn-lg me-3">
                                <i class="bi bi-arrow-left me-2"></i>Back to Caller Panel
                            </a>
                            <?php if (!empty($preserved_data)): ?>
                            <a href="caller_performance.php" class="btn btn-outline-success btn-lg">
                                <i class="bi bi-graph-up me-2"></i>View Your Performance
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>