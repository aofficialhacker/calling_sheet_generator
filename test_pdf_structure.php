<?php
// Test script for PDF structure verification - bypasses authentication
require 'vendor/autoload.php';

// Mock data for testing 5-column structure
$mockData = [
    [
        'id' => 'HIV02B00100001',
        'slot' => '',
        'connectivity' => '',
        'disposition' => '',
        'mobile_no' => '9860470014'
    ],
    [
        'id' => 'HIV02B00100002', 
        'slot' => '',
        'connectivity' => '',
        'disposition' => '',
        'mobile_no' => '9766343453'
    ],
    [
        'id' => 'HIV02B00100003',
        'slot' => '',
        'connectivity' => '',
        'disposition' => '',
        'mobile_no' => '9561111037'
    ]
];

// Mock dispositions
$dispositionList = [
    ['code' => '11', 'description' => 'Interested', 'category' => 'connected'],
    ['code' => '12', 'description' => 'Not Interested', 'category' => 'connected'],
    ['code' => '13', 'description' => 'Call Back', 'category' => 'connected'],
    ['code' => '14', 'description' => 'Follow Up', 'category' => 'connected'],
    ['code' => '21', 'description' => 'Ringing', 'category' => 'not_connected'],
    ['code' => '22', 'description' => 'Switch Off', 'category' => 'not_connected'],
    ['code' => '23', 'description' => 'Invalid Number', 'category' => 'not_connected'],
    ['code' => '24', 'description' => 'Out of Service', 'category' => 'not_connected']
];

// Build legends
$dispLegendY = [];
$dispLegendN = [];
foreach($dispositionList as $d){
    if($d['category'] == 'connected') {
        $dispLegendY[] = "{$d['code']}:{$d['description']}";
    } else {
        $dispLegendN[] = "{$d['code']}:{$d['description']}";
    }
}

$dispLegend = '';
if (!empty($dispLegendY)) $dispLegend .= "DISPO (Y): " . implode(' | ', $dispLegendY);
if (!empty($dispLegendN)) {
    if (!empty($dispLegend)) $dispLegend .= "\nDISPO (N): " . implode(' | ', $dispLegendN);
}
$slotLegend = "SLOTS: 1 (10-11a) | 2 (11a-12p) | 3 (12-1p) | 4 (1-2p) | 5 (2-3p) | 6 (3-4p) | 7 (4-5p) | 8 (5-6p)";

// Custom PDF Class with cutlines and structure
class TestPDF extends TCPDF {
    private $pdfTitle;
    private $slotLegend;
    private $dispLegend;
    private $cutlineBefore;
    private $cutlineAfter;
    
    public function __construct($title, $slotLegend, $dispLegend) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdfTitle = $title;
        $this->slotLegend = $slotLegend;
        $this->dispLegend = $dispLegend;
    }
    
    public function setCutlines($before, $after) {
        $this->cutlineBefore = $before;
        $this->cutlineAfter = $after;
    }
    
    public function Header() {
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(0, 6, $this->pdfTitle, 0, 1, 'C');
        
        $this->SetFont('helvetica', '', 7);
        $this->Cell(0, 4, $this->slotLegend, 0, 1, 'C');
        
        $dispLines = explode("\n", $this->dispLegend);
        foreach ($dispLines as $line) {
            if (!empty(trim($line))) {
                $this->Cell(0, 3, $line, 0, 1, 'C');
            }
        }
        $this->Ln(2);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
    
    public function drawPageCutlines() {
        if (!$this->cutlineBefore || !$this->cutlineAfter) return;

        $this->SetDrawColor(100, 100, 100);
        $this->SetLineWidth(0.5);
        
        $pageHeight = $this->getPageHeight();
        $topMargin = 45;
        $bottomMargin = 20;
        
        $mobileCenter = ($this->cutlineBefore + $this->cutlineAfter) / 2;
        
        // Top cutline with scissor
        $topMarkY = $topMargin + 5;
        $this->drawDottedLine($this->cutlineBefore, $topMarkY, $this->cutlineAfter, $topMarkY);
        
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY($mobileCenter - 4, $topMarkY - 6);
        $this->Cell(8, 6, '✂', 0, 0, 'C');
        
        // Bottom cutline with scissor
        $bottomMarkY = $pageHeight - $bottomMargin - 5;
        $this->drawDottedLine($this->cutlineBefore, $bottomMarkY, $this->cutlineAfter, $bottomMarkY);
        
        $this->SetXY($mobileCenter - 4, $bottomMarkY + 1);
        $this->Cell(8, 6, '✂', 0, 0, 'C');
    }
    
    private function drawDottedLine($x1, $y1, $x2, $y2) {
        $dashLength = 2;
        $gapLength = 1;
        $totalLength = $x2 - $x1;
        $dashCount = floor($totalLength / ($dashLength + $gapLength));
        
        for ($i = 0; $i < $dashCount; $i++) {
            $startX = $x1 + ($i * ($dashLength + $gapLength));
            $endX = $startX + $dashLength;
            $this->Line($startX, $y1, $endX, $y2);
        }
    }
}

// Fixed 5-column structure
$finalHeaders = ['id', 'slot', 'connectivity', 'disposition', 'mobile_no'];

