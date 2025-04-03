/**
 * pfs-integration.js
 * Handles integration of PFS with search results display
 */

// When the document is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize PFS if it exists
    if (typeof PFS !== 'undefined') {
        PFS.init();
        
        // Check if we're on a search results page with encrypted results
        checkAndDisplayEncryptedResults();
    } else {
        // Load PFS scripts
        loadPFSScript('pfs-client.js')
            .then(() => {
                if (typeof PFS !== 'undefined') {
                    PFS.init();
                    checkAndDisplayEncryptedResults();
                }
            })
            .catch(error => {
                console.error('Error loading PFS scripts:', error);
            });
    }
    
    // Add PFS activation status indicator to the page
    addPFSStatusIndicator();
});

/**
 * Load a script asynchronously
 * 
 * @param {string} src Script URL
 * @returns {Promise} Promise that resolves when script is loaded
 */
function loadPFSScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

/**
 * Check if we have encrypted search results and prepare for decryption
 */
function checkAndDisplayEncryptedResults() {
    // Check if we're on a search results page with PFS enabled
    const urlParams = new URLSearchParams(window.location.search);
    const hasPFS = urlParams.get('pfs') === 'true';
    
    if (!hasPFS) return;
    
    // Make sure PFS is initialized
    if (typeof PFS === 'undefined') return;
    
    // Add PFS info card at the top of search results
    addPFSInfoCard();
    
    // Get encrypted query from URL
    const encryptedQuery = urlParams.get('eq');
    if (!encryptedQuery) return;
    
    // Get client public key (might be stored in localStorage)
    const clientPublic = localStorage.getItem('pfs_client_public');
    if (!clientPublic) return;
    
    // Check if we need to fetch results or if they're already in the page
    const hasEncryptedResults = document.querySelector('[data-encrypted-results]');
    
    if (hasEncryptedResults) {
        // Results already in page, prepare for decryption
        prepareResultsForDecryption();
    } else {
        // Fetch results
        fetchEncryptedResults(encryptedQuery, clientPublic);
    }
}

/**
 * Add PFS information card to search results
 */
function addPFSInfoCard() {
    const resultsContainer = document.querySelector('.g-search-results');
    if (!resultsContainer) return;
    
    const infoCard = document.createElement('div');
    infoCard.className = 'pfs-info-card';
    infoCard.innerHTML = `
        <div class="pfs-info-title">
            <i class="fas fa-shield-alt"></i>
            Perfect Forward Secrecy Enabled
        </div>
        <div class="pfs-info-text">
            Your search is encrypted using PFS. Search results are protected and can only be 
            decrypted by your browser. Your search terms and results are not stored or tracked.
        </div>
    `;
    
    // Insert at the top
    resultsContainer.parentNode.insertBefore(infoCard, resultsContainer);
}

/**
 * Add PFS status indicator to the page
 */
function addPFSStatusIndicator() {
    // Check if PFS is enabled
    const isPFSEnabled = localStorage.getItem('pfsenabled') === 'true';
    
    // Create status indicator
    const statusIndicator = document.createElement('div');
    statusIndicator.className = `pfs-status ${isPFSEnabled ? 'active' : ''}`;
    statusIndicator.innerHTML = `
        <span class="pfs-status-icon"></span>
        <span class="pfs-status-text">${isPFSEnabled ? 'PFS Active' : 'PFS Inactive'}</span>
    `;
    
    // Add click handler to toggle PFS settings
    statusIndicator.addEventListener('click', function() {
        // Show PFS modal
        showPFSModal();
    });
    
    // Add to the page
    document.body.appendChild(statusIndicator);
}

/**
 * Show PFS settings modal
 */
