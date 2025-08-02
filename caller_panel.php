<?php
session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet');

// --- HELPER FUNCTION FOR CAPTCHA ---
function generateCaptcha() {
    $num1 = rand(1, 9);
    $num2 = rand(1, 5);
    $_SESSION['captcha_answer'] = $num1 + $num2;
    $_SESSION['captcha_question'] = "What is $num1 + $num2?";
}

// --- LOGIN & CAPTCHA LOGIC ---
$login_error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['finqy_id'])) {
    // Check CAPTCHA first
    if (isset($_POST['captcha'], $_SESSION['captcha_answer']) && intval($_POST['captcha']) == $_SESSION['captcha_answer']) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) { die("DB Connection Failed."); }
        
        $stmt = $conn->prepare("SELECT finqy_id, caller_name FROM callers WHERE finqy_id = ? AND is_active = 1");
        $stmt->bind_param("s", $_POST['finqy_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $_SESSION['finqy_id'] = $user['finqy_id'];
            $_SESSION['caller_name'] = $user['caller_name'];
            unset($_SESSION['captcha_answer'], $_SESSION['captcha_question']); // Clean up session
        } else {
            $login_error = "Invalid or inactive FinqyID.";
        }
        $stmt->close();
        $conn->close();
    } else {
        $login_error = "Incorrect CAPTCHA answer. Please try again.";
    }
    
    // BUG FIX: Regenerate a new CAPTCHA for the next attempt, regardless of success or failure.
    // This ensures the user always sees a fresh, valid question.
    if (!isset($_SESSION['finqy_id'])) {
        generateCaptcha();
    }

} else if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_SESSION['finqy_id'])) {
    // Generate CAPTCHA for the initial page load
    generateCaptcha();
}


if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_unset();
    session_destroy();
    header("Location: caller_panel.php");
    exit();
}

