<?php
class SearchEncryptHandler {
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
            'secret_key' => $_SESSION['search_encrypt_key'] ?? null
        ];
    }

    // Handles encrypted search queries
    public static function handleEncryptedSearch() {
        // Check if encrypted search
        if (isset($_GET['encrypt']) && $_GET['encrypt'] === 'active' && 
            isset($_GET['q']) && isset($_GET['sid'])) {
            try {
                error_log('[SearchEncrypt] Processing encrypted search query');
                
                // Restore session
                $sessionId = $_GET['sid'];
                $sessionData = self::getSessionData($sessionId);
                
                if (empty($sessionData['secret_key'])) {
                    error_log('[SearchEncrypt] No secret key found in session');
                    return '';
                }
                
                // Parse encrypted query data
                $encryptedData = json_decode($_GET['q'], true);
                if (!$encryptedData || !isset($encryptedData['iv']) || !isset($encryptedData['ciphertext'])) {
                    error_log('[SearchEncrypt] Invalid encrypted data format');
                    return '';
                }
                
                // Decrypt query
                $decryptedQuery = self::decryptData($encryptedData, $sessionData['secret_key']);
                error_log('[SearchEncrypt] Decrypted query: ' . $decryptedQuery);
                
                // Check if this is a URL
                $isUrl = isset($_GET['url']) && $_GET['url'] === '1';
                if ($isUrl) {
                    // If it's a URL, make sure it has proper protocol
                    $decryptedQuery = self::ensureUrlProtocol($decryptedQuery);
                    
                    // Log and redirect
                    error_log('[SearchEncrypt] URL detected, redirecting to: ' . $decryptedQuery);
                    
                    // Store the URL in a session variable for redirection
                    $_SESSION['encrypted_url_redirect'] = $decryptedQuery;
                }
                
                return $decryptedQuery;
                
            } catch (Exception $e) {
                error_log('[SearchEncrypt] Decryption error: ' . $e->getMessage());
                return '';
            }
        }
        
        // Regular search - just return the query
        return $_GET['q'] ?? '';
    }
    
    // Check for URL redirect from encrypted search
    public static function checkForRedirect() {
        if (isset($_SESSION['encrypted_url_redirect'])) {
            $url = $_SESSION['encrypted_url_redirect'];
            unset($_SESSION['encrypted_url_redirect']);
            
            // Validate URL (basic security check)
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                header("Location: $url");
                exit;
            }
        }
    }
    
    // Ensure URL has proper protocol
    private static function ensureUrlProtocol($url) {
        if (!preg_match('/^https?:\/\//i', $url)) {
            return 'https://' . $url;
        }
        return $url;
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
                error_log('[SearchEncrypt] Invalid IV length: ' . strlen($iv));
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
            error_log('[SearchEncrypt] Decryption error details: ' . $e->getMessage());
            throw $e;
        }
    }
}
?>