<?php
// Start the session to store processed data between requests.
session_start();

// Require the Composer autoloader
require 'vendor/autoload.php';

// Use the necessary classes
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Mpdf\Mpdf;

/**
 * Generates a PDF file from session data by streaming HTML for optimal performance.
 */
function downloadPdf() {
    if (!isset($_SESSION['processed_data']) || !isset($_SESSION['output_headers'])) {
        die("No data available to download. Please upload a file first.");
    }

    // Increase resource limits for handling large files
    ini_set("pcre.backtrack_limit", "5000000"); // Increase backtrack limit for complex regex
    ini_set("memory_limit", "1024M"); // Increase memory for large data arrays

    if (isset($_GET['download_token'])) {
        $token = $_GET['download_token'];
        setcookie($token, '1', ['expires' => time() + 120, 'path' => '/']);
    }

    $processedData = $_SESSION['processed_data'];
    $outputHeaders = $_SESSION['output_headers'];
    $colCount = count($outputHeaders);
    
    $slotLegend = "<strong>SLOTS:</strong> 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";
    $dispLegend = "<strong>DISPO CODES (Y):</strong> 11:Int | 12:Not Int | 13:CB | 14:FU | 15:Info | 16:Lang | 17:Drop || <strong>(N):</strong> 21:Ring | 22:Off | 23:Invalid | 24:OOS | 25:Wrong# | 26:Busy";

    // --- OPTIMIZED PDF GENERATION ---

    // 1. Initialize mPDF with performance-oriented settings
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L']);
    $mpdf->simpleTables = true; // Use a faster, simpler table rendering algorithm
    $mpdf->useSubstitutions = false; // Disable substitution, as we use htmlspecialchars
    $mpdf->SetDisplayMode('fullpage');

    // 2. Write the document head and opening table structure ONCE
    $html_head = '
    <html>
    <head>
        <style>
            body { font-family: sans-serif; font-size: 7.5pt; }
            table.data-table { width: 100%; border-collapse: collapse; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            th, td { border: 1px solid #333; padding: 3px; text-align: left; vertical-align: middle; word-wrap: break-word; }
            thead th, .legend-cell { text-align: center; font-weight: bold; background-color: #f2f2f2; }
            .disposition-cell { font-size: 7pt; text-align: center; }
            .connectivity-cell, .slot-cell { text-align: center; }
        </style>
    </head>
    <body>';
    
    $table_open_and_header = '<table class="data-table"><thead>';
    $table_open_and_header .= '<tr><th class="legend-cell" colspan="'.$colCount.'">' . $slotLegend . '</th></tr>';
    $table_open_and_header .= '<tr><th class="legend-cell" colspan="'.$colCount.'">' . $dispLegend . '</th></tr>';
    $table_open_and_header .= '<tr>';
    foreach($outputHeaders as $header) {
        $table_open_and_header .= '<th>' . htmlspecialchars(str_replace('_', ' ', ucwords($header))) . '</th>';
    }
    $table_open_and_header .= '</tr></thead><tbody>';

    $mpdf->WriteHTML($html_head . $table_open_and_header);

    // 3. Stream only the table rows (<tr>) in chunks
    $chunkSize = 250; // A safe number of rows to process per chunk
    $rowsHtml = '';
    $i = 0;

    foreach ($processedData as $dataRow) {
        $rowsHtml .= '<tr>';
        foreach ($outputHeaders as $header) {
             $cell = $dataRow[$header] ?? '';
             $class = '';
             if ($header === 'slot') $class = 'slot-cell';
             if ($header === 'connectivity') $class = 'connectivity-cell';
             if ($header === 'disposition') $class = 'disposition-cell';
             
             $cellContent = ($header === 'disposition') ? str_replace('|', '<br>', htmlspecialchars($cell)) : htmlspecialchars($cell);
             $rowsHtml .= '<td class="'.$class.'">' . $cellContent . '</td>';
        }
        $rowsHtml .= '</tr>';
        
        $i++;
        if ($i % $chunkSize == 0) {
            $mpdf->WriteHTML($rowsHtml); // Write the chunk of rows
            $rowsHtml = ''; // Reset the string
        }
    }

    // 4. Write any remaining rows and close the table and document
    if (!empty($rowsHtml)) {
        $mpdf->WriteHTML($rowsHtml); // Write the final chunk
    }

    $mpdf->WriteHTML('</tbody></table></body></html>');
    
    // 5. Output the PDF
    $outputFilename = 'Standard_Calling_Sheet_' . date('Y-m-d') . '.pdf';
    $mpdf->Output($outputFilename, 'D');
    exit;
}

// --- Main Logic Controller ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'download_pdf') {
        downloadPdf();
    }
}

// Handle File Upload and Processing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['customerFile'])) {
    
    ini_set("memory_limit", "1024M"); // Also increase memory for file processing
    unset($_SESSION['processed_data'], $_SESSION['output_headers']);

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $originalFile = $uploadDir . basename($_FILES['customerFile']['name']);
    $fileType = strtolower(pathinfo($originalFile, PATHINFO_EXTENSION));

    $allowed_types = ['xlsx', 'csv', 'pdf', 'png', 'jpg', 'jpeg'];
    if (!in_array($fileType, $allowed_types)) die("Error: Unsupported file type.");

    if (move_uploaded_file($_FILES['customerFile']['tmp_name'], $originalFile)) {
        $sourceFile = $originalFile;
        $is_ocr_request = isset($_POST['is_ocr']);
        
        if ($is_ocr_request) {
            $ocrCsvOutput = $uploadDir . 'ocr_output.csv';
            $command = "python ocr_processor.py " . escapeshellarg($originalFile) . " " . escapeshellarg($ocrCsvOutput);
            shell_exec($command);
            if (!file_exists($ocrCsvOutput)) die("Error: OCR failed. Check ocr_extraction_log.txt.");
            $sourceFile = $ocrCsvOutput;
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($sourceFile);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($sourceFile);
            $worksheet = $spreadsheet->getActiveSheet();
            $dataRows = $worksheet->toArray();
            
            $headerRow = array_shift($dataRows);
            $columnMap = mapColumns($headerRow);

            $foundStandardKeys = [];
            $extraHeadersInfo = [];
            $mappedIndexes = [];

            foreach ($columnMap as $key => $index) {
                if ($index !== -1) {
                    $foundStandardKeys[$key] = $key;
                    if (!in_array($index, $mappedIndexes)) {
                        $mappedIndexes[] = $index;
                    }
                }
            }

            foreach ($headerRow as $index => $header) {
                if (!in_array($index, $mappedIndexes)) {
                    $sanitizedHeader = strtolower(str_replace(' ', '_', preg_replace('/[^A-Za-z0-9 ]/', '', trim($header))));
                    if (!empty($sanitizedHeader)) {
                        $extraHeadersInfo[$index] = $sanitizedHeader;
                    }
                }
            }
            
            $outputHeaders = ['slot'];
            if(isset($foundStandardKeys['title'])) $outputHeaders[] = 'title';
            if(isset($foundStandardKeys['name'])) $outputHeaders[] = 'name';
            if(isset($foundStandardKeys['mobile_no'])) $outputHeaders[] = 'mobile_no';
            $outputHeaders[] = 'connectivity';
            $outputHeaders[] = 'disposition';
            
            $standardOrderAfterFixed = ['pan', 'dob', 'expiry', 'address', 'city', 'state', 'country', 'pincode', 'plan', 'premium', 'sum_insured'];
            foreach($standardOrderAfterFixed as $key) {
                if(isset($foundStandardKeys[$key])) {
                    $outputHeaders[] = $key;
                }
            }
            
            $outputHeaders = array_merge($outputHeaders, array_values($extraHeadersInfo));
            $_SESSION['output_headers'] = $outputHeaders;
            
            $processedData = [];

            foreach ($dataRows as $row) {
                if (empty(implode('', $row))) continue;

                $newRow = [];
                $newRow['slot'] = '';
                $newRow['connectivity'] = '○ Y  /  ○ N';
                $newRow['disposition'] = "○ 11 ○ 12 ○ 13 ○ 14 ○ 15 ○ 16 ○ 17|○ 21 ○ 22 ○ 23 ○ 24 ○ 25 ○ 26";

                foreach ($columnMap as $standardKey => $mappedIndex) {
                    if ($mappedIndex !== -1 && isset($row[$mappedIndex])) {
                        $cellValue = $row[$mappedIndex];
                        
                        if (is_string($cellValue)) {
                            $cellValue = preg_replace('/(\\\\r\\\\n|\\\\n|\\\\r|\r\n|\n|\r)/', ' ', $cellValue);
                        }

                        if (is_numeric($cellValue) && $cellValue > 1 && ($standardKey === 'dob' || $standardKey === 'expiry')) {
                            try { $cellValue = Date::excelToDateTimeObject($cellValue)->format('d-m-Y'); } catch (Exception $e) {}
                        }
                        $newRow[$standardKey] = $cellValue;
                    }
                }

                if (isset($columnMap['address2']) && $columnMap['address2'] !== -1 && !empty($row[$columnMap['address2']])) {
                    $address2 = $row[$columnMap['address2']];
                    if(is_string($address2)) {
                        $address2 = preg_replace('/(\\\\r\\\\n|\\\\n|\\\\r|\r\n|\n|\r)/', ' ', $address2);
                    }
                    $newRow['address'] = ($newRow['address'] ?? '') . ', ' . $address2;
                }
                if (isset($columnMap['address3']) && $columnMap['address3'] !== -1 && !empty($row[$columnMap['address3']])) {
                    $address3 = $row[$columnMap['address3']];
                    if(is_string($address3)) {
                        $address3 = preg_replace('/(\\\\r\\\\n|\\\\n|\\\\r|\r\n|\n|\r)/', ' ', $address3);
                    }
                    $newRow['address'] = ($newRow['address'] ?? '') . ', ' . $address3;
                }
                if (isset($newRow['address'])) {
                    $newRow['address'] = trim($newRow['address'], " ,");
                }
                
                foreach ($extraHeadersInfo as $originalIndex => $sanitizedHeader) {
                    $extraValue = $row[$originalIndex] ?? '';
                    if (is_string($extraValue)) {
                        $extraValue = preg_replace('/(\\\\r\\\\n|\\\\n|\\\\r|\r\n|\n|\r)/', ' ', $extraValue);
                    }
                    $newRow[$sanitizedHeader] = $extraValue;
                }
                
                $processedData[] = $newRow;
            }
            
            $_SESSION['processed_data'] = $processedData;
            if (file_exists($originalFile)) unlink($originalFile);
            if ($is_ocr_request && file_exists($sourceFile)) unlink($sourceFile);

        } catch (Exception $e) {
            die('Error loading file: ' . $e->getMessage());
        }
    } else {
        die("Error: There was a problem uploading your file.");
    }
}

