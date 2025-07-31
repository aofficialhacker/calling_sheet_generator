<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

const DISPOSITION_MAP = [ '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up', '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing', '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy', ];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("DB Connection Failed."); }

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results']) && isset($_POST['finqy_id'])) {
    $results = json_decode($_POST['json_results'], true);
    $finqy_id = $_POST['finqy_id'];

    if (json_last_error() === JSON_ERROR_NONE && !empty($results) && !empty($finqy_id)) {
        $conn->begin_transaction();
        try {
            // This is now an UPDATE statement
            $update_stmt = $conn->prepare("UPDATE final_call_logs SET connectivity = ?, disposition = ?, slot = ?, finqy_id = ?, processed_at = NOW() WHERE mobile_no = ?");
            if ($update_stmt === false) throw new Exception("Prepare failed (UPDATE): " . $conn->error);
            
            $saved_count = 0;
            foreach ($results as $row) {
                $mobile_no = $row['mobile_no'] ?? null;
                if (empty($mobile_no)) continue;

                $connectivity = !empty($row['connectivity_code']) ? (CONNECTIVITY_MAP[$row['connectivity_code']] ?? null) : null;
                $disposition = !empty($row['disposition_code']) ? (DISPOSITION_MAP[$row['disposition_code']] ?? null) : null;
                $slot = !empty($row['slot']) ? (int)$row['slot'] : null;
                
                // Bind params: s (connectivity), s (disposition), i (slot), s (finqy_id), s (mobile_no)
                $update_stmt->bind_param("ssiss", $connectivity, $disposition, $slot, $finqy_id, $mobile_no);
                $update_stmt->execute();
                
                if ($update_stmt->affected_rows > 0) {
                    $saved_count++;
                }
            }
            $conn->commit();
            $message = "Successfully updated " . $saved_count . " records in the final log.";
            $update_stmt->close();
        } catch (Exception $exception) {
            $conn->rollback();
            $error = "A database transaction error occurred: " . $exception->getMessage();
        }
    } else { $error = "Invalid or missing data (FinqyID or results)."; }
} else { $error = "No data submitted."; }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Save Final Log</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"></head>
<body>
<div class="container mt-5">
    <div class="card text-center shadow-sm">
        <div class="card-body p-5">
            <?php if ($message): ?><h3 class="text-success"><i class="bi bi-check-circle-fill fs-1"></i><br/><?= htmlspecialchars($message) ?></h3><?php endif; ?>
            <?php if ($error): ?><h3 class="text-danger"><i class="bi bi-x-octagon-fill fs-1"></i><br/><?= htmlspecialchars($error) ?></h3><?php endif; ?>
            <div class="mt-4">
                 <a href="caller_panel.php" class="btn btn-primary"><i class="bi bi-arrow-left-circle me-2"></i>Back to Caller Panel</a>
                 <a href="view_final_logs.php" class="btn btn-secondary"><i class="bi bi-card-list me-2"></i>View All Logs</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>