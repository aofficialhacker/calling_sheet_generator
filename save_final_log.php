<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');
const DISPOSITION_MAP = [
    '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up',
    '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing',
    '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy',
];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("DB Connection Failed: " . $conn->connect_error); }

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['json_results'])) {
    $results = json_decode($_POST['json_results'], true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($results)) {
        $conn->begin_transaction();
        try {
            $select_stmt = $conn->prepare("SELECT * FROM temp_processed_data WHERE uuid = ?");
            $insert_stmt = $conn->prepare("INSERT INTO final_call_logs (source_uuid, source_filename, connectivity, disposition, title, name, mobile_no, policy_number, pan, dob, expiry, address, city, state, country, pincode, plan, premium, sum_insured, extra_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $delete_stmt = $conn->prepare("DELETE FROM temp_processed_data WHERE uuid = ?");

            $saved_count = 0;
            foreach ($results as $row) {
                $uuid = $row['unique_id'];
                if (empty($uuid)) continue;
                $select_stmt->bind_param("s", $uuid);
                $select_stmt->execute();
                $source_data = $select_stmt->get_result()->fetch_assoc();
                if ($source_data) {
                    $connectivity = CONNECTIVITY_MAP[$row['connectivity_code']] ?? null;
                    $disposition = DISPOSITION_MAP[$row['disposition_code']] ?? null;
                    $insert_stmt->bind_param("ssssssssssssssssssss", $source_data['uuid'], $source_data['source_filename'], $connectivity, $disposition, $source_data['title'], $source_data['name'], $source_data['mobile_no'], $source_data['policy_number'], $source_data['pan'], $source_data['dob'], $source_data['expiry'], $source_data['address'], $source_data['city'], $source_data['state'], $source_data['country'], $source_data['pincode'], $source_data['plan'], $source_data['premium'], $source_data['sum_insured'], $source_data['extra_data']);
                    $insert_stmt->execute();
                    $delete_stmt->bind_param("s", $uuid);
                    $delete_stmt->execute();
                    $saved_count++;
                }
            }
            $conn->commit();
            $message = "Successfully processed and saved " . $saved_count . " records to the final log.";
            $select_stmt->close();
            $insert_stmt->close();
            $delete_stmt->close();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $error = "A database transaction error occurred: " . $exception->getMessage();
        }
    } else { $error = "Invalid data received."; }
} else { $error = "No data submitted."; }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><title>Save Final Log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="card text-center shadow-sm">
        <div class="card-body p-4">
            <?php if ($message): ?>
                <h3 class="text-success"><?= htmlspecialchars($message) ?></h3>
                <a href="process_with_gemini_python.php" class="btn btn-primary mt-3">Process Another Sheet</a>
            <?php endif; ?>
            <?php if ($error): ?>
                <h3 class="text-danger"><?= htmlspecialchars($error) ?></h3>
                <a href="process_with_gemini_python.php" class="btn btn-secondary mt-3">Try Again</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>