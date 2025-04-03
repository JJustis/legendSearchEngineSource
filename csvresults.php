<?php
/**
 * Site Metadata Collector
 * 
 * This tool processes a CSV file containing a list of domains with their rankings
 * and collects metadata from each website to store in a database.
 * 
 * CSV Format:
 * "Rank","Domain","Open Page Rank"
 * "1","facebook.com","10.00"
 * "2","fonts.googleapis.com","10.00"
 * ...
 * 
 * Usage: php site_metadata_collector.php [options]
 * 
 * Options:
 *   --help                Show this help message
 *   --file=filename.csv   CSV file containing domain list (required)
 *   --batch=N             Process N domains at a time (default: 10)
 *   --delay=N             Add N milliseconds delay between requests (default: 1000)
 *   --threads=N           Use N parallel threads (default: 5, requires pcntl extension)
 *   --timeout=N           Timeout in seconds for requests (default: 10)
 *   --start=N             Start from the Nth record in the CSV (default: 1)
 *   --max=N               Maximum number of domains to process before exiting
 *   --db-host=host        Database hostname (default: localhost)
 *   --db-user=user        Database username (default: root)
 *   --db-pass=pass        Database password (default: empty)
 *   --db-name=name        Database name (default: legenddx)
 *   --table=name          Table name for storing results (default: registered_sites)
 *   --update              Update existing records if they exist (default: false)
 *   --quiet               Only output successful metadata collections
 *   --https               Try HTTPS first, then HTTP if HTTPS fails (default: false)
 * 
 * Examples:
 *   php site_metadata_collector.php --file=top_domains.csv --batch=20 --delay=500
 *   php site_metadata_collector.php --file=domains.csv --start=101 --max=1000
 *   php site_metadata_collector.php --file=domains.csv --update --https
 */

// Default configuration
$config = [
    'file' => 'top10milliondomains.csv',
    'batch_size' => 10,
    'delay' => 100,
    'threads' => 5,
    'timeout' => 3,
    'start' => 1,
    'max' => 100000000,
    'db_host' => 'localhost',
    'db_user' => 'root',
    'db_pass' => '',
    'db_name' => 'legenddx',
    'table' => 'registered_sites',
    'update' => false,
    'quiet' => false,
    'https' => false
];

// Parse command line arguments
$options = getopt('h', [
    'help', 'file:', 'batch:', 'delay:', 'threads:', 'timeout:',
    'start:', 'max:', 'db-host:', 'db-user:', 'db-pass:', 'db-name:',
    'table:', 'update', 'quiet', 'https'
]);

// Show help if requested or no file provided
if (isset($options['h']) || isset($options['help']) || empty($options)) {
    showHelp();
    exit(0);
}

// Apply options
if (isset($options['file'])) $config['file'] = $options['file'];
if (isset($options['batch'])) $config['batch_size'] = intval($options['batch']);
if (isset($options['delay'])) $config['delay'] = intval($options['delay']);
if (isset($options['threads'])) $config['threads'] = intval($options['threads']);
if (isset($options['timeout'])) $config['timeout'] = intval($options['timeout']);
if (isset($options['start'])) $config['start'] = intval($options['start']);
if (isset($options['max'])) $config['max'] = intval($options['max']);
if (isset($options['db-host'])) $config['db_host'] = $options['db-host'];
if (isset($options['db-user'])) $config['db_user'] = $options['db-user'];
if (isset($options['db-pass'])) $config['db_pass'] = $options['db-pass'];
if (isset($options['db-name'])) $config['db_name'] = $options['db-name'];
if (isset($options['table'])) $config['table'] = $options['table'];
if (isset($options['update'])) $config['update'] = true;
if (isset($options['quiet'])) $config['quiet'] = true;
if (isset($options['https'])) $config['https'] = true;

// Verify file existence
if (empty($config['file'])) {
    die("Error: CSV file not specified. Use --file=filename.csv\n");
}

if (!file_exists($config['file'])) {
    die("Error: File '{$config['file']}' not found\n");
}

// Connect to database
$db = connectToDatabase($config);

// Check if threads are supported
$threadsSupported = function_exists('pcntl_fork');
if (!$threadsSupported && $config['threads'] > 1) {
    echo "Warning: pcntl extension not available. Running in single-thread mode.\n";
    $config['threads'] = 1;
}

