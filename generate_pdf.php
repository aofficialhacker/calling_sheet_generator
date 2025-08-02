<?php
// This script generates a PDF calling sheet based on a batch ID or a disposition status.

require 'vendor/autoload.php';

use Mpdf\Mpdf;

// --- Server-Side Download Token ---
// Sets a cookie that the front-end JavaScript can detect to know the download has started.
if (isset($_GET['download_token'])) {
    $token = preg_replace('/[^0-9]/', '', $_GET['download_token']);
    if (!empty($token)) {
        setcookie("download_token_" . $token, "true", time() + 30, "/");
    }
}

// --- Configuration and DB Connection ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// Allow script to run for a long time and use more memory for large PDFs
set_time_limit(0);
ini_set('memory_limit', '1024M');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// --- Determine Data Source and PDF Metadata ---
$dataSourceWhereClause = '';
$pdfFileName = 'Calling_Sheet_' . date('Y-m-d') . '.pdf';
$pdfTitle = 'General Calling Sheet';

if (isset($_GET['batch_id']) && is_numeric($_GET['batch_id'])) {
    $batch_id = (int)$_GET['batch_id'];
    $dataSourceWhereClause = "WHERE batch_id = {$batch_id}"; // Batch ID is numeric, direct interpolation is safe here.
    $pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Batch DB{$batch_id}";

} elseif (isset($_GET['disposition']) && !empty($_GET['disposition'])) {
    $disposition = $_GET['disposition'];
    // Use a prepared statement placeholder for security
    $dataSourceWhereClause = "WHERE disposition = ?";
    // Sanitize disposition for use in filename
    $safeDispositionName = preg_replace("/[^a-zA-Z0-9\s]/", "", $disposition);
    $pdfFileName = ucwords(str_replace(' ', '_', $safeDispositionName)) . '_Sheet.pdf';
    $pdfTitle = "Calling Sheet for Status: " . htmlspecialchars($disposition);

} else {
    die("Error: No valid batch ID or disposition provided.");
}

// --- Step 1: Dynamically Detect Which Columns Have Data ---
$mandatoryHeaders = ['mobile_no', 'slot', 'connectivity', 'disposition'];
$optionalColumns = ['name', 'title', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
$selects = [];
foreach ($optionalColumns as $column) {
    $selects[] = "MAX(CASE WHEN `{$column}` IS NOT NULL AND `{$column}` != '' THEN 1 ELSE 0 END) as has_{$column}";
}
$presenceCheckSql = "SELECT " . implode(', ', $selects) . " FROM final_call_logs {$dataSourceWhereClause}";

// Use prepared statement if filtering by disposition
if (isset($disposition)) {
    $presence_stmt = $conn->prepare($presenceCheckSql);
    $presence_stmt->bind_param("s", $disposition);
    $presence_stmt->execute();
    $presenceResult = $presence_stmt->get_result();
} else {
    $presenceResult = $conn->query($presenceCheckSql);
}
$columnPresence = $presenceResult->fetch_assoc();

// --- Step 2: Build the Final List of Headers for the PDF ---
$pdfHeaders = $mandatoryHeaders;
if ($columnPresence) {
    foreach ($optionalColumns as $column) {
        if ($columnPresence["has_{$column}"] == 1) {
            $pdfHeaders[] = $column;
        }
    }
}

// Check if any data exists at all before proceeding
$countCheckSql = "SELECT COUNT(*) as total FROM final_call_logs {$dataSourceWhereClause}";
if (isset($disposition)) {
    $count_stmt = $conn->prepare($countCheckSql);
    $count_stmt->bind_param("s", $disposition);
    $count_stmt->execute();
    $countResult = $count_stmt->get_result()->fetch_assoc();
} else {
    $countResult = $conn->query($countCheckSql)->fetch_assoc();
}
if ($countResult['total'] == 0) {
    die("No data found for the selected criteria. PDF cannot be generated.");
}

// --- Step 3: Configure mPDF and Generate Static HTML ---
$colCount = count($pdfHeaders);
$mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => __DIR__ . '/tmp']);
$mpdf->SetDisplayMode('fullpage');
$mpdf->shrink_tables_to_fit = 1;
$mpdf->SetTitle($pdfTitle);

