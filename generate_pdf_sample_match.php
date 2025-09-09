<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

// Check admin authentication
if (session_status() == PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_manager.php';
    SessionManager::start();
}

if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use TCPDF;

set_time_limit(0);
ini_set('memory_limit', '2048M');

$conn = getDBConnection();

// Get all the same parameters as the original
$batch_id = $_GET['batch_id'] ?? null;
$dispositions = null;
$scope = $_GET['scope'] ?? '';
$product_code = $_GET['product_code'] ?? '';

if (!$batch_id && !isset($_GET['disposition'])) {
    die("Error: No valid batch ID or disposition provided.");
}

$adminId = $_SESSION['admin_id'];

// Handle different types of requests (same logic as original)
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle = 'Calling Sheet';

if (isset($_GET['disposition'])) {
    if (is_array($_GET['disposition'])) {
        $dispositions = $_GET['disposition'];
    } else {
        $dispositions = [$_GET['disposition']];
    }
    
    $dispNames = array_map(function($d) { return preg_replace("/[^a-zA-Z0-9]/", "", $d); }, $dispositions);
    
    switch ($scope) {
        case 'batch-wise':
            $batch_id = $_GET['batch_id'];
            $pdfFileName = 'Batch_' . $batch_id . '_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for Batch $batch_id - Status: " . implode(', ', $dispositions);
            break;
        case 'all-batch':
            $pdfFileName = 'AllBatches_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for All Batches - Status: " . implode(', ', $dispositions);
            break;
        case 'product-wise':
            $pdfFileName = 'Product_' . $product_code . '_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for Product $product_code - Status: " . implode(', ', $dispositions);
            break;
        case 'all-product':
            $pdfFileName = 'AllProducts_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle = "Calling Sheet for All Products - Status: " . implode(', ', $dispositions);
            break;
        default:
            $safeDispositionName = preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0]);
            $pdfFileName = ucwords($safeDispositionName) . '_Sheet.pdf';
            $pdfTitle = "Calling Sheet for Status: " . implode(', ', $dispositions);
    }
} elseif (isset($_GET['batch_id'])) {
    $batch_id = $_GET['batch_id'];
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);
}

// --- Fetch Dynamic Disposition Codes (same as original) ---
$dispResult = $conn->query("
    SELECT code, description, category 
    FROM disposition_codes 
    WHERE is_active = 1 
    ORDER BY category, CAST(code AS UNSIGNED), code
");
$dispositionList = [];
$dispLegendY = [];
$dispLegendN = [];
while($d = $dispResult->fetch_assoc()){
    $dispositionList[] = $d;
    if($d['category'] == 'connected') {
        $dispLegendY[] = "{$d['code']}:{$d['description']}";
    } else {
        $dispLegendN[] = "{$d['code']}:{$d['description']}";
    }
}

// Build legends (same as original)
$dispLegend = '';
if (!empty($dispLegendY)) {
    $dispLegend .= "DISPO (Y): " . implode(' | ', $dispLegendY);
}
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= "DISPO (N): " . implode(' | ', $dispLegendN);
}

$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// --- Build Database Query (same as original) ---
$baseSql = "FROM final_call_logs fcl JOIN file_batches fb ON fcl.batch_id = fb.id ";
$whereClauses = [];
$params = [];
$types = '';

$whereClauses[] = "fb.admin_id = ?";
$params[] = $adminId;
$types .= 's';

switch ($scope) {
    case 'batch-wise':
        if ($batch_id) {
            $whereClauses[] = "fcl.batch_id = ?";
            $params[] = $batch_id;
            $types .= 's';
        }
        break;
    case 'product-wise':
        if ($product_code) {
            $whereClauses[] = "fb.product_code = ?";
            $params[] = $product_code;
            $types .= 's';
        }
        break;
    default:
        if ($batch_id) {
            $whereClauses[] = "fcl.batch_id = ?";
            $params[] = $batch_id;
            $types .= 's';
        }
}

if ($dispositions && !empty($dispositions)) {
    $placeholders = implode(',', array_fill(0, count($dispositions), '?'));
    $whereClauses[] = "fcl.disposition IN ($placeholders)";
    $params = array_merge($params, $dispositions);
    $types .= str_repeat('s', count($dispositions));
}

if (empty($whereClauses)) {
    die("Error: No criteria selected for PDF generation.");
}
$whereSql = "WHERE " . implode(' AND ', $whereClauses);
$fullBaseSql = $baseSql . $whereSql;

// Check total record count
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

if ($totalRecords == 0) {
    die("No records found matching the criteria.");
}

// Column detection logic (same as original)
$optionalColumns = ['title','name', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
$selects = [];
foreach ($optionalColumns as $column) {
    $selects[] = "MAX(CASE WHEN fcl.`{$column}` IS NOT NULL AND fcl.`{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}

$presenceCheckSql = "SELECT " . implode(', ', $selects) . " " . $fullBaseSql;
$stmt = $conn->prepare($presenceCheckSql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$columnPresence = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Build final headers (same logic as original)
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];

if (!empty($columnPresence["has_title"]) || !empty($columnPresence["has_name"])) {
    if (!empty($columnPresence["has_title"])) {
        $finalHeaders[] = 'title';
    }
    $finalHeaders[] = 'name';
}

$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if ($column !== 'title' && $column !== 'name' && !empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column;
        $addedCount++;
    }
}

// Create PDF to match sample exactly
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);
$pdf->SetMargins(10, 25, 10);
$pdf->SetAutoPageBreak(true, 20);

$pdf->AddPage();