// Optimized widths for 5-column layout
$widthDefinitions = [
    'id' => 50,
    'slot' => 20,
    'connectivity' => 30,
    'disposition' => 120,
    'mobile_no' => 35
];

$columnData = [];
foreach ($finalHeaders as $header) {
    $width = $widthDefinitions[$header];
    $displayName = ucwords(str_replace('_', ' ', $header));
    if ($header === 'id') $displayName = 'ID';
    if ($header === 'mobile_no') $displayName = 'Mobile';
    $columnData[] = ['header' => $displayName, 'width' => $width, 'key' => $header];
}

// Create PDF
$pdf = new TestPDF('Calling Sheet for Batch TEST - Production Structure', $slotLegend, $dispLegend);
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle('Test Production Structure');

// Calculate margins and cutlines
$totalWidth = array_sum(array_column($columnData, 'width'));
$pageWidth = 297; // A4 landscape
$leftMargin = ($pageWidth - $totalWidth) / 2;
$pdf->SetMargins($leftMargin, 35, $leftMargin);
$pdf->SetAutoPageBreak(true, 15);

// Set cutlines for mobile column
$mobileIndex = array_search('mobile_no', array_column($columnData, 'key'));
if ($mobileIndex !== false) {
    $cutlineBefore = $leftMargin;
    for ($i = 0; $i < $mobileIndex; $i++) {
        $cutlineBefore += $columnData[$i]['width'];
    }
    $cutlineAfter = $cutlineBefore + $columnData[$mobileIndex]['width'];
    $pdf->setCutlines($cutlineBefore, $cutlineAfter);
}

$pdf->AddPage();

// Function to draw headers
function redrawHeaders($pdf, $columnData) {
    $pdf->drawPageCutlines();
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    foreach ($columnData as $col) {
        $pdf->Cell($col['width'], 7, $col['header'], 1, 0, 'C', true);
    }
    $pdf->Ln();
}

// Create disposition grid - more compact layout
$dispGrid = '';
$gridRows = array_chunk($dispositionList, 4); // 4 per row
foreach ($gridRows as $row) {
    $rowItems = [];
    foreach ($row as $disp) {
        $paddedCode = str_pad($disp['code'], 2, '0', STR_PAD_LEFT);
        $rowItems[] = '○' . $paddedCode;
    }
    $dispGrid .= implode('  ', $rowItems) . "\n";
}
$dispGrid = trim($dispGrid);

// Draw headers
redrawHeaders($pdf, $columnData);

// Add data rows
foreach ($mockData as $row) {
    // Calculate max height needed for disposition grid
    $maxHeight = 15; // Height to accommodate disposition grid
    
    // Check if we need a page break
    if ($pdf->GetY() + $maxHeight > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
        $pdf->AddPage();
        redrawHeaders($pdf, $columnData);
    }
    
    // Draw each cell in the row
    $currentX = $pdf->GetX(); // Store starting X position
    $currentY = $pdf->GetY(); // Store starting Y position
    
    foreach ($columnData as $index => $col) {
        $header = $col['key'];
        $cellContent = '';
        $fontSize = 7;
        $alignment = 'L';
        $isBold = false;
        
        switch($header) {
            case 'id':
                $cellContent = $row[$header] ?? '';
                $alignment = 'C';
                $isBold = true;
                $fontSize = 8;
                break;
            case 'slot':
                $cellContent = ''; // Empty for now
                $alignment = 'C';
                break;
            case 'connectivity': 
                $cellContent = "O Y / O N"; 
                $alignment = 'C';
                break;
            case 'disposition': 
                $cellContent = $dispGrid; 
                $fontSize = 6;
                $alignment = 'C'; 
                break;
            case 'mobile_no': 
                $cellContent = $row[$header] ?? '';
                $isBold = true; 
                $alignment = 'C'; 
                $fontSize = 8;
                break;
            default: 
                $cellContent = $row[$header] ?? '';
        }
        
        // Set position for this cell
        $cellX = $currentX;
        for ($i = 0; $i < $index; $i++) {
            $cellX += $columnData[$i]['width'];
        }
        $pdf->SetXY($cellX, $currentY);
        
        // Special handling for ID - force single line
        if ($header === 'id') {
            $pdf->SetFont('helvetica', 'B', $fontSize);
            while ($pdf->getStringWidth($cellContent) > ($col['width'] - 4) && $fontSize > 5) {
                $fontSize -= 0.5;
                $pdf->SetFont('helvetica', 'B', $fontSize);
            }
            // Use Cell instead of MultiCell for single line
            $pdf->Cell($col['width'], $maxHeight, $cellContent, 1, 0, $alignment);
        } else {
            $pdf->SetFont('helvetica', $isBold ? 'B' : '', $fontSize);
            $pdf->MultiCell($col['width'], $maxHeight, $cellContent, 1, $alignment, false, 0, '', '', true, 0, false, true, $maxHeight, 'M');
        }
    }
    
    // Move to next row
    $pdf->SetXY($currentX, $currentY + $maxHeight);
}

// Output PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Test_Production_Structure.pdf"');
$pdf->Output('Test_Production_Structure.pdf', 'D');
?>