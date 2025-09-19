<?php
require_once 'db_config.php';
require_once 'blocklist_utils.php';
require_once 'mobile_duplication_utils.php';
requireAdmin();
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// --- FILE UPLOAD PROCESSING ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_FILES['customerFile']) || isset($_FILES['customerImage']))) {
    set_time_limit(600);
    $conn = getDBConnection();
    
    $vendorId = $_POST['vendor_id'] ?? null;
    $productId = $_POST['product_id'] ?? null;
    $adminId = $_SESSION['admin_id'];

    if (!$vendorId || !$productId) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Please select a unit and a product.'];
        header("Location: upload_batch.php");
        exit();
    }

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $uploadType = $_POST['upload_type'] ?? 'file';
    
    if ($uploadType === 'image' && isset($_FILES['customerImage'])) {
        // Handle image upload and OCR processing
        $originalFileName = basename($_FILES['customerImage']['name']);
        $tempFile = $_FILES['customerImage']['tmp_name'];
        
        // Validate image file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/bmp', 'image/tiff'];
        $fileType = $_FILES['customerImage']['type'];
        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception("Invalid file type. Please upload JPG, PNG, BMP, or TIFF images only.");
        }
        
        // Save uploaded image temporarily
        $tempImagePath = $uploadDir . 'temp_' . uniqid() . '_' . $originalFileName;
        if (!move_uploaded_file($tempFile, $tempImagePath)) {
            throw new Exception("Failed to save uploaded image.");
        }
        
        // Process image with Gemini OCR
        $pythonScript = __DIR__ . '/gemini_excel_parser.py';
        $command = "python \"$pythonScript\" \"$tempImagePath\" 2>&1";
        $output = shell_exec($command);
        
        // Clean up temporary image
        if (file_exists($tempImagePath)) {
            unlink($tempImagePath);
        }
        
        if (!$output) {
            throw new Exception("Failed to process image with OCR. Please ensure Python and required libraries are installed.");
        }
        
        // Check if output is JSON error
        $jsonOutput = json_decode($output, true);
        if ($jsonOutput && isset($jsonOutput['error'])) {
            throw new Exception("OCR Error: " . $jsonOutput['error']);
        }
        
        // Parse CSV output from OCR
        $csvLines = array_filter(explode("\n", trim($output)));
        if (empty($csvLines)) {
            throw new Exception("OCR processing returned no data. Please ensure the image is clear and contains tabular data.");
        }
        
        // Convert CSV to array format like Excel processing
        $headerRow = str_getcsv(array_shift($csvLines));
        $dataRows = array_map('str_getcsv', $csvLines);
        $columnMap = mapColumns($headerRow);
        $rowCount = count($dataRows);
        
    } else {
        // Handle regular file upload (existing logic)
        $originalFileName = basename($_FILES['customerFile']['name']);
        $tempFile = $_FILES['customerFile']['tmp_name'];
        
        $spreadsheet = IOFactory::load($tempFile);
        $worksheet = $spreadsheet->getActiveSheet();
        $rowCount = $worksheet->getHighestRow() - 1; // Subtract header row

        // MODIFICATION 1: Check for 10,000 row limit
        if ($rowCount > 10000) {
            throw new Exception("File contains {$rowCount} rows. The maximum allowed is 10,000 rows per batch.");
        }

        $dataRows = $worksheet->toArray(null, true, true, false);
        $headerRow = array_shift($dataRows);
        $columnMap = mapColumns($headerRow);
    }

    // Common processing for both file and image uploads
    try {
        // Check row limit for both types
        if ($rowCount > 10000) {
            throw new Exception("Data contains {$rowCount} rows. The maximum allowed is 10,000 rows per batch.");
        }
        
        // Fetch product code
        $prodStmt = $conn->prepare("SELECT product_code FROM lv_products WHERE id = ?");
        $prodStmt->bind_param("i", $productId);
        $prodStmt->execute();
        $productCode = $prodStmt->get_result()->fetch_assoc()['product_code'];
        $prodStmt->close();
        
        // Step 1: Pre-process all rows and extract mobile numbers
        $allRowData = [];
        $allMobileNumbers = [];
        $rowCounter = 1;
        
        foreach ($dataRows as $dataRow) {
            if (empty(implode('', $dataRow))) continue;
            
            // Stop if we've processed 10,000 rows
            if (count($allRowData) >= 10000) {
                break;
            }
            
            $mobile_no_raw = ($columnMap['mobile_no'] !== -1 && isset($dataRow[$columnMap['mobile_no']])) ? (string)$dataRow[$columnMap['mobile_no']] : null;
            if (empty($mobile_no_raw)) continue;
            
            $mobile_no = preg_replace('/\D/', '', $mobile_no_raw);
            if (strlen($mobile_no) < 10) continue;
            
            // Extract all other fields
            $policy_number = ($columnMap['policy_number'] !== -1 && isset($dataRow[$columnMap['policy_number']])) ? (string)$dataRow[$columnMap['policy_number']] : null;
            $status = (!empty($policy_number)) ? 'old' : 'fresh';

            $title = ($columnMap['title'] !== -1) ? ($dataRow[$columnMap['title']] ?? null) : null;
            $name = ($columnMap['name'] !== -1) ? ($dataRow[$columnMap['name']] ?? null) : null;
            $pan = ($columnMap['pan'] !== -1) ? ($dataRow[$columnMap['pan']] ?? null) : null;
            $dob_raw = ($columnMap['dob'] !== -1) ? ($dataRow[$columnMap['dob']] ?? null) : null;
            $dob = formatDateString($dob_raw);
            $age = ($columnMap['age'] !== -1 && is_numeric($dataRow[$columnMap['age']])) ? (int)$dataRow[$columnMap['age']] : null;
            $expiry_raw = ($columnMap['expiry'] !== -1) ? ($dataRow[$columnMap['expiry']] ?? null) : null;
            $expiry = formatDateString($expiry_raw);
            $address = ($columnMap['address'] !== -1) ? ($dataRow[$columnMap['address']] ?? null) : null;
            $city = ($columnMap['city'] !== -1) ? ($dataRow[$columnMap['city']] ?? null) : null;
            $state = ($columnMap['state'] !== -1) ? ($dataRow[$columnMap['state']] ?? null) : null;
            $country = ($columnMap['country'] !== -1) ? ($dataRow[$columnMap['country']] ?? null) : null;
            $pincode = ($columnMap['pincode'] !== -1) ? ($dataRow[$columnMap['pincode']] ?? null) : null;
            $plan = ($columnMap['plan'] !== -1) ? ($dataRow[$columnMap['plan']] ?? null) : null;
            $premium = ($columnMap['premium'] !== -1) ? ($dataRow[$columnMap['premium']] ?? null) : null;
            $sum_insured = ($columnMap['sum_insured'] !== -1) ? ($dataRow[$columnMap['sum_insured']] ?? null) : null;
            $extraData = null;

            // Store all row data for processing
            $allRowData[] = [
                'mobile_no' => $mobile_no,
                'status' => $status,
                'title' => $title,
                'name' => $name,
                'policy_number' => $policy_number,
                'pan' => $pan,
                'dob' => $dob,
                'age' => $age,
                'expiry' => $expiry,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'pincode' => $pincode,
                'plan' => $plan,
                'premium' => $premium,
                'sum_insured' => $sum_insured,
                'extra_data' => $extraData,
                'row_counter' => $rowCounter
            ];
            
            $allMobileNumbers[] = $mobile_no;
            $rowCounter++;
        }
        
        if (empty($allRowData)) {
            throw new Exception("No valid mobile numbers found in the uploaded file.");
        }
        
        // Step 2: Bulk check for blocked numbers (admin-specific)
        $blockedNumbers = [];
        if (!empty($allMobileNumbers)) {
            $blockedNumbers = array_flip(getBulkBlockedMobileNumbers($adminId, $allMobileNumbers));
        }
        
        // Step 3: Bulk check for duplicate numbers (system-wide) 
        $duplicateNumbers = [];
        if (!empty($allMobileNumbers)) {
            $duplicateNumbers = array_flip(getBulkDuplicateMobileNumbers($allMobileNumbers));
        }
        
        // Step 4: Filter valid records based on bulk checks
        $validRecords = [];
        $blockedCount = 0;
        $duplicateCount = 0;
        
        foreach ($allRowData as $rowData) {
            $mobile_no = $rowData['mobile_no'];
            
            // Check if blocked
            if (isset($blockedNumbers[$mobile_no])) {
                $blockedCount++;
                continue;
            }
            
            // Check if duplicate
            if (isset($duplicateNumbers[$mobile_no])) {
                $duplicateCount++;
                continue;
            }
            
            // This record is valid
            $validRecords[] = $rowData;
        }
        
        // Check if we have any valid records to create a batch
        if (empty($validRecords)) {
            $totalExcluded = $blockedCount + $duplicateCount;
            throw new Exception("No valid records to create batch. All {$totalExcluded} records were excluded ({$blockedCount} blocked, {$duplicateCount} duplicates).");
        }
        
        // Now create the batch and insert valid records
        $conn->begin_transaction();

        // Generate new batch ID
        $batch_id = generateBatchId($productCode, $vendorId, $adminId, $conn);

        // Create batch entry
        $batch_stmt = $conn->prepare("INSERT INTO lv_file_batches (id, admin_id, vendor_id, product_code, original_filename) VALUES (?, ?, ?, ?, ?)");
        $batch_stmt->bind_param("sssss", $batch_id, $adminId, $vendorId, $productCode, $originalFileName);
        $batch_stmt->execute();
        $batch_stmt->close();

        // Insert all valid records
        $sql = "INSERT INTO lv_final_call_logs (id, mobile_no, batch_id, status, title, name, policy_number, pan, dob, age, expiry, address, city, state, country, pincode, plan, premium, sum_insured, extra_data) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) { throw new Exception("Prepare failed (INSERT): " . $conn->error); }

        foreach ($validRecords as $record) {
            $log_id = generateLogRowId($batch_id, $record['row_counter']);
            $stmt->bind_param("sssssssssissssssssss", 
                $log_id, 
                $record['mobile_no'], 
                $batch_id, 
                $record['status'], 
                $record['title'], 
                $record['name'], 
                $record['policy_number'], 
                $record['pan'], 
                $record['dob'], 
                $record['age'], 
                $record['expiry'], 
                $record['address'], 
                $record['city'], 
                $record['state'], 
                $record['country'], 
                $record['pincode'], 
                $record['plan'], 
                $record['premium'], 
                $record['sum_insured'], 
                $record['extra_data']
            );
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
        
        $message = "Batch {$batch_id} created successfully with " . count($validRecords) . " records.";
        $exclusions = [];
        if ($blockedCount > 0) {
            $exclusions[] = "{$blockedCount} numbers were blocked";
        }
        if ($duplicateCount > 0) {
            $exclusions[] = "{$duplicateCount} duplicate numbers found";
        }
        if (!empty($exclusions)) {
            $message .= " (" . implode(", ", $exclusions) . " and excluded)";
        }
        $_SESSION['flash_message'] = ['type' => 'success', 'text' => $message];

    } catch (Exception $e) {
        if (isset($conn) && $conn->ping()) { $conn->rollback(); }
        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Error processing file: ' . $e->getMessage()];
    } finally {
        if (isset($conn) && $conn->ping()) { $conn->close(); }
        header("Location: upload_batch.php");
        exit();
    }
}

