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

// --- Production Performance Settings ---
set_time_limit(600);
ini_set('memory_limit', '2048M');
ini_set('display_errors', 0);
ini_set('max_execution_time', 600);
ini_set('zlib.output_compression', 'Off');

if (ob_get_level() == 0) ob_start();

// --- Enhanced Debug Logging ---
function debugLog($message, $level = 'INFO') {
    $logFile = 'pdf_production.log';
    $timestamp = date('[Y-m-d H:i:s]');
    $memory = round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB';
    $logMessage = "$timestamp [$level] PDF Production: $message (Memory: $memory)" . PHP_EOL;
    error_log($logMessage, 3, $logFile);
}

function handleError($message, $exception = null) {
    $errorDetails = $message;
    if ($exception) {
        $errorDetails .= " - Exception: " . $exception->getMessage();
    }
    debugLog($errorDetails, 'ERROR');
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/html', true, 500);
    echo '<!DOCTYPE html><html><head><title>PDF Generation Error</title></head><body>';
    echo '<h2>PDF Generation Error</h2><p>An error occurred. Please try again.</p>';
    echo '<button onclick="history.back()">Go Back</button></body></html>';
    exit;
}

debugLog("Production PDF generation started");

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];

// --- Get Request Parameters ---
$batch_id = $_GET['batch_id'] ?? null;
$dispositions = null;
$scope = $_GET['scope'] ?? '';
$product_code = $_GET['product_code'] ?? '';

if (!$batch_id && !isset($_GET['disposition'])) {
    die("Error: No valid batch ID or disposition provided.");
}

// --- Determine PDF Title and Filename ---
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle = 'Calling Sheet';

if (isset($_GET['disposition'])) {
    $dispositions = is_array($_GET['disposition']) ? $_GET['disposition'] : [$_GET['disposition']];
    $dispNames = array_map(function($d) { return preg_replace("/[^a-zA-Z0-9]/", "", $d); }, $dispositions);
    
    switch ($scope) {
        case 'batch-wise':
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
        default:
            $pdfFileName = ucwords(preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0])) . '_Sheet.pdf';
            $pdfTitle = "Calling Sheet for Status: " . implode(', ', $dispositions);
    }
} elseif (isset($_GET['batch_id'])) {
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);
}

// --- Fetch Active Dispositions for Legend ---
$dispResult = $conn->query("SELECT code, description, category FROM disposition_codes WHERE is_active = 1 ORDER BY category, CAST(code AS UNSIGNED), code");
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

// Build legends
$dispLegend = '';
if (!empty($dispLegendY)) $dispLegend .= "DISPO (Y): " . implode(' | ', $dispLegendY);
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= "DISPO (N): " . implode(' | ', $dispLegendN);
}
$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// --- Build Database Query ---
$baseSql = "FROM final_call_logs fcl JOIN file_batches fb ON fcl.batch_id = fb.id ";
$whereClauses = ["fb.admin_id = ?"];
$params = [$adminId];
$types = 's';

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

$whereSql = "WHERE " . implode(' AND ', $whereClauses);
$fullBaseSql = $baseSql . $whereSql;

// --- Check Record Count ---
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

if ($totalRecords == 0) {
    die("No records found matching the criteria.");
}

debugLog("Found $totalRecords total records");

// --- Production PDF Class with Exact Requirements ---
class ProductionPDF extends TCPDF {
    private $pdfTitle;
    private $slotLegend;
    private $dispLegend;
    private $mobileColumnBounds;
    
