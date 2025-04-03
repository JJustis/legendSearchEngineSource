/**
 * Video Analytics Client Library
 * 
 * Tracks user interactions with videos and sends data to the analytics API
 */

class VideoAnalytics {
    /**
     * Constructor
     * 
     * @param {string} apiEndpoint - The base URL for the analytics API
     * @param {Object} options - Configuration options
     */
    constructor(apiEndpoint, options = {}) {
        this.apiEndpoint = apiEndpoint.endsWith('/') ? apiEndpoint : apiEndpoint + '/';
        this.options = Object.assign({
            debug: false,
            autoTrackCompletion: true,
            trackingInterval: 10, // seconds
            sessionTimeout: 30 * 60 // 30 minutes
        }, options);
        
        this.activeVideos = {};
        this.sessionData = this._loadSessionData();
        
        // Periodically save session data
        setInterval(() => this._saveSessionData(), 60000);
        
        if (this.options.debug) {
            console.log('VideoAnalytics initialized with endpoint:', this.apiEndpoint);
        }
    }
    
    /**
     * Track a HTML5 video element
     * 
     * @param {HTMLVideoElement} videoElement - The video element to track
     * @param {string} videoId - Unique identifier for the video
     * @param {Object} metadata - Additional metadata about the video
     */
    trackVideo(videoElement, videoId, metadata = {}) {
        if (!videoElement || !(videoElement instanceof HTMLVideoElement)) {
            console.error('Invalid video element provided');
            return;
        }
        
        if (!videoId) {
            console.error('Video ID is required');
            return;
        }
        
        // Initialize tracking data for this video
        this.activeVideos[videoId] = {
            element: videoElement,
            id: videoId,
            metadata: metadata,
            startTime: Date.now(),
            lastUpdateTime: Date.now(),
            playTime: 0,
            pauseTime: 0,
            seekCount: 0,
            played: false,
            completed: false,
            trackingInterval: null,
            checkpoints: {
                start: false,
                quarter: false,
                half: false,
                threeQuarters: false,
                complete: false
            }
        };
        
        const videoData = this.activeVideos[videoId];
        
        // Set up event listeners
        videoElement.addEventListener('play', () => this._handlePlay(videoId));
        videoElement.addEventListener('pause', () => this._handlePause(videoId));
        videoElement.addEventListener('seeking', () => this._handleSeeking(videoId));
        videoElement.addEventListener('ended', () => this._handleEnded(videoId));
        
        if (this.options.debug) {
            console.log(`Tracking video: ${videoId}`);
        }
        
        // Send initial view event
        this._trackVideoView(videoId, 0, 0);
    }
    
    /**
     * Handle play event
     */
    _handlePlay(videoId) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        const video = videoData.element;
        
        // Mark as played
        videoData.played = true;
        
        // Update timestamps
        videoData.lastUpdateTime = Date.now();
        
        // Check for start checkpoint
        if (!videoData.checkpoints.start) {
            videoData.checkpoints.start = true;
            this._trackCheckpoint(videoId, 'start');
        }
        
        // Start tracking interval
        if (!videoData.trackingInterval) {
            videoData.trackingInterval = setInterval(() => {
                this._updateTracking(videoId);
            }, this.options.trackingInterval * 1000);
        }
        
