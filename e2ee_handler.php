<?php
class E2EEHandler {
    // Get session data
    private static function getSessionData($sessionId = null) {
        // If session ID provided, restore that session
        if ($sessionId && session_id() !== $sessionId) {
            session_write_close();
            session_id($sessionId);
            session_start();
        }
        
        // Return session key
        return [
            'secret_key' => $_SESSION['e2ee_secret_key'] ?? null
        ];
    }

    // Handles encrypted search queries
    public static function handleE2EESearch() {
        // Check if normal search or E2EE search
        if (isset($_GET['e2ee']) && $_GET['e2ee'] === 'active' && isset($_GET['q']) && isset($_GET['sid'])) {
            try {
                error_log('[E2EE] Processing encrypted search query');
                
                // Restore session
                $sessionId = $_GET['sid'];
                $sessionData = self::getSessionData($sessionId);
                
                if (empty($sessionData['secret_key'])) {
                    error_log('[E2EE] No secret key found in session');
                    return '';
                }
                
                // Parse encrypted query data
                $encryptedData = json_decode($_GET['q'], true);
                if (!$encryptedData || !isset($encryptedData['iv']) || !isset($encryptedData['ciphertext'])) {
                    error_log('[E2EE] Invalid encrypted data format');
                    return '';
                }
                
                // Decrypt query
                $decryptedQuery = self::decryptData($encryptedData, $sessionData['secret_key']);
                error_log('[E2EE] Decrypted query: ' . $decryptedQuery);
                
                return $decryptedQuery;
                
            } catch (Exception $e) {
                error_log('[E2EE] Decryption error: ' . $e->getMessage());
                return '';
            }
        }
        
        // Regular search - just return the query
        return $_GET['q'] ?? '';
    }
    
    // Encrypt search results for secure transmission
    public static function encryptResults($results, $sessionId) {
        if (empty($sessionId)) return $results;
        
        try {
            $sessionData = self::getSessionData($sessionId);
            if (empty($sessionData['secret_key'])) {
                return $results;
            }
            
            $encryptedResults = [];
            
            foreach ($results as $result) {
                // Prepare HTML content
                $htmlContent = '<div class="result">';
                $htmlContent .= '<div class="result-url">' . htmlspecialchars($result['url']) . '</div>';
                $htmlContent .= '<h3 class="result-title"><a href="' . htmlspecialchars($result['url']) . '" target="_blank">' . 
                                htmlspecialchars($result['title']) . '</a></h3>';
                $htmlContent .= '<p class="result-description">' . htmlspecialchars($result['description']) . '</p>';
                $htmlContent .= '</div>';
                
                // Encrypt the content
                $encrypted = self::encryptData([
                    'html' => $htmlContent,
                    'url' => $result['url'],
                    'timestamp' => time()
                ], $sessionData['secret_key']);
                
                // Add encrypted wrapper
                $encryptedResults[] = [
                    'encrypted' => true,
                    'data' => $encrypted
                ];
            }
            
            return $encryptedResults;
            
        } catch (Exception $e) {
            error_log('[E2EE] Result encryption error: ' . $e->getMessage());
            return $results; // Fall back to unencrypted results
        }
    }
    
    // Decrypt data using AES-CBC
    private static function decryptData($encryptedData, $key) {
        try {
            // Convert from hex
            $iv = hex2bin($encryptedData['iv']);
            $ciphertext = hex2bin($encryptedData['ciphertext']);
            $key = hex2bin($key);
            
            // Validate IV length
            if (strlen($iv) !== 16) {
                error_log('[E2EE] Invalid IV length: ' . strlen($iv));
                $iv = str_pad(substr($iv, 0, 16), 16, "\0");
            }
            
            // Decrypt using AES-256-CBC
            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-cbc',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($decrypted === false) {
                throw new Exception('Decryption failed: ' . openssl_error_string());
            }
            
            return $decrypted;
        } catch (Exception $e) {
            error_log('[E2EE] Decryption error details: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // Encrypt data using AES-CBC
    private static function encryptData($data, $key) {
        // Convert data to JSON if it's an array
        if (is_array($data)) {
            $plaintext = json_encode($data);
        } else {
            $plaintext = $data;
        }
        
        // Convert key from hex
        $key = hex2bin($key);
        
        // Generate random IV (16 bytes for CBC)
        $iv = random_bytes(16);
        
        // Encrypt using AES-256-CBC
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($ciphertext === false) {
            throw new Exception('Encryption failed: ' . openssl_error_string());
        }
        
        // Return as hex encoded values
        return [
            'iv' => bin2hex($iv),
            'ciphertext' => bin2hex($ciphertext)
        ];
    }
    
    // Render encrypted results for client-side decryption
    public static function renderEncryptedResults($results) {
        $output = '';
        
        foreach ($results as $result) {
            if (isset($result['encrypted']) && $result['encrypted']) {
                // Encrypted result - create placeholder for client-side decryption
                $encodedData = htmlspecialchars(json_encode($result['data']));
                $output .= '<div class="encrypted-result" data-encrypted="' . $encodedData . '">';
                $output .= '<div class="loading-placeholder">';
                $output .= '<span class="loading-spinner"></span>';
                $output .= '<span>Decrypting...</span>';
                $output .= '</div>';
                $output .= '</div>';
            } else {
                // Regular result
                $output .= '<div class="result">';
                $output .= '<div class="result-url">' . htmlspecialchars($result['url']) . '</div>';
                $output .= '<h3 class="result-title"><a href="' . htmlspecialchars($result['url']) . '" target="_blank">' . 
                          htmlspecialchars($result['title']) . '</a></h3>';
                $output .= '<p class="result-description">' . htmlspecialchars($result['description']) . '</p>';
                $output .= '</div>';
            }
        }
        
        return $output;
    }
}
?>