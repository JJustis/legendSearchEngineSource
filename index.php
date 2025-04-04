<?php
// index.php - Main entry point with article integration
require_once 'pfs_handler.php';

// Include the analytics tracker
require_once 'analytics_tracker.php';
// Include article functions
require_once 'article_functions.php';
$tracker = new AnalyticsTracker();
// Include translation service
require_once 'translation_service.php';

$translator = new TranslationService();

// Add translation-related variables
$translated_query = '';
$detected_language = '';
$is_translated = false;
// Handle form submissions and routing
$page = isset($_GET['page']) ? $_GET['page'] : 'search';
$results = [];
$ip_info = null;
$image_results = [];
$video_results = [];
$uploaded_image = null;
$articles_data = null;
$single_article = null;
$query = '';
// Function to check if a string is a URL
function isUrl($string) {
    // Simple pattern to match URLs
    $pattern = '/^(https?:\/\/)?([a-zA-Z0-9][-a-zA-Z0-9]*\.)+[a-zA-Z]{2,}(\/[-a-zA-Z0-9@:%_\+.~#?&\/=]*)?$/i';
    return preg_match($pattern, $string);
}

// Simple endpoint to look up words from the database as the user types
if (isset($_GET['term'])) {
    header('Content-Type: application/json');
    
    // Database connection parameters
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'reservesphp';
    
    try {
        // Connect to the database
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get search term and add wildcard
        $term = $_GET['term'] . '%';
        
        $stmt = $pdo->prepare("
    SELECT rs.id, rs.title, rs.url, rs.description, rs.subject
    FROM registered_sites rs
    WHERE MATCH(rs.title, rs.description, rs.keywords) AGAINST (? IN BOOLEAN MODE)
    ORDER BY rs.registration_date DESC
    LIMIT ? OFFSET ?
");
$searchTerms = $searchQuery . '*'; // Add wildcard for partial matches
$stmt->execute([$searchTerms, $perPage, $offset]);
        
        // Fetch results
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Return JSON response
        echo json_encode($results);
        exit;
    } catch (PDOException $e) {
        // Return empty array on error
        echo json_encode([]);
        exit;
    }
}

// Redirect to image_uploader.php if requested
if (isset($_GET['upload_image'])) {
    header("Location: image_uploader.php");
    exit;
}

// Handle search queries and URL detection
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $query = htmlspecialchars($_GET['q']);
    
    // Check if the query is a URL
    if (isUrl($query)) {
        // Ensure the URL has the correct protocol
        if (!preg_match('/^https?:\/\//i', $query)) {
            $query = 'https://' . $query;
        }
        
        // Track the URL visit in analytics
        $tracker->trackSearch($query);
        
        // Redirect to the URL
        header("Location: $query");
        exit;
    }
    
    // Track this search query
    $tracker->trackSearch($query);
    
    // Check if it's a single or two-word query for Wikipedia search
    $word_count = str_word_count($query);
    if ($word_count <= 2) {
        // This would be a Wikipedia API call in production
        // For demo purposes, we'll simulate Wikipedia results
        $results = [
            [
                'title' => "$query - Wikipedia",
                'url' => 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $query)),
                'description' => "Wikipedia page about \"$query\" with comprehensive information and references."
            ],
            [
                'title' => "Talk:$query - Wikipedia",
                'url' => 'https://en.wikipedia.org/wiki/Talk:' . urlencode(str_replace(' ', '_', $query)),
                'description' => "Discussion page related to the \"$query\" article on Wikipedia."
            ],
            [
                'title' => "Category:$query - Wikipedia",
                'url' => 'https://en.wikipedia.org/wiki/Category:' . urlencode(str_replace(' ', '_', $query)),
                'description' => "Wikipedia category page listing articles related to \"$query\"."
            ]
        ];
    } 
    
    // Search for images in the image_metadata.json database
    $db_file = "image_metadata.json";
    if (file_exists($db_file)) {
        $image_db = json_decode(file_get_contents($db_file), true);
        
        // Filter images by query (in title, description, or tags)
        foreach ($image_db as $image) {
            $searchable_text = strtolower($image['title'] . ' ' . $image['description'] . ' ' . implode(' ', $image['tags']));
            if (stripos($searchable_text, strtolower($query)) !== false) {
                $image_results[] = [
                    'url' => "track_view.php?img=" . urlencode($image['url']) . "&output=1", // Use the tracking script
                    'direct_url' => $image['url'], // Original URL for linking
                    'title' => $image['title'] ?: $image['filename'],
                    'description' => $image['description'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'tags' => $image['tags'],
                    'id' => $image['id'] // Use ID for tracking
                ];
            }
        }
    }
    

    
    // Track hostnames in the results for analytics
    foreach ($results as $result) {
        if (preg_match('/^https?:\/\/([^\/]+)/', $result['url'], $matches)) {
            $hostname = $matches[1];
            $tracker->trackHostname($hostname, $query);
        }
    }
}

// Handle IP lookup
if (isset($_GET['ip']) && !empty($_GET['ip'])) {
    $ip = $_GET['ip'];
    
    // Perform simple hostname lookup (actual functionality)
    $hostname = gethostbyaddr($ip);
    
    $ip_info = [
        'ip' => $ip,
        'hostname' => $hostname ?: 'No hostname found'
    ];
    
    // Track the hostname if found
    if ($hostname && $hostname != $ip) {
        $tracker->trackHostname($hostname, "ip-lookup");
    }
}

if ($page == 'articles') {
    // Get current page for pagination
    $currentPage = isset($_GET['pg']) && is_numeric($_GET['pg']) ? (int)$_GET['pg'] : 1;
    $articles_per_page = 5; // Number of articles per page
    
    // Handle search
    if (isset($_GET['action']) && $_GET['action'] === 'search' && isset($_GET['q'])) {
        $searchTerm = $_GET['q'];
        $articles_data = renderSearchResults($searchTerm, $currentPage, $articles_per_page);
    }
    // Handle single article view
    elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $single_article = renderSingleArticle($_GET['id']);
    }
    // Handle category/genre filter
    elseif (isset($_GET['genre']) && !empty($_GET['genre'])) {
        $genre = $_GET['genre'];
        $articles_data = renderArticleList($currentPage, $articles_per_page, $genre);
        $genre_filters = renderGenreFilters($genre);
    }
    // List all articles
    else {
        $articles_data = renderArticleList($currentPage, $articles_per_page);
        $genre_filters = renderGenreFilters();
    }
}

// Add custom CSS specifically for articles
$article_css = <<<CSS
/* Article specific styling */
.article-card {
    margin-bottom: var(--space-lg);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.article-image {
	height: auto;
	width: -moz-available;
    margin-bottom: var(--space-lg);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.article-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

.article-meta {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-sm);
    color: var(--google-grey-dark);
    font-size: 0.9rem;
}

.article-date {
    display: flex;
    align-items: center;
}

.article-date::before {
    content: '\f073';
    font-family: 'Font Awesome 5 Free';
    margin-right: var(--space-xs);
}

.article-excerpt {
    margin-bottom: var(--space-md);
    line-height: 1.6;
}

.article-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: var(--space-md);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--google-grey-medium);
}

.article-author {
    display: flex;
    align-items: center;
    color: var(--google-grey-dark);
    font-size: 0.9rem;
}

.article-author i {
    margin-right: var(--space-xs);
    font-size: 1.2rem;
}

/* Single article page styling */
.single-article {
    padding: var(--space-xl);
}

.single-article .article-title {
    font-size: 2.2rem;
    margin-bottom: var(--space-md);
    color: var(--google-text);
}

.single-article .article-meta {
    margin-bottom: var(--space-lg);
}

.single-article .article-content {
    line-height: 1.8;
    font-size: 1.1rem;
}

.single-article .article-content p {
    margin-bottom: var(--space-lg);
}

.single-article .article-footer {
    margin-top: var(--space-xl);
    padding-top: var(--space-md);
}

/* Featured article styling */
.featured-article {
    background: linear-gradient(135deg, rgba(66, 133, 244, 0.1) 0%, rgba(66, 133, 244, 0.05) 100%);
    border-left: 5px solid var(--google-blue);
    position: relative;
    overflow: hidden;
}

