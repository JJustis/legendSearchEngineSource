<?php
class SecureKeyExchange {
    private $privateKey;
    private $publicKey;

    public function __construct() {
        // Generate EC key pair for ECDH
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1'
        ];
        $keyPair = openssl_pkey_new($config);
        
        // Extract private and public keys
        openssl_pkey_export($keyPair, $this->privateKey);
        $pubKeyDetails = openssl_pkey_get_details($keyPair);
        $this->publicKey = $pubKeyDetails['key'];
    }

    public function getPublicKey() {
        return $this->publicKey;
    }

    public function computeSharedSecret($clientPublicKey) {
        // Compute shared secret using ECDH
        $sharedSecret = openssl_dh_compute_key($clientPublicKey, $this->privateKey);
        
        // Derive AES key from shared secret
        return hash('sha256', $sharedSecret, true);
    }
}

class SecureSearch {
    private $aesKey;

    public function setAesKey($key) {
        $this->aesKey = $key;
    }

    public function encryptResults($results) {
        $iv = random_bytes(12); // GCM IV
        $tag = '';
        
        $encryptedResults = openssl_encrypt(
            json_encode($results), 
            'aes-256-gcm', 
            $this->aesKey, 
            OPENSSL_RAW_DATA, 
            $iv, 
            $tag
        );

        return base64_encode(json_encode([
            'ciphertext' => base64_encode($encryptedResults),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag)
        ]));
    }
}

// Handle key exchange request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'init_key_exchange') {
        $keyExchange = new SecureKeyExchange();
        
        // Store private key in session for later use
        session_start();
        $_SESSION['private_key'] = $keyExchange->getPrivateKey();
        
        // Return server's public key
        echo json_encode([
            'server_public_key' => $keyExchange->getPublicKey()
        ]);
    } elseif ($action === 'perform_search') {
        // Receive client's public key and encrypted search query
        $clientPublicKey = $_POST['client_public_key'];
        $encryptedQuery = $_POST['encrypted_query'];
        
        // Compute shared secret
        $keyExchange = new SecureKeyExchange();
        $sharedSecret = $keyExchange->computeSharedSecret($clientPublicKey);
        
        // Decrypt search query
        $query = openssl_decrypt(
            base64_decode($encryptedQuery), 
            'aes-256-gcm', 
            $sharedSecret, 
            OPENSSL_RAW_DATA
        );
        
        // Perform search (using PFSHandler from previous implementation)
        $results = PFSHandler::handlePFSSearch($query);
        
        // Encrypt results
        $secureSearch = new SecureSearch();
        $secureSearch->setAesKey($sharedSecret);
        $encryptedResults = $secureSearch->encryptResults($results);
        
        echo json_encode([
            'encrypted_results' => $encryptedResults
        ]);
    }
}
?>