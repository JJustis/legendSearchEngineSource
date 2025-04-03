// Simplified E2EE for Legend Search (Query Encryption Only)
class SearchEncryption {
    constructor() {
        // State
        this.encryptButton = null;
        this.secretKey = null;
        this.sessionId = null;
        this.isActive = false;
        
        // Bind methods
        this.initialize = this.initialize.bind(this);
        this.createEncryptButton = this.createEncryptButton.bind(this);
        this.toggleEncryption = this.toggleEncryption.bind(this);
        this.setupEncryption = this.setupEncryption.bind(this);
        this.hookSearchForm = this.hookSearchForm.bind(this);
    }
    
    async initialize() {
        console.log('[SearchEncrypt] Initializing...');
        
        // Only show encryption button on HTTPS
        if (window.location.protocol !== 'https:') {
            console.log('[SearchEncrypt] HTTPS required - not initializing');
            return;
        }
        
        // Create encryption button
        this.createEncryptButton();
        
        // Check if encryption was previously enabled in this session
        const encryptState = sessionStorage.getItem('search_encrypt_state');
        if (encryptState === 'active') {
            console.log('[SearchEncrypt] Restoring previous encryption session');
            this.secretKey = sessionStorage.getItem('search_encrypt_key');
            this.sessionId = sessionStorage.getItem('search_encrypt_session');
            
            if (this.secretKey && this.sessionId) {
                this.isActive = true;
                this.updateButtonState(true);
            }
        }
        
        // Modify search form to use encryption
        this.hookSearchForm();
        
        console.log('[SearchEncrypt] Initialization complete');
    }
    
    createEncryptButton() {
        console.log('[SearchEncrypt] Creating button');
        
        // Create button
        this.encryptButton = document.createElement('button');
        this.encryptButton.id = 'encrypt-toggle';
        this.encryptButton.className = 'pfs-button'; // Reuse PFS button styling
        this.encryptButton.innerHTML = `
            <span class="pfs-icon">🔒</span>
            <span class="pfs-text">Enable E2EE</span>
        `;
        
        // Find container for the button
        const searchContainer = document.querySelector('.g-search-container') || 
                                document.querySelector('.search-container');
        if (searchContainer) {
            searchContainer.appendChild(this.encryptButton);
            console.log('[SearchEncrypt] Button added to search container');
        } else {
            console.warn('[SearchEncrypt] Could not find search container, adding button to body');
            document.body.appendChild(this.encryptButton);
        }
        
        // Add click event listener
        this.encryptButton.addEventListener('click', this.toggleEncryption);
    }
    
    async toggleEncryption() {
        if (!this.isActive) {
            await this.setupEncryption();
        } else {
            this.disableEncryption();
        }
    }
    
