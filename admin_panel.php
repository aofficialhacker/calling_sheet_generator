<?php
session_start();
require_once 'db_config.php';
requireAdmin();

// Check if user is admin or superadmin
if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

// Determine admin ID
$conn = getDBConnection();
$adminId = $_SESSION['admin_id'] ?? null;
$isSuper = isSuperadmin();

if (!$adminId) {
    die("Admin ID not found in session");
}

// Fetch performance data for callers mapped to this admin
$stmt = $conn->prepare("SELECT c.id, c.name, c.mobile_no, COUNT(l.id) as total_calls, 
    SUM(CASE WHEN l.disposition IN ('Interested', 'Call Back', 'Hot Lead') THEN 1 ELSE 0 END) as positive_calls,
    SUM(CASE WHEN l.disposition = 'Interested' THEN 1 ELSE 0 END) as interested_calls
    FROM callers c 
    LEFT JOIN call_logs l ON c.id = l.caller_id
    WHERE c.admin_id = ?
    GROUP BY c.id");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$callerStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
// Get caller statistics from the database
$stmt = $conn->prepare("SELECT c.id, c.name, c.mobile_no, COUNT(l.id) as total_calls, 
    SUM(CASE WHEN l.disposition IN ('Interested', 'Call Back', 'Hot Lead') THEN 1 ELSE 0 END) as positive_calls,
    SUM(CASE WHEN l.disposition = 'Interested' THEN 1 ELSE 0 END) as interested_calls
    FROM callers c 
    LEFT JOIN call_logs l ON c.id = l.caller_id
    WHERE c.admin_id = ?
    GROUP BY c.id");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$callerStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
    
    $productCode = $_POST['product'] ?? '';
    $vendorId = $_POST['vendor'] ?? '';
    
    if (empty($productCode) || empty($vendorId)) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Please select both product and vendor before uploading.'];
        header("Location: admin_panel.php");
        exit();
    }

    if (move_uploaded_file($_FILES['customerFile']['tmp_name'], $originalFile)) {
        try {
            $conn->begin_transaction();
            $batch_stmt = $conn->prepare("INSERT INTO file_batches (original_filename, admin_id, vendor_id, product_code) VALUES (?, ?, ?, ?)");
            $batch_stmt->bind_param("ssss", $originalFileName, $adminId, $vendorId, $productCode);
            $batch_stmt->execute();
            $batch_id = $conn->insert_id;
            $batch_stmt->close();

            require 'vendor/autoload.php';
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
            header("Location: admin_panel.php");
            exit();
        }
    } else {
        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'There was a problem uploading your file.'];
        header("Location: admin_panel.php");
        exit();
    }
}

// --- Fetch data for dashboard ---

// Build WHERE clause for batches based on admin access
$batchWhereClause = $isSuper ? "" : "WHERE b.admin_id = ?";

// --- Caller performance data ---
$perf_sql = "SELECT 
                fcl.finqy_id,
                c.caller_name,
                COUNT(fcl.id) as total_calls,
                SUM(CASE WHEN fcl.connectivity = 'Yes' THEN 1 ELSE 0 END) as connected,
                SUM(CASE WHEN fcl.connectivity = 'No' THEN 1 ELSE 0 END) as not_connected,
                SUM(CASE WHEN fcl.disposition = 'Interested' THEN 1 ELSE 0 END) as interested,
                SUM(CASE WHEN fcl.disposition = 'Follow Up' THEN 1 ELSE 0 END) as follow_up,
                SUM(CASE WHEN fcl.disposition IS NULL AND fcl.finqy_id IS NOT NULL THEN 1 ELSE 0 END) as empty_disposition,
                MAX(fcl.processed_at) as last_activity
             FROM final_call_logs fcl";

$perfWhereConditions = [];
$perfWhereConditions[] = "fcl.finqy_id IS NOT NULL";

if (!$isSuper) {
    $perf_sql .= " JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id COLLATE utf8mb4_general_ci";
    $perf_sql .= " JOIN callers c ON fcl.finqy_id = c.finqy_id";
    $perfWhereConditions[] = "acm.admin_id = ?";
} else {
    $perf_sql .= " JOIN callers c ON fcl.finqy_id = c.finqy_id";
}

if (!empty($perfWhereConditions)) {
    $perf_sql .= " WHERE " . implode(" AND ", $perfWhereConditions);
}

$perf_sql .= " GROUP BY fcl.finqy_id, c.caller_name
             ORDER BY total_calls DESC";

$stmt = $conn->prepare($perf_sql);
if (!$isSuper) {
    $stmt->bind_param("s", $adminId);
}
$stmt->execute();
$performance_data = $stmt->get_result();

