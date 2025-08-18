<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

// Check admin authentication
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use TCPDF;

set_time_limit(0);
ini_set('memory_limit', '2048M');
ini_set('display_errors', 0);

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

// Create exactly matching PDF layout
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);
$pdf->SetMargins(5, 20, 5);
$pdf->SetAutoPageBreak(true, 15);

// Custom header function
$pdf->SetHeaderData('', 0, '', '');
$pdf->setHeaderFont(Array('helvetica', '', 10));
$pdf->setFooterFont(Array('helvetica', '', 8));

$pdf->AddPage();

// Title and legends exactly like sample
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, $pdfTitle, 0, 1, 'C');

$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(0, 4, $slotLegend, 0, 1, 'C');

// Handle long disposition legend
$dispLines = strlen($dispLegend) > 140 ? explode(' || ', $dispLegend) : [$dispLegend];
foreach ($dispLines as $line) {
    $pdf->Cell(0, 3, $line, 0, 1, 'C');
}
$pdf->Ln(2);

// Set up exact column structure matching sample
$columnData = [];
$totalWidth = 287; // A4 landscape minus margins

foreach ($finalHeaders as $header) {
    switch ($header) {
        case 'id':
            $columnData[] = ['header' => 'Id', 'width' => 22];
            break;
        case 'slot':
            $columnData[] = ['header' => 'Slot', 'width' => 12];
            break;
        case 'connectivity':
            $columnData[] = ['header' => 'Connectivity', 'width' => 20];
            break;
        case 'disposition':
            $columnData[] = ['header' => 'Disposition', 'width' => 32];
            break;
        case 'mobile_no':
            $columnData[] = ['header' => 'Mobile', 'width' => 24];
            break;
        case 'title':
            $columnData[] = ['header' => 'Title', 'width' => 12];
            break;
        case 'name':
            $columnData[] = ['header' => 'Name', 'width' => 28];
            break;
        case 'pan':
            $columnData[] = ['header' => 'Pan', 'width' => 18];
            break;
        case 'dob':
            $columnData[] = ['header' => 'Dob', 'width' => 18];
            break;
        case 'age':
            $columnData[] = ['header' => 'Age', 'width' => 10];
            break;
        case 'address':
            $columnData[] = ['header' => 'Address', 'width' => 35];
            break;
        case 'city':
            $columnData[] = ['header' => 'City', 'width' => 16];
            break;
        case 'state':
            $columnData[] = ['header' => 'State', 'width' => 16];
            break;
        case 'pincode':
            $columnData[] = ['header' => 'Pincode', 'width' => 16];
            break;
        default:
            $columnData[] = ['header' => ucwords(str_replace('_', ' ', $header)), 'width' => 18];
            break;
    }
}

// Scale to fit if necessary
$currentTotal = array_sum(array_column($columnData, 'width'));
if ($currentTotal > $totalWidth) {
    $scale = $totalWidth / $currentTotal;
    foreach ($columnData as &$col) {
        $col['width'] *= $scale;
    }
}

// Draw header row exactly like sample
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(240, 240, 240);
foreach ($columnData as $col) {
    $pdf->Cell($col['width'], 6, $col['header'], 1, 0, 'C', true);
}
$pdf->Ln();

// Calculate cutline position (after Mobile column)
$cutlineX = 5; // Start from left margin
$mobileIndex = array_search('mobile_no', $finalHeaders);
for ($i = 0; $i <= $mobileIndex; $i++) {
    $cutlineX += $columnData[$i]['width'];
}

// Draw cutline (dashed vertical line like sample)
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.2);
$startY = $pdf->GetY();
for ($y = $startY; $y < 200; $y += 2) {
    $pdf->Line($cutlineX, $y, $cutlineX, $y + 1);
}

// Create compact disposition grid exactly like sample
$dispGrid = '';
$gridRow1 = [];
$gridRow2 = [];
$gridRow3 = [];

foreach ($dispositionList as $i => $disp) {
    if ($i < 4) {
        $gridRow1[] = 'O ' . $disp['code'];
    } elseif ($i < 8) {
        $gridRow2[] = 'O ' . $disp['code'];
    } else {
        $gridRow3[] = 'O ' . $disp['code'];
    }
}

$dispGrid = implode(' ', $gridRow1);
if (!empty($gridRow2)) {
    $dispGrid .= "\n" . implode(' ', $gridRow2);
}
if (!empty($gridRow3)) {
    $dispGrid .= "\n" . implode(' ', $gridRow3);
}

function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    
    $timestamp = strtotime($dateValue);
    if ($timestamp !== false) {
        return date('d-m-Y', $timestamp);
    }
    
    return $dateValue;
}

// Fetch and display data with exact sample formatting
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

$rowHeight = 8; // Compact row height like sample

while ($row = $result->fetch_assoc()) {
    if ($pdf->GetY() + $rowHeight > 190) {
        $pdf->AddPage();
        
        // Redraw header
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(240, 240, 240);
        foreach ($columnData as $col) {
            $pdf->Cell($col['width'], 6, $col['header'], 1, 0, 'C', true);
        }
        $pdf->Ln();
        
        // Redraw cutline
        $startY = $pdf->GetY();
        for ($y = $startY; $y < 200; $y += 2) {
            $pdf->Line($cutlineX, $y, $cutlineX, $y + 1);
        }
    }
    
    $pdf->SetFont('helvetica', '', 6);
    
    foreach ($finalHeaders as $i => $header) {
        $cellContent = '';
        $fontSize = 6;
        $isBold = false;
        
        switch($header) {
            case 'disposition':
                $cellContent = $dispGrid;
                $fontSize = 5;
                break;
            case 'connectivity':
                $cellContent = 'O Y / O N';
                break;
            case 'slot':
                $cellContent = '';
                break;
            case 'mobile_no':
                $cellContent = $row[$header] ?? '';
                $isBold = true;
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
                if (strlen($cellContent) > 15) {
                    $cellContent = substr($cellContent, 0, 15) . '..';
                }
                break;
        }
        
        $pdf->SetFont('helvetica', $isBold ? 'B' : '', $fontSize);
        
        if ($header === 'disposition') {
            $currentX = $pdf->GetX();
            $currentY = $pdf->GetY();
            $pdf->MultiCell($columnData[$i]['width'], $rowHeight, $cellContent, 1, 'L', false);
            $pdf->SetXY($currentX + $columnData[$i]['width'], $currentY);
        } else {
            $pdf->Cell($columnData[$i]['width'], $rowHeight, $cellContent, 1, 0, 'L');
        }
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