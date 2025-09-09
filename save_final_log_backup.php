<?php
require_once __DIR__ . '/session_manager.php';
SessionManager::start();
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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results']) && isset($_POST['finqy_id'])) {
    $results = json_decode($_POST['json_results'], true);
    $finqy_id = $_POST['finqy_id'];

    if (json_last_error() === JSON_ERROR_NONE && !empty($results) && !empty($finqy_id)) {
        $conn->begin_transaction();
        try {
            // --- FIX: The SQL query now uses 'id = ?' as the sole condition in the WHERE clause. ---
            // This is more direct and reliable than using the mobile number.
            $update_sql = "UPDATE final_call_logs 
                           SET connectivity = ?, disposition = ?, slot = ?, finqy_id = ?, processed_at = NOW() 
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
                
                // --- FIX: The parameters bound now match the corrected SQL query. ---
                // Types: s(connectivity), s(disposition), i(slot), s(finqy_id), s(record_id)
                $update_stmt->bind_param("ssiss", $connectivity, $disposition, $slot, $finqy_id, $record_id);
                $update_stmt->execute();
                
                if ($update_stmt->affected_rows > 0) {
                    $saved_count++;
                }
                $updated_count++;
            }
            $conn->commit();
            $message = "Successfully updated " . $saved_count . " out of " . $updated_count . " records.";
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
    if (!empty($message)) {
        echo json_encode(['success' => true, 'message' => $message, 'saved_count' => $saved_count, 'updated_count' => $updated_count]);
    } else {
        echo json_encode(['success' => false, 'message' => $error]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Save Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm text-center">
                    <div class="card-body p-5">
                        <?php if (!empty($message)): ?>
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                            <h2 class="mt-3">Success!</h2>
                            <p class="lead"><?= htmlspecialchars($message) ?></p>
                        <?php else: ?>
                            <i class="bi bi-x-circle-fill text-danger" style="font-size: 4rem;"></i>
                            <h2 class="mt-3">Error</h2>
                            <p class="lead"><?= htmlspecialchars($error) ?></p>
                        <?php endif; ?>
                        <a href="caller_panel.php" class="btn btn-primary mt-4">
                            <i class="bi bi-arrow-left me-2"></i>Back to Caller Panel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