    public function __construct($title, $slotLegend, $dispLegend) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle = $title;
        $this->slotLegend = $slotLegend;
        $this->dispLegend = $dispLegend;
        $this->mobileColumnBounds = [];
    }
    
    public function setMobileColumnBounds($startX, $endX) {
        $this->mobileColumnBounds = ['start' => $startX, 'end' => $endX];
    }
    
    public function Header() {
        $this->SetY(10);
        
        // Main title
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 8, $this->pdfTitle, 0, 1, 'C');
        
        // Slot legend
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 5, $this->slotLegend, 0, 1, 'C');
        
        // Disposition legend (may span multiple lines)
        $this->SetFont('helvetica', '', 7);
        if (strlen($this->dispLegend) > 150) {
            $dispLines = explode(' || ', $this->dispLegend);
            foreach ($dispLines as $line) {
                $this->Cell(0, 4, $line, 0, 1, 'C');
            }
        } else {
            $this->Cell(0, 4, $this->dispLegend, 0, 1, 'C');
        }
        
        $this->Ln(3);
        
        // Draw cutlines after header
        $this->drawCutlines();
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
    
    public function drawCutlines() {
        if (empty($this->mobileColumnBounds)) return;
        
        $this->SetDrawColor(100, 100, 100);
        $this->SetLineWidth(0.3);
        
        $mobileStart = $this->mobileColumnBounds['start'];
        $mobileEnd = $this->mobileColumnBounds['end'];
        $mobileCenter = ($mobileStart + $mobileEnd) / 2;
        
        $pageHeight = $this->getPageHeight();
        $headerEnd = 35; // After header area
        $footerStart = $pageHeight - 20; // Before footer
        
        // Top cutline with scissors at mobile column center
        $topCutY = $headerEnd + 2;
        $this->drawDottedLine($mobileStart, $topCutY, $mobileEnd, $topCutY);
        
        // Scissor symbol at top center
        $this->SetFont('helvetica', 'B', 16);
        $this->SetXY($mobileCenter - 4, $topCutY - 6);
        $this->Cell(8, 6, '✂', 0, 0, 'C');
        
        // Bottom cutline with scissors at mobile column center
        $bottomCutY = $footerStart - 2;
        $this->drawDottedLine($mobileStart, $bottomCutY, $mobileEnd, $bottomCutY);
        
        // Scissor symbol at bottom center
        $this->SetXY($mobileCenter - 4, $bottomCutY + 1);
        $this->Cell(8, 6, '✂', 0, 0, 'C');
    }
    
    private function drawDottedLine($x1, $y1, $x2, $y2) {
        $dashLength = 2;
        $gapLength = 1;
        $totalLength = abs($x2 - $x1);
        $dashCount = floor($totalLength / ($dashLength + $gapLength));
        
        for ($i = 0; $i < $dashCount; $i++) {
            $startX = $x1 + ($i * ($dashLength + $gapLength));
            $endX = min($x1 + ($i * ($dashLength + $gapLength) + $dashLength), $x2);
            $this->Line($startX, $y1, $endX, $y2);
        }
    }
}

// --- FIXED 5-COLUMN STRUCTURE AS REQUIRED ---
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];
$totalWidth = 275; // A4 landscape usable width

// Fixed column widths optimized for requirements
$columnWidths = [
    'id' => 55,           // ID - adequate for single line display
    'slot' => 15,         // Slot - small for single digit
    'connectivity' => 25,  // Connectivity - for "O Y / O N"
    'disposition' => 140,  // Disposition - large for circles grid
    'mobile_no' => 40     // Mobile - with cutlines
];

$columnData = [];
foreach ($finalHeaders as $header) {
    $width = $columnWidths[$header];
    $displayName = match($header) {
        'id' => 'ID',
        'slot' => 'Slot',
        'connectivity' => 'Connectivity',
        'disposition' => 'Disposition',
        'mobile_no' => 'Mobile',
        default => ucwords(str_replace('_', ' ', $header))
    };
    $columnData[] = ['header' => $displayName, 'width' => $width, 'key' => $header];
}

// Initialize PDF
$pdf = new ProductionPDF($pdfTitle, $slotLegend, $dispLegend);
$pdf->SetCreator('Calling Sheet Generator - Production');
$pdf->SetTitle($pdfTitle);

// Calculate margins for centering
$pageWidth = 297; // A4 landscape
$leftMargin = ($pageWidth - $totalWidth) / 2;
$pdf->SetMargins($leftMargin, 40, $leftMargin);
$pdf->SetAutoPageBreak(true, 20);

// Set mobile column bounds for cutlines
$mobileStart = $leftMargin + $columnWidths['id'] + $columnWidths['slot'] + $columnWidths['connectivity'] + $columnWidths['disposition'];
$mobileEnd = $mobileStart + $columnWidths['mobile_no'];
$pdf->setMobileColumnBounds($mobileStart, $mobileEnd);

$pdf->AddPage();

// --- Helper Functions ---
function drawTableHeaders($pdf, $columnData) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    foreach ($columnData as $col) {
        $pdf->Cell($col['width'], 12, $col['header'], 1, 0, 'C', true);
    }
    $pdf->Ln();
}

// --- Create Empty Disposition Circles Grid ---
function createDispositionGrid($dispositionList) {
    $grid = '';
    $itemsPerRow = 6; // Optimized for 140mm width
    $rows = array_chunk($dispositionList, $itemsPerRow);
    
    foreach ($rows as $row) {
        $rowItems = [];
        foreach ($row as $disp) {
            // Empty circles with 2-digit padded codes as required
            $paddedCode = str_pad($disp['code'], 2, '0', STR_PAD_LEFT);
            $rowItems[] = '○' . $paddedCode;
        }
        $grid .= implode('  ', $rowItems) . "\n";
    }
    
    return trim($grid);
}

$dispositionGrid = createDispositionGrid($dispositionList);

// Draw initial headers
drawTableHeaders($pdf, $columnData);

