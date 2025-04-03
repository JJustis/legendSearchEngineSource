<?php
/**
 * Enhanced video functions to use JSON files for video data
 * Dynamically loads videos from JSON files in a specified directory
 */

// Directory where videos and their JSON files are stored
define('VIDEO_URL_DIR', 'https://jcmc.serveminecraft.net/videoplayer/uploads/');

/**
 * Search videos by scanning JSON files in the video directory
 * Results are sorted by relevance to the search query
 * 
 * @param string $query Search query
 * @return array Sorted array of video results
 */
function searchVideos($query) {
    // Log the search for debugging
    error_log("Searching for videos with query: " . $query);
    
    // Load all videos from JSON files
    $videos = loadVideosFromJsonFiles();
    
    // If no query, return all videos
    if (empty($query)) {
        error_log("Empty query, returning all " . count($videos) . " videos");
        return $videos;
    }
    
    // Filter and rank videos by query relevance (case-insensitive)
    $query = strtolower($query);
    $results = [];
    $scores = [];
    
    foreach ($videos as $index => $video) {
        $score = calculateRelevanceScore($video, $query);
        
        if ($score > 0) {
            error_log("Found matching video: " . $video['id'] . " with score: " . $score);
            $results[] = $video;
            $scores[] = $score;
        }
    }
    
    // Sort results by relevance score (higher score first)
    array_multisort($scores, SORT_DESC, $results);
    
    error_log("Found " . count($results) . " matching videos");
    return $results;
}

/**
 * Calculate relevance score for a video against a search query
 * 
 * @param array $video Video data
 * @param string $query Search query (lowercase)
 * @return int Relevance score
 */
function calculateRelevanceScore($video, $query) {
    $score = 0;
    
    // Check exact matches in ID (highest priority)
    if (strtolower($video['id']) === $query) {
        $score += 100;
    } elseif (stripos($video['id'], $query) !== false) {
        $score += 50;
    }
    
    // Check title matches
    if (isset($video['title'])) {
        if (strtolower($video['title']) === $query) {
            $score += 80;
        } elseif (stripos($video['title'], $query) !== false) {
            $score += 40;
        }
    }
    
    // Check description matches
    if (isset($video['description']) && stripos($video['description'], $query) !== false) {
        $score += 20;
        
        // Bonus points for each occurrence in description
        $score += substr_count(strtolower($video['description']), $query) * 2;
    }
    
    // Check tag matches
    if (isset($video['tags']) && is_array($video['tags'])) {
        foreach ($video['tags'] as $tag) {
            if (strtolower($tag) === $query) {
                $score += 30;
            } elseif (stripos($tag, $query) !== false) {
                $score += 15;
            }
        }
    }
    
    // Check for any additional metadata matches
    foreach ($video as $key => $value) {
        if (!in_array($key, ['id', 'title', 'description', 'tags']) && 
            is_string($value) && 
            stripos($value, $query) !== false) {
            $score += 10;
        }
    }
    
    return $score;
}

/**
 * Load all videos by scanning the uploads directory for video and JSON files
 * 
 * @return array Array of video data
 */
