<?php
require_once 'db_config.php';
requireTeamLeader();

// Get a sample lead for testing
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
    <title>AJAX Authentication Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header">
                <h4>AJAX Authentication Test</h4>
                <small>Test the View button authentication without security restrictions</small>
            </div>
            <div class="card-body">
                <?php if ($sampleLead): ?>
                <div class="alert alert-info">
                    <strong>Sample Lead:</strong><br>
                    ID: <?= $sampleLead['id'] ?><br>
                    Name: <?= $sampleLead['name'] ?><br>
                    Mobile: <?= $sampleLead['mobile_no'] ?>
                </div>
                <?php endif; ?>
                
                <form id="testForm">
                    <div class="mb-3">
                        <label>Access Code:</label>
                        <input type="text" id="accessCode" class="form-control" maxlength="6" style="text-transform: uppercase;">
                    </div>
                    <div class="mb-3">
                        <label>Lead ID:</label>
                        <input type="text" id="leadId" class="form-control" value="<?= $sampleLead['id'] ?? '' ?>">
                    </div>
                    <button type="button" onclick="testAuth()" class="btn btn-primary">Test Authentication</button>
                    <button type="button" onclick="testAuthDebug()" class="btn btn-warning">Test with Debug</button>
                </form>
                
                <div id="result" class="mt-4"></div>
                
                <hr>
                <a href="team_leader_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>
    </div>

    <script>
        function testAuth() {
            const accessCode = document.getElementById('accessCode').value;
            const leadId = document.getElementById('leadId').value;
            
            if (!accessCode || !leadId) {
                showResult('Please enter both access code and lead ID', 'danger');
                return;
            }
            
            showResult('Testing authentication...', 'info');
            
            fetch('team_leader_auth_view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    access_code: accessCode,
                    lead_id: leadId
                })
            })
            .then(response => {
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Response is not JSON: ' + text);
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    showResult('SUCCESS! ' + data.message + '<br>Name: ' + data.data.name + '<br>Mobile: ' + data.data.mobile, 'success');
                } else {
                    showResult('FAILED: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                showResult('ERROR: ' + error.message, 'danger');
            });
        }
        
        function testAuthDebug() {
            const accessCode = document.getElementById('accessCode').value;
            const leadId = document.getElementById('leadId').value;
            
            if (!accessCode || !leadId) {
                showResult('Please enter both access code and lead ID', 'danger');
                return;
            }
            
            showResult('Testing authentication with debug info...', 'info');
            
            fetch('team_leader_auth_view_debug.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    access_code: accessCode,
                    lead_id: leadId
                })
            })
            .then(response => response.text())
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    let debugInfo = '<strong>Debug Info:</strong><ul>';
                    if (data.debug) {
                        data.debug.forEach(item => {
                            debugInfo += '<li>' + item + '</li>';
                        });
                    }
                    debugInfo += '</ul>';
                    
                    if (data.success) {
                        showResult('SUCCESS! ' + data.message + '<br>' + debugInfo, 'success');
                    } else {
                        showResult('FAILED: ' + data.message + '<br>' + debugInfo, 'danger');
                    }
                } catch (e) {
                    showResult('Raw Response: <pre>' + text + '</pre>', 'warning');
                }
            })
            .catch(error => {
                showResult('ERROR: ' + error.message, 'danger');
            });
        }
        
        function showResult(message, type) {
            document.getElementById('result').innerHTML = 
                '<div class="alert alert-' + type + '">' + message + '</div>';
        }
    </script>
</body>
</html>