<?php
/**
 * Enhanced Analytics Tracking System
 * 
 * Tracks and stores analytics data for:
 * - Image requests/views
 * - Search queries
 * - Hostname appearances in search results
 * - Video views and engagement metrics
 */

class AnalyticsTracker {
    private $image_stats_file = 'image_stats.json';
    private $search_stats_file = 'search_stats.json';
    private $hostname_stats_file = 'hostname_stats.json';
    private $video_stats_file = 'video_stats.json'; // New file for video analytics
    
    /**
     * Constructor initializes tracking files if they don't exist
     */
    public function __construct() {
        // Initialize image stats file
        if (!file_exists($this->image_stats_file)) {
            file_put_contents($this->image_stats_file, json_encode([
                'images' => [],
                'total_views' => 0,
                'last_updated' => date('Y-m-d H:i:s')
            ]));
        }
        
        // Initialize search stats file
        if (!file_exists($this->search_stats_file)) {
            file_put_contents($this->search_stats_file, json_encode([
                'queries' => [],
                'total_searches' => 0,
                'last_updated' => date('Y-m-d H:i:s')
            ]));
        }
        
        // Initialize hostname stats file
        if (!file_exists($this->hostname_stats_file)) {
            file_put_contents($this->hostname_stats_file, json_encode([
                'hostnames' => [],
                'total_appearances' => 0,
                'last_updated' => date('Y-m-d H:i:s')
            ]));
        }
        
        // Initialize video stats file
        if (!file_exists($this->video_stats_file)) {
            file_put_contents($this->video_stats_file, json_encode([
                'videos' => [],
                'total_views' => 0,
                'total_duration_watched' => 0,
                'last_updated' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    /**
     * Track an image view
     * 
     * @param string $image_id Unique identifier for the image (filename or ID)
     * @param string $image_path Path to the image
     * @param array $metadata Additional image metadata
     * @return bool Success status
     */
    public function trackImageView($image_id, $image_path, $metadata = []) {
        try {
            $stats = json_decode(file_get_contents($this->image_stats_file), true);
            
            // Update total views
            $stats['total_views']++;
            
            // Check if image exists in stats
            if (isset($stats['images'][$image_id])) {
                // Update existing image stats
                $stats['images'][$image_id]['views']++;
                $stats['images'][$image_id]['last_viewed'] = date('Y-m-d H:i:s');
                
                // Update trending score (recency bias)
                $daysSinceFirstView = max(1, (time() - strtotime($stats['images'][$image_id]['first_viewed'])) / 86400);
                $stats['images'][$image_id]['trending_score'] = $stats['images'][$image_id]['views'] / $daysSinceFirstView;
            } else {
                // Add new image to stats
                $stats['images'][$image_id] = [
                    'path' => $image_path,
                    'views' => 1,
                    'first_viewed' => date('Y-m-d H:i:s'),
                    'last_viewed' => date('Y-m-d H:i:s'),
                    'trending_score' => 1, // Initial trending score
                    'metadata' => $metadata
                ];
            }
            
            $stats['last_updated'] = date('Y-m-d H:i:s');
            
            // Save updated stats
            file_put_contents($this->image_stats_file, json_encode($stats, JSON_PRETTY_PRINT));
            return true;
        } catch (Exception $e) {
            error_log("Error tracking image view: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Track a search query
     * 
     * @param string $query The search query
     * @param int $results_count Number of results returned
     * @param array $metadata Additional search metadata
     * @return bool Success status
     */
    public function trackSearch($query, $results_count = 0, $metadata = []) {
        try {
            $stats = json_decode(file_get_contents($this->search_stats_file), true);
            $query = trim(strtolower($query)); // Normalize query
            
            // Update total searches
            $stats['total_searches']++;
            
            // Check if query exists in stats
            if (isset($stats['queries'][$query])) {
                // Update existing query stats
                $stats['queries'][$query]['count']++;
                $stats['queries'][$query]['last_searched'] = date('Y-m-d H:i:s');
                $stats['queries'][$query]['results_count'] = $results_count;
                
                // Update trending score (recency bias)
                $daysSinceFirstSearch = max(1, (time() - strtotime($stats['queries'][$query]['first_searched'])) / 86400);
                $stats['queries'][$query]['trending_score'] = $stats['queries'][$query]['count'] / $daysSinceFirstSearch;
            } else {
                // Add new query to stats
                $stats['queries'][$query] = [
                    'count' => 1,
                    'first_searched' => date('Y-m-d H:i:s'),
                    'last_searched' => date('Y-m-d H:i:s'),
                    'results_count' => $results_count,
                    'trending_score' => 1, // Initial trending score
                    'metadata' => $metadata
                ];
            }
            
            $stats['last_updated'] = date('Y-m-d H:i:s');
            
            // Save updated stats
            file_put_contents($this->search_stats_file, json_encode($stats, JSON_PRETTY_PRINT));
            return true;
        } catch (Exception $e) {
            error_log("Error tracking search: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Track a hostname appearance in search results
     * 
     * @param string $hostname The hostname
     * @param string $query The search query that surfaced this hostname
     * @param array $metadata Additional hostname metadata
     * @return bool Success status
     */
    public function trackHostname($hostname, $query = '', $metadata = []) {
        try {
            $stats = json_decode(file_get_contents($this->hostname_stats_file), true);
            $hostname = trim(strtolower($hostname)); // Normalize hostname
            
            // Update total appearances
            $stats['total_appearances']++;
            
            // Check if hostname exists in stats
            if (isset($stats['hostnames'][$hostname])) {
                // Update existing hostname stats
                $stats['hostnames'][$hostname]['appearances']++;
                $stats['hostnames'][$hostname]['last_appeared'] = date('Y-m-d H:i:s');
                
                // Add query to associated queries if not already present
                if (!empty($query) && !in_array($query, $stats['hostnames'][$hostname]['queries'])) {
                    $stats['hostnames'][$hostname]['queries'][] = $query;
                }
                
                // Update trending score (recency bias)
                $daysSinceFirstAppearance = max(1, (time() - strtotime($stats['hostnames'][$hostname]['first_appeared'])) / 86400);
                $stats['hostnames'][$hostname]['trending_score'] = $stats['hostnames'][$hostname]['appearances'] / $daysSinceFirstAppearance;
            } else {
                // Add new hostname to stats
                $stats['hostnames'][$hostname] = [
                    'appearances' => 1,
                    'first_appeared' => date('Y-m-d H:i:s'),
                    'last_appeared' => date('Y-m-d H:i:s'),
                    'queries' => !empty($query) ? [$query] : [],
                    'trending_score' => 1, // Initial trending score
                    'metadata' => $metadata
                ];
            }
            
            $stats['last_updated'] = date('Y-m-d H:i:s');
            
            // Save updated stats
            file_put_contents($this->hostname_stats_file, json_encode($stats, JSON_PRETTY_PRINT));
            return true;
        } catch (Exception $e) {
            error_log("Error tracking hostname: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Track a video view with engagement metrics
     * 
     * @param string $video_id Video identifier
     * @param int $duration_watched Duration watched in seconds
     * @param float $completion_percentage Percentage of video watched (0-100)
     * @param array $metadata Additional video metadata (tags, title, etc)
     * @return bool Success status
     */
    public function trackVideoView($video_id, $duration_watched = 0, $completion_percentage = 0, $metadata = []) {
        try {
            $stats = json_decode(file_get_contents($this->video_stats_file), true);
            
            // Update total views and duration
            $stats['total_views']++;
            $stats['total_duration_watched'] += $duration_watched;
            
            // Current timestamp and day of week for pattern detection
            $now = date('Y-m-d H:i:s');
            $hour = date('G'); // 0-23 hour
            $day_of_week = date('N'); // 1-7 (Monday-Sunday)
            
            // Check if video exists in stats
            if (isset($stats['videos'][$video_id])) {
                $video = &$stats['videos'][$video_id];
                
                // Update basic stats
                $video['views']++;
                $video['total_duration_watched'] += $duration_watched;
                $video['last_viewed'] = $now;
                
                // Update completion tracking
                $video['completion_data'][] = $completion_percentage;
                $video['avg_completion'] = array_sum($video['completion_data']) / count($video['completion_data']);
                
                // Update time patterns
                if (!isset($video['hourly_views'][$hour])) {
                    $video['hourly_views'][$hour] = 0;
                }
                $video['hourly_views'][$hour]++;
                
                if (!isset($video['daily_views'][$day_of_week])) {
                    $video['daily_views'][$day_of_week] = 0;
                }
                $video['daily_views'][$day_of_week]++;
                
                // Calculate engagement score
                $days_active = max(1, (time() - strtotime($video['first_viewed'])) / 86400);
                $recency_factor = 1 / log10(1 + $days_active);
                $completion_factor = $video['avg_completion'] / 100;
                
                $video['engagement_score'] = ($video['views'] / $days_active) * 0.5 + 
                                            ($completion_factor * 0.3) + 
                                            ($recency_factor * 0.2);
                                         
                // Update trending score
                $video['trending_score'] = ($video['views'] / $days_active) * ($video['avg_completion'] / 100);
                
                // Track tags performance if available
                if (!empty($metadata['tags']) && is_array($metadata['tags'])) {
                    if (!isset($video['tag_performance'])) {
                        $video['tag_performance'] = [];
                    }
                    
                    foreach ($metadata['tags'] as $tag) {
                        if (!isset($video['tag_performance'][$tag])) {
                            $video['tag_performance'][$tag] = [
                                'views' => 0,
                                'completions' => []
                            ];
                        }
                        
                        $video['tag_performance'][$tag]['views']++;
                        $video['tag_performance'][$tag]['completions'][] = $completion_percentage;
                    }
                }
            } else {
                // Initialize new video stats
                $stats['videos'][$video_id] = [
                    'title' => isset($metadata['title']) ? $metadata['title'] : $video_id,
                    'views' => 1,
                    'total_duration_watched' => $duration_watched,
                    'first_viewed' => $now,
                    'last_viewed' => $now,
                    'completion_data' => [$completion_percentage],
                    'avg_completion' => $completion_percentage,
                    'hourly_views' => [$hour => 1],
                    'daily_views' => [$day_of_week => 1],
                    'engagement_score' => 1.0,
                    'trending_score' => 1.0,
                    'metadata' => $metadata
                ];
                
                // Initialize tag performance if tags provided
                if (!empty($metadata['tags']) && is_array($metadata['tags'])) {
                    $stats['videos'][$video_id]['tag_performance'] = [];
                    
                    foreach ($metadata['tags'] as $tag) {
                        $stats['videos'][$video_id]['tag_performance'][$tag] = [
                            'views' => 1,
                            'completions' => [$completion_percentage]
                        ];
                    }
                }
            }
            
            $stats['last_updated'] = $now;
            
            // Save updated stats
            file_put_contents($this->video_stats_file, json_encode($stats, JSON_PRETTY_PRINT));
            return true;
        } catch (Exception $e) {
            error_log("Error tracking video view: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get trending images
     * 
     * @param int $limit Maximum number of images to return
     * @param string $sort_by Field to sort by (trending_score, views, last_viewed)
     * @param string $order Sort order (desc, asc)
     * @return array Trending images
     */
    public function getTrendingImages($limit = 10, $sort_by = 'trending_score', $order = 'desc') {
        try {
            $stats = json_decode(file_get_contents($this->image_stats_file), true);
            $images = $stats['images'];
            
            // Sort images by specified field
            $sort_values = [];
            foreach ($images as $id => $data) {
                $sort_values[$id] = $data[$sort_by] ?? 0;
            }
            
            if ($order === 'desc') {
                arsort($sort_values);
            } else {
                asort($sort_values);
            }
            
            // Get top images
            $top_images = [];
            $count = 0;
            foreach ($sort_values as $id => $value) {
                if ($count >= $limit) {
                    break;
                }
                $top_images[$id] = $images[$id];
                $count++;
            }
            
            return $top_images;
        } catch (Exception $e) {
            error_log("Error getting trending images: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get trending searches
     * 
     * @param int $limit Maximum number of searches to return
     * @param string $sort_by Field to sort by (trending_score, count, last_searched)
     * @param string $order Sort order (desc, asc)
     * @return array Trending searches
     */
    public function getTrendingSearches($limit = 10, $sort_by = 'trending_score', $order = 'desc') {
        try {
            $stats = json_decode(file_get_contents($this->search_stats_file), true);
            $queries = $stats['queries'];
            
            // Sort queries by specified field
            $sort_values = [];
            foreach ($queries as $query => $data) {
                $sort_values[$query] = $data[$sort_by] ?? 0;
            }
            
            if ($order === 'desc') {
                arsort($sort_values);
            } else {
                asort($sort_values);
            }
            
            // Get top queries
            $top_queries = [];
            $count = 0;
            foreach ($sort_values as $query => $value) {
                if ($count >= $limit) {
                    break;
                }
                $top_queries[$query] = $queries[$query];
                $count++;
            }
            
            return $top_queries;
        } catch (Exception $e) {
            error_log("Error getting trending searches: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get trending hostnames
     * 
     * @param int $limit Maximum number of hostnames to return
     * @param string $sort_by Field to sort by (trending_score, appearances, last_appeared)
     * @param string $order Sort order (desc, asc)
     * @return array Trending hostnames
     */
    public function getTrendingHostnames($limit = 10, $sort_by = 'trending_score', $order = 'desc') {
        try {
            $stats = json_decode(file_get_contents($this->hostname_stats_file), true);
            $hostnames = $stats['hostnames'];
            
            // Sort hostnames by specified field
            $sort_values = [];
            foreach ($hostnames as $hostname => $data) {
                $sort_values[$hostname] = $data[$sort_by] ?? 0;
            }
            
            if ($order === 'desc') {
                arsort($sort_values);
            } else {
                asort($sort_values);
            }
            
            // Get top hostnames
            $top_hostnames = [];
            $count = 0;
            foreach ($sort_values as $hostname => $value) {
                if ($count >= $limit) {
                    break;
                }
                $top_hostnames[$hostname] = $hostnames[$hostname];
                $count++;
            }
            
            return $top_hostnames;
        } catch (Exception $e) {
            error_log("Error getting trending hostnames: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get trending videos
     * 
     * @param int $limit Maximum number of videos to return
     * @param string $sort_by Field to sort by (trending_score, views, engagement_score)
     * @param string $order Sort order (desc, asc)
     * @return array Trending videos
     */
    public function getTrendingVideos($limit = 10, $sort_by = 'trending_score', $order = 'desc') {
        try {
            $stats = json_decode(file_get_contents($this->video_stats_file), true);
            
            // Return empty array if no videos tracked yet
            if (empty($stats['videos'])) {
                return [];
            }
            
            $videos = $stats['videos'];
            
            // Sort videos by specified field
            $sort_values = [];
            foreach ($videos as $id => $data) {
                $sort_values[$id] = $data[$sort_by] ?? 0;
            }
            
            if ($order === 'desc') {
                arsort($sort_values);
            } else {
                asort($sort_values);
            }
            
            // Get top videos
            $top_videos = [];
            $count = 0;
            foreach ($sort_values as $id => $value) {
                if ($count >= $limit) {
                    break;
                }
                $top_videos[$id] = $videos[$id];
                $count++;
            }
            
            return $top_videos;
        } catch (Exception $e) {
            error_log("Error getting trending videos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get video insights (patterns, recommendations, etc.)
     * 
     * @return array Video insights
     */
    public function getVideoInsights() {
        try {
            $stats = json_decode(file_get_contents($this->video_stats_file), true);
            $insights = [];
            
            // Return empty insights if no videos tracked
            if (empty($stats['videos'])) {
                return $insights;
            }
            
            // Detect peak viewing hours
            $hourly_totals = array_fill(0, 24, 0);
            
            foreach ($stats['videos'] as $video) {
                if (!empty($video['hourly_views'])) {
                    foreach ($video['hourly_views'] as $hour => $count) {
                        if (isset($hourly_totals[$hour])) {
                            $hourly_totals[$hour] += $count;
                        }
                    }
                }
            }
            
            $peak_hour = array_search(max($hourly_totals), $hourly_totals);
            $insights['peak_hour'] = [
                'hour' => $peak_hour,
                'formatted' => sprintf('%02d:00 - %02d:00', $peak_hour, ($peak_hour + 1) % 24),
                'views' => $hourly_totals[$peak_hour],
                'distribution' => $hourly_totals
            ];
            
            // Find best performing tags
            $tag_performance = [];
            foreach ($stats['videos'] as $video) {
                if (!empty($video['tag_performance'])) {
                    foreach ($video['tag_performance'] as $tag => $perf) {
                        if (!isset($tag_performance[$tag])) {
                            $tag_performance[$tag] = [
                                'views' => 0,
                                'completion_total' => 0,
                                'completion_count' => 0,
                                'videos' => 0
                            ];
                        }
                        
                        $tag_performance[$tag]['views'] += $perf['views'];
                        $tag_performance[$tag]['videos']++;
                        
                        if (!empty($perf['completions'])) {
                            $tag_performance[$tag]['completion_total'] += array_sum($perf['completions']);
                            $tag_performance[$tag]['completion_count'] += count($perf['completions']);
                        }
                    }
                }
            }
            
            // Calculate average completion rate per tag
            foreach ($tag_performance as $tag => &$data) {
                if ($data['completion_count'] > 0) {
                    $data['avg_completion'] = $data['completion_total'] / $data['completion_count'];
                } else {
                    $data['avg_completion'] = 0;
                }
                
                // Calculate engagement score
                $data['engagement_score'] = ($data['views'] * 0.6) + ($data['avg_completion'] * 0.4);
            }
            
            // Sort tags by engagement score
            uasort($tag_performance, function($a, $b) {
                return $b['engagement_score'] <=> $a['engagement_score'];
            });
            
            $insights['top_tags'] = array_slice($tag_performance, 0, 5, true);
            
            // Generate content suggestions
            $insights['content_suggestions'] = [];
            
            // Suggest content based on top engaging tags
            $top_tags = array_keys($insights['top_tags']);
            if (!empty($top_tags)) {
                $insights['content_suggestions'][] = [
                    'type' => 'tag_based',
                    'message' => 'Consider creating more content with these tags: ' . implode(', ', array_slice($top_tags, 0, 3)),
                    'reasoning' => 'These tags show high engagement and completion rates'
                ];
            }
            
            // Suggest optimal content length
            $completion_by_length = [];
            foreach ($stats['videos'] as $video) {
                $duration = isset($video['metadata']['duration']) ? $video['metadata']['duration'] : 0;
                
                // Group videos by duration range
                $duration_group = '0-60';
                if ($duration > 60 && $duration <= 180) {
                    $duration_group = '61-180';
                } elseif ($duration > 180 && $duration <= 300) {
                    $duration_group = '181-300';
                } elseif ($duration > 300 && $duration <= 600) {
                    $duration_group = '301-600';
                } elseif ($duration > 600) {
                    $duration_group = '600+';
                }
                
                if (!isset($completion_by_length[$duration_group])) {
                    $completion_by_length[$duration_group] = [
                        'total' => 0,
                        'count' => 0
                    ];
                }
                
                $completion_by_length[$duration_group]['total'] += $video['avg_completion'] ?? 0;
                $completion_by_length[$duration_group]['count']++;
            }
            
            // Calculate average completion rate by duration
            foreach ($completion_by_length as $group => &$data) {
                if ($data['count'] > 0) {
                    $data['avg_completion'] = $data['total'] / $data['count'];
                } else {
                    $data['avg_completion'] = 0;
                }
            }
            
            // Find best performing length
            uasort($completion_by_length, function($a, $b) {
                return $b['avg_completion'] <=> $a['avg_completion'];
            });
            
            $best_length = key($completion_by_length);
            if (!empty($best_length)) {
                $insights['content_suggestions'][] = [
                    'type' => 'length_based',
                    'message' => 'Optimal video length appears to be ' . $best_length . ' seconds',
                    'reasoning' => 'Videos in this duration range have the highest average completion rate'
                ];
            }
            
            return $insights;
        } catch (Exception $e) {
            error_log("Error getting video insights: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate analytics report
     * 
     * @return array Report data
     */
    public function generateReport() {
        $image_stats = json_decode(file_get_contents($this->image_stats_file), true);
        $search_stats = json_decode(file_get_contents($this->search_stats_file), true);
        $hostname_stats = json_decode(file_get_contents($this->hostname_stats_file), true);
        $video_stats = json_decode(file_get_contents($this->video_stats_file), true);
        
        // Get video insights
        $video_insights = $this->getVideoInsights();
        
        // Calculate time-based metrics
        $now = time();
        $views_24h = 0;
        $views_7d = 0;
        
        foreach ($video_stats['videos'] as $video) {
            $last_viewed = strtotime($video['last_viewed']);
            
            if ($last_viewed >= ($now - 86400)) { // 24 hours
                $views_24h += $video['views'];
            }
            
            if ($last_viewed >= ($now - 604800)) { // 7 days
                $views_7d += $video['views'];
            }
        }
        
        // Build enhanced report
        return [
            'summary' => [
                'total_images_tracked' => count($image_stats['images']),
                'total_image_views' => $image_stats['total_views'],
                'total_searches' => $search_stats['total_searches'],
                'total_unique_queries' => count($search_stats['queries']),
                'total_hostnames_tracked' => count($hostname_stats['hostnames']),
                'total_hostname_appearances' => $hostname_stats['total_appearances'],
                'total_videos_tracked' => count($video_stats['videos']),
                'total_video_views' => $video_stats['total_views'],
                'total_video_duration_watched' => $video_stats['total_duration_watched'],
                'video_views_last_24h' => $views_24h,
                'video_views_last_7d' => $views_7d,
                'report_generated' => date('Y-m-d H:i:s')
            ],
            'trending_images' => $this->getTrendingImages(5),
            'trending_searches' => $this->getTrendingSearches(5),
            'trending_hostnames' => $this->getTrendingHostnames(5),
            'trending_videos' => $this->getTrendingVideos(5),
            'video_insights' => $video_insights
        ];
    }
}
?>
