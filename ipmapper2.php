<?php
/**
 * Full IP Range Scanner with Website Metadata Collection
 * 
 * This tool can scan the entire IPv4 address space (0.0.0.0 to 255.255.255.255)
 * or any specified range, collecting hostname information and website metadata.
 * 
 * Usage: php full_ip_scanner.php [options]
 * 
 * Options:
 *   --help                Show this help message
 *   --range=X.X.X.X-Y.Y.Y.Y    Process a range of IPs (all octets supported)
 *   --start=X.X.X.X       Start IP address (if no range specified)
 *   --end=Y.Y.Y.Y         End IP address (if no range specified)
 *   --batch=N             Process N IPs at a time (default: 100)
 *   --delay=N             Add N milliseconds delay between lookups (default: 10)
 *   --threads=N           Use N parallel threads (default: 10, requires pcntl extension)
 *   --metadata            Collect website metadata (default: hostname only)
 *   --timeout=N           Timeout in seconds for requests (default: 3)
 *   --save-file=filename  Save results to CSV file (default: results.csv)
 *   --resume=filename     Resume from last position in file
 *   --quiet               Only output IPs with hostnames
 *   --random              Use random sampling across the IP space
 *   --max=N               Maximum number of IPs to process before exiting
 *   --db-host=host        Database hostname (default: localhost)
 *   --db-user=user        Database username (default: root)
 *   --db-pass=pass        Database password (default: empty)
 *   --db-name=name        Database name (default: ip_mapper)
 * 
 * Examples:
 *   php full_ip_scanner.php --range=8.8.8.0-8.8.8.255 --metadata
 *   php full_ip_scanner.php --start=192.168.1.1 --end=192.168.5.255 --batch=50
 *   php full_ip_scanner.php --random --max=10000 --metadata
 *   php full_ip_scanner.php --range=172.16.0.0-172.31.255.255 --save-file=private_ranges.csv
 */

// Default configuration
$config = [
    'batch_size' => 100,
    'delay' => 0,
    'threads' => 10,
    'collect_metadata' => true,
    'timeout' => 3,
    'save_file' => 'results.csv',
    'resume_file' => null,
    'quiet' => false,
    'random' => false,
    'max_ips' => 0,
    'db_host' => 'localhost',
    'db_user' => 'root',
    'db_pass' => '',
    'db_name' => 'ip_mapper'
];

// Parse command line arguments
$options = getopt('h', [
    'help', 'range:', 'start:', 'end:', 'batch:', 'delay:', 'threads:', 'metadata',
    'timeout:', 'save-file:', 'resume:', 'quiet', 'random', 'max:', 
    'db-host:', 'db-user:', 'db-pass:', 'db-name:'
]);

// Show help if requested or no arguments provided
if (isset($options['h']) || isset($options['help']) || empty($options)) {
    showHelp();
    exit(0);
}

// Apply options
if (isset($options['batch'])) $config['batch_size'] = intval($options['batch']);
if (isset($options['delay'])) $config['delay'] = intval($options['delay']);
if (isset($options['threads'])) $config['threads'] = intval($options['threads']);
if (isset($options['metadata'])) $config['collect_metadata'] = true;
if (isset($options['timeout'])) $config['timeout'] = intval($options['timeout']);
if (isset($options['save-file'])) $config['save_file'] = $options['save-file'];
if (isset($options['resume'])) $config['resume_file'] = $options['resume'];
if (isset($options['quiet'])) $config['quiet'] = true;
if (isset($options['random'])) $config['random'] = true;
if (isset($options['max'])) $config['max_ips'] = intval($options['max']);
if (isset($options['db-host'])) $config['db_host'] = $options['db-host'];
if (isset($options['db-user'])) $config['db_user'] = $options['db-user'];
if (isset($options['db-pass'])) $config['db_pass'] = $options['db-pass'];
if (isset($options['db-name'])) $config['db_name'] = $options['db-name'];

// Determine IP range
$startIP = '0.0.0.0';
$endIP = '255.255.255.255';

if (isset($options['range'])) {
    $range = explode('-', $options['range']);
    if (count($range) == 2) {
        $startIP = trim($range[0]);
        $endIP = trim($range[1]);
    } else {
        die("Error: Invalid range format. Use --range=start-end\n");
    }
} else {
    if (isset($options['start'])) $startIP = $options['start'];
    if (isset($options['end'])) $endIP = $options['end'];
}

// Validate IPs
if (!filter_var($startIP, FILTER_VALIDATE_IP)) {
    die("Error: Invalid start IP address format\n");
}

