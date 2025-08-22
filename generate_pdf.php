<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

// --- Auth ---------------------------------------------------------------
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use TCPDF;

// --- Runtime & Output Buffering ----------------------------------------
set_time_limit(300);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
if (ob_get_level() == 0) ob_start();

function debugLog($message) {
    error_log(date('[Y-m-d H:i:s] ') . "PDF Generation: " . $message);
}

debugLog("PDF generation started");

$conn = getDBConnection();
debugLog("Database connection established");

// --- Inputs -------------------------------------------------------------
$batch_id     = $_GET['batch_id'] ?? null;
$dispositions = null;
$scope        = $_GET['scope'] ?? '';
$product_code = $_GET['product_code'] ?? '';

if (!$batch_id && !isset($_GET['disposition'])) {
    die("Error: No valid batch ID or disposition provided.");
}

$adminId = $_SESSION['admin_id'];

// --- File name / Title --------------------------------------------------
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle    = 'Calling Sheet';

if (isset($_GET['disposition'])) {
    $dispositions = is_array($_GET['disposition']) ? $_GET['disposition'] : [$_GET['disposition']];
    $dispNames = array_map(function($d){ return preg_replace("/[^a-zA-Z0-9]/", "", $d); }, $dispositions);

    switch ($scope) {
        case 'batch-wise':
            $pdfFileName = 'Batch_' . $batch_id . '_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle    = "Calling Sheet for Batch $batch_id - Status: " . implode(', ', $dispositions);
            break;
        case 'all-batch':
            $pdfFileName = 'AllBatches_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle    = "Calling Sheet for All Batches - Status: " . implode(', ', $dispositions);
            break;
        case 'product-wise':
            $pdfFileName = 'Product_' . $product_code . '_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle    = "Calling Sheet for Product $product_code - Status: " . implode(', ', $dispositions);
            break;
        case 'all-product':
            $pdfFileName = 'AllProducts_' . implode('_', $dispNames) . '.pdf';
            $pdfTitle    = "Calling Sheet for All Products - Status: " . implode(', ', $dispositions);
            break;
        default:
            $safeDispositionName = preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0]);
            $pdfFileName = ucwords($safeDispositionName) . '_Sheet.pdf';
            $pdfTitle    = "Calling Sheet for Status: " . implode(', ', $dispositions);
    }
} elseif ($batch_id) {
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle    = "Calling Sheet for Batch " . htmlspecialchars($batch_id);
}

// --- Disposition Codes & Legends ---------------------------------------
$dispResult = $conn->query("SELECT code, description, category FROM disposition_codes WHERE is_active = 1 ORDER BY category, CAST(code AS UNSIGNED), code");
$dispositionList = [];
$dispLegendY = [];
$dispLegendN = [];
while ($d = $dispResult->fetch_assoc()) {
    $dispositionList[] = $d;
    if ($d['category'] === 'connected') {
        $dispLegendY[] = "{$d['code']}:{$d['description']}";
    } else {
        $dispLegendN[] = "{$d['code']}:{$d['description']}";
    }
}

$dispLegend = '';
if (!empty($dispLegendY)) $dispLegend .= 'DISPO (Y): ' . implode(' | ', $dispLegendY);
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= 'DISPO (N): ' . implode(' | ', $dispLegendN);
}
$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// --- Query Build (STRICT like Code 1) -----------------------------------
$baseSql = "FROM final_call_logs fcl JOIN file_batches fb ON fcl.batch_id = fb.id ";
$whereClauses = ["fb.admin_id = ?"];
$params = [$adminId];
$types  = 's';

switch ($scope) {
    case 'batch-wise':
        if ($batch_id) { $whereClauses[] = "fcl.batch_id = ?"; $params[] = $batch_id; $types .= 's'; }
        break;
    case 'product-wise':
        if ($product_code) { $whereClauses[] = "fb.product_code = ?"; $params[] = $product_code; $types .= 's'; }
        break;
    default:
        if ($batch_id) { $whereClauses[] = "fcl.batch_id = ?"; $params[] = $batch_id; $types .= 's'; }
}