function loadVideosFromJsonFiles() {
    $videos = [];
    $dirContents = null;
    
    try {
        // Attempt to get directory contents from the videos URL
        $dirListUrl = VIDEO_URL_DIR;
        $context = stream_context_create([
            'http' => [
                'timeout' => 5, // 5 seconds timeout
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $dirHtml = @file_get_contents($dirListUrl, false, $context);
        if ($dirHtml === false) {
            error_log("Error accessing directory listing from URL: " . $dirListUrl);
            return $videos;
        }
        
        // Find all JSON files (.json extension)
        preg_match_all('/<a href="([^"]+\.json)"[^>]*>/', $dirHtml, $jsonMatches);
        $jsonFiles = isset($jsonMatches[1]) ? $jsonMatches[1] : [];
        
        error_log("Found " . count($jsonFiles) . " JSON files in uploads directory");
        
        foreach ($jsonFiles as $jsonFile) {
            try {
                // Extract base filename (without extension)
                $baseFilename = pathinfo($jsonFile, PATHINFO_FILENAME);
                
                // Check if corresponding video file exists
                $videoFilePattern = '/href="(' . preg_quote($baseFilename, '/') . '\.(mp4|webm|mov|avi|wmv|flv|mkv))"/i';
                if (!preg_match($videoFilePattern, $dirHtml, $videoMatch)) {
                    error_log("No matching video file found for JSON: " . $jsonFile);
                    continue;
                }
                
                $videoFile = $videoMatch[1];
                
                // Get JSON content
                $jsonContent = @file_get_contents(VIDEO_URL_DIR . $jsonFile, false, $context);
                if ($jsonContent === false) {
                    error_log("Error reading JSON file: " . $jsonFile);
                    continue;
                }
                
                $videoData = json_decode($jsonContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON parsing error for file " . $jsonFile . ": " . json_last_error_msg());
                    continue;
                }
                
                // Ensure the video data has essential fields
                $videoData['id'] = $videoData['id'] ?? $baseFilename;
                $videoData['url'] = $videoData['url'] ?? (VIDEO_URL_DIR . $videoFile);
                $videoData['title'] = $videoData['title'] ?? formatVideoTitle($baseFilename);
                
                // Add to videos array
                $videos[] = $videoData;
                
            } catch (Exception $e) {
                error_log("Error processing JSON file " . $jsonFile . ": " . $e->getMessage());
            }
        }
        
    } catch (Exception $e) {
        error_log("Exception while scanning video directory: " . $e->getMessage());
    }
    
    return $videos;
}

/**
 * Load all videos - uses JSON files in the uploads directory
 * 
 * @return array All videos
 */
function loadVideos() {
    return loadVideosFromJsonFiles();
}

/**
 * Format a video title from filename
 * 
 * @param string $filename The filename without extension
 * @return string Formatted title
 */
function formatVideoTitle($filename) {
    // Replace underscores and hyphens with spaces
    $title = str_replace(['_', '-'], ' ', $filename);
    
    // Capitalize words
    $title = ucwords($title);
    
    return $title;
}

/**
 * Format upload date to a more readable format
 * 
 * @param string $date The upload date in Y-m-d H:i:s format
 * @return string Formatted date
 */
function formatUploadDate($date) {
    if ($date == 'Unknown' || empty($date)) {
        return 'Unknown';
    }
    
    try {
        $datetime = new DateTime($date);
        $now = new DateTime();
        $diff = $now->diff($datetime);
        
        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        } elseif ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        } elseif ($diff->d > 6) {
            return floor($diff->d / 7) . ' week' . (floor($diff->d / 7) > 1 ? 's' : '') . ' ago';
        } elseif ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    } catch (Exception $e) {
        error_log("Date formatting error: " . $e->getMessage());
        return 'Unknown';
    }
}

/**
 * Generate HTML for video results
 * 
 * @param array $videos Array of video results
 * @param string $query Search query
 * @return string HTML of video results
 */
/**
 * Generate HTML for video results with compact preview and JSON data panel
 * 
 * @param array $videos Array of video results
 * @param string $query Search query
 * @return string HTML of video results
 */
function renderVideoResults($videos, $query = '') {
    $html = '';
    
    error_log("Rendering " . count($videos) . " video results");
    
    if (!empty($videos)) {
        $html .= '<div class="g-search-stats">';
        $html .= 'Found ' . count($videos) . ' videos' . (!empty($query) ? ' matching "' . htmlspecialchars($query) . '"' : '');
        $html .= '</div>';
        
        $html .= '<div class="video-results">';
        
        foreach ($videos as $video) {
            // Format upload date
            $formattedDate = isset($video['upload_date']) ? formatUploadDate($video['upload_date']) : 'Unknown';
            
            $html .= '<div class="video-item" data-video-id="' . htmlspecialchars($video['id']) . '">';
            
            // Create flexible container for preview and data panel
            $html .= '<div class="video-flex-container">';
            
            // Left side - Video preview bar
            $html .= '<div class="video-preview-bar">';
            $thumbnailUrl = isset($video['thumbnail']) && !empty($video['thumbnail']) ? $video['thumbnail'] : 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxOTIgMTA4IiBmaWxsPSJub25lIj48cmVjdCB3aWR0aD0iMTkyIiBoZWlnaHQ9IjEwOCIgZmlsbD0iI2VlZSIvPjxwYXRoIGQ9Ik03OCw1NCw5OCw2NnYtMjRMNzgsNTRtNDQsMTRINzBWNDBIOTJaIiBmaWxsPSIjY2NjIi8+PC9zdmc+';
            
            $html .= '<a href="' . htmlspecialchars($video['url']) . '" class="video-thumbnail-compact">';
            $html .= '<img src="' . htmlspecialchars($thumbnailUrl) . '" alt="' . htmlspecialchars(isset($video['title']) ? $video['title'] : $video['id']) . '" 
                      onerror="this.src=\'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxOTIgMTA4IiBmaWxsPSJub25lIj48cmVjdCB3aWR0aD0iMTkyIiBoZWlnaHQ9IjEwOCIgZmlsbD0iI2VlZSIvPjxwYXRoIGQ9Ik03OCw1NCw5OCw2NnYtMjRMNzgsNTRtNDQsMTRINzBWNDBIOTJaIiBmaWxsPSIjY2NjIi8+PC9zdmc+\'; this.alt=\'Video thumbnail unavailable\';">';
            $html .= '<div class="video-play-icon"></div>';
            $html .= '</a>';
            $html .= '</div>'; // End video-preview-bar
            
            // Right side - JSON data panel
            $html .= '<div class="video-data-panel">';
            $html .= '<div class="panel-header">';
            $html .= '<h3 class="video-title"><a href="' . htmlspecialchars($video['url']) . '">' . 
                    htmlspecialchars(isset($video['title']) ? $video['title'] : formatVideoTitle($video['id'])) . '</a></h3>';
            $html .= '</div>';
            
            $html .= '<div class="panel-content">';
            
            // Video ID
            $html .= '<div class="data-row">';
            $html .= '<span class="data-label">ID:</span>';
            $html .= '<span class="data-value">' . htmlspecialchars($video['id']) . '</span>';
            $html .= '</div>';
            
            // Upload date
            $html .= '<div class="data-row">';
            $html .= '<span class="data-label">Uploaded:</span>';
            $html .= '<span class="data-value">' . htmlspecialchars($formattedDate) . '</span>';
            $html .= '</div>';
            
            // Tags if available
            if (!empty($video['tags'])) {
                $html .= '<div class="data-row">';
                $html .= '<span class="data-label">Tags:</span>';
                $html .= '<span class="data-value">';
                $tagHTML = '';
                foreach (array_slice($video['tags'], 0, 5) as $tag) {
                    $tagHTML .= '<span class="tag">' . htmlspecialchars($tag) . '</span>';
                }
                if (count($video['tags']) > 5) {
                    $tagHTML .= '<span class="tag more-tag">+' . (count($video['tags']) - 5) . '</span>';
                }
                $html .= $tagHTML;
                $html .= '</span>';
                $html .= '</div>';
            }
            
            // Description (truncated) if available
            if (!empty($video['description'])) {
                $description = $video['description'];
                if (strlen($description) > 80) {
                    $description = substr($description, 0, 80) . '...';
                }
                $html .= '<div class="data-row description-row">';
                $html .= '<span class="data-label">Description:</span>';
                $html .= '<span class="data-value">' . htmlspecialchars($description) . '</span>';
                $html .= '</div>';
            }
            
            // File URL - truncated for display
            $html .= '<div class="data-row">';
            $html .= '<span class="data-label">File:</span>';
            $displayUrl = $video['url'];
            if (strlen($displayUrl) > 40) {
                $displayUrl = substr($displayUrl, 0, 20) . '...' . substr($displayUrl, -20);
            }
            $html .= '<span class="data-value file-url" title="' . htmlspecialchars($video['url']) . '">' . 
                     htmlspecialchars($displayUrl) . '</span>';
            $html .= '</div>';
            
            // JSON Data Viewer button
            $html .= '<div class="data-row action-row">';
            $html .= '<button class="view-json-btn" data-video-id="' . htmlspecialchars($video['id']) . '">View Full JSON</button>';
            $html .= '<a href="' . htmlspecialchars($video['url']) . '" class="play-video-btn">Play Video</a>';
            $html .= '</div>';
            
            $html .= '</div>'; // End panel-content
            $html .= '</div>'; // End video-data-panel
            
            $html .= '</div>'; // End video-flex-container
            
            // Hidden JSON data for viewer
            $html .= '<div class="json-data-viewer" id="json-' . htmlspecialchars($video['id']) . '" style="display: none;">';
            $html .= '<pre>' . htmlspecialchars(json_encode($video, JSON_PRETTY_PRINT)) . '</pre>';
            $html .= '</div>';
            
            $html .= '</div>'; // End video-item
        }
        
        $html .= '</div>'; // End video-results
        
        // Add CSS for the new layout
        $html .= '<style>
            .video-results {
                width: 100%;
                max-width: 1200px;
                margin: 0 auto;
            }
            .video-item {
                margin-bottom: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                overflow: hidden;
                background: #fff;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            .video-flex-container {
                display: flex;
                flex-direction: row;
                min-height: 140px;
            }
            .video-preview-bar {
                width: 220px;
                background: #f5f5f5;
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                border-right: 1px solid #e0e0e0;
            }
            .video-thumbnail-compact {
                height: 100%;
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
            }
            .video-thumbnail-compact img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }
            .video-play-icon {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 40px;
                height: 40px;
                background: rgba(0,0,0,0.7);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .video-play-icon:before {
                content: "";
                width: 0;
                height: 0;
                border-style: solid;
                border-width: 8px 0 8px 16px;
                border-color: transparent transparent transparent #fff;
                margin-left: 3px;
            }
            .video-data-panel {
                flex: 1;
                padding: 15px;
                display: flex;
                flex-direction: column;
            }
            .panel-header {
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
                margin-bottom: 10px;
            }
            .video-title {
                margin: 0;
                font-size: 1.2rem;
                font-weight: 600;
            }
            .video-title a {
                color: #1a73e8;
                text-decoration: none;
            }
            .video-title a:hover {
                text-decoration: underline;
            }
            .panel-content {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .data-row {
                display: flex;
                align-items: flex-start;
                font-size: 0.9rem;
                line-height: 1.2;
            }
            .data-label {
                width: 90px;
                color: #666;
                font-weight: 500;
                flex-shrink: 0;
            }
            .data-value {
                flex: 1;
            }
            .description-row {
                margin-top: 5px;
                margin-bottom: 5px;
            }
            .tag {
                display: inline-block;
                padding: 2px 8px;
                margin-right: 5px;
                margin-bottom: 5px;
                background: #f0f7ff;
                color: #1a73e8;
                border-radius: 12px;
                font-size: 0.8rem;
            }
            .more-tag {
                background: #eee;
                color: #666;
            }
            .file-url {
                font-family: monospace;
                font-size: 0.8rem;
                color: #666;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .action-row {
                margin-top: auto;
                padding-top: 10px;
                justify-content: flex-start;
                gap: 10px;
            }
            .view-json-btn, .play-video-btn {
                padding: 5px 10px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.8rem;
                text-decoration: none;
                display: inline-block;
            }
            .view-json-btn {
                background: #f1f3f4;
                color: #444;
            }
            .play-video-btn {
                background: #1a73e8;
                color: white;
            }
            .view-json-btn:hover {
                background: #e0e0e0;
            }
            .play-video-btn:hover {
                background: #1765cc;
            }
            .json-data-viewer {
                padding: 15px;
                background: #f9f9f9;
                border-top: 1px solid #e0e0e0;
                overflow-x: auto;
            }
            .json-data-viewer pre {
                margin: 0;
                white-space: pre-wrap;
                font-family: monospace;
                font-size: 0.85rem;
                line-height: 1.5;
            }
            
            /* Add some responsive styling */
            @media (max-width: 768px) {
                .video-flex-container {
                    flex-direction: column;
                }
                .video-preview-bar {
                    width: 100%;
                    height: 180px;
                    border-right: none;
                    border-bottom: 1px solid #e0e0e0;
                }
                .data-row {
                    flex-direction: column;
                }
                .data-label {
                    width: 100%;
                    margin-bottom: 2px;
                }
            }
        </style>';
        
        // Add JavaScript to handle the JSON viewer toggle
        $html .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                // Toggle JSON viewer when button is clicked
                document.querySelectorAll(".view-json-btn").forEach(function(button) {
                    button.addEventListener("click", function() {
                        var videoId = this.getAttribute("data-video-id");
                        var jsonViewer = document.getElementById("json-" + videoId);
                        
                        if (jsonViewer.style.display === "none") {
                            jsonViewer.style.display = "block";
                            this.textContent = "Hide JSON";
                        } else {
                            jsonViewer.style.display = "none";
                            this.textContent = "View Full JSON";
                        }
                    });
                });
            });
        </script>';
    } else {
        // No results
        $html .= '<div style="text-align: center; padding: 50px 20px;">';
        $html .= '<i class="fas fa-video" style="font-size: 48px; color: #dadce0; margin-bottom: 20px;"></i>';
        $html .= '<h2>No videos found</h2>';
        
        if (!empty($query)) {
            $html .= '<p>No videos matching "' . htmlspecialchars($query) . '". Try a different search term.</p>';
        } else {
            $html .= '<p>No videos available in the uploads directory. Check the connection to the server.</p>';
        }
        
        $html .= '</div>';
    }
    
    return $html;
}
/**
 * Retrieve a specific video's data by its ID
 * 
 * @param string $videoId ID of the video to retrieve
 * @return array|null Video data or null if not found
 */