.featured-article::before {
    content: 'Featured';
    position: absolute;
    top: 10px;
    right: -30px;
    background: var(--google-blue);
    color: white;
    padding: 5px 40px;
    transform: rotate(45deg);
    font-size: 0.8rem;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

/* Article search bar */
.article-search-container {
    margin-bottom: var(--space-lg);
}

.article-categories {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}

.article-category {
    padding: var(--space-xs) var(--space-sm);
    background-color: var(--google-grey-light);
    border-radius: var(--radius-pill);
    font-size: 0.9rem;
    color: var(--google-grey-dark);
    cursor: pointer;
    transition: background-color 0.2s ease, color 0.2s ease;
}

.article-category:hover, .article-category.active {
    background-color: var(--google-blue);
    color: white;
}
CSS;

// HTML header and basic styling
?>
<?PHP
require_once 'config.php';
// Get database connection
$pdo = getDbConnection();

// Check if connection was successful
if (!$pdo) {
    die("Database connection failed. Please check your configuration.");
}

// Initialize variables

$searchResults = [];
$totalResults = 0;
$singleWordResult = null;
$wikipediaResults = [];
$wordpediaResults = [];
$suggestedWords = [];
$executionTime = 0;

// Process search query if provided
if (isset($_GET['q']) && !empty($_GET['q'])) {
    // Start timing the search
    $startTime = microtime(true);
    
    $searchQuery = trim($_GET['q']);
    
    // Track this search in the database
    trackSearch($searchQuery);
    
    // Check if this is a single word query
    $wordCount = str_word_count($searchQuery);
    
// Modify the single word search logic in index.php
if ($wordCount === 1) {
    // Single word query - check for Wordpedia entry and do enhanced search
    $word = strtolower($searchQuery);
    
    // First, search for the word in the word table (existing functionality)
    $stmt = $pdo->prepare("
        SELECT w.word, w.frequency, rs.title as site_title, rs.url as site_url, rs.description as site_description
        FROM word w
        JOIN registered_sites rs ON w.site_id = rs.id
        WHERE w.word = ?
        ORDER BY w.frequency DESC
        LIMIT 9002
    ");
    $stmt->execute([$word]);
    $wordResults = $stmt->fetchAll();
    
    // Add the word results to search results
    if (!empty($wordResults)) {
        foreach ($wordResults as $result) {
            $searchResults[] = [
                'title' => "'{$result['word']}' found on {$result['site_title']}",
                'url' => $result['site_url'],
                'description' => $result['site_description'] ?: "This site contains information about '{$result['word']}'.",
                'source' => 'database',
                'frequency' => $result['frequency']
            ];
        }
    }
    
    // NEW CODE: Also search in URLs, titles, descriptions, and keywords
    $stmt = $pdo->prepare("
        SELECT id, url, title, description, keywords, subject
        FROM registered_sites
        WHERE 
            url LIKE ? OR 
            title LIKE ? OR 
            description LIKE ? OR 
            keywords LIKE ?
        ORDER BY registration_date DESC
        LIMIT 9002
    ");
    
    $searchPattern = '%' . $word . '%';
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
    $metadataResults = $stmt->fetchAll();
    
    // Add metadata results to search results
    foreach ($metadataResults as $result) {
        // Calculate relevance score based on where the word appears
        $relevanceScore = 0;
        $matchTypes = [];
        
        if (stripos($result['url'], $word) !== false) {
            $relevanceScore += 5;
            $matchTypes[] = 'URL';
        }
        if (stripos($result['title'], $word) !== false) {
            $relevanceScore += 4;
            $matchTypes[] = 'title';
        }
        if (stripos($result['keywords'], $word) !== false) {
            $relevanceScore += 3;
            $matchTypes[] = 'keywords';
        }
        if (stripos($result['description'], $word) !== false) {
            $relevanceScore += 2;
            $matchTypes[] = 'description';
        }
        
        // Skip if this result is already in the search results
        $isDuplicate = false;
        foreach ($searchResults as $existingResult) {
            if (isset($existingResult['url']) && $existingResult['url'] === $result['url']) {
                $isDuplicate = true;
                break;
            }
        }
        
        if (!$isDuplicate) {
            $matchInfo = !empty($matchTypes) ? "Found in: " . implode(", ", $matchTypes) : "";
            $searchResults[] = [
                'title' => $result['title'] ?: $result['url'],
                'url' => $result['url'],
                'description' => $result['description'] ?: "This site contains '{$word}' in its " . strtolower(implode(", ", $matchTypes)) . ".",
                'source' => 'metadata',
                'relevance' => $relevanceScore,
                'match_info' => $matchInfo
            ];
        }
    }
    
    // Sort results by relevance/frequency (most relevant first)
    usort($searchResults, function($a, $b) {
        // If both have relevance, compare by relevance
        if (isset($a['relevance']) && isset($b['relevance'])) {
            return $b['relevance'] <=> $a['relevance'];
        }
        // If both have frequency, compare by frequency
        if (isset($a['frequency']) && isset($b['frequency'])) {
            return $b['frequency'] <=> $a['frequency'];
        }
        // If one has relevance and one has frequency, prioritize relevance
        if (isset($a['relevance']) && isset($b['frequency'])) {
            return -1;
        }
        if (isset($a['frequency']) && isset($b['relevance'])) {
            return 1;
        }
        // Default case
        return 0;
    });
} else if ($wordCount === 2) {
        // Two-word query - try Wikipedia style results first
        $wikipediaResults = [
            [
                'title' => "$searchQuery - Wikipedia",
                'url' => 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $searchQuery)),
                'description' => "Wikipedia page about \"$searchQuery\" with comprehensive information and references.",
                'source' => 'wikipedia'
            ],
            [
                'title' => "Talk:$searchQuery - Wikipedia",
                'url' => 'https://en.wikipedia.org/wiki/Talk:' . urlencode(str_replace(' ', '_', $searchQuery)),
                'description' => "Discussion page related to the \"$searchQuery\" article on Wikipedia.",
                'source' => 'wikipedia'
            ]
        ];
        
        // Also search in registered sites
        $stmt = $pdo->prepare("
            SELECT rs.id, rs.title, rs.url, rs.description, rs.subject
            FROM registered_sites rs
            WHERE rs.title LIKE ? OR rs.description LIKE ? OR rs.keywords LIKE ?
            ORDER BY rs.registration_date DESC
            LIMIT 9002
        ");
        $searchPattern = '%' . $searchQuery . '%';
        $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
        $siteResults = $stmt->fetchAll();
        
        foreach ($siteResults as $result) {
            $searchResults[] = [
                'title' => $result['title'],
                'url' => $result['url'],
                'description' => $result['description'] ?: "Website about " . $result['subject'],
                'source' => 'database'
            ];
        }
    } else {
        // Multi-word query - search in registered sites
        $stmt = $pdo->prepare("
            SELECT rs.id, rs.title, rs.url, rs.description, rs.subject
            FROM registered_sites rs
            WHERE rs.title LIKE ? OR rs.description LIKE ? OR rs.keywords LIKE ?
            ORDER BY rs.registration_date DESC
            LIMIT 9002
        ");
        $searchPattern = '%' . $searchQuery . '%';
        $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
        $searchResults = $stmt->fetchAll();
        
        // Also search for individual words in the query
        $words = explode(' ', $searchQuery);
        $validWords = [];
        
        foreach ($words as $word) {
            if (strlen($word) >= 3 && !in_array(strtolower($word), $searchConfig['stopWords'])) {
                $validWords[] = strtolower($word);
            }
        }
        
        if (!empty($validWords)) {
            $placeholders = implode(',', array_fill(0, count($validWords), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT w.word
                FROM word w
                WHERE w.word IN ($placeholders)
                ORDER BY w.frequency DESC
                LIMIT 5
            ");
            $stmt->execute($validWords);
            $wordResults = $stmt->fetchAll();
            
            foreach ($wordResults as $result) {
                $suggestedWords[] = $result['word'];
            }
        }
    }
    
    // Calculate total results and execution time
    $totalResults = count($searchResults) + count($wordpediaResults) + count($wikipediaResults);
    $executionTime = round(microtime(true) - $startTime, 2);
}

/**
 * Track search query in the database
 * 
 * @param string $query The search query to track
 * @return bool Success or failure
 */
function trackSearch($query) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO search_history (query, ip_address, user_agent, search_date, results_count)
            VALUES (?, ?, ?, NOW(), 0)
        ");
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        return $stmt->execute([$query, $ipAddress, $userAgent]);
    } catch (Exception $e) {
        error_log("Error tracking search: " . $e->getMessage());
        return false;
    }
}

/**
 * Format number with commas for thousands
 * 
 * @param int $number The number to format
 * @return string Formatted number
 */
function formatNumber($number) {
    return number_format($number);
}

/**
 * Get random result count for display purposes
 * 
 * @return int Random result count
 */
function getRandomResultCount() {
    return rand(100000, 9999999);
}
function getPlaceholderFavicon($domain = null) {
    // If domain is provided, you could generate different icons for different domains
    // For now, we'll use a single generic placeholder
    
    // Base64 encoded SVG favicon - a simple, colorful design that's calm but eye-catching
    $base64Icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNiAxNiI+CiAgPGRlZnM+CiAgICA8bGluZWFyR3JhZGllbnQgaWQ9Imc1IiB4MT0iMCUiIHkxPSIwJSIgeDI9IjEwMCUiIHkyPSIxMDAlIj4KICAgICAgPHN0b3Agb2Zmc2V0PSIwJSIgc3RvcC1jb2xvcj0iIzQyODVmNCIgLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIyNSUiIHN0b3AtY29sb3I9IiNlYTQzMzUiIC8+CiAgICAgIDxzdG9wIG9mZnNldD0iNTAlIiBzdG9wLWNvbG9yPSIjZmJiYzA1IiAvPgogICAgICA8c3RvcCBvZmZzZXQ9Ijc1JSIgc3RvcC1jb2xvcj0iIzM0YTg1MyIgLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxMDAlIiBzdG9wLWNvbG9yPSIjNDI4NWY0IiAvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3QgeD0iMSIgeT0iMSIgd2lkdGg9IjE0IiBoZWlnaHQ9IjE0IiByeD0iMyIgZmlsbD0id2hpdGUiIHN0cm9rZT0idXJsKCNnNSkiIHN0cm9rZS13aWR0aD0iMiIgLz4KICA8Y2lyY2xlIGN4PSI4IiBjeT0iOCIgcj0iMyIgZmlsbD0idXJsKCNnNSkiIC8+Cjwvc3ZnPg==';
    
    return $base64Icon;
}
?>
<?php
// Add this PHP code to your index.php file to handle tab content separation

// Process search query if provided
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $searchQuery = trim($_GET['q']);
    $currentTab = isset($_GET['page']) ? $_GET['page'] : 'search';
    
    // Set default tab content
    $allTabContent = '';
    $imagesTabContent = '';
    $ipMapperTabContent = '';
    
    // Generate "All" tab content with search results
    ob_start();
    include 'search_results.php'; // This includes the search results code
    $allTabContent = ob_get_clean();
    
    // For "Images" tab, we'll show a custom message instead of the All tab results
    $imagesTabContent = '
    <div class="g-tab-content' . ($currentTab == 'images' ? ' active' : '') . '" id="images-content">
        <div style="text-align: center; padding: 50px 20px;">
            <i class="fas fa-images" style="font-size: 48px; color: #dadce0; margin-bottom: 20px;"></i>
            <h2>Image Search</h2>
            <p>Search for images using the search box above.</p>
            <p>This tab shows image-specific content and does not display the same results as the All tab.</p>
        </div>
    </div>';
    
    // For "IP Mapper" tab, we'll show a custom form instead of the All tab results
    $ipMapperTabContent = '
    <div class="g-tab-content' . ($currentTab == 'ip' ? ' active' : '') . '" id="ipmapper-content">
        <h2>IP Mapper</h2>
        <p>Enter an IP address to look up its information.</p>
        
        <form class="g-form" action="" method="GET">
            <input type="hidden" name="page" value="ip">
            <div class="g-form-group">
                <label for="ip" class="g-form-label">IP Address</label>
                <input type="text" id="ip" name="ip" class="g-form-input" placeholder="Enter IP address (e.g. 192.168.1.1)" required>
            </div>
            <button type="submit" class="g-button">
                <i class="fas fa-search"></i> Lookup IP
            </button>
        </form>';
    
    // If we have an IP to look up, add that content
    if (isset($_GET['ip']) && !empty($_GET['ip'])) {
        $ip = $_GET['ip'];
        
        // You can replace this with your actual IP lookup code
        $ipMapperTabContent .= '
        <div class="g-panel" style="margin-top: 20px;">
            <h3>IP Information: ' . htmlspecialchars($ip) . '</h3>
            <div>
                <strong>Hostname:</strong> ' . htmlspecialchars(gethostbyaddr($ip)) . '<br>
                <strong>Location:</strong> Information not available<br>
                <strong>ISP:</strong> Information not available
            </div>
        </div>';
    }
    
    $ipMapperTabContent .= '</div>';
}
?>
<?php
// Add this function to your index.php file
// This should be placed with your other PHP functions
// Function to scan uploads folder for images matching the search query
// Function to scan uploads folder for images matching the search query
function scanUploadsFolder($query) {
    $uploads_dir = "uploads/"; // Make sure this path is correct relative to index.php
    $image_results = [];
    $query = strtolower($query);
    
    // Debug information
    error_log("Scanning uploads folder for query: $query");
    
    // Check if directory exists
    if (!is_dir($uploads_dir)) {
        error_log("Uploads directory does not exist: $uploads_dir");
        // Try to create the directory if it doesn't exist
        if (!mkdir($uploads_dir, 0755, true)) {
            error_log("Failed to create uploads directory");
        }
        return $image_results;
    }
    
    // Get all files in the directory
    $files = scandir($uploads_dir);
    error_log("Found " . count($files) . " files in uploads directory");
    
    foreach ($files as $file) {
        // Skip . and .. directories
        if ($file == '.' || $file == '..') {
            continue;
        }
        
        // Only process image files
        $file_extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (!in_array($file_extension, $valid_extensions)) {
            continue;
        }
        
        // If query is empty, include all images
        // Otherwise, check if the filename contains the query
        $filename = strtolower(pathinfo($file, PATHINFO_FILENAME));
        if (empty($query) || strpos($filename, $query) !== false) {
            // Get image dimensions if possible
            $image_path = $uploads_dir . $file;
            $dimensions = @getimagesize($image_path);
            $width = $dimensions ? $dimensions[0] : 300;
            $height = $dimensions ? $dimensions[1] : 200;
            
            // Create a unique ID based on the filename
            $id = "upload_" . md5($file);
            
            // Generate tags based on filename parts
            $tags = array_filter(explode('_', $filename));
            $tags[] = 'uploads'; // Add uploads tag
            
            // Add image to results
            $image_results[] = [
                'url' => $image_path, // Direct path for display
                'direct_url' => $image_path, // Same path for linking
                'title' => ucwords(str_replace('_', ' ', $filename)),
                'description' => "Uploaded image: " . ucwords(str_replace('_', ' ', $filename)),
                'width' => $width,
                'height' => $height,
                'tags' => $tags,
                'id' => $id,
                'source' => 'uploads',
                'filename' => $file
            ];
            
            error_log("Added image to results: $file");
        }
    }
    
    error_log("Found " . count($image_results) . " matching images in uploads directory");
    return $image_results;
}
/**
 * Fetches images from Wikipedia for a given search term
 * 
 * @param string $query The search query
 * @return array Array of image results
 */
function fetchWikipediaImages($query) {
    $image_results = [];
    
    // Split the query into words
    $words = array_filter(preg_split('/\s+/', $query), function($word) {
        return strlen($word) > 2; // Only process words longer than 2 characters
    });
    
    foreach ($words as $word) {
        // Simulate fetching data from Wikipedia
        // In a real implementation, you would use the MediaWiki API
        
        // For demonstration, we'll simulate 2 images per word
        $image_id = abs(crc32($word)); // Generate a consistent ID based on the word
        
    }
    
    return $image_results;
}

// Modify the search handler in your index.php to include Wikipedia images
// Find where $image_results is populated and add:

// Modify the search handler in your index.php to include both uploads folder and Wikipedia images
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $query = htmlspecialchars($_GET['q']);
    
    // Get existing image results from your current code
    $image_results = [];
    
    // Search for images in the image_metadata.json database
    $db_file = "image_metadata.json";
    if (file_exists($db_file)) {
        $image_db = json_decode(file_get_contents($db_file), true);
        
        // Filter images by query (in title, description, or tags)
        foreach ($image_db as $image) {
            $searchable_text = strtolower($image['title'] . ' ' . $image['description'] . ' ' . implode(' ', $image['tags']));
            if (stripos($searchable_text, strtolower($query)) !== false) {
                $image_results[] = [
                    'url' => "track_view.php?img=" . urlencode($image['url']) . "&output=1", // Use the tracking script
                    'direct_url' => $image['url'], // Original URL for linking
                    'title' => $image['title'] ?: $image['filename'],
                    'description' => $image['description'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'tags' => $image['tags'],
                    'id' => $image['id'], // Use ID for tracking
                    'source' => 'database'
                ];
            }
        }
    }
    
    // Add images from uploads folder
    $uploads_images = scanUploadsFolder($query);
    $image_results = array_merge($image_results, $uploads_images);
    
    // Add Wikipedia images results (handled by JavaScript)
    // The wiki_images will be fetched through AJAX when the page loads
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legend The Network Search<?= $page == 'articles' ? ' - Articles' : '' ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
	@import url('https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@400;700&family=Roboto:wght@400;700&family=Playfair+Display:wght@400;700&family=Montserrat:wght@400;700&family=Open+Sans:wght@400;700&family=Lato:wght@400;700&family=Bangers&family=Luckiest+Guy&display=swap');
@import url('https://fonts.cdnfonts.com/css/waltograph');
@import url('https://fonts.cdnfonts.com/css/enchanted-land');
@import url('https://fonts.cdnfonts.com/css/minecraft-evenings');
    /* Google-Inspired CSS Framework */
    :root {
      /* Color variables */
      --google-blue: #4285f4;
      --google-red: #ea4335;
      --google-yellow: #fbbc05;
      --google-green: #34a853;
      --google-grey-light: #f8f9fa;
      --google-grey-medium: #dadce0;
      --google-grey-dark: #70757a;
      --google-text: #202124;
      --panel-bg: rgba(255, 255, 255, 0.95);
      --shadow-color: rgba(0, 0, 0, 0.05);
      
      /* Font variables */
      --font-primary: 'Product Sans', 'Google Sans', Arial, sans-serif;
      --font-secondary: 'Roboto', Arial, sans-serif;
      
      /* Spacing variables */
      --space-xs: 0.25rem;
      --space-sm: 0.5rem;
      --space-md: 1rem;
      --space-lg: 1.5rem;
      --space-xl: 2rem;
      
      /* Border radius */
      --radius-sm: 4px;
      --radius-md: 8px;
      --radius-lg: 16px;
      --radius-pill: 9999px;
    }
.wordpedia-button {
  position: fixed;
  left: 0;
  top: 70%;
  transform: translateY(-50%);
  background-color: var(--google-blue);
  color: white;
  border: none;
  border-radius: 0 4px 4px 0;
  padding: 10px;
  cursor: pointer;
  z-index: 998;
  box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
  display: flex;
  flex-direction: column;
  align-items: center;
  transition: background-color 0.2s;
}
    /* Base styles */
    body {
      margin: 0;
      padding: 0;
      font-family: var(--font-secondary);
      color: var(--google-text);
      background: white;
  
    }

    /* Typography */
    h1, h2, h3, h4, h5, h6 {
      font-family: var(--font-primary);
      margin-top: 0;
    }

    h1 {
      font-size: 2.5rem;
      font-weight: 400;
    }

    h2 {
      font-size: 2rem;
      font-weight: 400;
    }

    p {
      margin-bottom: 1rem;
    }

    a {
      color: #1a0dab;
      text-decoration: none;
      transition: color 0.2s ease-in-out;
    }

    a:hover {
      text-decoration: underline;
    }

    a:visited {
      color: #681da8;
    }

    /* Layout containers */
    .container {
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
      padding: 0 1rem;
      box-sizing: border-box;
    }

    .container-sm {
      max-width: 800px;
    }

    /* Header styles */
    .g-header {
      background: white;
      padding: 1rem 0;
      border-bottom: 1px solid var(--google-grey-medium);
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .g-header-container {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .g-logo {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .g-logo img {
      height: 30px;
    }

    .g-logo-quad {
      display: flex;
      align-items: center;
      margin-right: var(--space-sm);
    }

    .g-logo-quad span {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      margin: 0 1px;
    }

    .g-logo-blue { background-color: var(--google-blue); }
    .g-logo-red { background-color: var(--google-red); }
    .g-logo-yellow { background-color: var(--google-yellow); }
    .g-logo-green { background-color: var(--google-green); }

    .g-nav {
      display: flex;
      gap: 1.5rem;
    }

    .g-nav-item {
      color: var(--google-text);
      font-weight: 500;
      position: relative;
    }

    .g-nav-item.active {
      color: var(--google-blue);
    }

    .g-nav-item.active::after {
      content: '';
      position: absolute;
      bottom: -5px;
      left: 0;
      width: 100%;
      height: 3px;
      background-color: var(--google-blue);
    }

    /* Enhanced search container */
    .g-search-wrapper {
      background: linear-gradient(90deg, 
        var(--google-blue) 0%, 
        var(--google-red) 33%, 
        var(--google-yellow) 66%, 
        var(--google-green) 100%);
      padding: 5px;
      border-radius: 28px;
      max-width: 650px;
      margin: 2rem auto;
    }

    .g-search-container {
      position: relative;
      width: 100%;
      margin: 0;
      background: white;
      border-radius: 24px;
    }

    .g-search {
      width: 100%;
      padding: 0.75rem 4.5rem 0.75rem 3rem;
      border: none;
      border-radius: 24px;
      font-size: 1rem;
      transition: box-shadow 0.3s ease;
      box-shadow: 0 1px 3px rgba(32, 33, 36, 0.1) inset;
    }

    .g-search:focus {
      outline: none;
      box-shadow: 0 1px 3px rgba(32, 33, 36, 0.2) inset;
    }

    .g-search-icon {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--google-grey-dark);
    }

    .g-search-clear {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--google-grey-dark);
      cursor: pointer;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background-color: var(--google-grey-light);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .g-search-voice {
      position: absolute;
      right: 3rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--google-blue);
      cursor: pointer;
    }

    /* Tab system */
    .g-tabs {
      display: flex;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--google-grey-medium);
      margin-bottom: var(--space-lg);
    }

    .g-tab {
      padding: var(--space-md) var(--space-lg);
      color: var(--google-grey-dark);
      cursor: pointer;
      position: relative;
      transition: color 0.2s ease;
      user-select: none;
    }

    .g-tab:hover {
      color: var(--google-text);
    }

   .g-tab.active {
      color: var(--google-blue);
    }

    .g-tab.active::after {
      content: '';
      position: absolute;
      bottom: -1px;
      left: 0;
      width: 100%;
      height: 3px;
      background-color: var(--google-blue);
    }

    .g-tab-content {
      display: none;
      padding: var(--space-md) 0;
    }

    .g-tab-content.active {
      display: block;
    }

    /* Search filters bar */
    .g-search-filters {
      display: flex;
      overflow-x: auto;
      padding: var(--space-xs) 0;
      margin: var(--space-md) 0;
      gap: var(--space-md);
      border-bottom: 1px solid var(--google-grey-medium);
      -ms-overflow-style: none;
      scrollbar-width: none;
    }

    .g-search-filters::-webkit-scrollbar {
      display: none;
    }

    .g-search-filter {
      color: var(--google-grey-dark);
      padding: var(--space-xs) var(--space-sm);
      font-size: 0.9rem;
      white-space: nowrap;
      cursor: pointer;
      transition: color 0.2s;
      position: relative;
    }

    .g-search-filter:hover {
      color: var(--google-text);
    }

    .g-search-filter.active {
      color: var(--google-blue);
    }

    .g-search-filter.active::after {
      content: '';
      position: absolute;
      bottom: -9px;
      left: 0;
      width: 100%;
      height: 3px;
      background-color: var(--google-blue);
    }

    .g-search-filter-icon {
      margin-right: var(--space-xs);
    }
// Add this to your CSS section
.live-update-button {
    display: flex;
    align-items: center;
    gap: 5px;
    background-color: #34A853;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 5px 10px;
    font-size: 12px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.live-update-button:hover {
    background-color: #2E7D32;
}

.live-update-button i {
    font-size: 14px;
}

.update-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

    /* Search stats */
    .g-search-stats {
      color: var(--google-grey-dark);
      font-size: 0.9rem;
      margin-bottom: var(--space-md);
    }

    /* Search result list */
    .g-search-results {
      list-style: none;
      padding: 0;
      margin: var(--space-lg) 0;
    }

    .g-search-result {
      margin-bottom: var(--space-xl);
    }

    .g-search-result-url {
      color: var(--google-grey-dark);
      font-size: 0.9rem;
      margin-bottom: var(--space-xs);
      display: flex;
      align-items: center;
    }

    .g-search-result-favicon {
      width: 16px;
      height: 16px;
      margin-right: var(--space-xs);
    }

    .g-search-result-title {
      color: #1a0dab;
      font-size: 1.2rem;
      margin: var(--space-xs) 0;
      font-weight: 400;
    }

    .g-search-result-title:visited {
      color: #681da8;
    }

    .g-search-result-title:hover {
      text-decoration: underline;
    }

    .g-search-result-snippet {
      color: var(--google-text);
      font-size: 0.9rem;
      line-height: 1.5;
    }

    .g-search-result-info {
      margin-top: var(--space-xs);
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-md);
    }

    .g-search-result-info-item {
      display: flex;
      align-items: center;
      font-size: 0.85rem;
      color: var(--google-grey-dark);
    }

    .g-search-result-info-icon {
      margin-right: var(--space-xs);
    }

    /* Badges */
    .g-badge {
      display: inline-block;
      padding: var(--space-xs) var(--space-sm);
      border-radius: var(--radius-pill);
      font-size: 0.75rem;
      font-weight: 500;
      line-height: 1;
      text-align: center;
      white-space: nowrap;
      vertical-align: baseline;
      background-color: var(--google-grey-medium);
      color: var(--google-text);
    }

    .g-badge-blue {
      background-color: var(--google-blue);
      color: white;
    }

    .g-badge-red {
      background-color: var(--google-red);
      color: white;
    }

    .g-badge-green {
      background-color: var(--google-green);
      color: white;
    }

    .g-badge-yellow {
      background-color: var(--google-yellow);
      color: var(--google-text);
    }
/* Source badges */
.upload-badge {
    display: flex;
    align-items: center;
    background-color: #E8F5E9;
    padding: 4px 6px;
    border-radius: 4px;
    font-size: 12px;
    color: #2E7D32;
    margin-top: 5px;
}

.database-badge {
    display: flex;
    align-items: center;
    background-color: #E3F2FD;
    padding: 4px 6px;
    border-radius: 4px;
    font-size: 12px;
    color: #1565C0;
    margin-top: 5px;
}

.upload-badge i, .database-badge i {
    margin-right: 4px;
    font-size: 14px;
}
    /* Tags */
    .g-tag {
      display: inline-flex;
      align-items: center;
      padding: var(--space-xs) var(--space-sm);
      border-radius: var(--radius-sm);
      background-color: var(--google-grey-light);
      color: var(--google-text);
      font-size: 0.8rem;
      margin-right: var(--space-xs);
      margin-bottom: var(--space-xs);
    }

    .g-tag-blue {
      background-color: rgba(66, 133, 244, 0.1);
      color: var(--google-blue);
    }

    .g-tag-red {
      background-color: rgba(234, 67, 53, 0.1);
      color: var(--google-red);
    }

    .g-tag-green {
      background-color: rgba(52, 168, 83, 0.1);
      color: var(--google-green);
    }

    .g-tag-yellow {
      background-color: rgba(251, 188, 5, 0.1);
      color: #d29600;
    }

    /* Search pagination */
    .g-search-pagination {
      display: flex;
      align-items: center;
      justify-content: center;
      margin: var(--space-xl) 0;
      gap: var(--space-md);
    }

    .g-search-page {
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      color: var(--google-blue);
      font-size: 0.9rem;
      cursor: pointer;
      transition: background-color 0.2s;
    }
/* Wikipedia Sidebar Styling */
.wikipedia-sidebar {
    position: fixed;
    left: -350px; /* Start offscreen */
    top: 0;
    width: 350px;
    height: 100%;
    background-color: white;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
    z-index: 1000;
    overflow-y: auto;
    transition: left 0.3s ease-in-out;
    padding: 0;
}

.wikipedia-sidebar.active {
    left: 0; /* Slide in */
}

.wikipedia-sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: linear-gradient(90deg, 
        var(--google-blue) 0%, 
        var(--google-red) 33%, 
        var(--google-yellow) 66%, 
        var(--google-green) 100%);
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.wikipedia-sidebar-title {
    font-size: 1.2rem;
    font-weight: bold;
    margin: 0;
    display: flex;
    align-items: center;
}

.wikipedia-sidebar-title .wiki-logo {
    height: 24px;
    margin-right: 10px;
}

.wikipedia-sidebar-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background-color 0.2s;
}

.wikipedia-sidebar-close:hover {
    background-color: rgba(255, 255, 255, 0.2);
}

.wikipedia-sidebar-content {
    padding: 15px;
}

.wikipedia-result {
    margin-bottom: 20px;
    border-bottom: 1px solid var(--google-grey-medium);
    padding-bottom: 20px;
}

.wikipedia-result:last-child {
    border-bottom: none;
}

.wikipedia-result-title {
    font-size: 1.1rem;
    margin-bottom: 8px;
    color: #1a0dab;
}

.wikipedia-result-description {
    font-size: 0.9rem;
    color: var(--google-text);
    margin-bottom: 8px;
    line-height: 1.5;
}

.wikipedia-result-url {
    font-size: 0.8rem;
    color: var(--google-grey-dark);
    word-break: break-all;
}

/* Overlay when sidebar is active */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 999;
    display: none;
}

.sidebar-overlay.active {
    display: block;
}

/* Wikipedia button to open sidebar */
.wikipedia-button {
    position: fixed;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    background-color: var(--google-blue);
    color: white;
    border: none;
    border-radius: 0 4px 4px 0;
    padding: 10px;
    cursor: pointer;
    z-index: 998;
    box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: background-color 0.2s;
}

.wikipedia-button:hover {
    background-color: #3367d6;
}

.wikipedia-button i {
    font-size: 1.5rem;
    margin-bottom: 5px;
}

.wikipedia-button span {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
    font-size: 0.9rem;
    letter-spacing: 1px;
}
/* Improved Image Grid Styling */
.image-results {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 15px;
    width: 100%;
    max-width: 100%;
    margin-bottom: 20px;
}

.image-results > div {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    transition: transform 0.2s;
    background-color: #fff;
    height: auto;
}

.image-results > div:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.image-result {
    width: 100%;
    height: 150px;
    object-fit: cover;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
    display: block;
}

.image-info {
    padding: 10px;
}

.image-title {
    font-size: 14px;
    color: #1a0dab;
    margin-bottom: 5px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Wikipedia Image Styling */
.wiki-image-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
    width: 100%;
}

.wiki-image-item {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    transition: transform 0.2s;
    background-color: #fff;
    cursor: pointer;
}

.wiki-image-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.wiki-image {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
}

.wiki-image-title {
    padding: 8px;
    font-size: 13px;
    color: #1a0dab;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.wiki-image-source {
    padding: 0 8px 8px 8px;
    font-size: 12px;
    color: #70757a;
    display: flex;
    align-items: center;
}
// Add this to your CSS section
.wordpedia-button {
    position: fixed;
    left: 0;
    top: 60%; /* Position below the Wikipedia button (which is at 50%) */
    transform: translateY(-50%);
    background-color: #34A853; /* Google green color for distinction */
    color: white;
    border: none;
    border-radius: 0 4px 4px 0;
    padding: 10px;
    cursor: pointer;
    z-index: 998;
    box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: background-color 0.2s;
}

.wordpedia-button:hover {
    background-color: #2E7D32; /* Darker green on hover */
}

.wordpedia-button i {
    font-size: 1.5rem;
    margin-bottom: 5px;
}

.wordpedia-button span {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
    font-size: 0.9rem;
    letter-spacing: 1px;
}

/* Wordpedia sidebar styling */
.wordpedia-sidebar {
    position: fixed;
    left: -600px; /* Start offscreen */
    top: 0;
    width: 600px;
    height: 100%;
    background-color: white;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
    z-index: 1000;
    overflow-y: auto;
    transition: left 0.3s ease-in-out;
    padding: 0;
}

.wordpedia-sidebar.active {
    left: 0; /* Slide in */
}

.wordpedia-sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: linear-gradient(90deg, 
        #34A853 0%, 
        #2E7D32 100%);
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.wordpedia-sidebar-title {
    font-size: 1.2rem;
    font-weight: bold;
    margin: 0;
    display: flex;
    align-items: center;
}

.wordpedia-sidebar-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background-color 0.2s;
}

.wordpedia-sidebar-close:hover {
    background-color: rgba(255, 255, 255, 0.2);
}

.wordpedia-sidebar-content {
    padding: 15px;
}

/* Media query for mobile */
@media (max-width: 768px) {
    .wordpedia-sidebar {
        width: 85%;
        left: -85%;
    }
    
    .wordpedia-button {
        padding: 6px;
    }
    
    .wordpedia-button i {
        font-size: 1.2rem;
    }
    
    .wordpedia-button span {
        font-size: 0.8rem;
    }
}
.wiki-image-source img {
    width: 16px;
    height: 16px;
    margin-right: 5px;
}

.wiki-section-header {
    margin: 15px 0 10px 0;
    padding: 8px;
    background-color: #f8f9fa;
    border-radius: 8px;
    display: flex;
    align-items: center;
    width: 100%;
}

.wiki-section-header img {
    width: 20px;
    height: 20px;
    margin-right: 8px;
}

.wiki-section-header span {
    font-size: 16px;
    font-weight: 500;
    color: #202124;
}

.wiki-badge {
    display: flex;
    align-items: center;
    background-color: #f8f9fa;
    padding: 4px 6px;
    border-radius: 4px;
    font-size: 12px;
    color: #70757a;
    margin-top: 5px;
}

.wiki-badge img {
    width: 14px;
    height: 14px;
    margin-right: 4px;
}

/* Make sure the tag-list doesn't overflow */
.tag-list {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 5px;
}

.tag {
    background-color: #E8F0FE;
    color: #1a73e8;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    display: inline-block;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .image-results, .wiki-image-container {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    }
    
    .image-result, .wiki-image {
        height: 120px;
    }
}

@media (max-width: 480px) {
    .image-results, .wiki-image-container {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    }
}
/* Media query for mobile */
@media (max-width: 768px) {
    .wikipedia-sidebar {
        width: 85%;
        left: -85%;
    }
    
    .wikipedia-button {
        padding: 8px;
    }
    
    .wikipedia-button i {
        font-size: 1.2rem;
    }
    
    .wikipedia-button span {
        font-size: 0.8rem;
    }
}
    .g-search-page:hover {
      background-color: rgba(66, 133, 244, 0.1);
    }

    .g-search-page.active {
      background-color: var(--google-blue);
      color: white;
    }

    .g-search-page-prev,
    .g-search-page-next {
      color: var(--google-blue);
      cursor: pointer;
    }

    .g-search-page-prev.disabled,
    .g-search-page-next.disabled {
      color: var(--google-grey-medium);
      cursor: not-allowed;
    }

    /* Knowledge Panel */
    .g-knowledge-panel {
      border: 1px solid var(--google-grey-medium);
      border-radius: var(--radius-md);
      overflow: hidden;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
      margin-bottom: var(--space-lg);
    }

    .g-knowledge-header {
      padding: var(--space-md);
      border-bottom: 1px solid var(--google-grey-medium);
      display: flex;
      align-items: center;
      gap: var(--space-md);
    }

    .g-knowledge-image {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      object-fit: cover;
    }
/* PFS Button Styling */
.pfs-button {
    position: absolute;
    right: 110px; /* Position between upload and font selector buttons */
    top: 50%;
    transform: translateY(-50%);
    background-color: transparent;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    font-size: 14px;
    color: #5f6368;
    transition: all 0.3s ease, width 0.5s ease;
    padding: 5px 10px;
    border-radius: 20px;
    z-index: 10;
    overflow: hidden;
    width: auto;
    max-width: 150px;
}

.pfs-button:hover {
    background-color: rgba(60, 64, 67, 0.08);
}

.pfs-button .pfs-icon {
    margin-right: 5px;
    font-size: 16px;
}

.pfs-button.pfs-active {
    background-color: #34A853;
    color: white;
}

.pfs-button.pfs-active .pfs-icon {
    color: white;
}

/* Loading animation */
.pfs-button.loading {
    width: 50px; /* Shrink to a small circle */
    border-radius: 50%;
    background: linear-gradient(
        45deg, 
        #4285f4, 
        #34a853, 
        #fbbc05, 
        #ea4335
    );
    background-size: 400% 400%;
    animation: loadingPulse 1.5s ease infinite, gradientFlow 3s ease infinite;
    color: transparent;
}

.pfs-button.loading .pfs-icon {
    color: transparent;
}

@keyframes loadingPulse {
    0%, 100% { transform: translateY(-50%) scale(1); }
    50% { transform: translateY(-50%) scale(0.9); }
}

@keyframes gradientFlow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* Transition effects */
.pfs-button {
    transition: 
        width 0.5s cubic-bezier(0.4, 0, 0.2, 1), 
        background-color 0.3s ease,
        border-radius 0.5s ease;
}
    .g-knowledge-title {
      font-size: 1.4rem;
      margin: 0 0 var(--space-xs);
    }

    .g-knowledge-subtitle {
      color: var(--google-grey-dark);
      margin: 0;
    }

    .g-knowledge-body {
      padding: var(--space-md);
    }

    .g-knowledge-section {
      margin-bottom: var(--space-md);
    }

    .g-knowledge-section-title {
      font-size: 1rem;
      font-weight: 500;
      margin-bottom: var(--space-xs);
    }

    /* Gradient background for specific sections */
    .g-bg-gradient-wrapper {
      background: linear-gradient(135deg, 
        var(--google-blue) 0%, 
        var(--google-red) 33%, 
        var(--google-yellow) 66%, 
        var(--google-green) 100%);
      padding: 3px;
      border-radius: var(--radius-md);
      margin-bottom: var(--space-lg);
    }

    .g-bg-gradient-content {
      background: white;
      border-radius: calc(var(--radius-md) - 3px);
      padding: var(--space-md);
    }

    /* Image grid */
    .g-image-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap: var(--space-md);
      margin-bottom: var(--space-lg);
    }

    .g-image-item {
      position: relative;
      aspect-ratio: 4/3;
      border-radius: var(--radius-sm);
      overflow: hidden;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      cursor: pointer;
    }

    .g-image-item img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.3s ease;
    }

    .g-image-item:hover img {
      transform: scale(1.05);
    }

    .g-image-caption {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      padding: var(--space-xs) var(--space-sm);
      background: rgba(0, 0, 0, 0.7);
      color: white;
      font-size: 0.8rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* News section */
    .g-news-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
      gap: var(--space-lg);
      margin-bottom: var(--space-lg);
    }

    .g-news-item {
      border-radius: var(--radius-md);
      overflow: hidden;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .g-news-item:hover {
      transform: translateY(-5px);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .g-news-image {
      width: 100%;
      height: 150px;
      object-fit: cover;
    }

    .g-news-content {
      padding: var(--space-md);
    }

    .g-news-source {
      font-size: 0.8rem;
      color: var(--google-grey-dark);
      margin-bottom: var(--space-xs);
    }

    .g-news-title {
      font-size: 1.1rem;
      margin: 0 0 var(--space-sm);
      line-height: 1.3;
    }

    .g-news-time {
      font-size: 0.8rem;
      color: var(--google-grey-dark);
    }

    /* Button styles with sheens */
    .g-button {
      display: inline-block;
      background-color: var(--google-blue);
      color: white;
      font-family: var(--font-primary);
      font-weight: 500;
      padding: 0.5rem 1.5rem;
      border-radius: 4px;
      border: none;
      cursor: pointer;
      position: relative;
      overflow: hidden;
      transition: background-color 0.2s ease, box-shadow 0.2s ease;
      z-index: 1;
      text-decoration: none;
    }

    .g-button:hover {
      background-color: #3367d6;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
      text-decoration: none;
    }

    .g-button-outline {
      background-color: transparent;
      color: var(--google-blue);
      border: 1px solid var(--google-blue);
    }

    .g-button-outline:hover {
      background-color: rgba(66, 133, 244, 0.1);
      box-shadow: none;
      text-decoration: none;
    }

    .g-panel {
      background-color: var(--panel-bg);
      border-radius: 8px;
      box-shadow: 0 1px 3px var(--shadow-color), 0 2px 8px var(--shadow-color);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      position: relative;
      z-index: 1;
    }

    .g-panel:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px var(--shadow-color), 0 8px 16px var(--shadow-color);
    }

    .g-panel-gradient {
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(240, 240, 240, 0.9) 100%);
      border: none;
    }

    .g-panel-gradient-blue {
      background: linear-gradient(135deg, rgba(66, 133, 244, 0.1) 0%, rgba(66, 133, 244, 0.05) 100%);
      border-left: 3px solid var(--google-blue);
    }

    /* Media queries for responsiveness */
    @media (max-width: 768px) {
      .g-header-container {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      
      .container {
        padding: 0 0.5rem;
      }
      
      .g-search-result-info {
        flex-direction: column;
        gap: var(--space-xs);
      }

      .g-news-grid, .g-image-grid {
        grid-template-columns: 1fr;
      }
    }
    
    /* Quad color gradients - Fixed and animated */
    .g-bg-quad-gradient-animated {
      background: linear-gradient(90deg, 
        var(--google-blue) 0%, 
        var(--google-red) 33%, 
        var(--google-yellow) 66%, 
        var(--google-green) 100%);
      background-size: 400% 100%;
      color: white;
      animation: gradient-shift 15s ease infinite;
    }

    @keyframes gradient-shift {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: #f9f9f9;
        }
        .logo {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .logo span:nth-child(1) { color: #4285F4; }
        .logo span:nth-child(2) { color: #EA4335; }
        .logo span:nth-child(3) { color: #FBBC05; }
        .logo span:nth-child(4) { color: #4285F4; }
        .logo span:nth-child(5) { color: #34A853; }
        .logo span:nth-child(6) { color: #EA4335; }
        .logo-clone {
            font-size: 0.8rem;
            color: #777;
            margin-left: 4px;
        }
        .search-container {
            width: 100%;
            max-width: 600px;
            margin-bottom: 20px;
            position: relative;
        }
        .search-input {
            width: -moz-available;
            padding: 12px 50px 12px 20px;
            border-radius: 24px;
            border: 1px solid #ddd;
            font-size: 16px;
            outline: none;
            box-shadow: 0 1px 6px rgba(32, 33, 36, 0.28);
        }
        .search-input:focus {
            box-shadow: 0 1px 8px rgba(32, 33, 36, 0.45);
        }
        .search-button {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            cursor: pointer;
        }
        .upload-button {
            position: absolute;
            right: 50px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            cursor: pointer;
        }
        .tabs {
            display: flex;
            width: 100%;
            max-width: 600px;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            color: #5f6368;
            border-bottom: 3px solid transparent;
            margin-right: 10px;
            text-decoration: none;
        }
        .tab.active {
            color: #1a73e8;
            border-bottom-color: #1a73e8;
        }
        .results-container {
            width: 100%;
            max-width: 600px;
        }
        .result {
            margin-bottom: 20px;
        }
        .result-url {
            color: #202124;
            font-size: 14px;
        }
        .result-title {
            color: #1a0dab;
            font-size: 18px;
            margin: 4px 0;
            cursor: pointer;
        }
        .result-title:hover {
            text-decoration: underline;
        }
        .result-description {
            color: #4d5156;
            font-size: 14px;
            line-height: 1.5;
        }
        .image-results {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            width: 100%;
            max-width: 600px;
        }
        .image-result {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
        }
        .image-results > div {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .image-results > div:hover {
            transform: translateY(-5px);
        }
        .ip-form {
            width: 100%;
            max-width: 600px;
            margin-bottom: 20px;
        }
        .ip-input {
            width: 75%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px 0 0 4px;
            font-size: 16px;
            outline: none;
        }
        .ip-button {
            width: 25%;
            padding: 10px;
            background-color: #1a73e8;
            color: white;
            border: none;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
            font-size: 16px;
        }
        .ip-result {
            width: 100%;
            max-width: 600px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin-top: 10px;
        }
        .ip-result-row {
            display: flex;
            margin-bottom: 10px;
        }
        .ip-result-label {
            font-weight: bold;
            width: 120px;
        }
        .uploaded-image-preview {
            width: 100%;
            max-width: 600px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
        }
        .preview-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
            margin-right: 15px;
        }
        .file-input {
            display: none;
        }
        .analytics-link {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 8px 15px;
          
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background-color 0.3s;
        }
        .analytics-link:hover {
            background-color: #2E7D32;
        }
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
            padding: 5px 10px 10px 10px;
        }
        .tag {
            background-color: #E8F0FE;
            color: #1a73e8;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        /* Word Prediction Dropdown */
        .word-predictions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border-radius: 0 0 24px 24px;
            box-shadow: 0 4px 6px rgba(32, 33, 36, 0.28);
            z-index: 10;
            margin-top: 5px;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }
        .prediction-item {
            padding: 12px 20px;
            border-bottom: 1px solid #f1f1f1;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .prediction-item:hover {
            background-color: #f1f1f1;
        }
        .prediction-item:last-child {
            border-bottom: none;
            border-radius: 0 0 24px 24px;
        }
		        .nav-link {
            position: absolute;
            top: 20px;
            left: 20px;
            padding: 8px 15px;
            
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background-color 0.3s;
        }


/* Font Modal Styling */
.font-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    display: none;
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.font-modal {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(32, 33, 36, 0.2);
    display: hidden;
    flex-direction: column;
}

.font-modal-header {
    padding: 16px 24px;
    background: linear-gradient(90deg, 
        var(--google-blue) 0%, 
        var(--google-red) 33%, 
        var(--google-yellow) 66%, 
        var(--google-green) 100%);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
}

.font-modal-title {
    font-size: 18px;
    font-weight: 500;
    margin: 0;
    position: relative;
    z-index: 1;
}

.font-modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 18px;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s;
    position: relative;
    z-index: 1;
}

.font-modal-close:hover {
    background-color: rgba(255, 255, 255, 0.2);
}

.font-modal-search {
    padding: 16px 24px;
    border-bottom: 1px solid var(--google-grey-medium);
    position: relative;
}

.font-search-input {
    width: 100%;
    padding: 10px 16px;
    border: 1px solid var(--google-grey-medium);
    border-radius: 24px;
    font-size: 16px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.font-search-input:focus {
    border-color: var(--google-blue);
    box-shadow: 0 1px 3px rgba(66, 133, 244, 0.3);
}

.font-search-icon {
    position: absolute;
    right: 40px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--google-grey-dark);
    font-size: 16px;
}

.font-modal-body {
    padding: 0;
    overflow-y: auto;
    flex: 1;
}

.font-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.font-option {
    padding: 16px 24px;
    border-bottom: 1px solid var(--google-grey-medium);
    display: flex;
    align-items: center;
    cursor: pointer;
    transition: background-color 0.2s;
}

.font-option:last-child {
    border-bottom: none;
}

.font-option:hover {
    background-color: var(--google-grey-light);
}

.font-checkbox {
    margin-right: 16px;
    width: 18px;
    height: 18px;
    position: relative;
}

.font-checkbox input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}

.font-checkbox-mark {
    position: absolute;
    top: 0;
    left: 0;
    height: 18px;
    width: 18px;
    background-color: white;
    border: 2px solid var(--google-grey-dark);
    border-radius: 2px;
    transition: all 0.2s;
}

.font-checkbox input:checked ~ .font-checkbox-mark {
    background-color: var(--google-blue);
    border-color: var(--google-blue);
}

.font-checkbox-mark:after {
    content: "";
    position: absolute;
    display: none;
    left: 5px;
    top: 1px;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.font-checkbox input:checked ~ .font-checkbox-mark:after {
    display: block;
}

.font-details {
    flex: 1;
}

.font-preview {
    font-size: 18px;
    margin-bottom: 8px;
    line-height: 1.4;
}

.font-name {
    font-size: 14px;
    color: var(--google-grey-dark);
}

.font-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--google-grey-medium);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.font-modal-button {
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.2s;
    border: none;
}

.font-cancel-button {
    background-color: transparent;
    color: var(--google-grey-dark);
}

.font-cancel-button:hover {
    background-color: var(--google-grey-light);
}

.font-apply-button {
    background-color: var(--google-blue);
    color: white;
}

.font-apply-button:hover {
    background-color: #3367d6;
}

.font-notification {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background-color: #323232;
    color: white;
    padding: 12px 24px;
    border-radius: 4px;
    box-shadow: 0 6px 10px rgba(0, 0, 0, 0.14), 0 1px 18px rgba(0, 0, 0, 0.12);
    z-index: 1000;
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.3s, transform 0.3s;
    display: flex;
    align-items: center;
    font-size: 14px;
}

.font-notification.visible {
    opacity: 1;
    transform: translateY(0);
}

.font-notification-icon {
    margin-right: 12px;
    font-size: 20px;
}

/* Font selector button */
.font-selector-button {
    position: absolute;
    right: 80px; /* Position to the right of search icons */
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background-color: transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s;
    z-index: 5;
}

.font-selector-button:hover {
    background-color: rgba(60, 64, 67, 0.08);
}

.font-selector-icon {
    width: 20px;
    height: 20px;
    background-image: url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjNWY2MzY4IiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+CiAgPHBhdGggZD0iTTQuNSA5aDYuMjVjMS4xNCAwIDIuMjcuMyAzLjI2LjlsNi40MiA0LjItMS4yIDEuODUtNi40My00LjJjLS41LS4zLTEuMi0uNDUtMS44LS40MmgtLjc1YzEgMCAyIC4yIDMgLjdsNi40MyA0LjItMS4yIDEuODUtNi40My00LjJjLTEtLjctMi40LTEuNS0zLjUtMS41aC00LjV2LTkiLz4KICA8cGF0aCBkPSJNMTAgMjJsLTEtM2gtMmwtMSAzLTEtMyIvPgogIDxwYXRoIGQ9Ik02IDE5aDciLz4KPC9zdmc+');
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}
        /* Article specific styling */
        <?php echo $article_css; ?>
    </style>
	
	
</head>
<body>
<nav class="g-nav nav-link">
				<a href="../zapbird/index.html" class="g-nav-item">ZapBird</a>
				<a href="#" class="g-nav-item">
                    <i class="fas fa-user-circle" style="font-size: 1.5rem;"></i>
                </a> </nav>
    <!-- Analytics Link -->
    <a href="analytics_dashboard.php" class="analytics-link">📊 Analytics</a>
    
    <!-- Logo -->
    <div class="logo">
        <span>L</span><span>e</span><span>g</span><span>e</span><span>n</span><span>d</span>
        <span class="logo-clone">DX</span>
    </div>

    <!-- Search Form -->
    <div class="search-container">
	 <div class="g-logo">
                <div class="g-logo-quad">
                    <span class="g-logo-blue"></span>
                    <span class="g-logo-red"></span>
                    <span class="g-logo-yellow"></span>
                    <span class="g-logo-green"></span>
                </div>
               
				
            </div>
			<div class="g-search-wrapper" style="flex-grow: 1; max-width: 600px;">
        <input type="text" id="search-input" class="search-input" placeholder="Search Legend or type a URL" value="<?= isset($_GET['q']) ? htmlspecialchars($_GET['q']) : '' ?>" autocomplete="off">        
                
            <!-- Font selector button added between upload and search buttons -->
        <button type="button" id="fontSelectorButton" class="font-selector-button" title="Change font style">
            <span class="font-selector-icon"></span>
        </button> 
        <a href="image_uploader.php" class="upload-button" title="Upload Image">📤</a>
        <button type="button" id="search-button" class="search-button">🔍</button> </div>
        
                   
        <!-- Word Prediction Dropdown -->
        <div id="word-predictions" class="word-predictions">
            <!-- Predictions will be populated by JavaScript -->
        </div>

    </div>
<div class="font-modal-overlay" id="fontModalOverlay" style="display: none;">
    <div class="font-modal">
        <div class="font-modal-header">
            <h3 class="font-modal-title">Select a font</h3>
            <button class="font-modal-close" id="fontModalClose">✕</button>
        </div>
        <div class="font-modal-search">
            <input type="text" class="font-search-input" id="fontSearchInput" placeholder="Search fonts...">
            <i class="fas fa-search font-search-icon"></i>
        </div><div class="font-modal-footer">
            <button class="font-modal-button font-cancel-button" id="fontCancelButton">Cancel</button>
            <button class="font-modal-button font-apply-button" id="fontApplyButton">Apply</button>
        </div>
        <div class="font-modal-body">
            <ul style="height:280px; overflow:scroll;" class="font-list" id="fontList">
                <!-- Font options will be populated by JavaScript -->
            </ul>        
        </div>

    </div>
</div>

<div class="font-notification" id="fontNotification">
    <span class="font-notification-icon">✓</span>
    <span class="font-notification-text">Font applied successfully</span>
</div>
</div>

 <!-- Main Tabs -->
        <div class="g-tabs">
            <div class="g-tab" data-tab="all"><a href="?page=search<?= isset($_GET['q']) ? '&q='.urlencode($_GET['q']) : '' ?>" class="tab <?= $page == 'search' ? 'active' : '' ?>">🔍 All</a></div>
            <div class="g-tab" data-tab="images"><a href="?page=images<?= isset($_GET['q']) ? '&q='.urlencode($_GET['q']) : '' ?>" class="tab <?= $page == 'images' ? 'active' : '' ?>">🖼️ Images</a></div>
            <div class="g-tab" data-tab="videos"><a href="?page=videos<?= isset($_GET['q']) ? '&q='.urlencode($_GET['q']) : '' ?>" class="tab <?= $page == 'videos' ? 'active' : '' ?>">🎬 Videos</a></div>
			<div class="g-tab" data-tab="ipmapper"><a href="?page=ip" class="tab <?= $page == 'ip' ? 'active' : '' ?>">🌐 Resolve IP</a></div>
            <div class="g-tab" data-tab="articles"><a href="?page=articles" class="tab <?= $page == 'articles' ? 'active' : '' ?>">📰 Articles</a></div>
        </div>
    <!-- Tabs -->
 

    <!-- Uploaded Image Preview -->
    <?php if ($uploaded_image): ?>
    <div class="uploaded-image-preview">
        <img src="<?= $uploaded_image ?>" alt="Uploaded" class="preview-image">
        <div>Search by image: Finding visually similar results...</div>
    </div>
    <?php endif; ?>
<main class="container container-sm">
       
        <!-- Search Results HTML -->
<div class="g-tab-content active" id="all-content">
    <!-- Search filters for All tab -->
    <div class="g-search-filters">
        <a href="#" class="g-search-filter active">
            <i class="fas fa-search g-search-filter-icon"></i> All
        </a>
        <a href="#" class="g-search-filter">
            <i class="fas fa-clock g-search-filter-icon"></i> Recent
        </a>
        <a href="#" class="g-search-filter">
            <i class="fas fa-book g-search-filter-icon"></i> PDF
        </a>
        <a href="../dsa" class="g-search-filter">
            <i class="fas fa-tools g-search-filter-icon"></i> DSA
        </a>
    </div>
    
    <?php if (!empty($searchQuery)): ?>
        <!-- Search stats -->
        <div class="g-search-stats">
            About <?= formatNumber($totalResults > 0 ? $totalResults : getRandomResultCount()) ?> results (<?= $executionTime ?> seconds)
        </div>
      

        
        <!-- Search results -->
        <ul class="g-search-results">
            <?php
			   
    
    // Modify your existing search result generation

    // Helper function to encrypt with IV

            // Combined results - Wordpedia first, then database, then Wikipedia
            $combinedResults = array_merge($wordpediaResults, $searchResults, $wikipediaResults);
            
            foreach ($combinedResults as $result):
                $sourceIcon = '';
                $sourceClass = '';
                
                if (isset($result['source'])) {
                    switch($result['source']) {
                        case 'wordpedia':
                            $sourceIcon = '<i class="fas fa-book"></i>';
                            $sourceClass = 'g-badge-green';
                            break;
                        case 'wikipedia':
                            $sourceIcon = '<i class="fas fa-globe"></i>';
                            $sourceClass = 'g-badge-blue';
                            break;
                        default:
                            $sourceIcon = '<i class="fas fa-link"></i>';
                            break;
                    }
                }
            ?>
                <li class="g-search-result">
                    <!-- Replace the existing favicon image with this -->
					
<div class="g-search-result-url">
    <img src="<?= getPlaceholderFavicon(parse_url($result['url'], PHP_URL_HOST)) ?>" 
         alt="Favicon" 
         class="g-search-result-favicon">
    <?= htmlspecialchars(isset($result['url']) ? $result['url'] : '') ?>
</div>
                    <h3 class="g-search-result-title">
<a href="<?= htmlspecialchars(isset($result['url']) ? (strpos($result['url'], 'http') === 0 ? $result['url'] : 'https://' . $result['url']) : '#') ?>" 
   target="_blank">
    <?= htmlspecialchars(isset($result['title']) ? $result['title'] : '') ?>
</a>
                    </h3>
                    <div class="g-search-result-snippet">
                        <?= htmlspecialchars(isset($result['description']) ? $result['description'] : '') ?>
                    </div>
                    <div class="g-search-result-info">
                        <?php if (!empty($sourceIcon)): ?>
                            <span class="g-search-result-info-item">
                                <?= $sourceIcon ?> <?= ucfirst($result['source']) ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if (isset($result['frequency'])): ?>
                            <span class="g-search-result-info-item">
                                <i class="fas fa-chart-line"></i> Found <?= $result['frequency'] ?> times
                            </span>
                        <?php endif; ?>
                        
                        <?php if (!empty($sourceClass)): ?>
                            <span class="g-badge <?= $sourceClass ?>"><?= ucfirst($result['source']) ?></span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            
            <?php if (empty($combinedResults)): ?>
                <li class="g-search-result">
                    <h3>No results found for "<?= htmlspecialchars($searchQuery) ?>"</h3>
                    <p>Try different keywords or check your spelling.</p>
                    
                    <?php if (!empty($suggestedWords)): ?>
                        <div style="margin-top: 15px;">
                            <strong>Did you mean:</strong>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                                <?php foreach ($suggestedWords as $word): ?>
                                    <a href="?q=<?= urlencode($word) ?>" class="g-tag g-tag-blue"><?= htmlspecialchars($word) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        </ul>
        
        <!-- Search pagination -->
        <?php if (!empty($combinedResults) && count($combinedResults) > 5): ?>
        <?php endif; ?>
    <?php else: ?>
    <?php endif; ?>
</div>
           
        
        <!-- Images Tab Content -->
        <!-- Image Tab Content -->
<div class="g-tab-content <?= $page == 'images' ? 'active' : '' ?>" id="images-content">
    <?php if ($page == 'images'): ?>
        <?php if (isset($query) && !empty($query)): ?>
            <div class="g-search-stats">
                Found <?= count($image_results) ?> images matching "<?= htmlspecialchars($query) ?>"
            </div>
            
            <?php
            // Debug output (remove in production)
            if (isset($_GET['debug'])) {
                echo '<div style="background:#f0f0f0; padding:10px; margin:10px 0; border:1px solid #ccc;">';
                echo '<h4>Debug Info</h4>';
                echo '<p>Total images: ' . count($image_results) . '</p>';
                
                $sources = [];
                foreach ($image_results as $img) {
                    $src = isset($img['source']) ? $img['source'] : 'unknown';
                    if (!isset($sources[$src])) $sources[$src] = 0;
                    $sources[$src]++;
                }
                
                echo '<p>Sources breakdown: ';
                foreach ($sources as $src => $count) {
                    echo "$src: $count, ";
                }
                echo '</p>';
                
                echo '</div>';
            }
            ?>
        <?php endif; ?>
        
        <!-- Image Search Results -->
        <?php if (!empty($image_results)): ?>
            <div class="image-results">
                <?php foreach ($image_results as $image): ?>
                    <div class="image-item" data-source="<?= isset($image['source']) ? htmlspecialchars($image['source']) : 'unknown' ?>">
                        <a href="<?= $image['direct_url'] ?>" target="_blank">
                            <img src="<?= $image['url'] ?>" 
                                 alt="<?= htmlspecialchars($image['title']) ?>" 
                                 class="image-result">
                        </a>
                        <div class="image-info">
                            <div class="image-title">
                                <?= htmlspecialchars($image['title']) ?>
                            </div>
                            <?php if (!empty($image['tags'])): ?>
                            <div class="tag-list">
                                <?php 
                                $displayed_tags = array_slice($image['tags'], 0, 3);
                                foreach ($displayed_tags as $tag): 
                                    if (!empty($tag) && $tag != 'wikipedia' && $tag != 'uploads'):
                                ?>
                                <span class="tag"><?= htmlspecialchars($tag) ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                if (count($image['tags']) > 3):
                                ?>
                                <span class="tag">+<?= count($image['tags']) - 3 ?> more</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($image['source'])): ?>
                                <?php if ($image['source'] === 'uploads'): ?>
                                    <div class="upload-badge">
                                        <i class="fas fa-upload"></i> Uploaded
                                    </div>
                                <?php elseif ($image['source'] === 'database'): ?>
                                    <div class="database-badge">
                                        <i class="fas fa-database"></i> Library
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Section for Wikipedia images (will be populated by JavaScript) -->
            <div id="wikipedia-images-container"></div>
        <?php else: ?>
            <div style="text-align: center; margin: 40px 0;">
                <?php if (isset($query) && !empty($query)): ?>
                    <p>No image results found for "<?= htmlspecialchars($query) ?>"</p>
                <?php else: ?>
                    <p>Enter a search term to find images</p>
                <?php endif; ?>
                
            
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
        <div class="g-tab-content <?= $page == 'videos' ? 'active' : '' ?>" id="videos-content">
    <?php 
    // Include video functions
    require_once 'video-functions.php';
    
    // Get video results if we have a query
    if (isset($_GET['q']) && !empty($_GET['q'])) {
        $query = htmlspecialchars($_GET['q']);
        
        // Call searchVideos and store results
        $video_results = searchVideos($query);
        
        // Render the results
        echo renderVideoResults($video_results, $query);
        
        // Output debug info if debug parameter is set
        if (isset($_GET['debug'])) {
            echo "<div style='margin-top: 30px; padding: 10px; border: 1px solid #ccc; background: #f9f9f9;'>";
            echo "<h3>Debug Information</h3>";
            echo "<p>Query: " . htmlspecialchars($query) . "</p>";
            echo "<p>Results found: " . count($video_results) . "</p>";
            
            // Output emergency check for test.mp4
            if (strtolower($query) === 'test') {
                $result = emergencyCheckForTestVideo();
                echo "<h4>Emergency Check for test.mp4</h4>";
                echo "<pre>";
                foreach ($result['debug'] as $line) {
                    echo htmlspecialchars($line) . "\n";
                }
                echo "</pre>";
            }
            
            echo "</div>";
        }
    } else {
        // Show all videos if no query
        $video_results = loadVideos();
        echo renderVideoResults($video_results);
    }
    ?>
</div>
        <!-- IP Mapper Tab Content -->
        <div class="g-tab-content <?= $page == 'ip' ? 'active' : '' ?>" id="ipmapper-content">
            <!-- IP Mapper content here -->
        </div>
        
        <!-- Articles Tab Content -->
        <div class="g-tab-content <?= $page == 'articles' ? 'active' : '' ?>" id="articles-content">
		
            <!-- Article content is loaded in the main page content area -->
        </div>

    </main>




    <!-- Page Content -->
<?php if ($page == 'search' && !empty($results)): ?>
    <!-- Web Search Results -->
    <div class="results-container">
        <?php foreach ($results as $result): ?>
            <div class="result">
                <div class="result-url"><?= $result['url'] ?></div>
                <h3 class="result-title"><a href="<?= $result['url'] ?>" target="_blank"><?= $result['title'] ?></a></h3>
                <p class="result-description"><?= $result['description'] ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($page == 'images'): ?>
    <!-- Update the g-tab-content div to include the image results -->
    <div class="g-tab-content active" id="images-content">
        <?php if (!empty($query)): ?>
            <div class="g-search-stats">
                Found <?= count($image_results) ?> images matching "<?= htmlspecialchars($query) ?>"
            </div>
        <?php endif; ?>
        
        <!-- Image Search Results -->
        <?php if (!empty($image_results)): ?>
            <div class="image-results">
                <?php foreach ($image_results as $image): ?>
                    <div class="image-item" data-source="<?= isset($image['source']) ? htmlspecialchars($image['source']) : 'unknown' ?>">
                        <a href="<?= $image['direct_url'] ?>" target="_blank">
                            <img src="<?= $image['url'] ?>" 
                                 alt="<?= htmlspecialchars($image['title']) ?>" 
                                 class="image-result">
                        </a>
                        <div class="image-info">
                            <div class="image-title">
                                <?= htmlspecialchars($image['title']) ?>
                            </div>
                            <?php if (!empty($image['tags'])): ?>
                            <div class="tag-list">
                                <?php 
                                $displayed_tags = array_slice($image['tags'], 0, 3);
                                foreach ($displayed_tags as $tag): 
                                    if (!empty($tag) && $tag != 'wikipedia' && $tag != 'uploads'):
                                ?>
                                <span class="tag"><?= htmlspecialchars($tag) ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                if (count($image['tags']) > 3):
                                ?>
                                <span class="tag">+<?= count($image['tags']) - 3 ?> more</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($image['source']) && $image['source'] === 'uploads'): ?>
                            <!-- Badge for uploaded images -->
                            <div class="upload-badge">
                                <i class="fas fa-upload"></i> Uploaded
                            </div>
                            <?php elseif (isset($image['source']) && $image['source'] === 'database'): ?>
                            <!-- Badge for database images -->
                            <div class="database-badge">
                                <i class="fas fa-database"></i> Library
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Section for Wikipedia images (will be populated by JavaScript) -->
            <div id="wikipedia-images-container"></div>
            
            <div style="margin-top: 20px; text-align: center;">
                <a href="image_uploader.php" class="g-button">
                    <i class="fas fa-upload"></i> Upload New Image
                </a>
            </div>
        <?php else: ?>
            <div style="text-align: center; margin: 40px 0;">
                <p>No image results found for "<?= htmlspecialchars($query) ?>"</p>
                <a href="image_uploader.php" class="g-button" style="margin-top: 20px;">
                    <i class="fas fa-upload"></i> Upload New Image
                </a>
            </div>
        <?php endif; ?>
    </div>


    <?php elseif ($page == 'ip'): ?>
        <!-- IP Mapper -->
        <form class="ip-form" action="index.php" method="GET">
            <input type="hidden" name="page" value="ip">
            <div style="display: flex;">
                <input type="text" name="ip" class="ip-input" placeholder="Enter IP address (e.g. 192.168.1.1)" value="<?= isset($_GET['ip']) ? htmlspecialchars($_GET['ip']) : '' ?>">
                <button type="submit" class="ip-button">Lookup</button>
            </div>
        </form>
        
        <?php if ($ip_info): ?>
            <div class="ip-result">
                <div class="ip-result-row">
                    <div class="ip-result-label">IP Address:</div>
                    <div><?= htmlspecialchars($ip_info['ip']) ?></div>
                </div>
                <div class="ip-result-row">
                    <div class="ip-result-label">Hostname:</div>
                    <div><?= htmlspecialchars($ip_info['hostname']) ?></div>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($page == 'articles'): ?>
        <!-- Articles Tab -->
        <div class="container container-sm">

            
            <?php if ($single_article): ?>
                <!-- Single Article View -->
                <?= $single_article ?>
            <?php else: ?>
                <!-- Article Search Container -->

                
                <!-- Article List -->
                <div class="article-list">
                    <!-- Featured Article (Always First) -->
                    
                    <!-- Regular Articles from Database -->
                    <?= $articles_data ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    		            <!-- Featured snippet -->

    <script>
	// Function to decrypt the data from the server
// Generate an AES key for AES-GCM
async function generateAESKey() {
    return window.crypto.subtle.generateKey(
        {
            name: "AES-GCM",
            length: 256,  // AES key length (256 bits)
        },
        true,  // Key can be exported
        ["encrypt", "decrypt"]  // Allowed operations
    );
}

// Call the function to generate the AES key
generateAESKey().then(aesKey => {
    console.log('AES Key generated:', aesKey);
    // Now you can use this key for encryption
});
// Derive AES key from an RSA private and public key exchange
async function deriveSharedKey(privateKey, publicKey) {
    const sharedSecret = await window.crypto.subtle.deriveKey(
        {
            name: 'ECDH',
            public: publicKey
        },
        privateKey,
        {
            name: 'AES-GCM',
            length: 256
        },
        false,  // Key is not extractable
        ['encrypt', 'decrypt']
    );

    return sharedSecret;
}


class SecureSearchClient {
    constructor() {
        this.serverPublicKey = null;
        this.clientKeyPair = null;
       this.clientPublicKey = null;
        this.sharedSecret = null; // Your shared secret for encryption
        console.log('SecureSearchClient initialized');
    }
	// Generate an AES key for AES-GCM


async initKeyExchange() {
    try {
        // Generate an AES key for encryption/decryption
        const aesKey = await window.crypto.subtle.generateKey(
            {
                name: 'AES-GCM',
                length: 256
            },
            true, // Extractable
            ['encrypt', 'decrypt'] // Use the key for encryption/decryption
        );

        // Store the AES key as the shared secret
        this.sharedSecret = aesKey;
        
        // For demonstration, also create a public/private key pair
        // (In a real secure implementation, you would use this for key exchange)
        const keyPair = await window.crypto.subtle.generateKey(
            {
                name: 'RSA-OAEP',
                modulusLength: 2048,
                publicExponent: new Uint8Array([1, 0, 1]), // 65537
                hash: 'SHA-256',
            },
            true, // Extractable
            ['encrypt', 'decrypt'] // Use the key pair for encryption/decryption
        );

        // Store the public key
        this.clientPublicKey = await window.crypto.subtle.exportKey('spki', keyPair.publicKey);
        console.log('Client public key generated:', this.clientPublicKey);

        return true; // Indicate that the key exchange was successful
    } catch (error) {
        console.error('Error during key exchange:', error);
        return false; // Indicate failure
    }
}

    // You can call this method to check if the public key is ready
    isPublicKeyReady() {
        return this.clientPublicKey !== null;
    }

async performSearch(query) {
    console.log(`Performing secure search for: "${query}"`);

    if (!this.sharedSecret || !(this.sharedSecret instanceof CryptoKey)) {
        console.error('Shared secret key is missing or invalid!');
        return false;
    }

    try {
        console.log('Encrypting search query...');
        const encodedQuery = new TextEncoder().encode(query);
        const iv = window.crypto.getRandomValues(new Uint8Array(12));  // Generate a random IV
        console.log('Using IV:', Array.from(iv).map(b => b.toString(16).padStart(2, '0')).join(''));

        // Encrypt the query using AES-GCM
        const encryptedQuery = await window.crypto.subtle.encrypt(
            {
                name: 'AES-GCM',
                iv: iv
            },
            this.sharedSecret,  // Ensure shared secret is an AES key
            encodedQuery
        );

        console.log('Query encrypted successfully');
        const encryptedQueryBase64 = btoa(String.fromCharCode.apply(null, new Uint8Array(encryptedQuery)));
        console.log('Encrypted query (base64):', encryptedQueryBase64.substring(0, 20) + '...');

        // Send the encrypted query to the server
        const response = await fetch('pfs_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'secure_search',
                client_public: btoa(String.fromCharCode.apply(null, new Uint8Array(this.clientPublicKey))),  // Public key in base64
                encrypted_query: encryptedQueryBase64
            })
        });

        const responseText = await response.text();
        console.log('Raw server response:', responseText);

        let responseData;
        try {
            responseData = JSON.parse(responseText);  // Attempt to parse JSON response
            console.log('Server response received:', responseData);
        } catch (error) {
            console.error('Failed to parse response as JSON:', error);
            return false;
        }

        const { encrypted_results } = responseData;
        if (!encrypted_results) {
            console.error('Encrypted results not found in server response');
            return false;
        }

        // Store encrypted results for decryption
        this.encryptedResults = encrypted_results;
        console.log('Encrypted results stored for decryption');
        
        return true;
    } catch (error) {
        console.error('Error performing secure search:', error);
        return false;
    }
}



    async decryptResults() {
        console.log('Starting decryption of search results...');
        try {
            // Decrypt results using shared secret
            console.log('Parsing encrypted result data...');
            const resultData = JSON.parse(atob(this.encryptedResults));
            console.log('Encrypted result structure:', Object.keys(resultData).join(', '));
            
            console.log('Extracting IV and ciphertext...');
            const iv = new Uint8Array(atob(resultData.iv).split('').map(c => c.charCodeAt(0)));
            const ciphertext = new Uint8Array(atob(resultData.ciphertext).split('').map(c => c.charCodeAt(0)));
            
            console.log('IV length:', iv.length);
            console.log('Ciphertext length:', ciphertext.length);
            
            console.log('Decrypting results...');
            const decryptedResults = await window.crypto.subtle.decrypt(
                {
                    name: 'AES-GCM',
                    iv: iv,
                    additionalData: new ArrayBuffer(0),
                    tagLength: 128
                },
                this.sharedSecret,
                ciphertext
            );
            console.log('Results decrypted successfully');

            // Convert decrypted results to readable format
            const decryptedText = new TextDecoder().decode(decryptedResults);
            console.log('Decrypted text (first 100 chars):', decryptedText.substring(0, 100) + '...');
            
            const results = JSON.parse(decryptedText);
            console.log('Parsed results:', results);
            
            // Render results
            console.log('Rendering results to DOM...');
            this.renderResults(results);
            console.log('Results rendered successfully');
            return true;
        } catch (error) {
            console.error('Error decrypting results:', error);
            return false;
        }
    }

    renderResults(results) {
        console.log('Rendering results to page...');
        
        // Implement your result rendering logic
        const resultsContainer = document.getElementById('search-results');
        if (!resultsContainer) {
            console.error('Results container not found in DOM');
            return false;
        }
        
        console.log('Clearing previous results...');
        resultsContainer.innerHTML = ''; // Clear previous results

        // Render word results
        if (results.word_results && results.word_results.length > 0) {
            console.log(`Rendering ${results.word_results.length} word results`);
            results.word_results.forEach((word, index) => {
                console.log(`Rendering word result ${index + 1}:`, word);
                const wordElement = document.createElement('div');
                wordElement.textContent = `Word: ${word.word}, Frequency: ${word.frequency}`;
                resultsContainer.appendChild(wordElement);
            });
        } else {
            console.log('No word results to render');
        }

        // Render site results
        if (results.site_results && results.site_results.length > 0) {
            console.log(`Rendering ${results.site_results.length} site results`);
            results.site_results.forEach((site, index) => {
                console.log(`Rendering site result ${index + 1}:`, site.title);
                const siteElement = document.createElement('div');
                siteElement.textContent = `Site: ${site.title}, URL: ${site.url}`;
                resultsContainer.appendChild(siteElement);
            });
        } else {
            console.log('No site results to render');
        }
        
        console.log('All results rendered successfully');
        return true;
    }
}

