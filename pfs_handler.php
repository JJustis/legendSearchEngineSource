<?php
/**
 * pfs_handler.php - Server-side implementation of PFS for Legend search engine
 * 
 * Handles encrypted search queries and encrypts search results
 * Self-contained version that does not rely on external config files
 */
session_start();
class PFSHandler {
    private $db = null;
    private $keys = [
        'serverPrivate' => null,
        'serverPublic' => null,
        'clientPublic' => null,
        'sharedSecret' => null
    ];
    
    /**
     * Constructor initializes database connection and keys
     */
    public function __construct() {
        // First ensure the database connection is set up properly
        $this->connectToDatabase();
        
        // Then load or generate keys
        $this->loadKeys();
    }
    
    /**
     * Connect to the database
     * Self-contained connection that doesn't rely on external config
     */
    private function connectToDatabase() {
        // Define database parameters directly
        $db_host = 'localhost';
        $db_user = 'root';
        $db_pass = '';
        $db_name = 'legend_search'; // Change this to your actual database name
        
        // Try to connect to the database
        try {
            $this->db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create necessary tables if they don't exist
            $this->createTables();
        } catch (PDOException $e) {
            error_log("PFS Database connection error: " . $e->getMessage());
            // Don't throw the exception, just log it and continue with null db
        }
    }
    
