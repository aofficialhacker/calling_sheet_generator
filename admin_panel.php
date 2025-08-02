<?php
session_start();
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// --- Database Configuration ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// --- Helper Functions ---
function formatDateString($value): string {
    if (empty($value)) return '';
    // Check for Excel's numeric date format
    if (is_numeric($value) && $value > 25569) {
        try {
            return Date::excelToDateTimeObject($value)->format('d-m-Y');
        } catch (Exception $e) {
            // Fall through if not a valid Excel date
        }
    }
    // Check for string date formats
    if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('d-m-Y', $timestamp);
        }
    }
    // Return as-is if no format matches
    return (string)$value;
}

function mapColumns(array $headerRow): array {
    $map = ['title' => -1, 'name' => -1, 'age' => -1, 'mobile_no' => -1, 'policy_number' => -1, 'pan' => -1, 'dob' => -1, 'expiry' => -1, 'address' => -1, 'address2' => -1, 'address3' => -1, 'city' => -1, 'state' => -1, 'country' => -1, 'pincode' => -1, 'plan' => -1, 'premium' => -1, 'sum_insured' => -1];
    foreach ($headerRow as $index => $header) {
        if (is_null($header)) continue;
        $normalizedHeader = strtolower(trim(str_replace(['_', ' '], '', $header)));
        if (empty($normalizedHeader)) continue;
        switch (true) {
            case ($map['mobile_no'] === -1 && preg_match('/mobile|phone|cell/i', $normalizedHeader)): $map['mobile_no'] = $index; break;
            case ($map['title'] === -1 && preg_match('/^title$/i', $normalizedHeader)): $map['title'] = $index; break;
            case ($map['name'] === -1 && preg_match('/name|insured/i', $normalizedHeader)): $map['name'] = $index; break;
            case ($map['age'] === -1 && preg_match('/^age$/i', $normalizedHeader)): $map['age'] = $index; break;
            case ($map['policy_number'] === -1 && preg_match('/policy(number)?/i', $normalizedHeader)): $map['policy_number'] = $index; break;
            case ($map['pan'] === -1 && preg_match('/pan/i', $normalizedHeader)): $map['pan'] = $index; break;
            case ($map['dob'] === -1 && preg_match('/dob|birth/i', $normalizedHeader)): $map['dob'] = $index; break;
            case ($map['expiry'] === -1 && preg_match('/expiry/i', $normalizedHeader)): $map['expiry'] = $index; break;
            case ($map['address'] === -1 && preg_match('/(cadd1|address|add\b)/i', $normalizedHeader)): $map['address'] = $index; break;
            case ($map['address2'] === -1 && preg_match('/cadd2/i', $normalizedHeader)): $map['address2'] = $index; break;
            case ($map['address3'] === -1 && preg_match('/cadd3/i', $normalizedHeader)): $map['address3'] = $index; break;
            case ($map['city'] === -1 && preg_match('/city|ccity/i', $normalizedHeader)): $map['city'] = $index; break;
            case ($map['state'] === -1 && preg_match('/state|cstate/i', $normalizedHeader)): $map['state'] = $index; break;
            case ($map['country'] === -1 && preg_match('/country|ccntry/i', $normalizedHeader)): $map['country'] = $index; break;
            case ($map['pincode'] === -1 && preg_match('/pin|pincode|cpin/i', $normalizedHeader)): $map['pincode'] = $index; break;
            case ($map['plan'] === -1 && preg_match('/plan(name)?/i', $normalizedHeader)): $map['plan'] = $index; break;
            case ($map['premium'] === -1 && preg_match('/premium/i', $normalizedHeader)): $map['premium'] = $index; break;
            case ($map['sum_insured'] === -1 && preg_match('/sum(insured)?/i', $normalizedHeader)): $map['sum_insured'] = $index; break;
        }
    }
    return $map;
}