// Show configuration
if (!$config['quiet']) {
    echo "Site Metadata Collector\n";
    echo "----------------------\n";
    echo "CSV File: {$config['file']}\n";
    echo "Batch Size: {$config['batch_size']}\n";
    echo "Delay: {$config['delay']}ms\n";
    echo "Threads: {$config['threads']}\n";
    echo "Timeout: {$config['timeout']} seconds\n";
    echo "Start Position: {$config['start']}\n";
    if ($config['max'] > 0) echo "Max Domains: {$config['max']}\n";
    echo "Database: {$config['db_name']}.{$config['table']}\n";
    echo "Update Mode: " . ($config['update'] ? "On" : "Off") . "\n";
    echo "Protocol: " . ($config['https'] ? "HTTPS preferred" : "HTTP preferred") . "\n";
    echo "----------------------\n";
}

// Start processing
processCSV($config, $db);

// End of main program

// ================ FUNCTIONS ================

/**
 * Display help message
 */
function showHelp() {
    echo "Site Metadata Collector\n\n";
    echo "This tool processes a CSV file containing a list of domains with their rankings\n";
    echo "and collects metadata from each website to store in a database.\n\n";
    echo "CSV Format:\n";
    echo "\"Rank\",\"Domain\",\"Open Page Rank\"\n";
    echo "\"1\",\"facebook.com\",\"10.00\"\n\n";
    echo "Usage: php " . basename(__FILE__) . " [options]\n\n";
    echo "Options:\n";
    echo "  --help                Show this help message\n";
    echo "  --file=filename.csv   CSV file containing domain list (required)\n";
    echo "  --batch=N             Process N domains at a time (default: 10)\n";
    echo "  --delay=N             Add N milliseconds delay between requests (default: 1000)\n";
    echo "  --threads=N           Use N parallel threads (default: 5, requires pcntl extension)\n";
    echo "  --timeout=N           Timeout in seconds for requests (default: 10)\n";
    echo "  --start=N             Start from the Nth record in the CSV (default: 1)\n";
    echo "  --max=N               Maximum number of domains to process before exiting\n";
    echo "  --db-host=host        Database hostname (default: localhost)\n";
    echo "  --db-user=user        Database username (default: root)\n";
    echo "  --db-pass=pass        Database password (default: empty)\n";
    echo "  --db-name=name        Database name (default: legenddx)\n";
    echo "  --table=name          Table name for storing results (default: registered_sites)\n";
    echo "  --update              Update existing records if they exist (default: false)\n";
    echo "  --quiet               Only output successful metadata collections\n";
    echo "  --https               Try HTTPS first, then HTTP if HTTPS fails (default: false)\n\n";
    echo "Examples:\n";
    echo "  php " . basename(__FILE__) . " --file=top_domains.csv --batch=20 --delay=500\n";
    echo "  php " . basename(__FILE__) . " --file=domains.csv --start=101 --max=1000\n";
    echo "  php " . basename(__FILE__) . " --file=domains.csv --update --https\n";
}

/**
 * Connect to the database
 */