debugLog("Starting data processing");

// --- Optimized Data Processing ---
$chunkSize = min(1000, max(250, floor(1800 / count($finalHeaders)))); // Dynamic chunk size
$offset = 0;
$processedRows = 0;
$maxRows = min($totalRecords, 15000);

while ($offset < $totalRecords && $processedRows < $maxRows) {
    try {
        $currentChunkSize = min($chunkSize, $totalRecords - $offset, $maxRows - $processedRows);
        
        $sql = "SELECT fcl.id, fcl.slot, fcl.connectivity, fcl.disposition, fcl.mobile_no " 
             . $fullBaseSql . " ORDER BY fcl.id LIMIT ?, ?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        $chunkParams = array_merge($params, [$offset, $currentChunkSize]);
        $chunkTypes = $types . 'ii';
        
        if (!$stmt->bind_param($chunkTypes, ...$chunkParams)) {
            throw new Exception("Failed to bind parameters: " . $stmt->error);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute query: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        if (!$result) {
            throw new Exception("Failed to get result set: " . $stmt->error);
        }
        
        if ($result->num_rows === 0) {
            $stmt->close();
            break;
        }
        
        while ($row = $result->fetch_assoc()) {
            // Fixed row height for production consistency
            $rowHeight = 25; // Increased for disposition grid visibility
            
            // Check for page break
            if ($pdf->GetY() + $rowHeight > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
                $pdf->AddPage();
                drawTableHeaders($pdf, $columnData);
            }
            
            // Column 1: ID (single line, auto-sized font)
            $idContent = $row['id'] ?? '';
            $pdf->SetFont('helvetica', 'B', 9);
            
            // Auto-resize ID font to ensure single line
            $fontSize = 9;
            while ($pdf->getStringWidth($idContent) > ($columnWidths['id'] - 6) && $fontSize > 6) {
                $fontSize -= 0.3;
                $pdf->SetFont('helvetica', 'B', $fontSize);
            }
            $pdf->Cell($columnWidths['id'], $rowHeight, $idContent, 1, 0, 'C');
            
            // Column 2: Slot (empty for manual entry)
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell($columnWidths['slot'], $rowHeight, '', 1, 0, 'C');
            
            // Column 3: Connectivity (fixed format)
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell($columnWidths['connectivity'], $rowHeight, '○ Y / ○ N', 1, 0, 'C');
            
            // Column 4: Disposition (empty circles with 2-digit numbers)
            $pdf->SetFont('helvetica', '', 6);
            $pdf->Cell($columnWidths['disposition'], $rowHeight, $dispositionGrid, 1, 0, 'L');
            
            // Column 5: Mobile (bold, centered)
            $mobileContent = $row['mobile_no'] ?? '';
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell($columnWidths['mobile_no'], $rowHeight, $mobileContent, 1, 0, 'C');
            
            $pdf->Ln();
            $processedRows++;
        }
        
        $stmt->close();
        $offset += $currentChunkSize;
        
        // Progress tracking
        if ($processedRows % 500 === 0) {
            gc_collect_cycles();
            $progress = round(($processedRows / min($totalRecords, $maxRows)) * 100, 1);
            debugLog("Progress: {$progress}% - Processed $processedRows / $totalRecords records");
        }
        
        // Memory check
        $currentMemory = memory_get_usage(true);
        $memoryLimit = (int)ini_get('memory_limit') * 1024 * 1024;
        if ($memoryLimit > 0 && $currentMemory > ($memoryLimit * 0.9)) {
            debugLog("Memory limit approaching, stopping at row $processedRows", 'WARNING');
            break;
        }
        
    } catch (Exception $e) {
        if (isset($stmt)) $stmt->close();
        handleError("Database processing error at offset $offset", $e);
    }
}

debugLog("PDF generation completed. Processed $processedRows records.");

// --- Safe PDF Output ---
try {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if ($processedRows === 0) {
        debugLog("No data found for PDF generation", 'WARNING');
        die("No data found matching your criteria.");
    }
    
    if (headers_sent($file, $line)) {
        throw new Exception("Headers already sent in $file on line $line");
    }
    
    debugLog("Outputting PDF with $processedRows records");
    
    header('Content-Type: application/pdf', true);
    header('Content-Disposition: attachment; filename="' . $pdfFileName . '"', true);
    header('Cache-Control: private, max-age=0, must-revalidate', true);
    header('Pragma: public', true);
    
    $pdf->Output($pdfFileName, 'D');
    
} catch (Exception $e) {
    handleError("Error during PDF output", $e);
} finally {
    if (isset($conn)) {
        $conn->close();
        debugLog("Database connection closed");
    }
    
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}

exit;
?>