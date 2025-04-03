// Perfect Forward Secrecy Implementation for Search
class PFSManager {
    constructor() {
        this.pfsButton = null;
        this.keyPair = null;
        this.serverPublicKey = null;
        this.sharedSecret = null;
        this.sessionId = null;
        this.isActive = false;
    }

    async initialize() {
        // Create PFS button
        this.initializePFSButton();
        
        // Check if PFS was previously enabled in this session
        const pfsState = sessionStorage.getItem('pfs_state');
        if (pfsState === 'active') {
            await this.initiatePFS();
        }
        
        // Modify search form to use PFS
        this.hookSearchForm();
    }

    initializePFSButton() {
        // Create PFS button
        this.pfsButton = document.createElement('button');
        this.pfsButton.id = 'pfs-toggle';
        this.pfsButton.className = 'pfs-button';
        this.pfsButton.innerHTML = `
            <span class="pfs-icon">🔒</span>
            <span class="pfs-text">Enable PFS</span>
        `;
        
        // Position the button near the search input
        const searchContainer = document.querySelector('.g-search-container');
        if (searchContainer) {
            searchContainer.appendChild(this.pfsButton);
        }

        // Add click event listener
        this.pfsButton.addEventListener('click', () => this.togglePFS());
    }

    async togglePFS() {
        if (!this.isActive) {
            await this.initiatePFS();
        } else {
            this.disablePFS();
        }
    }

    async initiatePFS() {
        try {
            // Start loading animation
            this.pfsButton.classList.add('loading');
            
            // Generate ECDH key pair
            this.keyPair = await window.crypto.subtle.generateKey(
                { name: 'ECDH', namedCurve: 'P-256' },
                true,
                ['deriveKey', 'deriveBits']
            );
            
            // Export public key
            const publicKeyBuffer = await window.crypto.subtle.exportKey(
                'spki', 
                this.keyPair.publicKey
            );
            
            // Convert to base64 for transmission
            const publicKeyBase64 = this.arrayBufferToBase64(publicKeyBuffer);
            
            // Send to server
            const response = await fetch('pfs_handshake.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'initiate_handshake',
                    clientPublicKey: publicKeyBase64
                })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Handshake failed');
            }
            
            // Store server public key and session ID
            this.serverPublicKey = result.serverPublicKey;
            this.sessionId = result.sessionId;
            
            // Derive shared secret
            const serverPublicKeyBuffer = this.base64ToArrayBuffer(this.serverPublicKey);
            const importedServerKey = await window.crypto.subtle.importKey(
                'spki',
                serverPublicKeyBuffer,
                { name: 'ECDH', namedCurve: 'P-256' },
                false,
                []
            );
            
            const sharedBits = await window.crypto.subtle.deriveBits(
                { name: 'ECDH', public: importedServerKey },
                this.keyPair.privateKey,
                256
            );
            
            // Use the derived bits as key material
            const sharedKeyMaterial = await window.crypto.subtle.importKey(
                'raw',
                sharedBits,
                { name: 'HKDF' },
                false,
                ['deriveKey']
            );
            
            // Derive actual encryption key
            this.encryptionKey = await window.crypto.subtle.deriveKey(
                {
                    name: 'HKDF',
                    info: new TextEncoder().encode('PFS Search Encryption'),
                    salt: new Uint8Array(16),
                    hash: 'SHA-256'
                },
                sharedKeyMaterial,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt']
            );
            
            // Update button state
            this.isActive = true;
            this.updatePFSButtonState(true);
            
            // Store PFS state
            sessionStorage.setItem('pfs_state', 'active');
            sessionStorage.setItem('pfs_session_id', this.sessionId);
            
