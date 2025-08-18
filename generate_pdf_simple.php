<?php
require_once 'db_config.php';
require 'vendor/autoload.php';

// Check admin authentication
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAdmin() && !isSuperadmin()) {
    header("Location: admin_login.php");
    exit();
}

use Fpdf\Fpdf;

set_time_limit(0);
ini_set('memory_limit', '1024M'); // Less memory needed

$conn = getDBConnection();

// Get batch ID
$batch_id = $_GET['batch_id'] ?? null;
if (!$batch_id) {
    die("Error: No batch ID provided.");
}

// Fetch data efficiently
$adminId = $_SESSION['admin_id'];
$sql = "SELECT fcl.id, fcl.mobile_no, fcl.name, fcl.disposition, fcl.policy_number, fcl.age, fcl.city 
        FROM final_call_logs fcl 
        JOIN file_batches fb ON fcl.batch_id = fb.id 
        WHERE fb.admin_id = ? AND fcl.batch_id = ? 
        ORDER BY fcl.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $adminId, $batch_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("No records found for this batch.");
}

// Create PDF class with custom methods
class PDF extends Fpdf
{
    private $batchId;
    
    function __construct($batchId) {
        parent::__construct('L', 'mm', 'A4'); // Landscape A4
        $this->batchId = $batchId;
    }
    
    // Header
    function Header()
    {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'Calling Sheet - Batch ' . $this->batchId, 0, 1, 'C');
        
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 6, 'SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)', 0, 1, 'C');
        $this->Ln(3);
        
        // Draw cutline (vertical dashed line after mobile column)
        $this->SetDrawColor(85, 85, 85);
        $cutlineX = 85; // Position after mobile column
        
        // Draw dashed line
        for ($y = 30; $y < 200; $y += 3) {
            $this->Line($cutlineX, $y, $cutlineX, $y + 1.5);
        }
        
        // Add scissors
        $this->SetXY($cutlineX - 3, 25);
        $this->SetFont('Arial', '', 12);
        $this->Cell(5, 5, chr(9986), 0, 0, 'C'); // Scissors symbol
        
        $this->SetXY($cutlineX - 3, 195);
        $this->Cell(5, 5, chr(9986), 0, 0, 'C');
    }
    
    // Footer
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'R');
    }
    
    // Table header
    function TableHeader()
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(240, 240, 240);
        
        $headers = ['ID', 'Slot', 'Connectivity', 'Disposition', 'Mobile', 'Name', 'Policy', 'Age', 'City'];
        $widths = [15, 12, 25, 30, 25, 35, 25, 12, 35];
        
        foreach ($headers as $i => $header) {
            $this->Cell($widths[$i], 8, $header, 1, 0, 'C', true);
        }
        $this->Ln();
    }
    
    // Table row
    function TableRow($data, $widths)
    {
        $this->SetFont('Arial', '', 7);
        
        foreach ($data as $i => $item) {
            // Make mobile number bold
            if ($i == 4) {
                $this->SetFont('Arial', 'B', 7);
            } else {
                $this->SetFont('Arial', '', 7);
            }
            
            $this->Cell($widths[$i], 6, $item, 1, 0, 'L');
        }
        $this->Ln();
    }
}

// Create PDF
$pdf = new PDF($batch_id);
$pdf->AddPage();
$pdf->TableHeader();

$widths = [15, 12, 25, 30, 25, 35, 25, 12, 35];

// Process data in chunks for better memory usage
$rowCount = 0;
while ($row = $result->fetch_assoc()) {
    // Check if we need a new page
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
        $pdf->TableHeader();
    }
    
    $rowData = [
        substr($row['id'], -6), // Last 6 chars of ID
        '', // Empty slot
        chr(9675) . ' Y / ' . chr(9675) . ' N', // Circle symbols for connectivity
        chr(9675) . ' ' . ($row['disposition'] ?? ''), // Circle + disposition
        $row['mobile_no'] ?? '',
        substr($row['name'] ?? '', 0, 20), // Truncate long names
        substr($row['policy_number'] ?? '', 0, 15), // Truncate policy
        $row['age'] ?? '',
        substr($row['city'] ?? '', 0, 20) // Truncate city
    ];
    
    $pdf->TableRow($rowData, $widths);
    $rowCount++;
}

// Clear output buffer and send PDF
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Calling_Sheet_Simple_' . $batch_id . '.pdf"');

$pdf->Output('D');

$conn->close();
exit;
?>