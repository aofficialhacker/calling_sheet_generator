<?php
require_once 'db_config.php';
requireAdmin();

$conn = getDBConnection();
$adminId = $_SESSION['admin_id'];
$message = '';
$messageType = '';

// Handle form submissions
if ($_POST) {
    if (isset($_POST['create_leader'])) {
        $finqyId = trim($_POST['finqy_id']);
        $leaderName = trim($_POST['leader_name']);
        $username = trim($_POST['username']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        
        // Generate unique leader ID
        $stmt = $conn->prepare("SELECT leader_id FROM team_leaders ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $lastId = $result->fetch_assoc()['leader_id'];
            $number = intval(substr($lastId, 2)) + 1;
        } else {
            $number = 1;
        }
        $leaderId = 'TL' . str_pad($number, 3, '0', STR_PAD_LEFT);
        $stmt->close();
        
        // Check if finqy_id is valid and mapped to this admin
        $stmt = $conn->prepare("SELECT c.caller_name FROM callers c 
                               JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id 
                               WHERE c.finqy_id = ? AND acm.admin_id = ? AND c.is_active = 1");
        $stmt->bind_param("ss", $finqyId, $adminId);
        $stmt->execute();
        $callerResult = $stmt->get_result();
        
        if ($callerResult->num_rows > 0) {
            $callerData = $callerResult->fetch_assoc();
            
            // Check if caller is already a team leader
            $stmt = $conn->prepare("SELECT id FROM team_leaders WHERE finqy_id = ? AND is_active = 1");
            $stmt->bind_param("s", $finqyId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $message = "This caller is already assigned as a Relationship Manager.";
                $messageType = "danger";
            } else {
                // Create team leader
                $stmt = $conn->prepare("INSERT INTO team_leaders (leader_id, leader_name, finqy_id, admin_id, username, password) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $leaderId, $leaderName, $finqyId, $adminId, $username, $password);
                
                if ($stmt->execute()) {
                    $message = "Relationship Manager created successfully! Leader ID: $leaderId";
                    $messageType = "success";
                } else {
                    $message = "Error creating Relationship Manager: " . $stmt->error;
                    $messageType = "danger";
                }
            }
        } else {
            $message = "Invalid caller selected or caller not mapped to your account.";
            $messageType = "danger";
        }
        $stmt->close();
    }
    
    if (isset($_POST['deactivate_leader'])) {
        $leaderId = $_POST['leader_id'];
        $stmt = $conn->prepare("UPDATE team_leaders SET is_active = 0 WHERE leader_id = ? AND admin_id = ?");
        $stmt->bind_param("ss", $leaderId, $adminId);
        
        if ($stmt->execute()) {
            $message = "Relationship Manager deactivated successfully.";
            $messageType = "success";
        } else {
            $message = "Error deactivating Relationship Manager.";
            $messageType = "danger";
        }
        $stmt->close();
    }
    
    if (isset($_POST['reactivate_leader'])) {
        $leaderId = $_POST['leader_id'];
        $stmt = $conn->prepare("UPDATE team_leaders SET is_active = 1 WHERE leader_id = ? AND admin_id = ?");
        $stmt->bind_param("ss", $leaderId, $adminId);
        
        if ($stmt->execute()) {
            $message = "Relationship Manager reactivated successfully.";
            $messageType = "success";
        } else {
            $message = "Error reactivating Relationship Manager.";
            $messageType = "danger";
        }
        $stmt->close();
    }
}

// Get available callers for this admin (not already team leaders)
$availableCallers = [];
$stmt = $conn->prepare("
    SELECT c.finqy_id, c.caller_name 
    FROM callers c 
    JOIN admin_caller_mapping acm ON c.finqy_id = acm.finqy_id 
    LEFT JOIN team_leaders tl ON c.finqy_id = tl.finqy_id AND tl.is_active = 1
    WHERE acm.admin_id = ? AND c.is_active = 1 AND tl.id IS NULL
    ORDER BY c.caller_name
");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $availableCallers[] = $row;
}
$stmt->close();

// Get existing team leaders for this admin
$teamLeaders = [];
$stmt = $conn->prepare("
    SELECT tl.*, c.caller_name, c.mobile_no,
           (SELECT COUNT(*) FROM team_leader_actions WHERE leader_id = tl.leader_id) as total_actions
    FROM team_leaders tl
    JOIN callers c ON tl.finqy_id = c.finqy_id
    WHERE tl.admin_id = ?
    ORDER BY tl.created_at DESC
");
$stmt->bind_param("s", $adminId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $teamLeaders[] = $row;
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Relationship Managers</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f7f6; }
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #3a506b 0%, #2c3e50 100%); color: white; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.75rem 1rem; margin: 0.25rem 0; border-radius: 0.5rem; transition: all 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.1); }
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease-in-out;
        }
        .stat-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-people-fill me-2"></i>Manage Relationship Managers</h1>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Create Relationship Manager Form -->
                <div class="card stat-card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-person-plus-fill me-2"></i>Create New Relationship Manager</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($availableCallers)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                No available callers to promote as Relationship Managers. All your callers are either already Relationship Managers or inactive.
                            </div>
                        <?php else: ?>
                            <form method="POST" class="row g-3">
                                <div class="col-md-6">
                                    <label for="finqy_id" class="form-label">Select Caller</label>
                                    <select name="finqy_id" id="finqy_id" class="form-select" required onchange="updateLeaderName()">
                                        <option value="">Choose a caller to promote...</option>
                                        <?php foreach ($availableCallers as $caller): ?>
                                            <option value="<?= $caller['finqy_id'] ?>" 
                                                    data-name="<?= htmlspecialchars($caller['caller_name']) ?>">
                                                <?= htmlspecialchars($caller['caller_name']) ?> (<?= $caller['finqy_id'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="leader_name" class="form-label">Leader Name</label>
                                    <input type="text" name="leader_name" id="leader_name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" name="username" id="username" class="form-control" required 
                                           placeholder="Unique username for login">
                                </div>
                                <div class="col-md-6">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" name="password" id="password" class="form-control" required 
                                           minlength="8" placeholder="Minimum 8 characters">
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="create_leader" class="btn btn-primary">
                                        <i class="bi bi-plus-circle me-2"></i>Create Relationship Manager
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Existing Relationship Managers -->
                <div class="card stat-card">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Your Relationship Managers</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($teamLeaders)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-people display-4 opacity-25"></i>
                                <p class="mt-3">No Relationship Managers created yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Leader ID</th>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Caller ID</th>
                                            <th>Actions Taken</th>
                                            <th>Last Login</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teamLeaders as $leader): ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?= $leader['leader_id'] ?></span></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($leader['leader_name']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($leader['caller_name']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($leader['username']) ?></td>
                                                <td><?= $leader['finqy_id'] ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?= $leader['total_actions'] ?></span>
                                                </td>
                                                <td>
                                                    <?= $leader['last_login'] ? date('d-M-Y H:i', strtotime($leader['last_login'])) : 'Never' ?>
                                                </td>
                                                <td>
                                                    <?php if ($leader['is_active']): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($leader['is_active']): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="leader_id" value="<?= $leader['leader_id'] ?>">
                                                            <button type="submit" name="deactivate_leader" 
                                                                    class="btn btn-sm btn-warning"
                                                                    onclick="return confirm('Are you sure you want to deactivate this Relationship Manager?')">
                                                                <i class="bi bi-pause-fill"></i> Deactivate
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="leader_id" value="<?= $leader['leader_id'] ?>">
                                                            <button type="submit" name="reactivate_leader" 
                                                                    class="btn btn-sm btn-success">
                                                                <i class="bi bi-play-fill"></i> Reactivate
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        function updateLeaderName() {
            const select = document.getElementById('finqy_id');
            const nameInput = document.getElementById('leader_name');
            const usernameInput = document.getElementById('username');
            
            if (select.value) {
                const selectedOption = select.options[select.selectedIndex];
                const callerName = selectedOption.getAttribute('data-name');
                nameInput.value = callerName;
                
                // Generate username suggestion
                const username = callerName.toLowerCase().replace(/\s+/g, '_') + '_tl';
                usernameInput.value = username;
            } else {
                nameInput.value = '';
                usernameInput.value = '';
            }
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>