// Usage
document.addEventListener('DOMContentLoaded', async () => {
    console.log('DOM fully loaded, initializing secure search...');
    const secureSearch = new SecureSearchClient();
    
    // Initialize key exchange
    console.log('Starting initial key exchange...');
    const keyExchangeResult = await secureSearch.initKeyExchange();
    console.log('Key exchange completed:', keyExchangeResult ? 'Success' : 'Failed');

    // Search button handler
    console.log('Setting up search button handler...');
    const searchBtn = document.getElementById('search-button');
    if (searchBtn) {
        searchBtn.addEventListener('click', async () => {
            console.log('Search button clicked');
            const searchInput = document.getElementById('search-input');
            if (!searchInput) {
                console.error('Search input element not found');
                return;
            }
            
            const query = searchInput.value;
            console.log('Search query:', query);
            
            if (!query.trim()) {
                console.warn('Empty search query, aborting search');
                return;
            }
            
            await secureSearch.performSearch(query);
            console.log('Search completed');
        });
        console.log('Search button handler set up successfully');
    } else {
        console.error('Search button element not found');
    }

    // Decrypt button handler
    console.log('Setting up decrypt button handler...');
    const decryptBtn = document.getElementById('decrypt-button');
    if (decryptBtn) {
        decryptBtn.addEventListener('click', async () => {
            console.log('Decrypt button clicked');
            await secureSearch.decryptResults();
            console.log('Decryption and rendering completed');
        });
        console.log('Decrypt button handler set up successfully');
    } else {
        console.error('Decrypt button element not found');
    }
    
    console.log('Secure search initialization complete');
});