// Header - match sample format
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, $pdfTitle, 0, 1, 'C');

// Legends
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, $slotLegend, 0, 1, 'C');

// Split disposition legend if too long
if (strlen($dispLegend) > 120) {
    $lines = explode(' || ', $dispLegend);
    foreach ($lines as $line) {
        $pdf->Cell(0, 4, $line, 0, 1, 'C');
    }
} else {
    $pdf->Cell(0, 4, $dispLegend, 0, 1, 'C');
}

$pdf->Ln(3);

// Table headers - exactly like sample
$headers = [];
$widths = [];
foreach ($finalHeaders as $header) {
    switch ($header) {
        case 'id':
            $headers[] = 'Id';
            $widths[] = 25;
            break;
        case 'slot':
            $headers[] = 'Slot';
            $widths[] = 15;
            break;
        case 'connectivity':
            $headers[] = 'Connectivity';
            $widths[] = 25;
            break;
        case 'disposition':
            $headers[] = 'Disposition';
            $widths[] = 50;
            break;
        case 'mobile_no':
            $headers[] = 'Mobile';
            $widths[] = 25;
            break;
        case 'name':
            $headers[] = 'Name';
            $widths[] = 35;
            break;
        case 'dob':
            $headers[] = 'Dob';
            $widths[] = 22;
            break;
        case 'age':
            $headers[] = 'Age';
            $widths[] = 15;
            break;
        case 'address':
            $headers[] = 'Address';
            $widths[] = 40;
            break;
        case 'city':
            $headers[] = 'City';
            $widths[] = 20;
            break;
        case 'state':
            $headers[] = 'State';
            $widths[] = 20;
            break;
        default:
            $headers[] = ucwords(str_replace('_', ' ', $header));
            $widths[] = 20;
            break;
    }
}

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(220, 220, 220);

foreach ($headers as $i => $header) {
    $pdf->Cell($widths[$i], 10, $header, 1, 0, 'C', true);
}
$pdf->Ln();

// Draw cutline after mobile column (match sample position)
$cutlineX = 10; // Start position
for ($i = 0; $i <= 4; $i++) { // Up to and including mobile column (index 4)
    $cutlineX += $widths[$i];
}

$pdf->SetDrawColor(100, 100, 100);
$pdf->SetLineWidth(0.3);

// Draw dashed line
for ($y = $pdf->GetY(); $y < 200; $y += 3) {
    $pdf->Line($cutlineX, $y, $cutlineX, $y + 1.5);
}

// Fetch and display data
$finalHeadersWithAlias = array_map(function($header) {
    return 'fcl.`' . $header . '`';
}, $finalHeaders);
$columnsToSelectWithAlias = implode(', ', $finalHeadersWithAlias);

$sql = "SELECT {$columnsToSelectWithAlias} " . $fullBaseSql . " ORDER BY fcl.id LIMIT 100";
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Create disposition grid exactly like sample
$dispositionGrid = '';
$dispRow1 = [];
$dispRow2 = [];
$dispRow3 = [];

foreach ($dispositionList as $i => $disp) {
    if ($i < 4) {
        $dispRow1[] = 'O ' . $disp['code'];
    } elseif ($i < 8) {
        $dispRow2[] = 'O ' . $disp['code'];
    } else {
        $dispRow3[] = 'O ' . $disp['code'];
    }
}

$dispositionGrid = implode(' ', $dispRow1);
if (!empty($dispRow2)) {
    $dispositionGrid .= "\n" . implode(' ', $dispRow2);
}
if (!empty($dispRow3)) {
    $dispositionGrid .= "\n" . implode(' ', $dispRow3);
}

function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    
    $timestamp = strtotime($dateValue);
    if ($timestamp !== false) {
        return date('d-m-Y', $timestamp);
    }
    
    return $dateValue;
}

while ($row = $result->fetch_assoc()) {
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
        
        // Redraw headers
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(220, 220, 220);
        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 10, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();
    }
    
    $pdf->SetFont('helvetica', '', 8);
    
    foreach ($finalHeaders as $i => $header) {
        $cellContent = '';
        
        switch($header) {
            case 'disposition':
                $cellContent = $dispositionGrid;
                break;
            case 'connectivity':
                $cellContent = 'O Y / O N';
                break;
            case 'slot':
                $cellContent = '';
                break;
            case 'mobile_no':
                $cellContent = $row[$header] ?? '';
                $pdf->SetFont('helvetica', 'B', 8);
                break;
            case 'id':
                $cellContent = $row[$header] ?? '';
                break;
            case 'dob':
            case 'expiry':
                $cellContent = formatDateForPDF($row[$header] ?? '');
                break;
            default:
                $cellContent = $row[$header] ?? '';
                if (strlen($cellContent) > 30) {
                    $cellContent = substr($cellContent, 0, 30) . '...';
                }
                break;
        }
        
        // Use MultiCell for disposition column to handle multiple lines
        if ($header === 'disposition') {
            $currentX = $pdf->GetX();
            $currentY = $pdf->GetY();
            
            $pdf->MultiCell($widths[$i], 12, $cellContent, 1, 'L', false);
            
            $pdf->SetXY($currentX + $widths[$i], $currentY);
        } else {
            $pdf->Cell($widths[$i], 12, $cellContent, 1, 0, 'L');
        }
        
        // Reset font
        $pdf->SetFont('helvetica', '', 8);
    }
    
    $pdf->Ln();
}

// Output PDF
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $pdfFileName . '"');

$pdf->Output($pdfFileName, 'D');

$conn->close();
exit;
?>