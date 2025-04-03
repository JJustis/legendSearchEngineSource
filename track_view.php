<?php
/**
 * Image View Tracker Script
 * 
 * This script handles tracking image views. It should be included
 * in image paths or as a proxy for serving images.
 * 
 * Usage:  track_view.php?img=path/to/image.jpg
 *         For direct output: &output=1
 */

// Include the analytics tracker
require_once 'analytics_tracker.php';

// Get image path from query string
$image_path = isset($_GET['img']) ? $_GET['img'] : '';
$output = isset($_GET['output']) ? (bool)$_GET['output'] : false;

// Validate image path
if (empty($image_path) || !file_exists($image_path)) {
    header("HTTP/1.0 404 Not Found");
    exit("Image not found");
}

// Extract image ID (filename)
$image_id = basename($image_path);

// Get metadata from image_metadata.json if available
$metadata = [];
$db_file = "image_metadata.json";
if (file_exists($db_file)) {
    $image_db = json_decode(file_get_contents($db_file), true);
    foreach ($image_db as $entry) {
        if ($entry['filename'] === $image_id) {
            $metadata = $entry;
            break;
        }
    }
}

// Initialize tracker and track the view
$tracker = new AnalyticsTracker();
$tracker->trackImageView($image_id, $image_path, $metadata);

// Output image directly if requested
if ($output) {
    // Get image MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $image_path);
    finfo_close($finfo);
    
    // Output appropriate headers
    header("Content-Type: $mime_type");
    header("Content-Length: " . filesize($image_path));
    
    // Send image data
    readfile($image_path);
    exit;
} else {
    // Redirect to actual image
    header("Location: $image_path");
    exit;
}
?>
