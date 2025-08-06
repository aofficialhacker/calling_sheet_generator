<?php
session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet3');

// --- NEW IMAGE CAPTCHA LOGIC ---
if (isset($_GET['action']) && $_GET['action'] == 'captcha') {
    header('Content-Type: image/png');
    $text = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);
    $_SESSION['captcha_answer'] = $text;

    $image = imagecreatetruecolor(150, 50);
    imageantialias($image, true);

    $colors = [];
    $red = rand(125, 175);
    $green = rand(125, 175);
    $blue = rand(125, 175);
    for ($i = 0; $i < 5; $i++) {
        $colors[] = imagecolorallocate($image, $red - 20 * $i, $green - 20 * $i, $blue - 20 * $i);
    }

    imagefill($image, 0, 0, $colors[0]);

    for ($i = 0; $i < 10; $i++) {
        imagesetthickness($image, rand(2, 10));
        $rect_color = $colors[rand(1, 4)];
        imagerectangle($image, rand(-10, 140), rand(-10, 40), rand(-10, 140), rand(-10, 40), $rect_color);
    }

    $black = imagecolorallocate($image, 0, 0, 0);
    $white = imagecolorallocate($image, 255, 255, 255);
    $textcolors = [$black, $white];
    $fonts = [__DIR__ . '/fonts/Acme-Regular.ttf', __DIR__ . '/fonts/Ubuntu-Regular.ttf'];

    if (!is_dir(__DIR__ . '/fonts') || count(glob(__DIR__ . '/fonts/*.ttf')) == 0) {
        $font_size = 5;
        $x = (150 - (imagefontwidth($font_size) * strlen($text))) / 2;
        $y = (50 - imagefontheight($font_size)) / 2;
        imagestring($image, $font_size, $x, $y, $text, $white);
    } else {
        $font_path = $fonts[array_rand($fonts)];
        for ($i = 0; $i < strlen($text); $i++) {
            $letter_space = 140 / strlen($text);
            $initial = 15;
            imagettftext($image, 24, rand(-15, 15), $initial + $i * $letter_space, rand(25, 45), $textcolors[rand(0, 1)], $font_path, $text[$i]);
        }
    }
    
    imagepng($image);
    imagedestroy($image);
    exit();
}

// --- LOGIN LOGIC ---
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['finqy_id'])) {
    if (isset($_POST['captcha'], $_SESSION['captcha_answer']) && strcasecmp($_POST['captcha'], $_SESSION['captcha_answer']) == 0) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) { die("DB Connection Failed."); }
        
        // Updated query to check if the refercode exists in callers table
        $stmt = $conn->prepare("SELECT finqy_id, caller_name, caller_type FROM callers WHERE finqy_id = ? AND is_active = 1");
        $stmt->bind_param("s", $_POST['finqy_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $_SESSION['finqy_id'] = $user['finqy_id'];
            $_SESSION['caller_name'] = $user['caller_name'];
            $_SESSION['caller_type'] = $user['caller_type'];
            unset($_SESSION['captcha_answer']);
        } else {
            $login_error = "Invalid or inactive Refercode/FinqyID.";
        }
        $stmt->close();
        $conn->close();
    } else {
        $login_error = "Incorrect CAPTCHA answer. Please try again.";
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_unset();
    session_destroy();
    header("Location: caller_panel.php");
    exit();
}

