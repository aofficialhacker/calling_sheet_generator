<?php
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || php_sapi_name() === 'cli') {
    $conn = getDBConnection();
    
    $defaultDispositions = [
        ['name' => 'Interested - Proceed to Payment', 'desc' => 'Customer confirmed interest and ready for payment processing'],
        ['name' => 'Follow Up Required', 'desc' => 'Customer interested but needs follow-up call at specific time'],
        ['name' => 'Not Qualified', 'desc' => 'Customer does not meet the qualification criteria'],
        ['name' => 'Price Objection', 'desc' => 'Customer interested but has concerns about pricing'],
        ['name' => 'Need More Information', 'desc' => 'Customer wants additional product details before deciding'],
        ['name' => 'Call Back Later', 'desc' => 'Customer requested to be called back at a different time'],
        ['name' => 'Wrong Contact Details', 'desc' => 'Phone number or contact information is incorrect'],
        ['name' => 'Already Purchased', 'desc' => 'Customer has already purchased this or similar product'],
        ['name' => 'Not Available', 'desc' => 'Customer was not available during the call attempt'],
        ['name' => 'Do Not Call', 'desc' => 'Customer requested to be removed from calling list']
    ];
    
    $added = 0;
    $skipped = 0;
    
    foreach ($defaultDispositions as $disp) {
        // Check if disposition already exists
        $stmt = $conn->prepare("SELECT id FROM lv_team_leader_dispositions WHERE disposition_name = ?");
        $stmt->bind_param("s", $disp['name']);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows == 0) {
            // Add new disposition
            $stmt = $conn->prepare("INSERT INTO lv_team_leader_dispositions (disposition_name, description, created_by) VALUES (?, ?, 'SYSTEM')");
            $stmt->bind_param("ss", $disp['name'], $disp['desc']);
            if ($stmt->execute()) {
                $added++;
            }
        } else {
            $skipped++;
        }
        $stmt->close();
    }
    
    echo "Added: $added dispositions, Skipped: $skipped existing";
    $conn->close();
} else {
    echo "Invalid request method";
}
?>