        if (this.options.debug) {
            console.log(`Video ${videoId} played`);
        }
    }
    
    /**
     * Handle pause event
     */
    _handlePause(videoId) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        const video = videoData.element;
        const now = Date.now();
        
        // Update play time if the video was playing
        if (videoData.lastUpdateTime > 0) {
            videoData.playTime += (now - videoData.lastUpdateTime) / 1000;
        }
        
        // Reset update time
        videoData.lastUpdateTime = 0;
        videoData.pauseTime = now;
        
        // Clear interval
        if (videoData.trackingInterval) {
            clearInterval(videoData.trackingInterval);
            videoData.trackingInterval = null;
        }
        
        // Update tracking data
        this._updateTracking(videoId);
        
        if (this.options.debug) {
            console.log(`Video ${videoId} paused`);
        }
    }
    
    /**
     * Handle seeking event
     */
    _handleSeeking(videoId) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        videoData.seekCount++;
        
        if (this.options.debug) {
            console.log(`Video ${videoId} seek (${videoData.seekCount} total)`);
        }
    }
    
    /**
     * Handle ended event
     */
    _handleEnded(videoId) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        // Mark as completed
        videoData.completed = true;
        
        // Update tracking data
        this._updateTracking(videoId);
        
        // Check for complete checkpoint
        if (!videoData.checkpoints.complete) {
            videoData.checkpoints.complete = true;
            this._trackCheckpoint(videoId, 'complete');
        }
        
        // Track final view with completion
        this._trackVideoView(videoId, videoData.playTime, 100);
        
        // Clear interval
        if (videoData.trackingInterval) {
            clearInterval(videoData.trackingInterval);
            videoData.trackingInterval = null;
        }
        
        if (this.options.debug) {
            console.log(`Video ${videoId} ended`);
        }
    }
    
    /**
     * Update tracking data and check progress
     */
    _updateTracking(videoId) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        const video = videoData.element;
        const now = Date.now();
        
        // Update play time if the video is currently playing
        if (video.paused === false && videoData.lastUpdateTime > 0) {
            videoData.playTime += (now - videoData.lastUpdateTime) / 1000;
            videoData.lastUpdateTime = now;
        }
        
        if (videoData.played && !video.paused) {
            // Calculate progress percentage
            const duration = video.duration;
            const currentTime = video.currentTime;
            const progressPercentage = (currentTime / duration) * 100;
            
            // Check for quarter checkpoint
            if (progressPercentage >= 25 && !videoData.checkpoints.quarter) {
                videoData.checkpoints.quarter = true;
                this._trackCheckpoint(videoId, 'quarter');
            }
            
            // Check for half checkpoint
            if (progressPercentage >= 50 && !videoData.checkpoints.half) {
                videoData.checkpoints.half = true;
                this._trackCheckpoint(videoId, 'half');
            }
            
            // Check for three-quarters checkpoint
            if (progressPercentage >= 75 && !videoData.checkpoints.threeQuarters) {
                videoData.checkpoints.threeQuarters = true;
                this._trackCheckpoint(videoId, 'threeQuarters');
            }
            
            // Track current progress if autoTrackCompletion is enabled
            if (this.options.autoTrackCompletion && videoData.playTime >= 5) {
                this._trackVideoView(videoId, videoData.playTime, progressPercentage);
            }
        }
    }
    
    /**
     * Track a checkpoint event
     */
    _trackCheckpoint(videoId, checkpoint) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        const video = videoData.element;
        const progressPercentage = (video.currentTime / video.duration) * 100;
        
        // Update session data for this video
        if (!this.sessionData.videos[videoId]) {
            this.sessionData.videos[videoId] = {
                views: 0,
                playTime: 0,
                completion: 0,
                lastViewed: Date.now()
            };
        }
        
        this.sessionData.videos[videoId].checkpoints = this.sessionData.videos[videoId].checkpoints || {};
        this.sessionData.videos[videoId].checkpoints[checkpoint] = true;
        this._saveSessionData();
        
        if (this.options.debug) {
            console.log(`Video ${videoId} reached ${checkpoint} checkpoint (${Math.round(progressPercentage)}%)`);
        }
    }
    
    /**
     * Track a video view event
     */
    _trackVideoView(videoId, durationWatched, completionPercentage) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        // Update session data for this video
        if (!this.sessionData.videos[videoId]) {
            this.sessionData.videos[id]) {
            this.sessionData.videos[videoId] = {
                views: 0,
                playTime: 0,
                completion: 0,
                lastViewed: Date.now()
            };
        }
        
        this.sessionData.videos[videoId].views++;
        this.sessionData.videos[videoId].playTime += durationWatched;
        this.sessionData.videos[videoId].completion = Math.max(
            this.sessionData.videos[videoId].completion,
            completionPercentage
        );
        this.sessionData.videos[videoId].lastViewed = Date.now();
        this._saveSessionData();
        
        // Prepare data for API request
        const data = {
            video_id: videoId,
            duration_watched: Math.round(durationWatched),
            completion_percentage: Math.round(completionPercentage * 10) / 10, // Round to 1 decimal
            metadata: {
                ...videoData.metadata,
                user_data: this._getUserData(),
                player_data: {
                    seekCount: videoData.seekCount,
                    sessionViews: this.sessionData.videos[videoId].views,
                    userAgent: navigator.userAgent
                }
            }
        };
        
        // Send API request
        fetch(this.apiEndpoint + 'track_video_view', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            if (this.options.debug) {
                console.log(`Video view tracked successfully for ${videoId}`, result);
            }
        })
        .catch(error => {
            console.error('Error tracking video view:', error);
        });
    }
    
    /**
     * Get user data for analytics
     */
    _getUserData() {
        return {
            sessionId: this.sessionData.id,
            device: this._getDeviceType(),
            screenSize: `${window.innerWidth}x${window.innerHeight}`,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            language: navigator.language || navigator.userLanguage,
            referrer: document.referrer || 'direct'
        };
    }
    
    /**
     * Get the device type based on user agent and screen size
     */
    _getDeviceType() {
        const ua = navigator.userAgent;
        if (/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(ua)) {
            return 'tablet';
        }
        if (/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(ua)) {
            return 'mobile';
        }
        return 'desktop';
    }
    
    /**
     * Load session data from storage
     */
    _loadSessionData() {
        let sessionData;
        
        try {
            // Try to load existing session
            sessionData = JSON.parse(localStorage.getItem('va_session_data'));
            
            // Check if session is expired
            if (sessionData && sessionData.expires < Date.now()) {
                // Session expired, create new one
                sessionData = null;
            }
        } catch (e) {
            console.error('Error loading session data:', e);
            sessionData = null;
        }
        
        if (!sessionData) {
            // Create new session
            sessionData = {
                id: this._generateSessionId(),
                created: Date.now(),
                expires: Date.now() + (this.options.sessionTimeout * 1000),
                videos: {}
            };
            
            this._saveSessionData(sessionData);
        }
        
        return sessionData;
    }
    
    /**
     * Save session data to storage
     */
    _saveSessionData() {
        try {
            // Update expiration time
            this.sessionData.expires = Date.now() + (this.options.sessionTimeout * 1000);
            
            // Save to localStorage
            localStorage.setItem('va_session_data', JSON.stringify(this.sessionData));
        } catch (e) {
            console.error('Error saving session data:', e);
        }
    }
    
    /**
     * Generate a unique session ID
     */
    _generateSessionId() {
        return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    
    /**
     * Stop tracking a video
     */
    stopTracking(videoId) {
        const videoData = this.activeVideos[videoId];
        if (!videoData) return;
        
        // Final update
        this._updateTracking(videoId);
        
        // Clear interval
        if (videoData.trackingInterval) {
            clearInterval(videoData.trackingInterval);
        }
        
        // Remove event listeners
        const video = videoData.element;
        video.removeEventListener('play', () => this._handlePlay(videoId));
        video.removeEventListener('pause', () => this._handlePause(videoId));
        video.removeEventListener('seeking', () => this._handleSeeking(videoId));
        video.removeEventListener('ended', () => this._handleEnded(videoId));
        
        // Remove from active videos
        delete this.activeVideos[videoId];
        
        if (this.options.debug) {
            console.log(`Stopped tracking video: ${videoId}`);
        }
    }
}

