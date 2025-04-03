<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Error handling and logging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the PFS Handler
require_once 'PFS-Handler.php';
require_once 'config1.php';

// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

// Get request body
$rawInput = file_get_contents('php://input');
$requestData = json_decode($rawInput, true);

// Validate input
if (!$requestData) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request format']);
    exit();
}

try {
    // Use configuration 
    $config = new PFSConfig();
    
    // Create PFS Handler
    $pfsHandler = new PFSHandler($config);
    
    // Handle different actions
    switch ($requestData['action'] ?? '') {
        case 'key_exchange':
            // Validate client public key
            if (!isset($requestData['client_public'])) {
                throw new Exception('Missing client public key');
            }
            
            // Perform key exchange
            $result = $pfsHandler->keyExchange($requestData['client_public']);
            
            // Send response
            echo json_encode($result);
            break;
        
        case 'secure_search':
            // Validate required parameters
            if (!isset($requestData['encrypted_query']) || !isset($requestData['client_public'])) {
                throw new Exception('Missing required parameters for secure search');
            }
            
            // Perform secure search
            $result = $pfsHandler->handleSecureSearch(
                $requestData['encrypted_query'], 
                $requestData['client_public']
            );
            
            echo json_encode($result);
            break;
        
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Exception $e) {
    // Log the error
    error_log('PFS Exchange Error: ' . $e->getMessage());
    
    // Send error response
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
exit();