<?php
require_once 'db_config.php';

$conn = getDBConnection();

echo "<h2>Fixing SUPER Admin Issue</h2>";

// First check if SUPER exists
$result = $conn->query("SELECT admin_id FROM lv_admin_users WHERE admin_id = 'SUPER'");
if ($result && $result->num_rows > 0) {
    echo "<p style='color: green;'>✅ SUPER admin already exists</p>";
} else {
    echo "<p style='color: orange;'>⚠️ SUPER admin doesn't exist, creating it...</p>";
    
    // Check table structure first
    $result = $conn->query("DESCRIBE lv_admin_users");
    echo "<p>Admin users table structure:</p><ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>" . $row['Field'] . " - " . $row['Type'] . "</li>";
    }
    echo "</ul>";
    
    // Insert SUPER admin - using correct columns and unique username
    $query = "INSERT INTO lv_admin_users (admin_id, username, name, password, designation, is_active) VALUES ('SUPER', 'system_super', 'System Superadmin', '" . password_hash('super123', PASSWORD_DEFAULT) . "', 'Superadmin', 1)";
    
    if ($conn->query($query)) {
        echo "<p style='color: green;'>✅ SUPER admin created successfully</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to create SUPER admin: " . $conn->error . "</p>";
        echo "<p>Query: " . htmlspecialchars($query) . "</p>";
    }
}

// Now test creating a TL disposition
echo "<h3>Testing TL Disposition Creation</h3>";

$testName = "Test Disposition " . date('H:i:s');
$testDesc = "Test description for foreign key validation";
$createdBy = 'SUPER';

$stmt = $conn->prepare("INSERT INTO lv_team_leader_dispositions (disposition_name, description, created_by) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $testName, $testDesc, $createdBy);

if ($stmt->execute()) {
    echo "<p style='color: green;'>✅ Test TL disposition created successfully!</p>";
    echo "<p>Disposition: " . htmlspecialchars($testName) . "</p>";
    
    // Clean up test disposition
    $testId = $conn->insert_id;
    $conn->query("DELETE FROM lv_team_leader_dispositions WHERE id = $testId");
    echo "<p>Test disposition cleaned up.</p>";
} else {
    echo "<p style='color: red;'>❌ Failed to create test TL disposition: " . $stmt->error . "</p>";
}
$stmt->close();

echo "<h3>Current Solution</h3>";
echo "<p>The issue was that the foreign key constraint required 'SUPER' to exist in lv_admin_users table.</p>";
echo "<p>Now you can create Team Leader dispositions in the superadmin panel.</p>";

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h2, h3 { color: #333; }
</style>