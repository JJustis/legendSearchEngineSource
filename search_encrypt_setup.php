<?php
session_start();
header('Content-Type: application/json');

// Error logging
function log_encrypt($message) {
    error_log('[SearchEncrypt Setup] ' . $message);
}

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method Not Allowed');
    }

    // Get input data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Validate input
    if (!$data || !isset($data['action']) || !isset($data['secretKey'])) {
        log_encrypt('Invalid request data');
        throw new Exception('Invalid request data');
    }
    
    // Process setup
    if ($data['action'] === 'setup_encryption') {
        log_encrypt('Processing encryption setup request');
        
        // Secret key (in hex)
        $secretKey = $data['secretKey'];
        
        // Validate key
        if (!$secretKey || !ctype_xdigit($secretKey) || strlen($secretKey) < 32) {
            throw new Exception('Invalid secret key format');
        }
        
        // Store in session
        $_SESSION['search_encrypt_key'] = $secretKey;
        
        log_encrypt('Secret key stored in session ' . session_id());
        
        // Return session ID
        echo json_encode([
            'success' => true,
            'sessionId' => session_id()
        ]);
        
    } else {
        throw new Exception('Unknown action: ' . $data['action']);
    }
    
} catch (Exception $e) {
    log_encrypt('Error: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>