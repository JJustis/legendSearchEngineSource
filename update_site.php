<?php
/**
 * update_site.php - Backend script to handle live site updates
 * 
 * This script fetches current title and description from a website
 * and updates them in the database.
 */

// Include the config file
require_once 'config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Check if the action is 'live_update'
if (!isset($_POST['action']) || $_POST['action'] !== 'live_update') {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

// Get URL and site ID
$url = isset($_POST['url']) ? trim($_POST['url']) : '';
$siteId = isset($_POST['site_id']) ? (int)$_POST['site_id'] : 0;

// Validate URL
if (empty($url)) {
    echo json_encode(['success' => false, 'message' => 'URL is required.']);
    exit;
}

// If no site ID provided, try to look it up by URL
if ($siteId === 0) {
    $pdo = getDbConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM registered_sites WHERE url = ?");
    $stmt->execute([$url]);
    $result = $stmt->fetch();
    
    if ($result) {
        $siteId = $result['id'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Site not found in database.']);
        exit;
    }
}

// Fetch current information from the website
$siteInfo = fetchSiteInfo($url);

if (!$siteInfo) {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch site information.']);
    exit;
}

// Update information in the database
$updateResult = updateSiteInDb($siteId, $siteInfo);

if (!$updateResult) {
    echo json_encode(['success' => false, 'message' => 'Failed to update site in database.']);
    exit;
}

// Return success response with updated data
echo json_encode([
    'success' => true,
    'message' => 'Site information updated successfully.',
    'data' => $siteInfo
]);
exit;

/**
 * Fetch information from a website
 * 
 * @param string $url URL to fetch
 * @return array|false Site information or false on error
 */
function fetchSiteInfo($url) {
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    
    // Execute cURL
    $html = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Check if the request was successful
    if ($statusCode !== 200 || empty($html)) {
        return false;
    }
    
    // Create DOM document
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    
    // Extract title
    $title = '';
    $titleTags = $dom->getElementsByTagName('title');
    if ($titleTags->length > 0) {
        $title = trim($titleTags->item(0)->textContent);
    }
    
    // Extract description from meta tags
    $description = '';
    $metaTags = $dom->getElementsByTagName('meta');
    foreach ($metaTags as $metaTag) {
        if ($metaTag->hasAttribute('name') && strtolower($metaTag->getAttribute('name')) === 'description') {
            $description = trim($metaTag->getAttribute('content'));
            break;
        }
    }
    
    // If no description meta tag, try to extract from the first paragraph
    if (empty($description)) {
        $paragraphs = $dom->getElementsByTagName('p');
        if ($paragraphs->length > 0) {
            $description = trim($paragraphs->item(0)->textContent);
            // Limit description length
            if (strlen($description) > 200) {
                $description = substr($description, 0, 197) . '...';
            }
        }
    }
    
    // Extract additional metadata
    $keywords = '';
    foreach ($metaTags as $metaTag) {
        if ($metaTag->hasAttribute('name') && strtolower($metaTag->getAttribute('name')) === 'keywords') {
            $keywords = trim($metaTag->getAttribute('content'));
            break;
        }
    }
    
    return [
        'title' => $title,
        'description' => $description,
        'keywords' => $keywords,
        'last_crawl' => date('Y-m-d H:i:s')
    ];
}

/**
 * Update site information in the database
 * 
 * @param int $siteId Site ID
 * @param array $siteInfo Site information
 * @return bool Success or failure
 */
function updateSiteInDb($siteId, $siteInfo) {
    global $pdo;
    
    if (!$pdo) {
        $pdo = getDbConnection();
    }
    
    if (!$pdo) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE registered_sites 
            SET 
                title = ?,
                description = ?,
                keywords = ?,
                last_crawl = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $siteInfo['title'],
            $siteInfo['description'],
            $siteInfo['keywords'],
            $siteInfo['last_crawl'],
            $siteId
        ]);
    } catch (Exception $e) {
        error_log("Error updating site in database: " . $e->getMessage());
        return false;
    }
}