function showPFSModal() {
    // Check if modal already exists
    let modal = document.querySelector('.pfs-modal-overlay');
    
    if (!modal) {
        // Create modal
        modal = document.createElement('div');
        modal.className = 'pfs-modal-overlay';
        modal.innerHTML = `
            <div class="pfs-modal">
                <div class="pfs-modal-header">
                    <div class="pfs-modal-title">
                        <i class="fas fa-shield-alt"></i>
                        Perfect Forward Secrecy Settings
                    </div>
                    <button class="pfs-modal-close">&times;</button>
                </div>
                <div class="pfs-modal-content">
                    <p>
                        Perfect Forward Secrecy (PFS) encrypts your search queries and results, 
                        providing enhanced privacy and security when using Legend search engine.
                    </p>
                    
                    <div class="pfs-status-section">
                        <h3>Status</h3>
                        <div class="pfs-status-info">
                            <div class="pfs-status-row">
                                <span>PFS Enabled:</span>
                                <span class="pfs-enabled-status">Checking...</span>
                            </div>
                            <div class="pfs-status-row">
                                <span>Secure Connection:</span>
                                <span class="pfs-connection-status">Checking...</span>
                            </div>
                            <div class="pfs-status-row">
                                <span>Key Exchange:</span>
                                <span class="pfs-keys-status">Checking...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="pfs-modal-footer">
                    <button class="pfs-toggle-button">Disable PFS</button>
                    <button class="pfs-generate-keys-button">Generate New Keys</button>
                    <button class="pfs-close-button">Close</button>
                </div>
            </div>
        `;
        
        // Add to the page
        document.body.appendChild(modal);
        
        // Add event listeners
        const closeBtn = modal.querySelector('.pfs-modal-close');
        const closeButton = modal.querySelector('.pfs-close-button');
        const toggleButton = modal.querySelector('.pfs-toggle-button');
        const generateKeysButton = modal.querySelector('.pfs-generate-keys-button');
        
        closeBtn.addEventListener('click', function() {
            modal.classList.remove('active');
        });
        
        closeButton.addEventListener('click', function() {
            modal.classList.remove('active');
        });
        
        toggleButton.addEventListener('click', function() {
            togglePFS();
            updatePFSModalStatus();
        });
        
        generateKeysButton.addEventListener('click', function() {
            // Generate new keys if PFS is available
            if (typeof PFS !== 'undefined') {
                PFS.generateKeys();
                updatePFSModalStatus();
            }
        });
        
        // Close when clicking outside
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    }
    
    // Show the modal
    modal.classList.add('active');
    
    // Update status
    updatePFSModalStatus();
}

/**
 * Update PFS modal status display
 */
function updatePFSModalStatus() {
    const modal = document.querySelector('.pfs-modal-overlay');
    if (!modal) return;
    
    const enabledStatus = modal.querySelector('.pfs-enabled-status');
    const connectionStatus = modal.querySelector('.pfs-connection-status');
    const keysStatus = modal.querySelector('.pfs-keys-status');
    const toggleButton = modal.querySelector('.pfs-toggle-button');
    
    // Check if PFS is enabled
    const isPFSEnabled = localStorage.getItem('pfsenabled') === 'true';
    
    if (enabledStatus) {
        enabledStatus.textContent = isPFSEnabled ? 'Enabled' : 'Disabled';
        enabledStatus.className = 'pfs-enabled-status ' + (isPFSEnabled ? 'active' : 'inactive');
    }
    
    // Update toggle button text
    if (toggleButton) {
        toggleButton.textContent = isPFSEnabled ? 'Disable PFS' : 'Enable PFS';
    }
    
    // Check connection status
    let isConnected = false;
    let hasKeys = false;
    
    if (typeof PFS !== 'undefined') {
        isConnected = PFS.status && PFS.status.connected;
        hasKeys = PFS.keys && PFS.keys.clientPrivate && PFS.keys.clientPublic;
        
        if (connectionStatus) {
            connectionStatus.textContent = isConnected ? 'Connected' : 'Not Connected';
            connectionStatus.className = 'pfs-connection-status ' + (isConnected ? 'active' : 'inactive');
        }
        
        if (keysStatus) {
            keysStatus.textContent = hasKeys ? 'Keys Generated' : 'No Keys';
            keysStatus.className = 'pfs-keys-status ' + (hasKeys ? 'active' : 'inactive');
        }
    } else {
        if (connectionStatus) {
            connectionStatus.textContent = 'PFS Not Loaded';
            connectionStatus.className = 'pfs-connection-status inactive';
        }
        
        if (keysStatus) {
            keysStatus.textContent = 'No Keys';
            keysStatus.className = 'pfs-keys-status inactive';
        }
    }
}

/**
 * Toggle PFS on/off
 */