// --- FILE UPLOAD PROCESSING ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['customerFile'])) {
    set_time_limit(300);

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $originalFileName = basename($_FILES['customerFile']['name']);
    $originalFile = $uploadDir . uniqid() . '-' . $originalFileName;

    if (move_uploaded_file($_FILES['customerFile']['tmp_name'], $originalFile)) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) { die("DB Connection Failed: " . $conn->connect_error); }

        try {
            $conn->begin_transaction();
            $batch_stmt = $conn->prepare("INSERT INTO file_batches (original_filename) VALUES (?)");
            $batch_stmt->bind_param("s", $originalFileName);
            $batch_stmt->execute();
            $batch_id = $conn->insert_id;
            $batch_stmt->close();

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($originalFile);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($originalFile);
            $dataRows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            $headerRow = array_shift($dataRows);
            $columnMap = mapColumns($headerRow);

            $sql = "INSERT INTO final_call_logs (mobile_no, source_filename, batch_id, title, name, policy_number, pan, dob, age, expiry, address, city, state, country, pincode, plan, premium, sum_insured, extra_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        name=VALUES(name), 
                        policy_number=VALUES(policy_number), 
                        pan=VALUES(pan), 
                        batch_id=VALUES(batch_id), 
                        source_filename=VALUES(source_filename)";

            $stmt = $conn->prepare($sql);
            if ($stmt === false) { throw new Exception("Prepare failed (INSERT): " . $conn->error); }

            foreach ($dataRows as $dataRow) {
                if (empty(implode('', $dataRow))) continue;
                $newRow = [];
                foreach ($columnMap as $standardKey => $mappedIndex) {
                    if ($mappedIndex !== -1 && isset($dataRow[$mappedIndex])) {
                        $cellValue = $dataRow[$mappedIndex];
                        if ($standardKey === 'dob' || $standardKey === 'expiry') { $newRow[$standardKey] = formatDateString($cellValue); } 
                        else { $newRow[$standardKey] = (string) $cellValue; }
                    }
                }
                if (empty($newRow['mobile_no'])) { continue; }
                
                $mobile_no = preg_replace('/\D/', '', $newRow['mobile_no']);
                $title = $newRow['title'] ?? null;
                $name = $newRow['name'] ?? null;
                $policy_number = $newRow['policy_number'] ?? null;
                $pan = $newRow['pan'] ?? null;
                $dob = $newRow['dob'] ?? null;
                $age = (isset($newRow['age']) && is_numeric($newRow['age'])) ? (int)$newRow['age'] : null;
                $expiry = $newRow['expiry'] ?? null;
                $address = $newRow['address'] ?? null;
                $city = $newRow['city'] ?? null;
                $state = $newRow['state'] ?? null;
                $country = $newRow['country'] ?? null;
                $pincode = $newRow['pincode'] ?? null;
                $plan = $newRow['plan'] ?? null;
                $premium = $newRow['premium'] ?? null;
                $sum_insured = $newRow['sum_insured'] ?? null;
                $extraData = null;
                
                $stmt->bind_param("ssisssssissssssssss", $mobile_no, $originalFileName, $batch_id, $title, $name, $policy_number, $pan, $dob, $age, $expiry, $address, $city, $state, $country, $pincode, $plan, $premium, $sum_insured, $extraData);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();
            
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Batch DB{$batch_id} created successfully from {$originalFileName}. You can now download its PDF."];
            if (file_exists($originalFile)) unlink($originalFile);

        } catch (Exception $e) {
            if (isset($conn) && $conn->ping()) { $conn->rollback(); }
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Error processing file: ' . $e->getMessage()];
        } finally {
            if (isset($conn) && $conn->ping()) { $conn->close(); }
            header("Location: admin_panel.php");
            exit();
        }
    } else {
        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'There was a problem uploading your file.'];
        header("Location: admin_panel.php");
        exit();
    }
}

// --- Fetch data for dashboard, batches table, and dispositions ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection Failed"); }

