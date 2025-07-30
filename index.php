<?php
// Start the session to store processed data for download links.
session_start();
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Mpdf\Mpdf;

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// REMOVED: generate_short_id() function is no longer needed.

function formatDateString($value): string {
    if (empty($value)) return '';
    if (is_numeric($value) && $value > 25569) {
        try { return Date::excelToDateTimeObject($value)->format('d-m-Y'); } catch (Exception $e) { /* Fall through */ }
    }
    if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) return date('d-m-Y', $timestamp);
    }
    return (string)$value;
}

function downloadPdf() {
    if (!isset($_SESSION['processed_data']) || !isset($_SESSION['output_headers'])) {
        die("No data available to download. Please upload a file first.");
    }
    if (isset($_GET['download_token'])) {
        $token = $_GET['download_token'];
        setcookie($token, '1', ['expires' => time() + 60, 'path' => '/']);
    }

    $processedData = $_SESSION['processed_data'];
    $outputHeaders = $_SESSION['output_headers'];
    $colCount = count($outputHeaders);
    
    $slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";
    $dispLegend = "<strong>DISPO CODES (Y):</strong> 11:Interested | 12:Not Interested | 13:Call Back | 14:Follow Up | 15:More Info | 16:Language Barrier | 17:Drop || <strong>(N):</strong> 21:Ringing | 22:Switch Off | 23:Invalid Number | 24:Out of Service | 25:Wrong Number | 26:Busy";

    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => __DIR__ . '/tmp']);
    $mpdf->SetDisplayMode('fullpage');
    $mpdf->shrink_tables_to_fit = 1;

    $html_head = '
    <html><head><style>
        body { font-family: sans-serif; font-size: 7.5pt; }
        table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; }
        thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; }
        .anchor-col { font-weight: bold; color: #333; font-family: monospace; }
        .connectivity-col, .slot-cell { text-align: center; }
        .disposition-cell { font-size: 7pt; padding: 1px !important; }
        .dispo-grid { border: none !important; width: 100%; table-layout: fixed; }
        .dispo-grid td { border: none !important; padding: 1px 2px; text-align: left; }
    </style></head><body>';
    $mpdf->WriteHTML($html_head);

    $tableHeader = '<thead>';
    $tableHeader .= '<tr><th class="legend-cell" colspan="'.$colCount.'">' . $slotLegend . '</th></tr>';
    $tableHeader .= '<tr><th class="legend-cell" colspan="'.$colCount.'">' . $dispLegend . '</th></tr>';
    $tableHeader .= '<tr>';
    foreach($outputHeaders as $header) {
        $tableHeader .= '<th>' . htmlspecialchars(str_replace('_', ' ', ucwords($header))) . '</th>';
    }
    $tableHeader .= '</tr></thead>';

    $dataChunks = array_chunk($processedData, 100);
    foreach ($dataChunks as $chunk) {
        $chunkHtml = '<table class="data-table">' . $tableHeader . '<tbody>';
        foreach ($chunk as $dataRow) {
            $chunkHtml .= '<tr>';
            foreach ($outputHeaders as $header) {
                if ($header === 'disposition') {
                    $chunkHtml .= '<td class="disposition-cell"><table class="dispo-grid"><tr><td>○ 11</td><td>○ 12</td><td>○ 13</td><td>○ 14</td><td>○ 15</td><td>○ 16</td><td>○ 17</td></tr><tr><td>○ 21</td><td>○ 22</td><td>○ 23</td><td>○ 24</td><td>○ 25</td><td>○ 26</td><td></td></tr></table></td>';
                } else {
                    $cell = $dataRow[$header] ?? '';
                    $class = '';
                    if ($header === 'mobile_no') $class = 'anchor-col';
                    if ($header === 'connectivity') $class = 'connectivity-col';
                    if ($header === 'slot') $class = 'slot-cell';
                    $chunkHtml .= '<td class="'.$class.'">' . htmlspecialchars($cell) . '</td>';
                }
            }
            $chunkHtml .= '</tr>';
        }
        $chunkHtml .= '</tbody></table>';
        $mpdf->WriteHTML($chunkHtml);
    }
    
    $mpdf->WriteHTML('</body></html>');
    $mpdf->Output('Standard_Calling_Sheet_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}

function mapColumns(array $headerRow): array {
    $map = ['title' => -1, 'name' => -1, 'age' => -1, 'mobile_no' => -1, 'policy_number' => -1, 'pan' => -1, 'dob' => -1, 'expiry' => -1, 'address' => -1, 'address2' => -1, 'address3' => -1, 'city' => -1, 'state' => -1, 'country' => -1, 'pincode' => -1, 'plan' => -1, 'premium' => -1, 'sum_insured' => -1 ];
    foreach ($headerRow as $index => $header) {
        if(is_null($header)) continue;
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

if (isset($_GET['action'])) {
    if ($_GET['action'] == 'download_pdf') downloadPdf();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['customerFile'])) {
    unset($_SESSION['processed_data'], $_SESSION['output_headers']);
    $uploadDir = 'uploads/'; $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true); if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

    $originalFileName = basename($_FILES['customerFile']['name']);
    $originalFile = $uploadDir . $originalFileName;
    if (move_uploaded_file($_FILES['customerFile']['tmp_name'], $originalFile)) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($originalFile);
            $spreadsheet = $reader->load($originalFile);
            $dataRows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            $headerRow = array_shift($dataRows);
            $columnMap = mapColumns($headerRow);
            
            $foundStandardKeys = []; $extraHeadersInfo = []; $mappedIndexes = [];
            foreach ($columnMap as $key => $index) {
                if ($index !== -1) { $foundStandardKeys[$key] = $key; if (!in_array($index, $mappedIndexes)) $mappedIndexes[] = $index; }
            }
            foreach ($headerRow as $index => $header) {
                if ($header !== null && !in_array($index, $mappedIndexes)) {
                    $sanitizedHeader = strtolower(str_replace(' ', '_', preg_replace('/[^A-Za-z0-9 ]/', '', trim($header))));
                    if (!empty($sanitizedHeader)) { $extraHeadersInfo[$index] = $sanitizedHeader; }
                }
            }

            $outputHeaders = [];
            $fixedOrder = [ 'mobile_no', 'slot', 'connectivity', 'disposition', 'name', 'title', 'policy_number', 'pan', 'dob', 'age', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured' ];
            
            foreach($fixedOrder as $key) {
                if (in_array($key, ['slot', 'connectivity', 'disposition']) || isset($foundStandardKeys[$key])) {
                    $outputHeaders[] = $key;
                }
            }
            $outputHeaders = array_merge($outputHeaders, array_values($extraHeadersInfo));

            $processedData = [];
            foreach ($dataRows as $row) {
                if (empty(implode('', $row))) continue;
                
                $newRow = [ 'connectivity' => '○ Y / ○ N', 'disposition' => "Ignored", 'slot' => '' ];

                foreach ($columnMap as $standardKey => $mappedIndex) {
                    if ($mappedIndex !== -1 && isset($row[$mappedIndex])) {
                        $cellValue = $row[$mappedIndex];
                        $numericKeys = ['premium', 'sum_insured', 'mobile_no', 'pincode', 'age'];
                        if ($standardKey === 'dob' || $standardKey === 'expiry') {
                            $newRow[$standardKey] = formatDateString($cellValue);
                        } elseif (in_array($standardKey, $numericKeys, true)) {
                            $newRow[$standardKey] = preg_replace('/\D/', '', (string)$cellValue);
                        } else {
                            $newRow[$standardKey] = (string) $cellValue;
                        }
                    }
                }
                
                if (empty($newRow['mobile_no'])) {
                    continue;
                }

                foreach (['address2', 'address3'] as $addrKey) {
                    if (isset($columnMap[$addrKey]) && $columnMap[$addrKey] !== -1 && !empty($row[$columnMap[$addrKey]])) {
                        $newRow['address'] = ($newRow['address'] ?? '') . ', ' . $row[$columnMap[$addrKey]];
                    }
                }
                if (isset($newRow['address'])) { $newRow['address'] = trim($newRow['address'], " ,"); }
                foreach ($extraHeadersInfo as $originalIndex => $sanitizedHeader) {
                    $newRow[$sanitizedHeader] = $row[$originalIndex] ?? '';
                }
                $processedData[] = $newRow;
            }
            
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) { die("Database Connection Failed: " . $conn->connect_error); }
            
            $sql = "INSERT INTO temp_processed_data (mobile_no, source_filename, title, name, policy_number, pan, dob, age, expiry, address, city, state, country, pincode, plan, premium, sum_insured, extra_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), policy_number=VALUES(policy_number), pan=VALUES(pan)";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) { die("Failed to prepare statement: " . $conn->error); }
            
            foreach($processedData as $dataRow) {
                $mobile_no = $dataRow['mobile_no'];
                $title = $dataRow['title'] ?? null; $name = $dataRow['name'] ?? null;
                $policy_number = $dataRow['policy_number'] ?? null; $pan = $dataRow['pan'] ?? null;
                $dob = $dataRow['dob'] ?? null; $age = $dataRow['age'] ?? null; $expiry = $dataRow['expiry'] ?? null;
                $address = $dataRow['address'] ?? null;
                // --- THIS IS THE CORRECTION ---
                $city = $dataRow['city'] ?? null; // CORRECTED: Was $data_row, now $dataRow
                // ------------------------------
                $state = $dataRow['state'] ?? null;
                $country = $dataRow['country'] ?? null; $pincode = $dataRow['pincode'] ?? null;
                $plan = $dataRow['plan'] ?? null; $premium = $dataRow['premium'] ?? null; $sum_insured = $dataRow['sum_insured'] ?? null;
                $extraData = []; $jsonExtraData = !empty($extraData) ? json_encode($extraData) : null;
                
                $stmt->bind_param("ssssssisssssssssss", $mobile_no, $originalFileName, $title, $name, $policy_number, $pan, $dob, $age, $expiry, $address, $city, $state, $country, $pincode, $plan, $premium, $sum_insured, $jsonExtraData);
                $stmt->execute();
            }
            $stmt->close(); $conn->close();

            $_SESSION['processed_data'] = $processedData; $_SESSION['output_headers'] = $outputHeaders;
            if (file_exists($originalFile)) unlink($originalFile);

        } catch (Exception $e) { die('Error loading file: ' . $e->getMessage()); }
    } else { die("Error: There was a problem uploading your file."); }
}
?>
<!-- The HTML part is unchanged -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Calling Sheet Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); z-index: 1050; display: none; justify-content: center; align-items: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; animation: spin 1.5s linear infinite; }
        .loading-text { color: white; margin-top: 20px; font-size: 1.2rem; font-weight: bold; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="loading-text" id="loading-message">Processing, please wait...</p></div>
    <div class="container mt-4">
        <h1 class="mb-4 text-center">Calling Sheet Dashboard</h1>
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-header bg-primary text-white"><h3 class="h5 mb-0">1. Generate Calling Sheets</h3></div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text">Upload a source file. This saves data to a temporary log and creates a printable PDF with the customer's <strong>mobile number</strong> as the unique identifier.</p>
                        <form action="index.php" method="post" enctype="multipart/form-data" class="mt-auto" id="upload-form">
                            <div class="mb-3">
                                <label for="customerFile" class="form-label"><strong>Select Source File:</strong></label>
                                <input class="form-control" type="file" id="customerFile" name="customerFile" accept=".xlsx, .csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Generate & Stage Data</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100 shadow-sm">
                     <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h3 class="h5 mb-0">2. Interpret Marked Sheets</h3>
                        <a href="view_final_logs.php" class="btn btn-sm btn-light">View Final Logs</a>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center text-center">
                        <p class="card-text">Capture a photo of the marked sheet. The AI will read the <strong>mobile numbers</strong> and marked circles, then move the data to the permanent log.</p>
                        <div class="mt-3"><a href="process_with_gemini_python.php" class="btn btn-info text-white w-75">Go to AI Interpreter</a></div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (isset($_SESSION['processed_data'])): ?>
            <div class="card mt-4 text-center shadow-sm"><div class="card-body p-4">
                    <h4 class="text-success">File Processed & Staged!</h4>
                    <p>Your PDF calling sheet is ready for download.</p>
                    <div class="d-grid gap-2 col-md-6 mx-auto mt-3">
                        <a href="index.php?action=download_pdf" class="btn btn-danger" id="download-btn">Download PDF Calling Sheet</a>
                    </div>
            </div></div>
        <?php endif; ?>
    </div>
    <script>
        const uploadForm = document.getElementById('upload-form'); const loadingOverlay = document.getElementById('loading-overlay'); const loadingMessage = document.getElementById('loading-message');
        if (uploadForm) { uploadForm.addEventListener('submit', function(event) { const fileInput = document.getElementById('customerFile'); if (fileInput && fileInput.files.length > 0) { loadingMessage.textContent = 'Processing, please wait...'; loadingOverlay.style.display = 'flex'; } }); }
        const downloadBtn = document.getElementById('download-btn');
        if (downloadBtn) { downloadBtn.addEventListener('click', function(e) { e.preventDefault(); loadingMessage.textContent = 'Generating PDF...'; loadingOverlay.style.display = 'flex'; const token = "dl_" + Date.now(); const downloadUrl = this.href + '&download_token=' + token;
            const intervalId = setInterval(function() { if (document.cookie.indexOf(token + "=1") !== -1) { clearInterval(intervalId); loadingOverlay.style.display = 'none'; document.cookie = token + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/"; } }, 500);
            window.location.href = downloadUrl; });
        }
    </script>
</body>
</html>