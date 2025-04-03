<?php
session_start();
header('Content-Type: application/json');

// Error logging
function log_pfs($message) {
    error_log('[PFS Handshake] ' . $message);
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
    if (!$data || !isset($data['action']) || !isset($data['clientPublicKey'])) {
        log_pfs('Invalid request data');
        throw new Exception('Invalid request data');
    }
    
    // Process handshake
    if ($data['action'] === 'initiate_handshake') {
        log_pfs('Processing handshake request');
        
        // Client key (in hex)
        $clientKey = $data['clientPublicKey'];
        
        // Validate client key
        if (!$clientKey || !ctype_xdigit($clientKey)) {
            throw new Exception('Invalid client key format');
        }
        
        // Generate a strong server key
        $serverKey = bin2hex(random_bytes(32));
        
        // Store in session
        $_SESSION['pfs_client_key'] = $clientKey;
        $_SESSION['pfs_server_key'] = $serverKey;
        
        log_pfs('Keys generated and stored in session ' . session_id());
        
        // Return server key to client
        echo json_encode([
            'success' => true,
            'serverPublicKey' => $serverKey,
            'sessionId' => session_id()
        ]);
        
    } else {
        throw new Exception('Unknown action: ' . $data['action']);
    }
    
} catch (Exception $e) {
    log_pfs('Error: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>