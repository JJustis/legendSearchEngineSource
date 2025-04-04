<?php
/**
 * This is a standalone test file to check if your server can access the video files
 * Save this as test-video-access.php and run it in your browser
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define video directory URL
$videoDirectory = 'https://jcmc.serveminecraft.net/videoplayer/uploads/';
$testVideoUrl = $videoDirectory . 'test.mp4';
$testJsonUrl = $videoDirectory . 'test.json';

echo "<html><head><title>Video Access Test</title>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; }
.section { margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
.success { color: green; }
.error { color: red; }
.key { font-weight: bold; }
pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow: auto; }
</style>";
echo "</head><body>";
echo "<h1>Video Access Test</h1>";

echo "<div class='section'>";
echo "<h2>Test Configuration</h2>";
echo "<p><span class='key'>PHP Version:</span> " . phpversion() . "</p>";
echo "<p><span class='key'>Video Directory:</span> " . htmlspecialchars($videoDirectory) . "</p>";
echo "<p><span class='key'>Test Video URL:</span> " . htmlspecialchars($testVideoUrl) . "</p>";
echo "<p><span class='key'>Test JSON URL:</span> " . htmlspecialchars($testJsonUrl) . "</p>";
echo "</div>";

// Check if cURL is available
echo "<div class='section'>";
echo "<h2>cURL Test</h2>";
if (function_exists('curl_version')) {
    $curlVersion = curl_version();
    echo "<p class='success'>cURL is available: Version " . $curlVersion['version'] . "</p>";
} else {
    echo "<p class='error'>cURL is NOT available. This may prevent access to external URLs.</p>";
}
echo "</div>";

// Test directory access with file_get_contents
echo "<div class='section'>";
echo "<h2>Directory Access Test (file_get_contents)</h2>";
try {
    $directoryContent = @file_get_contents($videoDirectory);
    if ($directoryContent !== false) {
        echo "<p class='success'>Successfully accessed directory with file_get_contents</p>";
        // Show a snippet of the content
        echo "<pre>" . htmlspecialchars(substr($directoryContent, 0, 500)) . "...</pre>";
    } else {
        echo "<p class='error'>Failed to access directory with file_get_contents</p>";
        echo "<p>Error: " . error_get_last()['message'] . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>Exception: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test video access with cURL
echo "<div class='section'>";
echo "<h2>Video File Access Test (cURL)</h2>";
$ch = curl_init($testVideoUrl);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$videoSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode == 200) {
    echo "<p class='success'>Successfully accessed the test video file</p>";
    echo "<p><span class='key'>HTTP Code:</span> 200 (OK)</p>";
    echo "<p><span class='key'>Video Size:</span> " . ($videoSize ? formatBytes($videoSize) : "Unknown") . "</p>";
    echo "<p><span class='key'>Final URL:</span> " . htmlspecialchars($lastUrl) . "</p>";
} else {
    echo "<p class='error'>Failed to access the test video file</p>";
    echo "<p><span class='key'>HTTP Code:</span> " . $httpCode . "</p>";
    echo "<p><span class='key'>Error:</span> " . ($error ? htmlspecialchars($error) : "None reported") . "</p>";
    echo "<p><span class='key'>Final URL:</span> " . htmlspecialchars($lastUrl) . "</p>";
}
echo "</div>";

// Test JSON access and parsing
echo "<div class='section'>";
echo "<h2>JSON File Access Test</h2>";
$ch = curl_init($testJsonUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$jsonContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode == 200 && $jsonContent) {
    echo "<p class='success'>Successfully accessed the test JSON file</p>";
    echo "<p><span class='key'>HTTP Code:</span> 200 (OK)</p>";
    echo "<p><span class='key'>Content Length:</span> " . strlen($jsonContent) . " bytes</p>";
    echo "<p><span class='key'>Raw Content:</span></p>";
    echo "<pre>" . htmlspecialchars($jsonContent) . "</pre>";
    
    // Try to parse the JSON
    $metadata = json_decode($jsonContent, true);
    if ($metadata && is_array($metadata)) {
        echo "<p class='success'>Successfully parsed JSON content</p>";
        echo "<p><span class='key'>Parsed Content:</span></p>";
        echo "<ul>";
        foreach ($metadata as $key => $value) {
            echo "<li><strong>" . htmlspecialchars($key) . ":</strong> ";
            if (is_array($value)) {
                echo htmlspecialchars(json_encode($value));
            } else {
                echo htmlspecialchars($value);
            }
            echo "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p class='error'>Failed to parse JSON content</p>";
        echo "<p><span class='key'>JSON Error:</span> " . json_last_error_msg() . "</p>";
    }
} else {
    echo "<p class='error'>Failed to access the test JSON file</p>";
    echo "<p><span class='key'>HTTP Code:</span> " . $httpCode . "</p>";
    echo "<p><span class='key'>Error:</span> " . ($error ? htmlspecialchars($error) : "None reported") . "</p>";
}
echo "</div>";

// Try listing all MP4 files in the directory
echo "<div class='section'>";
echo "<h2>Directory MP4 Scan Test</h2>";
$ch = curl_init($videoDirectory);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode == 200 && $html) {
    echo "<p class='success'>Successfully retrieved directory listing</p>";
    
    // Try to extract MP4 links
    preg_match_all('/<a[^>]*href=[\'"]([^\'"]*\.mp4)[\'"][^>]*>(.*?)<\/a>/i', $html, $matches);
    
    if (!empty($matches[1])) {
        echo "<p class='success'>Found " . count($matches[1]) . " MP4 files in directory</p>";
        echo "<ul>";
        foreach ($matches[1] as $index => $file) {
            echo "<li><a href='" . htmlspecialchars($videoDirectory . $file) . "' target='_blank'>" . 
                 htmlspecialchars($file) . "</a></li>";
        }
        echo "</ul>";
    } else {
        echo "<p class='error'>No MP4 files found in directory listing</p>";
        
        // Try another pattern
        preg_match_all('/href=[\'"]([^\'"]*\.mp4)[\'"]/', $html, $matches);
        
        if (!empty($matches[1])) {
            echo "<p class='success'>Found " . count($matches[1]) . " MP4 files with alternate pattern</p>";
            echo "<ul>";
            foreach ($matches[1] as $file) {
                echo "<li><a href='" . htmlspecialchars($videoDirectory . $file) . "' target='_blank'>" . 
                     htmlspecialchars($file) . "</a></li>";
            }
            echo "</ul>";
        } else {
            echo "<p>Directory HTML snippet:</p>";
            echo "<pre>" . htmlspecialchars(substr($html, 0, 1000)) . "...</pre>";
        }
    }
} else {
    echo "<p class='error'>Failed to retrieve directory listing</p>";
    echo "<p><span class='key'>HTTP Code:</span> " . $httpCode . "</p>";
    echo "<p><span class='key'>Error:</span> " . ($error ? htmlspecialchars($error) : "None reported") . "</p>";
}
echo "</div>";

// Helper function to format byte sizes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

echo "</body></html>";
?>