if ($dispositions && !empty($dispositions)) {
    $placeholders = implode(',', array_fill(0, count($dispositions), '?'));
    $whereClauses[] = "fcl.disposition IN ($placeholders)";
    $params = array_merge($params, $dispositions);
    $types .= str_repeat('s', count($dispositions));
}

// STRONG guard: must have admin_id + at least one more filter
if (count($whereClauses) <= 1 && !$batch_id && !$dispositions) {
    die("Error: No criteria selected for PDF generation.");
}
$whereSql    = 'WHERE ' . implode(' AND ', $whereClauses);
$fullBaseSql = $baseSql . $whereSql;

// --- Count ---------------------------------------------------------------
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
if ($totalRecords == 0) die("No records found matching the criteria.");

// --- Detect optional columns -------------------------------------------
$optionalColumns = ['title','name','policy_number','pan','dob','age','expiry','address','city','state','country','pincode','plan','premium','sum_insured'];
$selects = [];
foreach ($optionalColumns as $column) {
    $selects[] = "MAX(CASE WHEN fcl.`{$column}` IS NOT NULL AND fcl.`{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}
$presenceCheckSql = 'SELECT ' . implode(', ', $selects) . ' ' . $fullBaseSql;
$stmt = $conn->prepare($presenceCheckSql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$columnPresence = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Final headers (Code 1 logic to avoid blank Name) -------------------
$finalHeaders = ['id','slot','connectivity','disposition','mobile_no'];
if (!empty($columnPresence['has_title'])) $finalHeaders[] = 'title';
if (!empty($columnPresence['has_name']))  $finalHeaders[] = 'name';

$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if (!in_array($column, $finalHeaders) && !empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column;
        $addedCount++;
    }
}

// --- PDF Class (merge: header/footer + dashed cutlines) -----------------
class CompletePDF extends TCPDF {
    private $pdfTitle;
    private $slotLegend;
    private $dispLegend;
    private $cutlineBefore;
    private $cutlineAfter;

    public function __construct($title, $slotLegend, $dispLegend) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle   = $title;
        $this->slotLegend = $slotLegend;
        $this->dispLegend = $dispLegend;
    }
    public function setCutlines($before, $after) { $this->cutlineBefore = $before; $this->cutlineAfter = $after; }

    public function Header() {
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(0, 6, $this->pdfTitle, 0, 1, 'C');
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 4, $this->slotLegend, 0, 1, 'C');
        $dispLines = strlen($this->dispLegend) > 140 ? explode(' || ', $this->dispLegend) : [$this->dispLegend];
        foreach ($dispLines as $line) { $this->Cell(0, 3, $line, 0, 1, 'C'); }
        $this->Ln(2);
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
    // Dashed vertical guides in header/footer areas (from Code 2)
    public function drawPageCutlines() {
        if (!$this->cutlineBefore || !$this->cutlineAfter) return;
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.1);
        $dash = 2;
        $headerEndY = 40;              // just below legends
        $footerStartY = $this->getPageHeight() - 25; // above footer
        // Top band
        for ($y = 10; $y < $headerEndY; $y += $dash * 2) {
            $this->Line($this->cutlineBefore, $y, $this->cutlineBefore, min($y + $dash, $headerEndY));
            $this->Line($this->cutlineAfter,  $y, $this->cutlineAfter,  min($y + $dash, $headerEndY));
        }
        // Bottom band
        $pageBottom = $this->getPageHeight() - 10;
        for ($y = $footerStartY; $y < $pageBottom; $y += $dash * 2) {
            $this->Line($this->cutlineBefore, $y, $this->cutlineBefore, min($y + $dash, $pageBottom));
            $this->Line($this->cutlineAfter,  $y, $this->cutlineAfter,  min($y + $dash, $pageBottom));
        }
        // Also add subtle 5mm ticks in margins (from Code 1)
        $this->SetDrawColor(150,150,150);
        $this->SetLineWidth(0.2);
        $topMargin = $this->getHeaderMargin();
        $bottomMargin = $this->getFooterMargin();
        $topMarkY = $topMargin / 2;               // middle of top margin
        $this->Line($this->cutlineBefore, $topMarkY - 2.5, $this->cutlineBefore, $topMarkY + 2.5);
        $this->Line($this->cutlineAfter,  $topMarkY - 2.5, $this->cutlineAfter,  $topMarkY + 2.5);
        $bottomMarkY = $this->getPageHeight() - ($bottomMargin / 2);
        $this->Line($this->cutlineBefore, $bottomMarkY - 2.5, $this->cutlineBefore, $bottomMarkY + 2.5);
        $this->Line($this->cutlineAfter,  $bottomMarkY - 2.5, $this->cutlineAfter,  $bottomMarkY + 2.5);
    }
}