function connectToDatabase($config) {
    try {
        $dsn = "mysql:host={$config['db_host']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if database exists
        $stmt = $pdo->query("SHOW DATABASES LIKE '{$config['db_name']}'");
        if (!$stmt->fetchColumn()) {
            // Create database
            $pdo->exec("CREATE DATABASE `{$config['db_name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if (!$config['quiet']) echo "Database created: {$config['db_name']}\n";
        }
        
        // Connect to database
        $pdo->exec("USE `{$config['db_name']}`");
        
        // Create tables
        createTables($pdo, $config['table']);
        
        return $pdo;
    } catch (PDOException $e) {
        if (!$config['quiet']) echo "Database Connection Error: " . $e->getMessage() . "\n";
        return null;
    }
}

/**
 * Create necessary database tables
 */
function createTables($pdo, $tableName) {
    // Check if registered_sites table exists, if not create it
    $stmt = $pdo->query("SHOW TABLES LIKE '$tableName'");
    if (!$stmt->fetchColumn()) {
        $pdo->exec("
            CREATE TABLE `$tableName` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `keywords` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `subject` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `registration_date` datetime DEFAULT NULL,
                `last_crawl` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_url` (`url`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        if (!$config['quiet']) echo "Table created: $tableName\n";
    }
    
    // Create processing_stats table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `processing_stats` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `file_processed` varchar(255) NOT NULL,
            `domains_processed` int(11) NOT NULL,
            `successful_fetches` int(11) NOT NULL,
            `failed_fetches` int(11) NOT NULL,
            `start_time` datetime NOT NULL,
            `end_time` datetime NOT NULL,
            `duration_seconds` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Read domains from CSV file
 */
function readCSV($filename, $startPosition = 1, $maxRecords = 100000000) {
    $domains = [];
    $count = 0;
    $position = 0;
    
    if (($handle = fopen($filename, "r")) !== FALSE) {
        // Skip header row
        fgetcsv($handle, 1000, ",");
        $position++;
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $position++;
            
            // Skip records before start position
            if ($position < $startPosition) {
                continue;
            }
            
            if (count($data) >= 3) {
                $domains[] = [
                    'rank' => intval(trim($data[0], '"')),
                    'domain' => trim($data[1], '"'),
                    'open_page_rank' => floatval(trim($data[2], '"'))
                ];
                
                $count++;
                
                // Check if we've reached the maximum number of records
                if ($maxRecords > 0 && $count >= $maxRecords) {
                    break;
                }
            }
        }
        fclose($handle);
    }
    
    return $domains;
}

/**
 * Process CSV file
 */
function processCSV($config, $db) {
    // Read domains from CSV
    $domains = readCSV($config['file'], $config['start'], $config['max']);
    $totalDomains = count($domains);
    
    if ($totalDomains == 0) {
        die("Error: No domains found in CSV file or after the start position\n");
    }
    
    if (!$config['quiet']) {
        echo "Found $totalDomains domains to process\n";
    }
    
    // Initialize counters
    $processed = 0;
    $successful = 0;
    $failed = 0;
    $startTime = time();
    
    // Process in batches
    for ($i = 0; $i < $totalDomains; $i += $config['batch_size']) {
        $batch = array_slice($domains, $i, $config['batch_size']);
        
        // Process batch
        foreach ($batch as $domain) {
            $result = processDomain($domain, $config, $db);
            $processed++;
            
            if ($result['success']) {
                $successful++;
            } else {
                $failed++;
            }
            
            // Display progress
            if (!$config['quiet']) {
                $elapsedSeconds = time() - $startTime;
                $domainsPerSecond = $elapsedSeconds > 0 ? round($processed / $elapsedSeconds, 2) : 0;
                $progress = round(($processed / $totalDomains) * 100, 2);
                
                echo "Progress: $progress% | Processed: $processed/$totalDomains | Success: $successful | Failed: $failed | Speed: $domainsPerSecond domains/sec\n";
            }
            
            // Add delay
            if ($config['delay'] > 0) {
                usleep($config['delay'] * 1000); // Convert to microseconds
            }
        }
    }
    
    // Calculate final stats
    $endTime = time();
    $duration = $endTime - $startTime;
    $domainsPerSecond = $duration > 0 ? round($processed / $duration, 2) : 0;
    
    // Display summary
    if (!$config['quiet']) {
        echo "\n========== PROCESSING COMPLETE ==========\n";
        echo "File: {$config['file']}\n";
        echo "Domains Processed: $processed\n";
        echo "Successful: $successful\n";
        echo "Failed: $failed\n";
        echo "Duration: " . formatTime($duration) . "\n";
        echo "Speed: $domainsPerSecond domains/second\n";
        echo "Success Rate: " . round(($successful / $processed) * 100, 2) . "%\n";
        echo "========================================\n";
    }
    
    // Save processing stats to database
    if ($db) {
        try {
            $stmt = $db->prepare("
                INSERT INTO processing_stats 
                (file_processed, domains_processed, successful_fetches, failed_fetches, start_time, end_time, duration_seconds) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                basename($config['file']),
                $processed,
                $successful,
                $failed,
                date('Y-m-d H:i:s', $startTime),
                date('Y-m-d H:i:s', $endTime),
                $duration
            ]);
        } catch (PDOException $e) {
            if (!$config['quiet']) {
                echo "Warning: Could not save processing stats: " . $e->getMessage() . "\n";
            }
        }
    }
}

/**
 * Process a single domain
 */
function processDomain($domain, $config, $db) {
    $domainName = $domain['domain'];
    $result = [
        'domain' => $domainName,
        'rank' => $domain['rank'],
        'open_page_rank' => $domain['open_page_rank'],
        'success' => false,
        'title' => null,
        'description' => null,
        'keywords' => null,
        'subject' => null,
        'content_type' => null,
        'http_status' => null,
        'redirect_url' => null,
        'server' => null,
        'page_size_bytes' => 0,
        'load_time_ms' => 0,
        'protocol' => null,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Try HTTPS first if configured
    $protocols = $config['https'] ? ['https', 'http'] : ['http', 'https'];
    $success = false;
    
    foreach ($protocols as $protocol) {
        if ($success) break;
        
        $url = "$protocol://$domainName";
        
        if (!$config['quiet']) {
            echo "Processing $url... ";
        }
        
        $metadata = fetchWebsiteMetadata($url, $config['timeout']);
        
        if ($metadata['http_status'] >= 200 && $metadata['http_status'] < 400) {
            $success = true;
            $result = array_merge($result, $metadata);
            $result['success'] = true;
            $result['protocol'] = $protocol;
            
            if (!$config['quiet']) {
                echo "Success! Title: " . substr($metadata['title'] ?? 'No title', 0, 50) . "\n";
            }
        } else {
            if (!$config['quiet']) {
                echo "Failed with status code: " . ($metadata['http_status'] ?? 'No response') . "\n";
            }
        }
    }
    
    // Check for robots.txt
    $robotsTxtUrl = $result['protocol'] . "://" . $domainName . "/robots.txt";
    $robotsResult = checkResourceExists($robotsTxtUrl, $config['timeout']);
    $result['has_robots_txt'] = $robotsResult ? 1 : 0;
    
    // Check for sitemap
    $sitemapUrl = $result['protocol'] . "://" . $domainName . "/sitemap.xml";
    $sitemapResult = checkResourceExists($sitemapUrl, $config['timeout']);
    $result['has_sitemap'] = $sitemapResult ? 1 : 0;
    
    // Save to database
    if ($db) {
        saveToDatabase($db, $result, $config['table'], $config['update']);
    }
    
    return $result;
}

/**
 * Fetch website metadata
 */
function fetchWebsiteMetadata($url, $timeout = 10) {
    $metadata = [
        'title' => null,
        'description' => null,
        'keywords' => null,
        'http_status' => null,
        'content_type' => null,
        'redirect_url' => null,
        'server' => null,
        'page_size_bytes' => 0,
        'load_time_ms' => 0
    ];
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    
    $start_time = microtime(true);
    $response = curl_exec($ch);
    $end_time = microtime(true);
    
    $metadata['load_time_ms'] = round(($end_time - $start_time) * 1000); // in milliseconds
    
    // Process response if successful
    if ($response) {
        // Separate header and body
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        // Get response info
        $metadata['http_status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $metadata['content_type'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $metadata['page_size_bytes'] = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        
        // Get redirect URL if any
        $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($final_url !== $url) {
            $metadata['redirect_url'] = $final_url;
        }
        
        // Parse header for server information
        if (preg_match('/Server: ([^\r\n]+)/i', $header, $matches)) {
            $metadata['server'] = $matches[1];
        }
        
        // Parse HTML content
        if ($metadata['http_status'] >= 200 && $metadata['http_status'] < 400 && $body) {
            // Extract title
            if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $body, $matches)) {
                $metadata['title'] = trim($matches[1]);
            }
            
            // Extract meta description
            if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/si', $body, $matches) ||
                preg_match('/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/si', $body, $matches)) {
                $metadata['description'] = trim($matches[1]);
            }
            
            // Extract meta keywords
            if (preg_match('/<meta[^>]*name=["\']keywords["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/si', $body, $matches) ||
                preg_match('/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']keywords["\'][^>]*>/si', $body, $matches)) {
                $metadata['keywords'] = trim($matches[1]);
            }
        }
    } else {
        $metadata['http_status'] = curl_errno($ch);
    }
    
    curl_close($ch);
    return $metadata;
}

/**
 * Check if a resource exists (e.g., robots.txt, sitemap.xml)
 */
function checkResourceExists($url, $timeout = 5) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode >= 200 && $httpCode < 400);
}

/**
 * Save domain metadata to database
 */
function saveToDatabase($pdo, $data, $tableName, $update = false) {
    try {
        // Build the domain URL
        $url = ($data['protocol'] ? $data['protocol'] . '://' : 'http://') . $data['domain'];
        
        // Check if domain already exists
        $stmt = $pdo->prepare("SELECT id FROM `$tableName` WHERE url = ?");
        $stmt->execute([$url]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing && $update) {
            // Update existing record
            $stmt = $pdo->prepare("
                UPDATE `$tableName` SET 
                    title = ?,
                    description = ?,
                    keywords = ?,
                    last_crawl = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['title'],
                $data['description'],
                $data['keywords'],
                $data['timestamp'],
                $existing['id']
            ]);
            
            return [true, false, $existing['id']];
        } elseif (!$existing) {
            // Insert new record
            $stmt = $pdo->prepare("
                INSERT INTO `$tableName` 
                (url, title, description, keywords, registration_date, last_crawl) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $url,
                $data['title'],
                $data['description'],
                $data['keywords'],
                $data['timestamp'], // Use current time as registration date
                $data['timestamp']
            ]);
            
            return [true, true, $pdo->lastInsertId()];
        }
        
        return [false, false, null];
    } catch (PDOException $e) {
        return [false, false, null, $e->getMessage()];
    }
}

/**
 * Format time in human-readable format
 */
function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}
?>