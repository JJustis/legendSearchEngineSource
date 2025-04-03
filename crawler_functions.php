<?php
/**
 * crawler_functions.php - Functions for site crawling and indexing
 * 
 * This file contains the SiteCrawler class and related functions
 * for crawling and indexing websites.
 */

/**
 * SiteCrawler class for crawling websites
 */
class SiteCrawler {
    private $baseUrl;
    private $domain;
    private $crawledUrls = [];
    private $foundWords = [];
    private $indexedPages = [];
    private $totalWordCount = 0;
    private $maxPages = 50; // Maximum pages to crawl
    private $excludedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'zip', 'rar', 'exe', 'css', 'js'];
    private $stopWords = ['a', 'an', 'the', 'and', 'or', 'but', 'if', 'then', 'else', 'when', 'at', 'from', 'by', 'on', 'off', 'for', 'in', 'out', 'over', 'to', 'into', 'with'];
    
    /**
     * Constructor
     * 
     * @param string $baseUrl The base URL to crawl
     */
    public function __construct($baseUrl) {
        $this->baseUrl = $baseUrl;
        $urlParts = parse_url($baseUrl);
        $this->domain = isset($urlParts['host']) ? $urlParts['host'] : '';
    }
    
    /**
     * Main crawl method
     * 
     * @return array Results of the crawl
     */
    public function crawl() {
        // Start crawling from the base URL
        $this->crawlPage($this->baseUrl);
        
        // Sort words by frequency (descending)
        arsort($this->foundWords);
        
        return [
            'words' => $this->foundWords,
            'pages' => $this->indexedPages,
            'total_words' => $this->totalWordCount
        ];
    }
    
    /**
     * Crawl a single page
     * 
     * @param string $url The URL to crawl
     * @return void
     */
    private function crawlPage($url) {
        // Check if we've already crawled this URL or reached the limit
        if (in_array($url, $this->crawledUrls) || count($this->crawledUrls) >= $this->maxPages) {
            return;
        }
        
        // Add to crawled URLs
        $this->crawledUrls[] = $url;
        
        // Get the page content
        $content = $this->getPageContent($url);
        if (!$content) {
            return;
        }
        
        // Parse the HTML
        $dom = new DOMDocument();
        @$dom->loadHTML($content);
        
        // Get the page title
        $title = '';
        $titleTags = $dom->getElementsByTagName('title');
        if ($titleTags->length > 0) {
            $title = $titleTags->item(0)->textContent;
        }
        
        // Extract text content
        $body = $dom->getElementsByTagName('body');
        $textContent = '';
        if ($body->length > 0) {
            $textContent = $body->item(0)->textContent;
        }
        
        // Index the page
        $this->indexPage($url, $title, $textContent);
        
        // Find and crawl links
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!$href) {
                continue;
            }
            
            // Convert relative URLs to absolute
            $absoluteUrl = $this->getAbsoluteUrl($href, $url);
            if (!$absoluteUrl) {
                continue;
            }
            
            // Only crawl URLs from the same domain
            $urlParts = parse_url($absoluteUrl);
            $urlDomain = isset($urlParts['host']) ? $urlParts['host'] : '';
            
            // Skip if not the same domain or has excluded extension
            if ($urlDomain !== $this->domain || $this->hasExcludedExtension($absoluteUrl)) {
                continue;
            }
            
            // Recursively crawl the linked page
            $this->crawlPage($absoluteUrl);
        }
    }
    
    /**
     * Get the content of a page
     * 
     * @param string $url The URL to fetch
     * @return string|false The page content or false on failure
     */
    private function getPageContent($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'LegendDX Crawler/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Only return content for successful requests
        if ($httpCode == 200 && !empty($content)) {
            return $content;
        }
        
        return false;
    }
    
    /**
     * Index a page
     * 
     * @param string $url The URL of the page
     * @param string $title The page title
     * @param string $content The page content
     * @return void
     */
    private function indexPage($url, $title, $content) {
        // Add to indexed pages
        $this->indexedPages[] = [
            'url' => $url,
            'title' => $title,
            'content' => $content
        ];
        
        // Extract and count words
        $words = $this->extractWords($content);
        $this->totalWordCount += count($words);
        
        foreach ($words as $word) {
            // Skip stop words and short words
            if (in_array($word, $this->stopWords) || strlen($word) < 3) {
                continue;
            }
            
            // Increment word count
            if (isset($this->foundWords[$word])) {
                $this->foundWords[$word]++;
            } else {
                $this->foundWords[$word] = 1;
            }
        }
    }
    
    /**
     * Extract words from text
     * 
     * @param string $text The text to extract words from
     * @return array Array of words
     */
    private function extractWords($text) {
        // Convert to lowercase
        $text = strtolower($text);
        
        // Remove HTML tags
        $text = strip_tags($text);
        
        // Remove special characters
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        
        // Split into words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        return $words;
    }
    
    /**
     * Convert a relative URL to absolute
     * 
     * @param string $url The URL to convert
     * @param string $baseUrl The base URL
     * @return string|false The absolute URL or false on failure
     */
    private function getAbsoluteUrl($url, $baseUrl) {
        // Already absolute
        if (preg_match('~^(?:f|ht)tps?://~i', $url)) {
            return $url;
        }
        
        // Fragment URL (e.g., #section)
        if (substr($url, 0, 1) === '#') {
            return false;
        }
        
        // Schema-relative URL (e.g., //example.com/path)
        if (substr($url, 0, 2) === '//') {
            $urlParts = parse_url($baseUrl);
            return isset($urlParts['scheme']) ? $urlParts['scheme'] . ':' . $url : 'http:' . $url;
        }
        
        // Root-relative URL (e.g., /path)
        if (substr($url, 0, 1) === '/') {
            $urlParts = parse_url($baseUrl);
            return isset($urlParts['scheme']) && isset($urlParts['host']) 
                ? $urlParts['scheme'] . '://' . $urlParts['host'] . $url 
                : false;
        }
        
        // Document-relative URL (e.g., path/to/file.html)
        $basePath = preg_replace('/\/[^\/]*$/', '/', $baseUrl);
        return $basePath . $url;
    }
    
    /**
     * Check if URL has an excluded extension
     * 
     * @param string $url The URL to check
     * @return bool True if URL has an excluded extension
     */
    private function hasExcludedExtension($url) {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        return in_array($extension, $this->excludedExtensions);
    }
}

