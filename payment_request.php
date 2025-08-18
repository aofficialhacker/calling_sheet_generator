<?php
require_once 'db_config.php';
requireTeamLeader();

$conn = getDBConnection();
$leaderId = $_SESSION['leader_id'];
$leadId = $_GET['lead_id'] ?? '';

if (empty($leadId)) {
    header("Location: team_leader_dashboard.php");
    exit();
}

// Validate lead and get details
$stmt = $conn->prepare("
    SELECT fcl.id, fcl.name, fcl.mobile_no, tla.new_disposition, p.product_name, c.caller_name
    FROM final_call_logs fcl
    JOIN team_leader_actions tla ON fcl.id = tla.lead_id
    JOIN file_batches b ON fcl.batch_id = b.id
    JOIN products p ON b.product_code = p.product_code
    JOIN callers c ON fcl.finqy_id = c.finqy_id
    WHERE fcl.id = ? AND tla.leader_id = ? AND tla.new_disposition = 'Interested - Proceed to Payment'
");
$stmt->bind_param("ss", $leadId, $leaderId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: team_leader_dashboard.php");
    exit();
}

$leadData = $result->fetch_assoc();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Request - Team Leader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .payment-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 600px;
            width: 100%;
        }
        .customer-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .payment-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            border-radius: 50px;
            padding: 15px 40px;
            font-size: 1.2rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
        }
        .payment-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.6);
            color: white;
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="bi bi-credit-card-fill text-success" style="font-size: 4rem;"></i>
                <h2 class="mt-3 text-success">Payment Request</h2>
                <p class="text-muted">Customer is ready for payment processing</p>
            </div>

            <div class="customer-info">
                <h5 class="mb-3">
                    <i class="bi bi-person-fill me-2 text-primary"></i>Customer Details
                </h5>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Name:</strong><br>
                        <span class="text-primary fs-5"><?= htmlspecialchars($leadData['name']) ?></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Mobile:</strong><br>
                        <span class="text-primary fs-5"><?= htmlspecialchars($leadData['mobile_no']) ?></span>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <strong>Product:</strong><br>
                        <span class="badge bg-info fs-6"><?= htmlspecialchars($leadData['product_name']) ?></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Original Caller:</strong><br>
                        <?= htmlspecialchars($leadData['caller_name']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Lead ID:</strong><br>
                        <code><?= htmlspecialchars($leadData['id']) ?></code>
                    </div>
                </div>
            </div>

            <div class="alert alert-success">
                <h6><i class="bi bi-check-circle-fill me-2"></i>Status Confirmed</h6>
                <p class="mb-0">This customer has been confirmed as interested and ready for payment processing by Team Leader.</p>
            </div>

            <div class="text-center">
                <h5 class="mb-4">Ready to proceed with CIF Form?</h5>
                
                <!-- This will redirect to the CIF Form page -->
                <a href="cif_form.php?lead_id=<?= urlencode($leadId) ?>&source=team_leader" 
                   class="payment-btn me-3">
                    <i class="bi bi-arrow-right-circle-fill me-2"></i>Proceed to CIF Form
                </a>
                
                <a href="team_leader_dashboard.php" class="btn btn-outline-secondary btn-lg">
                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>

            <div class="mt-4 p-3 bg-light rounded">
                <h6><i class="bi bi-info-circle me-2"></i>Next Steps:</h6>
                <ol class="mb-0 small">
                    <li>Click "Proceed to CIF Form" to redirect to the CIF form page</li>
                    <li>Complete the customer information form with required details</li>
                    <li>Process payment through the established payment gateway</li>
                    <li>Customer will receive confirmation upon successful processing</li>
                </ol>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>