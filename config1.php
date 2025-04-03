<?php
/**
 * Config file for Legend DX
 * Database and site configuration settings
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Database configuration
 */
$db_host = 'localhost';
$db_name = 'legend_search';
$db_user = 'root';
$db_pass = '';
$db_charset = 'utf8mb4';

$dbConfig = [
    'host' => $db_host,
    'database' => $db_name,
    'username' => $db_user,
    'password' => $db_pass,
    'charset' => $db_charset,
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];

/**
 * Site configuration
 */
$siteConfig = [
    'title' => 'Legend DX Search',
    'description' => 'Secure and private web search engine',
    'version' => '1.0.0',
    'admin_email' => 'admin@example.com',
    'results_per_page' => 10,
    'enable_pfs' => true, // Enable Perfect Forward Secrecy by default
    'enable_analytics' => true,
    'debug_mode' => false
];

/**
 * Search configuration
 */
$searchConfig = [
    'stopWords' => ['the', 'and', 'or', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'about', 'like', 'through', 'over', 'before', 'between', 'after', 'from', 'up', 'down', 'of', 'a', 'an'],
    'minQueryLength' => 3,
    'maxQueryLength' => 100,
    'defaultResultsPerPage' => 10,
    'maxResultsPerPage' => 50
];

// Check if the getDbConnection function already exists to avoid redeclaration
if (!function_exists('getDbConnection')) {
    /**
     * Get PDO database connection
     * 
     * @return PDO|null Database connection or null on failure
     */
    function getDbConnection() {
        global $db_host, $db_name, $db_user, $db_pass, $db_charset;
        
        static $pdo = null;
        
        // Return existing connection if available
        if ($pdo !== null) {
            return $pdo;
        }
        
        try {
            $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $pdo = new PDO($dsn, $db_user, $db_pass, $options);
            
            return $pdo;
        } catch (PDOException $e) {
            // Log error
            error_log("Database connection failed: " . $e->getMessage());
            
            // Return null on failure
            return null;
        }
    }
}

// Create a global PDO connection for use throughout the application
$pdo = getDbConnection();

// Utility functions

/**
 * Log error messages
 * 
 * @param string $message The error message
 * @param string $level The error level (error, warning, info)
 * @return bool Success or failure
 */
function logError($message, $level = 'error') {
    $logFile = __DIR__ . '/logs/' . date('Y-m-d') . '.log';
    
    // Create logs directory if it doesn't exist
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    return file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Generate a secure random token
 * 
 * @param int $length Token length
 * @return string Random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Safely get a value from $_GET, $_POST, or $_COOKIE with default
 * 
 * @param string $key The key to look for
 * @param mixed $default Default value if key not found
 * @param string $source 'get', 'post', or 'cookie'
 * @return mixed The value or default
 */
function getParam($key, $default = null, $source = 'get') {
    switch (strtolower($source)) {
        case 'post':
            return isset($_POST[$key]) ? $_POST[$key] : $default;
        case 'cookie':
            return isset($_COOKIE[$key]) ? $_COOKIE[$key] : $default;
        case 'get':
        default:
            return isset($_GET[$key]) ? $_GET[$key] : $default;
    }
}

/**
 * Check if debug mode is enabled
 * 
 * @return bool True if debug mode is enabled
 */
function isDebugMode() {
    global $siteConfig;
    return isset($siteConfig['debug_mode']) && $siteConfig['debug_mode'] === true;
}