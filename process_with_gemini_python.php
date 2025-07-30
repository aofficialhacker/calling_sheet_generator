<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

const DISPOSITION_MAP = [
    '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up', '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped',
    '21' => 'Ringing', '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy',
];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$results = []; $error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['markedSheet'])) {
    $uploadDir = 'marked_uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $targetFile = $uploadDir . uniqid() . '-' . basename($_FILES['markedSheet']['name']);
    
    if (move_uploaded_file($_FILES['markedSheet']['tmp_name'], $targetFile)) {
        $command = "python gemini_omr_parser.py " . escapeshellarg($targetFile);
        $output = shell_exec($command);

        $lines = explode("\n", trim($output));
        $headers = !empty($lines) ? str_getcsv(array_shift($lines)) : false;

        if (!$headers || (isset($headers[0]) && strtolower(trim(str_replace('_', '', $headers[0]))) !== 'mobileno')) {
            $error_check = json_decode($output, true);
            if ($error_check && isset($error_check['error'])) {
                 $error = "Python script failed: " . htmlspecialchars($error_check['error']);
            } else {
                 $error = "AI script returned an invalid header format. Expected 'mobile_no' as first column. Response: <pre>" . htmlspecialchars($output) . "</pre>";
            }
        } else {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) { die("DB Connection Failed."); }

            $stmt = $conn->prepare("SELECT name FROM temp_processed_data WHERE mobile_no = ?");
            if ($stmt === false) { die("Prepare statement failed: " . $conn->error); }

            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $row_data = str_getcsv($line);
                if (count($row_data) === count($headers)) {
                    $parsed_data = array_combine($headers, $row_data);
                    $mobile_no = preg_replace('/\D/', '', $parsed_data['mobile_no'] ?? '');
                    if (!empty($mobile_no)) {
                        $stmt->bind_param("s", $mobile_no);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $customer = $res->fetch_assoc();
                        $parsed_data['customer_name'] = $customer['name'] ?? 'Unknown Mobile No';
                        $parsed_data['mobile_no'] = $mobile_no; // Ensure it's the cleaned version
                        $results[] = $parsed_data;
                    }
                }
            }
            $stmt->close(); $conn->close();
            
            if (empty($results)) {
                $error = "AI processing finished, but no valid data rows could be detected in the image. Please try again with a clearer, well-lit picture.";
            }
        }
        unlink($targetFile);
    } else { $error = "File upload error."; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>Capture & Process Marked Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.camera-container{border:2px dashed #0d6efd;border-radius:.5rem;padding:1.5rem;text-align:center;cursor:pointer;background-color:#f8f9fa;transition:background-color .2s}.camera-container:hover{background-color:#e9ecef}#imagePreview{max-width:100%;max-height:400px;border-radius:.5rem;margin-top:1rem;border:1px solid #dee2e6}.form-label-icon{font-size:3rem;color:#0d6efd}</style>
</head>
<body>
<div class="container mt-4 mb-5">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"><h2 class="h4 mb-0">Capture Marked Sheet</h2><a href="index.php" class="btn btn-light btn-sm">Back to Dashboard</a></div>
        <div class="card-body">
            <p class="text-muted">Capture a photo of the sheet, ensuring the "Mobile No", "Connectivity", and "Disposition" columns are clear.</p>
            <form id="captureForm" action="process_with_gemini_python.php" method="post" enctype="multipart/form-data">
                <label for="markedSheet" class="camera-container" id="cameraLabel"><div class="form-label-icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" fill="currentColor" class="bi bi-camera-fill" viewBox="0 0 16 16"><path d="M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0"/><path d="M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828-.828A2 2 0 0 1 3.172 4H2Zm.5 4a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/></svg></div><h5 class="mt-2">Tap here to open Camera</h5></label>
                <input class="form-control d-none" type="file" name="markedSheet" id="markedSheet" accept="image/*" capture="environment" required>
                <div id="previewContainer" class="text-center mt-3" style="display: none;"><img id="imagePreview" src="#" alt="Image Preview"/><p class="mt-2 text-muted">Image captured. Click Process to continue.</p></div>
                <div class="d-grid mt-4"><button id="submitButton" type="submit" class="btn btn-primary btn-lg" disabled>Process with AI</button></div>
            </form>
        </div>
    </div>
    <?php if ($error): ?><div class="alert alert-danger mt-4"><?= $error ?></div><?php endif; ?>
    <?php if (!empty($results)): ?>
    <div class="card mt-5 shadow-sm">
        <div class="card-header"><h3>AI Processing Results</h3><p class="mb-0">Please review the data. If correct, click "Save" to move it to the permanent log.</p></div>
        <div class="card-body">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Customer Name</th><th>Mobile No</th><th>Connectivity</th><th>Disposition</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['customer_name']) ?></td>
                            <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                            <td><?= htmlspecialchars(CONNECTIVITY_MAP[$row['connectivity_code']] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(DISPOSITION_MAP[$row['disposition_code']] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form action="save_final_log.php" method="post" class="mt-3">
                <input type="hidden" name="json_results" value='<?= htmlspecialchars(json_encode($results), ENT_QUOTES, 'UTF-8') ?>'>
                <button type="submit" class="btn btn-success">Confirm & Save to Final Log</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
    const markedSheetInput=document.getElementById("markedSheet"),imagePreview=document.getElementById("imagePreview"),previewContainer=document.getElementById("previewContainer"),cameraLabel=document.getElementById("cameraLabel"),submitButton=document.getElementById("submitButton");
    markedSheetInput.addEventListener("change",function(e){const t=e.target.files[0];if(t){const n=new FileReader;n.onload=function(e){imagePreview.src=e.target.result},n.readAsDataURL(t),previewContainer.style.display="block",cameraLabel.style.display="none",submitButton.disabled=!1}});
</script>
</body>
</html>