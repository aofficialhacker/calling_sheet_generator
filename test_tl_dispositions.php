<?php
require_once 'db_config.php';

echo "<h2>Team Leader Dispositions System Test</h2>";

$conn = getDBConnection();

// Test 1: Check if table exists
echo "<h3>1. Testing Table Structure</h3>";
try {
    $result = $conn->query("DESCRIBE team_leader_dispositions");
    if ($result && $result->num_rows > 0) {
        echo "✅ team_leader_dispositions table exists<br>";
        echo "<strong>Table structure:</strong><br>";
        while ($row = $result->fetch_assoc()) {
            echo "- {$row['Field']} ({$row['Type']})<br>";
        }
    } else {
        echo "❌ team_leader_dispositions table does not exist<br>";
    }
} catch (Exception $e) {
    echo "❌ Error checking table: " . $e->getMessage() . "<br>";
}

// Test 2: Check existing dispositions
echo "<h3>2. Current Dispositions</h3>";
try {
    $result = $conn->query("SELECT * FROM team_leader_dispositions ORDER BY created_at DESC");
    if ($result && $result->num_rows > 0) {
        echo "✅ Found " . $result->num_rows . " dispositions:<br>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Description</th><th>Active</th><th>Created</th></tr>";
        while ($row = $result->fetch_assoc()) {
            $status = $row['is_active'] ? '✅ Active' : '❌ Inactive';
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td><strong>{$row['disposition_name']}</strong></td>";
            echo "<td>{$row['description']}</td>";
            echo "<td>{$status}</td>";
            echo "<td>{$row['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "⚠️ No dispositions found. <a href='#' onclick='addDefaults()'>Add default dispositions</a><br>";
    }
} catch (Exception $e) {
    echo "❌ Error checking dispositions: " . $e->getMessage() . "<br>";
}

// Test 3: Check how TL sees dispositions
echo "<h3>3. Team Leader View Test</h3>";
try {
    $result = $conn->query("SELECT * FROM team_leader_dispositions WHERE is_active = 1 ORDER BY disposition_name");
    if ($result && $result->num_rows > 0) {
        echo "✅ Team leaders will see " . $result->num_rows . " active dispositions:<br>";
        echo "<select style='margin: 10px 0; padding: 5px;'>";
        echo "<option value=''>Choose disposition...</option>";
        while ($row = $result->fetch_assoc()) {
            echo "<option value='{$row['disposition_name']}'>";
            echo htmlspecialchars($row['disposition_name']);
            if ($row['description']) {
                echo " - " . htmlspecialchars($row['description']);
            }
            echo "</option>";
        }
        echo "</select>";
    } else {
        echo "⚠️ No active dispositions for team leaders<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 4: Check file access
echo "<h3>4. File Access Test</h3>";
if (file_exists('manage_tl_dispositions.php')) {
    echo "✅ manage_tl_dispositions.php file exists<br>";
    echo "<a href='manage_tl_dispositions.php' target='_blank'>Open TL Dispositions Management Page</a><br>";
} else {
    echo "❌ manage_tl_dispositions.php file missing<br>";
}

// Test 5: Navigation test
echo "<h3>5. Navigation Test</h3>";
if (file_exists('superadmin_sidebar.php')) {
    echo "✅ superadmin_sidebar.php exists<br>";
    $sidebarContent = file_get_contents('superadmin_sidebar.php');
    if (strpos($sidebarContent, 'manage_tl_dispositions.php') !== false) {
        echo "✅ Menu item found in superadmin sidebar<br>";
    } else {
        echo "❌ Menu item missing from superadmin sidebar<br>";
    }
} else {
    echo "❌ superadmin_sidebar.php missing<br>";
}

echo "<h3>6. How to Access</h3>";
echo "<ol>";
echo "<li><strong>Login as Superadmin</strong></li>";
echo "<li>Look for '<strong>Team Leader Dispositions</strong>' in the left sidebar menu</li>";
echo "<li>Click on it to manage TL dispositions</li>";
echo "<li>Create dispositions that Team Leaders will use when reviewing interested leads</li>";
echo "</ol>";

echo "<h3>7. Quick Actions</h3>";
echo "<button onclick='addDefaults()' style='padding: 10px; margin: 5px; background: #007bff; color: white; border: none; border-radius: 5px;'>Add Default Dispositions</button><br>";

$conn->close();
?>

<script>
function addDefaults() {
    if (confirm('Add default Team Leader dispositions?')) {
        fetch('add_default_dispositions.php', {method: 'POST'})
            .then(response => response.text())
            .then(data => {
                alert('Default dispositions added!');
                location.reload();
            })
            .catch(error => {
                alert('Error: ' + error);
            });
    }
}
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
h3 { color: #555; margin-top: 30px; }
table { margin: 10px 0; }
th, td { padding: 8px 12px; text-align: left; }
th { background-color: #f8f9fa; }
</style>