// Caller performance data
$perf_sql = "SELECT 
                finqy_id,
                COUNT(*) as total_calls,
                SUM(CASE WHEN connectivity = 'Yes' THEN 1 ELSE 0 END) as connected,
                SUM(CASE WHEN connectivity = 'No' THEN 1 ELSE 0 END) as not_connected,
                SUM(CASE WHEN disposition = 'Interested' THEN 1 ELSE 0 END) as interested,
                SUM(CASE WHEN disposition = 'Follow Up' THEN 1 ELSE 0 END) as follow_up,
                SUM(CASE WHEN disposition IS NULL AND finqy_id IS NOT NULL THEN 1 ELSE 0 END) as empty_disposition,
                MAX(processed_at) as last_activity
             FROM final_call_logs 
             WHERE finqy_id IS NOT NULL
             GROUP BY finqy_id 
             ORDER BY total_calls DESC";
$performance_data = $conn->query($perf_sql);

// Uploaded batches data
$batches_sql = "SELECT b.id, b.original_filename, b.upload_time, COUNT(f.id) as record_count 
               FROM file_batches b
               LEFT JOIN final_call_logs f ON b.id = f.batch_id
               GROUP BY b.id, b.original_filename, b.upload_time
               ORDER BY b.id DESC";
$batches_data = $conn->query($batches_sql);

