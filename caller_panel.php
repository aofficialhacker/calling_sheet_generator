<?php
session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// --- LOGIN & LOGOUT LOGIC ---
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['finqy_id'])) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) { die("DB Connection Failed."); }
    
    $stmt = $conn->prepare("SELECT finqy_id, caller_name FROM callers WHERE finqy_id = ? AND is_active = 1");
    $stmt->bind_param("s", $_POST['finqy_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $_SESSION['finqy_id'] = $user['finqy_id'];
        $_SESSION['caller_name'] = $user['caller_name'];
    } else {
        $login_error = "Invalid or inactive FinqyID. Please try again.";
    }
    $stmt->close();
    $conn->close();
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_unset();
    session_destroy();
    header("Location: caller_panel.php");
    exit();
}

// --- AI PROCESSING LOGIC (from previous version) ---
const DISPOSITION_MAP = [ '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up', '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing', '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy', ];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$results = []; $error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['markedSheet'])) {
    if (!isset($_SESSION['finqy_id'])) {
        die("Authentication error. Please log in again.");
    }
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
            if ($error_check && isset($error_check['error'])) { $error = "Python script failed: " . htmlspecialchars($error_check['error']); }
            else { $error = "AI script returned an invalid header format. Expected 'mobile_no' as first column. Response: <pre>" . htmlspecialchars($output) . "</pre>"; }
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
                        $parsed_data['mobile_no'] = $mobile_no;
                        $results[] = $parsed_data;
                    }
                }
            }
            $stmt->close(); $conn->close();
            if (empty($results)) { $error = "AI processing finished, but no valid data rows could be detected in the image."; }
        }
        unlink($targetFile);
    } else { $error = "File upload error."; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>Caller Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .camera-container{border:2px dashed #0dcaf0;border-radius:.5rem;padding:1.5rem;text-align:center;cursor:pointer;background-color:#f8f9fa;transition:background-color .2s}.camera-container:hover{background-color:#e2f8fd}#imagePreview{max-width:100%;max-height:400px;border-radius:.5rem;margin-top:1rem;border:1px solid #dee2e6}.form-label-icon{font-size:3rem;color:#0dcaf0}
        .panel-card { transition: all 0.3s ease; border: none; }
        .panel-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="container mt-4 mb-5">
    <?php if (!isset($_SESSION['finqy_id'])): // --- SHOW LOGIN FORM --- ?>
    <div class="row justify-content-center">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <h1 class="h3 fw-bold">Caller Panel Login</h1>
                        <p class="text-muted">Please enter your FinqyID to continue</p>
                    </div>
                    <form action="caller_panel.php" method="POST">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="finqy_id" name="finqy_id" placeholder="e.g., FINQY001" required>
                            <label for="finqy_id">FinqyID</label>
                        </div>
                        <?php if ($login_error): ?>
                            <div class="alert alert-danger py-2"><?= $login_error ?></div>
                        <?php endif; ?>
                        <div class="d-grid">
                            <button class="btn btn-primary btn-lg" type="submit">Login</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php else: // --- SHOW LOGGED-IN DASHBOARD --- ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-telephone-inbound-fill me-2"></i>Caller Panel</h1>
            <span class="text-muted">Welcome, <?= htmlspecialchars($_SESSION['caller_name']) ?>!</span>
        </div>
        <a href="?action=logout" class="btn btn-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
    </div>

    <?php if (empty($results) && empty($error)): // Show options if no file has been processed yet ?>
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card panel-card text-center h-100 shadow-sm">
                <div class="card-body p-4">
                    <h3 class="card-title"><i class="bi bi-camera2 fs-1 text-info"></i><br>Upload Marked Sheet</h3>
                    <p class="text-muted">Process a new sheet by uploading its photo.</p>
                    <button class="btn btn-info text-white" data-bs-toggle="collapse" data-bs-target="#uploadCollapse">Start Upload</button>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card panel-card text-center h-100 shadow-sm">
                <div class="card-body p-4">
                    <h3 class="card-title"><i class="bi bi-bar-chart-line-fill fs-1 text-success"></i><br>View Performance</h3>
                    <p class="text-muted">Check your call statistics and recent activity.</p>
                    <a href="view_performance.php" class="btn btn-success">View My Performance</a>
                </div>
            </div>
        </div>
    </div>
    <div class="collapse" id="uploadCollapse">
        <div class="card shadow-sm mt-4">
            <div class="card-header"><h3 class="h5 mb-0">Upload Marked Sheet</h3></div>
            <div class="card-body">
                <form id="captureForm" action="caller_panel.php" method="post" enctype="multipart/form-data">
                    <label for="markedSheet" class="camera-container" id="cameraLabel">
                        <div class="form-label-icon"><i class="bi bi-camera2"></i></div>
                        <h5 class="mt-2 text-info">Tap here to open Camera or select file</h5>
                    </label>
                    <input class="form-control d-none" type="file" name="markedSheet" id="markedSheet" accept="image/*" capture="environment" required>
                    <div id="previewContainer" class="text-center mt-3" style="display: none;"><img id="imagePreview" src="#" alt="Image Preview"/><p class="mt-2 text-muted">Image captured. Click Process to continue.</p></div>
                    <div class="d-grid mt-4"><button id="submitButton" type="submit" class="btn btn-info btn-lg text-white" disabled><i class="bi bi-magic me-2"></i>Process with AI</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-danger mt-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?> <a href="caller_panel.php" class="alert-link">Try again</a>.</div><?php endif; ?>
    
    <?php if (!empty($results)): ?>
    <div class="card mt-4 shadow-sm">
        <div class="card-header"><h3><i class="bi bi-clipboard-check-fill me-2"></i>AI Processing Results</h3><p class="mb-0 text-muted">Please review the data. If correct, click "Confirm & Save" to log these results under your ID.</p></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light"><tr><th>Customer Name</th><th>Mobile No</th><th>Connectivity</th><th>Disposition</th></tr></thead>
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
            </div>
            <form action="save_final_log.php" method="post" class="mt-3">
                <input type="hidden" name="json_results" value='<?= htmlspecialchars(json_encode($results), ENT_QUOTES, 'UTF-8') ?>'>
                <!-- Pass the FinqyID to the save script -->
                <input type="hidden" name="finqy_id" value="<?= htmlspecialchars($_SESSION['finqy_id']) ?>">
                <button type="submit" class="btn btn-success"><i class="bi bi-save-fill me-2"></i>Confirm & Save to Final Log</button>
                <a href="caller_panel.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // --- END LOGGED-IN DASHBOARD --- ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    if(document.getElementById("markedSheet")) {
        const markedSheetInput=document.getElementById("markedSheet"),imagePreview=document.getElementById("imagePreview"),previewContainer=document.getElementById("previewContainer"),cameraLabel=document.getElementById("cameraLabel"),submitButton=document.getElementById("submitButton");
        markedSheetInput.addEventListener("change",function(e){const t=e.target.files[0];if(t){const n=new FileReader;n.onload=function(e){imagePreview.src=e.target.result},n.readAsDataURL(t),previewContainer.style.display="block",cameraLabel.style.display="none",submitButton.disabled=!1}});
    }
</script>
</body>
</html>