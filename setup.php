<?php
/**
 * setup.php - Initial database setup script for Legend DX
 * 
 * This script creates the necessary database and tables for Legend DX.
 * Run this script once to set up the database structure.
 */

// Check if this is being run from the command line or browser
$isCli = (php_sapi_name() === 'cli');

// Function to output messages based on environment
function output($message) {
    global $isCli;
    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        echo $message . '<br>';
    }
}

// Set up headers for browser
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legend DX - Database Setup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 20px;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            color: #4285f4;
        }
        .success {
            color: #34a853;
            font-weight: bold;
        }
        .error {
            color: #ea4335;
            font-weight: bold;
        }
        .warning {
            color: #fbbc05;
            font-weight: bold;
        }
        pre {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <h1>Legend DX - Database Setup</h1>
';
}

output("Starting Legend DX database setup...");
output("-----------------------------------");

// Database connection parameters
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'legenddx';

try {
    // Connect to MySQL without specifying a database first
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    output("Connected to MySQL server successfully.");
    
    // Check if database exists
    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$dbName'");
    $dbExists = $stmt->fetch();
    
    if ($dbExists) {
        output("<span class='warning'>Database '$dbName' already exists.</span>");
    } else {
        // Create the database
        $pdo->exec("CREATE DATABASE `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        output("<span class='success'>Database '$dbName' created successfully.</span>");
    }
    
    // Connect to the specific database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    output("Connected to database '$dbName'.");
    
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
    output("<span class='success'>Table 'registered_sites' created or already exists.</span>");
    
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
    output("<span class='success'>Table 'word' created or already exists.</span>");
    
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
    output("<span class='success'>Table 'site_pages' created or already exists.</span>");
    
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
    output("<span class='success'>Table 'search_history' created or already exists.</span>");
    
    // Create analytics table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS analytics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_url VARCHAR(255),
            referrer_url VARCHAR(255),
            ip_address VARCHAR(45),
            user_agent TEXT,
            visit_date DATETIME,
            session_id VARCHAR(255)
        )
    ");
    output("<span class='success'>Table 'analytics' created or already exists.</span>");
    
    // Check if wordpedia directory exists
    $wordpediaDir = '../wordpedia/pages';
    if (!is_dir($wordpediaDir)) {
        if (mkdir($wordpediaDir, 0755, true)) {
            output("<span class='success'>Wordpedia directory created at: $wordpediaDir</span>");
        } else {
            output("<span class='error'>Failed to create Wordpedia directory at: $wordpediaDir</span>");
        }
    } else {
        output("<span class='warning'>Wordpedia directory already exists at: $wordpediaDir</span>");
    }
    
    // All done!
    output("-----------------------------------");
    output("<span class='success'>Database setup completed successfully!</span>");
    output("You can now use Legend DX.");
    
    // Generate config file if it doesn't exist
    if (!file_exists('config.php')) {
        $configContent = <<<PHP
<?php
/**
 * Config file for Legend DX
 * Database and site configuration settings
 */

/**
 * Database configuration
 */
\$dbConfig = [
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
\$siteConfig = [
    'siteName' => 'Legend DX',
    'siteUrl' => 'https://jcmc.serveminecraft.net/legenddx/',
    'adminEmail' => 'admin@example.com',
    'maxCrawlPages' => 50,
    'crawlerUserAgent' => 'LegendDX Crawler/1.0'
];

/**
 * File paths
 */
\$pathConfig = [
    'wordpediaDir' => '../wordpedia/pages/',
    'imageDir' => 'images/',
    'uploadsDir' => 'uploads/'
];

/**
 * Search configuration
 */
\$searchConfig = [
    'resultsPerPage' => 10,
    'maxSearchHistory' => 100,
    'minWordLength' => 3,
    'stopWords' => ['a', 'an', 'the', 'and', 'or', 'but', 'if', 'then', 'else', 'when', 'at', 'from', 'by', 'on', 'off', 'for', 'in', 'out', 'over', 'to', 'into', 'with']
];

/**
 * Crawler configuration
 */
\$crawlerConfig = [
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
    global \$dbConfig;
    
    try {
        \$dsn = "mysql:host={\$dbConfig['host']};dbname={\$dbConfig['database']};charset={\$dbConfig['charset']}";
        
        \$pdo = new PDO(\$dsn, \$dbConfig['username'], \$dbConfig['password'], \$dbConfig['options']);
        
        return \$pdo;
    } catch (PDOException \$e) {
        // Log error
        error_log("Database connection failed: " . \$e->getMessage());
        
        // In production, you might want to handle this differently
        // For now, just return null
        return null;
    }
}
PHP;
        file_put_contents('config.php', $configContent);
        output("<span class='success'>Configuration file (config.php) generated successfully.</span>");
    } else {
        output("<span class='warning'>Configuration file (config.php) already exists.</span>");
    }
    
} catch (PDOException $e) {
    output("<span class='error'>Error: " . $e->getMessage() . "</span>");
}

// Close HTML if browser
if (!$isCli) {
    echo '</body></html>';
}