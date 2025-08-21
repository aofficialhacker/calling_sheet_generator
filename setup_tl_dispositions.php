<?php
require_once 'db_config.php';

echo "<h2>Setting up Team Leader Dispositions</h2>";

$conn = getDBConnection();

// Check if table exists
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM team_leader_dispositions");
    $currentCount = $result->fetch_assoc()['count'];
    echo "<p>Current TL dispositions count: <strong>$currentCount</strong></p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: Table may not exist. Run setup_team_leader.sql first.</p>";
    echo "<p>Error details: " . $e->getMessage() . "</p>";
    exit;
}

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

echo "<h3>Processing Dispositions:</h3>";
foreach ($defaultDispositions as $disp) {
    // Check if disposition already exists
    $stmt = $conn->prepare("SELECT id FROM team_leader_dispositions WHERE disposition_name = ?");
    $stmt->bind_param("s", $disp['name']);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows == 0) {
        // Add new disposition
        $stmt2 = $conn->prepare("INSERT INTO team_leader_dispositions (disposition_name, description, created_by) VALUES (?, ?, 'SYSTEM')");
        $stmt2->bind_param("ss", $disp['name'], $disp['desc']);
        if ($stmt2->execute()) {
            echo "<p style='color: green;'>✅ Added: " . htmlspecialchars($disp['name']) . "</p>";
            $added++;
        } else {
            echo "<p style='color: red;'>❌ Failed to add: " . htmlspecialchars($disp['name']) . "</p>";
        }
        $stmt2->close();
    } else {
        echo "<p style='color: orange;'>⚠️ Skipped (exists): " . htmlspecialchars($disp['name']) . "</p>";
        $skipped++;
    }
    $stmt->close();
}

echo "<h3>Summary:</h3>";
echo "<p><strong>Added:</strong> $added dispositions</p>";
echo "<p><strong>Skipped:</strong> $skipped existing dispositions</p>";

// Show current dispositions
echo "<h3>Current Team Leader Dispositions:</h3>";
$result = $conn->query("SELECT * FROM team_leader_dispositions ORDER BY created_at DESC");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Disposition Name</th><th>Description</th><th>Active</th><th>Created</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $status = $row['is_active'] ? '✅ Active' : '❌ Inactive';
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td><strong>" . htmlspecialchars($row['disposition_name']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['description']) . "</td>";
        echo "<td>$status</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>No dispositions found!</p>";
}

echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li><strong>Go to Superadmin Panel:</strong> <a href='superadmin_panel.php'>superadmin_panel.php</a></li>";
echo "<li><strong>Look for 'Team Leader Dispositions' in sidebar</strong> (should now be visible)</li>";
echo "<li><strong>Or go directly:</strong> <a href='manage_tl_dispositions.php'>manage_tl_dispositions.php</a></li>";
echo "<li><strong>Manage dispositions</strong> as needed</li>";
echo "</ol>";

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h2, h3 { color: #333; }
table { margin: 10px 0; }
th, td { padding: 8px 12px; text-align: left; border: 1px solid #ddd; }
th { background-color: #f8f9fa; }
</style>