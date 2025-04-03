<?php
// image_uploader.php - Advanced image uploader with SEO options
session_start();

// Include the analytics tracker
require_once 'analytics_tracker.php';
$tracker = new AnalyticsTracker();

// Configuration
$upload_dir = "uploads/";
$db_file = "image_metadata.json";
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$max_file_size = 5 * 1024 * 1024; // 5MB

// Create directories if they don't exist
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Initialize or load the database
if (!file_exists($db_file)) {
    file_put_contents($db_file, json_encode([]));
}
$db = json_decode(file_get_contents($db_file), true);

$message = '';
$error = '';
$uploaded_image = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $file = $_FILES['image'];
    
    // Check for errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error = "File is too large. Maximum size is " . ($max_file_size / 1024 / 1024) . "MB.";
                break;
            case UPLOAD_ERR_PARTIAL:
                $error = "The file was only partially uploaded.";
                break;
            case UPLOAD_ERR_NO_FILE:
                $error = "No file was uploaded.";
                break;
            default:
                $error = "Unknown error occurred during upload.";
        }
    } elseif ($file['size'] > $max_file_size) {
        $error = "File is too large. Maximum size is " . ($max_file_size / 1024 / 1024) . "MB.";
    } else {
        // Validate file type
        $file_info = pathinfo($file['name']);
        $extension = strtolower($file_info['extension']);
        
        if (!in_array($extension, $allowed_extensions)) {
            $error = "Invalid file type. Allowed types: " . implode(', ', $allowed_extensions);
        } else {
            // Get SEO metadata
            $custom_filename = isset($_POST['filename']) && !empty($_POST['filename']) 
                ? preg_replace('/[^a-zA-Z0-9_-]/', '-', $_POST['filename']) 
                : preg_replace('/[^a-zA-Z0-9_-]/', '-', $file_info['filename']);
            
            $title = isset($_POST['title']) ? $_POST['title'] : '';
            $description = isset($_POST['description']) ? $_POST['description'] : '';
            $tags = isset($_POST['tags']) ? $_POST['tags'] : '';
            $tags_array = array_map('trim', explode(',', $tags));
            
            // Generate safe filename
            $filename = $custom_filename . '.' . $extension;
            $counter = 1;
            
            // Check if file exists, append number if it does
            while (file_exists($upload_dir . $filename)) {
                $filename = $custom_filename . '-' . $counter . '.' . $extension;
                $counter++;
            }
            
            $target_path = $upload_dir . $filename;
            
            // Move the file
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                // Get image dimensions
                list($width, $height) = getimagesize($target_path);
                
                // Store metadata
                $image_id = uniqid();
                $metadata = [
                    'id' => $image_id,
                    'filename' => $filename,
                    'original_name' => $file['name'],
                    'path' => $target_path,
                    'url' => $target_path,
                    'title' => $title,
                    'description' => $description,
                    'tags' => $tags_array,
                    'size' => $file['size'],
                    'width' => $width,
                    'height' => $height,
                    'type' => $file['type'],
                    'uploaded_at' => date('Y-m-d H:i:s'),
                    'ip' => $_SERVER['REMOTE_ADDR']
                ];
                
                $db[] = $metadata;
                file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
                
                // Track the upload in analytics
                $tracker->trackImageView($image_id, $target_path, $metadata);
                
                $message = "Image uploaded successfully!";
                $uploaded_image = $metadata;
            } else {
                $error = "Failed to move uploaded file.";
            }
        }
    }
}

