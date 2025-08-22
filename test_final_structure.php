<?php
// Final test script with proper table structure
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

// Custom PDF Class
class FinalPDF extends TCPDF {
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
        $this->Ln(3);
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
        $topMargin = 50;
        $bottomMargin = 20;
        
        $mobileCenter = ($this->cutlineBefore + $this->cutlineAfter) / 2;
        
        // Top cutline with scissor
        $topMarkY = $topMargin;
        $this->drawDottedLine($this->cutlineBefore, $topMarkY, $this->cutlineAfter, $topMarkY);
        
        $this->SetFont('helvetica', 'B', 12);
        $this->SetXY($mobileCenter - 3, $topMarkY - 5);
        $this->Cell(6, 4, '✂', 0, 0, 'C');
        
        // Bottom cutline with scissor
        $bottomMarkY = $pageHeight - $bottomMargin;
        $this->drawDottedLine($this->cutlineBefore, $bottomMarkY, $this->cutlineAfter, $bottomMarkY);
        
        $this->SetXY($mobileCenter - 3, $bottomMarkY - 2);
        $this->Cell(6, 4, '✂', 0, 0, 'C');
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

// Fixed 5-column structure with proper widths
$columnData = [
    ['header' => 'ID', 'width' => 50, 'key' => 'id'],
    ['header' => 'Slot', 'width' => 20, 'key' => 'slot'],
    ['header' => 'Connectivity', 'width' => 30, 'key' => 'connectivity'],
    ['header' => 'Disposition', 'width' => 120, 'key' => 'disposition'],
    ['header' => 'Mobile', 'width' => 35, 'key' => 'mobile_no']
];

// Create PDF
$pdf = new FinalPDF('Calling Sheet for Batch TEST - Final Structure', $slotLegend, $dispLegend);
$pdf->SetCreator('Calling Sheet Generator');
$pdf->SetTitle('Final Production Structure');

// Calculate margins and cutlines
$totalWidth = array_sum(array_column($columnData, 'width'));
$pageWidth = 297; // A4 landscape
$leftMargin = ($pageWidth - $totalWidth) / 2;
$pdf->SetMargins($leftMargin, 38, $leftMargin);
$pdf->SetAutoPageBreak(true, 15);

// Set cutlines for mobile column (last column)
$cutlineBefore = $leftMargin + $columnData[0]['width'] + $columnData[1]['width'] + $columnData[2]['width'] + $columnData[3]['width'];
$cutlineAfter = $cutlineBefore + $columnData[4]['width'];
$pdf->setCutlines($cutlineBefore, $cutlineAfter);

$pdf->AddPage();

// Draw cutlines first
$pdf->drawPageCutlines();

// Draw table headers
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
foreach ($columnData as $col) {
    $pdf->Cell($col['width'], 8, $col['header'], 1, 0, 'C', true);
}
$pdf->Ln();

// Create disposition grid - compact layout
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

// Add data rows - PROPER TABLE STRUCTURE
foreach ($mockData as $row) {
    $rowHeight = 16;
    
    // Check page break
    if ($pdf->GetY() + $rowHeight > ($pdf->getPageHeight() - 20)) {
        $pdf->AddPage();
        $pdf->drawPageCutlines();
        
        // Redraw headers
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        foreach ($columnData as $col) {
            $pdf->Cell($col['width'], 8, $col['header'], 1, 0, 'C', true);
        }
        $pdf->Ln();
    }
    
    // Draw complete row with all 5 columns
    $startY = $pdf->GetY();
    
    // Column 1: ID
    $pdf->SetXY($leftMargin, $startY);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell($columnData[0]['width'], $rowHeight, $row['id'], 1, 0, 'C');
    
    // Column 2: Slot (empty)
    $pdf->SetXY($leftMargin + $columnData[0]['width'], $startY);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell($columnData[1]['width'], $rowHeight, '', 1, 0, 'C');
    
    // Column 3: Connectivity
    $pdf->SetXY($leftMargin + $columnData[0]['width'] + $columnData[1]['width'], $startY);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell($columnData[2]['width'], $rowHeight, 'O Y / O N', 1, 0, 'C');
    
    // Column 4: Disposition (grid)
    $pdf->SetXY($leftMargin + $columnData[0]['width'] + $columnData[1]['width'] + $columnData[2]['width'], $startY);
    $pdf->SetFont('helvetica', '', 6);
    $pdf->MultiCell($columnData[3]['width'], $rowHeight, $dispGrid, 1, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
    
    // Column 5: Mobile
    $pdf->SetXY($leftMargin + $columnData[0]['width'] + $columnData[1]['width'] + $columnData[2]['width'] + $columnData[3]['width'], $startY);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell($columnData[4]['width'], $rowHeight, $row['mobile_no'], 1, 0, 'C');
    
    // Move to next row
    $pdf->SetY($startY + $rowHeight);
}

// Output PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Final_Structure_Test.pdf"');
$pdf->Output('Final_Structure_Test.pdf', 'D');
?>