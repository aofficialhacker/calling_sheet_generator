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

// Create custom TCPDF class with proper header/footer
class SampleMatchPDF extends TCPDF {
    private $pdfTitle;
    private $slotLegend;
    private $dispLegend;
    private $cutlineX = 0;
    
    public function __construct($title, $slotLegend, $dispLegend) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle = $title;
        $this->slotLegend = $slotLegend;
        $this->dispLegend = $dispLegend;
    }
    
    public function setCutlineX($x) {
        $this->cutlineX = $x;
    }
    
    public function Header() {
        // Title
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 8, $this->pdfTitle, 0, 1, 'C');
        
        // Legends on every page
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 5, $this->slotLegend, 0, 1, 'C');
        
        // Split disposition legend if too long
        if (strlen($this->dispLegend) > 120) {
            $lines = explode(' || ', $this->dispLegend);
            foreach ($lines as $line) {
                $this->Cell(0, 4, $line, 0, 1, 'C');
            }
        } else {
            $this->Cell(0, 4, $this->dispLegend, 0, 1, 'C');
        }
        
        $this->Ln(3);
        
        // Draw cutline ONLY at top and bottom of Mobile column if position is set
        if ($this->cutlineX > 0) {
            $this->SetDrawColor(128, 128, 128);
            $this->SetLineWidth(0.5);
            
            // Top cutline (only small segment)
            $headerY = $this->GetY();
            $this->Line($this->cutlineX, $headerY - 2, $this->cutlineX, $headerY + 3);
            
            // Add scissors symbol at top
            $this->SetXY($this->cutlineX - 2, $headerY - 5);
            $this->SetFont('helvetica', '', 8);
            $this->Cell(4, 4, '✂', 0, 0, 'C');
        }
    }
    
    public function Footer() {
        // Bottom cutline if position is set
        if ($this->cutlineX > 0) {
            $this->SetDrawColor(128, 128, 128);
            $this->SetLineWidth(0.5);
            
            // Bottom cutline (only small segment)
            $footerY = $this->getPageHeight() - 25;
            $this->Line($this->cutlineX, $footerY, $this->cutlineX, $footerY + 5);
            
            // Add scissors symbol at bottom
            $this->SetXY($this->cutlineX - 2, $footerY + 6);
            $this->SetFont('helvetica', '', 8);
            $this->Cell(4, 4, '✂', 0, 0, 'C');
        }
        
        // Page number
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

// Create PDF instance
$pdf = new SampleMatchPDF($pdfTitle, $slotLegend, $dispLegend);
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);
$pdf->SetMargins(5, 30, 5); // Reduced margins to fit more content
$pdf->SetAutoPageBreak(true, 25);

$pdf->AddPage();

// Fixed table headers and column widths matching sample exactly
$headers = [];
$widths = [];

// Calculate available width (A4 landscape - margins)
$pageWidth = 297 - 10; // A4 landscape width minus margins
$totalCols = count($finalHeaders);

// Set exact widths based on sample screenshot
foreach ($finalHeaders as $header) {
    switch ($header) {
        case 'id':
            $headers[] = 'Id';
            $widths[] = 22;
            break;
        case 'slot':
            $headers[] = 'Slot';
            $widths[] = 15;
            break;
        case 'connectivity':
            $headers[] = 'Connectivity';
            $widths[] = 24;
            break;
        case 'disposition':
            $headers[] = 'Disposition';
            $widths[] = 52;
            break;
        case 'mobile_no':
            $headers[] = 'Mobile';
            $widths[] = 28;
            break;
        case 'title':
            $headers[] = 'Title';
            $widths[] = 18;
            break;
        case 'name':
            $headers[] = 'Name';
            $widths[] = 32;
            break;
        case 'pan':
            $headers[] = 'Pan';
            $widths[] = 20;
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
            $widths[] = 45;
            break;
        case 'city':
            $headers[] = 'City';
            $widths[] = 18;
            break;
        case 'state':
            $headers[] = 'State';
            $widths[] = 16;
            break;
        case 'pincode':
            $headers[] = 'Pincode';
            $widths[] = 16;
            break;
        default:
            $headers[] = ucwords(str_replace('_', ' ', $header));
            $widths[] = 18;
            break;
    }
}

// Scale down if needed to fit page
$totalWidth = array_sum($widths);
if ($totalWidth > $pageWidth) {
    $scale = $pageWidth / $totalWidth;
    for ($i = 0; $i < count($widths); $i++) {
        $widths[$i] = $widths[$i] * $scale;
    }
}

// Draw table headers
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(220, 220, 220);

foreach ($headers as $i => $header) {
    $pdf->Cell($widths[$i], 8, $header, 1, 0, 'C', true);
}
$pdf->Ln();

// Calculate cutline position (after Mobile column)
$cutlineX = 5; // Start from left margin
$mobileIndex = array_search('mobile_no', $finalHeaders);
for ($i = 0; $i <= $mobileIndex; $i++) {
    $cutlineX += $widths[$i];
}

// Set cutline position in PDF class
$pdf->setCutlineX($cutlineX);

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

// Function to redraw table headers on new page
function drawTableHeaders($pdf, $headers, $widths) {
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 220, 220);
    foreach ($headers as $i => $header) {
        $pdf->Cell($widths[$i], 8, $header, 1, 0, 'C', true);
    }
    $pdf->Ln();
}

$rowHeight = 14; // Fixed row height to prevent overlapping

while ($row = $result->fetch_assoc()) {
    // Check if we need a new page
    if ($pdf->GetY() + $rowHeight > 180) {
        $pdf->AddPage();
        drawTableHeaders($pdf, $headers, $widths);
    }
    
    $startY = $pdf->GetY();
    
    // Process each cell
    foreach ($finalHeaders as $i => $header) {
        $cellContent = '';
        $fontSize = 7;
        $fontStyle = '';
        
        switch($header) {
            case 'disposition':
                $cellContent = $dispositionGrid;
                $fontSize = 6;
                break;
            case 'connectivity':
                $cellContent = 'O Y / O N';
                break;
            case 'slot':
                $cellContent = '';
                break;
            case 'mobile_no':
                $cellContent = $row[$header] ?? '';
                $fontStyle = 'B';
                break;
            case 'id':
                $cellContent = $row[$header] ?? '';
                break;
            case 'dob':
            case 'expiry':
                $cellContent = formatDateForPDF($row[$header] ?? '');
                break;
            case 'address':
                $addr = $row[$header] ?? '';
                if (strlen($addr) > 40) {
                    $cellContent = substr($addr, 0, 40) . '...';
                } else {
                    $cellContent = $addr;
                }
                $fontSize = 6;
                break;
            default:
                $cellContent = $row[$header] ?? '';
                if (strlen($cellContent) > 25) {
                    $cellContent = substr($cellContent, 0, 25) . '...';
                }
                break;
        }
        
        $pdf->SetFont('helvetica', $fontStyle, $fontSize);
        
        // Use proper cell positioning to prevent overlapping
        $currentX = $pdf->GetX();
        
        if ($header === 'disposition') {
            // Use MultiCell for disposition with fixed height
            $pdf->MultiCell($widths[$i], $rowHeight, $cellContent, 1, 'L', false);
            $pdf->SetXY($currentX + $widths[$i], $startY);
        } else {
            // Use regular Cell with fixed height
            $pdf->Cell($widths[$i], $rowHeight, $cellContent, 1, 0, 'L');
        }
    }
    
    // Move to next row
    $pdf->SetXY(5, $startY + $rowHeight);
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