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

// Debug logging function
function debugLog($message) {
    $logFile = __DIR__ . '/pdf_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

debugLog("Correct PDF generation started");

// Get parameters
$batch_id = $_GET['batch_id'] ?? null;
if (!$batch_id) {
    die("Error: No batch ID provided.");
}

$adminId = $_SESSION['admin_id'];
$pdfFileName = 'Batch_' . $batch_id . '_Sheet.pdf';
$pdfTitle = "Calling Sheet for Batch " . htmlspecialchars($batch_id);

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
    $dispLegend .= "DISPO (Y): " . implode(' | ', $dispLegendY);
}
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= ' || ';
    $dispLegend .= "DISPO (N): " . implode(' | ', $dispLegendN);
}

$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// Get data count
$countSql = "SELECT COUNT(*) as total FROM final_call_logs fcl 
             JOIN file_batches fb ON fcl.batch_id = fb.id 
             WHERE fb.admin_id = ? AND fcl.batch_id = ?";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param("ss", $adminId, $batch_id);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

if ($totalRecords == 0) {
    die("No records found for this batch.");
}

// Define the exact column structure
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no', 'name', 'dob', 'age', 'address', 'city', 'state'];

// Create custom TCPDF class
class CorrectCallSheetPDF extends TCPDF {
    private $pdfTitle;
    private $slotLegend;
    private $dispLegend;
    
    public function __construct($title, $slotLegend, $dispLegend) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle = $title;
        $this->slotLegend = $slotLegend;
        $this->dispLegend = $dispLegend;
    }
    
    public function Header() {
        // Title
        $this->SetY(8);
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 10, $this->pdfTitle, 0, 1, 'C');
        
        // Slot legend
        $this->SetFont('helvetica', '', 9);
        $this->Cell(0, 6, $this->slotLegend, 0, 1, 'C');
        
        // Disposition legend (split into multiple lines if too long)
        if (!empty($this->dispLegend)) {
            $this->SetFont('helvetica', '', 8);
            // Split long legend into multiple lines
            $maxWidth = 270; // mm
            $lines = $this->splitTextToWidth($this->dispLegend, $maxWidth);
            foreach ($lines as $line) {
                $this->Cell(0, 5, $line, 0, 1, 'C');
            }
        }
        
        $this->Ln(3);
        
        // Draw cutline after mobile column (position calculated based on column widths)
        $this->drawCutline();
    }
    
    private function splitTextToWidth($text, $maxWidth) {
        // Simple text splitting based on character count
        $maxChars = 120; // Approximate characters per line
        $lines = [];
        
        if (strlen($text) <= $maxChars) {
            return [$text];
        }
        
        // Split at logical points (| separators)
        $parts = explode(' || ', $text);
        foreach ($parts as $part) {
            if (strlen($part) <= $maxChars) {
                $lines[] = $part;
            } else {
                // Further split long parts
                $subParts = explode(' | ', $part);
                $currentLine = array_shift($subParts);
                foreach ($subParts as $subPart) {
                    if (strlen($currentLine . ' | ' . $subPart) <= $maxChars) {
                        $currentLine .= ' | ' . $subPart;
                    } else {
                        $lines[] = $currentLine;
                        $currentLine = $subPart;
                    }
                }
                if (!empty($currentLine)) {
                    $lines[] = $currentLine;
                }
            }
        }
        
        return $lines;
    }
    
    private function drawCutline() {
        // Calculate position after mobile column
        $cutlineX = 10 + 25 + 15 + 25 + 40 + 30; // Sum of widths up to and including mobile
        
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.5);
        
        // Draw dashed vertical line
        for ($y = 40; $y < $this->getPageHeight() - 20; $y += 5) {
            $this->Line($cutlineX, $y, $cutlineX, $y + 2.5);
        }
        
        // Add scissors at top and bottom
        $this->SetXY($cutlineX - 5, 35);
        $this->SetFont('helvetica', '', 14);
        $this->Cell(10, 8, 'X', 0, 0, 'C'); // Simple X for scissors
        
        $this->SetXY($cutlineX - 5, $this->getPageHeight() - 25);
        $this->Cell(10, 8, 'X', 0, 0, 'C');
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
    
    public function drawTableHeader($headers) {
        $this->SetFont('helvetica', 'B', 10);
        $this->SetFillColor(220, 220, 220);
        
        // Proper column widths for clean layout
        $widths = [25, 15, 25, 40, 30, 35, 25, 15, 35, 25, 25]; // Matches finalHeaders order
        
        foreach ($headers as $i => $header) {
            $displayHeader = str_replace('_', ' ', ucwords($header));
            if ($header === 'mobile_no') $displayHeader = 'Mobile';
            $this->Cell($widths[$i], 12, $displayHeader, 1, 0, 'C', true);
        }
        $this->Ln();
        
        return $widths;
    }
}

// Create PDF
$pdf = new CorrectCallSheetPDF($pdfTitle, $slotLegend, $dispLegend);

// Set document properties
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle($pdfTitle);
$pdf->SetMargins(10, 40, 10);
$pdf->SetAutoPageBreak(true, 20);

// Add first page
$pdf->AddPage();

// Draw table header
$columnWidths = $pdf->drawTableHeader($finalHeaders);

// Fetch and display data
$sql = "SELECT fcl.id, fcl.mobile_no, fcl.name, fcl.dob, fcl.age, fcl.address, fcl.city, fcl.state
        FROM final_call_logs fcl 
        JOIN file_batches fb ON fcl.batch_id = fb.id 
        WHERE fb.admin_id = ? AND fcl.batch_id = ? 
        ORDER BY fcl.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $adminId, $batch_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Check if we need a new page
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
        $columnWidths = $pdf->drawTableHeader($finalHeaders);
    }
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Prepare row data
    $rowData = [
        $row['id'],                          // Full ID
        '',                                  // Empty slot for manual entry
        'O Y / O N',                        // Connectivity checkboxes
        $this->createDispositionGrid($dispositionList), // Disposition grid
        $row['mobile_no'] ?? '',            // Mobile number
        $row['name'] ?? '',                 // Name
        $this->formatDate($row['dob']),     // DOB
        $row['age'] ?? '',                  // Age
        substr($row['address'] ?? '', 0, 30), // Address (truncated)
        $row['city'] ?? '',                 // City
        $row['state'] ?? ''                 // State
    ];
    
    foreach ($rowData as $i => $data) {
        // Make mobile number bold
        if ($i == 4) {
            $pdf->SetFont('helvetica', 'B', 9);
        } else {
            $pdf->SetFont('helvetica', '', 9);
        }
        
        $pdf->Cell($columnWidths[$i], 10, $data, 1, 0, 'L');
    }
    $pdf->Ln();
}

// Helper function to create disposition grid
function createDispositionGrid($dispositionList) {
    $codes = [];
    foreach ($dispositionList as $disp) {
        $codes[] = 'O ' . $disp['code'];
        if (count($codes) >= 8) break; // Limit to first 8 codes
    }
    return implode('  ', $codes);
}

// Helper function to format dates
function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    return date('d-m-Y', strtotime($date));
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