function togglePFS() {
    // Check current status
    const isPFSEnabled = localStorage.getItem('pfsenabled') === 'true';
    
    // Toggle status
    localStorage.setItem('pfsenabled', isPFSEnabled ? 'false' : 'true');
    
    // Update cookie for server-side
    document.cookie = `pfs_enabled=${isPFSEnabled ? 'false' : 'true'}; path=/; max-age=` + (86400 * 30); // 30 days
    
    // Update UI
    const pfsButton = document.getElementById('pfsButton');
    const statusIndicator = document.querySelector('.pfs-status');
    
    if (pfsButton) {
        if (isPFSEnabled) {
            pfsButton.classList.remove('pfs-active');
        } else {
            pfsButton.classList.add('pfs-active');
        }
    }
    
    if (statusIndicator) {
        if (isPFSEnabled) {
            statusIndicator.classList.remove('active');
            statusIndicator.querySelector('.pfs-status-text').textContent = 'PFS Inactive';
        } else {
            statusIndicator.classList.add('active');
            statusIndicator.querySelector('.pfs-status-text').textContent = 'PFS Active';
        }
    }
    
    // Show notification
    if (typeof PFS !== 'undefined') {
        PFS.showNotification(isPFSEnabled ? 'PFS Disabled' : 'PFS Enabled');
    }
}

/**
 * Fetch encrypted search results
 * 
 * @param {string} encryptedQuery Encrypted query from URL
 * @param {string} clientPublic Client's public key
 */
function fetchEncryptedResults(encryptedQuery, clientPublic) {
    // Make sure PFS is initialized
    if (typeof PFS === 'undefined') return;
    
    // Fetch results from API
    fetch('secure_search.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'secure_search',
            encrypted_query: encryptedQuery,
            client_public: clientPublic
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.encrypted_results) {
            // Store the encrypted results in the page for decryption
            const resultsContainer = document.querySelector('.g-search-results');
            if (resultsContainer) {
                resultsContainer.setAttribute('data-encrypted-results', data.encrypted_results);
                
                // Decrypt and display results
                displayEncryptedResults(data.encrypted_results);
            }
        } else {
            console.error('Failed to fetch encrypted results:', data.message);
            
            // Show error message
            if (typeof PFS !== 'undefined') {
                PFS.showNotification('Failed to fetch secure search results', 'error');
            }
        }
    })
    .catch(error => {
        console.error('Error fetching encrypted results:', error);
        
        // Show error message
        if (typeof PFS !== 'undefined') {
            PFS.showNotification('Error fetching secure search results', 'error');
        }
    });
}

/**
 * Prepare existing search results for decryption
 */
function prepareResultsForDecryption() {
    // Make sure PFS is initialized
    if (typeof PFS === 'undefined') return;
    
    const resultsContainer = document.querySelector('.g-search-results');
    if (!resultsContainer) return;
    
    // Get encrypted results from the container
    const encryptedResults = resultsContainer.getAttribute('data-encrypted-results');
    if (!encryptedResults) return;
    
    // Add global decrypt button
    const globalDecryptBtn = document.createElement('button');
    globalDecryptBtn.className = 'global-decrypt-button';
    globalDecryptBtn.innerHTML = '<i class="fas fa-unlock-alt"></i> Decrypt All Results';
    
    // Add event listener
    globalDecryptBtn.addEventListener('click', function() {
        displayEncryptedResults(encryptedResults);
    });
    
    // Insert before results
    resultsContainer.parentNode.insertBefore(globalDecryptBtn, resultsContainer);
}

/**
 * Display encrypted search results
 * 
 * @param {string} encryptedResults Encrypted search results
 */
function displayEncryptedResults(encryptedResults) {
    // Make sure PFS is initialized
    if (typeof PFS === 'undefined') return;
    
    // Decrypt the results
    const decryptedResults = PFS.decryptMessage(encryptedResults);
    if (!decryptedResults) {
        // Show error message
        PFS.showNotification('Failed to decrypt search results', 'error');
        return;
    }
    
    try {
        // Parse the decrypted results
        const results = JSON.parse(decryptedResults);
        
        // Get the container
        const resultsContainer = document.querySelector('.g-search-results');
        if (!resultsContainer) return;
        
        // Clear existing results
        resultsContainer.innerHTML = '';
        
        // Check if we have results
        if (results.length === 0) {
            resultsContainer.innerHTML = '<p>No results found for your secure search.</p>';
            return;
        }
        
        // Add each result
        results.forEach(result => {
            addSearchResult(resultsContainer, result);
        });
        
        // Update stats
        const statsElement = document.querySelector('.g-search-stats');
        if (statsElement) {
            statsElement.innerHTML = `About ${results.length} encrypted results <span class="pfs-badge"><i class="fas fa-lock"></i> PFS</span>`;
        }
        
        // Remove global decrypt button if it exists
        const globalDecryptBtn = document.querySelector('.global-decrypt-button');
        if (globalDecryptBtn) {
            globalDecryptBtn.remove();
        }
        
        // Show success message
        PFS.showNotification('Search results decrypted successfully');
    } catch (error) {
        console.error('Error parsing decrypted results:', error);
        PFS.showNotification('Error parsing decrypted results', 'error');
    }
}

