<?php
// Maps to convert codes to their full text meaning for displaying in the preview
const DISPOSITION_MAP = [
    '11' => 'Interested', '12' => 'Not Interested', '13' => 'Call Back', '14' => 'Follow Up',
    '15' => 'Info Shared', '16' => 'Language Barrier', '17' => 'Call Dropped', '21' => 'Ringing',
    '22' => 'Switched Off', '23' => 'Invalid Number', '24' => 'Out of Service', '25' => 'Wrong Number', '26' => 'Busy',
];
const CONNECTIVITY_MAP = [ 'Y' => 'Yes', 'N' => 'No' ];

$results = [];
$error = '';
$original_filename = '';

// --- PHP Backend Logic (No changes needed here) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['markedSheet'])) {
    $uploadDir = 'marked_uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $original_filename = basename($_FILES['markedSheet']['name']);
    $targetFile = $uploadDir . uniqid() . '-' . $original_filename;
    
    if (move_uploaded_file($_FILES['markedSheet']['tmp_name'], $targetFile)) {
        $command = "python gemini_omr_parser.py " . escapeshellarg($targetFile);
        $output = shell_exec($command . " 2>&1");
        $error_check = json_decode($output, true);

        if ($error_check && isset($error_check['error'])) {
            $error = "Python script failed: " . $error_check['error'];
        } elseif (empty($output)) {
            $error = "Python script produced no output. Check server logs and script permissions.";
        } else {
            $csv_response = trim($output);
            $lines = explode("\n", trim($csv_response));
            $headers = str_getcsv(array_shift($lines));
            
            if ($headers && isset($headers[0]) && $headers[0] === 'row_index') {
                 foreach ($lines as $line) {
                    if(trim($line)){ $results[] = array_combine($headers, str_getcsv($line)); }
                }
            } else { $error = "The AI response was not in the expected CSV format. Raw response: " . htmlspecialchars($output); }
        }
        
        unlink($targetFile);
    } else { $error = "Sorry, there was an error uploading your file."; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capture & Process Marked Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* NEW STYLES FOR CAMERA PREVIEW */
        .camera-container {
            border: 2px dashed #0d6efd;
            border-radius: 0.5rem;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            background-color: #f8f9fa;
            transition: background-color 0.2s;
        }
        .camera-container:hover {
            background-color: #e9ecef;
        }
        #imagePreview {
            max-width: 100%;
            max-height: 400px; /* Limit preview height */
            border-radius: 0.5rem;
            margin-top: 1rem;
            border: 1px solid #dee2e6;
        }
        .form-label-icon {
            font-size: 3rem;
            color: #0d6efd;
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h2 class="h4 mb-0">Capture Marked Sheet</h2>
            <a href="index.php" class="btn btn-light btn-sm">Back to Dashboard</a>
        </div>
        <div class="card-body">
            <!-- The form action remains the same, but the content is updated -->
            <form id="captureForm" action="process_with_gemini_python.php" method="post" enctype="multipart/form-data">
                
                <!-- This label wraps the input, making the whole area clickable -->
                <label for="markedSheet" class="camera-container" id="cameraLabel">
                    <div class="form-label-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" fill="currentColor" class="bi bi-camera-fill" viewBox="0 0 16 16">
                            <path d="M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0"/>
                            <path d="M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4H2Zm.5 4a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                        </svg>
                    </div>
                    <h5 class="mt-2">Tap here to open Camera</h5>
                    <p class="text-muted mb-0">Capture a clear, well-lit photo of the entire sheet.</p>
                </label>
                
                <!-- THE IMPORTANT CHANGE IS HERE -->
                <!-- 'capture="environment"' prefers the rear camera. 'accept="image/*"' restricts to images. -->
                <input class="form-control d-none" type="file" name="markedSheet" id="markedSheet" accept="image/*" capture="environment" required>
                
                <!-- Container for the image preview -->
                <div id="previewContainer" class="text-center mt-3" style="display: none;">
                    <img id="imagePreview" src="#" alt="Image Preview"/>
                    <p class="mt-2 text-muted">Image captured. Click Process to continue.</p>
                </div>

                <div class="d-grid mt-4">
                    <button id="submitButton" type="submit" class="btn btn-primary btn-lg" disabled>Process with AI</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Results section remains the same -->
    <?php if ($error): ?><div class="alert alert-danger mt-4" style="white-space: pre-wrap;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!empty($results)): ?>
    <div class="card mt-5 shadow-sm">
        <div class="card-header"><h3>AI Processing Results for: <?= htmlspecialchars($original_filename) ?></h3></div>
        <div class="card-body">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Row on Sheet</th><th>Connectivity</th><th>Disposition</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['row_index']) ?></td>
                            <td><?= htmlspecialchars(CONNECTIVITY_MAP[$row['connectivity_code']] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(DISPOSITION_MAP[$row['disposition_code']] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form action="save_interpreted_data.php" method="post" class="mt-3">
                <input type="hidden" name="json_results" value='<?= htmlspecialchars(json_encode($results), ENT_QUOTES, 'UTF-8') ?>'>
                <input type="hidden" name="original_filename" value="<?= htmlspecialchars($original_filename) ?>">
                <button type="submit" class="btn btn-success">Save Results to Database</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- NEW JAVASCRIPT FOR PREVIEW -->
<script>
    const markedSheetInput = document.getElementById('markedSheet');
    const imagePreview = document.getElementById('imagePreview');
    const previewContainer = document.getElementById('previewContainer');
    const cameraLabel = document.getElementById('cameraLabel');
    const submitButton = document.getElementById('submitButton');

    markedSheetInput.addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            // Create a URL for the captured image
            const reader = new FileReader();
            reader.onload = function(e) {
                imagePreview.src = e.target.result;
            }
            reader.readAsDataURL(file);

            // Show the preview and hide the camera prompt
            previewContainer.style.display = 'block';
            cameraLabel.style.display = 'none';

            // Enable the submit button
            submitButton.disabled = false;
        }
    });
</script>

</body>
</html>