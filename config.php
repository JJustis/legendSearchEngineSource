<?php
/**
 * Config file for Legend DX
 * Database and site configuration settings
 */

/**
 * Database configuration
 */
$dbConfig = [
    'host' => 'localhost',
    'database' => 'legenddx',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
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
    'siteName' => 'Legend DX',
    'siteUrl' => 'https://jcmc.serveminecraft.net/legenddx/',
    'adminEmail' => 'admin@example.com',
    'maxCrawlPages' => 50,
    'crawlerUserAgent' => 'LegendDX Crawler/1.0'
];

/**
 * File paths
 */
$pathConfig = [
    'wordpediaDir' => '../wordpedia/pages/',
    'imageDir' => 'images/',
    'uploadsDir' => 'uploads/'
];

/**
 * Search configuration
 */
$searchConfig = [
    'resultsPerPage' => 10,
    'maxSearchHistory' => 100,
    'minWordLength' => 3,
    'stopWords' => ['a', 'an', 'the', 'and', 'or', 'but', 'if', 'then', 'else', 'when', 'at', 'from', 'by', 'on', 'off', 'for', 'in', 'out', 'over', 'to', 'into', 'with']
];

/**
 * Crawler configuration
 */
$crawlerConfig = [
    'maxPagesPerSite' => 50,
    'maxCrawlDepth' => 3,
    'requestTimeout' => 30,
    'crawlInterval' => 86400, // 24 hours in seconds
    'excludedExtensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'zip', 'rar', 'exe', 'css', 'js']
];

/**
 * Get PDO database connection
 * 
 * @return PDO Database connection
 */
function getDbConnection() {
    global $dbConfig;
    
    try {
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
        
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $dbConfig['options']);
        
        return $pdo;
    } catch (PDOException $e) {
        // Log error
        error_log("Database connection failed: " . $e->getMessage());
        
        // In production, you might want to handle this differently
        // For now, just return null
        return null;
    }
}

/**
 * Create required database tables if they don't exist
 * 
 * @return boolean Success or failure
 */
function createRequiredTables() {
    try {
        $pdo = getDbConnection();
        
        if (!$pdo) {
            return false;
        }
        
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
        
        // Create search_history table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS search_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                query VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                search_date DATETIME,
                results_count INT
            )
        ");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating required tables: " . $e->getMessage());
        return false;
    }
}

// Create tables when the config file is included for the first time
$createTablesResult = createRequiredTables();