// Fetch disposition maps from the database for dynamic legends
$conn_maps = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$dispositions = $conn_maps->query("SELECT code, description FROM disposition_codes WHERE is_active = 1 ORDER BY code");
$disposition_map = [];
while($row = $dispositions->fetch_assoc()) {
    $disposition_map[$row['code']] = $row['description'];
}
$conn_maps->close();

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
        #imagePreviewContainer img { max-width: 100%; max-height: 400px; border-radius: .5rem; border: 2px solid #0dcaf0; }
        .form-label-icon{font-size:3rem;color:#0dcaf0}
        .panel-card { transition: all 0.3s ease; border: none; }
        .panel-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .captcha-img { border-radius: .5rem; border: 1px solid #dee2e6; cursor: pointer; }
    </style>
</head>
<body>
<div class="container mt-4 mb-5">
    <?php if (!isset($_SESSION['finqy_id'])): // --- SHOW LOGIN FORM --- ?>
    <div class="row justify-content-center">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-5">
                    <div class="text-center mb-4"><h1 class="h3 fw-bold">Caller Panel Login</h1><p class="text-muted">Enter your Refercode/FinqyID and solve the problem</p></div>
                    <form action="caller_panel.php" method="POST">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="finqy_id" name="finqy_id" placeholder="e.g., ABCD1234" required>
                            <label for="finqy_id">Refercode/FinqyID</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Enter the text from the image</label>
                            <div class="d-flex gap-3">
                                <img src="caller_panel.php?action=captcha&t=<?= time() ?>" alt="Captcha Image" class="captcha-img" onclick="this.src='caller_panel.php?action=captcha&t='+new Date().getTime()">
                                <input type="text" class="form-control" id="captcha" name="captcha" placeholder="CAPTCHA" required autocomplete="off">
                            </div>
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
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-telephone-inbound-fill me-2"></i>Caller Panel</h1>
            <span class="text-muted">Welcome, <?= htmlspecialchars($_SESSION['caller_name']) ?>! 
                <span class="badge bg-info"><?= ucfirst($_SESSION['caller_type']) ?></span>
            </span>
        </div>
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
                    <div class="form-label-icon"><i class="bi bi-camera2"></i></div><h5 class="mt-2 text-info">Tap to open Camera</h5>
                </label>
                <input class="form-control d-none" type="file" name="markedSheet" id="markedSheet" accept="image/*" capture="environment" required>
                <div id="imagePreviewContainer" class="text-center mt-3"></div>
                <div id="processing-feedback" class="alert alert-info mt-3" style="display: none;"></div>
                <div class="d-grid gap-2 mt-4">
                    <button id="submitButton" type="submit" class="btn btn-info btn-lg text-white" disabled><i class="bi bi-magic me-2"></i>Process with AI</button>
                    <button id="retakeButton" type="button" class="btn btn-secondary btn-lg" style="display: none;"><i class="bi bi-camera me-2"></i>Retake Photo</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="results-section" class="card mt-4 shadow-sm" style="display:none;">
        <div class="card-header"><h3><i class="bi bi-clipboard-check-fill me-2"></i>AI Processing Results</h3><p class="mb-0 text-muted">Please review the data. If correct, click "Confirm & Save" to log these results.</p></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light"><tr><th>Record ID</th><th>Customer Name</th><th>Mobile No</th><th>Connectivity</th><th>Disposition</th><th>Slot</th></tr></thead>
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
        const retakeButton = document.getElementById('retakeButton');
        let currentImage = null;
        
        startUploadBtn.addEventListener('click', () => {
            mainOptions.style.display = 'none';
            uploadSection.style.display = 'block';
        });
        
        markedSheetInput.addEventListener('change', (e) => {
            imagePreviewContainer.innerHTML = '';
            const file = e.target.files[0];
            if (file) {
                currentImage = file;
                const reader = new FileReader();
                reader.onload = (event) => {
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    imagePreviewContainer.appendChild(img);
                }
                reader.readAsDataURL(file);
                cameraLabel.style.display = 'none';
                submitButton.disabled = false;
                retakeButton.style.display = 'block';
            } else {
                cameraLabel.style.display = 'block';
                submitButton.disabled = true;
                retakeButton.style.display = 'none';
            }
        });
        
        retakeButton.addEventListener('click', () => {
            markedSheetInput.value = '';
            imagePreviewContainer.innerHTML = '';
            cameraLabel.style.display = 'block';
            submitButton.disabled = true;
            retakeButton.style.display = 'none';
            currentImage = null;
        });
        
        captureForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!currentImage) return;
            
            submitButton.disabled = true;
            retakeButton.disabled = true;
            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing with AI...`;
            feedbackDiv.style.display = 'block';
            feedbackDiv.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>AI is analyzing your marked sheet...';
            
            const formData = new FormData();
            formData.append('markedSheet', currentImage);
            
            try {
                const response = await fetch('ajax_process_image.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success && result.data && result.data.length > 0) {
                    feedbackDiv.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>AI processing complete! Review the results below.';
                    displayResults(result.data);
                } else {
                    feedbackDiv.className = 'alert alert-danger mt-3';
                    feedbackDiv.innerHTML = `<i class="bi bi-x-circle-fill me-2"></i>${result.message || 'AI could not read the marked sheet. Please ensure the image is clear and try again.'}`;
                    submitButton.disabled = false;
                    retakeButton.disabled = false;
                    submitButton.innerHTML = `<i class="bi bi-magic me-2"></i>Process with AI`;
                }
            } catch (error) {
                feedbackDiv.className = 'alert alert-danger mt-3';
                feedbackDiv.innerHTML = '<i class="bi bi-wifi-off me-2"></i>Network error occurred. Please check your connection and try again.';
                submitButton.disabled = false;
                retakeButton.disabled = false;
                submitButton.innerHTML = `<i class="bi bi-magic me-2"></i>Process with AI`;
            }
        });
        function displayResults(results) {
            const tbody = document.getElementById('results-tbody');
            const dispoMap = <?= json_encode($disposition_map) ?>;
            const connMap = <?= json_encode(CONNECTIVITY_MAP) ?>;
            tbody.innerHTML = '';
            results.forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><code>${escapeHtml(row.record_id || 'N/A')}</code></td>
                    <td>${escapeHtml(row.customer_name || 'Not Found')}</td>
                    <td>${escapeHtml(row.mobile_no || 'N/A')}</td>
                    <td>${escapeHtml(connMap[row.connectivity_code] || 'N/A')}</td>
                    <td>${escapeHtml(dispoMap[row.disposition_code] || 'Empty')}</td>
                    <td>${escapeHtml(row.slot || 'N/A')}</td>
                `;
                tbody.appendChild(tr);
            });
            document.getElementById('json_results_input').value = JSON.stringify(results);
            uploadSection.style.display = 'none';
            resultsSection.style.display = 'block';
        }
        function escapeHtml(unsafe) {
            return unsafe ? unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;") : '';
        }
    }
</script>
</body>
</html>