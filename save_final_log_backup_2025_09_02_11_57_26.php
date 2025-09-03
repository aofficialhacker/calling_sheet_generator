<?php
session_start();
require_once 'db_config.php'; // Use centralized db config

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
$re_attempts = [];
$new_attempts = [];

/**
 * Check if this is a re-attempt by the same caller
 */
function isReAttempt($conn, $record_id, $finqy_id) {
    $stmt = $conn->prepare("
        SELECT fcl.finqy_id, fcl.disposition, fcl.slot, fcl.processed_at,
               COUNT(ch.id) as previous_attempts
        FROM final_call_logs fcl 
        LEFT JOIN call_history ch ON ch.original_record_id = fcl.id AND ch.finqy_id = ?
        WHERE fcl.id = ? 
        GROUP BY fcl.id
    ");
    $stmt->bind_param("ss", $finqy_id, $record_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result;
}

/**
 * Create a call history entry
 */
function createCallHistoryEntry($conn, $record_id, $finqy_id, $attempt_number, $batch_id, $disposition, $slot, $connectivity, $is_original = false) {
    $stmt = $conn->prepare("
        INSERT INTO call_history (
            original_record_id, finqy_id, attempt_number, batch_id, 
            disposition, slot, connectivity, attempt_date, is_original_attempt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->bind_param("ssisissi", $record_id, $finqy_id, $attempt_number, $batch_id, $disposition, $slot, $connectivity, $is_original);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get batch_id for a record
 */
function getBatchId($conn, $record_id) {
    $stmt = $conn->prepare("SELECT batch_id FROM final_call_logs WHERE id = ?");
    $stmt->bind_param("s", $record_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['batch_id'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results']) && isset($_POST['finqy_id'])) {
    $results = json_decode($_POST['json_results'], true);
    $finqy_id = $_POST['finqy_id'];

    if (json_last_error() === JSON_ERROR_NONE && !empty($results) && !empty($finqy_id)) {
        $conn->begin_transaction();
        try {
            // Enhanced SQL query to also update tracking fields
            $update_sql = "UPDATE final_call_logs 
                           SET connectivity = ?, disposition = ?, slot = ?, finqy_id = ?, processed_at = NOW(),
                               last_updated_by = ?, last_attempt_date = NOW(),
                               original_caller_id = COALESCE(original_caller_id, ?),
                               redistribution_count = CASE WHEN finqy_id != ? AND finqy_id IS NOT NULL THEN redistribution_count + 1 ELSE redistribution_count END
                           WHERE id = ?";
            
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt === false) {
                throw new Exception("Database prepare statement failed: " . $conn->error);
            }
            
            foreach ($results as $row) {
                // Use the record_id from the AI's output
                $record_id = $row['record_id'] ?? null;
                if (empty($record_id)) {
                    continue; // Skip if there's no record ID
                }

                $connectivity = !empty($row['connectivity_code']) ? (CONNECTIVITY_MAP[$row['connectivity_code']] ?? null) : null;
                $disposition = !empty($row['disposition_code']) ? ($DISPOSITION_MAP[$row['disposition_code']] ?? null) : null;
                $slot = !empty($row['slot']) ? (int)$row['slot'] : null;
                
                // Check if this is a re-attempt
                $attempt_info = isReAttempt($conn, $record_id, $finqy_id);
                $batch_id = getBatchId($conn, $record_id);
                
                if ($attempt_info && $attempt_info['finqy_id'] === $finqy_id && $attempt_info['previous_attempts'] > 0) {
                    // This is a re-attempt by the same caller
                    $attempt_number = $attempt_info['previous_attempts'] + 1;
                    $re_attempts[] = [
                        'record_id' => $record_id,
                        'attempt_number' => $attempt_number,
                        'previous_disposition' => $attempt_info['disposition'],
                        'new_disposition' => $disposition
                    ];
                    
                    // Create history entry for this re-attempt
                    createCallHistoryEntry($conn, $record_id, $finqy_id, $attempt_number, $batch_id, $disposition, $slot, $connectivity, false);
                    
                } elseif ($attempt_info && ($attempt_info['finqy_id'] !== $finqy_id || $attempt_info['finqy_id'] === null)) {
                    // This is either a new caller on an existing record or first attempt on this record
                    $attempt_number = $attempt_info['previous_attempts'] + 1;
                    $new_attempts[] = [
                        'record_id' => $record_id,
                        'previous_caller' => $attempt_info['finqy_id'],
                        'new_caller' => $finqy_id,
                        'is_redistribution' => $attempt_info['finqy_id'] !== null && $attempt_info['finqy_id'] !== $finqy_id
                    ];
                    
                    // Create history entry
                    createCallHistoryEntry($conn, $record_id, $finqy_id, $attempt_number, $batch_id, $disposition, $slot, $connectivity, $attempt_info['finqy_id'] === null);
                    
                } else {
                    // This is a brand new record or first attempt
                    $new_attempts[] = [
                        'record_id' => $record_id,
                        'previous_caller' => null,
                        'new_caller' => $finqy_id,
                        'is_redistribution' => false
                    ];
                    
                    // Create history entry for original attempt
                    createCallHistoryEntry($conn, $record_id, $finqy_id, 1, $batch_id, $disposition, $slot, $connectivity, true);
                }
                
                // Update the main record
                // Parameters: connectivity, disposition, slot, finqy_id, last_updated_by, original_caller_id, check_finqy_id, record_id
                $update_stmt->bind_param("ssississs", $connectivity, $disposition, $slot, $finqy_id, $finqy_id, $finqy_id, $finqy_id, $record_id);
                $update_stmt->execute();
                
                if ($update_stmt->affected_rows > 0) {
                    $saved_count++;
                }
                $updated_count++;
            }
            
            $conn->commit();
            
            // Build detailed success message
            $message = "Successfully updated " . $saved_count . " out of " . $updated_count . " records.";
            
            if (!empty($re_attempts)) {
                $message .= "\n\n📊 Re-attempts detected: " . count($re_attempts) . " records";
                $message .= "\nYour previous work on these leads has been preserved in call history.";
            }
            
            if (!empty($new_attempts)) {
                $redistributions = array_filter($new_attempts, function($attempt) { 
                    return $attempt['is_redistribution']; 
                });
                
                if (!empty($redistributions)) {
                    $message .= "\n\n🔄 Redistributed leads: " . count($redistributions) . " records";
                    $message .= "\nThese were previously worked by other callers. All attempts are tracked.";
                }
            }
            
            $update_stmt->close();
            
        } catch (Exception $exception) {
            $conn->rollback();
            $error = "A database transaction error occurred: " . $exception->getMessage();
        }
    } else {
        $error = "Invalid or missing data (FinqyID or results).";
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
        $response['re_attempts'] = $re_attempts;
        $response['new_attempts'] = $new_attempts;
        $response['redistributions'] = !empty($new_attempts) ? count(array_filter($new_attempts, function($a) { return $a['is_redistribution']; })) : 0;
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
    <title>Save Results - Enhanced with Call History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .stats-card { border-left: 4px solid #28a745; }
        .re-attempt-card { border-left: 4px solid #ffc107; background-color: #fffbf0; }
        .redistribution-card { border-left: 4px solid #17a2b8; background-color: #f0f9ff; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card shadow-sm text-center">
                    <div class="card-body p-5">
                        <?php if (!empty($message)): ?>
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                            <h2 class="mt-3">Upload Processed Successfully!</h2>
                            <p class="lead"><?= nl2br(htmlspecialchars($message)) ?></p>
                            
                            <!-- Statistics Cards -->
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <div class="card stats-card">
                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <i class="bi bi-check-circle text-success"></i>
                                                Records Updated
                                            </h5>
                                            <h3 class="text-success"><?= $saved_count ?></h3>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($re_attempts)): ?>
                                <div class="col-md-4">
                                    <div class="card re-attempt-card">
                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <i class="bi bi-arrow-repeat text-warning"></i>
                                                Your Re-attempts
                                            </h5>
                                            <h3 class="text-warning"><?= count($re_attempts) ?></h3>
                                            <small class="text-muted">Previous data preserved</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($new_attempts)): ?>
                                <?php $redistributions = array_filter($new_attempts, function($attempt) { return $attempt['is_redistribution']; }); ?>
                                <?php if (!empty($redistributions)): ?>
                                <div class="col-md-4">
                                    <div class="card redistribution-card">
                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <i class="bi bi-share text-info"></i>
                                                Redistributed Leads
                                            </h5>
                                            <h3 class="text-info"><?= count($redistributions) ?></h3>
                                            <small class="text-muted">From other callers</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Detailed Information -->
                            <?php if (!empty($re_attempts) || !empty($redistributions)): ?>
                            <div class="alert alert-info mt-4 text-start">
                                <h6><i class="bi bi-info-circle"></i> Call History Tracking Active</h6>
                                <ul class="mb-0">
                                    <li><strong>All attempts are preserved</strong> - Your work and others' work is never lost</li>
                                    <li><strong>Performance tracking enabled</strong> - View your progress on follow-up leads</li>
                                    <li><strong>Complete audit trail</strong> - Admins can compare caller performance</li>
                                </ul>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <i class="bi bi-x-circle-fill text-danger" style="font-size: 4rem;"></i>
                            <h2 class="mt-3">Upload Error</h2>
                            <p class="lead"><?= htmlspecialchars($error) ?></p>
                        <?php endif; ?>
                        
                        <div class="mt-4">
                            <a href="caller_panel.php" class="btn btn-primary me-3">
                                <i class="bi bi-arrow-left me-2"></i>Back to Caller Panel
                            </a>
                            <?php if (!empty($re_attempts)): ?>
                            <a href="caller_performance.php" class="btn btn-outline-info">
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