const DISPOSITION_MAP = [ '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up', '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing', '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy', ];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>Caller Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .camera-container{border:2px dashed #0dcaf0;border-radius:.5rem;padding:1.5rem;text-align:center;cursor:pointer;background-color:#f8f9fa;transition:background-color .2s}
        .camera-container:hover{background-color:#e2f8fd}
        #imagePreviewContainer img { max-width: 150px; height: auto; border-radius: .5rem; margin: 5px; border: 1px solid #dee2e6; }
        .form-label-icon{font-size:3rem;color:#0dcaf0}
        .panel-card { transition: all 0.3s ease; border: none; }
        .panel-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="container mt-4 mb-5">
    <?php if (!isset($_SESSION['finqy_id'])): // --- SHOW LOGIN FORM --- ?>
    <div class="row justify-content-center">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-5">
                    <div class="text-center mb-4"><h1 class="h3 fw-bold">Caller Panel Login</h1><p class="text-muted">Enter your FinqyID and solve the problem</p></div>
                    <form action="caller_panel.php" method="POST">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="finqy_id" name="finqy_id" placeholder="e.g., FINQY001" required>
                            <label for="finqy_id">FinqyID</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="number" class="form-control" id="captcha" name="captcha" placeholder="Answer" required>
                            <label for="captcha"><?= htmlspecialchars($_SESSION['captcha_question'] ?? 'Loading...') ?></label>
                        </div>
                        <?php if ($login_error): ?><div class="alert alert-danger py-2"><?= $login_error ?></div><?php endif; ?>
                        <div class="d-grid"><button class="btn btn-primary btn-lg" type="submit">Login</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php else: // --- SHOW LOGGED-IN DASHBOARD --- ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="h2 mb-0"><i class="bi bi-telephone-inbound-fill me-2"></i>Caller Panel</h1><span class="text-muted">Welcome, <?= htmlspecialchars($_SESSION['caller_name']) ?>!</span></div>
        <a href="?action=logout" class="btn btn-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
    </div>

    <div class="row g-4" id="main-options">
        <div class="col-md-6">
            <div class="card panel-card text-center h-100 shadow-sm">
                <div class="card-body p-4 d-flex flex-column justify-content-center"><h3 class="card-title"><i class="bi bi-camera2 fs-1 text-info"></i><br>Upload Marked Sheet(s)</h3><p class="text-muted mt-2">Process new sheets by uploading their photos. You can select multiple images.</p><button class="btn btn-info text-white mt-auto" id="startUploadBtn">Start Upload</button></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card panel-card text-center h-100 shadow-sm">
                <div class="card-body p-4 d-flex flex-column justify-content-center"><h3 class="card-title"><i class="bi bi-bar-chart-line-fill fs-1 text-success"></i><br>View Performance</h3><p class="text-muted mt-2">Check your call statistics and recent activity.</p><a href="view_performance.php" class="btn btn-success mt-auto">View All Logs</a></div>
            </div>
        </div>
    </div>
    
    <div id="upload-section" class="card shadow-sm mt-4" style="display: none;">
        <div class="card-header"><h3 class="h5 mb-0">Upload Marked Sheets</h3></div>
        <div class="card-body">
            <form id="captureForm">
                <label for="markedSheet" class="camera-container" id="cameraLabel">
                    <div class="form-label-icon"><i class="bi bi-camera2"></i></div><h5 class="mt-2 text-info">Tap to open Camera or select files</h5>
                </label>
                <input class="form-control d-none" type="file" name="markedSheet" id="markedSheet" accept="image/*" multiple required>
                <div id="imagePreviewContainer" class="text-center mt-3 d-flex flex-wrap justify-content-center"></div>
                <div id="processing-feedback" class="alert alert-info mt-3" style="display: none;"></div>
                <div class="d-grid mt-4"><button id="submitButton" type="submit" class="btn btn-info btn-lg text-white" disabled><i class="bi bi-magic me-2"></i>Process with AI</button></div>
            </form>
        </div>
    </div>
    
    <div id="results-section" class="card mt-4 shadow-sm" style="display:none;">
        <div class="card-header"><h3><i class="bi bi-clipboard-check-fill me-2"></i>AI Processing Results</h3><p class="mb-0 text-muted">Please review the data. If correct, click "Confirm & Save" to log these results.</p></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light"><tr><th>Customer Name</th><th>Mobile No</th><th>Connectivity</th><th>Disposition</th><th>Slot</th></tr></thead>
                    <tbody id="results-tbody"></tbody>
                </table>
            </div>
            <form action="save_final_log.php" method="post" class="mt-3">
                <input type="hidden" name="json_results" id="json_results_input">
                <input type="hidden" name="finqy_id" value="<?= htmlspecialchars($_SESSION['finqy_id']) ?>">
                <button type="submit" class="btn btn-success"><i class="bi bi-save-fill me-2"></i>Confirm & Save</button>
                <a href="caller_panel.php" class="btn btn-secondary">Cancel and Start Over</a>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    if (document.getElementById('captureForm')) {
        const startUploadBtn = document.getElementById('startUploadBtn');
        const mainOptions = document.getElementById('main-options');
        const uploadSection = document.getElementById('upload-section');
        const resultsSection = document.getElementById('results-section');
        const markedSheetInput = document.getElementById('markedSheet');
        const imagePreviewContainer = document.getElementById('imagePreviewContainer');
        const cameraLabel = document.getElementById('cameraLabel');
        const submitButton = document.getElementById('submitButton');
        const captureForm = document.getElementById('captureForm');
        const feedbackDiv = document.getElementById('processing-feedback');
        let allResults = [];
        startUploadBtn.addEventListener('click', () => {
            mainOptions.style.display = 'none';
            uploadSection.style.display = 'block';
        });
        markedSheetInput.addEventListener('change', (e) => {
            imagePreviewContainer.innerHTML = '';
            const files = e.target.files;
            if (files.length > 0) {
                for (const file of files) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        const img = document.createElement('img');
                        img.src = event.target.result;
                        imagePreviewContainer.appendChild(img);
                    }
                    reader.readAsDataURL(file);
                }
                cameraLabel.style.display = 'none';
                submitButton.disabled = false;
            } else {
                cameraLabel.style.display = 'block';
                submitButton.disabled = true;
            }
        });
        captureForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            allResults = [];
            const files = markedSheetInput.files;
            if (files.length === 0) return;
            submitButton.disabled = true;
            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...`;
            feedbackDiv.style.display = 'block';
            for (let i = 0; i < files.length; i++) {
                feedbackDiv.textContent = `Processing image ${i + 1} of ${files.length}...`;
                const formData = new FormData();
                formData.append('markedSheet', files[i]);
                try {
                    const response = await fetch('ajax_process_image.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.success && result.data) {
                        allResults.push(...result.data);
                    } else {
                        feedbackDiv.textContent = `Error on image ${i + 1}: ${result.message || 'Unknown error'}. Skipping.`;
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    }
                } catch (error) {
                    feedbackDiv.textContent = `A network error occurred on image ${i + 1}. Skipping.`;
                    await new Promise(resolve => setTimeout(resolve, 2000));
                }
            }
            submitButton.disabled = false;
            submitButton.innerHTML = `<i class="bi bi-magic me-2"></i>Process with AI`;
            feedbackDiv.style.display = 'none';
            if (allResults.length > 0) {
                displayResults();
            } else {
                alert('Processing complete, but no usable data could be extracted from the images.');
                location.reload();
            }
        });
        function displayResults() {
            const tbody = document.getElementById('results-tbody');
            const dispoMap = <?= json_encode(DISPOSITION_MAP) ?>;
            const connMap = <?= json_encode(CONNECTIVITY_MAP) ?>;
            tbody.innerHTML = '';
            allResults.forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${escapeHtml(row.customer_name || 'N/A')}</td>
                    <td>${escapeHtml(row.mobile_no || 'N/A')}</td>
                    <td>${escapeHtml(connMap[row.connectivity_code] || 'N/A')}</td>
                    <td>${escapeHtml(dispoMap[row.disposition_code] || 'Empty')}</td>
                    <td>${escapeHtml(row.slot || 'N/A')}</td>
                `;
                tbody.appendChild(tr);
            });
            document.getElementById('json_results_input').value = JSON.stringify(allResults);
            uploadSection.style.display = 'none';
            resultsSection.style.display = 'block';
        }
        function escapeHtml(unsafe) {
            return unsafe ? unsafe.toString().replace(/&/g, "&").replace(/</g, "<").replace(/>/g, ">").replace(/"/g, """).replace(/'/g, "'") : '';
        }
    }
</script>
</body>
</html>