<?php
/**
 * secure_search.php - Endpoint for handling secure search requests
 * 
 * This file serves as the API endpoint for PFS secure search functionality,
 * accepting encrypted queries and returning encrypted results.
 */

// Include necessary files
require_once 'config.php';
require_once 'pfs_handler.php';

// Set content type to JSON
header('Content-Type: application/json');

// Enable CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed'
    ]);
    exit;
}

// Get request body
$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);

// Validate request data
if (!$data || !isset($data['action'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request format'
    ]);
    exit;
}

// Create PFS handler
$pfsHandler = new PFSHandler();

// Process different actions
switch ($data['action']) {
    case 'key_exchange':
        // Handle key exchange request
        if (!isset($data['client_public'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing client public key'
            ]);
            exit;
        }
        
        $result = $pfsHandler->keyExchange($data['client_public']);
        echo json_encode($result);
        break;
        
    case 'secure_search':
        // Handle secure search request
        if (!isset($data['encrypted_query']) || !isset($data['client_public'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameters'
            ]);
            exit;
        }
        
        $result = $pfsHandler->handleSecureSearch($data['encrypted_query'], $data['client_public']);
        echo json_encode($result);
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Unknown action'
        ]);
}