/**
 * Add a search result to the container
 * 
 * @param {HTMLElement} container Container element
 * @param {Object} result Search result data
 */
function addSearchResult(container, result) {
    const resultElement = document.createElement('li');
    resultElement.className = 'g-search-result decrypted-result';
    
    resultElement.innerHTML = `
        <div class="g-search-result-url">
            ${result.url}
        </div>
        <h3 class="g-search-result-title">
            <a href="${result.url}" target="_blank">${result.title}</a>
        </h3>
        <div class="g-search-result-snippet">
            ${result.description}
        </div>
        <div class="g-search-result-info">
            <span class="g-search-result-info-item">
                <i class="fas fa-shield-alt"></i> Securely Decrypted
            </span>
            ${result.source ? `
                <span class="g-search-result-info-item">
                    <i class="fas fa-database"></i> ${result.source}
                </span>
            ` : ''}
            ${result.frequency ? `
                <span class="g-search-result-info-item">
                    <i class="fas fa-chart-line"></i> Found ${result.frequency} times
                </span>
            ` : ''}
        </div>
    `;
    
    container.appendChild(resultElement);
}

/**
 * Modify the search form to use PFS when enabled
 * 
 * This function should be called when PFS is initialized
 */
function modifySearchForm() {
    const searchForm = document.querySelector('form');
    const searchInput = document.getElementById('search-input');
    const searchButton = document.getElementById('search-button');
    
    if (!searchForm || !searchInput || !searchButton) return;
    
    // Store original submit handler
    const originalSubmit = searchForm.onsubmit;
    
    // Replace with PFS-aware handler
    searchForm.onsubmit = function(e) {
        // Check if PFS is enabled
        const isPFSEnabled = localStorage.getItem('pfsenabled') === 'true';
        
        if (isPFSEnabled && typeof PFS !== 'undefined') {
            e.preventDefault();
            
            const query = searchInput.value.trim();
            if (!query) return false;
            
            // Show loading state
            searchButton.disabled = true;
            searchButton.classList.add('loading');
            
            // Perform secure search
            PFS.secureSearch(query)
                .then(result => {
                    // Redirect to search results page with encrypted query
                    window.location.href = `?page=search&pfs=true&eq=${encodeURIComponent(result.encrypted_query)}&cp=${encodeURIComponent(result.client_public)}`;
                })
                .catch(error => {
                    console.error('Secure search error:', error);
                    searchButton.disabled = false;
                    searchButton.classList.remove('loading');
                    
                    // Show error message
                    PFS.showNotification('Secure search failed: ' + error.message, 'error');
                });
            
            return false;
        } else if (originalSubmit) {
            // Use original handler
            return originalSubmit.call(this, e);
        }
        
        // Default behavior
        return true;
    };
    
    // Also handle search button click
    const originalClickHandler = searchButton.onclick;
    searchButton.onclick = function(e) {
        // Check if PFS is enabled
        const isPFSEnabled = localStorage.getItem('pfsenabled') === 'true';
        
        if (isPFSEnabled && typeof PFS !== 'undefined') {
            e.preventDefault();
            searchForm.onsubmit(e);
            return false;
        } else if (originalClickHandler) {
            // Use original handler
            return originalClickHandler.call(this, e);
        }
    };
    
    // Also handle enter key in search input
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            // The form's onsubmit handler will take care of it
        }
    });
}

// Initialize search form modification when PFS is loaded
if (typeof PFS !== 'undefined') {
    modifySearchForm();
} else {
    // Wait for PFS to load
    document.addEventListener('PFSLoaded', function() {
        modifySearchForm();
    });
}