<?php
session_start();
header('Content-Type: text/html');

echo '<!DOCTYPE html>
<html>
<head>
    <title>PFS Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        pre { background: #f0f0f0; padding: 10px; }
        .section { margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>PFS Debug Tool</h1>';

// Display session info
echo '<div class="section">
    <h2>Session Information</h2>
    <p>Current Session ID: ' . session_id() . '</p>';

if (!empty($_SESSION)) {
    echo '<pre>' . print_r($_SESSION, true) . '</pre>';
} else {
    echo '<p>No session data available.</p>';
}
echo '</div>';

// Handle test decryption
if (isset($_GET['test']) && $_GET['test'] === 'decrypt' && !empty($_GET['data'])) {
    require_once 'pfs_handler.php';
    
    echo '<div class="section">
        <h2>Decryption Test</h2>';
    
    try {
        // Set session if provided
        if (!empty($_GET['session'])) {
            session_write_close();
            session_id($_GET['session']);
            session_start();
            echo '<p>Switched to session: ' . session_id() . '</p>';
        }
        
        // Set test key if provided
        if (!empty($_GET['key'])) {
            $_SESSION['pfs_shared_secret'] = $_GET['key'];
            echo '<p>Set test shared secret: ' . substr($_GET['key'], 0, 8) . '...</p>';
        }
        
        echo '<p>Testing decryption with: ' . htmlspecialchars($_GET['data']) . '</p>';
        
        // Attempt decryption
        $decrypted = PFSHandler::decryptSearchQuery($_GET['data']);
        
        echo '<p>Decryption result: "' . htmlspecialchars($decrypted) . '"</p>';
    } catch (Exception $e) {
        echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    
    echo '</div>';
}

// Test form
echo '<div class="section">
    <h2>Test Decryption</h2>
    <form method="GET" action="pfs_test.php">
        <input type="hidden" name="test" value="decrypt">
        <div style="margin-bottom: 10px;">
            <label>Encrypted JSON data:</label><br>
            <textarea name="data" rows="5" cols="60">' . (isset($_GET['data']) ? htmlspecialchars($_GET['data']) : '{"iv":"...","ciphertext":"..."}') . '</textarea>
        </div>
        <div style="margin-bottom: 10px;">
            <label>Session ID (optional):</label><br>
            <input type="text" name="session" value="' . (isset($_GET['session']) ? htmlspecialchars($_GET['session']) : '') . '" style="width:300px;">
        </div>
        <div style="margin-bottom: 10px;">
            <label>Test Key (optional):</label><br>
            <input type="text" name="key" value="' . (isset($_GET['key']) ? htmlspecialchars($_GET['key']) : '') . '" style="width:400px;">
        </div>
        <div>
            <button type="submit">Test Decryption</button>
        </div>
    </form>
</div>';

echo '</body>
</html>';
?>