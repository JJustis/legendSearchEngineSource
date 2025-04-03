// pfs-client.js - Client-side implementation for the PFS demo

// Wrap in an IIFE to prevent global namespace pollution
(function(window) {
    // Check if pfsClient already exists to prevent redeclaration
    if (window.pfsClient) {
        console.warn('PFS Client already initialized');
        return;
    }

    // Global client object for the demo
    window.pfsClient = {
        ephemeralKeyPair: null,       // Current session's key pair
        sharedSecret: null,           // Derived shared secret
        sessionKey: null,             // Session encryption key
        sessionId: null,              // Current session ID
        isConnected: false,           // Connection status
        clientTerminal: null,         // Terminal output element
        
        // Initialize the client
        init: function(terminalElement) {
            // Store terminal element for output
            this.clientTerminal = terminalElement;
            this.logToTerminal('Client initialized', 'info');
        },
        
        // Log message to the client terminal
        logToTerminal: function(message, type = 'default') {
            if (!this.clientTerminal) return;
            
            const line = document.createElement('div');
            line.classList.add('terminal-line');
            
            // Format the line based on message type
            let formattedMessage = message;
            
            switch(type) {
                case 'command':
                    formattedMessage = `<span class="command">$ ${message}</span>`;
                    break;
                case 'key':
                    formattedMessage = `<span class="key">${message}</span>`;
                    break;
                case 'value':
                    formattedMessage = `<span class="value">${message}</span>`;
                    break;
                case 'string':
                    formattedMessage = `<span class="string">"${message}"</span>`;
                    break;
                case 'info':
                    formattedMessage = `<span style="color: #17a2b8;">[INFO] ${message}</span>`;
                    break;
                case 'success':
                    formattedMessage = `<span style="color: #28a745;">[SUCCESS] ${message}</span>`;
                    break;
                case 'error':
                    formattedMessage = `<span style="color: #dc3545;">[ERROR] ${message}</span>`;
                    break;
            }
            
            line.innerHTML = formattedMessage;
            this.clientTerminal.appendChild(line);
            this.clientTerminal.scrollTop = this.clientTerminal.scrollHeight;
        },
        
        // Generate new ephemeral key pair for the session
        generateEphemeralKeys: async function() {
            this.logToTerminal('Generating ephemeral key pair...', 'command');
            
            try {
                // Use WebCrypto API for secure key generation
                const keyPair = await window.crypto.subtle.generateKey(
                    {
                        name: "ECDH",
                        namedCurve: "P-256" // NIST P-256 curve
                    },
                    true, // Extractable, needed for demo purposes
                    ["deriveKey", "deriveBits"]
                );
                
                this.ephemeralKeyPair = keyPair;
                
                // Export public key for display and sharing
                const publicKeyRaw = await window.crypto.subtle.exportKey(
                    "spki",
                    keyPair.publicKey
                );
                
                // Generate a short fingerprint of the key for display
                const publicKeyBytes = new Uint8Array(publicKeyRaw);
                const publicKeyFingerprint = this.getKeyFingerprint(publicKeyBytes);
                
                this.logToTerminal('Ephemeral key pair generated', 'success');
                this.logToTerminal(`Public key fingerprint: ${publicKeyFingerprint}`, 'key');
                
                // Animate the key generation
                animateStep('step-1');
                
                // Update UI state
                document.getElementById('establish-session-btn').disabled = false;
                
                return {
                    keyPair,
                    publicKeyRaw
                };
            } catch (error) {
                this.logToTerminal(`Error generating keys: ${error.message}`, 'error');
                throw error;
            }
        },
        
        // Establish a secure session with the server
        establishSecureSession: async function() {
            this.logToTerminal('Establishing secure session...', 'command');
            
            try {
                // If we don't have keys yet, generate them
                if (!this.ephemeralKeyPair) {
                    await this.generateEphemeralKeys();
                }
                
                // Export public key to send to server
                const clientPublicKey = await window.crypto.subtle.exportKey(
                    "spki",
                    this.ephemeralKeyPair.publicKey
                );
                
                // Send public key to server (simulated in pfs-server.js)
                this.logToTerminal('Sending public key to server', 'info');
                
                // For this demo, we'll use the simulated server in the same browser
                const serverResponse = await pfsServer.initiateSession(clientPublicKey);
                
                if (!serverResponse.success) {
                    throw new Error(serverResponse.error || "Failed to establish secure session");
                }
                
                this.logToTerminal('Received server public key', 'info');
                
                // Import server's public key
                const serverPublicKey = await window.crypto.subtle.importKey(
                    "spki",
                    serverResponse.server_public_key,
                    {
                        name: "ECDH",
                        namedCurve: "P-256"
                    },
                    false,
                    []
                );
                
                // Generate a fingerprint of the server key for display
                const serverKeyFingerprint = this.getKeyFingerprint(serverResponse.server_public_key);
                this.logToTerminal(`Server public key fingerprint: ${serverKeyFingerprint}`, 'key');
                
                // Derive shared secret using ECDH
                this.logToTerminal('Deriving shared secret...', 'info');
                
                this.sharedSecret = await window.crypto.subtle.deriveBits(
                    {
                        name: "ECDH",
                        public: serverPublicKey
                    },
                    this.ephemeralKeyPair.privateKey,
                    256 // 256 bits
                );
                
                // Generate a fingerprint of the shared secret for display
                const sharedSecretFingerprint = this.getKeyFingerprint(this.sharedSecret);
                this.logToTerminal(`Shared secret established: ${sharedSecretFingerprint}`, 'success');
                
                // Animate the shared secret derivation
                animateStep('step-2');
                
                // Derive session key from shared secret using HKDF with SHA-384
                this.logToTerminal('Deriving session key with HKDF...', 'info');
                
                this.sessionKey = await window.crypto.subtle.deriveKey(
                    {
                        name: "HKDF",
                        hash: "SHA-384",
                        salt: serverResponse.salt,
                        info: new TextEncoder().encode("TLS_AES_256_GCM_SHA384")
                    },
                    this.sharedSecret,
                    {
                        name: "AES-GCM",
                        length: 256
                    },
                    false,
                    ["encrypt", "decrypt"]
                );
                
                this.logToTerminal('Session key derived', 'success');
                this.sessionId = serverResponse.session_id;
                
                // Update connection status
                this.isConnected = true;
                
                // Update UI state
                document.getElementById('client-status').innerHTML = '<span class="status-indicator status-success"></span> Connected';
                document.getElementById('message-input').disabled = false;
                document.getElementById('send-message-btn').disabled = false;
                
                return {
                    sessionId: this.sessionId,
                    established: true
                };
            } catch (error) {
                this.logToTerminal(`Error establishing session: ${error.message}`, 'error');
                throw error;
            }
        },
        
        // Encrypt a message using the session key
        encryptMessage: async function(message) {
            if (!this.sessionKey) {
                throw new Error("Secure session not established");
            }
            
            try {
                // Generate random IV (must be 12 bytes for GCM)
                const iv = window.crypto.getRandomValues(new Uint8Array(12));
                
                // Log what we're doing
                this.logToTerminal(`Encrypting message: "${message}"`, 'info');
                
                // Encrypt the message
                const encrypted = await window.crypto.subtle.encrypt(
                    {
                        name: "AES-GCM",
                        iv: iv,
                        tagLength: 128 // authentication tag length
                    },
                    this.sessionKey,
                    new TextEncoder().encode(message)
                );
                
                // Create a fingerprint of the ciphertext for display
                const ciphertextFingerprint = this.getKeyFingerprint(encrypted);
                this.logToTerminal(`Ciphertext: ${ciphertextFingerprint}`, 'value');
                
                // Animate the encryption process
                document.getElementById('animated-message').textContent = message;
                animateStep('step-3');
                
                return {
                    iv,
                    ciphertext: encrypted
                };
            } catch (error) {
                this.logToTerminal(`Encryption error: ${error.message}`, 'error');
                throw error;
            }
        },
        
        // Decrypt a message using the session key
        decryptMessage: async function(encryptedMsg) {
            if (!this.sessionKey) {
                throw new Error("Secure session not established");
            }
            
            try {
                const decrypted = await window.crypto.subtle.decrypt(
                    {
                        name: "AES-GCM",
                        iv: encryptedMsg.iv,
                        tagLength: 128
                    },
                    this.sessionKey,
                    encryptedMsg.ciphertext
                );
                
                const plaintext = new TextDecoder().decode(decrypted);
                this.logToTerminal(`Decrypted message: "${plaintext}"`, 'string');
                
                // Animate the decryption process
                animateStep('step-4');
                
                return plaintext;
            } catch (error) {
                this.logToTerminal(`Message authentication failed: ${error.message}`, 'error');
                throw new Error("Message authentication failed");
            }
        },
        
        // Send encrypted message to server
        sendSecureMessage: async function(message) {
            if (!this.isConnected) {
                await this.establishSecureSession();
            }
            
            try {
                this.logToTerminal(`Sending secure message to server`, 'command');
                
                // Encrypt the message
                const encrypted = await this.encryptMessage(message);
                
                // Send to server (simulated)
                const serverResponse = await pfsServer.receiveSecureMessage(
                    this.sessionId, 
                    {
                        iv: encrypted.iv,
                        ciphertext: encrypted.ciphertext
                    }
                );
                
                if (serverResponse.encrypted_response) {
                    // Decrypt the server's response
                    const decryptedResponse = await this.decryptMessage(serverResponse.encrypted_response);
                    return decryptedResponse;
                }
                
                return "No encrypted response from server";
            } catch (error) {
                this.logToTerminal(`Error sending message: ${error.message}`, 'error');
                throw error;
            }
        },
        
        // Helper to generate a short fingerprint of a key or buffer
        getKeyFingerprint: function(buffer) {
            // If buffer is an ArrayBuffer, convert to Uint8Array
            const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
            
            // Take first and last 4 bytes for the fingerprint
            const start = Array.from(bytes.slice(0, 4))
                .map(b => b.toString(16).padStart(2, '0'))
                .join('');
                
            const end = Array.from(bytes.slice(-4))
                .map(b => b.toString(16).padStart(2, '0'))
                .join('');
                
            return `${start}...${end}`;
        }
    };

    // Initialize PFS client when DOM is ready
    function initPFSClient() {
        const terminalElement = document.getElementById('client-terminal');
        if (terminalElement) {
            window.pfsClient.init(terminalElement);
        }
    }

    // Add DOM ready event listener
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPFSClient);
    } else {
        initPFSClient();
    }
})(window);