// --- Fetch data for form dropdowns ---
$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];
$lv_vendors = $conn->query("SELECT vendor_id, vendor_name FROM lv_vendors WHERE admin_id = '{$adminId}' AND is_approved = 1 ORDER BY vendor_id");
$products = $conn->query("SELECT id, product_name FROM lv_products WHERE is_active = 1 ORDER BY product_name");

// Dispositions removed - moved to view batch page

// Check vendor request count for this admin
$requestCountStmt = $conn->prepare("SELECT COUNT(*) as request_count FROM lv_vendor_requests WHERE admin_id = ?");
$requestCountStmt->bind_param("s", $adminId);
$requestCountStmt->execute();
$requestCount = $requestCountStmt->get_result()->fetch_assoc()['request_count'];
$requestCountStmt->close();

$conn->close();

function mapColumns(array $headerRow): array {
    $map = ['title' => -1, 'name' => -1, 'age' => -1, 'mobile_no' => -1, 'policy_number' => -1, 'pan' => -1, 'dob' => -1, 'expiry' => -1, 'address' => -1, 'city' => -1, 'state' => -1, 'country' => -1, 'pincode' => -1, 'plan' => -1, 'premium' => -1, 'sum_insured' => -1];
    foreach ($headerRow as $index => $header) {
        if (is_null($header)) continue;
        $normalizedHeader = strtolower(trim(str_replace(['_', ' '], '', $header)));
        if (empty($normalizedHeader)) continue;
        switch (true) {
            case ($map['mobile_no'] === -1 && preg_match('/mobile|phone|cell/i', $normalizedHeader)): $map['mobile_no'] = $index; break;
            case ($map['name'] === -1 && preg_match('/name|insured/i', $normalizedHeader)): $map['name'] = $index; break;
            case ($map['policy_number'] === -1 && preg_match('/policy(number)?/i', $normalizedHeader)): $map['policy_number'] = $index; break;
            case ($map['title'] === -1 && preg_match('/^title$/i', $normalizedHeader)): $map['title'] = $index; break;
            case ($map['pan'] === -1 && preg_match('/pan/i', $normalizedHeader)): $map['pan'] = $index; break;
            case ($map['dob'] === -1 && preg_match('/dob|birth/i', $normalizedHeader)): $map['dob'] = $index; break;
            case ($map['age'] === -1 && preg_match('/^age$/i', $normalizedHeader)): $map['age'] = $index; break;
            case ($map['expiry'] === -1 && preg_match('/expiry/i', $normalizedHeader)): $map['expiry'] = $index; break;
            case ($map['address'] === -1 && preg_match('/address|add\b/i', $normalizedHeader)): $map['address'] = $index; break;
            case ($map['city'] === -1 && preg_match('/city/i', $normalizedHeader)): $map['city'] = $index; break;
            case ($map['state'] === -1 && preg_match('/state/i', $normalizedHeader)): $map['state'] = $index; break;
            case ($map['country'] === -1 && preg_match('/country/i', $normalizedHeader)): $map['country'] = $index; break;
            case ($map['pincode'] === -1 && preg_match('/pin|pincode/i', $normalizedHeader)): $map['pincode'] = $index; break;
            case ($map['plan'] === -1 && preg_match('/plan/i', $normalizedHeader)): $map['plan'] = $index; break;
            case ($map['premium'] === -1 && preg_match('/premium/i', $normalizedHeader)): $map['premium'] = $index; break;
            case ($map['sum_insured'] === -1 && preg_match('/sum.*insured/i', $normalizedHeader)): $map['sum_insured'] = $index; break;
            default: break;
        }
    }
    return $map;
}

