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
    <title>Embedded Notification Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); box-shadow: 0 0 20px rgba(220, 53, 69, 0.6); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><i class="bi bi-star-fill me-2 text-warning"></i>Embedded Notification Test</h1>
            <div class="btn-toolbar mb-2 mb-md-0" id="toolbar">
                <div class="btn-group me-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info">
            <h4>🔔 Direct Notification Test</h4>
            <p><strong>Session:</strong> TL001</p>
            <p><strong>Available notifications:</strong> 10 (after reset)</p>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h5>API Test</h5></div>
                    <div class="card-body">
                        <button class="btn btn-primary" onclick="testAPI()">Test API</button>
                        <button class="btn btn-success" onclick="createBell()">Create Bell</button>
                        <button class="btn btn-warning" onclick="testMarkRead()">Mark All Read</button>
                        <div id="apiResult" class="mt-3"></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h5>Manual Bell Example</h5></div>
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <button class="btn btn-success position-relative me-3" id="exampleBell">
                                <i class="bi bi-bell-fill text-white"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">10</span>
                            </button>
                            <span>← This is how it should look</span>
                        </div>
                        <small class="text-muted">Green = Low/Medium, Orange = High, Red = Critical</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testAPI() {
            const result = document.getElementById('apiResult');
            result.innerHTML = 'Testing API...';
            
            fetch('ajax_followup_notifications.php?action=check_notifications')
                .then(response => response.json())
                .then(data => {
                    result.innerHTML = `
                        <div class="alert alert-success">
                            <strong>✅ API Success!</strong><br>
                            Count: ${data.count}<br>
                            Success: ${data.success}
                        </div>
                    `;
                    
                    // Update the bell badge count
                    const badge = document.getElementById('notificationCount');
                    if (badge) {
                        badge.textContent = data.count;
                        badge.style.display = data.count > 0 ? 'block' : 'none';
                    }
                    
                    if (data.notifications && data.notifications.length > 0) {
                        const notifications = data.notifications.slice(0, 3); // Show first 3
                        result.innerHTML += '<div class="alert alert-info"><strong>Sample Notifications:</strong><ul>';
                        notifications.forEach(n => {
                            result.innerHTML += `<li>${n.customer_name} - ${n.urgency} - ${n.display_time}</li>`;
                        });
                        result.innerHTML += '</ul></div>';
                        
                        // Update example bell based on urgency
                        updateExampleBell(data.notifications);
                    } else {
                        result.innerHTML += '<div class="alert alert-warning"><strong>No notifications found</strong></div>';
                    }
                })
                .catch(error => {
                    result.innerHTML = `<div class="alert alert-danger">❌ Error: ${error.message}</div>`;
                });
        }
        
        function updateExampleBell(notifications) {
            const bell = document.getElementById('exampleBell');
            const hasCritical = notifications.some(n => n.urgency === 'critical');
            const hasHigh = notifications.some(n => n.urgency === 'high');
            
            if (hasCritical) {
                bell.className = 'btn btn-danger position-relative me-3';
                bell.style.animation = 'pulse 1.5s infinite';
            } else if (hasHigh) {
                bell.className = 'btn btn-warning position-relative me-3';
                bell.style.animation = 'none';
            } else {
                bell.className = 'btn btn-success position-relative me-3';
                bell.style.animation = 'none';
            }
        }
        
        function createBell() {
            const toolbar = document.getElementById('toolbar');
            const existingBell = document.getElementById('notificationBell');
            
            if (existingBell) {
                existingBell.remove();
            }
            
            const bellHtml = `
                <div class="notification-container position-relative me-3">
                    <button class="btn btn-primary position-relative" id="notificationBell" type="button" 
                            data-bs-toggle="dropdown" style="min-width: 45px;">
                        <i class="bi bi-bell-fill text-white"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
                              id="notificationCount">10</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0" style="width: 350px;">
                        <div class="dropdown-header">Follow-up Notifications</div>
                        <div class="dropdown-item">
                            <strong>🔔 Bell created successfully!</strong><br>
                            <small>Click "Test API" to load real notifications</small>
                        </div>
                    </div>
                </div>
            `;
            
            toolbar.insertAdjacentHTML('afterbegin', bellHtml);
            
            const result = document.getElementById('apiResult');
            result.innerHTML = '<div class="alert alert-success">✅ Bell icon created in toolbar!</div>';
        }
        
        function testMarkRead() {
            const result = document.getElementById('apiResult');
            result.innerHTML = 'Marking all as read...';
            
            fetch('ajax_followup_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' })
            })
            .then(response => response.json())
            .then(data => {
                result.innerHTML = `
                    <div class="alert alert-success">
                        ✅ ${data.message}<br>
                        <small>Auto-testing API to update badge...</small>
                    </div>
                `;
                
                // Auto-test API to update the badge
                setTimeout(() => {
                    testAPI();
                }, 500);
            })
            .catch(error => {
                result.innerHTML = `<div class="alert alert-danger">❌ Error: ${error.message}</div>`;
            });
        }
        
        // Auto-test on page load
        window.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded - auto testing API');
            setTimeout(() => {
                testAPI();
            }, 1000);
        });
    </script>
</body>
</html>