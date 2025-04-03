<?php
/**
 * TrendPredictor - A College-Level Search Trend Analysis & Prediction System
 * 
 * This application tracks search/view frequency data and predicts future trends
 * using various statistical methods and machine learning algorithms.
 * 
 * Features:
 * - Data collection and storage for search/view metrics
 * - Time series analysis with multiple forecasting models
 * - Anomaly detection
 * - Statistical analysis of trends
 * - Visualization of historical data and predictions
 * - REST API endpoints for integration with other systems
 */

// Config file
require_once 'config.php';

/**
 * Main TrendPredictor class
 */
class TrendPredictor {
    private $db;
    private $dataCache = [];
    private $models = [];
    private $config;
    
    /**
     * Constructor
     * 
     * @param array $config Configuration options
     */
    public function __construct($config = []) {
        $this->config = array_merge([
            'db_host' => DB_HOST,
            'db_name' => DB_NAME,
            'db_user' => DB_USER,
            'db_pass' => DB_PASS,
            'timeframe' => 'daily',
            'prediction_horizon' => 30
        ], $config);
        
        $this->connectDatabase();
    }
    
    /**
     * Connect to the database
     */
    private function connectDatabase() {
        try {
            $this->db = new PDO(
                "mysql:host={$this->config['db_host']};dbname={$this->config['db_name']};charset=utf8mb4",
                $this->config['db_user'],
                $this->config['db_pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Track a new search/view event
     * 
     * @param string $term The search term or content identifier
     * @param string $category Category of the content (optional)
     * @param array $metadata Additional metadata (optional)
     * @return boolean Success status
     */
    public function trackEvent($term, $category = null, $metadata = []) {
        try {
            // Check if term exists
            $stmt = $this->db->prepare("
                SELECT id FROM search_terms 
                WHERE term = :term
            ");
            $stmt->bindParam(':term', $term);
            $stmt->execute();
            $result = $stmt->fetch();
            
            $termId = null;
            
            // If term doesn't exist, create it
            if (!$result) {
                $stmt = $this->db->prepare("
                    INSERT INTO search_terms (term, category, created_at)
                    VALUES (:term, :category, NOW())
                ");
                $stmt->bindParam(':term', $term);
                $stmt->bindParam(':category', $category);
                $stmt->execute();
                
                $termId = $this->db->lastInsertId();
            } else {
                $termId = $result['id'];
            }
            
            // Record the event
            $stmt = $this->db->prepare("
                INSERT INTO search_events (term_id, timestamp, metadata)
                VALUES (:term_id, NOW(), :metadata)
            ");
            $stmt->bindParam(':term_id', $termId);
            $stmt->bindParam(':metadata', json_encode($metadata));
            $stmt->execute();
            
            // Update aggregate counts based on timeframe
            $this->updateAggregateCounts($termId);
            
            return true;
        } catch (PDOException $e) {
            error_log("Error tracking event: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update aggregate count tables
     * 
     * @param integer $termId The term ID
     */
    private function updateAggregateCounts($termId) {
        $timeframes = ['hourly', 'daily', 'weekly', 'monthly'];
        
        foreach ($timeframes as $timeframe) {
            try {
                switch ($timeframe) {
                    case 'hourly':
                        $dateFormat = "DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00')";
                        break;
                    case 'daily':
                        $dateFormat = "DATE(timestamp)";
                        break;
                    case 'weekly':
                        $dateFormat = "DATE(timestamp - INTERVAL (DAYOFWEEK(timestamp) - 1) DAY)";
                        break;
                    case 'monthly':
                        $dateFormat = "DATE_FORMAT(timestamp, '%Y-%m-01')";
                        break;
                }
                
                // Get the latest timestamp
                $stmt = $this->db->prepare("
                    SELECT {$dateFormat} AS period, COUNT(*) AS count
                    FROM search_events
                    WHERE term_id = :term_id
                    GROUP BY period
                    ORDER BY period DESC
                    LIMIT 1
                ");
                $stmt->bindParam(':term_id', $termId);
                $stmt->execute();
                $result = $stmt->fetch();
                
                if ($result) {
                    // Check if this period already exists in the aggregate table
                    $stmt = $this->db->prepare("
                        SELECT id FROM {$timeframe}_stats
                        WHERE term_id = :term_id AND period = :period
                    ");
                    $stmt->bindParam(':term_id', $termId);
                    $stmt->bindParam(':period', $result['period']);
                    $stmt->execute();
                    $existing = $stmt->fetch();
                    
                    if ($existing) {
                        // Update existing record
                        $stmt = $this->db->prepare("
                            UPDATE {$timeframe}_stats
                            SET count = :count, updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->bindParam(':count', $result['count']);
                        $stmt->bindParam(':id', $existing['id']);
                        $stmt->execute();
                    } else {
                        // Insert new record
                        $stmt = $this->db->prepare("
                            INSERT INTO {$timeframe}_stats (term_id, period, count, created_at, updated_at)
                            VALUES (:term_id, :period, :count, NOW(), NOW())
                        ");
                        $stmt->bindParam(':term_id', $termId);
                        $stmt->bindParam(':period', $result['period']);
                        $stmt->bindParam(':count', $result['count']);
                        $stmt->execute();
                    }
                }
            } catch (PDOException $e) {
                error_log("Error updating {$timeframe} stats: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get historical data for a term
     * 
     * @param string $term The search term
     * @param string $timeframe Time aggregation level (hourly, daily, weekly, monthly)
     * @param integer $limit Number of data points to return
     * @return array Historical data
     */
    public function getHistoricalData($term, $timeframe = 'daily', $limit = 90) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM search_terms WHERE term = :term
            ");
            $stmt->bindParam(':term', $term);
            $stmt->execute();
            $result = $stmt->fetch();
            
            if (!$result) {
                return [];
            }
            
            $termId = $result['id'];
            
            $stmt = $this->db->prepare("
                SELECT period, count
                FROM {$timeframe}_stats
                WHERE term_id = :term_id
                ORDER BY period ASC
                LIMIT :limit
            ");
            $stmt->bindParam(':term_id', $termId);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $data = $stmt->fetchAll();
            
            // Cache this data for use in predictions
            $this->dataCache[$term] = [
                'timeframe' => $timeframe,
                'data' => $data
            ];
            
            return $data;
        } catch (PDOException $e) {
            error_log("Error getting historical data: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Predict future trends using double exponential smoothing
     * 
     * @param string $term The search term
     * @param integer $horizon Number of periods to predict
     * @param string $timeframe Time aggregation level
     * @return array Predictions
     */
    public function predictTrendExponentialSmoothing($term, $horizon = 30, $timeframe = 'daily') {
        // Get historical data if not already cached
        if (!isset($this->dataCache[$term]) || $this->dataCache[$term]['timeframe'] !== $timeframe) {
            $this->getHistoricalData($term, $timeframe);
        }
        
        if (empty($this->dataCache[$term]['data'])) {
            return ['error' => 'Not enough historical data'];
        }
        
        $data = array_column($this->dataCache[$term]['data'], 'count');
        
        // Need at least 2 data points for exponential smoothing
        if (count($data) < 2) {
            return ['error' => 'Not enough historical data'];
        }
        
        // Holt's double exponential smoothing implementation
        $alpha = 0.7; // Level smoothing factor
        $beta = 0.3;  // Trend smoothing factor
        
        $level = $data[0];
        $trend = $data[1] - $data[0];
        
        $smoothed = [$level];
        
        // Apply smoothing to historical data
        for ($i = 1; $i < count($data); $i++) {
            $prevLevel = $level;
            $level = $alpha * $data[$i] + (1 - $alpha) * ($level + $trend);
            $trend = $beta * ($level - $prevLevel) + (1 - $beta) * $trend;
            $smoothed[] = $level;
        }
        
        // Calculate forecast
        $forecast = [];
        $lastDate = new DateTime($this->dataCache[$term]['data'][count($data) - 1]['period']);
        
        for ($i = 1; $i <= $horizon; $i++) {
            $predictedValue = $level + $i * $trend;
            // Ensure prediction is not negative
            $predictedValue = max(0, $predictedValue);
            
            // Calculate date for this forecast point
            $forecastDate = clone $lastDate;
            
            switch ($timeframe) {
                case 'hourly':
                    $forecastDate->modify("+{$i} hour");
                    break;
                case 'daily':
                    $forecastDate->modify("+{$i} day");
                    break;
                case 'weekly':
                    $forecastDate->modify("+{$i} week");
                    break;
                case 'monthly':
                    $forecastDate->modify("+{$i} month");
                    break;
            }
            
            $forecast[] = [
                'period' => $forecastDate->format('Y-m-d'),
                'value' => round($predictedValue, 2)
            ];
        }
        
        return [
            'historical' => $this->dataCache[$term]['data'],
            'smoothed' => array_map(function($val) { return round($val, 2); }, $smoothed),
            'forecast' => $forecast,
            'model' => [
                'type' => 'double_exponential_smoothing',
                'alpha' => $alpha,
                'beta' => $beta
            ]
        ];
    }
    
    /**
     * Predict future trends using triple exponential smoothing (Holt-Winters)
     * 
     * @param string $term The search term
     * @param integer $horizon Number of periods to predict
     * @param string $timeframe Time aggregation level
     * @param integer $seasonLength Length of the seasonal pattern
     * @return array Predictions
     */
    public function predictTrendHoltWinters($term, $horizon = 30, $timeframe = 'daily', $seasonLength = 7) {
        // Get historical data if not already cached
        if (!isset($this->dataCache[$term]) || $this->dataCache[$term]['timeframe'] !== $timeframe) {
            $this->getHistoricalData($term, $timeframe, $seasonLength * 4); // Need at least a few seasons of data
        }
        
        if (empty($this->dataCache[$term]['data'])) {
            return ['error' => 'Not enough historical data'];
        }
        
        $data = array_column($this->dataCache[$term]['data'], 'count');
        
        // Need at least 2 full seasons for Holt-Winters
        if (count($data) < $seasonLength * 2) {
            return ['error' => 'Not enough historical data for seasonal analysis'];
        }
        
        // Holt-Winters triple exponential smoothing implementation
        $alpha = 0.7; // Level smoothing factor
        $beta = 0.3;  // Trend smoothing factor
        $gamma = 0.4; // Seasonal smoothing factor
        
        // Initialize seasonal components
        $seasonal = [];
        for ($i = 0; $i < $seasonLength; $i++) {
            $seasonal[$i] = 0;
            $count = 0;
            
            for ($j = $i; $j < count($data); $j += $seasonLength) {
                $seasonal[$i] += $data[$j];
                $count++;
            }
            
            if ($count > 0) {
                $seasonal[$i] /= $count;
            }
        }
        
        // Normalize seasonal components
        $seasonalSum = array_sum($seasonal);
        if ($seasonalSum > 0) {
            $seasonalFactor = $seasonLength / $seasonalSum;
            for ($i = 0; $i < $seasonLength; $i++) {
                $seasonal[$i] *= $seasonalFactor;
            }
        }
        
        // Initialize level and trend
        $level = $data[0];
        $trend = ($data[$seasonLength] - $data[0]) / $seasonLength;
        
        $smoothed = [$level * $seasonal[0]];
        
        // Apply smoothing to historical data
        for ($i = 1; $i < count($data); $i++) {
            $s = $i % $seasonLength;
            $prevLevel = $level;
            
            // Update level, trend, and seasonal components
            $level = $alpha * ($data[$i] / $seasonal[$s]) + (1 - $alpha) * ($prevLevel + $trend);
            $trend = $beta * ($level - $prevLevel) + (1 - $beta) * $trend;
            $seasonal[$s] = $gamma * ($data[$i] / $level) + (1 - $gamma) * $seasonal[$s];
            
            $smoothed[] = $level * $seasonal[$s];
        }
        
        // Calculate forecast
        $forecast = [];
        $lastDate = new DateTime($this->dataCache[$term]['data'][count($data) - 1]['period']);
        
        for ($i = 1; $i <= $horizon; $i++) {
            $s = ($count($data) + $i - 1) % $seasonLength;
            $predictedValue = ($level + $i * $trend) * $seasonal[$s];
            // Ensure prediction is not negative
            $predictedValue = max(0, $predictedValue);
            
            // Calculate date for this forecast point
            $forecastDate = clone $lastDate;
            
            switch ($timeframe) {
                case 'hourly':
                    $forecastDate->modify("+{$i} hour");
                    break;
                case 'daily':
                    $forecastDate->modify("+{$i} day");
                    break;
                case 'weekly':
                    $forecastDate->modify("+{$i} week");
                    break;
                case 'monthly':
                    $forecastDate->modify("+{$i} month");
                    break;
            }
            
            $forecast[] = [
                'period' => $forecastDate->format('Y-m-d'),
                'value' => round($predictedValue, 2)
            ];
        }
        
        return [
            'historical' => $this->dataCache[$term]['data'],
            'smoothed' => array_map(function($val) { return round($val, 2); }, $smoothed),
            'forecast' => $forecast,
            'model' => [
                'type' => 'holt_winters',
                'alpha' => $alpha,
                'beta' => $beta,
                'gamma' => $gamma,
                'season_length' => $seasonLength
            ]
        ];
    }
    
    /**
     * Perform statistical analysis on the trend data
     * 
     * @param string $term The search term
     * @param string $timeframe Time aggregation level
     * @return array Statistical analysis
     */
    public function analyzeStatistics($term, $timeframe = 'daily') {
        // Get historical data if not already cached
        if (!isset($this->dataCache[$term]) || $this->dataCache[$term]['timeframe'] !== $timeframe) {
            $this->getHistoricalData($term, $timeframe);
        }
        
        if (empty($this->dataCache[$term]['data'])) {
            return ['error' => 'Not enough historical data'];
        }
        
        $data = array_column($this->dataCache[$term]['data'], 'count');
        
        // Calculate basic statistics
        $count = count($data);
        $mean = array_sum($data) / $count;
        
        // Calculate variance and standard deviation
        $variance = 0;
        foreach ($data as $value) {
            $variance += pow($value - $mean, 2);
        }
        $variance /= $count;
        $stdDev = sqrt($variance);
        
        // Calculate median
        sort($data);
        $median = ($count % 2) ? $data[floor($count / 2)] : ($data[$count / 2 - 1] + $data[$count / 2]) / 2;
        
        // Calculate linear regression for trend analysis
        $x = range(0, $count - 1);
        $xSquared = array_map(function($val) { return pow($val, 2); }, $x);
        $xy = [];
        for ($i = 0; $i < $count; $i++) {
            $xy[] = $x[$i] * $data[$i];
        }
        
        $sumX = array_sum($x);
        $sumY = array_sum($data);
        $sumXY = array_sum($xy);
        $sumXSquared = array_sum($xSquared);
        
        $slope = ($count * $sumXY - $sumX * $sumY) / ($count * $sumXSquared - pow($sumX, 2));
        $intercept = ($sumY - $slope * $sumX) / $count;
        
        // Calculate moving averages
        $ma7 = $this->calculateMovingAverage($data, 7);
        $ma30 = $this->calculateMovingAverage($data, 30);
        
        // Calculate growth rate
        $growthRate = null;
        if ($count > 1 && $data[0] > 0) {
            $growthRate = (($data[$count - 1] / $data[0]) - 1) * 100;
        }
        
        // Detect outliers (Z-score > 3)
        $outliers = [];
        foreach ($data as $index => $value) {
            $zScore = abs(($value - $mean) / $stdDev);
            if ($zScore > 3) {
                $outliers[] = [
                    'index' => $index,
                    'period' => $this->dataCache[$term]['data'][$index]['period'],
                    'value' => $value,
                    'z_score' => $zScore
                ];
            }
        }
        
        return [
            'basic_stats' => [
                'count' => $count,
                'mean' => round($mean, 2),
                'median' => $median,
                'std_dev' => round($stdDev, 2),
                'min' => min($data),
                'max' => max($data),
                'range' => max($data) - min($data)
            ],
            'trend_analysis' => [
                'linear_regression' => [
                    'slope' => round($slope, 4),
                    'intercept' => round($intercept, 2),
                    'trend_direction' => $slope > 0 ? 'upward' : ($slope < 0 ? 'downward' : 'stable'),
                    'strength' => abs($slope) > 0.1 ? 'strong' : (abs($slope) > 0.01 ? 'moderate' : 'weak')
                ],
                'growth_rate' => $growthRate !== null ? round($growthRate, 2) . '%' : null
            ],
            'moving_averages' => [
                'ma7' => $ma7,
                'ma30' => $ma30
            ],
            'outliers' => $outliers
        ];
    }
    
    /**
     * Calculate moving average for an array of data
     * 
     * @param array $data Data points
     * @param integer $window Window size
     * @return array Moving averages
     */
    private function calculateMovingAverage($data, $window) {
        $result = [];
        
        for ($i = 0; $i < count($data); $i++) {
            if ($i < $window - 1) {
                $result[] = null;
                continue;
            }
            
            $sum = 0;
            for ($j = 0; $j < $window; $j++) {
                $sum += $data[$i - $j];
            }
            
            $result[] = round($sum / $window, 2);
        }
        
        return $result;
    }
    
    /**
     * Detect anomalies in the time series
     * 
     * @param string $term The search term
     * @param string $timeframe Time aggregation level
     * @return array Detected anomalies
     */
    public function detectAnomalies($term, $timeframe = 'daily') {
        // Get historical data if not already cached
        if (!isset($this->dataCache[$term]) || $this->dataCache[$term]['timeframe'] !== $timeframe) {
            $this->getHistoricalData($term, $timeframe);
        }
        
        if (empty($this->dataCache[$term]['data'])) {
            return ['error' => 'Not enough historical data'];
        }
        
        $data = array_column($this->dataCache[$term]['data'], 'count');
        $dates = array_column($this->dataCache[$term]['data'], 'period');
        
        // Calculate moving average and standard deviation
        $window = min(30, count($data) / 3);
        $window = max(7, $window); // Ensure window is at least 7
        
        $ma = $this->calculateMovingAverage($data, $window);
        
        // Calculate moving standard deviation
        $movingStdDev = [];
        for ($i = 0; $i < count($data); $i++) {
            if ($i < $window - 1) {
                $movingStdDev[] = null;
                continue;
            }
            
            $sum = 0;
            $meanForWindow = $ma[$i];
            
            for ($j = 0; $j < $window; $j++) {
                $sum += pow($data[$i - $j] - $meanForWindow, 2);
            }
            
            $movingStdDev[] = sqrt($sum / $window);
        }
        
        // Detect anomalies (values outside 3 standard deviations)
        $anomalies = [];
        for ($i = $window - 1; $i < count($data); $i++) {
            $upperBound = $ma[$i] + 3 * $movingStdDev[$i];
            $lowerBound = $ma[$i] - 3 * $movingStdDev[$i];
            
            if ($data[$i] > $upperBound || $data[$i] < $lowerBound) {
                $anomalies[] = [
                    'period' => $dates[$i],
                    'value' => $data[$i],
                    'expected' => round($ma[$i], 2),
                    'deviation' => round(($data[$i] - $ma[$i]) / $movingStdDev[$i], 2),
                    'type' => $data[$i] > $upperBound ? 'spike' : 'drop'
                ];
            }
        }
        
        return [
            'anomalies' => $anomalies,
            'analysis' => [
                'window_size' => $window,
                'moving_average' => array_slice($ma, $window - 1),
                'moving_std_dev' => array_map(function($val) { 
                    return $val !== null ? round($val, 2) : null; 
                }, array_slice($movingStdDev, $window - 1)),
                'periods' => array_slice($dates, $window - 1)
            ]
        ];
    }
    
    /**
     * Find correlations between different search terms
     * 
     * @param array $terms Array of search terms
     * @param string $timeframe Time aggregation level
     * @return array Correlation matrix
     */
    public function findCorrelations($terms, $timeframe = 'daily') {
        $termData = [];
        
        // Load data for all terms
        foreach ($terms as $term) {
            $data = $this->getHistoricalData($term, $timeframe);
            if (!empty($data)) {
                $termData[$term] = array_column($data, 'count');
            }
        }
        
        // Find the minimum length of all datasets
        $minLength = PHP_INT_MAX;
        foreach ($termData as $data) {
            $minLength = min($minLength, count($data));
        }
        
        // Truncate all datasets to the same length
        foreach ($termData as $term => $data) {
            $termData[$term] = array_slice($data, -$minLength);
        }
        
        // Calculate correlation matrix
        $correlations = [];
        
        foreach ($termData as $term1 => $data1) {
            $correlations[$term1] = [];
            
            foreach ($termData as $term2 => $data2) {
                // Pearson correlation coefficient
                $correlation = $this->calculatePearsonCorrelation($data1, $data2);
                $correlations[$term1][$term2] = round($correlation, 3);
            }
        }
        
        return $correlations;
    }
    
    /**
     * Calculate Pearson correlation coefficient between two data sets
     * 
     * @param array $x First data set
     * @param array $y Second data set
     * @return float Correlation coefficient
     */
    private function calculatePearsonCorrelation($x, $y) {
        $n = count($x);
        
        // Calculate means
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        
        // Calculate covariance and standard deviations
        $covariance = 0;
        $varX = 0;
        $varY = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $diffX = $x[$i] - $meanX;
            $diffY = $y[$i] - $meanY;
            
            $covariance += $diffX * $diffY;
            $varX += $diffX * $diffX;
            $varY += $diffY * $diffY;
        }
        
        $stdDevX = sqrt($varX);
        $stdDevY = sqrt($varY);
        
        // Avoid division by zero
        if ($stdDevX == 0 || $stdDevY == 0) {
            return 0;
        }
        
        return $covariance / ($stdDevX * $stdDevY);
    }
    
    /**
     * Linear regression forecast
     * 
     * @param string $term The search term
     * @param integer $horizon Number of periods to predict
     * @param string $timeframe Time aggregation level
     * @return array Prediction results
     */
    public function linearRegressionForecast($term, $horizon = 30, $timeframe = 'daily') {
        // Get historical data if not already cached
        if (!isset($this->dataCache[$term]) || $this->dataCache[$term]['timeframe'] !== $timeframe) {
            $this->getHistoricalData($term, $timeframe);
        }
        
        if (empty($this->dataCache[$term]['data'])) {
            return ['error' => 'Not enough historical data'];
        }
        
        $data = array_column($this->dataCache[$term]['data'], 'count');
        $n = count($data);
        
        // Calculate linear regression
        $x = range(0, $n - 1);
        $sumX = array_sum($x);
        $sumY = array_sum($data);
        
        $sumXY = 0;
        $sumXSquared = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $data[$i];
            $sumXSquared += $x[$i] * $x[$i];
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXSquared - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // Calculate fitted values
        $fitted = [];
        for ($i = 0; $i < $n; $i++) {
            $fitted[] = $intercept + $slope * $i;
        }
        
        // Calculate forecast
        $forecast = [];
        $lastDate = new DateTime($this->dataCache[$term]['data'][$n - 1]['period']);
        
        for ($i = 1; $i <= $horizon; $i++) {
            $forecastValue = $intercept + $slope * ($n - 1 + $i);
            // Ensure prediction is not negative
            $forecastValue = max(0, $forecastValue);
            
            // Calculate date for this forecast point
            $forecastDate = clone $lastDate;
            
            switch ($timeframe) {
                case 'hourly':
                    $forecastDate->modify("+{$i} hour");
                    break;
                case 'daily':
                    $forecastDate->modify("+{$i} day");
                    break;
                
                    case 'weekly':
                        $forecastDate->modify("+{$i} week");
                        break;
                    case 'monthly':
                        $forecastDate->modify("+{$i} month");
                        break;
                }
            
            $forecast[] = [
                'period' => $forecastDate->format('Y-m-d'),
                'value' => round($forecastValue, 2)
            ];
        }
        
        // Calculate R-squared (coefficient of determination)
        $meanY = $sumY / $n;
        $totalSumOfSquares = 0;
        $residualSumOfSquares = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $totalSumOfSquares += pow($data[$i] - $meanY, 2);
            $residualSumOfSquares += pow($data[$i] - $fitted[$i], 2);
        }
        
        $rSquared = 1 - ($residualSumOfSquares / $totalSumOfSquares);
        
        return [
            'historical' => $this->dataCache[$term]['data'],
            'fitted' => array_map(function($val) { return round($val, 2); }, $fitted),
            'forecast' => $forecast,
            'model' => [
                'type' => 'linear_regression',
                'slope' => round($slope, 4),
                'intercept' => round($intercept, 2),
                'r_squared' => round($rSquared, 4)
            ]
        ];
    }
    
    /**
     * Ensemble forecast combining multiple prediction methods
     * 
     * @param string $term The search term
     * @param integer $horizon Number of periods to predict
     * @param string $timeframe Time aggregation level
     * @return array Ensemble prediction
     */
    public function ensembleForecast($term, $horizon = 30, $timeframe = 'daily') {
        // Generate forecasts from multiple models
        $exponentialForecast = $this->predictTrendExponentialSmoothing($term, $horizon, $timeframe);
        $linearForecast = $this->linearRegressionForecast($term, $horizon, $timeframe);
        
        // Check for errors
        if (isset($exponentialForecast['error']) || isset($linearForecast['error'])) {
            return [
                'error' => 'One or more models failed to generate a forecast',
                'details' => [
                    'exponential' => isset($exponentialForecast['error']) ? $exponentialForecast['error'] : null,
                    'linear' => isset($linearForecast['error']) ? $linearForecast['error'] : null
                ]
            ];
        }
        
        // Try to add Holt-Winters if we have enough data
        $holtwintersForecast = $this->predictTrendHoltWinters($term, $horizon, $timeframe);
        $useHoltWinters = !isset($holtwintersForecast['error']);
        
        // Combine forecasts (simple average for now, could be weighted)
        $ensembleForecast = [];
        
        for ($i = 0; $i < $horizon; $i++) {
            $expValue = $exponentialForecast['forecast'][$i]['value'];
            $linValue = $linearForecast['forecast'][$i]['value'];
            
            if ($useHoltWinters) {
                $hwValue = $holtwintersForecast['forecast'][$i]['value'];
                $avgValue = ($expValue + $linValue + $hwValue) / 3;
            } else {
                $avgValue = ($expValue + $linValue) / 2;
            }
            
            $ensembleForecast[] = [
                'period' => $exponentialForecast['forecast'][$i]['period'],
                'value' => round($avgValue, 2),
                'exp_value' => $expValue,
                'lin_value' => $linValue,
                'hw_value' => $useHoltWinters ? $hwValue : null
            ];
        }
        
        // Set model weights based on historical performance
        $modelWeights = [
            'exponential' => 0.4,
            'linear' => 0.4,
            'holt_winters' => $useHoltWinters ? 0.2 : 0
        ];
        
        return [
            'historical' => $this->dataCache[$term]['data'],
            'forecast' => $ensembleForecast,
            'models_used' => [
                'exponential_smoothing' => true,
                'linear_regression' => true,
                'holt_winters' => $useHoltWinters
            ],
            'model_weights' => $modelWeights
        ];
    }
    
    /**
     * Create error-bounded prediction intervals
     * 
     * @param array $forecast Forecast data
     * @param float $confidenceLevel Confidence level (e.g., 0.95 for 95%)
     * @return array Forecast with prediction intervals
     */
    public function createPredictionIntervals($forecast, $confidenceLevel = 0.95) {
        if (empty($forecast) || !isset($forecast['historical']) || !isset($forecast['forecast'])) {
            return ['error' => 'Invalid forecast data'];
        }
        
        // Extract historical data for error analysis
        $historical = array_column($forecast['historical'], 'count');
        
        // If we have fitted values, use them to calculate prediction error
        if (isset($forecast['fitted'])) {
            $fitted = $forecast['fitted'];
        } else {
            // Otherwise, use simple moving average as a proxy
            $window = min(7, count($historical));
            $fitted = $this->calculateMovingAverage($historical, $window);
            // Fill in the first window-1 values with the first calculated MA
            $firstMA = $fitted[$window - 1];
            for ($i = 0; $i < $window - 1; $i++) {
                $fitted[$i] = $firstMA;
            }
        }
        
        // Calculate mean absolute error (MAE)
        $errors = [];
        for ($i = 0; $i < count($historical); $i++) {
            if ($fitted[$i] !== null) {
                $errors[] = abs($historical[$i] - $fitted[$i]);
            }
        }
        
        $mae = array_sum($errors) / count($errors);
        
        // Calculate standard deviation of errors
        $meanError = array_sum($errors) / count($errors);
        $varianceError = 0;
        
        foreach ($errors as $error) {
            $varianceError += pow($error - $meanError, 2);
        }
        
        $stdDevError = sqrt($varianceError / count($errors));
        
        // Calculate z-score for the given confidence level
        // Using approximation for normal distribution
        $zScore = 1.96; // For 95% confidence
        
        if ($confidenceLevel == 0.90) {
            $zScore = 1.645;
        } else if ($confidenceLevel == 0.99) {
            $zScore = 2.576;
        }
        
        // Create prediction intervals
        $forecastWithIntervals = [];
        
        foreach ($forecast['forecast'] as $index => $point) {
            // Interval width grows with forecast horizon to account for increasing uncertainty
            $intervalWidth = $stdDevError * $zScore * (1 + $index * 0.05);
            
            $forecastWithIntervals[] = [
                'period' => $point['period'],
                'value' => $point['value'],
                'lower_bound' => max(0, round($point['value'] - $intervalWidth, 2)),
                'upper_bound' => round($point['value'] + $intervalWidth, 2)
            ];
        }
        
        return [
            'historical' => $forecast['historical'],
            'forecast' => $forecastWithIntervals,
            'error_metrics' => [
                'mae' => round($mae, 2),
                'std_dev_error' => round($stdDevError, 2)
            ],
            'confidence_level' => $confidenceLevel
        ];
    }
    
    /**
     * Compare two search terms trend similarity
     * 
     * @param string $term1 First search term
     * @param string $term2 Second search term
     * @param string $timeframe Time aggregation level
     * @return array Similarity analysis
     */
    public function compareTrends($term1, $term2, $timeframe = 'daily') {
        // Get data for both terms
        $data1 = $this->getHistoricalData($term1, $timeframe);
        $data2 = $this->getHistoricalData($term2, $timeframe);
        
        if (empty($data1) || empty($data2)) {
            return ['error' => 'Insufficient data for one or both terms'];
        }
        
        // Find common date range
        $dates1 = array_column($data1, 'period');
        $dates2 = array_column($data2, 'period');
        
        $commonDates = array_intersect($dates1, $dates2);
        
        if (count($commonDates) < 7) {
            return ['error' => 'Insufficient overlapping data points'];
        }
        
        // Extract values for common dates
        $values1 = [];
        $values2 = [];
        
        foreach ($commonDates as $date) {
            $index1 = array_search($date, $dates1);
            $index2 = array_search($date, $dates2);
            
            $values1[] = $data1[$index1]['count'];
            $values2[] = $data2[$index2]['count'];
        }
        
        // Calculate correlation
        $correlation = $this->calculatePearsonCorrelation($values1, $values2);
        
        // Normalize both series for comparison
        $max1 = max($values1);
        $max2 = max($values2);
        
        $normalized1 = array_map(function($val) use ($max1) { 
            return $max1 > 0 ? $val / $max1 : 0; 
        }, $values1);
        
        $normalized2 = array_map(function($val) use ($max2) { 
            return $max2 > 0 ? $val / $max2 : 0; 
        }, $values2);
        
        // Calculate trend similarity metrics
        $euclideanDistance = 0;
        $dtw = $this->calculateDTW($normalized1, $normalized2);
        
        for ($i = 0; $i < count($normalized1); $i++) {
            $euclideanDistance += pow($normalized1[$i] - $normalized2[$i], 2);
        }
        $euclideanDistance = sqrt($euclideanDistance);
        
        // Calculate cosine similarity
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;
        
        for ($i = 0; $i < count($values1); $i++) {
            $dotProduct += $values1[$i] * $values2[$i];
            $magnitude1 += pow($values1[$i], 2);
            $magnitude2 += pow($values2[$i], 2);
        }
        
        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);
        $cosineSimilarity = ($magnitude1 * $magnitude2 > 0) ? 
            $dotProduct / ($magnitude1 * $magnitude2) : 0;
        
        // Calculate lead/lag relationship (cross-correlation)
        $maxLag = min(30, intval(count($values1) / 4));
        $crossCorrelation = [];
        $maxCrossCorr = ['lag' => 0, 'value' => $correlation];
        
        for ($lag = -$maxLag; $lag <= $maxLag; $lag++) {
            $lagged1 = [];
            $lagged2 = [];
            
            for ($i = 0; $i < count($values1); $i++) {
                $j = $i + $lag;
                if ($j >= 0 && $j < count($values1)) {
                    $lagged1[] = $values1[$i];
                    $lagged2[] = $values2[$j];
                }
            }
            
            if (count($lagged1) > 7) {
                $corrValue = $this->calculatePearsonCorrelation($lagged1, $lagged2);
                $crossCorrelation[] = ['lag' => $lag, 'value' => $corrValue];
                
                if ($corrValue > $maxCrossCorr['value']) {
                    $maxCrossCorr = ['lag' => $lag, 'value' => $corrValue];
                }
            }
        }
        
        // Determine which term leads/lags based on maximum cross-correlation
        $leadLagRelationship = [
            'status' => 'concurrent',
            'leader' => null,
            'lag_periods' => 0
        ];
        
        if (abs($maxCrossCorr['lag']) > 1 && $maxCrossCorr['value'] > $correlation * 1.1) {
            $leadLagRelationship = [
                'status' => 'lagged',
                'leader' => $maxCrossCorr['lag'] < 0 ? $term2 : $term1,
                'follower' => $maxCrossCorr['lag'] < 0 ? $term1 : $term2,
                'lag_periods' => abs($maxCrossCorr['lag'])
            ];
        }
        
        return [
            'common_date_range' => [
                'start' => min($commonDates),
                'end' => max($commonDates),
                'data_points' => count($commonDates)
            ],
            'similarity_metrics' => [
                'correlation' => round($correlation, 3),
                'correlation_strength' => $this->interpretCorrelation($correlation),
                'euclidean_distance' => round($euclideanDistance, 3),
                'dtw_distance' => round($dtw, 3),
                'cosine_similarity' => round($cosineSimilarity, 3)
            ],
            'lead_lag_relationship' => $leadLagRelationship,
            'max_cross_correlation' => [
                'lag' => $maxCrossCorr['lag'],
                'value' => round($maxCrossCorr['value'], 3)
            ],
            'comparative_stats' => [
                $term1 => [
                    'average' => round(array_sum($values1) / count($values1), 2),
                    'max' => max($values1),
                    'variance' => round($this->calculateVariance($values1), 2)
                ],
                $term2 => [
                    'average' => round(array_sum($values2) / count($values2), 2),
                    'max' => max($values2),
                    'variance' => round($this->calculateVariance($values2), 2)
                ]
            ]
        ];
    }
    
    /**
     * Calculate variance of a dataset
     * 
     * @param array $data Input data
     * @return float Variance
     */
    private function calculateVariance($data) {
        $mean = array_sum($data) / count($data);
        $variance = 0;
        
        foreach ($data as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        return $variance / count($data);
    }
    
    /**
     * Interpret correlation coefficient as strength description
     * 
     * @param float $correlation Correlation coefficient
     * @return string Interpretation
     */
    private function interpretCorrelation($correlation) {
        $absCorr = abs($correlation);
        
        if ($absCorr > 0.9) {
            return 'very strong ' . ($correlation > 0 ? 'positive' : 'negative');
        } else if ($absCorr > 0.7) {
            return 'strong ' . ($correlation > 0 ? 'positive' : 'negative');
        } else if ($absCorr > 0.5) {
            return 'moderate ' . ($correlation > 0 ? 'positive' : 'negative');
        } else if ($absCorr > 0.3) {
            return 'weak ' . ($correlation > 0 ? 'positive' : 'negative');
        } else {
            return 'negligible or no correlation';
        }
    }
    
    /**
     * Calculate Dynamic Time Warping distance
     * 
     * @param array $seq1 First sequence
     * @param array $seq2 Second sequence
     * @return float DTW distance
     */
    private function calculateDTW($seq1, $seq2) {
        $n = count($seq1);
        $m = count($seq2);
        
        // Initialize cost matrix
        $dtw = [];
        for ($i = 0; $i <= $n; $i++) {
            $dtw[$i] = [];
            for ($j = 0; $j <= $m; $j++) {
                $dtw[$i][$j] = INF;
            }
        }
        
        $dtw[0][0] = 0;
        
        // Fill the cost matrix
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = abs($seq1[$i-1] - $seq2[$j-1]);
                $dtw[$i][$j] = $cost + min($dtw[$i-1][$j], $dtw[$i][$j-1], $dtw[$i-1][$j-1]);
            }
        }
        
        return $dtw[$n][$m];
    }
    
    /**
     * Generate database schema
     */
    public static function generateDatabaseSchema() {
        return "
            -- Create search_terms table
            CREATE TABLE search_terms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term VARCHAR(255) NOT NULL,
                category VARCHAR(100) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY (term)
            );
            
            -- Create search_events table
            CREATE TABLE search_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term_id INT NOT NULL,
                timestamp DATETIME NOT NULL,
                metadata JSON NULL,
                FOREIGN KEY (term_id) REFERENCES search_terms(id)
            );
            
            -- Create hourly_stats table
            CREATE TABLE hourly_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term_id INT NOT NULL,
                period DATETIME NOT NULL,
                count INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY (term_id, period),
                FOREIGN KEY (term_id) REFERENCES search_terms(id)
            );
            
            -- Create daily_stats table
            CREATE TABLE daily_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term_id INT NOT NULL,
                period DATE NOT NULL,
                count INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY (term_id, period),
                FOREIGN KEY (term_id) REFERENCES search_terms(id)
            );
            
            -- Create weekly_stats table
            CREATE TABLE weekly_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term_id INT NOT NULL,
                period DATE NOT NULL,
                count INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY (term_id, period),
                FOREIGN KEY (term_id) REFERENCES search_terms(id)
            );
            
            -- Create monthly_stats table
            CREATE TABLE monthly_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term_id INT NOT NULL,
                period DATE NOT NULL,
                count INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY (term_id, period),
                FOREIGN KEY (term_id) REFERENCES search_terms(id)
            );
            
            -- Create prediction_models table
            CREATE TABLE prediction_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term_id INT NOT NULL,
                model_type VARCHAR(50) NOT NULL,
                timeframe VARCHAR(20) NOT NULL,
                parameters JSON NOT NULL,
                accuracy_metrics JSON NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (term_id) REFERENCES search_terms(id)
            );
            
            -- Create predictions table
            CREATE TABLE predictions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                period DATE NOT NULL,
                value FLOAT NOT NULL,
                lower_bound FLOAT NULL,
                upper_bound FLOAT NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (model_id) REFERENCES prediction_models(id)
            );
        ";
    }
}

/**
 * Class for data visualization
 */
class TrendVisualizer {
    private $trendPredictor;
    
    /**
     * Constructor
     * 
     * @param TrendPredictor $trendPredictor Trend predictor instance
     */
    public function __construct($trendPredictor) {
        $this->trendPredictor = $trendPredictor;
    }
    
    /**
     * Generate time series chart
     * 
     * @param string $term Search term
     * @param string $timeframe Time aggregation level
     * @return array Chart data
     */
    public function generateTimeSeriesChart($term, $timeframe = 'daily') {
        $data = $this->trendPredictor->getHistoricalData($term, $timeframe);
        
        if (empty($data)) {
            return ['error' => 'No data available for chart'];
        }
        
        // Prepare data for chart.js
        $labels = array_column($data, 'period');
        $values = array_column($data, 'count');
        
        // Calculate moving average
        $window = min(7, intval(count($values) / 5));
        $movingAvg = [];
        
        for ($i = 0; $i < count($values); $i++) {
            if ($i < $window - 1) {
                $movingAvg[] = null;
                continue;
            }
            
            $sum = 0;
            for ($j = 0; $j < $window; $j++) {
                $sum += $values[$i - $j];
            }
            
            $movingAvg[] = round($sum / $window, 2);
        }
        
        return [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => $term,
                        'data' => $values,
                        'borderColor' => 'rgba(54, 162, 235, 1)',
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'borderWidth' => 1,
                        'pointRadius' => 2
                    ],
                    [
                        'label' => "{$window}-point Moving Average",
                        'data' => $movingAvg,
                        'borderColor' => 'rgba(255, 99, 132, 1)',
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'borderWidth' => 2,
                        'pointRadius' => 0
                    ]
                ]
            ],
            'options' => [
                'responsive' => true,
                'title' => [
                    'display' => true,
                    'text' => "Search Trend for '{$term}'"
                ],
                'scales' => [
                    'xAxes' => [
                        [
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Date'
                            ]
                        ]
                    ],
                    'yAxes' => [
                        [
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Count'
                            ],
                            'ticks' => [
                                'beginAtZero' => true
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Generate forecast chart
     * 
     * @param array $forecast Forecast data
     * @param string $term Search term
     * @return array Chart data
     */
    public function generateForecastChart($forecast, $term) {
        if (empty($forecast) || isset($forecast['error'])) {
            return ['error' => 'No forecast data available for chart'];
        }
        
        $historicalData = $forecast['historical'];
        $forecastData = $forecast['forecast'];
        
        // Extract data points
        $historicalLabels = array_column($historicalData, 'period');
        $historicalValues = array_column($historicalData, 'count');
        
        $forecastLabels = array_column($forecastData, 'period');
        $forecastValues = array_column($forecastData, 'value');
        
        // Create datasets for chart.js
        $datasets = [
            [
                'label' => 'Historical',
                'data' => array_map(function($val, $label) {
                    return ['x' => $label, 'y' => $val];
                }, $historicalValues, $historicalLabels),
                'borderColor' => 'rgba(54, 162, 235, 1)',
                'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                'borderWidth' => 1,
                'pointRadius' => 2
            ],
            [
                'label' => 'Forecast',
                'data' => array_map(function($val, $label) {
                    return ['x' => $label, 'y' => $val];
                }, $forecastValues, $forecastLabels),
                'borderColor' => 'rgba(255, 99, 132, 1)',
                'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                'borderWidth' => 2,
                'pointRadius' => 2,
                'borderDash' => [5, 5]
            ]
        ];
        
        // Add confidence intervals if available
        if (isset($forecastData[0]['lower_bound']) && isset($forecastData[0]['upper_bound'])) {
            $lowerBounds = array_column($forecastData, 'lower_bound');
            $upperBounds = array_column($forecastData, 'upper_bound');
            
            $datasets[] = [
                'label' => 'Upper Bound',
                'data' => array_map(function($val, $label) {
                    return ['x' => $label, 'y' => $val];
                }, $upperBounds, $forecastLabels),
                'borderColor' => 'rgba(255, 99, 132, 0.5)',
                'backgroundColor' => 'rgba(0, 0, 0, 0)',
                'borderWidth' => 1,
                'pointRadius' => 0,
                'borderDash' => [2, 2]
            ];
            
            $datasets[] = [
                'label' => 'Lower Bound',
                'data' => array_map(function($val, $label) {
                    return ['x' => $label, 'y' => $val];
                }, $lowerBounds, $forecastLabels),
                'borderColor' => 'rgba(255, 99, 132, 0.5)',
                'backgroundColor' => 'rgba(255, 99, 132, 0.1)',
                'borderWidth' => 1,
                'pointRadius' => 0,
                'borderDash' => [2, 2],
                'fill' => '-1' // Fill between this dataset and the previous one
            ];
        }
        
        return [
            'type' => 'line',
            'data' => [
                'datasets' => $datasets
            ],
            'options' => [
                'responsive' => true,
                'title' => [
                    'display' => true,
                    'text' => "Forecast for '{$term}'"
                ],
                'scales' => [
                    'xAxes' => [
                        [
                            'type' => 'time',
                            'time' => [
                                'unit' => 'day'
                            ],
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Date'
                            ]
                        ]
                    ],
                    'yAxes' => [
                        [
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Count'
                            ],
                            'ticks' => [
                                'beginAtZero' => true
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Generate comparison chart for multiple terms
     * 
     * @param array $terms Array of search terms
     * @param string $timeframe Time aggregation level
     * @return array Chart data
     */
    public function generateComparisonChart($terms, $timeframe = 'daily') {
        if (empty($terms)) {
            return ['error' => 'No terms provided for comparison'];
        }
        
        $datasets = [];
        $allLabels = [];
        
        // Generate a different color for each term
        $colors = [
            'rgba(54, 162, 235, 1)',  // blue
            'rgba(255, 99, 132, 1)',  // red
            'rgba(75, 192, 192, 1)',  // green
            'rgba(255, 159, 64, 1)',  // orange
            'rgba(153, 102, 255, 1)', // purple
            'rgba(255, 205, 86, 1)',  // yellow
            'rgba(201, 203, 207, 1)'  // grey
        ];
        
        // Get data for each term
        foreach ($terms as $index => $term) {
            $data = $this->trendPredictor->getHistoricalData($term, $timeframe);
            
            if (!empty($data)) {
                $labels = array_column($data, 'period');
                $values = array_column($data, 'count');
                $allLabels = array_merge($allLabels, $labels);
                
                $colorIndex = $index % count($colors);
                
                $datasets[] = [
                    'label' => $term,
                    'data' => array_map(function($val, $label) {
                        return ['x' => $label, 'y' => $val];
                    }, $values, $labels),
                    'borderColor' => $colors[$colorIndex],
                    'backgroundColor' => str_replace('1)', '0.2)', $colors[$colorIndex]),
                    'borderWidth' => 2,
                    'pointRadius' => 1
                ];
            }
        }
        
        if (empty($datasets)) {
            return ['error' => 'No data available for comparison'];
        }
        
         // Sort all labels chronologically and remove duplicates
        $allLabels = array_unique($allLabels);
        sort($allLabels);
        
        return [
            'type' => 'line',
            'data' => [
                'datasets' => $datasets
            ],
            'options' => [
                'responsive' => true,
                'title' => [
                    'display' => true,
                    'text' => 'Term Comparison'
                ],
                'scales' => [
                    'xAxes' => [
                        [
                            'type' => 'time',
                            'time' => [
                                'unit' => 'day'
                            ],
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Date'
                            ]
                        ]
                    ],
                    'yAxes' => [
                        [
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Count'
                            ],
                            'ticks' => [
                                'beginAtZero' => true
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Generate heatmap for correlation matrix
     * 
     * @param array $correlations Correlation matrix
     * @return array Chart data
     */
    public function generateCorrelationHeatmap($correlations) {
        if (empty($correlations)) {
            return ['error' => 'No correlation data available for chart'];
        }
        
        $terms = array_keys($correlations);
        $data = [];
        
        foreach ($terms as $i => $term1) {
            foreach ($terms as $j => $term2) {
                $data[] = [
                    'x' => $term2,
                    'y' => $term1,
                    'v' => $correlations[$term1][$term2]
                ];
            }
        }
        
        return [
            'type' => 'heatmap',
            'data' => [
                'datasets' => [
                    [
                        'data' => $data,
                        'hoverBackgroundColor' => 'rgba(200, 200, 200, 1)',
                        'backgroundColor' => function($context) {
                            $value = $context['dataset']['data'][$context['dataIndex']]['v'];
                            
                            // Color scale from red (negative) to blue (positive)
                            if ($value < 0) {
                                $intensity = min(255, round(abs($value) * 255));
                                return "rgba({$intensity}, 0, 0, 0.7)";
                            } else {
                                $intensity = min(255, round($value * 255));
                                return "rgba(0, 0, {$intensity}, 0.7)";
                            }
                        }
                    ]
                ]
            },
            'options' => [
                'responsive' => true,
                'title' => [
                    'display' => true,
                    'text' => 'Term Correlation Heatmap'
                ],
                'scales' => [
                    'xAxes' => [
                        [
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Term'
                            ]
                        ]
                    ],
                    'yAxes' => [
                        [
                            'display' => true,
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Term'
                            ]
                        ]
                    ]
                ],
                'plugins' => [
                    'customColorScale' => [
                        'colorScale' => [
                            ['pos' => 0, 'color' => 'rgba(255, 0, 0, 0.7)'],  // Red for -1
                            ['pos' => 0.5, 'color' => 'rgba(255, 255, 255, 0.7)'], // White for 0
                            ['pos' => 1, 'color' => 'rgba(0, 0, 255, 0.7)']   // Blue for 1
                        ],
                        'min' => -1,
                        'max' => 1
                    ]
                ]
            ]
        ];
    }
}

/**
 * REST API for the trend prediction system
 */
class TrendPredictorAPI {
    private $trendPredictor;
    private $visualizer;
    
    /**
     * Constructor
     * 
     * @param TrendPredictor $trendPredictor Trend predictor instance
     */
    public function __construct($trendPredictor) {
        $this->trendPredictor = $trendPredictor;
        $this->visualizer = new TrendVisualizer($trendPredictor);
    }
    
    /**
     * Process API requests
     */
    public function processRequest() {
        // Get request method and path
        $method = $_SERVER['REQUEST_METHOD'];
        $path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';
        
        // Parse request body for POST/PUT requests
        $requestBody = null;
        if ($method === 'POST' || $method === 'PUT') {
            $requestBody = json_decode(file_get_contents('php://input'), true);
        }
        
        // Process request
        try {
            $response = $this->routeRequest($method, $path, $requestBody);
            $this->sendResponse(200, $response);
        } catch (Exception $e) {
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Route request to appropriate handler
     * 
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array $requestBody Request body (for POST/PUT)
     * @return array Response data
     */
    private function routeRequest($method, $path, $requestBody) {
        $pathParts = explode('/', trim($path, '/'));
        $baseResource = $pathParts[0] ?? '';
        
        switch ($baseResource) {
            case 'track':
                if ($method === 'POST') {
                    return $this->handleTrackEvent($requestBody);
                }
                break;
                
            case 'terms':
                if ($method === 'GET') {
                    return $this->handleGetTerms();
                }
                break;
                
            case 'data':
                if ($method === 'GET') {
                    $term = $pathParts[1] ?? null;
                    $timeframe = $pathParts[2] ?? 'daily';
                    return $this->handleGetData($term, $timeframe);
                }
                break;
                
            case 'forecast':
                if ($method === 'GET') {
                    $term = $pathParts[1] ?? null;
                    $model = $pathParts[2] ?? 'ensemble';
                    $timeframe = $pathParts[3] ?? 'daily';
                    $horizon = intval($pathParts[4] ?? 30);
                    return $this->handleGetForecast($term, $model, $timeframe, $horizon);
                }
                break;
                
            case 'analyze':
                if ($method === 'GET') {
                    $term = $pathParts[1] ?? null;
                    $timeframe = $pathParts[2] ?? 'daily';
                    return $this->handleAnalyzeStats($term, $timeframe);
                }
                break;
                
            case 'anomalies':
                if ($method === 'GET') {
                    $term = $pathParts[1] ?? null;
                    $timeframe = $pathParts[2] ?? 'daily';
                    return $this->handleDetectAnomalies($term, $timeframe);
                }
                break;
                
            case 'compare':
                if ($method === 'GET') {
                    $term1 = $pathParts[1] ?? null;
                    $term2 = $pathParts[2] ?? null;
                    $timeframe = $pathParts[3] ?? 'daily';
                    return $this->handleCompareTrends($term1, $term2, $timeframe);
                }
                break;
                
            case 'correlations':
                if ($method === 'POST') {
                    return $this->handleFindCorrelations($requestBody);
                }
                break;
                
            case 'chart':
                if ($method === 'GET') {
                    $chartType = $pathParts[1] ?? null;
                    return $this->handleGenerateChart($chartType, array_slice($pathParts, 2));
                }
                break;
        }
        
        return ['error' => 'Invalid API endpoint or method'];
    }
    
    /**
     * Handle track event request
     * 
     * @param array $requestBody Request body
     * @return array Response data
     */
    private function handleTrackEvent($requestBody) {
        if (!isset($requestBody['term'])) {
            return ['error' => 'Missing required parameter: term'];
        }
        
        $term = $requestBody['term'];
        $category = $requestBody['category'] ?? null;
        $metadata = $requestBody['metadata'] ?? [];
        
        $result = $this->trendPredictor->trackEvent($term, $category, $metadata);
        
        return ['success' => $result];
    }
    
    /**
     * Handle get terms request
     * 
     * @return array Response data
     */
    private function handleGetTerms() {
        // This would need to be implemented in the TrendPredictor class
        return ['message' => 'Feature not implemented yet'];
    }
    
    /**
     * Handle get data request
     * 
     * @param string $term Search term
     * @param string $timeframe Time aggregation level
     * @return array Response data
     */
    private function handleGetData($term, $timeframe) {
        if (!$term) {
            return ['error' => 'Missing required parameter: term'];
        }
        
        $data = $this->trendPredictor->getHistoricalData($term, $timeframe);
        
        return ['term' => $term, 'timeframe' => $timeframe, 'data' => $data];
    }
    
    /**
     * Handle get forecast request
     * 
     * @param string $term Search term
     * @param string $model Forecast model type
     * @param string $timeframe Time aggregation level
     * @param integer $horizon Forecast horizon
     * @return array Response data
     */
    private function handleGetForecast($term, $model, $timeframe, $horizon) {
        if (!$term) {
            return ['error' => 'Missing required parameter: term'];
        }
        
        $forecast = null;
        
        switch ($model) {
            case 'exponential':
                $forecast = $this->trendPredictor->predictTrendExponentialSmoothing($term, $horizon, $timeframe);
                break;
                
            case 'linear':
                $forecast = $this->trendPredictor->linearRegressionForecast($term, $horizon, $timeframe);
                break;
                
            case 'holtwinters':
                $forecast = $this->trendPredictor->predictTrendHoltWinters($term, $horizon, $timeframe);
                break;
                
            case 'ensemble':
                $forecast = $this->trendPredictor->ensembleForecast($term, $horizon, $timeframe);
                break;
                
            default:
                return ['error' => 'Invalid model type'];
        }
        
        // Add confidence intervals
        if ($forecast && !isset($forecast['error'])) {
            $forecast = $this->trendPredictor->createPredictionIntervals($forecast);
        }
        
        return $forecast;
    }
    
    /**
     * Handle analyze stats request
     * 
     * @param string $term Search term
     * @param string $timeframe Time aggregation level
     * @return array Response data
     */
    private function handleAnalyzeStats($term, $timeframe) {
        if (!$term) {
            return ['error' => 'Missing required parameter: term'];
        }
        
        $stats = $this->trendPredictor->analyzeStatistics($term, $timeframe);
        
        return $stats;
    }
    
    /**
     * Handle detect anomalies request
     * 
     * @param string $term Search term
     * @param string $timeframe Time aggregation level
     * @return array Response data
     */
    private function handleDetectAnomalies($term, $timeframe) {
        if (!$term) {
            return ['error' => 'Missing required parameter: term'];
        }
        
        $anomalies = $this->trendPredictor->detectAnomalies($term, $timeframe);
        
        return $anomalies;
    }
    
    /**
     * Handle compare trends request
     * 
     * @param string $term1 First search term
     * @param string $term2 Second search term
     * @param string $timeframe Time aggregation level
     * @return array Response data
     */
    private function handleCompareTrends($term1, $term2, $timeframe) {
        if (!$term1 || !$term2) {
            return ['error' => 'Missing required parameters: term1 and term2'];
        }
        
        $comparison = $this->trendPredictor->compareTrends($term1, $term2, $timeframe);
        
        return $comparison;
    }
    
    /**
     * Handle find correlations request
     * 
     * @param array $requestBody Request body
     * @return array Response data
     */
    private function handleFindCorrelations($requestBody) {
        if (!isset($requestBody['terms']) || !is_array($requestBody['terms'])) {
            return ['error' => 'Missing required parameter: terms (array)'];
        }
        
        $terms = $requestBody['terms'];
        $timeframe = $requestBody['timeframe'] ?? 'daily';
        
        $correlations = $this->trendPredictor->findCorrelations($terms, $timeframe);
        
        return ['correlations' => $correlations];
    }
    
    /**
     * Handle generate chart request
     * 
     * @param string $chartType Chart type
     * @param array $params Additional parameters
     * @return array Response data
     */
    private function handleGenerateChart($chartType, $params) {
        switch ($chartType) {
            case 'timeseries':
                $term = $params[0] ?? null;
                $timeframe = $params[1] ?? 'daily';
                
                if (!$term) {
                    return ['error' => 'Missing required parameter: term'];
                }
                
                return $this->visualizer->generateTimeSeriesChart($term, $timeframe);
                
            case 'forecast':
                $term = $params[0] ?? null;
                $model = $params[1] ?? 'ensemble';
                $timeframe = $params[2] ?? 'daily';
                $horizon = intval($params[3] ?? 30);
                
                if (!$term) {
                    return ['error' => 'Missing required parameter: term'];
                }
                
                $forecast = $this->handleGetForecast($term, $model, $timeframe, $horizon);
                return $this->visualizer->generateForecastChart($forecast, $term);
                
            case 'comparison':
                if (empty($params)) {
                    return ['error' => 'Missing required parameters: terms'];
                }
                
                $timeframe = 'daily';
                $terms = [];
                
                foreach ($params as $param) {
                    if ($param === 'daily' || $param === 'weekly' || $param === 'monthly') {
                        $timeframe = $param;
                    } else {
                        $terms[] = $param;
                    }
                }
                
                if (empty($terms)) {
                    return ['error' => 'Missing required parameters: terms'];
                }
                
                return $this->visualizer->generateComparisonChart($terms, $timeframe);
                
            case 'correlation':
                if (empty($params)) {
                    return ['error' => 'Missing required parameters: terms'];
                }
                
                $timeframe = 'daily';
                $terms = [];
                
                foreach ($params as $param) {
                    if ($param === 'daily' || $param === 'weekly' || $param === 'monthly') {
                        $timeframe = $param;
                    } else {
                        $terms[] = $param;
                    }
                }
                
                if (count($terms) < 2) {
                    return ['error' => 'At least 2 terms required for correlation heatmap'];
                }
                
                $correlations = $this->trendPredictor->findCorrelations($terms, $timeframe);
                return $this->visualizer->generateCorrelationHeatmap($correlations);
                
            default:
                return ['error' => 'Invalid chart type'];
        }
    }
    
    /**
     * Send HTTP response
     * 
     * @param integer $statusCode HTTP status code
     * @param array $data Response data
     */
    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

/**
 * Web UI for the trend prediction system
 */
class TrendPredictorUI {
    private $trendPredictor;
    private $visualizer;
    
    /**
     * Constructor
     * 
     * @param TrendPredictor $trendPredictor Trend predictor instance
     */
    public function __construct($trendPredictor) {
        $this->trendPredictor = $trendPredictor;
        $this->visualizer = new TrendVisualizer($trendPredictor);
    }
    
    /**
     * Render dashboard page
     * 
     * @return string HTML content
     */
    public function renderDashboard() {
        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Trend Predictor Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment@1.0.0/dist/chartjs-adapter-moment.min.js"></script>
    <style>
        .card {
            margin-bottom: 20px;
        }
        .chart-container {
            height: 400px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <h1 class="mt-4 mb-4">Search Trend Predictor Dashboard</h1>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header">
                        Search Term Analysis
                    </div>
                    <div class="card-body">
                        <form id="termForm">
                            <div class="mb-3">
                                <label for="term" class="form-label">Search Term</label>
                                <input type="text" class="form-control" id="term" required>
                            </div>
                            <div class="mb-3">
                                <label for="timeframe" class="form-label">Timeframe</label>
                                <select class="form-select" id="timeframe">
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="forecastModel" class="form-label">Forecast Model</label>
                                <select class="form-select" id="forecastModel">
                                    <option value="ensemble">Ensemble (Recommended)</option>
                                    <option value="exponential">Exponential Smoothing</option>
                                    <option value="linear">Linear Regression</option>
                                    <option value="holtwinters">Holt-Winters</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="horizon" class="form-label">Forecast Horizon</label>
                                <input type="number" class="form-control" id="horizon" value="30" min="7" max="365">
                            </div>
                            <button type="submit" class="btn btn-primary">Analyze</button>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        Term Comparison
                    </div>
                    <div class="card-body">
                        <form id="comparisonForm">
                            <div class="mb-3">
                                <label for="compTerms" class="form-label">Search Terms (comma separated)</label>
                                <input type="text" class="form-control" id="compTerms" required>
                            </div>
                            <div class="mb-3">
                                <label for="compTimeframe" class="form-label">Timeframe</label>
                                <select class="form-select" id="compTimeframe">
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="showCorrelation">
                                    <label class="form-check-label" for="showCorrelation">
                                        Show Correlation Heatmap
                                    </label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Compare</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-9">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 id="chartTitle">Time Series & Forecast</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="forecastChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Statistical Analysis</h5>
                            </div>
                            <div class="card-body">
                                <div id="statsContainer">
                                    <p class="text-muted">Select a term to view statistics</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Anomalies</h5>
                            </div>
                            <div class="card-body">
                                <div id="anomaliesContainer">
                                    <p class="text-muted">Select a term to view anomalies</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5>Term Correlation</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="correlationChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Charts
        let forecastChart = null;
        let correlationChart = null;
        
        // Event listeners
        document.getElementById('termForm').addEventListener('submit', function(e) {
            e.preventDefault();
            analyzeTerm();
        });
        
        document.getElementById('comparisonForm').addEventListener('submit', function(e) {
            e.preventDefault();
            compareTerms();
        });
        
        // Analyze a single term
        function analyzeTerm() {
            const term = document.getElementById('term').value;
            const timeframe = document.getElementById('timeframe').value;
            const model = document.getElementById('forecastModel').value;
            const horizon = document.getElementById('horizon').value;
            
            // API calls
            Promise.all([
                fetch(`/api/chart/forecast/${term}/${model}/${timeframe}/${horizon}`).then(res => res.json()),
                fetch(`/api/analyze/${term}/${timeframe}`).then(res => res.json()),
                fetch(`/api/anomalies/${term}/${timeframe}`).then(res => res.json())
            ])
            .then(([chartData, statsData, anomaliesData]) => {
                // Update chart
                updateForecastChart(chartData, `${term} - Forecast (${model})`);
                
                // Update stats
                updateStats(statsData);
                
                // Update anomalies
                updateAnomalies(anomaliesData);
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while analyzing the term.');
            });
        }
        
        // Compare multiple terms
        function compareTerms() {
            const terms = document.getElementById('compTerms').value.split(',').map(t => t.trim());
            const timeframe = document.getElementById('compTimeframe').value;
            const showCorrelation = document.getElementById('showCorrelation').checked;
            
            if (terms.length < 2) {
                alert('Please enter at least two terms for comparison.');
                return;
            }
            
            // Build the API URL for comparison chart
            let comparisonUrl = '/api/chart/comparison/';
            comparisonUrl += terms.join('/') + '/' + timeframe;
            
            // Make API requests
            Promise.all([
                fetch(comparisonUrl).then(res => res.json()),
                showCorrelation ? 
                    fetch('/api/chart/correlation/' + terms.join('/') + '/' + timeframe).then(res => res.json()) : 
                    Promise.resolve(null)
            ])
            .then(([comparisonData, correlationData]) => {
                // Update comparison chart
                updateForecastChart(comparisonData, 'Term Comparison');
                
                // Update correlation chart if requested
                if (showCorrelation && correlationData) {
                    updateCorrelationChart(correlationData);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while comparing the terms.');
            });
        }
        
        // Update forecast chart
        function updateForecastChart(chartData, title) {
            const ctx = document.getElementById('forecastChart').getContext('2d');
            
            if (forecastChart) {
                forecastChart.destroy();
            }
            
            document.getElementById('chartTitle').textContent = title;
            
            forecastChart = new Chart(ctx, {
                type: chartData.type,
                data: chartData.data,
                options: chartData.options
            });
        }
        
        // Update correlation chart
        function updateCorrelationChart(chartData) {
            const ctx = document.getElementById('correlationChart').getContext('2d');
            
            if (correlationChart) {
                correlationChart.destroy();
            }
            
            correlationChart = new Chart(ctx, {
                type: chartData.type,
                data: chartData.data,
                options: chartData.options
            });
        }
        
        // Update statistics display
        function updateStats(statsData) {
            const container = document.getElementById('statsContainer');
            
            if (statsData.error) {
                container.innerHTML = `<div class="alert alert-warning">${statsData.error}</div>`;
                return;
            }
            
            let html = '<div class="row">';
            
            // Basic stats
            html += '<div class="col-md-6">';
            html += '<h6>Basic Statistics</h6>';
            html += '<table class="table table-sm">';
            html += '<tbody>';
            Object.entries(statsData.basic_stats).forEach(([key, value]) => {
                html += `<tr><td>${key.replace('_', ' ')}</td><td>${value}</td></tr>`;
            });
            html += '</tbody></table>';
            html += '</div>';
            
            // Trend analysis
            html += '<div class="col-md-6">';
            html += '<h6>Trend Analysis</h6>';
            html += '<table class="table table-sm">';
            html += '<tbody>';
            Object.entries(statsData.trend_analysis.linear_regression).forEach(([key, value]) => {
                html += `<tr><td>${key.replace('_', ' ')}</td><td>${value}</td></tr>`;
            });
            if (statsData.trend_analysis.growth_rate) {
                html += `<tr><td>growth rate</td><td>${statsData.trend_analysis.growth_rate}</td></tr>`;
            }
            html += '</tbody></table>';
            html += '</div>';
            
            html += '</div>'; // End row
            
            container.innerHTML = html;
        }
        
        // Update anomalies display
        function updateAnomalies(anomaliesData) {
            const container = document.getElementById('anomaliesContainer');
            
            if (anomaliesData.error) {
                container.innerHTML = `<div class="alert alert-warning">${anomaliesData.error}</div>`;
                return;
            }
            
            if (anomaliesData.anomalies.length === 0) {
                container.innerHTML = '<p>No anomalies detected.</p>';
                return;
            }
            
            let html = '<table class="table table-sm">';
            
            html += '<thead><tr><th>Date</th><th>Value</th><th>Expected</th><th>Deviation</th><th>Type</th></tr></thead>';
            html += '<tbody>';
            
            anomaliesData.anomalies.forEach(anomaly => {
                const typeClass = anomaly.type === 'spike' ? 'text-danger' : 'text-warning';
                html += `<tr>
                    <td>${anomaly.period}</td>
                    <td>${anomaly.value}</td>
                    <td>${anomaly.expected}</td>
                    <td>${anomaly.deviation}</td>
                    <td class="${typeClass}">${anomaly.type}</td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            
            container.innerHTML = html;
        }
    </script>
</body>
</html>
<?php
        return ob_get_clean();
    }
}

/**
 * Sample config file content
 */
function createConfigFile() {
    $content = '<?php
/**
 * Configuration for TrendPredictor
 */

// Database configuration
define("DB_HOST", "localhost");
define("DB_NAME", "trend_predictor");
define("DB_USER", "root");
define("DB_PASS", "");

// API settings
define("API_KEY", "your_api_key_here"); // For secure API access
define("ALLOW_CORS", true);             // Allow cross-origin requests

// Application settings
define("DEFAULT_TIMEFRAME", "daily");
define("DEFAULT_FORECAST_HORIZON", 30);
define("DEFAULT_FORECAST_MODEL", "ensemble");
define("MAX_TERMS_PER_REQUEST", 10);    // Limit for term comparison/correlation
';
    
    return $content;
}

/**
 * Sample index.php file for the API
 */
function createAPIIndexFile() {
    $content = '<?php
/**
 * TrendPredictor API
 * 
 * This is the main entry point for the API
 */

// Include necessary files
require_once "config.php";
require_once "TrendPredictor.php";

// Set headers for API
header("Content-Type: application/json");

// Handle CORS if enabled
if (defined("ALLOW_CORS") && ALLOW_CORS) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    
    // Handle preflight OPTIONS request
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(200);
        exit;
    }
}

// Check API key (if required)
if (defined("API_KEY") && !empty(API_KEY)) {
    $authHeader = isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "";
    $apiKey = str_replace("Bearer ", "", $authHeader);
    
    if ($apiKey !== API_KEY) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized: Invalid API key"]);
        exit;
    }
}

// Initialize TrendPredictor
$trendPredictor = new TrendPredictor();

// Initialize and process API request
$api = new TrendPredictorAPI($trendPredictor);
$api->processRequest();
';
    
    return $content;
}

/**
 * Sample index.php file for the Web UI
 */
function createWebUIIndexFile() {
    $content = '<?php
/**
 * TrendPredictor Web UI
 * 
 * This is the main entry point for the web interface
 */

// Include necessary files
require_once "config.php";
require_once "TrendPredictor.php";

// Initialize TrendPredictor
$trendPredictor = new TrendPredictor();

// Initialize Web UI
$ui = new TrendPredictorUI($trendPredictor);

// Output the dashboard
echo $ui->renderDashboard();
';
    
    return $content;
}

/**
 * Sample data collection script for integrating with web apps
 */
function createDataCollectorScript() {
    $content = '<?php
/**
 * Search/View Event Collector
 * 
 * This script can be included in web applications to track
 * search terms and content views
 */

// Include necessary files
require_once "config.php";
require_once "TrendPredictor.php";

/**
 * Track a search or view event
 * 
 * @param string $term The search term or content identifier
 * @param string $category Category of the content (optional)
 * @param array $metadata Additional metadata (optional)
 * @return boolean Success status
 */
function trackSearchEvent($term, $category = null, $metadata = []) {
    // Add user agent and IP info to metadata
    $metadata["user_agent"] = $_SERVER["HTTP_USER_AGENT"] ?? null;
    $metadata["ip_hash"] = md5($_SERVER["REMOTE_ADDR"] ?? "unknown"); // Hashed for privacy
    
    // Initialize TrendPredictor
    $trendPredictor = new TrendPredictor();
    
    // Track the event
    return $trendPredictor->trackEvent($term, $category, $metadata);
}

/**
 * JavaScript snippet for tracking on the client side
 * 
 * @return string JavaScript code
 */
function getTrackingJavaScript() {
    $apiEndpoint = "/api/track";
    
    $js = <<<EOT
<script>
    function trackSearch(term, category, metadata) {
        if (!term) return;
        
        // Add timestamp and page URL
        const data = {
            term: term,
            category: category || null,
            metadata: Object.assign({
                timestamp: new Date().toISOString(),
                page_url: window.location.href,
                referrer: document.referrer
            }, metadata || {})
        };
        
        // Send data to the API endpoint
        fetch("{$apiEndpoint}", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(data),
            // Use beacon for better reliability when page is unloading
            keepalive: true
        }).catch(err => console.error("Error tracking search:", err));
    }
    
    // Hook into search forms automatically
    document.addEventListener("DOMContentLoaded", function() {
        const searchForms = document.querySelectorAll("form[role=search], form.search-form");
        
        searchForms.forEach(form => {
            form.addEventListener("submit", function(e) {
                const searchInput = form.querySelector("input[type=search], input[name=s], input[name=q], input[name=query]");
                
                if (searchInput && searchInput.value) {
                    trackSearch(searchInput.value, "search");
                }
            });
        });
    });
</script>
EOT;
    
    return $js;
}
';
    
    return $content;
}

/**
 * Sample installation script
 */
function createInstallationScript() {
    $content = '<?php
/**
 * TrendPredictor Installation Script
 * 
 * This script sets up the database schema and initial configuration
 */

// Include necessary files
require_once "config.php";
require_once "TrendPredictor.php";

echo "Starting TrendPredictor installation...\n";

// Connect to MySQL server (without selecting database)
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    echo "Connected to MySQL server successfully.\n";
    
    // Create database if it doesn\'t exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    echo "Database \'".DB_NAME."\' created or already exists.\n";
    
    // Select the database
    $pdo->exec("USE " . DB_NAME);
    
    echo "Selected database \'".DB_NAME."\'.\n";
    
    // Get the database schema from TrendPredictor
    $schema = TrendPredictor::generateDatabaseSchema();
    
    echo "Generated database schema.\n";
    
    // Execute the schema creation SQL
    $pdo->exec($schema);
    
    echo "Created database tables.\n";
    
    // Create indexes for better performance
    $pdo->exec("CREATE INDEX idx_search_events_timestamp ON search_events(timestamp)");
    $pdo->exec("CREATE INDEX idx_search_terms_category ON search_terms(category)");
    
    echo "Created database indexes.\n";
    
    // Everything was successful
    echo "Installation completed successfully!\n";
    echo "You can now use the TrendPredictor system.\n";
    
} catch (PDOException $e) {
    echo "Error during installation: " . $e->getMessage() . "\n";
}
';
    
    return $content;
}

/**
 * Sample cron job for training prediction models
 */
function createCronJobScript() {
    $content = '<?php
/**
 * TrendPredictor Cron Job
 * 
 * This script should be run via cron to update prediction models
 * and cache forecast results
 * 
 * Recommended schedule: Once per day
 */

// Include necessary files
require_once "config.php";
require_once "TrendPredictor.php";

/**
 * Get the top search terms to model
 * 
 * @param PDO $db Database connection
 * @param integer $limit Maximum number of terms
 * @return array Top terms
 */
function getTopTerms($db, $limit = 100) {
    try {
        // Get terms with the most events in the last 30 days
        $stmt = $db->prepare("
            SELECT t.id, t.term, COUNT(*) as event_count
            FROM search_terms t
            JOIN search_events e ON t.id = e.term_id
            WHERE e.timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY t.id, t.term
            ORDER BY event_count DESC
            LIMIT :limit
        ");
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        echo "Error getting top terms: " . $e->getMessage() . "\n";
        return [];
    }
}

/**
 * Main cron job function
 */
function runCronJob() {
    echo "Starting TrendPredictor cron job at " . date("Y-m-d H:i:s") . "\n";
    
    try {
        // Connect to database
        $db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        
        echo "Connected to database.\n";
        
        // Get top terms
        $topTerms = getTopTerms($db, 100);
        
        echo "Retrieved " . count($topTerms) . " top terms.\n";
        
        // Initialize TrendPredictor
        $trendPredictor = new TrendPredictor();
        
        // Process each term
        foreach ($topTerms as $index => $term) {
            echo "Processing term " . ($index + 1) . "/" . count($topTerms) . ": " . $term["term"] . "\n";
            
            try {
                // Generate and store predictions for different timeframes
                $timeframes = ["daily", "weekly", "monthly"];
                
                foreach ($timeframes as $timeframe) {
                    echo "  Generating {$timeframe} forecast...\n";
                    
                    // Get forecast
                    $forecast = $trendPredictor->ensembleForecast($term["term"], 90, $timeframe);
                    
                    if (!isset($forecast["error"])) {
                        // Add prediction intervals
                        $forecastWithIntervals = $trendPredictor->createPredictionIntervals($forecast);
                        
                        // Store prediction model
                        storePredictionModel($db, $term["id"], "ensemble", $timeframe, $forecastWithIntervals);
                        
                        echo "  Stored {$timeframe} forecast successfully.\n";
                    } else {
                        echo "  Error generating {$timeframe} forecast: " . $forecast["error"] . "\n";
                    }
                }
            } catch (Exception $e) {
                echo "  Error processing term: " . $e->getMessage() . "\n";
            }
        }
        
        echo "Cron job completed successfully at " . date("Y-m-d H:i:s") . "\n";
    } catch (Exception $e) {
        echo "Error in cron job: " . $e->getMessage() . "\n";
    }
}

/**
 * Store prediction model in the database
 * 
 * @param PDO $db Database connection
 * @param integer $termId Term ID
 * @param string $modelType Model type
 * @param string $timeframe Time aggregation level
 * @param array $forecast Forecast data
 * @return boolean Success status
 */
function storePredictionModel($db, $termId, $modelType, $timeframe, $forecast) {
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Insert/update model
        $stmt = $db->prepare("
            INSERT INTO prediction_models 
                (term_id, model_type, timeframe, parameters, accuracy_metrics, created_at)
            VALUES 
                (:term_id, :model_type, :timeframe, :parameters, :accuracy_metrics, NOW())
            ON DUPLICATE KEY UPDATE
                parameters = :parameters,
                accuracy_metrics = :accuracy_metrics,
                created_at = NOW()
        ");
        
        $parameters = json_encode($forecast["model"] ?? []);
        $accuracyMetrics = json_encode($forecast["error_metrics"] ?? []);
        
        $stmt->bindParam(":term_id", $termId);
        $stmt->bindParam(":model_type", $modelType);
        $stmt->bindParam(":timeframe", $timeframe);
        $stmt->bindParam(":parameters", $parameters);
        $stmt->bindParam(":accuracy_metrics", $accuracyMetrics);
        $stmt->execute();
        
        // Get model ID
        $modelId = $db->lastInsertId();
        
        // Clear existing predictions
        $stmt = $db->prepare("DELETE FROM predictions WHERE model_id = :model_id");
        $stmt->bindParam(":model_id", $modelId);
        $stmt->execute();
        
        // Insert predictions
        $stmt = $db->prepare("
            INSERT INTO predictions 
                (model_id, period, value, lower_bound, upper_bound, created_at)
            VALUES 
                (:model_id, :period, :value, :lower_bound, :upper_bound, NOW())
        ");
        
        foreach ($forecast["forecast"] as $point) {
            $stmt->bindParam(":model_id", $modelId);
            $stmt->bindParam(":period", $point["period"]);
            $stmt->bindParam(":value", $point["value"]);
            $stmt->bindParam(":lower_bound", $point["lower_bound"] ?? null);
            $stmt->bindParam(":upper_bound", $point["upper_bound"] ?? null);
            $stmt->execute();
        }
        
        // Commit transaction
        $db->commit();
        
        return true;
    } catch (PDOException $e) {
        // Rollback transaction on error
        $db->rollBack();
        echo "Error storing prediction model: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run the cron job
runCronJob();
';
    
    return $content;
}

/**
 * Usage instructions
 */
function getUsageInstructions() {
    $instructions = '
/**
 * TrendPredictor System - Usage Instructions
 * 
 * This system provides advanced time series analysis and prediction
 * for search term and content view data.
 * 
 * Installation:
 * -------------
 * 1. Configure the database settings in config.php
 * 2. Run install.php to create the database schema
 * 3. Set up a cron job to run cron.php daily for prediction model updates
 * 
 * System Components:
 * -----------------
 * - TrendPredictor.php: Core class with prediction algorithms
 * - api/index.php: REST API endpoints for integration
 * - web/index.php: Web UI dashboard
 * - collector.php: Script for tracking search/view events
 * - cron.php: Automated job for updating prediction models
 * 
 * Integration Examples:
 * --------------------
 * 
 * 1. Track a search term:
 * 
 * require_once "collector.php";
 * trackSearchEvent("machine learning", "technology", ["user_id" => 123]);
 * 
 * 2. Add tracking to a search form:
 * 
 * require_once "collector.php";
 * echo getTrackingJavaScript();
 * 
 * 3. Get predictions via API:
 * 
 * GET /api/forecast/artificial+intelligence/ensemble/daily/30
 * 
 * 4. Generate a chart via API:
 * 
 * GET /api/chart/forecast/artificial+intelligence/ensemble/daily/30
 * 
 * 5. Compare multiple terms:
 * 
 * GET /api/compare/machine+learning/deep+learning/daily
 * 
 * Mathematical Models Used:
 * ------------------------
 * 
 * 1. Double Exponential Smoothing (Holt\'s method)
 *    - Optimal for short-term forecasting
 *    - Captures level and trend components
 *    - Alpha (level) and Beta (trend) parameters control smoothing
 * 
 * 2. Triple Exponential Smoothing (Holt-Winters)
 *    - Extends double smoothing to include seasonality
 *    - Gamma parameter controls seasonal smoothing
 *    - Multiplicative or additive seasonal components
 * 
 * 3. Linear Regression
 *    - Fits a linear trend to historical data
 *    - Calculates slope and intercept
 *    - R-squared metric indicates fit quality
 * 
 * 4. Ensemble Model
 *    - Combines multiple forecasting methods
 *    - Weighted average based on historical accuracy
 *    - More robust to different patterns in data
 * 
 * 5. ARIMA (To be implemented)
 *    - Auto-Regressive Integrated Moving Average
 *    - Handles more complex time series patterns
 *    - Requires more data for accurate modeling
 * 
 * Statistical Analyses:
 * --------------------
 * 
 * 1. Correlation Analysis
 *    - Pearson correlation coefficient between terms
 *    - Lead/lag analysis using cross-correlation
 *    - Cosine similarity for pattern matching
 * 
 * 2. Anomaly Detection
 *    - Z-score based outlier detection
 *    - Moving window for adaptive thresholds
 *    - Classification of spikes and drops
 * 
 * 3. Dynamic Time Warping (DTW)
 *    - Measures similarity between temporal sequences
 *    - Handles sequences of different lengths
 *    - Useful for comparing pattern shapes regardless of timing
 * 
 */
';
    
    return $instructions;
}

// Example of how to use the TrendPredictor class
function exampleUsage() {
    // Create a new TrendPredictor instance
    $predictor = new TrendPredictor();
    
    // Track a search event
    $predictor->trackEvent('machine learning', 'technology', [
        'user_id' => 123,
        'session_id' => 'abc123',
        'device' => 'mobile'
    ]);
    
    // Get historical data
    $data = $predictor->getHistoricalData('machine learning', 'daily', 90);
    
    // Generate forecast
    $forecast = $predictor->ensembleForecast('machine learning', 30, 'daily');
    $forecastWithIntervals = $predictor->createPredictionIntervals($forecast);
    
    // Analyze statistics
    $stats = $predictor->analyzeStatistics('machine learning', 'daily');
    
    // Detect anomalies
    $anomalies = $predictor->detectAnomalies('machine learning', 'daily');
    
    // Compare two terms
    $comparison = $predictor->compareTrends('machine learning', 'artificial intelligence', 'daily');
    
    // Find correlations between multiple terms
    $correlations = $predictor->findCorrelations([
        'machine learning',
        'artificial intelligence',
        'deep learning',
        'neural networks'
    ], 'daily');
    
    // Create a visualizer
    $visualizer = new TrendVisualizer($predictor);
    
    // Generate charts
    $timeSeriesChart = $visualizer->generateTimeSeriesChart('machine learning', 'daily');
    $forecastChart = $visualizer->generateForecastChart($forecastWithIntervals, 'machine learning');
    $comparisonChart = $visualizer->generateComparisonChart([
        'machine learning',
        'artificial intelligence',
        'deep learning'
    ], 'daily');
    $correlationHeatmap = $visualizer->generateCorrelationHeatmap($correlations);
}
?>