<?php
require_once 'db_config.php';
require_once 'masking_utils.php';
requireTeamLeader();

$message = '';
$testResult = '';

if ($_POST) {
    $accessCode = strtoupper(trim($_POST['access_code']));
    $leadId = trim($_POST['lead_id']);
    
    if ($accessCode && $leadId) {
        $conn = getDBConnection();
        
        try {
            // Test the access code validation
            $isValid = validateTeamLeaderAccessCode($_SESSION['leader_id'], $accessCode, $conn);
            
            if ($isValid) {
                // Test if lead exists and belongs to this admin
                $stmt = $conn->prepare("
                    SELECT fcl.id, fcl.name, fcl.mobile_no 
                    FROM final_call_logs fcl
                    JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
                    WHERE fcl.id = ? AND acm.admin_id = ? AND fcl.disposition = 'Interested'
                ");
                $stmt->bind_param("ss", $leadId, $_SESSION['admin_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $leadData = $result->fetch_assoc();
                    $testResult = "SUCCESS! Authentication passed. Lead found: " . $leadData['name'] . " - " . $leadData['mobile_no'];
                } else {
                    $testResult = "Authentication passed but lead not found or not accessible";
                }
                $stmt->close();
            } else {
                $testResult = "FAILED! Access code validation failed";
            }
            
        } catch (Exception $e) {
            $testResult = "ERROR: " . $e->getMessage();
        }
        
        $conn->close();
    } else {
        $testResult = "Please provide both access code and lead ID";
    }
}

// Get a sample lead ID for testing
$conn = getDBConnection();
$stmt = $conn->prepare("
    SELECT fcl.id, fcl.name, fcl.mobile_no
    FROM final_call_logs fcl
    JOIN admin_caller_mapping acm ON fcl.finqy_id = acm.finqy_id
    LEFT JOIN team_leader_actions tla ON fcl.id = tla.lead_id AND tla.leader_id = ?
    WHERE acm.admin_id = ? AND fcl.disposition = 'Interested' AND tla.id IS NULL
    LIMIT 1
");
$stmt->bind_param("ss", $_SESSION['leader_id'], $_SESSION['admin_id']);
$stmt->execute();
$sampleLead = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Authentication Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header">
                <h3>Team Leader Authentication Test</h3>
                <small>Testing the same authentication used in the View button</small>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <strong>Session Info:</strong><br>
                    Leader ID: <?= $_SESSION['leader_id'] ?? 'Not set' ?><br>
                    Admin ID: <?= $_SESSION['admin_id'] ?? 'Not set' ?><br>
                    Is Team Leader: <?= (isset($_SESSION['is_team_leader']) && $_SESSION['is_team_leader']) ? 'Yes' : 'No' ?>
                </div>
                
                <?php if ($sampleLead): ?>
                <div class="alert alert-secondary">
                    <strong>Sample Lead for Testing:</strong><br>
                    ID: <?= $sampleLead['id'] ?><br>
                    Name: <?= maskName($sampleLead['name']) ?> (Real: <?= $sampleLead['name'] ?>)<br>
                    Mobile: <?= maskMobile($sampleLead['mobile_no']) ?> (Real: <?= $sampleLead['mobile_no'] ?>)
                </div>
                <?php endif; ?>
                
                <?php if ($testResult): ?>
                <div class="alert alert-<?= strpos($testResult, 'SUCCESS') === 0 ? 'success' : (strpos($testResult, 'ERROR') === 0 ? 'danger' : 'warning') ?>">
                    <strong>Test Result:</strong><br>
                    <?= htmlspecialchars($testResult) ?>
                </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Access Code (same as login)</label>
                        <input type="text" name="access_code" class="form-control" maxlength="6" style="text-transform: uppercase;" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lead ID to test</label>
                        <input type="text" name="lead_id" class="form-control" value="<?= $sampleLead['id'] ?? '' ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Test Authentication</button>
                </form>
                
                <hr>
                <a href="team_leader_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>