/**
 * Helper function to check if a word is valid for indexing
 * 
 * @param string $word The word to check
 * @return bool True if the word is valid
 */
function isValidWord($word) {
    // Must be at least 3 characters
    if (strlen($word) < 3) {
        return false;
    }
    
    // Must contain only letters
    return preg_match('/^[a-zA-Z]+$/', $word);
}

/**
 * Helper function to extract meta tags from HTML
 * 
 * @param string $html The HTML content
 * @return array Associative array of meta tags
 */
function extractMetaTags($html) {
    $metaTags = [];
    
    // Create DOM document
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    
    // Get all meta tags
    $metas = $dom->getElementsByTagName('meta');
    
    // Extract meta information
    foreach ($metas as $meta) {
        // Get name and content attributes
        $name = $meta->getAttribute('name');
        $property = $meta->getAttribute('property');
        $content = $meta->getAttribute('content');
        
        // Use name or property as the key
        if (!empty($name)) {
            $metaTags[$name] = $content;
        } elseif (!empty($property)) {
            $metaTags[$property] = $content;
        }
    }
    
    return $metaTags;
}

/**
 * Helper function to extract schema.org structured data
 * 
 * @param string $html The HTML content
 * @return array Associative array of structured data
 */
function extractStructuredData($html) {
    $structuredData = [];
    
    // Look for JSON-LD script tags
    if (preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches)) {
        foreach ($matches[1] as $json) {
            $data = json_decode($json, true);
            if ($data) {
                $structuredData[] = $data;
            }
        }
    }
    
    return $structuredData;
}

