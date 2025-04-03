<?php
/**
 * Emergency debug function to directly check for a specific video file
 * Add this function to your video_functions.php file
 */

function emergencyCheckForTestVideo() {
    // Define the video URL
    $videoUrl = 'https://jcmc.serveminecraft.net/videoplayer/uploads/test.mp4';
    $jsonUrl = 'https://jcmc.serveminecraft.net/videoplayer/uploads/test.json';
    
    // Use cURL to check if the video file exists
    $ch = curl_init($videoUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    $videoExists = (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200);
    $videoSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    
    // Check if the JSON file exists
    $ch = curl_init($jsonUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $jsonContent = curl_exec($ch);
    $jsonExists = (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200);
    curl_close($ch);
    
    // Prepare debug info
    $debug = [];
    $debug[] = "Video URL: $videoUrl";
    $debug[] = "Video exists: " . ($videoExists ? "YES" : "NO");
    $debug[] = "Video size: " . ($videoSize ? $videoSize . " bytes" : "Unknown");
    
    $debug[] = "JSON URL: $jsonUrl";
    $debug[] = "JSON exists: " . ($jsonExists ? "YES" : "NO");
    
    if ($jsonExists && $jsonContent) {
        $debug[] = "JSON content: " . $jsonContent;
        
        // Try to parse the JSON
        $metadata = json_decode($jsonContent, true);
        if ($metadata && is_array($metadata)) {
            $debug[] = "JSON parsed successfully:";
            foreach ($metadata as $key => $value) {
                $debug[] = " - $key: " . (is_array($value) ? json_encode($value) : $value);
            }
        } else {
            $debug[] = "JSON parse error: " . json_last_error_msg();
        }
    }
    
    // If video exists, create a video result
    if ($videoExists) {
        $videoData = [
            'id' => 'test',
            'title' => 'Test Video',
            'url' => $videoUrl,
            'description' => 'Debug test video',
            'upload_date' => date('Y-m-d H:i:s'),
            'thumbnail' => '',
            'preview' => '',
            'tags' => ['test', 'debug']
        ];
        
        // If JSON exists and parsed successfully, merge the data
        if ($jsonExists && $jsonContent) {
            $metadata = json_decode($jsonContent, true);
            if ($metadata && is_array($metadata)) {
                $videoData = array_merge($videoData, $metadata);
            }
        }
        
        // Return both the video data and debug info
        return [
            'video' => $videoData,
            'debug' => $debug
        ];
    }
    
    // Return only debug info if no video found
    return [
        'video' => null,
        'debug' => $debug
    ];
}

// Override the searchVideos function to force a direct check for "test" query
function searchVideos($query) {
    // If searching for "test", use emergency check
    if (strtolower(trim($query)) === 'test') {
        error_log("Direct check for test.mp4");
        $result = emergencyCheckForTestVideo();
        
        // Log all debug info
        foreach ($result['debug'] as $line) {
            error_log("TEST VIDEO DEBUG: $line");
        }
        
        // Return the video in an array if found
        if ($result['video']) {
            return [$result['video']];
        }
        
        // Return empty array if no video found
        return [];
    }
    
    // Otherwise, use the regular search logic
    error_log("Regular search for: $query");
    
    // First, try exact filename match (highest priority)
    $baseUrl = 'https://jcmc.serveminecraft.net/videoplayer/uploads/';
    $exactMatches = [];
    
    // Try with MP4 extension
    $potentialFilename = $query . '.mp4';
    $videoUrl = $baseUrl . $potentialFilename;
    
    // Check if file exists
    $ch = curl_init($videoUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $videoExists = (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200);
    curl_close($ch);
    
    if ($videoExists) {
        error_log("Found exact match for filename: {$potentialFilename}");
        
        $jsonUrl = $baseUrl . $query . '.json';
        
        // Default video data
        $videoData = [
            'id' => $query,
            'title' => formatVideoTitle($query),
            'url' => $videoUrl,
            'description' => '',
            'upload_date' => date('Y-m-d H:i:s'),
            'thumbnail' => '',
            'preview' => '',
            'tags' => [$query]
        ];
        
        // Check for JSON
        $ch = curl_init($jsonUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $jsonContent = curl_exec($ch);
        $jsonExists = (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200);
        curl_close($ch);
        
        if ($jsonExists && $jsonContent) {
            // Parse JSON data
            $metadata = json_decode($jsonContent, true);
            if ($metadata && is_array($metadata)) {
                // Merge with JSON data
                $videoData = array_merge($videoData, $metadata);
                
                // Make sure thumbnail and preview URLs are absolute
                if (isset($videoData['thumbnail']) && !preg_match('/^https?:\/\//', $videoData['thumbnail'])) {
                    $videoData['thumbnail'] = $baseUrl . $videoData['thumbnail'];
                }
                
                if (isset($videoData['preview']) && !preg_match('/^https?:\/\//', $videoData['preview'])) {
                    $videoData['preview'] = $baseUrl . $videoData['preview'];
                }
            }
        }
        
        $exactMatches[] = $videoData;
    }
    
    // Check if we should still try to load all videos
    if (empty($exactMatches)) {
        error_log("No exact matches, trying to load all videos");
        // Load all videos and filter with our enhanced matching function
        $allVideos = loadVideos();
        $matches = [];
        
        foreach ($allVideos as $video) {
            if (videoMatchesQuery($video, $query)) {
                $matches[] = $video;
            }
        }
        
        $results = $matches;
    } else {
        // Just use the exact matches
        $results = $exactMatches;
    }
    
    error_log("Total search results: " . count($results));
    return $results;
}