// Define Legends and CSS Styles
$slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";
$dispLegend = "<strong>DISPO CODES (Y):</strong> 11:Interested | 12:Not Interested | 13:Call Back | 14:Follow Up | 15:More Info | 16:Language Barrier | 17:Drop || <strong>(N):</strong> 21:Ringing | 22:Switch Off | 23:Invalid Number | 24:Out of Service | 25:Wrong Number | 26:Busy";
$html_head = '<html><head><style>body { font-family: sans-serif; font-size: 7.5pt; } table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; } thead { display: table-header-group; } tr { page-break-inside: avoid; page-break-after: auto; } th, td { border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; } thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; } .anchor-col { font-weight: bold; color: #333; font-family: monospace; } .connectivity-col, .slot-cell { text-align: center; } .disposition-cell { font-size: 7pt; padding: 1px !important; } .dispo-grid { border: none !important; width: 100%; table-layout: fixed; } .dispo-grid td { border: none !important; padding: 1px 2px; text-align: left; }</style></head><body>';
$tableHeader = '<thead><tr><th class="legend-cell" colspan="' . $colCount . '">' . $slotLegend . '</th></tr><tr><th class="legend-cell" colspan="' . $colCount . '">' . $dispLegend . '</th></tr><tr>';
foreach ($pdfHeaders as $header) {
    $tableHeader .= '<th>' . htmlspecialchars(str_replace('_', ' ', ucwords($header))) . '</th>';
}
$tableHeader .= '</tr></thead>';
$mpdf->WriteHTML($html_head);

// --- Step 4: Process Data in Chunks and Write to PDF ---
$chunkSize = 500;
$offset = 0;
$columnsToSelect = '`' . implode('`, `', $pdfHeaders) . '`';

while (true) {
    $sql = "SELECT {$columnsToSelect} FROM final_call_logs {$dataSourceWhereClause} ORDER BY id LIMIT ?, ?";
    $stmt = $conn->prepare($sql);

    // Bind parameters based on whether we are filtering by disposition
    if (isset($disposition)) {
        $stmt->bind_param("sii", $disposition, $offset, $chunkSize);
    } else {
        $stmt->bind_param("ii", $offset, $chunkSize);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        break; // Exit loop when no more rows are found
    }

    $chunkHtml = '<table class="data-table">' . $tableHeader . '<tbody>';
    while ($row = $result->fetch_assoc()) {
        $chunkHtml .= '<tr>';
        foreach ($pdfHeaders as $header) {
            // Handle special, static columns
            if ($header === 'disposition') {
                // Use non-breaking spaces (&nbsp;) to prevent line wraps between the circle and numbers
                $chunkHtml .= '<td class="disposition-cell"><table class="dispo-grid"><tr><td>○&nbsp;11</td><td>○&nbsp;12</td><td>○&nbsp;13</td><td>○&nbsp;14</td><td>○&nbsp;15</td><td>○&nbsp;16</td><td>○&nbsp;17</td></tr><tr><td>○&nbsp;21</td><td>○&nbsp;22</td><td>○&nbsp;23</td><td>○&nbsp;24</td><td>○&nbsp;25</td><td>○&nbsp;26</td><td></td></tr></table></td>';
            } elseif ($header === 'connectivity') {
                 // Use non-breaking spaces (&nbsp;) to prevent line wraps
                $chunkHtml .= '<td class="connectivity-col">○&nbsp;Y&nbsp;/&nbsp;○&nbsp;N</td>';
            } elseif ($header === 'slot') {
                $chunkHtml .= '<td class="slot-cell"></td>';
            } else {
                // Handle regular data columns
                $cell = $row[$header] ?? '';
                $class = ($header === 'mobile_no') ? 'anchor-col' : '';
                $chunkHtml .= '<td class="' . $class . '">' . htmlspecialchars($cell) . '</td>';
            }
        }
        $chunkHtml .= '</tr>';
    }
    $chunkHtml .= '</tbody></table>';

    $mpdf->WriteHTML($chunkHtml);
    $stmt->close();
    $offset += $chunkSize;
}

// --- Step 5: Finalize and Output the PDF ---
$mpdf->WriteHTML('</body></html>');
$mpdf->Output($pdfFileName, 'D'); // 'D' forces a direct file download

$conn->close();
exit;