document.addEventListener('DOMContentLoaded', function() {
    console.log('Setting up search UI components...');
    const searchInput = document.getElementById('search-input');
    const searchButton = document.getElementById('search-button');
    const predictionsContainer = document.getElementById('word-predictions');
    const imageTabContent = document.getElementById('images-content');
    let debounceTimer;
    
    if (searchInput) {
        console.log('Search input found');
    } else {
        console.error('Search input element not found');
    }
    
    if (searchButton) {
        console.log('Search button found');
    } else {
        console.error('Search button element not found');
    }
    
    if (predictionsContainer) {
        console.log('Predictions container found');
    } else {
        console.warn('Predictions container element not found');
    }
    
    if (imageTabContent) {
        console.log('Image tab content found');
    } else {
        console.warn('Image tab content element not found');
    }
    
    console.log('Search UI components setup complete');
});

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            const searchButton = document.getElementById('search-button');
            const predictionsContainer = document.getElementById('word-predictions');
			const imageTabContent = document.getElementById('images-content');
            let debounceTimer;
             async function fetchWikipediaImages(searchTerm) {
        // Split the search term into individual words
        const words = searchTerm.split(/\s+/).filter(word => word.length > 2);
        let allImages = [];
        
        // Process each word
        for (const word of words) {
            try {
                // First search for the term to get better results
                const searchUrl = `https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=${encodeURIComponent(word)}&format=json&origin=*`;
                
                const searchResponse = await fetch(searchUrl);
                const searchData = await searchResponse.json();
                
                if (searchData.query && searchData.query.search && searchData.query.search.length > 0) {
                    // Get the page title from search results
                    const pageTitle = searchData.query.search[0].title;
                    
                    // Now get images from the page
                    const url = `https://en.wikipedia.org/w/api.php?action=query&titles=${encodeURIComponent(pageTitle)}&prop=images&format=json&origin=*`;
                    
                    const response = await fetch(url);
                    const data = await response.json();
                    
                    // Extract page IDs
                    const pages = data.query.pages;
                    const pageId = Object.keys(pages)[0];
                    
                    if (pageId !== '-1' && pages[pageId].images) {
                        // Get image titles from the page, but filter out icons, logos, and SVGs
                        const imageTitles = pages[pageId].images
                            .map(img => img.title)
                            .filter(title => 
                                !title.toLowerCase().includes('icon') && 
                                !title.toLowerCase().includes('logo') &&
                                !title.toLowerCase().endsWith('.svg'));
                        
                        // For each image title, get the image URL
                        for (const imageTitle of imageTitles.slice(0, 3)) { // Limit to 3 images per word
                            const imageUrl = await getImageUrl(imageTitle);
                            if (imageUrl && !imageUrl.endsWith('.svg')) {
                                allImages.push({
                                    url: imageUrl,
                                    title: `${pageTitle} - ${imageTitle.replace('File:', '')}`,
                                    source: 'Wikipedia',
                                    word: word,
                                    pageTitle: pageTitle
                                });
                            }
                        }
                    }
                }
            } catch (error) {
                console.error(`Error fetching Wikipedia data for ${word}:`, error);
            }
        }
        
        return allImages;
    }
    
    // Function to get the actual image URL from image title
    async function getImageUrl(imageTitle) {
        try {
            const url = `https://en.wikipedia.org/w/api.php?action=query&titles=${encodeURIComponent(imageTitle)}&prop=imageinfo&iiprop=url&format=json&origin=*`;
            
            const response = await fetch(url);
            const data = await response.json();
            
            const pages = data.query.pages;
            const pageId = Object.keys(pages)[0];
            
            if (pages[pageId].imageinfo && pages[pageId].imageinfo.length > 0) {
                return pages[pageId].imageinfo[0].url;
            }
        } catch (error) {
            console.error(`Error fetching image URL for ${imageTitle}:`, error);
        }
        
        return null;
    }
    
    function displayWikipediaImages(images) {
    const imageTabContent = document.getElementById('images-content');
    if (!imageTabContent) return;
    
    // Clear any existing content if needed
    const existingWikiContent = imageTabContent.querySelector('.wiki-main-header');
    if (existingWikiContent) {
        // Remove existing Wikipedia content
        let toRemove = imageTabContent.querySelector('.wiki-main-header');
        while (toRemove) {
            toRemove.nextElementSibling.remove();
            toRemove.remove();
            toRemove = imageTabContent.querySelector('.wiki-main-header');
        }
    }
    
    // Group images by their page title
    const groupedImages = {};
    images.forEach(image => {
        const key = image.pageTitle || image.word;
        if (!groupedImages[key]) {
            groupedImages[key] = [];
        }
        groupedImages[key].push(image);
    });
    
    if (Object.keys(groupedImages).length > 0) {
        // Add the "Wikipedia Images" header
        const mainHeader = document.createElement('h2');
        mainHeader.textContent = 'Wikipedia Images';
        mainHeader.className = 'wiki-main-header';
        mainHeader.style.color = '#34A853';
        mainHeader.style.borderBottom = '2px solid #34A853';
        mainHeader.style.padding = '10px 0';
        mainHeader.style.marginTop = '30px';
        
        imageTabContent.appendChild(mainHeader);
        
        // Process each group
        Object.keys(groupedImages).forEach(pageTitle => {
            // Create section header
            const sectionHeader = document.createElement('div');
            sectionHeader.className = 'wiki-section-header';
            sectionHeader.innerHTML = `
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/8/80/Wikipedia-logo-v2.svg/23px-Wikipedia-logo-v2.svg.png" 
                     alt="Wikipedia">
                <span>Images for: ${pageTitle}</span>
            `;
            
            // Create image container for this group
            const groupContainer = document.createElement('div');
            groupContainer.className = 'wiki-image-container';
            
            // Add images to the grid
            groupedImages[pageTitle].forEach(image => {
                const imageItem = document.createElement('div');
                imageItem.className = 'wiki-image-item';
                
                // Add click event
                imageItem.addEventListener('click', () => {
                    window.open(image.url, '_blank');
                });
                
                // Create image element
                const img = document.createElement('img');
                img.src = image.url;
                img.alt = image.title;
                img.className = 'wiki-image';

                
                // Create title element
                const title = document.createElement('div');
                title.className = 'wiki-image-title';
                title.textContent = image.title.replace('File:', '').replace(/_/g, ' ').replace(/\.(jpg|png|gif|jpeg)/i, '');
                
                // Create source badge
                const sourceBadge = document.createElement('div');
                sourceBadge.className = 'wiki-image-source';
                sourceBadge.innerHTML = `
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/8/80/Wikipedia-logo-v2.svg/23px-Wikipedia-logo-v2.svg.png" 
                         alt="Wikipedia"> 
                    From: Wikipedia
                `;
                
                // Assemble the image item
                imageItem.appendChild(img);
                imageItem.appendChild(title);
                imageItem.appendChild(sourceBadge);
                groupContainer.appendChild(imageItem);
            });
            
            // Add to the main container
            imageTabContent.appendChild(sectionHeader);
            imageTabContent.appendChild(groupContainer);
        });
    }
}

