<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// Shared data maps
const DISPOSITION_MAP = [
    '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up',
    '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing',
    '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy',
];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results'])) {
    $results = json_decode($_POST['json_results'], true);

    if (json_last_error() === JSON_ERROR_NONE && !empty($results)) {
        // Begin a transaction for data integrity
        $conn->begin_transaction();
        try {
            // Prepare all statements once before the loop for efficiency
            $select_stmt = $conn->prepare("SELECT * FROM temp_processed_data WHERE unique_id = ?");
            if ($select_stmt === false) {
                 throw new Exception("Prepare failed (SELECT): " . $conn->error);
            }

            // --- START: CRITICAL CORRECTION ---
            // The INSERT statement must list all columns we intend to fill.
            // This now correctly includes all 22 columns from the temp table + AI results.
            $insert_stmt = $conn->prepare("INSERT INTO final_call_logs (
                source_uuid, source_filename, connectivity, disposition, slot,
                title, name, mobile_no, policy_number, pan, dob, age, expiry,
                address, city, state, country, pincode, plan, premium, sum_insured, extra_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($insert_stmt === false) {
                 throw new Exception("Prepare failed (INSERT): " . $conn->error . ". Please ensure the 'final_call_logs' table exists and has the correct columns.");
            }
            // --- END: CRITICAL CORRECTION ---

            $delete_stmt = $conn->prepare("DELETE FROM temp_processed_data WHERE unique_id = ?");
            if ($delete_stmt === false) {
                 throw new Exception("Prepare failed (DELETE): " . $conn->error);
            }

            $saved_count = 0;
            foreach ($results as $row) {
                $unique_id = $row['unique_id'] ?? null;
                if (empty($unique_id)) {
                    continue; // Skip rows with no ID
                }

                // 1. SELECT the full data row from the temporary table
                $select_stmt->bind_param("s", $unique_id);
                $select_stmt->execute();
                $source_data = $select_stmt->get_result()->fetch_assoc();

                if ($source_data) {
                    // Prepare data from AI results. Handle potential empty values.
                    $connectivity = CONNECTIVITY_MAP[$row['connectivity_code']] ?? null;
                    $disposition = DISPOSITION_MAP[$row['disposition_code']] ?? null;
                    $slot = !empty($row['slot']) ? (int)$row['slot'] : null;

                    // --- START: CRITICAL CORRECTION ---
                    // The `bind_param` call must have a type string and variable list
                    // that perfectly matches the INSERT statement's columns in order and count (22).
                    // Type string: s-string, i-integer.
                    $insert_stmt->bind_param("ssssisssississssssssss",
                        $source_data['unique_id'],
                        $source_data['source_filename'],
                        $connectivity,
                        $disposition,
                        $slot,
                        $source_data['title'],
                        $source_data['name'],
                        $source_data['mobile_no'],
                        $source_data['policy_number'],
                        $source_data['pan'],
                        $source_data['dob'],
                        $source_data['age'],
                        $source_data['expiry'],
                        $source_data['address'],
                        $source_data['city'],
                        $source_data['state'],
                        $source_data['country'],
                        $source_data['pincode'],
                        $source_data['plan'],
                        $source_data['premium'],
                        $source_data['sum_insured'],
                        $source_data['extra_data']
                    );
                    // --- END: CRITICAL CORRECTION ---

                    // 2. INSERT the combined data into the final log
                    $insert_stmt->execute();

                    // 3. DELETE the record from the temporary table
                    $delete_stmt->bind_param("s", $unique_id);
                    $delete_stmt->execute();

                    $saved_count++;
                }
            }

            // If all operations were successful, commit the transaction
            $conn->commit();
            $message = "Successfully processed and saved " . $saved_count . " records to the final log.";

            // Close the prepared statements
            $select_stmt->close();
            $insert_stmt->close();
            $delete_stmt->close();

        } catch (Exception $exception) {
            // If any operation fails, roll back the entire transaction
            $conn->rollback();
            $error = "A database transaction error occurred: " . $exception->getMessage();
        }

    } else {
        $error = "Invalid or empty data received. Nothing was saved.";
    }
} else {
    $error = "No data submitted. Please process a sheet first.";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Save Final Log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="card text-center shadow-sm">
        <div class="card-body p-4">
            <?php if ($message): ?>
                <h3 class="text-success"><?= htmlspecialchars($message) ?></h3>
                <p>The processed records have been moved from the temporary staging area to the permanent log.</p>
            <?php endif; ?>
            <?php if ($error): ?>
                <h3 class="text-danger">An Error Occurred</h3>
                <p class="text-muted"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            <div class="mt-4">
                 <a href="process_with_gemini_python.php" class="btn btn-primary">Process Another Sheet</a>
                 <a href="view_final_logs.php" class="btn btn-info text-white">View All Final Logs</a>
                 <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>