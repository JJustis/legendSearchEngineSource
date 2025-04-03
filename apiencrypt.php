<?php
// Assuming the encrypted data is stored in an array or fetched from the database
session_start();

// Sample encrypted results
$encryptedResults = [
    [
        'encrypted_title' => 'sampleTitle',
        'encrypted_url' => 'sampleUrl',
        'encrypted_source' => 'sampleSource',
        'encrypted_description' => 'sampleDescription',
        'iv' => base64_encode(openssl_random_pseudo_bytes(16)) // Example IV
    ]
];

// Return the encrypted results as a JSON response
header('Content-Type: application/json');
echo json_encode($encryptedResults);
?>