function getVideoById($videoId) {
    if (empty($videoId)) {
        return null;
    }
    
    // Try to get the JSON file for this video ID
    $jsonUrl = VIDEO_URL_DIR . $videoId . '.json';
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $jsonContent = @file_get_contents($jsonUrl, false, $context);
    
    if ($jsonContent === false) {
        error_log("Error retrieving JSON for video ID: " . $videoId);
        return null;
    }
    
    $videoData = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing error for video ID " . $videoId . ": " . json_last_error_msg());
        return null;
    }
    
    // Ensure essential fields
    $videoData['id'] = $videoData['id'] ?? $videoId;
    $videoData['url'] = $videoData['url'] ?? (VIDEO_URL_DIR . $videoId . '.mp4'); // Assuming MP4 if not specified
    $videoData['title'] = $videoData['title'] ?? formatVideoTitle($videoId);
    
    return $videoData;
}

/**
 * Helper function to check if a remote file exists
 * 
 * @param string $url URL to check
 * @return bool True if file exists
 */
function remoteFileExists($url) {
    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 5,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $headers = @get_headers($url, 1, $context);
    return $headers && (strpos($headers[0], '200') !== false || strpos($headers[0], '302') !== false);
}

/**
 * Deep search inside JSON content for keyword matches
 * This is used to find videos with content matching search terms even if not in title/tags
 * 
 * @param string $query Search query
 * @return array Additional video IDs found
 */