            return true;
        } catch (error) {
            console.error('PFS Initialization Error:', error);
            this.disablePFS();
            alert('Failed to enable PFS encryption: ' + error.message);
            return false;
        }
    }

    async encryptData(data) {
        if (!this.isActive || !this.encryptionKey) {
            throw new Error('PFS not initialized');
        }

        // Generate IV
        const iv = window.crypto.getRandomValues(new Uint8Array(12));
        
        // Encode data
        const encodedData = new TextEncoder().encode(
            typeof data === 'string' ? data : JSON.stringify(data)
        );
        
        // Encrypt
        const encryptedBuffer = await window.crypto.subtle.encrypt(
            {
                name: 'AES-GCM',
                iv: iv
            },
            this.encryptionKey,
            encodedData
        );
        
        // Return as Base64 encoded strings
        return {
            iv: this.arrayBufferToBase64(iv),
            data: this.arrayBufferToBase64(encryptedBuffer)
        };
    }
    
    async decryptData(encryptedData) {
        if (!this.isActive || !this.encryptionKey) {
            throw new Error('PFS not initialized');
        }
        
        // Convert from Base64
        const iv = this.base64ToArrayBuffer(encryptedData.iv);
        const data = this.base64ToArrayBuffer(encryptedData.data);
        
        // Decrypt
        const decryptedBuffer = await window.crypto.subtle.decrypt(
            {
                name: 'AES-GCM',
                iv: iv
            },
            this.encryptionKey,
            data
        );
        
        // Decode result
        const decoded = new TextDecoder().decode(decryptedBuffer);
        
        // Try to parse as JSON if possible
        try {
            return JSON.parse(decoded);
        } catch (e) {
            return decoded;
        }
    }

    updatePFSButtonState(enabled) {
        this.pfsButton.classList.remove('loading');
        
        if (enabled) {
            this.pfsButton.classList.add('pfs-active');
            this.pfsButton.querySelector('.pfs-text').textContent = 'PFS Active';
            this.pfsButton.querySelector('.pfs-icon').textContent = '🔓';
        } else {
            this.pfsButton.classList.remove('pfs-active');
            this.pfsButton.querySelector('.pfs-text').textContent = 'Enable PFS';
            this.pfsButton.querySelector('.pfs-icon').textContent = '🔒';
        }
    }

    disablePFS() {
        this.keyPair = null;
        this.serverPublicKey = null;
        this.sharedSecret = null;
        this.encryptionKey = null;
        this.sessionId = null;
        this.isActive = false;
        
        this.updatePFSButtonState(false);
        
        // Clear PFS state
        sessionStorage.removeItem('pfs_state');
        sessionStorage.removeItem('pfs_session_id');
    }

    hookSearchForm() {
        const searchInput = document.getElementById('search-input');
        const searchButton = document.getElementById('search-button');
        
        if (!searchInput || !searchButton) return;
        
        // Intercept form submission
        const onSearch = async (e) => {
            e.preventDefault();
            
            const query = searchInput.value.trim();
            if (!query) return;
            
            if (this.isActive) {
                try {
                    // Encrypt query
                    const encryptedQuery = await this.encryptData(query);
                    
                    // Submit encrypted query
                    window.location.href = `?page=search&pfs=active&sid=${encodeURIComponent(this.sessionId)}&q=${encodeURIComponent(JSON.stringify(encryptedQuery))}`;
                } catch (error) {
                    console.error('PFS Search Error:', error);
                    // Fall back to unencrypted search
                    window.location.href = `?page=search&q=${encodeURIComponent(query)}`;
                }
            } else {
                // Normal search
                window.location.href = `?page=search&q=${encodeURIComponent(query)}`;
            }
        };
        
        // Hook events
        searchButton.addEventListener('click', onSearch);
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') onSearch(e);
        });
        
        // Initialize decryption of search results if needed
        this.decryptSearchResults();
    }
    
    async decryptSearchResults() {
        // Check if we have encrypted results
        const resultContainers = document.querySelectorAll('.encrypted-result');
        if (resultContainers.length === 0) return;
        
        // Ensure PFS is activated
        if (!this.isActive) {
            const success = await this.initiatePFS();
            if (!success) return;
        }
        
        // Decrypt each result
        for (const container of resultContainers) {
            try {
                const encryptedData = JSON.parse(container.getAttribute('data-encrypted'));
                const decryptedData = await this.decryptData(encryptedData);
                
                // Replace content
                container.innerHTML = decryptedData.html;
                container.classList.remove('encrypted-result');
                container.classList.add('decrypted-result');
            } catch (error) {
                console.error('Result Decryption Error:', error);
                container.innerHTML = '<div class="error-message">Failed to decrypt this result.</div>';
            }
        }
    }

    // Utility methods for base64 conversion
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    base64ToArrayBuffer(base64) {
        const binaryString = atob(base64);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }
}

// Initialize PFS when page loads
document.addEventListener('DOMContentLoaded', async () => {
    const pfsManager = new PFSManager();
    await pfsManager.initialize();
});