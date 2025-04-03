<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Player with Enhanced Analytics</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .player-container {
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .video-container {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            height: 0;
            overflow: hidden;
        }
        .video-container video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #000;
        }
        .video-info {
            padding: 20px;
        }
        h1 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        .video-metadata {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .views {
            display: flex;
            align-items: center;
            margin-right: 20px;
            color: #7f8c8d;
            font-size: 14px;
        }
        .views i {
            margin-right: 5px;
        }
        .upload-date {
            color: #7f8c8d;
            font-size: 14px;
        }
        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 15px;
        }
        .tag {
            background-color: #e8f5e9;
            color: #388e3c;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .description {
            padding: 15px 0;
            border-top: 1px solid #eee;
            margin-top: 15px;
            color: #555;
        }
        .analytics-section {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .analytics-section h2 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #2c3e50;
            font-size: 18px;
        }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .analytics-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        .analytics-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .analytics-card .label {
            font-size: 14px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .chart-container {
            height: 250px;
            margin-top: 20px;
        }
        .player-controls {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
        }
        .control-btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            transition: background-color 0.2s;
        }
        .control-btn i {
            margin-right: 5px;
        }
        .control-btn:hover {
            background-color: #2980b9;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Analytics Tracking Script -->
    <script src="video-analytics-client.js" data-va-endpoint="https://example.com/api/video-analytics/" data-va-debug="true"></script>
</head>
<body>
    <div class="container">
        <div class="player-container">
            <div class="video-container">
                <!-- Video player with analytics tracking attributes -->
                <video id="main-video" controls 
                       data-va-autotrack="true" 
                       data-va-id="sample-video-123" 
                       data-va-meta-title="Sample Video Title"
                       data-va-meta-category="tutorial"
                       data-va-meta-tags="sample,tutorial,analytics"
                       poster="https://via.placeholder.com/1280x720.png?text=Video+Thumbnail">
                    <source src="https://jcmc.serveminecraft.net/videoplayer/uploads/sample.mp4" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
            
            <div class="video-info">
                <h1>Sample Video Title</h1>
                
                <div class="video-metadata">
                    <div class="views">
                        <i class="fas fa-eye"></i> <span id="view-count">Loading...</span> views
                    </div>
                    <div class="upload-date">
                        Uploaded on March 15, 2024
                    </div>
                </div>
                
                <div class="tags">
                    <div class="tag">Sample</div>
                    <div class="tag">Tutorial</div>
                    <div class="tag">Analytics</div>
                </div>
                
                <div class="description">
                    <p>This is a sample video demonstrating the enhanced analytics tracking system. The system tracks user interactions with the video player and provides detailed analytics on the dashboard.</p>
                    <p>The analytics include view counts, engagement metrics, completion rates, and user behavior patterns.</p>
                </div>
                
                <div class="player-controls">
                    <button class="control-btn" onclick="jumpToPosition(30)">
                        <i class="fas fa-forward"></i> Skip 30s
                    </button>
                    <button class="control-btn" onclick="toggleVideoSize()">
                        <i class="fas fa-expand"></i> Toggle Size
                    </button>
                    <button class="control-btn" onclick="refreshAnalytics()">
                        <i class="fas fa-sync"></i> Refresh Stats
                    </button>
                </div>
            </div>
        </div>
        
        <div class="analytics-section">
            <h2><i class="fas fa-chart-bar"></i> Real-Time Video Analytics</h2>
            
            <div class="analytics-grid">
                <div class="analytics-card">
                    <div class="value" id="total-views">--</div>
                    <div class="label">Total Views</div>
                </div>
                <div class="analytics-card">
                    <div class="value" id="completion-rate">--</div>
                    <div class="label">Avg. Completion</div>
                </div>
                <div class="analytics-card">
                    <div class="value" id="engagement-score">--</div>
                    <div class="label">Engagement Score</div>
                </div>
                <div class="analytics-card">
                    <div class="value" id="total-watch-time">--</div>
                    <div class="label">Total Watch Time</div>
                </div>
            </div>
            
            <div class="chart-container">
                <canvas id="viewsChart"></canvas>
            </div>
        </div>
    </div>
    
    <script>
        // Video player helper functions
        function jumpToPosition(seconds) {
            const video = document.getElementById('main-video');
            video.currentTime += seconds;
            if (video.paused) {
                video.play();
            }
        }
        
        function toggleVideoSize() {
            const container = document.querySelector('.video-container');
            if (container.style.paddingBottom === '40%') {
                container.style.paddingBottom = '56.25%';
            } else {
                container.style.paddingBottom = '40%';
            }
        }
        
        // Analytics data fetching
        async function fetchVideoAnalytics() {
            try {
                // In a real implementation, this would call your analytics API
                const response = await fetch('https://example.com/api/video-analytics/get_video_analytics?video_id=sample-video-123');
                if (!response.ok) {
                    throw new Error('Failed to fetch analytics data');
                }
                
                const data = await response.json();
                return data.data.analytics;
            } catch (error) {
                console.error('Error fetching analytics:', error);
                
                // For demonstration purposes, return mock data when API is unavailable
                return {
                    views: 1254,
                    avg_completion: 68.5,
                    engagement_score: 7.2,
                    total_duration_watched: 28650, // seconds
                    hourly_views: {
                        '0': 25, '1': 18, '2': 12, '3': 8, '4': 6, '5': 10, 
                        '6': 15, '7': 32, '8': 58, '9': 79, '10': 95, '11': 107,
                        '12': 120, '13': 115, '14': 98, '15': 87, '16': 92, '17': 105,
                        '18': 112, '19': 98, '20': 85, '21': 65, '22': 45, '23': 32
                    }
                };
            }
        }
        
        // Format duration for display
        function formatDuration(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            
            if (hours > 0) {
                return `${hours}h ${minutes}m`;
            } else {
                return `${minutes}m`;
            }
        }
        
        // Update analytics display
        function updateAnalyticsDisplay(data) {
            document.getElementById('total-views').textContent = data.views.toLocaleString();
            document.getElementById('view-count').textContent = data.views.toLocaleString();
            document.getElementById('completion-rate').textContent = `${data.avg_completion}%`;
            document.getElementById('engagement-score').textContent = data.engagement_score.toFixed(1);
            document.getElementById('total-watch-time').textContent = formatDuration(data.total_duration_watched);
            
            // Create or update chart
            createViewsChart(data.hourly_views);
        }
        
        // Create views by hour chart
        function createViewsChart(hourlyData) {
            const ctx = document.getElementById('viewsChart').getContext('2d');
            
            // Convert hourly data to arrays
            const hours = Array.from({length: 24}, (_, i) => `${i}:00`);
            const views = hours.map((_, i) => hourlyData[i] || 0);
            
            // Create gradient fill
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(52, 152, 219, 0.7)');
            gradient.addColorStop(1, 'rgba(52, 152, 219, 0.1)');
            
            // Destroy previous chart if it exists
            if (window.viewsChart) {
                window.viewsChart.destroy();
            }
            
            // Create new chart
            window.viewsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: hours,
                    datasets: [{
                        label: 'Views by Hour',
                        data: views,
                        borderColor: 'rgba(52, 152, 219, 1)',
                        backgroundColor: gradient,
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(52, 152, 219, 1)',
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            bodyFont: {
                                size: 14
                            },
                            titleFont: {
                                size: 16
                            }
                        }
                    }
                }
            });
        }
        
        // Refresh analytics data
        async function refreshAnalytics() {
            const data = await fetchVideoAnalytics();
            updateAnalyticsDisplay(data);
        }
        
        // Initial load
        document.addEventListener('DOMContentLoaded', function() {
            refreshAnalytics();
            
            // Set up periodic refresh (every 2 minutes)
            setInterval(refreshAnalytics, 2 * 60 * 1000);
        });
    </script>
</body>
</html>