/**
 * Helper function to normalize a URL
 * 
 * @param string $url The URL to normalize
 * @return string The normalized URL
 */
function normalizeUrl($url) {
    // Parse URL
    $parts = parse_url($url);
    
    // Rebuild URL
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
    $host = isset($parts['host']) ? $parts['host'] : '';
    $path = isset($parts['path']) ? $parts['path'] : '';
    
    // Remove trailing slashes from path
    $path = rtrim($path, '/');
    
    // Remove default ports
    $port = '';
    if (isset($parts['port'])) {
        if (($scheme === 'http' && $parts['port'] !== 80) || 
            ($scheme === 'https' && $parts['port'] !== 443)) {
            $port = ':' . $parts['port'];
        }
    }
    
    // Normalize path
    $path = preg_replace('/\/+/', '/', $path);
    
    // Rebuild query string
    $query = '';
    if (isset($parts['query'])) {
        parse_str($parts['query'], $params);
        ksort($params);
        $query = '?' . http_build_query($params);
    }
    
    // Remove fragment
    $fragment = '';
    
    // Rebuild URL
    return $scheme . '://' . $host . $port . $path . $query . $fragment;
}

/**
 * Create required database tables
 * This function should be run once during setup
 * 
 * @param PDO $pdo PDO database connection
 * @return bool True on success
 */
function createCrawlerTables($pdo) {
    try {
        // Create registered_sites table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS registered_sites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(255) NOT NULL,
                title VARCHAR(255),
                description TEXT,
                keywords TEXT,
                subject VARCHAR(100),
                registration_date DATETIME,
                last_crawl DATETIME,
                UNIQUE KEY (url)
            )
        ");
        
        // Create word table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS word (
                id INT AUTO_INCREMENT PRIMARY KEY,
                word VARCHAR(255) NOT NULL,
                frequency INT DEFAULT 1,
                site_id INT,
                UNIQUE KEY (word, site_id),
                FOREIGN KEY (site_id) REFERENCES registered_sites(id) ON DELETE CASCADE
            )
        ");
        
        // Create site_pages table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS site_pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT,
                url VARCHAR(255) NOT NULL,
                title VARCHAR(255),
                content_hash CHAR(32),
                last_crawl DATETIME,
                UNIQUE KEY (site_id, url),
                FOREIGN KEY (site_id) REFERENCES registered_sites(id) ON DELETE CASCADE
            )
        ");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating crawler tables: " . $e->getMessage());
        return false;
    }
}

/**
 * Config for database connection 
 * This should be moved to a separate config file in production
 */
if (!function_exists('getDbConnection')) {
    function getDbConnection() {
        $dbHost = 'localhost';
        $dbName = 'legenddx';
        $dbUser = 'root';
        $dbPass = '';
        
        try {
            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
}

// Create a simple config.php file if it doesn't exist
if (!file_exists('config.php')) {
    $configContent = <<<PHP
<?php
/**
 * Database configuration
 */
function getDbConnection() {
    \$dbHost = 'localhost';
    \$dbName = 'legenddx';
    \$dbUser = 'root';
    \$dbPass = '';
    
    try {
        \$pdo = new PDO("mysql:host=\$dbHost;dbname=\$dbName;charset=utf8mb4", \$dbUser, \$dbPass);
        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return \$pdo;
    } catch (PDOException \$e) {
        error_log("Database connection failed: " . \$e->getMessage());
        return null;
    }
}

/**
 * Site configuration
 */
\$siteConfig = [
    'siteName' => 'Legend DX',
    'siteUrl' => 'https://jcmc.serveminecraft.net/',
    'adminEmail' => 'admin@example.com',
    'maxCrawlPages' => 50,
    'crawlerUserAgent' => 'LegendDX Crawler/1.0'
];
PHP;
    
    file_put_contents('config.php', $configContent);
}