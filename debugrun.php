<?php
// Debug script for testing OpenSSL ECDH key generation
if (DIRECTORY_SEPARATOR === '\\') { // Windows
    // Use PHP's internal secure random generator instead of /dev/urandom
    ini_set('openssl.cafile', '');
    ini_set('openssl.capath', '');
}
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check OpenSSL extension
if (!extension_loaded('openssl')) {
    die("OpenSSL extension is not loaded");
}

// Show OpenSSL version
echo "OpenSSL Version: " . OPENSSL_VERSION_TEXT . "<br>";

// List available curves
$curves = openssl_get_curve_names();
echo "Available curves:<br>";
foreach ($curves as $curve) {
    echo "- $curve<br>";
}

try {
    // Try basic key generation without ECDH-specific options first
    echo "<br>Attempting basic RSA key generation...<br>";
    $basic_res = openssl_pkey_new();
    
    if ($basic_res === false) {
        echo "Failed to generate basic key: " . openssl_error_string() . "<br>";
    } else {
        echo "Basic key generation successful<br>";
        openssl_pkey_free($basic_res);
    }
    
    // Now try EC key generation with P-256 curve
    echo "<br>Attempting ECDH key generation with P-256 curve...<br>";
    $config = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1'
    ];
    
    $res = openssl_pkey_new($config);
    
    if ($res === false) {
        echo "Failed to generate ECDH key: " . openssl_error_string() . "<br>";
    } else {
        echo "ECDH key generation successful<br>";
        
        // Try to export the private key
        $success = openssl_pkey_export($res, $privateKey);
        if (!$success) {
            echo "Failed to export private key: " . openssl_error_string() . "<br>";
        } else {
            echo "Private key export successful<br>";
        }
        
        // Try to get key details
        $details = openssl_pkey_get_details($res);
        if ($details === false) {
            echo "Failed to get key details: " . openssl_error_string() . "<br>";
        } else {
            echo "Key details retrieval successful<br>";
            echo "Key type: " . $details['type'] . "<br>";
            echo "Key bits: " . $details['bits'] . "<br>";
            
            if (isset($details['ec'])) {
                echo "EC key data available:<br>";
                echo "X length: " . strlen($details['ec']['x']) . " bytes<br>";
                echo "Y length: " . strlen($details['ec']['y']) . " bytes<br>";
            } else {
                echo "No EC key data found in details<br>";
            }
        }
        
        openssl_pkey_free($res);
    }
    
    echo "<br>Testing alternate approach with different curves:<br>";
    $altCurves = ['secp384r1', 'secp521r1', 'secp256k1'];
    
    foreach ($altCurves as $curve) {
        if (in_array($curve, $curves)) {
            echo "Testing curve $curve...<br>";
            $config = [
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => $curve
            ];
            
            $res = openssl_pkey_new($config);
            if ($res === false) {
                echo "Failed with curve $curve: " . openssl_error_string() . "<br>";
            } else {
                echo "Success with curve $curve<br>";
                openssl_pkey_free($res);
            }
        } else {
            echo "Curve $curve not available<br>";
        }
    }
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "<br>";
}
?>