// Function to ensure existing image results are properly displayed in a grid
function formatExistingImageResults() {
    const imageResults = document.querySelector('.image-results');
    if (!imageResults) return;
    
    // Convert any direct child images to proper grid items
    const directImages = imageResults.querySelectorAll(':scope > img.image-result');
    directImages.forEach(img => {
        // Create wrapper div for the image
        const wrapper = document.createElement('div');
        wrapper.className = 'image-item';
        
        // Get image attributes
        const src = img.src;
        const alt = img.alt;
        const href = img.parentElement?.href || '#';
        
        // Create proper structure
        wrapper.innerHTML = `
            <a href="${href}" target="_blank">
                <img src="${src}" alt="${alt}" class="image-result">
            </a>
            <div class="image-info">
                <div class="image-title">${alt}</div>
            </div>
        `;
        
        // Replace the image with the wrapper
        img.parentElement.replaceChild(wrapper, img);
    });
}

// Call this when the page loads
document.addEventListener('DOMContentLoaded', function() {
    // Format any existing image results
    formatExistingImageResults();
    
    // Apply the updated CSS
    const styleElement = document.createElement('style');
    styleElement.textContent = `
        /* Copy the CSS from the CSS artifact here */
        /* This ensures the styling is applied immediately */
    `;
    document.head.appendChild(styleElement);
});