    async setupEncryption() {
        try {
            console.log('[SearchEncrypt] Setting up encryption');
            
            // Start loading animation
            this.encryptButton.classList.add('loading');
            
            // Generate a strong random key
            const keyBytes = crypto.getRandomValues(new Uint8Array(32));
            this.secretKey = this.arrayBufferToHex(keyBytes);
            
            // Send to server
            const response = await fetch('search_encrypt_setup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'setup_encryption',
                    secretKey: this.secretKey
                })
            });
            
            const result = await response.json();
            console.log('[SearchEncrypt] Setup response:', result);
            
            if (!result.success) {
                throw new Error(result.error || 'Setup failed');
            }
            
            // Store session ID
            this.sessionId = result.sessionId;
            
            // Update state
            this.isActive = true;
            this.updateButtonState(true);
            
            // Store in session storage
            sessionStorage.setItem('search_encrypt_state', 'active');
            sessionStorage.setItem('search_encrypt_key', this.secretKey);
            sessionStorage.setItem('search_encrypt_session', this.sessionId);
            
            console.log('[SearchEncrypt] Encryption setup complete');
            return true;
            
        } catch (error) {
            console.error('[SearchEncrypt] Setup Error:', error);
            this.disableEncryption();
            alert('Failed to enable encryption: ' + error.message);
            return false;
        }
    }
    
    updateButtonState(enabled) {
        this.encryptButton.classList.remove('loading');
        
        if (enabled) {
            this.encryptButton.classList.add('pfs-active');
            this.encryptButton.querySelector('.pfs-text').textContent = 'E2EE Active';
            this.encryptButton.querySelector('.pfs-icon').textContent = '🔓';
        } else {
            this.encryptButton.classList.remove('pfs-active');
            this.encryptButton.querySelector('.pfs-text').textContent = 'Enable E2EE';
            this.encryptButton.querySelector('.pfs-icon').textContent = '🔒';
        }
    }
    
    disableEncryption() {
        console.log('[SearchEncrypt] Disabling encryption');
        
        // Reset state variables
        this.secretKey = null;
        this.sessionId = null;
        this.isActive = false;
        
        // Update button state
        this.updateButtonState(false);
        
        // Clear session storage
        sessionStorage.removeItem('search_encrypt_state');
        sessionStorage.removeItem('search_encrypt_key');
        sessionStorage.removeItem('search_encrypt_session');
    }
    
    // Encrypt data for transmission
    async encryptData(data) {
        if (!this.isActive || !this.secretKey) {
            throw new Error('Encryption not initialized');
        }
        
        try {
            // Generate IV
            const iv = crypto.getRandomValues(new Uint8Array(16));
            
            // Import key
            const key = await crypto.subtle.importKey(
                'raw',
                this.hexToArrayBuffer(this.secretKey),
                { name: 'AES-CBC', length: 256 },
                false,
                ['encrypt']
            );
            
            // Encode data
            const encodedData = new TextEncoder().encode(data);
            
            // Encrypt
            const encryptedBuffer = await crypto.subtle.encrypt(
                {
                    name: 'AES-CBC',
                    iv: iv
                },
                key,
                encodedData
            );
            
            // Return as hex encoded strings
            return {
                iv: this.arrayBufferToHex(iv),
                ciphertext: this.arrayBufferToHex(encryptedBuffer)
            };
            
        } catch (error) {
            console.error('[SearchEncrypt] Encryption Error:', error);
            throw error;
        }
    }
    
    // Hook search form to use encryption
    hookSearchForm() {
        const searchInput = document.getElementById('search-input');
        const searchButton = document.getElementById('search-button');
        
        if (!searchInput || !searchButton) {
            console.warn('[SearchEncrypt] Search input or button not found');
            return;
        }
        
        console.log('[SearchEncrypt] Hooking search form');
        
        // Store original event handlers
        const originalButtonClick = searchButton.onclick;
        
        // Intercept form submission
        const onSearch = async (e) => {
            e.preventDefault();
            e.stopPropagation();
            
            const query = searchInput.value.trim();
            if (!query) return;
            
            if (this.isActive) {
                try {
                    console.log('[SearchEncrypt] Encrypting search query:', query);
                    
                    // Check if query is a URL
                    const isUrl = this.isUrlPattern(query);
                    
                    // Encrypt query
                    const encryptedQuery = await this.encryptData(query);
                    
                    // Submit encrypted query (with URL flag if needed)
                    window.location.href = `?page=search&encrypt=active&sid=${encodeURIComponent(this.sessionId)}&q=${encodeURIComponent(JSON.stringify(encryptedQuery))}&url=${isUrl ? '1' : '0'}`;
                } catch (error) {
                    console.error('[SearchEncrypt] Search Encryption Error:', error);
                    
                    // Fall back to unencrypted search
                    window.location.href = `?page=search&q=${encodeURIComponent(query)}`;
                }
            } else {
                // Normal search
                if (originalButtonClick) {
                    // Execute original handler
                    originalButtonClick.call(searchButton, e);
                } else {
                    // Default behavior
                    window.location.href = `?page=search&q=${encodeURIComponent(query)}`;
                }
            }
        };
        
        // Hook events
        searchButton.onclick = onSearch;
        
        // Hook Enter key
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                onSearch(e);
            }
        });
        
        console.log('[SearchEncrypt] Search form hooked');
    }
    
    // Check if the query looks like a URL
    isUrlPattern(query) {
        // Simple URL pattern detection
        return /^(https?:\/\/)?([a-zA-Z0-9][-a-zA-Z0-9]*\.)+[a-zA-Z]{2,}(\/[-a-zA-Z0-9@:%_\+.~#?&\/=]*)?$/i.test(query);
    }
    
    // Utility: Convert ArrayBuffer to hex string
    arrayBufferToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }
    
    // Utility: Convert hex string to ArrayBuffer
    hexToArrayBuffer(hex) {
        const bytes = new Uint8Array(Math.ceil(hex.length / 2));
        for (let i = 0; i < bytes.length; i++) {
            bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
        }
        return bytes.buffer;
    }
}

// Initialize encryption when DOM is loaded
document.addEventListener('DOMContentLoaded', async () => {
    console.log('[SearchEncrypt] DOM loaded, initializing encryption system');
    
    // Create and initialize encryption system
    try {
        window.searchEncryption = new SearchEncryption();
        await window.searchEncryption.initialize();
    } catch (error) {
        console.error('[SearchEncrypt] Initialization error:', error);
    }
});