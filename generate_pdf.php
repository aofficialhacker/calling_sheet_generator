<?php
require 'vendor/autoload.php';
require_once 'db_config.php';

use Mpdf\Mpdf;
use Mpdf\HTMLParserMode;

// --- Server-Side Download Token ---
if (isset($_GET['download_token'])) {
    $token = preg_replace('/[^0-9]/', '', $_GET['download_token']);
    if (!empty($token)) {
        setcookie("download_token_" . $token, "true", time() + 30, "/");
    }
}

set_time_limit(0);
ini_set('memory_limit', '2048M');

$conn = getDBConnection();

// --- Determine Data Source ---
$pdfFileName = 'Calling_Sheet.pdf';
$pdfTitle = 'Calling Sheet';
$batch_id = null;
$dispositions = null;

if (isset($_GET['batch_id'])) {
    $batch_id = $_GET['batch_id'];
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);
} elseif (isset($_GET['disposition'])) {
    $dispositions = explode(',', $_GET['disposition']);
    $safeDispositionName = preg_replace("/[^a-zA-Z0-9]/", "", $dispositions[0]);
    $pdfFileName = ucwords($safeDispositionName) . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Status: " . htmlspecialchars(implode(', ', $dispositions));
} else {
    die("Error: No valid batch ID or disposition provided.");
}

// --- Fetch Dynamic Disposition Codes ---
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

// Build legends
$dispLegend = '';
if (!empty($dispLegendY)) {
    $dispLegend .= "<strong>DISPO (Y):</strong> " . implode(' | ', $dispLegendY);
}
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= "<strong>DISPO (N):</strong> " . implode(' | ', $dispLegendN);
}

$slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// --- Dynamically Detect Which Columns Have Data ---
$baseSql = "FROM final_call_logs ";
$whereClauses = [];
$params = [];
$types = '';

if ($batch_id) {
    $whereClauses[] = "batch_id = ?";
    $params[] = $batch_id;
    $types .= 's';
}
if ($dispositions) {
    $placeholders = implode(',', array_fill(0, count($dispositions), '?'));
    $whereClauses[] = "disposition IN ($placeholders)";
    $params = array_merge($params, $dispositions);
    $types .= str_repeat('s', count($dispositions));
}

if (empty($whereClauses)) {
    die("Error: No criteria selected for PDF generation.");
}
$whereSql = "WHERE " . implode(' AND ', $whereClauses);
$fullBaseSql = $baseSql . $whereSql;

// Check total record count for optimization
$countSql = "SELECT COUNT(*) as total " . $fullBaseSql;
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

// Define columns in the order they should appear (MODIFICATION: mobile_no before name)
// Fixed order: id, slot, mobile_no, name, connectivity, disposition, then dynamic columns
$optionalColumns = ['title','name', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
$selects = [];
foreach ($optionalColumns as $column) {
    $selects[] = "MAX(CASE WHEN `{$column}` IS NOT NULL AND `{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}

$presenceCheckSql = "SELECT " . implode(', ', $selects) . " " . $fullBaseSql;
$stmt = $conn->prepare($presenceCheckSql);
if ($stmt === false) {
    die("Error preparing statement (presence check): " . $conn->error);
}
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$columnPresence = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Build final headers in the correct order
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];

// Add name if it has data
if (!empty($columnPresence["has_title"]) || !empty($columnPresence["has_name"])) {
    if (!empty($columnPresence["has_title"])) {
        $finalHeaders[] = 'title';
    }
    $finalHeaders[] = 'name';
}

// Add remaining optional columns that have data, limiting to 12 total columns (MODIFICATION 4)
$remainingSlots = 12 - count($finalHeaders);
$addedCount = 0;
foreach ($optionalColumns as $column) {
    if ($addedCount >= $remainingSlots) break;
    if ($column !== 'title' && $column !== 'name' && !empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column;
        $addedCount++;
    }
}

// Configure mPDF with optimizations
$colCount = count($finalHeaders);
$mpdf = new Mpdf([
    'mode' => 'utf-8', 
    'format' => 'A4-L', 
    'tempDir' => __DIR__ . '/tmp',
    'simpleTables' => true, // Optimization for faster processing
    'packTableData' => true // Memory optimization
]);
$mpdf->SetDisplayMode('fullpage');
$mpdf->SetTitle($pdfTitle);

// Create dynamic disposition grid based on active codes
$dispoGridHtml = '<table class="dispo-grid"><tr>';
$gridCols = 0;
$maxCols = 4; // Maximum columns in disposition grid
foreach ($dispositionList as $index => $disp) {
    if ($gridCols >= $maxCols) {
        $dispoGridHtml .= '</tr><tr>';
        $gridCols = 0;
    }
    $dispoGridHtml .= '<td>○ ' . htmlspecialchars($disp['code']) . '</td>';
    $gridCols++;
}
// Fill remaining cells if needed
while ($gridCols < $maxCols) {
    $dispoGridHtml .= '<td></td>';
    $gridCols++;
}
$dispoGridHtml .= '</tr></table>';

// CSS and HTML Head with optimizations
$html_head = '<html><head><style>
    body { font-family: sans-serif; font-size: 7pt; }
    table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; page-break-after: auto; }
    th, td { border: 1px solid #333; padding: 2px 3px; text-align: left; vertical-align: middle; word-wrap: break-word; overflow: hidden; }
    thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; font-size: 7pt; }
    .id-col { font-size: 6pt; font-family: monospace; }
    .mobile-col { font-weight: bold; font-family: monospace; position: relative; }
    .mobile-col-content::after {
        content: '';
        position: absolute;
        top: 0;
        bottom: 0;
        left: 50%;
        border-left: 1px dashed black;
    }
    .connectivity-col, .slot-cell { text-align: center; }
    .disposition-cell { font-size: 6.5pt; padding: 1px !important; }
    .dispo-grid { border: none !important; width: 100%; table-layout: fixed; }
    .dispo-grid td { border: none !important; padding: 0px 1px; text-align: left; font-size: 6.5pt; white-space: nowrap; }
    @media print { 
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    }
</style></head><body>';