/**
 * Maps various input header names to a standard set of keys.
 */
function mapColumns(array $headerRow): array
{
    $map = [
        'title' => -1, 'name' => -1, 'mobile_no' => -1, 'pan' => -1, 'dob' => -1, 'expiry' => -1,
        'address' => -1, 'address2' => -1, 'address3' => -1, 'city' => -1, 
        'state' => -1, 'country' => -1, 'pincode' => -1,
        'plan' => -1, 'premium' => -1, 'sum_insured' => -1
    ];

    foreach ($headerRow as $index => $header) {
        $normalizedHeader = strtolower(trim(str_replace('_', ' ', $header)));
        if (empty($normalizedHeader)) continue;

        switch (true) {
            case ($map['title'] === -1 && preg_match('/^title$/i', $normalizedHeader)):
                $map['title'] = $index; break;
            case ($map['name'] === -1 && preg_match('/name|insured/i', $normalizedHeader)):
                $map['name'] = $index; break;
            case ($map['mobile_no'] === -1 && preg_match('/mobile|phone|cell|ces clean mobile/i', $normalizedHeader)):
                $map['mobile_no'] = $index; break;
            case ($map['pan'] === -1 && preg_match('/pan/i', $normalizedHeader)):
                $map['pan'] = $index; break;
            case ($map['dob'] === -1 && preg_match('/dob|birth/i', $normalizedHeader)):
                $map['dob'] = $index; break;
            case ($map['expiry'] === -1 && preg_match('/expiry/i', $normalizedHeader)):
                $map['expiry'] = $index; break;
            case ($map['address'] === -1 && preg_match('/c add1|address|add\b/i', $normalizedHeader)):
                $map['address'] = $index; break;
            case ($map['address2'] === -1 && preg_match('/c add2/i', $normalizedHeader)):
                $map['address2'] = $index; break;
            case ($map['address3'] === -1 && preg_match('/c add3/i', $normalizedHeader)):
                $map['address3'] = $index; break;
            case ($map['city'] === -1 && preg_match('/city|c city/i', $normalizedHeader)):
                $map['city'] = $index; break;
            case ($map['state'] === -1 && preg_match('/state|c state/i', $normalizedHeader)):
                $map['state'] = $index; break;
            case ($map['country'] === -1 && preg_match('/country|c cntry/i', $normalizedHeader)):
                $map['country'] = $index; break;
            case ($map['pincode'] === -1 && preg_match('/pin|pincode|c pin/i', $normalizedHeader)):
                $map['pincode'] = $index; break;
            case ($map['plan'] === -1 && preg_match('/plan|plan name/i', $normalizedHeader)):
                $map['plan'] = $index; break;
            case ($map['premium'] === -1 && preg_match('/premium/i', $normalizedHeader)):
                $map['premium'] = $index; break;
            case ($map['sum_insured'] === -1 && preg_match('/suminsured|sum insured/i', $normalizedHeader)):
                $map['sum_insured'] = $index; break;
        }
    }
    return $map;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calling Sheet Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 800px; }
        .card { margin-top: 30px; border-radius: 15px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .card-header { background-color: #007bff; color: white; font-weight: bold; border-top-left-radius: 15px; border-top-right-radius: 15px; }
        .btn-primary { background-color: #007bff; border: none; }
        .btn-success { background-color: #198754; border: none; }
        .form-check-input:checked { background-color: #007bff; border-color: #007bff; }
        
        #loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.6); z-index: 9999;
            display: none; justify-content: center; align-items: center; flex-direction: column;
        }
        .spinner {
            width: 56px; height: 56px; border-radius: 50%;
            border: 9px solid #555; border-top: 9px solid #fff;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { color: white; margin-top: 20px; font-size: 1.2rem; font-weight: bold; }

    </style>
</head>
<body>

    <div id="loading-overlay">
        <div class="spinner"></div>
        <p class="loading-text">Processing your file...</p>
    </div>

    <div class="container">
        <div class="card no-print">
            <div class="card-header text-center">
                <h2>Standard Calling Sheet Generator</h2>
            </div>
            <div class="card-body">
                <p class="card-text">Upload an Excel/CSV for standard processing. For scanned documents (PDF/Image), check the OCR box.</p>
                
                <form id="upload-form" action="index.php" method="post" enctype="multipart/form-data" class="mt-4">
                    <div class="mb-3">
                        <label for="customerFile" class="form-label"><strong>Upload Data File:</strong></label>
                        <input class="form-control" type="file" id="customerFile" name="customerFile" accept=".xlsx, .csv, .pdf, .png, .jpg, .jpeg" required>
                    </div>
                    
                    <div class="form-check form-switch mb-3">
                      <input class="form-check-input" type="checkbox" role="switch" id="is_ocr" name="is_ocr">
                      <label class="form-check-label" for="is_ocr"><strong>Process as Scanned Document (OCR)</strong> <small class="text-muted">(for PDF/Image files)</small></label>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">Generate Calling Sheet</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($_SESSION['processed_data']) && isset($_SESSION['output_headers'])): ?>
            <div class="card mt-4 text-center">
                <div class="card-body p-5">
                    <h3 class="text-success">File Processed Successfully!</h3>
                    <p class="lead">Your calling sheet is ready for download.</p>
                    <div class="d-grid gap-2 col-8 mx-auto mt-4">
                        <a href="index.php?action=download_pdf" id="download-pdf-btn" class="btn btn-success btn-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-file-earmark-arrow-down-fill" viewBox="0 0 16 16" style="margin-bottom: 3px; margin-right: 5px;">
                                <path d="M9.293 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.707A1 1 0 0 0 13.707 4L10 .293A1 1 0 0 0 9.293 0M9.5 3.5v-2l3 3h-2a1 1 0 0 1-1-1m-1 4v3.793l1.146-1.147a.5.5 0 0 1 .708.708l-2 2a.5.5 0 0 1-.708 0l-2-2a.5.5 0 0 1 .708-.708L7.5 11.293V7.5a.5.5 0 0 1 1 0"/>
                            </svg>
                            Download PDF
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const form = document.getElementById('upload-form');
        const loadingOverlay = document.getElementById('loading-overlay');
        const downloadPdfBtn = document.getElementById('download-pdf-btn');

        if (form) {
            form.addEventListener('submit', function() {
                const fileInput = document.getElementById('customerFile');
                if (fileInput && fileInput.files.length > 0) {
                     loadingOverlay.style.display = 'flex';
                }
            });
        }
        
        if (downloadPdfBtn) {
            downloadPdfBtn.addEventListener('click', function(e) {
                e.preventDefault();
                loadingOverlay.style.display = 'flex';
                loadingOverlay.querySelector('.loading-text').textContent = 'Generating PDF... Please wait.';

                const token = "dl_" + Date.now();
                const downloadUrl = this.href + '&download_token=' + token;

                const checkCookie = () => {
                    // This cookie is set on the server when the download begins.
                    if (document.cookie.split(';').some((item) => item.trim().startsWith(token + '='))) {
                        // Hide the overlay once the download is initiated.
                        clearInterval(intervalId);
                        loadingOverlay.style.display = 'none';
                        loadingOverlay.querySelector('.loading-text').textContent = 'Processing your file...';
                        
                        // Clean up the cookie.
                        document.cookie = token + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
                    }
                };
                
                // Start polling for the cookie
                const intervalId = setInterval(checkCookie, 500);

                // Initiate the download
                window.location.href = downloadUrl;
            });
        }
    </script>

</body>
</html>