if (!filter_var($endIP, FILTER_VALIDATE_IP)) {
    die("Error: Invalid end IP address format\n");
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
    echo "Full IP Range Scanner\n";
    echo "--------------------\n";
    echo "IP Range: $startIP to $endIP\n";
    echo "Batch Size: {$config['batch_size']}\n";
    echo "Delay: {$config['delay']}ms\n";
    echo "Threads: {$config['threads']}\n";
    echo "Collect Metadata: " . ($config['collect_metadata'] ? "Yes" : "No") . "\n";
    echo "Save File: {$config['save_file']}\n";
    echo "Random Sampling: " . ($config['random'] ? "Yes" : "No") . "\n";
    if ($config['max_ips'] > 0) echo "Max IPs: {$config['max_ips']}\n";
    echo "--------------------\n";
}

// Start scanning
scanIPRange($startIP, $endIP, $config, $db);

// End of main program

// ================ FUNCTIONS ================

/**
 * Display help message
 */
function showHelp() {
    echo "Full IP Range Scanner with Website Metadata Collection\n\n";
    echo "This tool can scan the entire IPv4 address space (0.0.0.0 to 255.255.255.255)\n";
    echo "or any specified range, collecting hostname information and website metadata.\n\n";
    echo "Usage: php " . basename(__FILE__) . " [options]\n\n";
    echo "Options:\n";
    echo "  --help                Show this help message\n";
    echo "  --range=X.X.X.X-Y.Y.Y.Y    Process a range of IPs (all octets supported)\n";
    echo "  --start=X.X.X.X       Start IP address (if no range specified)\n";
    echo "  --end=Y.Y.Y.Y         End IP address (if no range specified)\n";
    echo "  --batch=N             Process N IPs at a time (default: 100)\n";
    echo "  --delay=N             Add N milliseconds delay between lookups (default: 10)\n";
    echo "  --threads=N           Use N parallel threads (default: 10, requires pcntl extension)\n";
    echo "  --metadata            Collect website metadata (default: hostname only)\n";
    echo "  --timeout=N           Timeout in seconds for requests (default: 3)\n";
    echo "  --save-file=filename  Save results to CSV file (default: results.csv)\n";
    echo "  --resume=filename     Resume from last position in file\n";
    echo "  --quiet               Only output IPs with hostnames\n";
    echo "  --random              Use random sampling across the IP space\n";
    echo "  --max=N               Maximum number of IPs to process before exiting\n";
    echo "  --db-host=host        Database hostname (default: localhost)\n";
    echo "  --db-user=user        Database username (default: root)\n";
    echo "  --db-pass=pass        Database password (default: empty)\n";
    echo "  --db-name=name        Database name (default: ip_mapper)\n\n";
    echo "Examples:\n";
    echo "  php " . basename(__FILE__) . " --range=8.8.8.0-8.8.8.255 --metadata\n";
    echo "  php " . basename(__FILE__) . " --start=192.168.1.1 --end=192.168.5.255 --batch=50\n";
    echo "  php " . basename(__FILE__) . " --random --max=10000 --metadata\n";
    echo "  php " . basename(__FILE__) . " --range=172.16.0.0-172.31.255.255 --save-file=private_ranges.csv\n";
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
        createTables($pdo);
        
        return $pdo;
    } catch (PDOException $e) {
        if (!$config['quiet']) echo "Database Connection Error: " . $e->getMessage() . "\n";
        return null;
    }
}

/**
 * Create necessary database tables
 */