// Create Table Header Row
$tableHeaderHtml = '<thead>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $pdfTitle . '</th></tr>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $slotLegend . '</th></tr>';

if (!empty($dispLegend)) {
    $tableHeaderHtml .= '<tr><th class="legend-cell" colspan="' . $colCount . '">' . $dispLegend . '</th></tr>';
}

$tableHeaderHtml .= '<tr>';
foreach ($finalHeaders as $header) {
    $headerClass = '';
    if ($header === 'id') {
        $headerClass = 'id-col';
    } elseif ($header === 'mobile_no') {
        $headerClass = 'mobile-col';
    }
    $displayHeader = str_replace('_', ' ', ucwords($header));
    if ($header === 'mobile_no') $displayHeader = 'Mobile ✂';
    $tableHeaderHtml .= '<th class="' . $headerClass . '">' . htmlspecialchars($displayHeader) . '</th>';
}
$tableHeaderHtml .= '</tr></thead>';

// Write initial HTML structure to mPDF
$mpdf->WriteHTML($html_head);
$mpdf->SetHTMLFooter('<div style="text-align: right; font-size: 8pt;">Page {PAGENO} of {nbpg}</div>');

// Process Data in Optimized Chunks
$chunkSize = 500; // Increased chunk size for better performance
$offset = 0;
$columnsToSelect = '`' . implode('`, `', $finalHeaders) . '`';

// Function to format date
function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    
    $timestamp = strtotime($dateValue);
    if ($timestamp !== false) {
        return date('d-m-Y', $timestamp);
    }
    
    return $dateValue;
}

// Pre-compile the entire document in memory for better performance
$fullHtml = '<table class="data-table">' . $tableHeaderHtml . '<tbody>';
$rowsProcessed = 0;

while ($rowsProcessed < $totalRecords) {
    $sql = "SELECT {$columnsToSelect} " . $fullBaseSql . " ORDER BY id LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        die("Error preparing statement (data fetch): " . $conn->error);
    }

    $chunkParams = array_merge($params, [$offset, $chunkSize]);
    $chunkTypes = $types . 'ii';
    if($chunkTypes) $stmt->bind_param($chunkTypes, ...$chunkParams);
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        break;
    }

    while ($row = $result->fetch_assoc()) {
        $fullHtml .= '<tr>';
        foreach ($finalHeaders as $header) {
            $class = '';
            $cellContent = '';
            switch($header) {
                case 'disposition':
                    // Use the dynamic disposition grid
                    $cellContent = $dispoGridHtml;
                    $class = 'disposition-cell';
                    break;
                case 'connectivity':
                    $cellContent = '○ Y / ○ N';
                    $class = 'connectivity-col';
                    break;
                case 'slot':
                    $cellContent = '';
                    $class = 'slot-cell';
                    break;
                case 'mobile_no':
                    $cellContent = '<div class="mobile-col-content">' . htmlspecialchars($row[$header] ?? '') . '</div>';
                    $class = 'mobile-col';
                    break;
                case 'id':
                    $cellContent = htmlspecialchars($row[$header] ?? '');
                    $class = 'id-col';
                    break;
                case 'dob':
                case 'expiry':
                    $cellContent = htmlspecialchars(formatDateForPDF($row[$header] ?? ''));
                    break;
                default:
                    $cellContent = htmlspecialchars($row[$header] ?? '');
                    break;
            }
            $fullHtml .= '<td class="' . $class . '">' . $cellContent . '</td>';
        }
        $fullHtml .= '</tr>';
        $rowsProcessed++;
        
        // Write in batches to prevent memory issues
        if ($rowsProcessed % 1000 == 0) {
            $fullHtml .= '</tbody></table>';
            $mpdf->WriteHTML($fullHtml);
            $fullHtml = '<table class="data-table"><tbody>';
        }
    }
    
    $stmt->close();
    $offset += $chunkSize;
}

// Write any remaining HTML
if (strpos($fullHtml, '<tbody>') !== false) {
    $fullHtml .= '</tbody></table>';
    $mpdf->WriteHTML($fullHtml);
}

// Finalize and Output
$mpdf->WriteHTML('</body></html>');
$mpdf->Output($pdfFileName, 'D');

$conn->close();
exit;