<?php
require_once 'db_config.php';

$conn = getDBConnection();

echo "<h2>Checking Admin Users Table</h2>";

// Check if SUPER exists in admin_users
$result = $conn->query("SELECT admin_id, name FROM admin_users WHERE admin_id = 'SUPER'");
if ($result && $result->num_rows > 0) {
    echo "<p style='color: green;'>✅ SUPER admin exists in admin_users table</p>";
    while ($row = $result->fetch_assoc()) {
        echo "<p>Admin ID: " . $row['admin_id'] . ", Name: " . $row['name'] . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ SUPER admin does NOT exist in admin_users table</p>";
}

// Show all admin_ids for reference
echo "<h3>All Admin IDs in admin_users:</h3>";
$result = $conn->query("SELECT admin_id, name FROM admin_users ORDER BY admin_id");
if ($result && $result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>" . htmlspecialchars($row['admin_id']) . " - " . htmlspecialchars($row['name']) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No admin users found</p>";
}

// Check foreign key constraint
echo "<h3>Foreign Key Constraint Info:</h3>";
$result = $conn->query("
    SELECT 
        CONSTRAINT_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_NAME = 'team_leader_dispositions' 
    AND TABLE_SCHEMA = 'caller_sheet3'
    AND REFERENCED_TABLE_NAME IS NOT NULL
");

if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Constraint</th><th>Column</th><th>References Table</th><th>References Column</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['CONSTRAINT_NAME'] . "</td>";
        echo "<td>" . $row['COLUMN_NAME'] . "</td>";
        echo "<td>" . $row['REFERENCED_TABLE_NAME'] . "</td>";
        echo "<td>" . $row['REFERENCED_COLUMN_NAME'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No foreign key constraints found</p>";
}

$conn->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h2, h3 { color: #333; }
table { margin: 10px 0; }
th, td { padding: 8px 12px; text-align: left; border: 1px solid #ddd; }
th { background-color: #f8f9fa; }
</style>