// Make sure to update the handleSearchResult function if it exists
// This is a function stub in case it doesn't exist in your code
function handleSearchResult(query) {
    // Format results after they load
    setTimeout(formatExistingImageResults, 100);
    
    // If you're fetching Wikipedia images
    fetchWikipediaImages(query).then(images => {
        if (images && images.length > 0) {
            displayWikipediaImages(images);
        }
    });
}
    
    // Handle search form submission
    if (searchButton && searchInput) {
        searchButton.addEventListener('click', async function() {
            const query = searchInput.value.trim();
            if (query) {
                // Fetch Wikipedia images
                const images = await fetchWikipediaImages(query);
                if (images.length > 0) {
                    displayWikipediaImages(images);
                    
                    // Switch to the Images tab
                    const imageTab = document.querySelector('[data-tab="images"]');
                    if (imageTab) {
                        imageTab.click();
                    }
                }
            }
        });
        
        // Also handle Enter key
        searchInput.addEventListener('keydown', async function(e) {
            if (e.key === 'Enter') {
                const query = searchInput.value.trim();
                if (query) {
                    // Fetch Wikipedia images
                    const images = await fetchWikipediaImages(query);
                    if (images.length > 0) {
                        displayWikipediaImages(images);
                    }
                }
            }
        });
    }
    
    // Check if we already have a search query in the URL
    const urlParams = new URLSearchParams(window.location.search);
    const searchQuery = urlParams.get('q');
    
    if (searchQuery && searchInput) {
        // Fill the search input with the query
        searchInput.value = searchQuery;
        
        // Fetch Wikipedia images for the query
        fetchWikipediaImages(searchQuery).then(images => {
            if (images.length > 0) {
                displayWikipediaImages(images);
            }
        });
    }
            // Search input event - look up words as user types
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                // Clear previous timer
                clearTimeout(debounceTimer);
                
                // Hide predictions if query is empty
                if (!query) {
                    predictionsContainer.style.display = 'none';
                    return;
                }
                
                // Set minimal debounce timer
                debounceTimer = setTimeout(() => {
                    // Direct request to get matching words from database
                    fetch(`?term=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length > 0) {
                                // Generate predictions HTML
                                let predictionsHTML = '';
                                
                                data.forEach((word) => {
                                    predictionsHTML += `
                                        <div class="prediction-item" data-word="${word}">
                                            ${word}
                                        </div>
                                    `;
                                });
                                
                                // Update and show predictions
                                predictionsContainer.innerHTML = predictionsHTML;
                                predictionsContainer.style.display = 'block';
                                
                                // Add click event to predictions
                                document.querySelectorAll('.prediction-item').forEach(item => {
                                    item.addEventListener('click', function() {
                                        const word = this.getAttribute('data-word');
                                        searchInput.value = word;
                                        predictionsContainer.style.display = 'none';
                                        
                                        // Submit search
                                        window.location.href = `?page=search&q=${encodeURIComponent(word)}`;
                                    });
                                });
                            } else {
                                predictionsContainer.style.display = 'none';
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching predictions:', error);
							predictionsContainer.style.display = 'none';
                        });
                }, 100); // Only 100ms delay for responsiveness
            });
            
            // Search button click
            searchButton.addEventListener('click', function() {
                const query = searchInput.value.trim();
                if (query) {
                    window.location.href = `?page=search&q=${encodeURIComponent(query)}`;
                }
            });
            
            // Search input enter key
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const query = this.value.trim();
                    if (query) {
                        window.location.href = `?page=search&q=${encodeURIComponent(query)}`;
                    }
                } else if (e.key === 'Escape') {
                    predictionsContainer.style.display = 'none';
                }
            });
            
            // Arrow key navigation in dropdown
            searchInput.addEventListener('keydown', function(e) {
                const predictions = document.querySelectorAll('.prediction-item');
                if (!predictions.length) return;
                
                let currentSelected = -1;
                
                // Find if any item is currently selected
                predictions.forEach((item, index) => {
                    if (item.classList.contains('active')) {
                        currentSelected = index;
                    }
                });
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    // Select next item
                    currentSelected = (currentSelected + 1) % predictions.length;
                    highlightPrediction(predictions, currentSelected);
                    searchInput.value = predictions[currentSelected].getAttribute('data-word');
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    // Select previous item
                    currentSelected = (currentSelected - 1 + predictions.length) % predictions.length;
                    highlightPrediction(predictions, currentSelected);
                    searchInput.value = predictions[currentSelected].getAttribute('data-word');
                }
            });
            
            // Function to highlight a prediction
            function highlightPrediction(predictions, index) {
                predictions.forEach(item => item.classList.remove('active'));
                if (index >= 0 && index < predictions.length) {
                    predictions[index].classList.add('active');
                    predictions[index].scrollIntoView({ block: 'nearest' });
                }
            }
            
            // Hide predictions when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !predictionsContainer.contains(e.target)) {
                    predictionsContainer.style.display = 'none';
                }
            });
            
            // Handle article category clicks
            const articleCategories = document.querySelectorAll('.article-category');
            if (articleCategories.length) {
                articleCategories.forEach(category => {
                    category.addEventListener('click', function() {
                        // Remove active class from all categories
                        articleCategories.forEach(cat => cat.classList.remove('active'));
                        // Add active class to clicked category
                        this.classList.add('active');
                        
                        // In a real implementation, this would filter articles by category
                        // For now, we'll just show a message
                        const categoryName = this.textContent;
                        if (categoryName !== 'All') {
                            alert(`Filtering articles by category: ${categoryName}`);
                            // In a real implementation:
                            // window.location.href = `?page=articles&category=${encodeURIComponent(categoryName)}`;
                        }
                    });
                });
            }
        });
    </script>
    <!-- Header -->

    <!-- Main content -->
        <!-- Footer -->
    <footer style="background-color: var(--google-grey-light); border-top: 1px solid var(--google-grey-medium); padding: var(--space-lg) 0; margin-top: var(--space-xl);">
        <div class="container">

			          <main class="container container-sm">  <div class="g-bg-gradient-wrapper">
                <div class="g-bg-gradient-content">
                                <div style="display: flex; flex-wrap: wrap; gap: var(--space-lg); justify-content: space-between; margin-bottom: var(--space-lg);">
                <div>
                    <a href="https://jcmc.serveminecraft.net/legenddx/rs.php">
                    <p style="color: var(--google-grey-dark); margin-bottom: var(--space-xs);">Register a site</p></a>
                    <a href="https://jcmc.serveminecraft.net/vip"><p style="color: var(--google-grey-dark); margin-bottom: 0;">Forum*</p></a>
                </div>
            </div>
                      
                    </div>
                </div>
            </div></main>
            <div style="text-align: center; color: var(--google-grey-dark); font-size: 0.9rem;">
                © 2025 NetworkSearch - Privacy - Terms
            </div>
        </div>
    </footer>
    <!-- JavaScript for tab functionality -->
	<script>
// JavaScript for search functionality
document.addEventListener('DOMContentLoaded', function() {
    // Handle search filter clicks
    const searchFilters = document.querySelectorAll('.g-search-filter');
    
    searchFilters.forEach(filter => {
        filter.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all filters
            searchFilters.forEach(f => f.classList.remove('active'));
            
            // Add active class to clicked filter
            this.classList.add('active');
            
            // In a real implementation, this would update the search results
            // For now, we'll just show an alert
            const filterType = this.textContent.trim();
            console.log(`Filter clicked: ${filterType}`);
        });
    });
    
    // Handle pagination clicks
    const paginationItems = document.querySelectorAll('.g-search-page');
    
    paginationItems.forEach(item => {
        item.addEventListener('click', function() {
            // Remove active class from all pagination items
            paginationItems.forEach(i => i.classList.remove('active'));
            
            // Add active class to clicked item
            this.classList.add('active');
            
            // In a real implementation, this would load the next page of results
            // For now, we'll just show an alert
            const page = this.textContent.trim();
            console.log(`Page clicked: ${page}`);
        });
    });
});
</script>
    <script>
	
        document.addEventListener('DOMContentLoaded', function() {
            // Tab functionality
            const tabs = document.querySelectorAll('.g-tab');
            const tabContents = document.querySelectorAll('.g-tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    // Skip if tab contains an <a> element (these handle their own navigation)
                    if (tab.querySelector('a')) {
                        return;
                    }
                    
                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked tab
                    tab.classList.add('active');
                    
                    // Show corresponding content
                    const tabId = tab.getAttribute('data-tab');
                    const content = document.getElementById(`${tabId}-content`);
                    if (content) {
                        content.classList.add('active');
                    }
                });
            });
            
            // FAQ accordion functionality
            const faqItems = document.querySelectorAll('.faq-item');
            
            faqItems.forEach(item => {
                item.addEventListener('click', () => {
                    const answer = item.querySelector('.faq-answer');
                    const icon = item.querySelector('i');
                    
                    // Toggle display
                    if (answer.style.display === 'none' || !answer.style.display) {
                        answer.style.display = 'block';
                        icon.classList.remove('fa-chevron-down');
                        icon.classList.add('fa-chevron-up');
                    } else {
                        answer.style.display = 'none';
                        icon.classList.remove('fa-chevron-up');
                        icon.classList.add('fa-chevron-down');
                    }
                });
            });
        });
		// Wikipedia Sidebar Functionality

document.addEventListener('DOMContentLoaded', function() {
    // Create Wikipedia sidebar elements if they don't exist
    if (!document.querySelector('.wikipedia-sidebar')) {
        createWikipediaSidebar();
    }
    
    // Initialize sidebar toggle functionality
    initWikipediaSidebar();
    
    // Process any existing Wikipedia results
    processWikipediaResults();
});

// Function to create Wikipedia sidebar elements
function createWikipediaSidebar() {
    // Create overlay
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
    
    // Create sidebar
    const sidebar = document.createElement('div');
    sidebar.className = 'wikipedia-sidebar';
    sidebar.innerHTML = `
        <div class="wikipedia-sidebar-header">
            <h3 class="wikipedia-sidebar-title">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/8/80/Wikipedia-logo-v2.svg/103px-Wikipedia-logo-v2.svg.png" 
                     alt="Wikipedia" class="wiki-logo">
                Wikipedia Results
            </h3>
            <button class="wikipedia-sidebar-close" title="Close">×</button>
        </div>
        <div class="wikipedia-sidebar-content"></div>
    `;
    document.body.appendChild(sidebar);
    
    // Create toggle button
    const toggleButton = document.createElement('button');
    toggleButton.className = 'wikipedia-button';
    toggleButton.innerHTML = `
        <i class="fas fa-book"></i>
        <span>Wiki</span>
    `;
    toggleButton.style.display = 'none'; // Hidden by default until we have results
    document.body.appendChild(toggleButton);
}

// Function to initialize sidebar functionality
function initWikipediaSidebar() {
    const sidebar = document.querySelector('.wikipedia-sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const closeButton = document.querySelector('.wikipedia-sidebar-close');
    const toggleButton = document.querySelector('.wikipedia-button');
    
    // Toggle button click
    toggleButton.addEventListener('click', function() {
        sidebar.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent scrolling
    });
    
    // Close button click
    closeButton.addEventListener('click', function() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = ''; // Restore scrolling
    });
    
    // Overlay click
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = ''; // Restore scrolling
    });
    
    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = ''; // Restore scrolling
        }
    });
}

// Function to process Wikipedia results
function processWikipediaResults() {
    // Check if we have Wikipedia results
    const wikiResults = [];
    
    // Look for results that contain "Wikipedia" in the title
    document.querySelectorAll('.result').forEach(function(result) {
        const titleElement = result.querySelector('.result-title');
        if (titleElement && titleElement.textContent.includes('Wikipedia')) {
            wikiResults.push({
                title: titleElement.textContent,
                url: titleElement.querySelector('a').href,
                description: result.querySelector('.result-description').textContent
            });
            
            // Hide the Wikipedia result from the main results list
            result.style.display = 'none';
        }
    });
    
    // If we have Wikipedia results, show them in the sidebar
    if (wikiResults.length > 0) {
        const sidebarContent = document.querySelector('.wikipedia-sidebar-content');
        let resultsHTML = '';
        
        wikiResults.forEach(function(result) {
            resultsHTML += `
                <div class="wikipedia-result">
                    <h4 class="wikipedia-result-title">
                        <a href="${result.url}" target="_blank">${result.title}</a>
                    </h4>
                    <p class="wikipedia-result-description">${result.description}</p>
                    <div class="wikipedia-result-url">${result.url}</div>
                </div>
            `;
        });
        
        sidebarContent.innerHTML = resultsHTML;
        
        // Show the toggle button
        document.querySelector('.wikipedia-button').style.display = 'flex';
        
        // Auto-open the sidebar on page load
        setTimeout(function() {
            document.querySelector('.wikipedia-sidebar').classList.add('active');
            document.querySelector('.sidebar-overlay').classList.add('active');
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        }, 500); // Slight delay for better user experience
    }
}

// Function to be called when new search results are loaded
function updateWikipediaResults(results) {
    // This function would be called when AJAX loads new search results
    // It would update the sidebar with new Wikipedia results
    
    if (!results || results.length === 0) {
        // Hide the button if no Wikipedia results
        document.querySelector('.wikipedia-button').style.display = 'none';
        return;
    }
    
    const sidebarContent = document.querySelector('.wikipedia-sidebar-content');
    let resultsHTML = '';
    
    results.forEach(function(result) {
        resultsHTML += `
            <div class="wikipedia-result">
                <h4 class="wikipedia-result-title">
                    <a href="${result.url}" target="_blank">${result.title}</a>
                </h4>
                <p class="wikipedia-result-description">${result.description}</p>
                <div class="wikipedia-result-url">${result.url}</div>
            </div>
        `;
    });
    
    sidebarContent.innerHTML = resultsHTML;
    
    // Show the toggle button
    document.querySelector('.wikipedia-button').style.display = 'flex';
    
    // Automatically open the sidebar
    document.querySelector('.wikipedia-sidebar').classList.add('active');
    document.querySelector('.sidebar-overlay').classList.add('active');
    document.body.style.overflow = 'hidden'; // Prevent scrolling
}
// Add this JavaScript code to create and manage the Wordpedia button and sidebar
// Add this JavaScript code to create and manage the Wordpedia button and sidebar
document.addEventListener('DOMContentLoaded', function() {
    // Create Wordpedia button and sidebar
    createWordpediaElements();
    
    // Initialize Wordpedia functionality
    initWordpedia();
});

// Function to create Wordpedia elements
function createWordpediaElements() {
    // Create Wordpedia button if it doesn't exist
    if (!document.querySelector('.wordpedia-button')) {
        const wordpediaButton = document.createElement('button');
        wordpediaButton.className = 'wordpedia-button';
        wordpediaButton.innerHTML = `
            <i class="fas fa-book-open"></i>
            <span>Word</span>
        `;
        document.body.appendChild(wordpediaButton);
    }
    
    // Create Wordpedia sidebar if it doesn't exist
    if (!document.querySelector('.wordpedia-sidebar')) {
        const wordpediaSidebar = document.createElement('div');
        wordpediaSidebar.className = 'wordpedia-sidebar';
        wordpediaSidebar.innerHTML = `
            <div class="wordpedia-sidebar-header">
                <h3 class="wordpedia-sidebar-title">
                    <i class="fas fa-book-open" style="margin-right: 10px;"></i>
                    Wordpedia Definition
                </h3>
                <button class="wordpedia-sidebar-close" title="Close">×</button>
            </div>
            <div class="wordpedia-sidebar-content"></div>
        `;
        document.body.appendChild(wordpediaSidebar);
    }
}
/* Add this JavaScript to handle the live update functionality */
document.addEventListener('DOMContentLoaded', function() {
    // Add Live Update buttons to search results
    addLiveUpdateButtons();
    
    // Handle tab navigation properly
    setupTabNavigation();
});

/**
 * Add Live Update buttons to search results from the database
 */
function addLiveUpdateButtons() {
    const searchResults = document.querySelectorAll('.g-search-result');
    
    searchResults.forEach(result => {
        // Only add the button to database results (not Wikipedia or Wordpedia)
        const sourceElement = result.querySelector('.g-search-result-info-item');
        if (sourceElement && !sourceElement.textContent.includes('Wikipedia') && !sourceElement.textContent.includes('Wordpedia')) {
            // Get the URL from the result
            const urlElement = result.querySelector('.g-search-result-url');
            if (urlElement) {
                const url = urlElement.textContent.trim();
                const siteId = result.getAttribute('data-site-id') || '';
                
                // Create the Live Update button
                const liveUpdateBtn = document.createElement('button');
                liveUpdateBtn.className = 'live-update-button';
                liveUpdateBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Live Update';
                liveUpdateBtn.setAttribute('data-url', url);
                liveUpdateBtn.setAttribute('data-site-id', siteId);
                
                // Add click event listener
                liveUpdateBtn.addEventListener('click', handleLiveUpdate);
                
                // Add button to the result info section
                const resultInfo = result.querySelector('.g-search-result-info');
                if (resultInfo) {
                    resultInfo.appendChild(liveUpdateBtn);
                }
            }
        }
    });
}

/**
 * Set up proper tab navigation to maintain separation between tabs
 */
function setupTabNavigation() {
    const tabs = document.querySelectorAll('.g-tab');
    const tabContents = document.querySelectorAll('.g-tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Skip if tab contains an <a> element (these handle their own navigation)
            if (tab.querySelector('a')) {
                return;
            }
            
            // Remove active class from all tabs and contents
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked tab
            tab.classList.add('active');
            
            // Show corresponding content
            const tabId = tab.getAttribute('data-tab');
            const content = document.getElementById(`${tabId}-content`);
            if (content) {
                content.classList.add('active');
            }
        });
    });
}

/**
 * Handle Live Update button click
 */
function handleLiveUpdate(event) {
    event.preventDefault();
    const button = event.currentTarget;
    const url = button.getAttribute('data-url');
    const siteId = button.getAttribute('data-site-id');
    
    // Show spinner during update
    const originalHTML = button.innerHTML;
    button.innerHTML = '<span class="update-spinner"></span> Updating...';
    button.disabled = true;
    
    // Make AJAX request to update the site
    fetchUpdatedSiteInfo(url, siteId)
        .then(response => {
            if (response.success) {
                // Update was successful
                updateSearchResultDisplay(button.closest('.g-search-result'), response.data);
                
                // Show success message
                button.innerHTML = '<i class="fas fa-check"></i> Updated';
                button.style.backgroundColor = '#28a745';
                
                // Reset button after 3 seconds
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.style.backgroundColor = '';
                    button.disabled = false;
                }, 3000);
            } else {
                // Update failed
                button.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Failed';
                button.style.backgroundColor = '#dc3545';
                
                // Reset button after 3 seconds
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.style.backgroundColor = '';
                    button.disabled = false;
                }, 3000);
            }
        })
        .catch(error => {
            console.error('Live update error:', error);
            
            // Show error
            button.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error';
            button.style.backgroundColor = '#dc3545';
            
            // Reset button after 3 seconds
            setTimeout(() => {
                button.innerHTML = originalHTML;
                button.style.backgroundColor = '';
                button.disabled = false;
            }, 3000);
        });
}

/**
 * Fetch updated site information
 */
async function fetchUpdatedSiteInfo(url, siteId) {
    const formData = new FormData();
    formData.append('action', 'live_update');
    formData.append('url', url);
    formData.append('site_id', siteId);
    
    try {
        const response = await fetch('update_site.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        return await response.json();
    } catch (error) {
        console.error('Error in fetchUpdatedSiteInfo:', error);
        return { success: false, message: error.message };
    }
}

/**
 * Update the search result display with new data
 */
function updateSearchResultDisplay(resultElement, data) {
    if (!resultElement || !data) return;
    
    // Update title
    const titleElement = resultElement.querySelector('.g-search-result-title a');
    if (titleElement && data.title) {
        titleElement.textContent = data.title;
    }
    
    // Update description
    const snippetElement = resultElement.querySelector('.g-search-result-snippet');
    if (snippetElement && data.description) {
        snippetElement.textContent = data.description;
    }
}
// Show a progress indicator for better UX
function showSearchProgress() {
    const progressBar = document.createElement('div');
    progressBar.className = 'search-progress-bar';
    progressBar.innerHTML = `
        <div class="progress-track">
            <div class="progress-fill"></div>
        </div>
        <div class="progress-text">Searching...</div>
    `;
    
    document.querySelector('.g-search-container').appendChild(progressBar);
    
    // Animate progress
    const fill = progressBar.querySelector('.progress-fill');
    let width = 0;
    const interval = setInterval(() => {
        if (width >= 90) {
            clearInterval(interval);
        } else {
            width += Math.random() * 2;
            fill.style.width = width + '%';
        }
    }, 100);
    
    return {
        complete: () => {
            fill.style.width = '100%';
            setTimeout(() => {
                progressBar.remove();
            }, 300);
            clearInterval(interval);
        }
    };
}
// Function to initialize Wordpedia functionality
function initWordpedia() {
    const wordpediaButton = document.querySelector('.wordpedia-button');
    const wordpediaSidebar = document.querySelector('.wordpedia-sidebar');
    const sidebarContent = document.querySelector('.wordpedia-sidebar-content');
    const closeButton = document.querySelector('.wordpedia-sidebar-close');
    const searchInput = document.getElementById('search-input');
    
    // Handle Wordpedia button click
    wordpediaButton.addEventListener('click', function() {
        const searchTerm = searchInput.value.trim();
        
        if (!searchTerm) {
            alert('Please enter a word in the search box first.');
            return;
        }
        
        // Get the first word only if there are multiple words
        const word = searchTerm.split(' ')[0].toLowerCase();
        
        // Instead of using AJAX to check directory, directly try to load the definition
        // This avoids the JSON parsing error
        const wordPath = `pages/${word}/index.html`;
        
        // Show the sidebar first with loading state
        sidebarContent.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i><p>Loading definition...</p></div>';
        wordpediaSidebar.classList.add('active');
        
        // If there's an overlay for the Wikipedia sidebar, reuse it or create a new one
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }
        
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent scrolling
        
        // Add click event to overlay
        overlay.addEventListener('click', function() {
            wordpediaSidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = ''; // Restore scrolling
        });
        
        // Make the button visible
        wordpediaButton.style.display = 'flex';
        
        // Load the word definition
        loadWordDefinition(word, sidebarContent);
    });
    
    // Close button event
    closeButton.addEventListener('click', function() {
        wordpediaSidebar.classList.remove('active');
        
        // If there's an overlay, hide it
        const overlay = document.querySelector('.sidebar-overlay');
        if (overlay) {
            overlay.classList.remove('active');
        }
        
        document.body.style.overflow = ''; // Restore scrolling
    });
    
    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && wordpediaSidebar.classList.contains('active')) {
            wordpediaSidebar.classList.remove('active');
            
            // If there's an overlay, hide it
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) {
                overlay.classList.remove('active');
            }
            
            document.body.style.overflow = ''; // Restore scrolling
        }
    });
    
    // Check search term on page load
    const urlParams = new URLSearchParams(window.location.search);
    const queryParam = urlParams.get('q');
    
    if (queryParam) {
        // Set the search input value
        if (searchInput) {
            searchInput.value = queryParam;
        }
        
        // If it's a single word, show the Wordpedia button
        if (!queryParam.includes(' ')) {
            wordpediaButton.style.display = 'flex';
        }
    } else {
        // Hide button by default if no search
        wordpediaButton.style.display = 'none';
    }
}

// Function to load word definition
function loadWordDefinition(word, container) {
    // Create an iframe to load the word page
    // This is a simpler approach that avoids AJAX issues
    container.innerHTML = `
        <div style="width: 100%; height: 600px; border: 1px solid #e0e0e0; border-radius: 4px;">
            <iframe src="../wordpedia/pages/${word}/index.html" 
                    style="width: 100%; height: 100%; border: none;" 
                    onload="this.style.height = '100%';"
                    onerror="handleIframeError('${word}')">
            </iframe>
        </div>
        <div style="margin-top: 20px; text-align: right;">
            <a href="../wordpedia/pages/${word}/index.html" target="_blank" style="color: #34A853; text-decoration: none;">
                View full page <i class="fas fa-external-link-alt" style="font-size: 0.8rem;"></i>
            </a>
        </div>
    `;
}

// Function to handle iframe loading errors
function handleIframeError(word) {
    const container = document.querySelector('.wordpedia-sidebar-content');
    
    if (container) {
        container.innerHTML = `<div style="padding: 20px; text-align: center;">
            <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: #EA4335;"></i>
            <p>Could not load definition for "${word}".</p>
            <p>The page may not exist at ../wordpedia/pages/${word}/index.html</p>
        </div>`;
    }
}

// Function to check search input and show/hide Wordpedia button
function checkSearchInput() {
    const searchInput = document.getElementById('search-input');
    const wordpediaButton = document.querySelector('.wordpedia-button');
    
    if (!searchInput || !wordpediaButton) return;
    
    // Update button visibility based on search input
    searchInput.addEventListener('input', function() {
        const value = this.value.trim();
        
        // Show button only if there's text and it's a single word
        if (value && !value.includes(' ')) {
            wordpediaButton.style.display = 'flex';
        } else {
            wordpediaButton.style.display = 'none';
        }
    });
}

// Run check search input function after DOM loads
document.addEventListener('DOMContentLoaded', checkSearchInput);
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Define available fonts
    const availableFonts = [
        { name: 'Default', family: 'var(--font-secondary)', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Arial', family: 'Arial, sans-serif', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Georgia', family: 'Georgia, serif', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Verdana', family: 'Verdana, sans-serif', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Courier New', family: 'Courier New, monospace', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Comic Sans MS', family: 'Comic Sans MS, cursive', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Minecraft', family: '"Minecraft", "VT323", monospace', previewText: 'Building worlds one block at a time' },
        { name: 'Disney', family: '"Waltograph", "Fantasy", cursive', previewText: 'Where dreams come true' },
        { name: 'Legend of Dragoon', family: '"Enchanted Land", fantasy, serif', previewText: 'The legend never dies' },
        { name: 'Comics', family: '"Bangers", "Luckiest Guy", cursive', previewText: 'POW! BAM! KABOOM!' },
        { name: 'Source Sans Pro', family: '"Source Sans Pro", sans-serif', previewText: 'Clean and professional typography' },
        { name: 'Roboto', family: '"Roboto", sans-serif', previewText: 'Modern, friendly, and readable' },
        { name: 'Playfair Display', family: '"Playfair Display", serif', previewText: 'Elegant and sophisticated style' },
        { name: 'Montserrat', family: '"Montserrat", sans-serif', previewText: 'Contemporary geometric design' },
        { name: 'Open Sans', family: '"Open Sans", sans-serif', previewText: 'Friendly and approachable feel' },
        { name: 'Lato', family: '"Lato", sans-serif', previewText: 'Balanced and modern sans-serif' }
    ];
    
    // Variables to store selected font
    let selectedFont = sessionStorage.getItem('legendDxFont') || '';
    let selectedFontName = sessionStorage.getItem('legendDxFontName') || '';
    
    // Add font selector button to search container
    function addFontSelectorButton() {
        const searchContainer = document.querySelector('.g-search-container');
        if (!searchContainer) return;
        
        // Create button element
        const fontButton = document.createElement('button');
        fontButton.className = 'font-selector-button';
        fontButton.title = 'Change font style';
        fontButton.setAttribute('aria-label', 'Change font style');
        fontButton.id = 'fontSelectorButton';
        
        // Add icon to button
        const fontIcon = document.createElement('span');
        fontIcon.className = 'font-selector-icon';
        fontButton.appendChild(fontIcon);
        
        // Add button to search container
        searchContainer.appendChild(fontButton);
        
        return fontButton;
    }
    
    // Initialize font selector
    function initFontSelector() {
        const fontButton = addFontSelectorButton();
        if (!fontButton) return;
        
        const fontModalOverlay = document.getElementById('fontModalOverlay');
        const fontList = document.getElementById('fontList');
        const closeButton = document.getElementById('fontModalClose');
        const searchInput = document.getElementById('fontSearchInput');
        const cancelButton = document.getElementById('fontCancelButton');
        const applyButton = document.getElementById('fontApplyButton');
        
        // Populate font list
        populateFontList();
        
        // Apply stored font if any
        applyStoredFont();
        
        // Add event listeners
        fontButton.addEventListener('click', openFontModal);
        closeButton.addEventListener('click', closeFontModal);
        cancelButton.addEventListener('click', closeFontModal);
        applyButton.addEventListener('click', applySelectedFont);
        searchInput.addEventListener('input', filterFonts);
        
        // Close modal when clicking on overlay
        fontModalOverlay.addEventListener('click', function(e) {
            if (e.target === fontModalOverlay) {
                closeFontModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && fontModalOverlay.style.display === 'flex') {
                closeFontModal();
            }
        });
    }
    
    // Open font modal
    function openFontModal() {
        const fontModalOverlay = document.getElementById('fontModalOverlay');
        const searchInput = document.getElementById('fontSearchInput');
        
        // Show modal
        fontModalOverlay.style.display = 'flex';
        
        // Reset search
        searchInput.value = '';
        filterFonts();
        
        // Focus search input
        setTimeout(() => {
            searchInput.focus();
        }, 100);
    }
    
    // Close font modal
    function closeFontModal() {
        const fontModalOverlay = document.getElementById('fontModalOverlay');
        fontModalOverlay.style.display = 'none';
    }
    
    // Populate the font list
    function populateFontList() {
        const fontList = document.getElementById('fontList');
        if (!fontList) return;
        
        // Clear existing content
        fontList.innerHTML = '';
        
        // Add each font option
        availableFonts.forEach(font => {
            const listItem = document.createElement('li');
            listItem.className = 'font-option';
            listItem.setAttribute('data-font', font.family);
            
            // Create checkbox container
            const checkboxContainer = document.createElement('label');
            checkboxContainer.className = 'font-checkbox';
            
            const checkbox = document.createElement('input');
            checkbox.type = 'radio';
            checkbox.name = 'font-selection';
            checkbox.value = font.family;
            checkbox.checked = font.family === selectedFont;
            
            const checkmark = document.createElement('span');
            checkmark.className = 'font-checkbox-mark';
            
            checkboxContainer.appendChild(checkbox);
            checkboxContainer.appendChild(checkmark);
            
            // Create details container
            const details = document.createElement('div');
            details.className = 'font-details';
            
            const preview = document.createElement('div');
            preview.className = 'font-preview';
            preview.style.fontFamily = font.family;
            preview.textContent = font.previewText;
            
            const name = document.createElement('div');
            name.className = 'font-name';
            name.textContent = font.name;
            
            details.appendChild(preview);
            details.appendChild(name);
            
            // Assemble the list item
            listItem.appendChild(checkboxContainer);
            listItem.appendChild(details);
            
            // Add click event to the entire row
            listItem.addEventListener('click', function(e) {
                // Ignore clicks on the checkbox itself to prevent double handling
                if (e.target !== checkbox) {
                    checkbox.checked = true;
                    selectFont(font.family, font.name);
                }
            });
            
            // Add change event to checkbox
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectFont(font.family, font.name);
                }
            });
            
            fontList.appendChild(listItem);
        });
    }
    
    // Select a font
    function selectFont(fontFamily, fontName) {
        selectedFont = fontFamily;
        selectedFontName = fontName;
    }
    
    // Apply selected font
    function applySelectedFont() {
        if (selectedFont) {
            // Apply to document
            document.body.style.fontFamily = selectedFont;
            
            // Store in session storage
            sessionStorage.setItem('legendDxFont', selectedFont);
            sessionStorage.setItem('legendDxFontName', selectedFontName);
            
            // Show notification
            showNotification(`Font changed to ${selectedFontName}`);
        }
        
        closeFontModal();
    }
    
    // Filter fonts based on search input
    function filterFonts() {
        const searchInput = document.getElementById('fontSearchInput');
        const searchTerm = searchInput.value.toLowerCase();
        const fontOptions = document.querySelectorAll('.font-option');
        
        fontOptions.forEach(option => {
            const fontName = option.querySelector('.font-name').textContent.toLowerCase();
            
            if (searchTerm === '' || fontName.includes(searchTerm)) {
                option.style.display = 'flex';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    // Apply stored font on page load
    function applyStoredFont() {
        const storedFont = sessionStorage.getItem('legendDxFont');
        
        if (storedFont) {
            document.body.style.fontFamily = storedFont;
            selectedFont = storedFont;
            selectedFontName = sessionStorage.getItem('legendDxFontName') || '';
        }
    }
    
    // Show notification
    function showNotification(message) {
        const notification = document.getElementById('fontNotification');
        notification.querySelector('.font-notification-text').textContent = message;
        
        notification.classList.add('visible');
        
        // Hide after 3 seconds
        setTimeout(() => {
            notification.classList.remove('visible');
        }, 3000);
    }
    
    // Initialize
    initFontSelector();
});
</script>
<script>
// JavaScript for tab navigation
// JavaScript for tab navigation
document.addEventListener('DOMContentLoaded', function() {
    // Get all tabs
    const tabs = document.querySelectorAll('.g-tab');
    // Get all tab contents
    const tabContents = document.querySelectorAll('.g-tab-content');
    
    // Handle click on tabs with links inside
    tabs.forEach(tab => {
        const link = tab.querySelector('a');
        if (link) {
            // The link will handle navigation, so we don't need additional click handlers
            // Just make sure the active class is set correctly based on current page
            const currentPage = new URLSearchParams(window.location.search).get('page') || 'search';
            const tabPage = link.href.split('page=')[1]?.split('&')[0] || 'search';
            
            if (currentPage === tabPage) {
                tab.classList.add('active');
                // Also make sure the corresponding content is visible
                const tabId = tab.getAttribute('data-tab');
                const contentElement = document.getElementById(`${tabId}-content`);
                if (contentElement) {
                    // First hide all tab contents
                    tabContents.forEach(content => content.classList.remove('active'));
                    // Then show this one
                    contentElement.classList.add('active');
                }
            }
        }
    });
    
    // This ensures that only one tab content is shown at a time
    // Get the current active tab
    const activeTab = document.querySelector('.g-tab.active');
    if (activeTab) {
        const tabId = activeTab.getAttribute('data-tab');
        // Hide all tab contents
        tabContents.forEach(content => content.classList.remove('active'));
        // Show only the active tab content
        const activeContent = document.getElementById(`${tabId}-content`);
        if (activeContent) {
            activeContent.classList.add('active');
        }
    }
});
// JavaScript for search filters functionality
document.addEventListener('DOMContentLoaded', function() {
    // Handle search filter clicks
    const searchFilters = document.querySelectorAll('.g-search-filter');
    
    searchFilters.forEach(filter => {
        filter.addEventListener('click', function(e) {
            // Only prevent default if this is not a direct link
            if (!this.getAttribute('href') || this.getAttribute('href') === '#') {
                e.preventDefault();
            }
            
            // Remove active class from all filters
            searchFilters.forEach(f => f.classList.remove('active'));
            
            // Add active class to clicked filter
            this.classList.add('active');
            
            // Get the filter type
            const filterType = this.textContent.trim();
            console.log(`Filter clicked: ${filterType}`);
            
            // If this filter has a specific href attribute, don't add additional handling
            if (this.getAttribute('href') && this.getAttribute('href') !== '#') {
                return; // Let the browser handle the navigation
            }
            
            // Get the current search query
            const searchQuery = document.getElementById('search-input')?.value || '';
            
            // Apply filter to search results based on filterType
            applyFilter(filterType, searchQuery);
        });
    });
    
    // Function to apply filter to search results
    function applyFilter(filterType, query) {
        // Get all search results
        const results = document.querySelectorAll('.g-search-result');
        
        // Handle each filter type
        switch (filterType.toLowerCase().replace(/^\s*\S+\s+/, '')) { // Remove icon text
            case 'all':
                // Show all results
                results.forEach(result => {
                    result.style.display = 'block';
                });
                break;
                
            case 'recent':
                // Filter to show only recent results (for demo, highlighting results randomly)
                results.forEach(result => {
                    // In a real implementation, you would check date information
                    // For demo, randomly hide some results
                    if (Math.random() > 0.5) {
                        result.style.display = 'block';
                        // Add highlight for visual feedback
                        result.style.borderLeft = '3px solid var(--google-blue)';
                        result.style.paddingLeft = '10px';
                    } else {
                        result.style.display = 'none';
                    }
                });
                break;
                
            case 'pdf':
                // Filter to show only PDF results
                // In this demo version, look for "pdf" in the title or URL
                results.forEach(result => {
                    const title = result.querySelector('.g-search-result-title')?.textContent.toLowerCase() || '';
                    const url = result.querySelector('.g-search-result-url')?.textContent.toLowerCase() || '';
                    
                    if (title.includes('pdf') || url.includes('pdf') || url.includes('.pdf')) {
                        result.style.display = 'block';
                    } else {
                        result.style.display = 'none';
                    }
                });
                break;
             case 'dsa':
    // Instead of filtering, redirect to the DSA page
    window.location.href = 'https://jcmc.serveminecraft.net/dsa';
    return; // Stop further execution since we're redirecting
    break;
            default:
                // For other filters, just show all results
                results.forEach(result => {
                    result.style.display = 'block';
                });
                break;
        }
        
        // Update results count
        updateResultsCount();
    }
    
    // Function to update the results count after filtering
    function updateResultsCount() {
        const statsElement = document.querySelector('.g-search-stats');
        if (!statsElement) return;
        
        // Count visible results
        const visibleResults = document.querySelectorAll('.g-search-result:not([style*="display: none"])').length;
        
        // Format the count with commas
        const formattedCount = new Intl.NumberFormat().format(visibleResults || 0);
        
        // Update stats text
        statsElement.textContent = `About ${formattedCount} results (${Math.random().toFixed(2)} seconds)`;
    }
});
// Find the DSA filter link and make sure it works properly
document.addEventListener('DOMContentLoaded', function() {
    // Find the DSA filter link
    const dsaFilter = document.querySelector('.g-search-filter[href="../dsa"]');
    
    if (dsaFilter) {
        // Update the href to the full URL
        dsaFilter.href = 'https://jcmc.serveminecraft.net/dsa';
        
        // Remove any click event listeners that might interfere
        dsaFilter.onclick = function(e) {
            // Don't prevent default - let the browser handle navigation
            window.location.href = 'https://jcmc.serveminecraft.net/dsa';
            return true;
        };
    } else {
        // If we can't find the existing link, create a new one
        const filtersContainer = document.querySelector('.g-search-filters');
        if (filtersContainer) {
            // Remove old DSA link if it exists
            const oldDsaFilter = Array.from(filtersContainer.querySelectorAll('.g-search-filter')).find(
                elem => elem.textContent.trim().includes('DSA')
            );
            if (oldDsaFilter) {
                oldDsaFilter.remove();
            }
            
            // Create new DSA link
            const dsaLink = document.createElement('a');
            dsaLink.className = 'g-search-filter';
            dsaLink.href = 'https://jcmc.serveminecraft.net/dsa';
            dsaLink.innerHTML = '<i class="fas fa-tools g-search-filter-icon"></i> DSA';
            filtersContainer.appendChild(dsaLink);
        }
    }
});
</script>
<style>
/* Font selector button positioning */
.font-selector-button {
    position: absolute;
    right: 75px; /* Position between upload and search buttons */
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background-color: transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s;
    z-index: 5;
}

.font-selector-button:hover {
    background-color: rgba(60, 64, 67, 0.08);
}

.font-selector-icon {
    width: 20px;
    height: 20px;
    background-image: url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjNWY2MzY4IiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+CiAgPHBhdGggZD0iTTQuNSA5aDYuMjVjMS4xNCAwIDIuMjcuMyAzLjI2LjlsNi40MiA0LjItMS4yIDEuODUtNi40My00LjJjLS41LS4zLTEuMi0uNDUtMS44LS40MmgtLjc1YzEgMCAyIC4yIDMgLjdsNi40MyA0LjItMS4yIDEuODUtNi40My00LjJjLTEtLjctMi40LTEuNS0zLjUtMS41aC00LjV2LTkiLz4KICA8cGF0aCBkPSJNMTAgMjJsLTEtM2gtMmwtMSAzLTEtMyIvPgogIDxwYXRoIGQ9Ik02IDE5aDciLz4KPC9zdmc+');
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

/* Adjust the upload button position if needed */
.upload-button {
    right: 45px; /* Adjust this value as needed */
}

/* Make sure the modal is positioned correctly */
.font-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
<!-- JavaScript adjustments (only modification needed) -->
<script>
// The existing JavaScript to initialize the font selector can remain the same,
// but remove the addFontSelectorButton function since we're now adding the button directly in HTML
document.addEventListener('DOMContentLoaded', function() {
    // Define available fonts
    const availableFonts = [
        { name: 'Default', family: 'var(--font-secondary)', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Arial', family: 'Arial, sans-serif', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Georgia', family: 'Georgia, serif', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Verdana', family: 'Verdana, sans-serif', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Courier New', family: 'Courier New, monospace', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Comic Sans MS', family: 'Comic Sans MS, cursive', previewText: 'The quick brown fox jumps over the lazy dog' },
        { name: 'Minecraft', family: '"Minecraft", "VT323", monospace', previewText: 'Building worlds one block at a time' },
        { name: 'Disney', family: '"Waltograph", "Fantasy", cursive', previewText: 'Where dreams come true' },
        { name: 'Legend of Dragoon', family: '"Enchanted Land", fantasy, serif', previewText: 'The legend never dies' },
        { name: 'Comics', family: '"Bangers", "Luckiest Guy", cursive', previewText: 'POW! BAM! KABOOM!' },
        { name: 'Source Sans Pro', family: '"Source Sans Pro", sans-serif', previewText: 'Clean and professional typography' },
        { name: 'Roboto', family: '"Roboto", sans-serif', previewText: 'Modern, friendly, and readable' },
        { name: 'Playfair Display', family: '"Playfair Display", serif', previewText: 'Elegant and sophisticated style' },
        { name: 'Montserrat', family: '"Montserrat", sans-serif', previewText: 'Contemporary geometric design' },
        { name: 'Open Sans', family: '"Open Sans", sans-serif', previewText: 'Friendly and approachable feel' },
        { name: 'Lato', family: '"Lato", sans-serif', previewText: 'Balanced and modern sans-serif' }
    ];
    
    // Variables to store selected font
    let selectedFont = sessionStorage.getItem('legendDxFont') || '';
    let selectedFontName = sessionStorage.getItem('legendDxFontName') || '';
	// Decrypt the encrypted results using the provided decryption function
// Function to decrypt the data from the server


    // Initialize font selector
    function initFontSelector() {
        const fontButton = document.getElementById('fontSelectorButton');
        if (!fontButton) return;
        
        const fontModalOverlay = document.getElementById('fontModalOverlay');
        const fontList = document.getElementById('fontList');
        const closeButton = document.getElementById('fontModalClose');
        const searchInput = document.getElementById('fontSearchInput');
        const cancelButton = document.getElementById('fontCancelButton');
        const applyButton = document.getElementById('fontApplyButton');
        
        // Populate font list
        populateFontList();
        
        // Apply stored font if any
        applyStoredFont();
        
        // Add event listeners
        fontButton.addEventListener('click', openFontModal);
        closeButton.addEventListener('click', closeFontModal);
        cancelButton.addEventListener('click', closeFontModal);
        applyButton.addEventListener('click', applySelectedFont);
        searchInput.addEventListener('input', filterFonts);
        
        // Close modal when clicking on overlay
        fontModalOverlay.addEventListener('click', function(e) {
            if (e.target === fontModalOverlay) {
                closeFontModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && fontModalOverlay.style.display === 'flex') {
                closeFontModal();
            }
        });
    }
    
    // Rest of the JavaScript functions (openFontModal, closeFontModal, etc.) remain the same
    
    // Open font modal
    function openFontModal() {
        const fontModalOverlay = document.getElementById('fontModalOverlay');
        const searchInput = document.getElementById('fontSearchInput');
        
        // Show modal
        fontModalOverlay.style.display = 'flex';
        
        // Reset search
        searchInput.value = '';
        filterFonts();
        
        // Focus search input
        setTimeout(() => {
            searchInput.focus();
        }, 100);
    }
    
    // Close font modal
    function closeFontModal() {
        const fontModalOverlay = document.getElementById('fontModalOverlay');
        fontModalOverlay.style.display = 'none';
    }
    
    // Populate the font list
    function populateFontList() {
        const fontList = document.getElementById('fontList');
        if (!fontList) return;
        
        // Clear existing content
        fontList.innerHTML = '';
        
        // Add each font option
        availableFonts.forEach(font => {
            const listItem = document.createElement('li');
            listItem.className = 'font-option';
            listItem.setAttribute('data-font', font.family);
            
            // Create checkbox container
            const checkboxContainer = document.createElement('label');
            checkboxContainer.className = 'font-checkbox';
            
            const checkbox = document.createElement('input');
            checkbox.type = 'radio';
            checkbox.name = 'font-selection';
            checkbox.value = font.family;
            checkbox.checked = font.family === selectedFont;
            
            const checkmark = document.createElement('span');
            checkmark.className = 'font-checkbox-mark';
            
            checkboxContainer.appendChild(checkbox);
            checkboxContainer.appendChild(checkmark);
            
            // Create details container
            const details = document.createElement('div');
            details.className = 'font-details';
            
            const preview = document.createElement('div');
            preview.className = 'font-preview';
            preview.style.fontFamily = font.family;
            preview.textContent = font.previewText;
            
            const name = document.createElement('div');
            name.className = 'font-name';
            name.textContent = font.name;
            
            details.appendChild(preview);
            details.appendChild(name);
            
            // Assemble the list item
            listItem.appendChild(checkboxContainer);
            listItem.appendChild(details);
            
            // Add click event to the entire row
            listItem.addEventListener('click', function(e) {
                // Ignore clicks on the checkbox itself to prevent double handling
                if (e.target !== checkbox) {
                    checkbox.checked = true;
                    selectFont(font.family, font.name);
                }
            });
            
            // Add change event to checkbox
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectFont(font.family, font.name);
                }
            });
            
            fontList.appendChild(listItem);
        });
    }
    
    // Select a font
    function selectFont(fontFamily, fontName) {
        selectedFont = fontFamily;
        selectedFontName = fontName;
    }
    
    // Apply selected font
    function applySelectedFont() {
        if (selectedFont) {
            // Apply to document
            document.body.style.fontFamily = selectedFont;
            
            // Store in session storage
            sessionStorage.setItem('legendDxFont', selectedFont);
            sessionStorage.setItem('legendDxFontName', selectedFontName);
            
            // Show notification
            showNotification(`Font changed to ${selectedFontName}`);
        }
        
        closeFontModal();
    }
    
    // Filter fonts based on search input
    function filterFonts() {
        const searchInput = document.getElementById('fontSearchInput');
        const searchTerm = searchInput.value.toLowerCase();
        const fontOptions = document.querySelectorAll('.font-option');
        
        fontOptions.forEach(option => {
            const fontName = option.querySelector('.font-name').textContent.toLowerCase();
            
            if (searchTerm === '' || fontName.includes(searchTerm)) {
                option.style.display = 'flex';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    // Apply stored font on page load
    function applyStoredFont() {
        const storedFont = sessionStorage.getItem('legendDxFont');
        
        if (storedFont) {
            document.body.style.fontFamily = storedFont;
            selectedFont = storedFont;
            selectedFontName = sessionStorage.getItem('legendDxFontName') || '';
        }
    }
    
    // Show notification
    function showNotification(message) {
        const notification = document.getElementById('fontNotification');
        if (!notification) return;
        
        const textElement = notification.querySelector('.font-notification-text');
        if (textElement) {
            textElement.textContent = message;
        } else {
            notification.textContent = message;
        }
        
        notification.classList.add('visible');
        
        // Hide after 3 seconds
        setTimeout(() => {
            notification.classList.remove('visible');
        }, 3000);
    }
    
    // Initialize
    initFontSelector();
});
// Video tab functionality
// Video tab functionality
// Video tab functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize video tab functionality
    initVideoTab();
    
    // Setup video tooltips
    setupVideoTooltips();
    
    // Setup video players
    setupVideoPlayers();
    
    // Add modal styles
    addVideoModalStyles();
});

/**
 * Initialize video tab functionality
 */
function initVideoTab() {
    // Make sure the videos tab is properly initialized
    const videosTab = document.querySelector('[data-tab="videos"]');
    const videosContent = document.getElementById('videos-content');
    
    if (videosTab && videosContent) {
        // If the tab isn't using an anchor tag for navigation
        if (!videosTab.querySelector('a')) {
            videosTab.addEventListener('click', function() {
                // Hide all tab contents
                document.querySelectorAll('.g-tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                
                // Remove active class from all tabs
                document.querySelectorAll('.g-tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Show the videos tab content
                videosContent.classList.add('active');
                videosTab.classList.add('active');
                
                // Update URL without reloading
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('page', 'videos');
                window.history.pushState({}, '', currentUrl.toString());
            });
        }
    }
    
    // Add video search capability to the main search
    const searchButton = document.getElementById('search-button');
    const searchInput = document.getElementById('search-input');
    
    if (searchButton && searchInput) {
        // Add a data attribute to indicate which tab we're in
        const currentTab = document.querySelector('.g-tab.active');
        if (currentTab) {
            searchButton.setAttribute('data-current-tab', currentTab.getAttribute('data-tab'));
        }
        
        // Handle search button click
        const originalClickHandler = searchButton.onclick;
        searchButton.onclick = function(e) {
            const query = searchInput.value.trim();
            const currentTab = this.getAttribute('data-current-tab') || 'search';
            
            if (query && currentTab === 'videos') {
                // If we're in the videos tab, search videos
                window.location.href = `?page=videos&q=${encodeURIComponent(query)}`;
                e.preventDefault();
                return false;
            } else if (originalClickHandler) {
                // Otherwise, use the original handler
                return originalClickHandler.call(this, e);
            }
        };
        
        // Handle Enter key in search input
        const originalKeyHandler = searchInput.onkeydown;
        searchInput.onkeydown = function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                const currentTab = document.querySelector('.g-tab.active');
                const currentTabId = currentTab ? currentTab.getAttribute('data-tab') : 'search';
                
                if (query && currentTabId === 'videos') {
                    // If we're in the videos tab, search videos
                    window.location.href = `?page=videos&q=${encodeURIComponent(query)}`;
                    e.preventDefault();
                    return false;
                } else if (originalKeyHandler) {
                    // Otherwise, use the original handler
                    return originalKeyHandler.call(this, e);
                }
            }
        };
    }
}

/**
 * Setup video tooltip functionality
 */
function setupVideoTooltips() {
    // Create tooltip container if it doesn't exist
    let tooltipContainer = document.getElementById('video-tooltip');
    if (!tooltipContainer) {
        tooltipContainer = document.createElement('div');
        tooltipContainer.id = 'video-tooltip';
        tooltipContainer.className = 'video-tooltip';
        document.body.appendChild(tooltipContainer);
    }
    
    // Add event listeners to all video thumbnails
    const videoThumbnails = document.querySelectorAll('.video-thumbnail-container');
    
    videoThumbnails.forEach(thumbnail => {
        // Mouse enter - show tooltip
        thumbnail.addEventListener('mouseenter', function(e) {
            showVideoTooltip(this, e);
        });
        
        // Mouse leave - hide tooltip
        thumbnail.addEventListener('mouseleave', function() {
            hideVideoTooltip();
        });
        
        // Mouse move - update tooltip position
        thumbnail.addEventListener('mousemove', function(e) {
            updateTooltipPosition(e);
        });
    });
}

/**
 * Show video tooltip with preview and info
 * 
 * @param {HTMLElement} element The element to show tooltip for
 * @param {Event} event The mouse event
 */
function showVideoTooltip(element, event) {
    // Get tooltip data
    const tooltipData = element.getAttribute('data-tooltip');
    if (!tooltipData) return;
    
    // Get video preview URL
    const previewUrl = element.getAttribute('data-preview');
    
    // Parse tooltip data
    let data;
    try {
        data = JSON.parse(tooltipData);
    } catch (error) {
        console.error('Error parsing tooltip data:', error);
        return;
    }
    
    // Get tooltip container
    const tooltipContainer = document.getElementById('video-tooltip');
    
    // Create tooltip content
    let tooltipContent = '';
    
    // Add preview video if available
    if (previewUrl) {
        tooltipContent += `
            <div class="video-tooltip-preview">
                <video autoplay muted loop>
                    <source src="${previewUrl}" type="video/mp4">
                </video>
            </div>
        `;
    }
    
    // Add tooltip content
    tooltipContent += `
        <div class="video-tooltip-content">
            <div class="video-tooltip-title">${data.title || 'Untitled Video'}</div>
    `;
    
    if (data.description) {
        tooltipContent += `<div class="video-tooltip-description">${data.description}</div>`;
    }
    
    if (data.upload_date) {
        tooltipContent += `<div class="video-tooltip-date">${data.upload_date}</div>`;
    }
    
    tooltipContent += '</div>'; // End tooltip-content
    
    // Update tooltip content
    tooltipContainer.innerHTML = tooltipContent;
    
    // Position tooltip
    updateTooltipPosition(event);
    
    // Show tooltip
    tooltipContainer.style.display = 'block';
    
    // Autoplay preview video if available
    const previewVideo = tooltipContainer.querySelector('video');
    if (previewVideo) {
        previewVideo.play().catch(error => {
            console.error('Error playing preview video:', error);
        });
    }
}

/**
 * Hide video tooltip
 */
function hideVideoTooltip() {
    const tooltipContainer = document.getElementById('video-tooltip');
    if (tooltipContainer) {
        // Stop any playing videos
        const video = tooltipContainer.querySelector('video');
        if (video) {
            video.pause();
            video.currentTime = 0;
        }
        
        // Hide tooltip
        tooltipContainer.style.display = 'none';
    }
}

/**
 * Update tooltip position based on mouse position
 * 
 * @param {Event} event The mouse event
 */
function updateTooltipPosition(event) {
    const tooltip = document.getElementById('video-tooltip');
    if (!tooltip || tooltip.style.display === 'none') return;
    
    const tooltipWidth = tooltip.offsetWidth;
    const tooltipHeight = tooltip.offsetHeight;
    
    // Get viewport dimensions
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    
    // Calculate position
    let left = event.clientX + 15; // Offset from cursor
    let top = event.clientY + 15;
    
    // Make sure tooltip doesn't go outside viewport
    if (left + tooltipWidth > viewportWidth) {
        left = event.clientX - tooltipWidth - 15;
    }
    
    if (top + tooltipHeight > viewportHeight) {
        top = event.clientY - tooltipHeight - 15;
    }
    
    // Apply position
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
}

/**
 * Set up video players to show in a modal when clicked
 */
function setupVideoPlayers() {
    // Get all video thumbnails
    const thumbnails = document.querySelectorAll('.video-thumbnail-container');
    
    thumbnails.forEach(thumbnail => {
        thumbnail.addEventListener('click', function(e) {
            // Prevent default link behavior
            e.preventDefault();
            
            // Get video URL
            const videoUrl = this.getAttribute('href');
            
            // Play video
            playVideo(videoUrl);
        });
    });
}

/**
 * Play video in a modal overlay
 * 
 * @param {string} videoUrl The video URL to play
 */
function playVideo(videoUrl) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('video-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'video-modal';
        modal.className = 'video-modal';
        document.body.appendChild(modal);
    }
    
    // Create modal content
    modal.innerHTML = `
        <button class="video-modal-close">&times;</button>
        <video class="video-modal-player" controls autoplay>
            <source src="${videoUrl}" type="video/mp4">
            Your browser does not support HTML5 video.
        </video>
    `;
    
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Prevent scrolling
    
    // Add close button event
    const closeButton = modal.querySelector('.video-modal-close');
    closeButton.addEventListener('click', function() {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    });
    
    // Add escape key to close
    document.addEventListener('keydown', function escapeHandler(e) {
        if (e.key === 'Escape') {
            modal.style.display = 'none';
            document.body.style.overflow = ''; // Restore scrolling
            document.removeEventListener('keydown', escapeHandler);
        }
    });
    
    // Add click outside to close
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
            document.body.style.overflow = ''; // Restore scrolling
        }
    });
}

/**
 * Add video modal styles to the document
 */
function addVideoModalStyles() {
    // Check if styles already exist
    if (document.getElementById('video-modal-styles')) return;
    
    // Create style element
    const style = document.createElement('style');
    style.id = 'video-modal-styles';
    
    // Add modal styles
    style.textContent = `
        .video-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .video-modal-player {
            max-width: 90%;
            max-height: 80vh;
            width: auto;
            height: auto;
        }
        
        .video-modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            background-color: transparent;
            border: none;
            color: white;
            font-size: 30px;
            cursor: pointer;
        }
    `;
    
    // Add styles to document
    document.head.appendChild(style);
}
</script>
 <script>
 // Add this to your JavaScript section that handles the image display
function formatImageResults() {
    const imageItems = document.querySelectorAll('.image-item');
    
    imageItems.forEach(item => {
        // Check for source information
        const source = item.getAttribute('data-source');
        
        // If this is an upload, add the upload badge
        if (source === 'uploads') {
            const infoDiv = item.querySelector('.image-info');
            if (infoDiv) {
                const uploadBadge = document.createElement('div');
                uploadBadge.className = 'upload-badge';
                uploadBadge.innerHTML = '<i class="fas fa-upload"></i> Uploaded';
                infoDiv.appendChild(uploadBadge);
            }
        }
    });
}

// Call this function when page loads and after adding new images
document.addEventListener('DOMContentLoaded', function() {
    formatImageResults();
});
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Video tab content loaded, initializing tooltips and players');
        
        // Check if the setupVideoTooltips function exists
        if (typeof setupVideoTooltips === 'function') {
            setupVideoTooltips();
        } else {
            console.error('setupVideoTooltips function not found');
        }
        
        // Check if the setupVideoPlayers function exists
        if (typeof setupVideoPlayers === 'function') {
            setupVideoPlayers();
        } else {
            console.error('setupVideoPlayers function not found');
        }
    });
// Translation feature functionality
document.addEventListener('DOMContentLoaded', function() {
    // Language selector toggle
    const languageSelector = document.getElementById('languageSelector');
    const languageMenu = document.getElementById('languageMenu');
    
    if (languageSelector && languageMenu) {
        languageSelector.addEventListener('click', function(e) {
            e.stopPropagation();
            languageMenu.classList.toggle('active');
        });
        
        // Handle language selection
        const languageItems = document.querySelectorAll('.language-menu-item');
        languageItems.forEach(item => {
            item.addEventListener('click', function() {
                const langCode = this.getAttribute('data-lang-code');
                const langName = this.textContent.trim();
                
                // Update UI
                languageSelector.querySelector('span').textContent = langName;
                
                // Set language preference (you could store this in a cookie/session)
                if (window.localStorage) {
                    localStorage.setItem('preferred_language', langCode);
                }
                
                // If we're on a search results page, re-run the search with translation
                const searchInput = document.getElementById('search-input');
                if (searchInput && searchInput.value) {
                    const currentQuery = searchInput.value;
                    window.location.href = `?page=search&q=${encodeURIComponent(currentQuery)}&lang=${langCode}`;
                }
                
                // Hide menu
                languageMenu.classList.remove('active');
            });
        });
        
        // Click outside to close
        document.addEventListener('click', function(e) {
            if (!languageSelector.contains(e.target) && !languageMenu.contains(e.target)) {
                languageMenu.classList.remove('active');
            }
        });
    }
    
    // Handle translation banner actions
    const useOriginalButton = document.getElementById('use-original-query');
    const translateBackButton = document.getElementById('translate-results-back');
    
    if (useOriginalButton) {
        useOriginalButton.addEventListener('click', function() {
            // Get original query from banner
            const originalQuery = document.querySelector('.translation-banner-original:last-child').textContent;
            // Search using original query with no_translate flag
            window.location.href = `?page=search&q=${encodeURIComponent(originalQuery)}&no_translate=1`;
        });
    }
    
    if (translateBackButton) {
        translateBackButton.addEventListener('click', function() {
            // Get detected language and toggle translation view
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('view_in_original', '1');
            window.location.href = currentUrl.toString();
        });
    }
});
    </script>
	<?php if (isset($wikipedia_results) && !empty($wikipedia_results)): ?>
    <!-- Wikipedia results will be displayed in the sidebar via JavaScript -->
    <script>
        // Pass Wikipedia results to JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const wikipediaResults = <?php echo json_encode($wikipedia_results); ?>;
            
            // Call the function to update the sidebar with these results
            if (typeof updateWikipediaResults === 'function') {
                updateWikipediaResults(wikipediaResults);
            } else {
                // If the function isn't loaded yet, wait and try again
                setTimeout(function() {
                    if (typeof updateWikipediaResults === 'function') {
                        updateWikipediaResults(wikipediaResults);
                    }
                }, 500);
            }
        });
    </script>
<?php endif; ?>
</body>
</html>