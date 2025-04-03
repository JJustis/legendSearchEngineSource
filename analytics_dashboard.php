<?php
/**
 * Enhanced Analytics Dashboard
 * 
 * Displays analytics data for images, searches, hostnames, and videos
 */

// Include the analytics tracker
require_once 'analytics_tracker.php';

// Initialize tracker
$tracker = new AnalyticsTracker();

// Generate report data
$report = $tracker->generateReport();

// Function to format timestamp difference as "time ago"
function time_ago($timestamp) {
    $timestamp = strtotime($timestamp);
    $difference = time() - $timestamp;
    
    if ($difference < 60) {
        return $difference . " seconds ago";
    } elseif ($difference < 3600) {
        return round($difference / 60) . " minutes ago";
    } elseif ($difference < 86400) {
        return round($difference / 3600) . " hours ago";
    } elseif ($difference < 604800) {
        return round($difference / 86400) . " days ago";
    } elseif ($difference < 2592000) {
        return round($difference / 604800) . " weeks ago";
    } elseif ($difference < 31536000) {
        return round($difference / 2592000) . " months ago";
    } else {
        return round($difference / 31536000) . " years ago";
    }
}

// Function to format seconds as human-readable duration
function format_duration($seconds) {
    if ($seconds < 60) {
        return $seconds . " seconds";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remaining_seconds = $seconds % 60;
        return $minutes . " min" . ($remaining_seconds > 0 ? " " . $remaining_seconds . " sec" : "");
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . " hr" . ($minutes > 0 ? " " . $minutes . " min" : "");
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Analytics Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        h1 {
            margin: 0;
            color: #2c3e50;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        .card h3 {
            margin-top: 0;
            color: #7f8c8d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .card .value {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
        }
        .card .icon {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            opacity: 0.2;
        }
        .card.video .icon {
            color: #e74c3c;
        }
        .card.image .icon {
            color: #3498db;
        }
        .card.search .icon {
            color: #2ecc71;
        }
        .card.hostname .icon {
            color: #9b59b6;
        }
        .card .trend {
            font-size: 14px;
            margin-top: 10px;
            color: #7f8c8d;
        }
        .trend-up {
            color: #2ecc71;
        }
        .trend-down {
            color: #e74c3c;
        }
        .section {
            margin-bottom: 40px;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .section-header h2 {
            margin: 0;
            color: #2c3e50;
        }
        .section-actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn i {
            margin-right: 6px;
        }
        .btn:hover {
            background-color: #2980b9;
        }
        .btn.success {
            background-color: #2ecc71;
        }
        .btn.success:hover {
            background-color: #27ae60;
        }
        .btn.warning {
            background-color: #f39c12;
        }
        .btn.warning:hover {
            background-color: #e67e22;
        }
        .section-content {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        tr:hover {
            background-color: #f5f7fa;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge.views {
            background-color: #e8f5e9;
            color: #388e3c;
        }
        .badge.searches {
            background-color: #fff8e1;
            color: #ffa000;
        }
        .badge.hostnames {
            background-color: #f3e5f5;
            color: #8e24aa;
        }
        .badge.videos {
            background-color: #ffebee;
            color: #c62828;
        }
        .badge.completion {
            background-color: #e0f7fa;
            color: #0097a7;
        }
        .badge.engagement {
            background-color: #e8f5e9;
            color: #388e3c;
        }
        .refresh-button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
        }
        .refresh-button i {
            margin-right: 6px;
        }
        .refresh-button:hover {
            background-color: #2980b9;
        }
        .timestamp {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 5px;
        }
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }
        .tag {
            background-color: #ecf0f1;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            color: #7f8c8d;
        }
        .image-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
            margin-right: 10px;
        }
        .video-thumbnail {
            width: 80px;
            height: 45px;
            object-fit: cover;
            border-radius: 4px;
            margin-right: 10px;
            position: relative;
        }
        .video-thumbnail::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 0;
            height: 0;
            border-style: solid;
            border-width: 8px 0 8px 16px;
            border-color: transparent transparent transparent rgba(255,255,255,0.8);
        }
        .flex-row {
            display: flex;
            align-items: center;
        }
        .chart-container {
            height: 300px;
            margin-top: 20px;
        }
        .progress-bar {
            height: 8px;
            background-color: #ecf0f1;
            border-radius: 4px;
            margin-top: 5px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background-color: #3498db;
            border-radius: 4px;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .insight-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #3498db;
            margin-bottom: 15px;
        }
        .insight-card.peak-hour {
            border-left-color: #f39c12;
        }
        .insight-card.tags {
            border-left-color: #2ecc71;
        }
        .insight-card.suggestion {
            border-left-color: #9b59b6;
        }
        .insight-title {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .insight-message {
            color: #7f8c8d;
            font-size: 14px;
        }
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 500;
        }
        .tab.active {
            border-bottom-color: #3498db;
            color: #3498db;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .heatmap-cell {
            text-align: center;
            padding: 10px;
            color: white;
            font-weight: bold;
            border-radius: 4px;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>Enhanced Analytics Dashboard</h1>
                <div class="timestamp">Last updated: <?php echo $report['summary']['report_generated']; ?></div>
            </div>
            <button class="refresh-button" onclick="window.location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh Data
            </button>
        </header>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <!-- Original Cards -->
            <div class="card image">
                <i class="fas fa-image icon"></i>
                <h3>Total Image Views</h3>
                <div class="value"><?php echo number_format($report['summary']['total_image_views']); ?></div>
            </div>
            <div class="card image">
                <i class="fas fa-images icon"></i>
                <h3>Images Tracked</h3>
                <div class="value"><?php echo number_format($report['summary']['total_images_tracked']); ?></div>
            </div>
            <div class="card search">
                <i class="fas fa-search icon"></i>
                <h3>Total Searches</h3>
                <div class="value"><?php echo number_format($report['summary']['total_searches']); ?></div>
            </div>
            <div class="card search">
                <i class="fas fa-list icon"></i>
                <h3>Unique Search Queries</h3>
                <div class="value"><?php echo number_format($report['summary']['total_unique_queries']); ?></div>
            </div>
            <div class="card hostname">
                <i class="fas fa-server icon"></i>
                <h3>Hostnames Tracked</h3>
                <div class="value"><?php echo number_format($report['summary']['total_hostnames_tracked']); ?></div>
            </div>
            <div class="card hostname">
                <i class="fas fa-globe icon"></i>
                <h3>Hostname Appearances</h3>
                <div class="value"><?php echo number_format($report['summary']['total_hostname_appearances']); ?></div>
            </div>
            
            <!-- New Video Cards -->
            <div class="card video">
                <i class="fas fa-video icon"></i>
                <h3>Total Video Views</h3>
                <div class="value"><?php echo number_format($report['summary']['total_video_views'] ?? 0); ?></div>
            </div>
            <div class="card video">
                <i class="fas fa-film icon"></i>
                <h3>Videos Tracked</h3>
                <div class="value"><?php echo number_format($report['summary']['total_videos_tracked'] ?? 0); ?></div>
            </div>
            <div class="card video">
                <i class="fas fa-clock icon"></i>
                <h3>Total Watch Time</h3>
                <div class="value"><?php echo format_duration($report['summary']['total_video_duration_watched'] ?? 0); ?></div>
            </div>
            <div class="card video">
                <i class="fas fa-chart-line icon"></i>
                <h3>Views (Last 24h)</h3>
                <div class="value"><?php echo number_format($report['summary']['video_views_last_24h'] ?? 0); ?></div>
            </div>
        </div>
        
        <!-- Video Analytics Section (NEW) -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-video"></i> Video Analytics</h2>
                <div class="section-actions">
                    <a href="#" class="btn" onclick="toggleVideoCharts()">
                        <i class="fas fa-chart-bar"></i> Toggle Charts
                    </a>
                    <a href="?export=videos" class="btn">
                        <i class="fas fa-download"></i> Export Data
                    </a>
                </div>
            </div>
            
            <div class="section-content">
                <!-- Video Analytics Tabs -->
                <div class="tabs">
                    <div class="tab active" onclick="showTab('trending-videos')">Trending Videos</div>
                    <div class="tab" onclick="showTab('video-insights')">Insights & Patterns</div>
                    <div class="tab" onclick="showTab('engagement-metrics')">Engagement Metrics</div>
                </div>
                
                <!-- Trending Videos Tab -->
                <div id="trending-videos" class="tab-content active">
                    <table>
                        <thead>
                            <tr>
                                <th>Video</th>
                                <th>Views</th>
                                <th>Last Viewed</th>
                                <th>Watch Time</th>
                                <th>Avg. Completion</th>
                                <th>Engagement Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['trending_videos'] as $id => $data): ?>
                            <tr>
                                <td class="flex-row">
                                    <div class="video-thumbnail">
                                        <?php 
                                        $thumbnail = isset($data['metadata']['thumbnail']) ? $data['metadata']['thumbnail'] : 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxOTIgMTA4IiBmaWxsPSJub25lIj48cmVjdCB3aWR0aD0iMTkyIiBoZWlnaHQ9IjEwOCIgZmlsbD0iI2VlZSIvPjxwYXRoIGQ9Ik03OCw1NCw5OCw2NnYtMjRMNzgsNTRtNDQsMTRINzBWNDBIOTJaIiBmaWxsPSIjY2NjIi8+PC9zdmc+';
                                        ?>
                                        <img src="<?php echo htmlspecialchars($thumbnail); ?>" alt="<?php echo htmlspecialchars($data['title'] ?? $id); ?>" class="video-thumbnail">
                                    </div>
                                    <div>
                                        <div><?php echo htmlspecialchars($data['title'] ?? $id); ?></div>
                                        <div class="tag-list">
                                            <?php 
                                            if (!empty($data['metadata']['tags'])) {
                                                foreach (array_slice($data['metadata']['tags'], 0, 3) as $tag) {
                                                    echo '<span class="tag">' . htmlspecialchars($tag) . '</span>';
                                                }
                                                if (count($data['metadata']['tags']) > 3) {
                                                    echo '<span class="tag">+' . (count($data['metadata']['tags']) - 3) . ' more</span>';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge videos"><?php echo number_format($data['views']); ?></span></td>
                                <td><?php echo time_ago($data['last_viewed']); ?></td>
                                <td><?php echo format_duration($data['total_duration_watched'] ?? 0); ?></td>
                                <td>
                                    <?php 
                                    $completion = isset($data['avg_completion']) ? $data['avg_completion'] : 0;
                                    echo number_format($completion, 1) . '%';
                                    ?>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min(100, $completion); ?>%;"></div>
                                    </div>
                                </td>
                                <td><span class="badge engagement"><?php echo number_format($data['engagement_score'] ?? 0, 2); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($report['trending_videos'])): ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">No video data available yet</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <!-- Video Charts (Initially Hidden) -->
                    <div id="video-charts" style="display: none; margin-top: 30px;">
                        <div class="grid-2">
                            <div>
                                <h3>Views by Video</h3>
                                <canvas id="viewsByVideoChart"></canvas>
                            </div>
                            <div>
                                <h3>Engagement Scores</h3>
                                <canvas id="engagementScoreChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Video Insights Tab -->
                <div id="video-insights" class="tab-content">
                    <?php if (!empty($report['video_insights'])): ?>
                    
                    <div class="grid-2">
                        <div>
                            <!-- Peak Hour Insight -->
                            <?php if (!empty($report['video_insights']['peak_hour'])): ?>
                            <div class="insight-card peak-hour">
                                <div class="insight-title">
                                    <i class="fas fa-clock"></i> Peak Viewing Hours
                                </div>
                                <div class="insight-message">
                                    Videos receive the most views at <?php echo $report['video_insights']['peak_hour']['formatted']; ?> hours
                                </div>
                                <div class="chart-container" style="height: 200px; margin-top: 15px;">
                                    <canvas id="hourlyViewsChart"></canvas>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Content Suggestions -->
                            <?php if (!empty($report['video_insights']['content_suggestions'])): ?>
                            <div class="insight-card suggestion">
                                <div class="insight-title">
                                    <i class="fas fa-lightbulb"></i> Content Suggestions
                                </div>
                                <div class="insight-message">
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                    <?php foreach ($report['video_insights']['content_suggestions'] as $suggestion): ?>
                                        <li><?php echo htmlspecialchars($suggestion['message']); ?></li>
                                    <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <!-- Top Tags Performance -->
                            <?php if (!empty($report['video_insights']['top_tags'])): ?>
                            <div class="insight-card tags">
                                <div class="insight-title">
                                    <i class="fas fa-tags"></i> Top Performing Tags
                                </div>
                                <div class="insight-message">
                                    These tags show the highest engagement levels
                                </div>
                                <table style="margin-top: 15px; font-size: 14px;">
                                    <thead>
                                        <tr>
                                            <th>Tag</th>
                                            <th>Views</th>
                                            <th>Avg. Completion</th>
                                            <th>Engagement</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report['video_insights']['top_tags'] as $tag => $data): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($tag); ?></td>
                                            <td><?php echo number_format($data['views']); ?></td>
                                            <td><?php echo number_format($data['avg_completion'], 1); ?>%</td>
                                            <td>
                                                <?php 
                                                $score = isset($data['engagement_score']) ? $data['engagement_score'] : 0;
                                                echo number_format($score, 2);
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <p style="text-align: center; padding: 30px;">Not enough video data to generate insights yet.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Engagement Metrics Tab -->
                <div id="engagement-metrics" class="tab-content">
                    <div class="grid-2">
                        <div>
                            <h3>Completion Rate by Video</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Video</th>
                                        <th>Avg. Completion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $videos = $report['trending_videos'];
                                    // Sort by completion rate (if available)
                                    uasort($videos, function($a, $b) {
                                        $a_comp = isset($a['avg_completion']) ? $a['avg_completion'] : 0;
                                        $b_comp = isset($b['avg_completion']) ? $b['avg_completion'] : 0;
                                        return $b_comp <=> $a_comp;
                                    });
                                    
                                    foreach ($videos as $id => $data): 
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($data['title'] ?? $id); ?></td>
                                        <td>
                                            <?php 
                                            $completion = isset($data['avg_completion']) ? $data['avg_completion'] : 0;
                                            echo number_format($completion, 1) . '%';
                                            ?>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min(100, $completion); ?>%; background-color: <?php echo getColorForValue($completion); ?>"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($videos)): ?>
                                    <tr>
                                        <td colspan="2" style="text-align: center;">No video data available yet</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div>
                            <h3>Watch Time by Video</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Video</th>
                                        <th>Total Watch Time</th>
                                        <th>Avg. per View</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Sort by watch time
                                    uasort($videos, function($a, $b) {
                                        $a_time = isset($a['total_duration_watched']) ? $a['total_duration_watched'] : 0;
                                        $b_time = isset($b['total_duration_watched']) ? $b['total_duration_watched'] : 0;
                                        return $b_time <=> $a_time;
                                    });
                                    
                                    foreach ($videos as $id => $data): 
                                        $total_duration = isset($data['total_duration_watched']) ? $data['total_duration_watched'] : 0;
                                        $views = isset($data['views']) ? $data['views'] : 1;
                                        $avg_duration = $views > 0 ? $total_duration / $views : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($data['title'] ?? $id); ?></td>
                                        <td><?php echo format_duration($total_duration); ?></td>
                                        <td><?php echo format_duration($avg_duration); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($videos)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center;">No video data available yet</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Recent Activity Graph -->
                    <div style="margin-top: 30px;">
                        <h3>Recent Viewing Activity</h3>
                        <div class="chart-container">
                            <canvas id="recentActivityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Original Trending Images Section -->
        <div class="section">
            <div class="section-header">
                <h2>Trending Images</h2>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Views</th>
                            <th>Last Viewed</th>
                            <th>Trending Score</th>
                            <th>Tags</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['trending_images'] as $id => $data): ?>
                        <tr>
                            <td class="flex-row">
                                <img src="<?php echo htmlspecialchars($data['path']); ?>" alt="<?php echo htmlspecialchars($id); ?>" class="image-thumbnail">
                                <?php echo htmlspecialchars($id); ?>
                            </td>
                            <td><span class="badge views"><?php echo number_format($data['views']); ?></span></td>
                            <td><?php echo time_ago($data['last_viewed']); ?></td>
                            <td><?php echo number_format($data['trending_score'], 2); ?></td>
                            <td>
                                <div class="tag-list">
                                    <?php 
                                    if (!empty($data['metadata']['tags'])) {
                                        foreach (array_slice($data['metadata']['tags'], 0, 5) as $tag) {
                                            echo '<span class="tag">' . htmlspecialchars($tag) . '</span>';
                                        }
                                        if (count($data['metadata']['tags']) > 5) {
                                            echo '<span class="tag">+' . (count($data['metadata']['tags']) - 5) . ' more</span>';
                                        }
                                    } else {
                                        echo '<span class="tag">No tags</span>';
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report['trending_images'])): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">No image data available yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Original Trending Searches Section -->
        <div class="section">
            <div class="section-header">
                <h2>Trending Searches</h2>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Query</th>
                            <th>Search Count</th>
                            <th>Last Searched</th>
                            <th>Results</th>
                            <th>Trending Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['trending_searches'] as $query => $data): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($query); ?></td>
                            <td><span class="badge searches"><?php echo number_format($data['count']); ?></span></td>
                            <td><?php echo time_ago($data['last_searched']); ?></td>
                            <td><?php echo number_format($data['results_count']); ?></td>
                            <td><?php echo number_format($data['trending_score'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report['trending_searches'])): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">No search data available yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Original Trending Hostnames Section -->
        <div class="section">
            <div class="section-header">
                <h2>Trending Hostnames</h2>
            </div>
            <div class="section-content">
                <table>
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>Appearances</th>
                            <th>Last Appeared</th>
                            <th>Associated Queries</th>
                            <th>Trending Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['trending_hostnames'] as $hostname => $data): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($hostname); ?></td>
                            <td><span class="badge hostnames"><?php echo number_format($data['appearances']); ?></span></td>
                            <td><?php echo time_ago($data['last_appeared']); ?></td>
                            <td>
                                <div class="tag-list">
                                    <?php 
                                    if (!empty($data['queries'])) {
                                        foreach (array_slice($data['queries'], 0, 3) as $query) {
                                            echo '<span class="tag">' . htmlspecialchars($query) . '</span>';
                                        }
                                        if (count($data['queries']) > 3) {
                                            echo '<span class="tag">+' . (count($data['queries']) - 3) . ' more</span>';
                                        }
                                    } else {
                                        echo '<span class="tag">No associated queries</span>';
                                    }
                                    ?>
                                </div>
                            </td>
                            <td><?php echo number_format($data['trending_score'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report['trending_hostnames'])): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">No hostname data available yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Original CSV Data Section -->
        <div class="section">
            <div class="section-header">
                <h2>CSV Data Analysis</h2>
            </div>
            <div class="section-content">
                <?php
                // Load and analyze CSV data if available
                $csv_data = [];
                $csv_metadata = [];
                
                if (file_exists('results.csv')) {
                    $handle = fopen('results.csv', 'r');
                    if ($handle !== false) {
                        // Get headers
                        $headers = fgetcsv($handle);
                        
                        // Parse data
                        while (($data = fgetcsv($handle)) !== false) {
                            if (count($data) >= count($headers)) {
                                $row = array_combine(array_slice($headers, 0, count($data)), $data);
                                $csv_data[] = $row;
                                
                                // Extract metadata if available
                                if (isset($row['Hostname']) && !empty($row['Hostname'])) {
                                    if (!isset($csv_metadata[$row['Hostname']])) {
                                        $csv_metadata[$row['Hostname']] = 0;
                                    }
                                    $csv_metadata[$row['Hostname']]++;
                                }
                            }
                        }
                        fclose($handle);
                    }
                }
                
                // Sort hostname data by count
                arsort($csv_metadata);
                ?>
                
                <h3>CSV Hostname Statistics</h3>
                <?php if (empty($csv_metadata)): ?>
                    <p>No CSV data available or no hostnames found in the CSV file.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Hostname</th>
                                <th>Occurrences</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_occurrences = array_sum($csv_metadata);
                            $counter = 0;
                            foreach ($csv_metadata as $hostname => $count): 
                                if (++$counter > 10) break; // Show only top 10
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($hostname); ?></td>
                                <td><span class="badge hostnames"><?php echo number_format($count); ?></span></td>
                                <td><?php echo number_format(($count / $total_occurrences) * 100, 1); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- JavaScript Functions for Enhanced Features -->
        <script>
            // Helper function to generate a color based on value
            function getColorForValue(value, min = 0, max = 100) {
                // Normalize value to 0-1 range
                const normalized = Math.max(0, Math.min(1, (value - min) / (max - min)));
                
                let r, g, b;
                
                if (normalized < 0.5) {
                    // Blue to green (0-50%)
                    r = Math.round(41 + (normalized * 2 * (46 - 41)));
                    g = Math.round(128 + (normalized * 2 * (204 - 128)));
                    b = Math.round(185 + (normalized * 2 * (113 - 185)));
                } else {
                    // Green to red (50-100%)
                    const adjusted = (normalized - 0.5) * 2;
                    r = Math.round(46 + (adjusted * (231 - 46)));
                    g = Math.round(204 - (adjusted * (204 - 76)));
                    b = Math.round(113 - (adjusted * (113 - 60)));
                }
                
                return `rgb(${r}, ${g}, ${b})`;
            }
            
            // Function to show/hide tab content
            function showTab(tabId) {
                // Hide all tab content
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Show selected tab content
                document.getElementById(tabId).classList.add('active');
                
                // Update tab styles
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Find the tab button that has this tab in its onclick attribute
                document.querySelectorAll('.tab').forEach(tab => {
                    if (tab.getAttribute('onclick').includes(tabId)) {
                        tab.classList.add('active');
                    }
                });
            }
            
            // Function to toggle video charts visibility
            function toggleVideoCharts() {
                const charts = document.getElementById('video-charts');
                if (charts.style.display === 'none') {
                    charts.style.display = 'block';
                    initVideoCharts();
                } else {
                    charts.style.display = 'none';
                }
            }
            
            // Initialize all charts when needed
            function initVideoCharts() {
                // Prepare data for charts
                const videoData = <?php echo json_encode($report['trending_videos'] ?? []); ?>;
                const videoInsights = <?php echo json_encode($report['video_insights'] ?? []); ?>;
                
                // Views by Video Chart
                if (Object.keys(videoData).length > 0) {
                    const videoLabels = Object.keys(videoData).map(id => videoData[id].title || id);
                    const videoViews = Object.values(videoData).map(data => data.views || 0);
                    
                    new Chart(document.getElementById('viewsByVideoChart'), {
                        type: 'bar',
                        data: {
                            labels: videoLabels,
                            datasets: [{
                                label: 'Views',
                                data: videoViews,
                                backgroundColor: 'rgba(231, 76, 60, 0.7)',
                                borderColor: 'rgba(231, 76, 60, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            },
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                    
                    // Engagement Score Chart
                    const engagementScores = Object.values(videoData).map(data => data.engagement_score || 0);
                    
                    new Chart(document.getElementById('engagementScoreChart'), {
                        type: 'bar',
                        data: {
                            labels: videoLabels,
                            datasets: [{
                                label: 'Engagement Score',
                                data: engagementScores,
                                backgroundColor: 'rgba(46, 204, 113, 0.7)',
                                borderColor: 'rgba(46, 204, 113, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            },
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
                
                // Recent Activity Chart (Mock data for demonstration)
                const dates = [];
                const views = [];
                
                // Generate last 14 days
                for (let i = 13; i >= 0; i--) {
                    const date = new Date();
                    date.setDate(date.getDate() - i);
                    dates.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                    
                    // Generate some random data for demonstration
                    views.push(Math.floor(Math.random() * 50) + 10);
                }
                
                new Chart(document.getElementById('recentActivityChart'), {
                    type: 'line',
                    data: {
                        labels: dates,
                        datasets: [{
                            label: 'Video Views',
                            data: views,
                            borderColor: 'rgba(52, 152, 219, 1)',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
            
            // Initialize hourly views chart if it exists
            document.addEventListener('DOMContentLoaded', function() {
                const hourlyViewsChart = document.getElementById('hourlyViewsChart');
                if (hourlyViewsChart) {
                    const hourlyData = <?php 
                        if (isset($report['video_insights']['peak_hour']['distribution'])) {
                            echo json_encode($report['video_insights']['peak_hour']['distribution']);
                        } else {
                            echo json_encode(array_fill(0, 24, 0));
                        }
                    ?>;
                    
                    const labels = Array.from({length: 24}, (_, i) => `${i}:00`);
                    const data = Object.values(hourlyData);
                    
                    // Create gradient background for the chart
                    const ctx = hourlyViewsChart.getContext('2d');
                    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(243, 156, 18, 0.8)');
                    gradient.addColorStop(1, 'rgba(243, 156, 18, 0.1)');
                    
                    new Chart(hourlyViewsChart, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Views',
                                data: data,
                                backgroundColor: gradient,
                                borderColor: 'rgba(243, 156, 18, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                }
                            }
                        }
                    });
                }
            });
            
            // Auto-refresh the page every 5 minutes
            setTimeout(function() {
                window.location.reload();
            }, 5 * 60 * 1000);
        </script>
        
        <?php
        // Function to generate a color based on a value
        function getColorForValue($value, $min = 0, $max = 100) {
            $value = max($min, min($max, $value));
            $percentage = ($value - $min) / ($max - $min);
            
            if ($percentage < 0.5) {
                // Blue to green (0-50%)
                $r = round(41 + ($percentage * 2 * (46 - 41)));
                $g = round(128 + ($percentage * 2 * (204 - 128)));
                $b = round(185 + ($percentage * 2 * (113 - 185)));
            } else {
                // Green to red (50-100%)
                $adjusted = ($percentage - 0.5) * 2;
                $r = round(46 + ($adjusted * (231 - 46)));
                $g = round(204 - ($adjusted * (204 - 76)));
                $b = round(113 - ($adjusted * (113 - 60)));
            }
            
            return "rgb($r, $g, $b)";
        }
        ?>
    </div>
</body>
</html>