function createTables($pdo) {
    // Hostnames Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hostnames` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip_address` VARCHAR(39) NOT NULL,
            `hostname` VARCHAR(255) NOT NULL,
            `first_seen` DATETIME NOT NULL,
            `last_seen` DATETIME NOT NULL,
            `visit_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `lookup_time_ms` INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `idx_ip_address` (`ip_address`),
            INDEX `idx_hostname` (`hostname`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Website Metadata Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `website_metadata` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `hostname_id` BIGINT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NULL DEFAULT NULL,
            `description` TEXT NULL DEFAULT NULL,
            `keywords` TEXT NULL DEFAULT NULL,
            `content_snippet` TEXT NULL DEFAULT NULL,
            `last_fetched` DATETIME NOT NULL,
            `http_status` INT NULL DEFAULT NULL,
            `content_type` VARCHAR(100) NULL DEFAULT NULL,
            `page_size_bytes` INT UNSIGNED NULL DEFAULT NULL,
            `website_url` VARCHAR(255) NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_hostname_id` (`hostname_id`),
            CONSTRAINT `fk_website_metadata_hostname_id` FOREIGN KEY (`hostname_id`) 
                REFERENCES `hostnames` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Scan History Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `scan_history` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `start_ip` VARCHAR(39) NOT NULL,
            `end_ip` VARCHAR(39) NOT NULL,
            `ips_processed` BIGINT UNSIGNED NOT NULL,
            `hostnames_found` BIGINT UNSIGNED NOT NULL,
            `start_time` DATETIME NOT NULL,
            `end_time` DATETIME NOT NULL,
            `duration_seconds` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Convert an IP address to its long integer representation
 */
function ip2long_custom($ip) {
    $long = ip2long($ip);
    // Convert signed int to unsigned
    if ($long < 0) {
        $long += 4294967296;
    }
    return $long;
}

/**
 * Convert a long integer to an IP address
 */
function long2ip_custom($long) {
    // Convert unsigned int to signed if needed
    if ($long > 2147483647) {
        $long -= 4294967296;
    }
    return long2ip($long);
}

/**
 * Perform a hostname lookup with timeout
 */
function lookupHostname($ip, $timeout = 3) {
    // Set a custom timeout for DNS lookups
    putenv("RES_OPTIONS=timeout:{$timeout} attempts:1");
    
    $start_time = microtime(true);
    $hostname = @gethostbyaddr($ip);
    $end_time = microtime(true);
    
    $lookup_time = round(($end_time - $start_time) * 1000); // in milliseconds
    $is_hostname_found = ($hostname && $hostname !== $ip);
    
    return [
        'ip' => $ip,
        'hostname' => $hostname,
        'is_hostname_found' => $is_hostname_found,
        'lookup_time' => $lookup_time,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Fetch website metadata
 */
function fetchWebsiteMetadata($ip, $hostname, $timeout = 3) {
    $url = "http://$hostname";
    $metadata = [
        'title' => null,
        'description' => null,
        'keywords' => null,
        'content_snippet' => null,
        'http_status' => null,
        'content_type' => null,
        'page_size_bytes' => 0,
        'website_url' => $url
    ];
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    
    $html = curl_exec($ch);
    
    // Get response info
    $metadata['http_status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $metadata['content_type'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $metadata['page_size_bytes'] = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    
    // If there's a redirect, get the final URL
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if ($final_url !== $url) {
        $metadata['website_url'] = $final_url;
    }
    
    curl_close($ch);
    
    // Parse HTML content if successful
    if ($metadata['http_status'] >= 200 && $metadata['http_status'] < 300 && $html) {
        // Extract title
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $matches)) {
            $metadata['title'] = trim($matches[1]);
        }
        
        // Extract meta description
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/si', $html, $matches) ||
            preg_match('/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/si', $html, $matches)) {
            $metadata['description'] = trim($matches[1]);
        }
        
        // Extract meta keywords
        if (preg_match('/<meta[^>]*name=["\']keywords["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/si', $html, $matches) ||
            preg_match('/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']keywords["\'][^>]*>/si', $html, $matches)) {
            $metadata['keywords'] = trim($matches[1]);
        }
        
        // Extract content snippet
        $body_content = '';
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $matches)) {
            $body_content = $matches[1];
        }
        
        $text_content = strip_tags($body_content);
        $text_content = preg_replace('/\s+/', ' ', $text_content); // Normalize whitespace
        $metadata['content_snippet'] = trim(substr($text_content, 0, 500));
    }
    
    return $metadata;
}

/**
 * Save hostname to database
 */
function saveHostnameToDB($pdo, $result, $metadata = null) {
    try {
        // Check if hostname already exists in database
        $stmt = $pdo->prepare("SELECT id, visit_count FROM hostnames WHERE ip_address = ?");
        $stmt->execute([$result['ip']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing record
            $stmt = $pdo->prepare("
                UPDATE hostnames SET 
                    hostname = ?,
                    last_seen = ?,
                    visit_count = visit_count + 1,
                    lookup_time_ms = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $result['hostname'],
                $result['timestamp'],
                $result['lookup_time'],
                $existing['id']
            ]);
            
            $hostname_id = $existing['id'];
            $is_new = false;
        } else {
            // Insert new record
            $stmt = $pdo->prepare("
                INSERT INTO hostnames 
                (ip_address, hostname, first_seen, last_seen, lookup_time_ms) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $result['ip'],
                $result['hostname'],
                $result['timestamp'],
                $result['timestamp'],
                $result['lookup_time']
            ]);
            
            $hostname_id = $pdo->lastInsertId();
            $is_new = true;
        }
        
        // Save metadata if provided
        if ($metadata && $hostname_id) {
            $stmt = $pdo->prepare("
                INSERT INTO website_metadata 
                (hostname_id, title, description, keywords, content_snippet, last_fetched, 
                http_status, content_type, page_size_bytes, website_url) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                keywords = VALUES(keywords),
                content_snippet = VALUES(content_snippet),
                last_fetched = VALUES(last_fetched),
                http_status = VALUES(http_status),
                content_type = VALUES(content_type),
                page_size_bytes = VALUES(page_size_bytes),
                website_url = VALUES(website_url)
            ");
            
            $stmt->execute([
                $hostname_id,
                $metadata['title'],
                $metadata['description'],
                $metadata['keywords'],
                $metadata['content_snippet'],
                date('Y-m-d H:i:s'),
                $metadata['http_status'],
                $metadata['content_type'],
                $metadata['page_size_bytes'],
                $metadata['website_url']
            ]);
        }
        
        return [true, $is_new, $hostname_id];
    } catch (PDOException $e) {
        return [false, false, null, $e->getMessage()];
    }
}