/**
 * Example usage:
 * 
 * // Initialize the analytics client
 * const analytics = new VideoAnalytics('https://example.com/api/video-analytics/', {
 *     debug: true
 * });
 * 
 * // Track a video element
 * const videoElement = document.getElementById('my-video');
 * analytics.trackVideo(videoElement, 'unique-video-id', {
 *     title: 'My Awesome Video',
 *     duration: videoElement.duration,
 *     tags: ['tutorial', 'coding', 'javascript'],
 *     category: 'education'
 * });
 */

// Add HTML data attributes support for easy integration
document.addEventListener('DOMContentLoaded', function() {
    // Check if auto-tracking is enabled
    const autoTrackElements = document.querySelectorAll('[data-va-autotrack="true"]');
    
    if (autoTrackElements.length > 0) {
        // Get API endpoint
        const apiEndpoint = document.querySelector('[data-va-endpoint]')?.getAttribute('data-va-endpoint');
        
        if (!apiEndpoint) {
            console.error('Video Analytics: No API endpoint specified. Add data-va-endpoint attribute to your script tag.');
            return;
        }
        
        // Initialize analytics
        const debug = document.querySelector('[data-va-debug="true"]') !== null;
        const analytics = new VideoAnalytics(apiEndpoint, { debug });
        
        // Auto-track videos
        autoTrackElements.forEach(videoElement => {
            if (videoElement instanceof HTMLVideoElement) {
                const videoId = videoElement.getAttribute('data-va-id');
                
                if (!videoId) {
                    console.error('Video Analytics: Missing data-va-id attribute on video element', videoElement);
                    return;
                }
                
                // Get metadata from data attributes
                const metadata = {};
                
                // Parse all data-va-meta-* attributes
                for (const attr of videoElement.attributes) {
                    if (attr.name.startsWith('data-va-meta-')) {
                        const key = attr.name.replace('data-va-meta-', '');
                        metadata[key] = attr.value;
                    }
                }
                
                // Parse tags if specified
                if (videoElement.hasAttribute('data-va-meta-tags')) {
                    metadata.tags = videoElement.getAttribute('data-va-meta-tags')
                        .split(',')
                        .map(tag => tag.trim());
                }
                
                // Track the video
                analytics.trackVideo(videoElement, videoId, metadata);
            }
        });
    }
});