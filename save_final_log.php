<?php
// DB and MAP constants remain the same
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

const DISPOSITION_MAP = [ '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up', '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing', '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy', ];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("DB Connection Failed."); }

$message = ''; $error = '';
// Check for finqy_id from the form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results']) && isset($_POST['finqy_id'])) {
    $results = json_decode($_POST['json_results'], true);
    $finqy_id = $_POST['finqy_id']; // Get the finqy_id

    if (json_last_error() === JSON_ERROR_NONE && !empty($results) && !empty($finqy_id)) {
        $conn->begin_transaction();
        try {
            $select_stmt = $conn->prepare("SELECT * FROM temp_processed_data WHERE mobile_no = ?");
            if ($select_stmt === false) throw new Exception("Prepare failed (SELECT): " . $conn->error);
            
            // INSERT statement with 22 columns
            $insert_stmt = $conn->prepare("INSERT INTO final_call_logs (mobile_no, source_filename, connectivity, disposition, slot, finqy_id, title, name, policy_number, pan, dob, age, expiry, address, city, state, country, pincode, plan, premium, sum_insured, extra_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($insert_stmt === false) throw new Exception("Prepare failed (INSERT): " . $conn->error);
            
            $delete_stmt = $conn->prepare("DELETE FROM temp_processed_data WHERE mobile_no = ?");
            if ($delete_stmt === false) throw new Exception("Prepare failed (DELETE): " . $conn->error);
            
            $saved_count = 0;
            foreach ($results as $row) {
                $mobile_no = $row['mobile_no'] ?? null;
                if (empty($mobile_no)) continue;
                $select_stmt->bind_param("s", $mobile_no);
                $select_stmt->execute();
                $source_data = $select_stmt->get_result()->fetch_assoc();
                if ($source_data) {
                    $connectivity = CONNECTIVITY_MAP[$row['connectivity_code']] ?? null; 
                    $disposition = DISPOSITION_MAP[$row['disposition_code']] ?? null; 
                    $slot = !empty($row['slot']) ? (int)$row['slot'] : null;
                    
                    // --- THIS IS THE CORRECTION ---
                    // The type string now has 22 characters (s-string, i-integer) that correctly
                    // match the 22 variables being passed in order.
                    $insert_stmt->bind_param("ssssisssssisssssssssss", 
                        $source_data['mobile_no'],       // s
                        $source_data['source_filename'], // s
                        $connectivity,                  // s
                        $disposition,                   // s
                        $slot,                          // i
                        $finqy_id,                      // s (The new one)
                        $source_data['title'],           // s
                        $source_data['name'],            // s
                        $source_data['policy_number'],   // s
                        $source_data['pan'],             // s
                        $source_data['dob'],             // s
                        $source_data['age'],             // i
                        $source_data['expiry'],          // s
                        $source_data['address'],         // s
                        $source_data['city'],            // s
                        $source_data['state'],           // s
                        $source_data['country'],         // s
                        $source_data['pincode'],         // s
                        $source_data['plan'],            // s
                        $source_data['premium'],         // s
                        $source_data['sum_insured'],     // s
                        $source_data['extra_data']       // s
                    );
                    // --- END OF CORRECTION ---

                    $insert_stmt->execute();
                    $delete_stmt->bind_param("s", $mobile_no);
                    $delete_stmt->execute();
                    $saved_count++;
                }
            }
            $conn->commit();
            $message = "Successfully processed and saved " . $saved_count . " records to the final log.";
            $select_stmt->close(); $insert_stmt->close(); $delete_stmt->close();
        } catch (Exception $exception) { $conn->rollback(); $error = "A database transaction error occurred: " . $exception->getMessage(); }
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
                 <a href="view_performance.php" class="btn btn-info text-white"><i class="bi bi-bar-chart-line-fill me-2"></i>View My Performance</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>