<?php
session_start();
$_SESSION['leader_id'] = 'TL001';
$_SESSION['is_team_leader'] = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Notification System Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><i class="bi bi-star-fill me-2 text-warning"></i>Full Notification Test</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <div class="btn-group me-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info">
                    <h4>🔔 Full Notification System Test</h4>
                    <p><strong>Team Leader ID:</strong> <?php echo $_SESSION['leader_id']; ?></p>
                    <p><strong>Expected:</strong> Bell icon should appear in the toolbar above with a notification count badge.</p>
                    <p><strong>Status:</strong> <span id="systemStatus">Loading...</span></p>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5>Console Output</h5>
                    </div>
                    <div class="card-body">
                        <div id="consoleOutput" style="background: #000; color: #00ff00; font-family: monospace; padding: 10px; height: 200px; overflow-y: auto;">
                            Starting notification system test...<br>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <button class="btn btn-primary" onclick="testNotificationAPI()">Test AJAX API Call</button>
                    <button class="btn btn-secondary" onclick="clearConsole()">Clear Console</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/followup-notifications.js"></script>
    <script>
        const consoleOutput = document.getElementById('consoleOutput');
        const systemStatus = document.getElementById('systemStatus');
        
        function log(message) {
            consoleOutput.innerHTML += new Date().toLocaleTimeString() + ' - ' + message + '<br>';
            consoleOutput.scrollTop = consoleOutput.scrollHeight;
        }
        
        function clearConsole() {
            consoleOutput.innerHTML = 'Console cleared...<br>';
        }
        
        // Override console.log to capture output
        const originalLog = console.log;
        console.log = function(...args) {
            originalLog.apply(console, arguments);
            log(args.join(' '));
        };
        
        const originalError = console.error;
        console.error = function(...args) {
            originalError.apply(console, arguments);
            log('ERROR: ' + args.join(' '));
        };
        
        // Test the notification API directly
        function testNotificationAPI() {
            log('Testing AJAX API call...');
            fetch('ajax_followup_notifications.php?action=check_notifications')
                .then(response => response.json())
                .then(data => {
                    log('API Response: ' + JSON.stringify(data));
                    log('Notification count: ' + data.count);
                })
                .catch(error => {
                    log('API Error: ' + error.message);
                });
        }
        
        // Initialize notification system
        window.addEventListener('DOMContentLoaded', function() {
            log('DOM loaded, initializing notification system...');
            
            try {
                window.followupNotifications = new FollowupNotifications();
                log('FollowupNotifications class instantiated');
                systemStatus.textContent = 'Initializing...';
                
                // Check notification permission
                log('Notification permission: ' + Notification.permission);
                
                // Check if bell icon appears
                setTimeout(() => {
                    const bellIcon = document.getElementById('notificationBell');
                    if (bellIcon) {
                        log('✓ Bell icon created successfully');
                        systemStatus.textContent = 'Active - Bell icon visible';
                        systemStatus.className = 'text-success';
                    } else {
                        log('✗ Bell icon not found');
                        systemStatus.textContent = 'Error - Bell icon not visible';
                        systemStatus.className = 'text-danger';
                    }
                }, 2000);
                
                // Test API after 3 seconds
                setTimeout(() => {
                    testNotificationAPI();
                }, 3000);
                
            } catch (error) {
                log('Error initializing: ' + error.message);
                systemStatus.textContent = 'Error - ' + error.message;
                systemStatus.className = 'text-danger';
            }
        });
    </script>
</body>
</html>