// Get recently uploaded images (last 10)
$recent_images = array_slice(array_reverse($db), 0, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Uploader with SEO Options</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f9f9f9;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="file"],
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .description {
            font-size: 14px;
            color: #666;
            margin-top: 4px;
        }
        .submit-btn {
            background-color: #4285F4;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            display: block;
            margin: 20px auto;
        }
        .submit-btn:hover {
            background-color: #3367D6;
        }
        .message {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .image-preview {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        .image-preview img {
            max-width: 100%;
            max-height: 300px;
            display: block;
            margin: 0 auto 15px;
            border-radius: 4px;
        }
        .image-metadata {
            margin-bottom: 10px;
        }
        .metadata-item {
            margin-bottom: 5px;
        }
        .label {
            font-weight: bold;
            display: inline-block;
            width: 120px;
        }
        .recent-images {
            margin-top: 30px;
        }
        .recent-images h2 {
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }
        .image-card {
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
            transition: transform 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .image-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .image-card img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }
        .image-card .title {
            padding: 8px;
            font-size: 14px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            background-color: #f8f9fa;
        }
        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }
        .tag {
            background-color: #e1ecf4;
            color: #39739d;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        #drag-area {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        #drag-area.active {
            border-color: #4285F4;
            background-color: #f0f8ff;
        }
        #drag-area p {
            margin: 0;
            color: #666;
        }
        .nav-links {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .nav-links a {
            text-decoration: none;
            color: #4285F4;
            font-weight: bold;
        }
        .nav-links a:hover {
            text-decoration: underline;
        }
        .stats-badge {
            display: inline-block;
            background-color: #E8F5E9;
            color: #388E3C;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 8px;
        }
        .analytics-link {
            background-color: #34A853;
            color: white;
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 14px;
            margin-left: auto;
        }
        .analytics-link:hover {
            background-color: #2E7D32;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="index.php">← Back to Search</a>
            <a href="analytics_dashboard.php" class="analytics-link">📊 Analytics</a>
        </div>
        
        <h1>Image Uploader with SEO Options</h1>
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form action="" method="POST" enctype="multipart/form-data">
            <div id="drag-area">
                <p>Drag & drop an image here or click to select</p>
            </div>
            
            <div class="form-group">
                <label for="image">Select Image:</label>
                <input type="file" id="image" name="image" accept="image/*" required>
                <div class="description">Allowed file types: JPG, JPEG, PNG, GIF, WEBP. Max size: 5MB</div>
            </div>
            
            <div class="form-group">
                <label for="filename">Custom Filename:</label>
                <input type="text" id="filename" name="filename" placeholder="Enter custom filename (without extension)">
                <div class="description">Leave blank to use original filename. Only letters, numbers, underscore and dash allowed.</div>
            </div>
            
            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" id="title" name="title" placeholder="Enter image title">
                <div class="description">A descriptive title for SEO purposes.</div>
            </div>
            
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" rows="3" placeholder="Enter image description"></textarea>
                <div class="description">Describe what's in the image for better search results.</div>
            </div>
            
            <div class="form-group">
                <label for="tags">Tags:</label>
                <input type="text" id="tags" name="tags" placeholder="Enter tags separated by commas">
                <div class="description">Add relevant keywords to make your image discoverable.</div>
            </div>
            
            <button type="submit" class="submit-btn">Upload Image</button>
        </form>
        
        <?php if ($uploaded_image): ?>
            <div class="image-preview">
                <h2>Uploaded Image</h2>
                <img src="<?= htmlspecialchars($uploaded_image['url']) ?>" alt="<?= htmlspecialchars($uploaded_image['title']) ?>">
                
                <div class="image-metadata">
                    <div class="metadata-item">
                        <span class="label">Filename:</span> 
                        <span><?= htmlspecialchars($uploaded_image['filename']) ?></span>
                    </div>
                    <div class="metadata-item">
                        <span class="label">Title:</span> 
                        <span><?= htmlspecialchars($uploaded_image['title']) ?></span>
                    </div>
                    <div class="metadata-item">
                        <span class="label">Description:</span> 
                        <span><?= htmlspecialchars($uploaded_image['description']) ?></span>
                    </div>
                    <div class="metadata-item">
                        <span class="label">Dimensions:</span> 
                        <span><?= $uploaded_image['width'] ?> x <?= $uploaded_image['height'] ?> pixels</span>
                    </div>
                    <div class="metadata-item">
                        <span class="label">Size:</span> 
                        <span><?= round($uploaded_image['size'] / 1024, 2) ?> KB</span>
                    </div>
                    <div class="metadata-item">
                        <span class="label">Uploaded:</span> 
                        <span><?= htmlspecialchars($uploaded_image['uploaded_at']) ?></span>
                    </div>
                    <div class="metadata-item">
                        <span class="label">Tags:</span> 
                        <div class="tags">
                            <?php foreach ($uploaded_image['tags'] as $tag): ?>
                                <?php if (!empty($tag)): ?>
                                    <span class="tag"><?= htmlspecialchars($tag) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($recent_images)): ?>
            <div class="recent-images">
                <h2>Recently Uploaded Images</h2>
                <div class="image-grid">
                    <?php foreach ($recent_images as $image): 
                        // Get view count from analytics if available
                        $view_count = 0;
                        if (file_exists('image_stats.json')) {
                            $stats = json_decode(file_get_contents('image_stats.json'), true);
                            if (isset($stats['images'][$image['id']]['views'])) {
                                $view_count = $stats['images'][$image['id']]['views'];
                            }
                        }
                    ?>
                        <div class="image-card">
                            <a href="index.php?page=images&q=<?= urlencode($image['title'] ?: $image['filename']) ?>">
                                <img src="<?= htmlspecialchars($image['url']) ?>" alt="<?= htmlspecialchars($image['title']) ?>">
                                <div class="title">
                                    <?= htmlspecialchars($image['title'] ?: $image['filename']) ?>
                                    <?php if ($view_count > 0): ?>
                                        <span class="stats-badge"><?= $view_count ?> views</span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Drag and drop functionality
        const dragArea = document.getElementById('drag-area');
        const fileInput = document.getElementById('image');
        
        dragArea.addEventListener('click', () => {
            fileInput.click();
        });
        
        dragArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            dragArea.classList.add('active');
        });
        
        dragArea.addEventListener('dragleave', () => {
            dragArea.classList.remove('active');
        });
        
        dragArea.addEventListener('drop', (e) => {
            e.preventDefault();
            dragArea.classList.remove('active');
            
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                // Show filename in drag area
                dragArea.innerHTML = `<p>File selected: ${e.dataTransfer.files[0].name}</p>`;
            }
        });
        
        // Show filename when selected through file input
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                dragArea.innerHTML = `<p>File selected: ${fileInput.files[0].name}</p>`;
            }
        });
    </script>
</body>
</html>