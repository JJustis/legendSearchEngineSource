<?php
/**
 * Video Analytics API
 * 
 * REST API endpoints to track video interactions and provide analytics data
 */

// Include the enhanced analytics tracker
require_once 'analytics_tracker.php';

// Set headers for JSON API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS preflight requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize tracker
$tracker = new AnalyticsTracker();

// Parse request parameters
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));

// API endpoint is the last part of the path
$endpoint = end($path_parts);

// Get JSON request body for POST requests
$request_body = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_body = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        response(400, 'Invalid JSON in request body');
    }
}

// Router for API endpoints
switch ($endpoint) {
    case 'track_video_view':
        handleTrackVideoView($tracker, $request_body);
        break;
        
    case 'get_trending_videos':
        handleGetTrendingVideos($tracker);
        break;
        
    case 'get_video_insights':
        handleGetVideoInsights($tracker);
        break;
        
    case 'get_video_analytics':
        handleGetVideoAnalytics($tracker, $_GET);
        break;
        
    case 'get_all_analytics':
        handleGetAllAnalytics($tracker);
        break;
        
    default:
        response(404, 'Endpoint not found');
}

/**
 * Handle tracking a video view
 */
function handleTrackVideoView($tracker, $data) {
    // Validate required fields
    if (empty($data['video_id'])) {
        response(400, 'Missing required field: video_id');
    }
    
    // Extract fields from request
    $video_id = $data['video_id'];
    $duration_watched = isset($data['duration_watched']) ? (int)$data['duration_watched'] : 0;
    $completion_percentage = isset($data['completion_percentage']) ? (float)$data['completion_percentage'] : 0;
    $metadata = isset($data['metadata']) ? $data['metadata'] : [];
    
    // Track the video view
    $success = $tracker->trackVideoView($video_id, $duration_watched, $completion_percentage, $metadata);
    
    if ($success) {
        response(200, 'Video view tracked successfully');
    } else {
        response(500, 'Failed to track video view');
    }
}

/**
 * Handle getting trending videos
 */
function handleGetTrendingVideos($tracker) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'trending_score';
    $order = isset($_GET['order']) ? $_GET['order'] : 'desc';
    
    $trending_videos = $tracker->getTrendingVideos($limit, $sort_by, $order);
    
    response(200, 'Trending videos retrieved', [
        'videos' => $trending_videos,
        'count' => count($trending_videos)
    ]);
}

/**
 * Handle getting video insights
 */
function handleGetVideoInsights($tracker) {
    $insights = $tracker->getVideoInsights();
    
    response(200, 'Video insights retrieved', [
        'insights' => $insights
    ]);
}

/**
 * Handle getting analytics for a specific video
 */
function handleGetVideoAnalytics($tracker, $params) {
    if (empty($params['video_id'])) {
        response(400, 'Missing required parameter: video_id');
    }
    
    $video_id = $params['video_id'];
    
    // Get video stats from JSON file
    $video_stats_file = 'video_stats.json';
    
    if (!file_exists($video_stats_file)) {
        response(404, 'No video analytics data available');
    }
    
    $stats = json_decode(file_get_contents($video_stats_file), true);
    
    if (!isset($stats['videos'][$video_id])) {
        response(404, 'No analytics data found for this video');
    }
    
    $video_data = $stats['videos'][$video_id];
    
    response(200, 'Video analytics retrieved', [
        'video_id' => $video_id,
        'analytics' => $video_data
    ]);
}

/**
 * Handle getting all analytics data
 */
function handleGetAllAnalytics($tracker) {
    $report = $tracker->generateReport();
    
    response(200, 'Analytics data retrieved', $report);
}

/**
 * Send a JSON response
 */
function response($status_code, $message, $data = null) {
    http_response_code($status_code);
    
    $response = [
        'status' => $status_code,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}
?>