function deepSearchJsonContent($query) {
    $results = [];
    
    try {
        // Get directory listing
        $dirListUrl = VIDEO_URL_DIR;
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $dirHtml = @file_get_contents($dirListUrl, false, $context);
        if ($dirHtml === false) {
            error_log("Error accessing directory for deep search");
            return $results;
        }
        
        // Find all JSON files
        preg_match_all('/<a href="([^"]+\.json)"[^>]*>/', $dirHtml, $jsonMatches);
        $jsonFiles = isset($jsonMatches[1]) ? $jsonMatches[1] : [];
        
        $query = strtolower($query);
        
        foreach ($jsonFiles as $jsonFile) {
            // Get JSON content
            $jsonContent = @file_get_contents(VIDEO_URL_DIR . $jsonFile, false, $context);
            if ($jsonContent === false) {
                continue;
            }
            
            // Check if query appears anywhere in the JSON content
            if (stripos($jsonContent, $query) !== false) {
                $baseFilename = pathinfo($jsonFile, PATHINFO_FILENAME);
                
                // Check if video file exists
                $videoExtensions = ['mp4', 'webm', 'mov', 'avi', 'wmv', 'flv', 'mkv'];
                foreach ($videoExtensions as $ext) {
                    if (remoteFileExists(VIDEO_URL_DIR . $baseFilename . '.' . $ext)) {
                        $results[] = $baseFilename;
                        break;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Exception in deep search: " . $e->getMessage());
    }
    
    return $results;
}
?>