function formatDateString($value): string {
    if (empty($value)) return '';
    
    // Check for Excel's numeric date format
    if (is_numeric($value)) {
        try {
            $dateObj = Date::excelToDateTimeObject($value);
            return $dateObj->format('Y-m-d'); // Store in MySQL format
        } catch (Exception $e) {
            // Fall through if not a valid Excel date
        }
    }
    
    // If it's a string, try various date formats
    if (is_string($value)) {
        // Remove any extra spaces
        $value = trim($value);
        
        // Common date formats to try
        $formats = [
            'd-m-Y', 'd/m/Y', 'd.m.Y',      // DD-MM-YYYY variations
            'Y-m-d', 'Y/m/d', 'Y.m.d',      // YYYY-MM-DD variations
            'd-M-Y', 'd/M/Y',                // DD-MON-YYYY variations
            'm/d/Y', 'm-d-Y',                // MM-DD-YYYY variations
            'd-m-y', 'd/m/y',                // DD-MM-YY variations
            'j-n-Y', 'j/n/Y',                // D-M-YYYY variations (no leading zeros)
        ];
        
        foreach ($formats as $format) {
            $dateObj = DateTime::createFromFormat($format, $value);
            if ($dateObj !== false) {
                return $dateObj->format('Y-m-d');
            }
        }
        
        // Try strtotime as last resort
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
    }
    
    // Return empty string if no format matches
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Batch</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        #loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); z-index: 1050; display: none; justify-content: center; align-items: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; animation: spin 1.5s linear infinite; }
        .loading-text { color: white; margin-top: 20px; font-size: 1.2rem; font-weight: bold; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="loading-text" id="loading-message">Processing, please wait...</p></div>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-cloud-upload-fill me-2"></i>Create New Batch</h1>
                </div>

                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['flash_message']['text']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['flash_message']); ?>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <p class="card-text">Upload a source file (.xlsx, .csv) with a <strong>maximum of 10,000 data rows</strong>. The data will be saved, and you can download the PDF from the "Manage Batches" page.</p>
                        <form action="upload_batch.php" method="post" enctype="multipart/form-data" id="upload-form">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="vendor_id" class="form-label"><strong>Select Unit</strong></label>
                                    <div class="input-group">
                                        <select class="form-select" id="vendor_id" name="vendor_id" required>
                                            <option value="">-- Select a Unit --</option>
                                            <?php while($row = $lv_vendors->fetch_assoc()): ?>
                                                <option value="<?= htmlspecialchars($row['vendor_id']) ?>"><?= htmlspecialchars($row['vendor_id']) ?> (<?= htmlspecialchars($row['vendor_name']) ?>)</option>
                                            <?php endwhile; ?>
                                        </select>
                                        <?php if ($requestCount < 4): ?>
                                        <a href="request_vendor.php" class="btn btn-outline-primary" title="Request new unit">
                                            <i class="bi bi-plus"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="product_id" class="form-label"><strong>Select Product</strong></label>
                                    <select class="form-select" id="product_id" name="product_id" required>
                                        <option value="">-- Select a Product --</option>
                                        <?php while($row = $products->fetch_assoc()): ?>
                                            <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['product_name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><strong>Upload Type</strong></label>
                                    <div class="mb-3">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="upload_type" id="file_upload" value="file" checked>
                                            <label class="form-check-label" for="file_upload">Excel/CSV File</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="upload_type" id="image_upload" value="image">
                                            <label class="form-check-label" for="image_upload">Excel Image</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12" id="file_upload_section">
                                    <label for="customerFile" class="form-label"><strong>Select Excel/CSV File</strong></label>
                                    <input class="form-control" type="file" id="customerFile" name="customerFile" accept=".xlsx, .csv">
                                    <small class="text-muted">Maximum 10,000 rows allowed per batch</small>
                                </div>
                                
                                <div class="col-12" id="image_upload_section" style="display: none;">
                                    <label for="customerImage" class="form-label"><strong>Select Excel Sheet Image</strong></label>
                                    <input class="form-control" type="file" id="customerImage" name="customerImage" accept=".jpg, .jpeg, .png, .bmp, .tiff">
                                    <small class="text-muted">Upload a clear image of your Excel spreadsheet for OCR processing</small>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-4"><i class="bi bi-gear-fill me-2"></i>Upload and Create Batch</button>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>
<script>
    // Handle upload type toggle
    document.addEventListener('DOMContentLoaded', function() {
        const fileUploadRadio = document.getElementById('file_upload');
        const imageUploadRadio = document.getElementById('image_upload');
        const fileSection = document.getElementById('file_upload_section');
        const imageSection = document.getElementById('image_upload_section');
        const fileInput = document.getElementById('customerFile');
        const imageInput = document.getElementById('customerImage');

        function toggleUploadSections() {
            if (fileUploadRadio.checked) {
                fileSection.style.display = 'block';
                imageSection.style.display = 'none';
                fileInput.required = true;
                imageInput.required = false;
            } else {
                fileSection.style.display = 'none';
                imageSection.style.display = 'block';
                fileInput.required = false;
                imageInput.required = true;
            }
        }

        fileUploadRadio.addEventListener('change', toggleUploadSections);
        imageUploadRadio.addEventListener('change', toggleUploadSections);
    });

    document.getElementById('upload-form').addEventListener('submit', function() {
        const uploadType = document.querySelector('input[name="upload_type"]:checked').value;
        let hasFile = false;
        let message = '';

        if (uploadType === 'file' && document.getElementById('customerFile').files.length > 0) {
            hasFile = true;
            message = 'Uploading and processing file... This may take a while.';
        } else if (uploadType === 'image' && document.getElementById('customerImage').files.length > 0) {
            hasFile = true;
            message = 'Processing image with AI OCR... This may take a while.';
        }

        if (hasFile) {
            document.getElementById('loading-message').textContent = message;
            document.getElementById('loading-overlay').style.display = 'flex';
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>