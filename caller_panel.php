<?php
session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'caller_sheet3');

// --- SIMPLIFIED CAPTCHA LOGIC ---
if (isset($_GET['action']) && $_GET['action'] == 'captcha') {
    header('Content-Type: image/png');
    
    // Simple math problem captcha
    $num1 = rand(1, 9);
    $num2 = rand(1, 9);
    $answer = $num1 + $num2;
    $text = $num1 . ' + ' . $num2 . ' = ?';
    $_SESSION['captcha_answer'] = (string)$answer;

    $image = imagecreatetruecolor(180, 60);
    imageantialias($image, true);

    // Simple background
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $blue = imagecolorallocate($image, 0, 100, 200);
    
    imagefill($image, 0, 0, $white);

    // Simple text without complex effects
    $font_size = 5;
    $x = (180 - (imagefontwidth($font_size) * strlen($text))) / 2;
    $y = (60 - imagefontheight($font_size)) / 2;
    imagestring($image, $font_size, $x, $y, $text, $blue);
    
    // Add simple border
    imagerectangle($image, 0, 0, 179, 59, $black);
    
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
        #imagePreviewContainer img { max-width: 100%; max-height: 200px; border-radius: .5rem; border: 2px solid #0dcaf0; margin: 5px; }
        .form-label-icon{font-size:3rem;color:#0dcaf0}
        .panel-card { transition: all 0.3s ease; border: none; }
        .panel-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .captcha-img { border-radius: .5rem; border: 1px solid #dee2e6; cursor: pointer; }
        .image-preview-item { position: relative; display: inline-block; margin: 5px; }
        .image-preview-item .remove-image { position: absolute; top: -5px; right: -5px; background: red; color: white; border: none; border-radius: 50%; width: 25px; height: 25px; cursor: pointer; font-size: 12px; }
        .progress-container { display: none; margin-top: 20px; }
        .progress-container.active { display: block; }
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
                            <label class="form-label">Solve the math problem shown in the image</label>
                            <div class="d-flex gap-3">
                                <img src="caller_panel.php?action=captcha&t=<?= time() ?>" alt="Captcha Image" class="captcha-img" onclick="this.src='caller_panel.php?action=captcha&t='+new Date().getTime()">
                                <input type="text" class="form-control" id="captcha" name="captcha" placeholder="Enter answer" required autocomplete="off">
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
                <div class="card-body p-4 d-flex flex-column justify-content-center">
                    <h3 class="card-title"><i class="bi bi-camera2 fs-1 text-info"></i><br>Upload Marked Sheet(s)</h3>
                    <p class="text-muted mt-2">Process multiple sheets by uploading their photos. You can select multiple images at once.</p>
                    <button class="btn btn-info text-white mt-auto" id="startUploadBtn">Start Upload</button>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card panel-card text-center h-100 shadow-sm">
                <div class="card-body p-4 d-flex flex-column justify-content-center">
                    <h3 class="card-title"><i class="bi bi-bar-chart-line-fill fs-1 text-success"></i><br>View Performance</h3>
                    <p class="text-muted mt-2">Check your call statistics and recent activity.</p>
                    <a href='improved_caller_performance.php' class='btn btn-success mt-auto'>View Performance</a>
                </div>
            </div>
        </div>
    </div>
    
    <div id="upload-section" class="card shadow-sm mt-4" style="display: none;">
        <div class="card-header">
            <h3 class="h5 mb-0">Upload Marked Sheets</h3>
            <p class="mb-0 text-muted small">Add multiple photos, then process them all at once</p>
        </div>
        <div class="card-body">
            <!-- Photo collection section -->
            <div id="photo-collection-section">
                <label for="singlePhoto" class="camera-container" id="cameraLabel">
                    <div class="form-label-icon"><i class="bi bi-camera2"></i></div>
                    <h5 class="mt-2 text-info">Tap to add photo</h5>
                    <p class="text-muted">Add photos one by one, then process all together</p>
                </label>
                <input class="form-control d-none" type="file" name="singlePhoto" id="singlePhoto" accept="image/*" capture="environment">
                
                <!-- Collected photos display -->
                <div id="collectedPhotos" class="mt-3" style="display: none;">
                    <div class="alert alert-info">
                        <i class="bi bi-images me-2"></i><span id="photoCount">0</span> photo(s) added
                    </div>
                    <div id="photoThumbnails" class="row g-2 mb-3"></div>
                    
                    <div class="d-grid gap-2">
                        <button id="addMoreButton" type="button" class="btn btn-outline-info btn-lg">
                            <i class="bi bi-plus-circle me-2"></i>Add More Photos
                        </button>
                        <button id="processAllButton" type="button" class="btn btn-success btn-lg">
                            <i class="bi bi-magic me-2"></i>Process All Photos with AI
                        </button>
                        <button id="clearAllButton" type="button" class="btn btn-outline-danger btn-lg">
                            <i class="bi bi-trash me-2"></i>Clear All Photos
                        </button>
                    </div>
                </div>

                <!-- Processing all photos indicator -->
                <div id="processingAllIndicator" class="text-center mt-3" style="display: none;">
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="progressBar" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <p class="text-info" id="progressText">Processing photos...</p>
                    <div id="processing-feedback" class="alert alert-info mt-3"></div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="results-section" class="card mt-4 shadow-sm" style="display:none;">
        <div class="card-header">
            <h3><i class="bi bi-clipboard-check-fill me-2"></i>AI Processing Results</h3>
            <p class="mb-0 text-muted">Review the combined data from all images. If correct, click "Confirm & Save" to log these results.</p>
        </div>
        <div class="card-body">
            <div class="alert alert-success mb-3">
                <i class="bi bi-check-circle-fill me-2"></i>Successfully processed <span id="total-images-processed">0</span> image(s) with <span id="total-records-found">0</span> total records found.
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light">
                        <tr>
                            <th>Record ID</th>
                            <th>Customer Name</th>
                            <th>Mobile No</th>
                            <th>Connectivity</th>
                            <th>Disposition</th>
                            <th>Slot</th>
                            <th>Image #</th>
                        </tr>
                    </thead>
                    <tbody id="results-tbody"></tbody>
                </table>
            </div>
            <form action="save_final_log.php" method="post" class="mt-3">
                <input type="hidden" name="json_results" id="json_results_input">
                <input type="hidden" name="finqy_id" value="<?= htmlspecialchars($_SESSION['finqy_id']) ?>">
                <button type="submit" class="btn btn-success"><i class="bi bi-save-fill me-2"></i>Confirm & Save All</button>
                <a href="caller_panel.php" class="btn btn-secondary">Cancel and Start Over</a>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    if (document.getElementById('singlePhoto')) {
        // Elements
        const startUploadBtn = document.getElementById('startUploadBtn');
        const mainOptions = document.getElementById('main-options');
        const uploadSection = document.getElementById('upload-section');
        const resultsSection = document.getElementById('results-section');
        
        // New photo collection workflow elements
        const singlePhotoInput = document.getElementById('singlePhoto');
        const cameraLabel = document.getElementById('cameraLabel');
        const collectedPhotos = document.getElementById('collectedPhotos');
        const photoCount = document.getElementById('photoCount');
        const photoThumbnails = document.getElementById('photoThumbnails');
        const addMoreButton = document.getElementById('addMoreButton');
        const processAllButton = document.getElementById('processAllButton');
        const clearAllButton = document.getElementById('clearAllButton');
        const processingAllIndicator = document.getElementById('processingAllIndicator');
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
        const feedbackDiv = document.getElementById('processing-feedback');
        
        // Data storage
        let collectedFiles = [];
        let allResults = [];
        
        // Start the upload workflow
        startUploadBtn.addEventListener('click', () => {
            mainOptions.style.display = 'none';
            uploadSection.style.display = 'block';
            resetToInitialState();
        });
        
        // Handle photo selection
        singlePhotoInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                addPhotoToCollection(file);
                singlePhotoInput.value = ''; // Reset input to allow same file again
            }
        });
        
        function addPhotoToCollection(file) {
            collectedFiles.push(file);
            updatePhotoDisplay();
            
            // Hide camera label and show collection
            cameraLabel.style.display = 'none';
            collectedPhotos.style.display = 'block';
        }
        
        function updatePhotoDisplay() {
            photoCount.textContent = collectedFiles.length;
            photoThumbnails.innerHTML = '';
            
            collectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = (event) => {
                    const colDiv = document.createElement('div');
                    colDiv.className = 'col-4 col-md-3';
                    colDiv.innerHTML = `
                        <div class="position-relative">
                            <img src="${event.target.result}" class="img-fluid rounded border" style="height: 80px; width: 100%; object-fit: cover;" alt="Photo ${index + 1}">
                            <button class="btn btn-sm btn-danger position-absolute top-0 end-0 rounded-circle" 
                                    style="width: 25px; height: 25px; padding: 0; margin: -5px;" 
                                    onclick="removePhoto(${index})" type="button">
                                <i class="bi bi-x" style="font-size: 12px;"></i>
                            </button>
                            <small class="d-block text-center mt-1">Photo ${index + 1}</small>
                        </div>
                    `;
                    photoThumbnails.appendChild(colDiv);
                };
                reader.readAsDataURL(file);
            });
        }
        
        // Make removePhoto function global so it can be called from HTML
        window.removePhoto = function(index) {
            collectedFiles.splice(index, 1);
            updatePhotoDisplay();
            
            if (collectedFiles.length === 0) {
                resetToInitialState();
            }
        };
        
        // Add more photos
        addMoreButton.addEventListener('click', () => {
            singlePhotoInput.click();
        });
        
        // Clear all photos
        clearAllButton.addEventListener('click', () => {
            collectedFiles = [];
            allResults = [];
            resetToInitialState();
        });
        
        // Process all photos
        processAllButton.addEventListener('click', async () => {
            if (collectedFiles.length === 0) return;
            
            // Hide photo collection and show processing
            collectedPhotos.style.display = 'none';
            processingAllIndicator.style.display = 'block';
            
            allResults = [];
            const totalFiles = collectedFiles.length;
            let processedCount = 0;
            let successCount = 0;
            
            for (let i = 0; i < collectedFiles.length; i++) {
                const file = collectedFiles[i];
                const imageNum = i + 1;
                
                // Update progress
                const percent = (processedCount / totalFiles) * 100;
                updateProgressBar(percent);
                progressText.textContent = `Processing photo ${imageNum} of ${totalFiles}...`;
                feedbackDiv.innerHTML = `<i class="bi bi-hourglass-split me-2"></i>AI is analyzing photo ${imageNum}...`;
                
                const formData = new FormData();
                formData.append('markedSheet', file);
                
                try {
                    const response = await fetch('ajax_process_image.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success && result.data && result.data.length > 0) {
                        // Add image number to each result
                        result.data.forEach(row => {
                            row.image_number = imageNum;
                        });
                        allResults = allResults.concat(result.data);
                        successCount++;
                    }
                } catch (error) {
                    console.error(`Error processing photo ${imageNum}:`, error);
                }
                
                processedCount++;
                updateProgressBar((processedCount / totalFiles) * 100);
            }
            
            // Processing complete
            if (allResults.length > 0) {
                progressText.textContent = `Processing complete! ${successCount} photo(s) processed successfully.`;
                feedbackDiv.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>AI processing complete! Found ${allResults.length} records. Auto-saving to database...`;
                
                setTimeout(() => {
                    // Automatically save the data without showing the table
                    autoSaveResults(allResults);
                }, 2000); // Show success for 2 seconds before auto-saving
            } else {
                feedbackDiv.className = 'alert alert-danger mt-3';
                feedbackDiv.innerHTML = `<i class="bi bi-x-circle-fill me-2"></i>No data could be extracted from the photos. Please ensure the images are clear and try again.`;
                
                setTimeout(() => {
                    // Go back to photo collection
                    processingAllIndicator.style.display = 'none';
                    collectedPhotos.style.display = 'block';
                }, 3000);
            }
        });
        
        function updateProgressBar(percent) {
            progressBar.style.width = percent + '%';
            progressBar.textContent = Math.round(percent) + '%';
        }
        
        function resetToInitialState() {
            cameraLabel.style.display = 'block';
            collectedPhotos.style.display = 'none';
            processingAllIndicator.style.display = 'none';
            collectedFiles = [];
            allResults = [];
        }
        
        function displayFinalResults(results) {
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
                    <td><span class="badge bg-info">Photo ${row.image_number}</span></td>
                `;
                tbody.appendChild(tr);
            });
            
            document.getElementById('json_results_input').value = JSON.stringify(results);
        }
        
        function escapeHtml(unsafe) {
            return unsafe ? unsafe.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;") : '';
        }
        
        async function autoSaveResults(results) {
            try {
                // Show saving message
                feedbackDiv.innerHTML = `<i class="bi bi-cloud-upload-fill me-2"></i>Saving ${results.length} records to database...`;
                
                // Prepare the form data
                const formData = new FormData();
                formData.append('json_results', JSON.stringify(results));
                formData.append('finqy_id', '<?= htmlspecialchars($_SESSION['finqy_id']) ?>');
                
                // Send the data to save_final_log.php
                const response = await fetch('save_final_log.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Show success message with tick symbol
                    feedbackDiv.className = 'alert alert-success mt-3';
                    feedbackDiv.innerHTML = `
                        <div class="text-center">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                            <h4 class="mt-2">Data Saved Successfully!</h4>
                            <p class="mb-0">${result.message}</p>
                        </div>
                    `;
                    
                    // Reset to initial state after 3 seconds
                    setTimeout(() => {
                        resetToInitialState();
                        uploadSection.style.display = 'none';
                        mainOptions.style.display = 'block';
                    }, 3000);
                } else {
                    throw new Error(result.message || 'Failed to save data');
                }
            } catch (error) {
                console.error('Error saving data:', error);
                feedbackDiv.className = 'alert alert-danger mt-3';
                feedbackDiv.innerHTML = `<i class="bi bi-x-circle-fill me-2"></i>Error saving data. Please try again.`;
            }
        }
    }
</script>
</body>
</html>