// Fetch overall stats for this admin
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM admin_caller_mapping WHERE admin_id = ?) as caller_count,
    (SELECT COUNT(*) FROM file_batches WHERE admin_id = ?) as batch_count,
    (SELECT COUNT(*) FROM final_call_logs WHERE batch_id IN (SELECT id FROM file_batches WHERE admin_id = ?)) as total_records";
$stmt = $conn->prepare($stats_sql);
$stmt->bind_param("sss", $adminId, $adminId, $adminId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
||||||| base
$performance_data = $conn->query($perf_sql);

// Uploaded batches data
$batches_sql = "SELECT b.id, b.original_filename, b.upload_time, COUNT(f.id) as record_count 
               FROM file_batches b
               LEFT JOIN final_call_logs f ON b.id = f.batch_id
               GROUP BY b.id, b.original_filename, b.upload_time
               ORDER BY b.id DESC";
$performance_data = $stmt->get_result();
$stmt->close();

// --- Uploaded batches data ---
$batches_sql = "SELECT b.id, b.original_filename, b.upload_time, b.product_code, b.vendor_id,
                p.product_name, v.vendor_name, COUNT(f.id) as record_count 
               FROM file_batches b
               LEFT JOIN final_call_logs f ON b.id = f.batch_id
               LEFT JOIN products p ON b.product_code = p.product_code
               LEFT JOIN vendors v ON b.vendor_id = v.vendor_id
               {$batchWhereClause}
               GROUP BY b.id
               ORDER BY b.id DESC";

if ($isSuper) {
    $batches_data = $conn->query($batches_sql);
} else {
    $batch_stmt = $conn->prepare($batches_sql);
    if ($batch_stmt === false) { die("Error preparing batch query: " . $conn->error); }
    $batch_stmt->bind_param("s", $adminId);
    $batch_stmt->execute();
    $batches_data = $batch_stmt->get_result();
}

// --- Available dispositions for download dropdown ---
$dispo_sql = "SELECT DISTINCT fcl.disposition 
              FROM final_call_logs fcl";

$dispoWhereConditions = [];
$dispoWhereConditions[] = "fcl.disposition IS NOT NULL";
$dispoWhereConditions[] = "fcl.disposition != ''";
$dispoWhereConditions[] = "fcl.disposition != 'Interested'";

if (!$isSuper) {
    $dispo_sql .= " JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id COLLATE utf8mb4_general_ci";
    $dispoWhereConditions[] = "acm.admin_id = ?";
}

if (!empty($dispoWhereConditions)) {
    $dispo_sql .= " WHERE " . implode(" AND ", $dispoWhereConditions);
}

$dispo_sql .= " ORDER BY fcl.disposition ASC";

if ($isSuper) {
    $dispositions_result = $conn->query($dispo_sql);
} else {
    $dispo_stmt = $conn->prepare($dispo_sql);
    if ($dispo_stmt === false) { die("Error preparing disposition query: " . $conn->error); }
    $dispo_stmt->bind_param("s", $adminId);
    $dispo_stmt->execute();
    $dispositions_result = $dispo_stmt->get_result();
}

// Get products and vendors for dropdowns
$products = $conn->query("SELECT product_code, product_name FROM products WHERE is_active = 1 ORDER BY product_name");

if ($isSuper) {
    $vendors = $conn->query("SELECT vendor_id, vendor_name FROM vendors WHERE is_active = 1 ORDER BY vendor_name");
} else {
    $vendor_stmt = $conn->prepare("SELECT vendor_id, vendor_name FROM vendors WHERE admin_id = ? AND is_active = 1 ORDER BY vendor_name");
    $vendor_stmt->bind_param("s", $adminId);
    $vendor_stmt->execute();
    $vendors = $vendor_stmt->get_result();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .stat-card { border: none; border-radius: 10px; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="loading-text" id="loading-message">Processing, please wait...</p></div>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>
            
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['flash_message']['text']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['flash_message']); ?>
        <?php endif; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">Dashboard</h1>
                        <span class="text-muted">
                            <?php if ($isSuper): ?>
                                <span class="badge bg-primary">SUPERADMIN</span>
                            <?php else: ?>
                                Welcome, <?= htmlspecialchars($_SESSION['admin_name']) ?> 
                                <span class="badge bg-secondary"><?= htmlspecialchars($adminId) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div>
                        <?php if ($isSuper): ?>
                            <a href="superadmin_panel.php" class="btn btn-primary"><i class="bi bi-shield-fill me-2"></i>Superadmin Panel</a>
                        <?php endif; ?>
                        <a href="logout.php?type=admin" class="btn btn-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
                    </div>
                </div>

                <!-- Stat Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card bg-primary text-white shadow">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Total Callers</h6><h2 class="mb-0"><?= $stats['caller_count'] ?? 0 ?></h2></div>
                                <i class="bi bi-telephone-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-success text-white shadow">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Total Batches</h6><h2 class="mb-0"><?= $stats['batch_count'] ?? 0 ?></h2></div>
                                <i class="bi bi-stack fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-info text-white shadow">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div><h6>Total Records</h6><h2 class="mb-0"><?= number_format($stats['total_records'] ?? 0) ?></h2></div>
                                <i class="bi bi-server fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Caller Performance Card -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-dark text-white"><h3 class="h5 mb-0"><i class="bi bi-graph-up me-2"></i>Caller Performance</h3></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
                            <h4 class="card-title mb-0">Caller Performance</h4>
                            <form class="d-flex gap-2" id="disposition-download-form" action="generate_pdf.php" method="GET">
                                <select class="form-select form-select-sm" name="disposition" id="disposition-select" 
                                        <?= ($_SESSION['multi_status_selection'] ?? false) ? 'multiple' : '' ?> required>
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

                <!-- Charts -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-12">
                        <div class="card shadow-sm">
                            <div class="card-header">Caller Performance</div>
                            <div class="card-body">
                                <canvas id="callerPerformanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Caller Performance Table -->
                <div class="card shadow-sm">
                    <div class="card-header"><h4 class="mb-0">Caller Leaderboard</h4></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Caller</th>
                                        <th>Total Logged</th>
                                        <th>Connected</th>
                                        <th>Interested</th>
                                        <th>Follow-up</th>
                                        <th>Last Activity</th>
                                    </tr>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Product</th>
                                        <th>Vendor</th>
                                        <th>Filename</th>
                                        <th>Records</th>
                                        <th>Action</th>
                                    </tr>
>>>>>>> theirs
                                </thead>
                                <tbody>
                                    <?php if ($performance_data && $performance_data->num_rows > 0): ?>
                                        <?php while($row = $performance_data->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($row['caller_name']) ?></strong> (<?= htmlspecialchars($row['finqy_id']) ?>)</td>
                                            <td><?= (int)$row['total_calls'] ?></td>
                                            <td class="text-success fw-bold"><?= (int)$row['connected'] ?></td>
                                            <td class="text-info fw-bold"><?= (int)$row['interested'] ?></td>
                                            <td><?= $row['last_activity'] ? date('d-M-Y H:i', strtotime($row['last_activity'])) : 'N/A' ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted">No caller performance data available yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
<<<<<<< ours
<script>
document.addEventListener('DOMContentLoaded', () => {
    const performanceData = <?= json_encode($performance_data->fetch_all(MYSQLI_ASSOC)) ?>;
    if (performanceData.length > 0) {
        const ctx = document.getElementById('callerPerformanceChart').getContext('2d');
        const labels = performanceData.map(d => d.caller_name);
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Calls',
                    data: performanceData.map(d => d.total_calls),
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }, {
                    label: 'Connected',
                    data: performanceData.map(d => d.connected),
                    backgroundColor: 'rgba(75, 192, 192, 0.6)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }, {
                    label: 'Interested',
                    data: performanceData.map(d => d.interested),
                    backgroundColor: 'rgba(255, 159, 64, 0.6)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: { y: { beginAtZero: true } },
                responsive: true,
                plugins: { legend: { position: 'top' } }
||||||| base
    
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
            
            // Request New Vendor Modal
            <?php if (!$isSuper): ?>
            // Add vendor request modal functionality
            const vendorRequestForm = document.querySelector('#requestVendorModal form');
            if (vendorRequestForm) {
                vendorRequestForm.addEventListener('submit', function() {
                    loadingMessage.textContent = 'Submitting vendor request...';
                    loadingOverlay.style.display = 'flex';
                });
            }
            <?php endif; ?>
        });
    </script>
    
    <!-- Request New Vendor Modal -->
    <?php if (!$isSuper): ?>
    <div class="modal fade" id="requestVendorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request New Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="request_vendor.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="vendor_name" class="form-label">Vendor Name</label>
                            <input type="text" class="form-control" id="vendor_name" name="vendor_name" required>
                        </div>
                        <p class="text-muted">Your request will be sent to the superadmin for approval.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
            }
        });
<<<<<<< ours
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
||||||| base
    </script>
=======
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
>>>>>>> theirs
</body>
</html>