/**
 * Save results to CSV file
 */
function saveToCSV($file, $result, $metadata = null, $append = true) {
    $mode = $append ? 'a' : 'w';
    $fp = fopen($file, $mode);
    
    // Write headers if new file
    if (!$append || filesize($file) == 0) {
        $headers = ['IP', 'Hostname', 'Found', 'Lookup Time (ms)', 'Timestamp'];
        
        if ($metadata) {
            $headers = array_merge($headers, ['Title', 'Description', 'Status', 'Content Type', 'URL']);
        }
        
        fputcsv($fp, $headers);
    }
    
    // Prepare row data
    $row = [
        $result['ip'],
        $result['hostname'],
        $result['is_hostname_found'] ? 'Yes' : 'No',
        $result['lookup_time'],
        $result['timestamp']
    ];
    
    // Add metadata if available
    if ($metadata) {
        $row = array_merge($row, [
            $metadata['title'] ?? '',
            $metadata['description'] ?? '',
            $metadata['http_status'] ?? '',
            $metadata['content_type'] ?? '',
            $metadata['website_url'] ?? ''
        ]);
    }
    
    fputcsv($fp, $row);
    fclose($fp);
}

/**
 * Process a single IP address
 */
function processIP($ip, $config, $pdo = null) {
    $result = lookupHostname($ip, $config['timeout']);
    $metadata = null;
    
    // Collect metadata if enabled and hostname found
    if ($config['collect_metadata'] && $result['is_hostname_found']) {
        $metadata = fetchWebsiteMetadata($ip, $result['hostname'], $config['timeout']);
    }
    
    // Save to database if connected
    if ($pdo && $result['is_hostname_found']) {
        list($success, $is_new) = saveHostnameToDB($pdo, $result, $metadata);
    }
    
    // Save to CSV file
    if ($result['is_hostname_found'] || !$config['quiet']) {
        saveToCSV($config['save_file'], $result, $metadata, true);
    }
    
    // Output to console
    if ($result['is_hostname_found'] || !$config['quiet']) {
        $status = $result['is_hostname_found'] ? "{$result['hostname']} ({$result['lookup_time']} ms)" : "No hostname";
        echo sprintf("%-15s  %s\n", $result['ip'], $status);
        
        if ($result['is_hostname_found'] && $metadata && $metadata['title']) {
            echo sprintf("              Title: %s\n", substr($metadata['title'], 0, 60) . (strlen($metadata['title']) > 60 ? '...' : ''));
        }
    }
    
    return $result;
}

/**
 * Generate the next batch of IPs
 */
function getNextBatch($startLong, $endLong, $batchSize, $random = false) {
    $batch = [];
    
    if ($random) {
        // Random sampling across range
        $range = $endLong - $startLong + 1;
        for ($i = 0; $i < $batchSize; $i++) {
            $randomLong = $startLong + mt_rand(0, $range - 1);
            $batch[] = long2ip_custom($randomLong);
        }
    } else {
        // Sequential batch
        $currentLong = $startLong;
        for ($i = 0; $i < $batchSize && $currentLong <= $endLong; $i++) {
            $batch[] = long2ip_custom($currentLong);
            $currentLong++;
        }
    }
    
    return [$batch, $currentLong];
}

/**
 * Main scanning function
 */