// Available dispositions for download dropdown
$dispo_sql = "SELECT DISTINCT disposition FROM final_call_logs WHERE disposition IS NOT NULL AND disposition != '' AND disposition != 'Interested' ORDER BY disposition ASC";
$dispositions_result = $conn->query($dispo_sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        #loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); z-index: 1050; display: none; justify-content: center; align-items: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; animation: spin 1.5s linear infinite; }
        .loading-text { color: white; margin-top: 20px; font-size: 1.2rem; font-weight: bold; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="loading-text" id="loading-message">Processing, please wait...</p></div>
    <div class="container mt-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2"><i class="bi bi-shield-lock-fill me-2"></i>Admin Panel</h1>
            <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-2"></i>Back to Main Menu</a>
        </div>
        
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['flash_message']['text']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['flash_message']); ?>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white"><h3 class="h5 mb-0"><i class="bi bi-graph-up me-2"></i>Admin Dashboard</h3></div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
                    <h4 class="card-title mb-0">Caller Performance</h4>
                    <form class="d-flex gap-2" id="disposition-download-form" action="generate_pdf.php" method="GET">
                        <select class="form-select form-select-sm" name="disposition" id="disposition-select" required>
                            <option value="" disabled selected>-- Select Status to Download --</option>
                            <?php if ($dispositions_result && $dispositions_result->num_rows > 0): ?>
                                <?php while($row = $dispositions_result->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($row['disposition']) ?>"><?= htmlspecialchars($row['disposition']) ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                        <button type="button" class="btn btn-info btn-sm text-white flex-shrink-0" id="download-disposition-btn">
                            <i class="bi bi-download me-1"></i> Download by Status
                        </button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-bordered">
                        <thead class="table-light">
                             <tr>
                                <th>Caller (FinqyID)</th>
                                <th>Total Logged</th>
                                <th>Connected</th>
                                <th>Not Connected</th>
                                <th>Interested</th>
                                <th>Follow Up</th>
                                <th>Empty Dispo</th>
                                <th>Last Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($performance_data && $performance_data->num_rows > 0): ?>
                                <?php while($row = $performance_data->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['finqy_id']) ?></strong></td>
                                    <td><?= (int)$row['total_calls'] ?></td>
                                    <td class="text-success fw-bold"><?= (int)$row['connected'] ?></td>
                                    <td class="text-danger"><?= (int)$row['not_connected'] ?></td>
                                    <td><?= (int)$row['interested'] ?></td>
                                    <td><?= (int)$row['follow_up'] ?></td>
                                    <td class="text-warning"><?= (int)$row['empty_disposition'] ?></td>
                                    <td><?= $row['last_activity'] ? date('d-M-Y H:i', strtotime($row['last_activity'])) : 'N/A' ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted">No caller performance data available yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                 <div class="card shadow-sm h-100">
                    <div class="card-header bg-secondary text-white"><h3 class="h5 mb-0"><i class="bi bi-stack me-2"></i>Uploaded Batches (DB Sets)</h3></div>
                    <div class="card-body">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Filename</th>
                                        <th>Records</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($batches_data && $batches_data->num_rows > 0): ?>
                                        <?php while($row = $batches_data->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="badge bg-primary fs-6">DB<?= htmlspecialchars($row['id']) ?></span></td>
                                            <td title="<?= htmlspecialchars($row['original_filename']) . ' (Uploaded: ' . date('d-M-Y H:i', strtotime($row['upload_time'])) . ')' ?>"><?= htmlspecialchars(substr($row['original_filename'], 0, 25)) . (strlen($row['original_filename']) > 25 ? '...' : '') ?></td>
                                            <td><?= htmlspecialchars($row['record_count']) ?></td>
                                            <td>
                                                <a href="generate_pdf.php?batch_id=<?= $row['id'] ?>" class="btn btn-danger btn-sm download-pdf-btn" title="Download PDF for this batch">
                                                    <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted">No batches have been uploaded yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-primary text-white"><h3 class="h5 mb-0"><i class="bi bi-cloud-upload-fill me-2"></i>Create New Batch</h3></div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Upload a source file. The data will be saved, and you can download the PDF from the list on the left.</p>
                        <form action="admin_panel.php" method="post" enctype="multipart/form-data" class="mt-auto" id="upload-form">
                            <div class="mb-3">
                                <label for="customerFile" class="form-label"><strong>Select Source File:</strong></label>
                                <input class="form-control" type="file" id="customerFile" name="customerFile" accept=".xlsx, .csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-gear-fill me-2"></i>Upload and Create Batch</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const uploadForm = document.getElementById('upload-form');
            const loadingOverlay = document.getElementById('loading-overlay');
            const loadingMessage = document.getElementById('loading-message');

            if (uploadForm) {
                uploadForm.addEventListener('submit', function() {
                    if (document.getElementById('customerFile').files.length > 0) {
                        loadingMessage.textContent = 'Uploading and processing file... This may take a while.';
                        loadingOverlay.style.display = 'flex';
                    }
                });
            }

            function getCookie(name) {
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
            }
            
            // Generic function to initiate download and show loading overlay
            const startPdfDownload = function(url) {
                loadingMessage.textContent = 'Generating PDF... Please wait.';
                loadingOverlay.style.display = 'flex';

                const downloadToken = new Date().getTime();
                const cookieName = `download_token_${downloadToken}`;
                
                // Append the unique token to the URL
                const finalUrl = url + (url.includes('?') ? '&' : '?') + `download_token=${downloadToken}`;
                
                // Start the download
                window.location.href = finalUrl;

                // Poll for the cookie to hide the overlay
                const timer = setInterval(function() {
                    if (getCookie(cookieName)) {
                        loadingOverlay.style.display = 'none';
                        clearInterval(timer);
                        // Clean up cookie
                        document.cookie = `${cookieName}=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;`;
                    }
                }, 1000); // Check every second

                // Failsafe to hide overlay after 20 seconds
                setTimeout(() => {
                    clearInterval(timer);
                    loadingOverlay.style.display = 'none';
                }, 20000);
            };

            // Attach handler to the batch PDF download buttons
            document.querySelectorAll('.download-pdf-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    startPdfDownload(this.href);
                });
            });
            
            // Attach handler to the new "Download by Status" button
            const dispoBtn = document.getElementById('download-disposition-btn');
            if(dispoBtn) {
                dispoBtn.addEventListener('click', function() {
                    const form = document.getElementById('disposition-download-form');
                    const select = document.getElementById('disposition-select');
                    if (select.value) { // Only proceed if a status is selected
                        const url = form.action + '?disposition=' + encodeURIComponent(select.value);
                        startPdfDownload(url);
                    } else {
                        alert('Please select a status from the dropdown first.');
                    }
                });
            }
        });
    </script>
</body>
</html>