<?php
session_start();
$_SESSION['leader_id'] = 'TL001';
$_SESSION['is_team_leader'] = true;
$_SESSION['leader_name'] = 'Test Leader';
$_SESSION['admin_id'] = 'ADMIN001';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Test - No Security</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <!-- Simulate Team Leader Dashboard Header -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><i class="bi bi-star-fill me-2 text-warning"></i>Notification Test Dashboard</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <div class="btn-group me-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
                <!-- Bell icon will be inserted here -->
            </div>
        </div>
        
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info">
                    <h4>🔔 Notification System Test</h4>
                    <p><strong>Session:</strong> TL001 (<?php echo $_SESSION['leader_name']; ?>)</p>
                    <p><strong>Expected:</strong> Bell icon should appear in toolbar above with notification count.</p>
                    <p><strong>Console messages will show below:</strong></p>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5>Debug Output</h5>
                    </div>
                    <div class="card-body">
                        <div id="debugOutput" style="background: #000; color: #00ff00; font-family: monospace; padding: 15px; height: 200px; overflow-y: auto;">
                            Waiting for notification system...<br>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary" onclick="testAPI()">Test API</button>
                            <button class="btn btn-success" onclick="testMarkAllRead()">Mark All Read</button>
                            <button class="btn btn-warning" onclick="checkBellIcon()">Check Bell</button>
                            <button class="btn btn-secondary" onclick="clearDebug()">Clear</button>
                        </div>
                    </div>
                </div>
                
                <!-- Manual notification test -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5>Manual Bell Test</h5>
                    </div>
                    <div class="card-body">
                        <button id="manualBell" class="btn btn-info position-relative me-3" onclick="showTestDropdown()">
                            <i class="bi bi-bell-fill text-white"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">8</span>
                        </button>
                        <span>← This is how the bell should look</span>
                        
                        <div id="testDropdown" class="dropdown-menu show mt-2" style="position: static; width: 350px; display: none;">
                            <div class="dropdown-header d-flex justify-content-between">
                                <span>Follow-up Notifications</span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="alert('Mark All Read clicked!')">Mark All Read</button>
                            </div>
                            
                            <!-- Test notifications with colors -->
                            <div style="background: linear-gradient(90deg, #dc3545 0%, rgba(220,53,69,0.1) 100%); color: #721c24; padding: 12px; border-left: 4px solid #dc3545; margin: 2px;">
                                <h6 class="mb-1 fw-bold">🔴 MR. CRITICAL CUSTOMER</h6>
                                <p class="mb-1 small">Follow-up Disposition</p>
                                <small class="fw-bold"><i class="bi bi-clock-fill"></i> OVERDUE <i class="bi bi-exclamation-triangle-fill text-danger"></i></small>
                            </div>
                            
                            <div style="background: linear-gradient(90deg, #fd7e14 0%, rgba(253,126,20,0.1) 100%); color: #a55d2a; padding: 12px; border-left: 4px solid #fd7e14; margin: 2px;">
                                <h6 class="mb-1 fw-bold">🟠 MR. HIGH CUSTOMER</h6>
                                <p class="mb-1 small">Follow-up Disposition</p>
                                <small class="fw-bold"><i class="bi bi-clock-fill"></i> Due 17:08</small>
                            </div>
                            
                            <div style="background: linear-gradient(90deg, #0dcaf0 0%, rgba(13,202,240,0.1) 100%); color: #067581; padding: 12px; border-left: 4px solid #0dcaf0; margin: 2px;">
                                <h6 class="mb-1 fw-bold">🔵 MR. MEDIUM CUSTOMER</h6>
                                <p class="mb-1 small">Follow-up Disposition</p>
                                <small class="fw-bold"><i class="bi bi-clock-fill"></i> Due 18:00</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- NO security protection script here -->
    <script src="js/followup-notifications.js"></script>
    
    <script>
        const debugDiv = document.getElementById('debugOutput');
        
        function log(message) {
            const timestamp = new Date().toLocaleTimeString();
            debugDiv.innerHTML += `[${timestamp}] ${message}<br>`;
            debugDiv.scrollTop = debugDiv.scrollHeight;
        }
        
        function clearDebug() {
            debugDiv.innerHTML = 'Debug cleared...<br>';
        }
        
        function checkBellIcon() {
            const bell = document.getElementById('notificationBell');
            if (bell) {
                log('✅ Bell icon found: ' + bell.outerHTML.substring(0, 100) + '...');
            } else {
                log('❌ Bell icon not found');
            }
        }
        
        function testAPI() {
            log('🔍 Testing API...');
            fetch('ajax_followup_notifications.php?action=check_notifications')
                .then(response => response.json())
                .then(data => {
                    log('✅ API Success: count = ' + data.count);
                    if (data.notifications) {
                        data.notifications.forEach((n, i) => {
                            log(`📋 ${i + 1}. ${n.customer_name} - ${n.urgency} - ${n.display_time}`);
                        });
                    }
                })
                .catch(error => log('❌ API Error: ' + error.message));
        }
        
        function testMarkAllRead() {
            log('🔄 Testing mark all read...');
            fetch('ajax_followup_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' })
            })
            .then(response => response.json())
            .then(data => {
                log('✅ Mark all read: ' + JSON.stringify(data));
                setTimeout(() => testAPI(), 500);
            })
            .catch(error => log('❌ Mark all read error: ' + error.message));
        }
        
        function showTestDropdown() {
            const dropdown = document.getElementById('testDropdown');
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            log('🚀 Page loaded - starting notification test');
            
            try {
                log('🔔 Creating notification system...');
                window.followupNotifications = new FollowupNotifications();
                log('✅ Notification system created');
                
                setTimeout(() => {
                    checkBellIcon();
                    testAPI();
                }, 2000);
                
            } catch (error) {
                log('❌ Error: ' + error.message);
            }
        });
        
        // Override console methods to capture output
        const originalLog = console.log;
        const originalError = console.error;
        
        console.log = function(...args) {
            originalLog.apply(console, arguments);
            log('LOG: ' + args.join(' '));
        };
        
        console.error = function(...args) {
            originalError.apply(console, arguments);
            log('ERROR: ' + args.join(' '));
        };
    </script>
</body>
</html>