// --- Column widths & centering -----------------------------------------
$columnData  = [];
$totalWidth  = 275; // content width target in mm
$widthMap = [
    'id'=>22,'slot'=>12,'connectivity'=>18,'disposition'=>42,
    'mobile_no'=>25,'title'=>12,'name'=>45,'policy_number'=>25,
    'pan'=>20,'dob'=>20,'age'=>12,'expiry'=>20,
    'address'=>50,'city'=>25,'state'=>25,'country'=>18,
    'pincode'=>16,'plan'=>22,'premium'=>18,'sum_insured'=>22
];
foreach ($finalHeaders as $h) {
    $w = $widthMap[$h] ?? 20;
    $label = ucwords(str_replace('_', ' ', $h));
    if ($h === 'id') $label = 'ID';
    if ($h === 'mobile_no') $label = 'Mobile';
    $columnData[] = ['header'=>$label,'width'=>$w,'key'=>$h];
}

$pdf = new CompletePDF($pdfTitle, $slotLegend, $dispLegend);
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);

// Scale to fit widthMap into totalWidth
$currentTotal = array_sum(array_column($columnData, 'width'));
if ($currentTotal > $totalWidth) {
    $scale = $totalWidth / $currentTotal;
    foreach ($columnData as &$col) { $col['width'] = round($col['width'] * $scale, 2); }
    unset($col);
    $currentTotal = array_sum(array_column($columnData, 'width'));
}
$pageWidth  = 297; // A4 landscape width in mm
$leftMargin = ($pageWidth - $currentTotal) / 2;
$pdf->SetMargins($leftMargin, 35, $leftMargin);
$pdf->SetAutoPageBreak(true, 15);

// Cutline X positions around the Mobile column
$mobileIndex = array_search('mobile_no', array_column($columnData, 'key'));
if ($mobileIndex !== false) {
    $cutlineBefore = $leftMargin; for ($i=0; $i<$mobileIndex; $i++) $cutlineBefore += $columnData[$i]['width'];
    $cutlineAfter  = $cutlineBefore + $columnData[$mobileIndex]['width'];
    $pdf->setCutlines($cutlineBefore, $cutlineAfter);
}

$pdf->AddPage();

// --- Helpers ------------------------------------------------------------
function redrawHeaders($pdf, $columnData) {
    $pdf->drawPageCutlines();
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240,240,240);
    foreach ($columnData as $col) {
        $pdf->Cell($col['width'], 7, $col['header'], 1, 0, 'C', true);
    }
    $pdf->Ln();
}
function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    $ts = strtotime($dateValue);
    return $ts !== false ? date('d-m-Y', $ts) : $dateValue;
}

// Draw initial headers
redrawHeaders($pdf, $columnData);

// Disposition grid (scales automatically)
$dispGrid = '';
$gridRows = array_chunk($dispositionList, 5);
foreach ($gridRows as $row) {
    $dispCodes = [];
    foreach ($row as $disp) { $dispCodes[] = 'O ' . $disp['code']; }
    $dispGrid .= implode('  ', $dispCodes) . "\n";
}
$dispGrid = trim($dispGrid);

debugLog("Found $totalRecords total records");

// --- Data streaming -----------------------------------------------------
$finalHeadersWithAlias = 'fcl.`' . implode('`, fcl.`', $finalHeaders) . '`';
$chunkSize    = 500;
$offset       = 0;
$processed    = 0;
$maxRows      = min($totalRecords, 10000);
$memCheckEvery = 100; // Code 2 guard

if ($totalRecords > $maxRows) debugLog("Warning: Large dataset ($totalRecords). Processing first $maxRows records.");

debugLog('Column headers: ' . implode(', ', $finalHeaders));