    /**
     * Create necessary tables if they don't exist
     */
    private function createTables() {
        try {
            // Create pfs_keys table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `pfs_keys` (
                    `id` INT PRIMARY KEY,
                    `server_private` TEXT NOT NULL,
                    `server_public` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");
            
            // Create pfs_sessions table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `pfs_sessions` (
                    `id` VARCHAR(64) PRIMARY KEY,
                    `client_public` TEXT NOT NULL,
                    `shared_secret` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `last_active` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Create pfs_logs table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `pfs_logs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `client_id` VARCHAR(64),
                    `action` VARCHAR(255) NOT NULL,
                    `details` TEXT,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (PDOException $e) {
            error_log("Error creating PFS tables: " . $e->getMessage());
        }
    }
    
    /**
     * Load existing keys or generate new ones
     */
    private function loadKeys() {
        // Check if we have a database connection
        if ($this->db === null) {
            // Generate temporary keys in memory (not persistent)
            $this->generateTemporaryKeys();
            return;
        }
        
        // Try to get existing server keys from database
        try {
            $stmt = $this->db->prepare("SELECT * FROM pfs_keys WHERE id = 1");
            $stmt->execute();
            $keys = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($keys) {
                $this->keys['serverPrivate'] = $keys['server_private'];
                $this->keys['serverPublic'] = $keys['server_public'];
            } else {
                // No keys found, generate new ones
                $this->generateServerKeys();
            }
        } catch (PDOException $e) {
            error_log("Error loading PFS keys: " . $e->getMessage());
            // Generate temporary keys
            $this->generateTemporaryKeys();
        }
    }
    
    /**
     * Generate new server keys
     */
    private function generateServerKeys() {
        // Generate strong random keys
        $privateKey = bin2hex(random_bytes(32));
        $publicKey = hash('sha256', $privateKey);
        
        $this->keys['serverPrivate'] = $privateKey;
        $this->keys['serverPublic'] = $publicKey;
        
        // If we have a database connection, save the keys
        if ($this->db !== null) {
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO pfs_keys (id, server_private, server_public) 
                    VALUES (1, :private, :public)
                    ON DUPLICATE KEY UPDATE 
                    server_private = :private, server_public = :public
                ");
                
                $stmt->execute([
                    ':private' => $privateKey,
                    ':public' => $publicKey
                ]);
                
                $this->logActivity('system', 'key_generation', 'Generated new server keys');
            } catch (PDOException $e) {
                error_log("Error saving server keys: " . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * Generate temporary in-memory keys (when database is unavailable)
     */
    private function generateTemporaryKeys() {
        // Generate simple keys for this session only
        $this->keys['serverPrivate'] = bin2hex(random_bytes(32));
        $this->keys['serverPublic'] = hash('sha256', $this->keys['serverPrivate']);
        
        // Log to error log
        error_log("PFS using temporary in-memory keys (not persistent)");
        
        return true;
    }
    
    /**
     * Handle key exchange with client
     * 
     * @param string $clientPublic Client's public key
     * @return array Response with server's public key
     */
    public function keyExchange($clientPublic) {
        // Store client's public key
        $this->keys['clientPublic'] = $clientPublic;
        
        // Calculate shared secret
        $this->calculateSharedSecret();
        
        // Generate client ID (hash of client public key)
        $clientId = hash('sha256', $clientPublic);
        
        // Store session in database if available
        if ($this->db !== null) {
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO pfs_sessions (id, client_public, shared_secret)
                    VALUES (:id, :public, :secret)
                    ON DUPLICATE KEY UPDATE
                    shared_secret = :secret, last_active = CURRENT_TIMESTAMP
                ");
                
                $stmt->execute([
                    ':id' => $clientId,
                    ':public' => $clientPublic,
                    ':secret' => $this->keys['sharedSecret']
                ]);
                
                // Log key exchange
                $this->logActivity($clientId, 'key_exchange', 'Key exchange completed');
            } catch (PDOException $e) {
                error_log("Error storing client session: " . $e->getMessage());
            }
        }
        
        // Store in session as fallback
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['pfs_client_public'] = $clientPublic;
        $_SESSION['pfs_shared_secret'] = $this->keys['sharedSecret'];
        
        return [
            'success' => true,
            'server_public' => $this->keys['serverPublic']
        ];
    }
    
    /**
     * Calculate shared secret using client public key and server private key
     */
    private function calculateSharedSecret() {
        if (!$this->keys['clientPublic'] || !$this->keys['serverPrivate']) {
            return false;
        }
        
        // In real implementation, use proper ECDH
        // For this demo, simulate with HMAC
        $this->keys['sharedSecret'] = hash_hmac(
            'sha256',
            $this->keys['clientPublic'],
            $this->keys['serverPrivate']
        );
        
        return true;
    }
    
    /**
     * Get client session by public key
     * 
     * @param string $clientPublic Client's public key
     * @return array|false Session data or false if not found
     */
    public function getClientSession($clientPublic) {
        // Generate client ID
        $clientId = hash('sha256', $clientPublic);
        
        // Try database first if available
        if ($this->db !== null) {
            try {
                $stmt = $this->db->prepare("
                    SELECT * FROM pfs_sessions 
                    WHERE id = :id
                ");
                
                $stmt->execute([':id' => $clientId]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($session) {
                    // Update last active time
                    $updateStmt = $this->db->prepare("
                        UPDATE pfs_sessions
                        SET last_active = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $updateStmt->execute([':id' => $clientId]);
                    
                    // Set keys from session
                    $this->keys['clientPublic'] = $session['client_public'];
                    $this->keys['sharedSecret'] = $session['shared_secret'];
                    
                    return $session;
                }
            } catch (PDOException $e) {
                error_log("Error retrieving client session: " . $e->getMessage());
            }
        }
        
        // Fallback to session-based storage
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['pfs_client_public']) && $_SESSION['pfs_client_public'] === $clientPublic) {
            $this->keys['clientPublic'] = $_SESSION['pfs_client_public'];
            $this->keys['sharedSecret'] = $_SESSION['pfs_shared_secret'];
            
            return [
                'id' => $clientId,
                'client_public' => $_SESSION['pfs_client_public'],
                'shared_secret' => $_SESSION['pfs_shared_secret']
            ];
        }
        
        return false;
    }
    
    /**
     * Encrypt a message using AES with the shared secret
     * 
     * @param string $message Message to encrypt
     * @return string|false Encrypted message or false on failure
     */
    public function encryptMessage($message) {
        if (!$this->keys['sharedSecret']) {
            return false;
        }
        
        // Generate a random IV
        $iv = openssl_random_pseudo_bytes(16);
        
        // Encrypt the message
        $encrypted = openssl_encrypt(
            $message,
            'AES-256-CBC',
            $this->keys['sharedSecret'],
            0,
            $iv
        );
        
        if ($encrypted === false) {
            return false;
        }
        
        // Combine IV and encrypted message
        $result = base64_encode($iv . $encrypted);
        
        return $result;
    }
    
    /**
     * Decrypt a message using AES with the shared secret
     * 
     * @param string $encryptedMessage Encrypted message
     * @return string|false Decrypted message or false on failure
     */
    public function decryptMessage($encryptedMessage) {
        if (!$this->keys['sharedSecret']) {
            return false;
        }
        
        try {
            // Decode the combined IV and encrypted message
            $decoded = base64_decode($encryptedMessage);
            
            // Extract IV (first 16 bytes)
            $iv = substr($decoded, 0, 16);
            $encrypted = substr($decoded, 16);
            
            // Decrypt the message
            $decrypted = openssl_decrypt(
                $encrypted,
                'AES-256-CBC',
                $this->keys['sharedSecret'],
                0,
                $iv
            );
            
            return $decrypted;
        } catch (Exception $e) {
            error_log("Error decrypting message: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle a secure search request using PFS
     * 
     * @param string $encryptedQuery Encrypted search query
     * @param string $clientPublic Client's public key for verification
     * @return array Encrypted search results
     */
    public function handleSecureSearch($encryptedQuery, $clientPublic) {
        // Get client session
        $session = $this->getClientSession($clientPublic);
        
        if (!$session) {
            // No session found, need to establish one first
            return [
                'success' => false,
                'message' => 'No secure session established',
                'action' => 'establish_session'
            ];
        }
        
        // Decrypt the query
        $query = $this->decryptMessage($encryptedQuery);
        
        if ($query === false) {
            return [
                'success' => false,
                'message' => 'Failed to decrypt query'
            ];
        }
        
        // Generate client ID for logging
        $clientId = hash('sha256', $clientPublic);
        
        // Log the search (without revealing the actual query)
        $this->logActivity($clientId, 'secure_search', 'Secure search performed');
        
        // Perform the search
        $results = $this->performSearch($query);
        
        // Encrypt the results
        $encryptedResults = $this->encryptMessage(json_encode($results));
        
        if ($encryptedResults === false) {
            return [
                'success' => false,
                'message' => 'Failed to encrypt search results'
            ];
        }
        
        return [
            'success' => true,
            'encrypted_results' => $encryptedResults
        ];
    }
    
    /**
     * Perform a search with the given query
     * 
     * @param string $query Search query
     * @return array Search results
     */
    private function performSearch($query) {
        // If database is not available, return dummy results
        if ($this->db === null) {
            return [
                [
                    'title' => 'Secure search result for "' . $query . '"',
                    'url' => 'https://example.com/result1',
                    'description' => 'This is a dummy secure search result since database is not available.',
                    'source' => 'pfs_secure',
                    'encrypted' => true
                ]
            ];
        }
        
        // Try to access the registered_sites table
        try {
            // Check if registered_sites table exists first to avoid errors
            $checkTable = $this->db->query("SHOW TABLES LIKE 'registered_sites'");
            $tableExists = $checkTable->rowCount() > 0;
            
            if ($tableExists) {
                // For demonstration, we'll create a simplified search
                $results = [];
                
                // Search in registered sites
                $stmt = $this->db->prepare("
                    SELECT id, title, url, description, keywords, subject
                    FROM registered_sites
                    WHERE title LIKE :query OR description LIKE :query OR keywords LIKE :query
                    ORDER BY registration_date DESC
                    LIMIT 10
                ");
                
                $searchPattern = '%' . $query . '%';
                $stmt->execute([':query' => $searchPattern]);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $results[] = [
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'url' => $row['url'],
                        'description' => $row['description'] ?: "Website about " . $row['subject'],
                        'source' => 'database',
                        'encrypted' => true  // Flag to indicate this came from encrypted search
                    ];
                }
                
                // If the word table exists, also search there
                $checkWordTable = $this->db->query("SHOW TABLES LIKE 'word'");
                $wordTableExists = $checkWordTable->rowCount() > 0;
                
                if ($wordTableExists && count($results) < 5) {
                    // Search for the word in the word table
                    $wordStmt = $this->db->prepare("
                        SELECT w.word, w.frequency, rs.title as site_title, rs.url as site_url, rs.description as site_description
                        FROM word w
                        JOIN registered_sites rs ON w.site_id = rs.id
                        WHERE w.word LIKE :query
                        ORDER BY w.frequency DESC
                        LIMIT 5
                    ");
                    
                    $wordStmt->execute([':query' => $query . '%']);
                    
                    while ($row = $wordStmt->fetch(PDO::FETCH_ASSOC)) {
                        $results[] = [
                            'title' => "'" . $row['word'] . "' found on " . $row['site_title'],
                            'url' => $row['site_url'],
                            'description' => $row['site_description'] ?: "This site contains information about '" . $row['word'] . "'.",
                            'source' => 'wordpedia',
                            'frequency' => $row['frequency'],
                            'encrypted' => true
                        ];
                    }
                }
                
                return $results;
            } else {
                // Registered_sites table doesn't exist, return dummy results
                return [
                    [
                        'title' => 'Encrypted search for "' . $query . '"',
                        'url' => 'https://example.com/search',
                        'description' => 'Secure search processed successfully, but no registered sites found.',
                        'source' => 'pfs_secure',
                        'encrypted' => true
                    ]
                ];
            }
        } catch (PDOException $e) {
            error_log("Error performing search: " . $e->getMessage());
            
            // Return a dummy result on error
            return [
                [
                    'title' => 'Secure search for "' . $query . '"',
                    'url' => 'https://example.com/search',
                    'description' => 'There was an error performing your secure search.',
                    'source' => 'pfs_error',
                    'encrypted' => true
                ]
            ];
        }
    }
    
    /**
     * Log PFS activity
     * 
     * @param string $clientId Client identifier
     * @param string $action Action performed
     * @param string $details Additional details
     * @return bool Success status
     */
    private function logActivity($clientId, $action, $details = '') {
        // Skip logging if database is not available
        if ($this->db === null) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO pfs_logs (client_id, action, details)
                VALUES (:client_id, :action, :details)
            ");
            
            return $stmt->execute([
                ':client_id' => $clientId,
                ':action' => $action,
                ':details' => $details
            ]);
        } catch (PDOException $e) {
            error_log("Error logging activity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Static method to handle PFS search from index.php
     * 
     * @param string $query Optional query to encrypt
     * @return string|false Encrypted query string or false if PFS not enabled
     */
    public static function handlePFSSearch($query = null) {
        // Check if PFS is enabled via cookie
        if (!isset($_COOKIE['pfs_enabled']) || $_COOKIE['pfs_enabled'] !== 'true') {
            return false;
        }
        
        // Create instance
        $handler = new self();
        
        // Check if we have an encrypted query parameter
        if (isset($_GET['eq']) && !empty($_GET['eq'])) {
            // This is an encrypted query - decrypt it
            $clientPublic = $_GET['cp'] ?? '';
            
            if (empty($clientPublic)) {
                // Try to get client public from session or cookies
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                
                if (isset($_SESSION['pfs_client_public'])) {
                    $clientPublic = $_SESSION['pfs_client_public'];
                } elseif (isset($_COOKIE['pfs_client_public'])) {
                    $clientPublic = $_COOKIE['pfs_client_public'];
                }
            }
            
            if (empty($clientPublic)) {
                return false;
            }
            
            // Get client session
            $session = $handler->getClientSession($clientPublic);
            
            if (!$session) {
                return false;
            }
            
            // Decrypt the query
            $decryptedQuery = $handler->decryptMessage($_GET['eq']);
            
            if ($decryptedQuery === false) {
                return false;
            }
            
            return $decryptedQuery;
        } elseif ($query !== null) {
            // We have a query to encrypt
            // This would be used when redirecting from a form submission
            
            // In this case, client already has the key established
            // and we're just encrypting the query for the URL
            
            // We would need the client public key
            // For now, just return the original query
            return $query;
        }
        
        return false;
    }
}

function generateSymmetricKey() {
    // Generate a secure 256-bit key
    return bin2hex(random_bytes(32));
}
$symmetricKey = generateSymmetricKey();
function encryptAES($data, $symmetricKey) {
    $ivLength = openssl_cipher_iv_length('AES-256-CBC');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encryptedData = openssl_encrypt($data, 'AES-256-CBC', $symmetricKey, OPENSSL_RAW_DATA, $iv);
    $encryptedDataWithIV = base64_encode($iv . $encryptedData);
    return $encryptedDataWithIV;
}

// Generate and store the symmetric key in the session

$_SESSION['ooee'] = $symmetricKey;
// API Endpoint for PFS operations - if called directly
if (php_sapi_name() !== 'cli' && 
    (!isset($_SERVER['SCRIPT_FILENAME']) || 
     basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) &&
    isset($_SERVER['REQUEST_METHOD']) && 
    $_SERVER['REQUEST_METHOD'] === 'POST') {
     
    header('Content-Type: application/json');
    
    // Get request body
    $requestBody = file_get_contents('php://input');
    $data = json_decode($requestBody, true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid request format']);
        exit;
    }
    
    $handler = new PFSHandler();
    
    // Process different actions
    switch ($data['action'] ?? '') {
        case 'key_exchange':
            if (isset($data['client_public'])) {
                $result = $handler->keyExchange($data['client_public']);
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing client public key']);
            }
            break;
            
        case 'secure_search':
            if (isset($data['encrypted_query']) && isset($data['client_public'])) {
                $result = $handler->handleSecureSearch($data['encrypted_query'], $data['client_public']);
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, $data['encrypted_query'], $data['client_public'] => 'Missing required parameters']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    
    exit;
}