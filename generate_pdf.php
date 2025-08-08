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

// Define columns in the order they should appear (excluding status)
// Fixed order: id, slot, connectivity, disposition, then dynamic columns
// Mobile number now comes before name as per new requirement
$optionalColumns = ['mobile_no', 'name', 'title', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
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

// Build final headers: id, slot, connectivity, disposition first, then dynamic columns
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition'];

// Add optional columns that have data, in the correct order
// Ensure the table does not exceed 12 columns in total
foreach ($optionalColumns as $column) {
    if (!empty($columnPresence["has_{$column}"])) {
        $finalHeaders[] = $column;
        if (count($finalHeaders) >= 12) break;
    }
}

// Configure mPDF
$colCount = count($finalHeaders);
$mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => __DIR__ . '/tmp']);
$mpdf->SetDisplayMode('fullpage');
$mpdf->SetTitle($pdfTitle);

// Fetch dynamic legends
$dispResult = $conn->query("SELECT code, description, category FROM disposition_codes WHERE is_active = 1 ORDER BY category, code");
$dispLegendY = "<strong>DISPO (Y):</strong>";
$dispLegendN = "<strong>DISPO (N):</strong>";
$dispCodes = [];
while($d = $dispResult->fetch_assoc()){
    if($d['category'] == 'connected') $dispLegendY .= " {$d['code']}:{$d['description']} |";
    else $dispLegendN .= " {$d['code']}:{$d['description']} |";
    $dispCodes[] = $d['code'];
}
$dispLegend = rtrim($dispLegendY, ' |') . ' || ' . rtrim($dispLegendN, ' |');
$slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// CSS and HTML Head
$html_head = '<html><head><style>
    body { font-family: sans-serif; font-size: 7.5pt; }
    table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; page-break-after: auto; }
    th, td { border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; }
    thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; }
    td:nth-child(5) { position: relative; font-weight: bold; font-family: monospace;} /* Mobile column is 5th */
    .id-col { font-size: 6.5pt; font-family: monospace; }
    .scissor-line { position: absolute; top: 0; bottom: 0; left: 50%; border-left: 1px dashed #000; width: 1px; height: 100%; }
    .scissor-icon { position: absolute; top: -8px; left: 45%; font-size: 10pt; }
    .connectivity-col, .slot-cell { text-align: center; }
    .disposition-cell { font-size: 7pt; padding: 1px !important; }
    .dispo-grid { border: none !important; width: 100%; table-layout: fixed; }
    .dispo-grid td { border: none !important; padding: 1px 2px; text-align: left; white-space: nowrap; }
</style></head><body>';

// Create Table Header Row
$tableHeaderHtml = '<thead>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $pdfTitle . '</th></tr>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $slotLegend . '</th></tr>
    <tr><th class="legend-cell" colspan="' . $colCount . '">' . $dispLegend . '</th></tr>
    <tr>';
$colIndex = 1;
foreach ($finalHeaders as $header) {
    $icon = '';
    $headerClass = '';
    if ($header === 'mobile_no') {
        $icon = '<span class="scissor-icon">✂</span>';
    }
    if ($header === 'id') {
        $headerClass = 'id-col';
    }
    $tableHeaderHtml .= '<th class="' . $headerClass . '">' . $icon . htmlspecialchars(str_replace('_', ' ', ucwords($header))) . '</th>';
    $colIndex++;
}
$tableHeaderHtml .= '</tr></thead>';

// Write initial HTML structure to mPDF
$mpdf->WriteHTML($html_head);
$mpdf->SetHTMLFooter('<div style="text-align: right; font-size: 8pt;">Page {PAGENO} of {nbpg}</div>');

// Process Data in Chunks
$chunkSize = 250;
$offset = 0;
$columnsToSelect = '`' . implode('`, `', $finalHeaders) . '`';

// Function to format date
function formatDateForPDF($dateValue) {
    if (empty($dateValue) || $dateValue === '0000-00-00') return '';
    
    // Try to parse the date
    $timestamp = strtotime($dateValue);
    if ($timestamp !== false) {
        return date('d-m-Y', $timestamp);
    }
    
    // If it's already in dd-mm-yyyy format, return as is
    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateValue)) {
        return $dateValue;
    }
    
    // Try to handle yyyy-mm-dd format
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateValue, $matches)) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }
    
    return $dateValue;
}

while (true) {
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

    $chunkHtml = '<table class="data-table">' . $tableHeaderHtml . '<tbody>';
    while ($row = $result->fetch_assoc()) {
        $chunkHtml .= '<tr>';
        foreach ($finalHeaders as $header) {
            $class = '';
            $cellContent = '';
            switch($header) {
                case 'disposition':
                    $cellContent = '<table class="dispo-grid"><tr>';
                    $codeCounter = 0;
                    foreach ($dispCodes as $code) {
                        if ($codeCounter > 0 && $codeCounter % 7 == 0) {
                            $cellContent .= '</tr><tr>';
                        }
                        $cellContent .= '<td>○ ' . htmlspecialchars($code) . '</td>';
                        $codeCounter++;
                    }
                    $cellContent .= '</tr></table>';
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
                    $cellContent = htmlspecialchars($row[$header] ?? '');
                    // Add scissor line div inside mobile column
                    $cellContent .= '<div class="scissor-line"></div>';
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
            $chunkHtml .= '<td class="' . $class . '">' . $cellContent . '</td>';
        }
        $chunkHtml .= '</tr>';
    }
    $chunkHtml .= '</tbody></table>';

    $mpdf->WriteHTML($chunkHtml);
    $stmt->close();
    $offset += $chunkSize;
}

// Finalize and Output
$mpdf->WriteHTML('</body></html>');
$mpdf->Output($pdfFileName, 'D');

$conn->close();
exit;