while ($offset < $totalRecords && $processed < $maxRows) {
    $currentChunk = min($chunkSize, $totalRecords - $offset, $maxRows - $processed);
    $sql = "SELECT {$finalHeadersWithAlias} " . $fullBaseSql . " ORDER BY fcl.id LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    $chunkParams = array_merge($params, [$offset, $currentChunk]);
    $chunkTypes  = $types . 'ii';
    if ($chunkTypes) $stmt->bind_param($chunkTypes, ...$chunkParams);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) { $stmt->close(); break; }

    $rowsInChunk = 0;
    while ($row = $result->fetch_assoc()) {
        // Memory guard (from Code 2)
        if ($rowsInChunk % $memCheckEvery === 0) {
            $mem = memory_get_usage(true) / 1048576; // MB
            if ($mem > 800) { debugLog("High memory usage: {$mem}MB. Aborting."); break 2; }
        }

        // --- 1) Measure row height across all cells (Code 1 approach) ----
        $maxHeight = 6; // min
        $cellContents = [];
        foreach ($columnData as $col) {
            $key = $col['key'];
            switch ($key) {
                case 'disposition': $content = $dispGrid; $font = 6; break;
                case 'connectivity': $content = 'O Y / O N'; $font = 7; break;
                case 'dob':
                case 'expiry': $content = formatDateForPDF($row[$key] ?? ''); $font = 7; break;
                default: $content = $row[$key] ?? ''; $font = ($key==='address'?6:7);
            }
            $cellContents[$key] = [$content, $font];
            $pdf->SetFont('helvetica','', $font);
            $h = $pdf->getStringHeight($col['width'], (string)$content);
            if ($h > $maxHeight) $maxHeight = $h;
        }

        // --- 2) Page break pre-check -----------------------------------
        if ($pdf->GetY() + $maxHeight > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
            $pdf->AddPage();
            redrawHeaders($pdf, $columnData);
        }

        // --- 3) Draw cells with uniform height using MultiCell ----------
        foreach ($columnData as $col) {
            $key = $col['key'];
            [$content, $font] = $cellContents[$key];
            $align = 'L'; $bold = false;
            switch ($key) {
                case 'mobile_no': $align='C'; $bold=true; break;
                case 'id':
                case 'dob':
                case 'expiry':
                case 'slot': $align='C'; break;
                case 'connectivity': $align='C'; break;
                case 'disposition': $font = 6; break;
                case 'address': $font = 6; break;
            }
            $pdf->SetFont('helvetica', $bold ? 'B' : '', $font);
            $pdf->MultiCell($col['width'], $maxHeight, (string)$content, 1, $align, false, 0, '', '', true, 0, false, true, $maxHeight, 'M');
        }
        $pdf->Ln($maxHeight);
        $processed++; $rowsInChunk++;
    }

    $stmt->close();
    $offset += $currentChunk;

    if ($processed % 1000 === 0) { gc_collect_cycles(); debugLog("Processed $processed / $totalRecords"); }
}

// --- Output -------------------------------------------------------------
try {
    while (ob_get_level()) ob_end_clean();
    if ($processed === 0) {
        $pdf = new CompletePDF('No Data Found', '', '');
        $pdf->AddPage();
        $pdf->SetFont('helvetica','B',16);
        $pdf->Cell(0,20,'No Data Found',0,1,'C');
        $pdf->SetFont('helvetica','',12);
        $pdf->MultiCell(0,10,'No records were found matching your selection criteria. Please adjust the filters and try again.',0,'C');
    }
    header('Content-Type: application/pdf', true);
    header('Content-Disposition: attachment; filename="' . $pdfFileName . '"', true);
    header('Cache-Control: private, max-age=0, must-revalidate', true);
    header('Pragma: public', true);
    $pdf->Output($pdfFileName, 'D');
} catch (Exception $e) {
    debugLog('Error during PDF output: ' . $e->getMessage());
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html', true, 500);
    echo '<!DOCTYPE html><html><head><title>PDF Generation Error</title></head><body>';
    echo '<h2>PDF Generation Error</h2><p>An error occurred while generating the PDF. Please try again or select fewer records.</p>';
    echo '<button onclick="history.back()">Go Back</button></body></html>';
} finally {
    if (isset($conn)) $conn->close();
}

exit;
?>