function scanIPRange($startIP, $endIP, $config, $db) {
    // Convert IPs to long integers
    $startLong = ip2long_custom($startIP);
    $endLong = ip2long_custom($endIP);
    $currentLong = $startLong;
    
    // Initialize counters
    $totalProcessed = 0;
    $totalFound = 0;
    $startTime = time();
    
    // Initialize CSV file
    if (!file_exists($config['save_file']) || !$config['resume_file']) {
        // Create new file
        saveToCSV($config['save_file'], [
            'ip' => '0.0.0.0',
            'hostname' => 'example.com',
            'is_hostname_found' => true,
            'lookup_time' => 0,
            'timestamp' => date('Y-m-d H:i:s')
        ], [
            'title' => 'Example',
            'description' => 'Description',
            'http_status' => 200,
            'content_type' => 'text/html',
            'website_url' => 'http://example.com'
        ], false);
        
        // Remove the example row
        $contents = file($config['save_file']);
        if (count($contents) > 1) {
            $fp = fopen($config['save_file'], 'w');
            fwrite($fp, $contents[0]);
            fclose($fp);
        }
    }
    
    // Resume from last position if requested
    if ($config['resume_file'] && file_exists($config['resume_file'])) {
        $lines = file($config['resume_file']);
        $lastLine = trim(end($lines));
        if (!empty($lastLine)) {
            $parts = str_getcsv($lastLine);
            if (!empty($parts[0])) {
                $lastIP = $parts[0];
                $lastLong = ip2long_custom($lastIP);
                if ($lastLong >= $startLong && $lastLong < $endLong) {
                    $currentLong = $lastLong + 1;
                    if (!$config['quiet']) {
                        echo "Resuming from IP: " . long2ip_custom($currentLong) . "\n";
                    }
                }
            }
        }
    }
    
    // Main processing loop
    while ($currentLong <= $endLong) {
        // Check if max IPs limit reached
        if ($config['max_ips'] > 0 && $totalProcessed >= $config['max_ips']) {
            if (!$config['quiet']) {
                echo "Reached maximum IPs limit ({$config['max_ips']}). Stopping.\n";
            }
            break;
        }
        
        // Get next batch of IPs
        list($batch, $nextLong) = getNextBatch($currentLong, $endLong, $config['batch_size'], $config['random']);
        $currentLong = $nextLong;
        
        // Process each IP in the batch
        foreach ($batch as $ip) {
            $result = processIP($ip, $config, $db);
            $totalProcessed++;
            
            if ($result['is_hostname_found']) {
                $totalFound++;
            }
            
            // Add delay
            if ($config['delay'] > 0) {
                usleep($config['delay'] * 1000); // Convert to microseconds
            }
        }
        
        // Display progress
        if (!$config['quiet'] && $totalProcessed % ($config['batch_size'] * 10) == 0) {
            $elapsedSeconds = time() - $startTime;
            $ipsPerSecond = $elapsedSeconds > 0 ? round($totalProcessed / $elapsedSeconds, 2) : 0;
            $progress = $endLong > $startLong ? round((($currentLong - $startLong) / ($endLong - $startLong)) * 100, 2) : 100;
            
            echo "----------------------------------------------------\n";
            echo "Progress: $progress% | Processed: $totalProcessed IPs | Found: $totalFound hostnames\n";
            echo "Speed: $ipsPerSecond IPs/sec | Elapsed: " . formatTime($elapsedSeconds) . "\n";
            echo "Current IP: " . long2ip_custom($currentLong) . "\n";
            echo "----------------------------------------------------\n";
        }
    }
    
    // Calculate final stats
    $endTime = time();
    $duration = $endTime - $startTime;
    $ipsPerSecond = $duration > 0 ? round($totalProcessed / $duration, 2) : 0;
    
    // Display summary
    if (!$config['quiet']) {
        echo "\n========== SCAN COMPLETE ==========\n";
        echo "Range: $startIP to $endIP\n";
        echo "Processed: " . number_format($totalProcessed) . " IPs\n";
    echo "Found: " . number_format($totalFound) . " hostnames\n";
    echo "Duration: " . formatTime($duration) . "\n";
    echo "Speed: $ipsPerSecond IPs/second\n";
    echo "Resolution Rate: " . ($totalProcessed > 0 ? round(($totalFound / $totalProcessed) * 100, 2) : 0) . "%\n";
    echo "Results saved to: {$config['save_file']}\n";
    echo "====================================\n";
    
    // Save scan history to database
    if ($db) {
        try {
            $stmt = $db->prepare("
                INSERT INTO scan_history 
                (start_ip, end_ip, ips_processed, hostnames_found, start_time, end_time, duration_seconds) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $startIP,
                $endIP,
                $totalProcessed,
                $totalFound,
                date('Y-m-d H:i:s', $startTime),
                date('Y-m-d H:i:s', $endTime),
                $duration
            ]);
        } catch (PDOException $e) {
            if (!$config['quiet']) {
                echo "Warning: Could not save scan history: " . $e->getMessage() . "\n";
            }
        }
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
}
?>