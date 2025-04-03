<?php
// register_site.php - Site registration page for Legend DX
session_start();

// Include necessary files
require_once 'config.php'; // Database connection
require_once 'crawler_functions.php'; // Will create this file separately

// Get database connection
$pdo = getDbConnection();

// Check if connection was successful
if (!$pdo) {
    die("Database connection failed. Please check your configuration.");
}
// Initialize variables
$url = '';
$title = '';
$description = '';
$keywords = '';
$subject = '';
$siteRegistered = false;
$error = '';
$crawlResults = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // Get form data
    $url = trim($_POST['url']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $keywords = trim($_POST['keywords']);
    $subject = trim($_POST['subject']);
    
    // Validate URL
    if (empty($url)) {
        $error = "URL is required";
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = "Invalid URL format";
    } else {
        // Add http:// if missing
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "http://" . $url;
        }
        
        // Check if URL already exists in database
        $stmt = $pdo->prepare("SELECT id FROM registered_sites WHERE url = ?");
        $stmt->execute([$url]);
        
        if ($stmt->rowCount() > 0) {
            $error = "This site is already registered";
        } else {
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Register the site
                $stmt = $pdo->prepare("
                    INSERT INTO registered_sites (url, title, description, keywords, subject, registration_date) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$url, $title, $description, $keywords, $subject]);
                $siteId = $pdo->lastInsertId();
                
                // Crawl the site to index words
                $crawler = new SiteCrawler($url);
                $crawlResults = $crawler->crawl();
                
                // Save indexed words
                if (!empty($crawlResults['words'])) {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO word (word, frequency, site_id) 
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE frequency = frequency + ?
                    ");
                    
                    foreach ($crawlResults['words'] as $word => $frequency) {
                        // Create wordpedia entry if it doesn't exist
                        createWordpediaEntry($word);
                        
                        // Insert word into database
                        $insertStmt->execute([$word, $frequency, $siteId, $frequency]);
                    }
                }
                
                // Save indexed pages
                if (!empty($crawlResults['pages'])) {
                    $pageStmt = $pdo->prepare("
                        INSERT INTO site_pages (site_id, url, title, content_hash) 
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    foreach ($crawlResults['pages'] as $page) {
                        $pageStmt->execute([
                            $siteId, 
                            $page['url'], 
                            $page['title'], 
                            md5($page['content'])
                        ]);
                    }
                }
                
                // Commit transaction
                $pdo->commit();
                
                $siteRegistered = true;
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

/**
 * Creates a Wordpedia entry for a word if it doesn't exist
 * 
 * @param string $word The word to create an entry for
 * @return bool True if entry was created, false if it already exists
 */
function createWordpediaEntry($word) {
    $word = strtolower(trim($word));
    
    // Skip invalid words (numbers, special characters, etc.)
    if (!preg_match('/^[a-zA-Z]+$/', $word)) {
        return false;
    }
    
    // Check if directory already exists
    $directory = "../wordpedia/pages/$word";
    if (is_dir($directory)) {
        return false;
    }
    
    // Create directory
    if (!mkdir($directory, 0755, true)) {
        return false;
    }
    
    // Create index.html file with basic template
    $content = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$word - Wordpedia</title>
    <link rel="stylesheet" href="../../style.css">
</head>
<body>
    <div class="wiki-container">
        <header>
            <h1>$word</h1>
        </header>
        <main>
            <div class="pronunciation">
                /$word/
            </div>
            <div class="definition-content">
                <p>Definition for $word. This entry was automatically generated.</p>
                <p>This word was found during site indexing.</p>
            </div>
            <div class="etymology">
                <p>Etymology information will be added later.</p>
            </div>
        </main>
    </div>
</body>
</html>
HTML;

    return file_put_contents("$directory/index.html", $content) !== false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Site - Legend DX</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
    /* Google-Inspired CSS Framework */
    :root {
      /* Color variables */
      --google-blue: #4285f4;
      --google-red: #ea4335;
      --google-yellow: #fbbc05;
      --google-green: #34a853;
      --google-grey-light: #f8f9fa;
      --google-grey-medium: #dadce0;
      --google-grey-dark: #70757a;
      --google-text: #202124;
      --panel-bg: rgba(255, 255, 255, 0.95);
      --shadow-color: rgba(0, 0, 0, 0.05);
      
      /* Font variables */
      --font-primary: 'Product Sans', 'Google Sans', Arial, sans-serif;
      --font-secondary: 'Roboto', Arial, sans-serif;
      
      /* Spacing variables */
      --space-xs: 0.25rem;
      --space-sm: 0.5rem;
      --space-md: 1rem;
      --space-lg: 1.5rem;
      --space-xl: 2rem;
      
      /* Border radius */
      --radius-sm: 4px;
      --radius-md: 8px;
      --radius-lg: 16px;
      --radius-pill: 9999px;
    }

    /* Base styles */
    body {
      margin: 0;
      padding: 0;
      font-family: var(--font-secondary);
      color: var(--google-text);
      background: white;
      line-height: 1.5;
    }

    /* Typography */
    h1, h2, h3, h4, h5, h6 {
      font-family: var(--font-primary);
      margin-top: 0;
    }

    h1 {
      font-size: 2.5rem;
      font-weight: 400;
      margin-bottom: var(--space-lg);
    }

    h2 {
      font-size: 2rem;
      font-weight: 400;
      margin-bottom: var(--space-md);
    }

    p {
      margin-bottom: 1rem;
    }

    a {
      color: #1a0dab;
      text-decoration: none;
      transition: color 0.2s ease-in-out;
    }

    a:hover {
      text-decoration: underline;
    }

    /* Layout containers */
    .container {
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
      padding: 0 1rem;
      box-sizing: border-box;
    }

    .container-sm {
      max-width: 800px;
    }

    /* Header styles */
    .g-header {
      background: white;
      padding: 1rem 0;
      border-bottom: 1px solid var(--google-grey-medium);
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .g-header-container {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .g-logo {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .g-logo img {
      height: 30px;
    }

    .g-logo-quad {
      display: flex;
      align-items: center;
      margin-right: var(--space-sm);
    }

    .g-logo-quad span {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      margin: 0 1px;
    }

    .g-logo-blue { background-color: var(--google-blue); }
    .g-logo-red { background-color: var(--google-red); }
    .g-logo-yellow { background-color: var(--google-yellow); }
    .g-logo-green { background-color: var(--google-green); }

    .g-nav {
      display: flex;
      gap: 1.5rem;
    }

    .g-nav-item {
      color: var(--google-text);
      font-weight: 500;
      position: relative;
    }

    .g-nav-item.active {
      color: var(--google-blue);
    }

    .g-nav-item.active::after {
      content: '';
      position: absolute;
      bottom: -5px;
      left: 0;
      width: 100%;
      height: 3px;
      background-color: var(--google-blue);
    }

    /* Form elements */
    .g-form {
      margin-bottom: var(--space-xl);
    }

    .g-form-group {
      margin-bottom: var(--space-lg);
    }

    .g-form-label {
      display: block;
      margin-bottom: var(--space-sm);
      font-weight: 500;
    }

    .g-form-input,
    .g-form-textarea,
    .g-form-select {
      width: 100%;
      padding: var(--space-md);
      border: 1px solid var(--google-grey-medium);
      border-radius: var(--radius-sm);
      font-family: var(--font-secondary);
      font-size: 1rem;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
      box-sizing: border-box;
    }

    .g-form-input:focus,
    .g-form-textarea:focus,
    .g-form-select:focus {
      outline: none;
      border-color: var(--google-blue);
      box-shadow: 0 0 0 2px rgba(66, 133, 244, 0.2);
    }

    .g-form-textarea {
      min-height: 150px;
      resize: vertical;
    }

    .g-form-help {
      font-size: 0.9rem;
      color: var(--google-grey-dark);
      margin-top: var(--space-xs);
    }

    .g-form-error {
      color: var(--google-red);
      font-size: 0.9rem;
      margin-top: var(--space-xs);
    }

    /* Button styles */
    .g-button {
      display: inline-block;
      background-color: var(--google-blue);
      color: white;
      font-family: var(--font-primary);
      font-weight: 500;
      padding: 0.75rem 1.5rem;
      border-radius: 4px;
      border: none;
      cursor: pointer;
      transition: background-color 0.2s ease, box-shadow 0.2s ease;
      text-decoration: none;
    }

    .g-button:hover {
      background-color: #3367d6;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
      text-decoration: none;
    }

    .g-button-outline {
      background-color: transparent;
      color: var(--google-blue);
      border: 1px solid var(--google-blue);
    }

    .g-button-outline:hover {
      background-color: rgba(66, 133, 244, 0.1);
      box-shadow: none;
    }
    
    /* Results panel */
    .g-panel {
      background-color: var(--panel-bg);
      border-radius: 8px;
      box-shadow: 0 1px 3px var(--shadow-color), 0 2px 8px var(--shadow-color);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }
    
    .g-panel-success {
      border-left: 4px solid var(--google-green);
    }
    
    .g-panel-error {
      border-left: 4px solid var(--google-red);
    }
    
    /* Crawl results */
    .crawl-results {
      margin-top: var(--space-xl);
    }
    
    .crawl-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: var(--space-md);
      margin-bottom: var(--space-lg);
    }
    
    .stat-card {
      background-color: var(--google-grey-light);
      padding: var(--space-md);
      border-radius: var(--radius-sm);
      text-align: center;
    }
    
    .stat-value {
      font-size: 2rem;
      font-weight: 500;
      color: var(--google-blue);
    }
    
    .stat-label {
      color: var(--google-grey-dark);
    }
    
    .word-cloud {
      background-color: white;
      padding: var(--space-lg);
      border-radius: var(--radius-md);
      border: 1px solid var(--google-grey-medium);
      margin-bottom: var(--space-lg);
    }
    
    .word-tag {
      display: inline-block;
      margin: var(--space-xs);
      padding: var(--space-xs) var(--space-sm);
      background-color: var(--google-grey-light);
      border-radius: var(--radius-pill);
      color: var(--google-blue);
      text-decoration: none;
    }
    
    .word-tag-1 { font-size: 0.8rem; }
    .word-tag-2 { font-size: 1rem; }
    .word-tag-3 { font-size: 1.2rem; }
    .word-tag-4 { font-size: 1.4rem; }
    .word-tag-5 { font-size: 1.8rem; }
    
    .pages-list {
      list-style: none;
      padding: 0;
    }
    
    .page-item {
      padding: var(--space-md);
      border-bottom: 1px solid var(--google-grey-medium);
    }
    
    .page-title {
      font-weight: 500;
      margin-bottom: var(--space-xs);
    }
    
    .page-url {
      font-size: 0.9rem;
      color: var(--google-grey-dark);
      word-break: break-all;
    }
    
    /* Media queries */
    @media (max-width: 768px) {
      .crawl-stats {
        grid-template-columns: 1fr;
      }
    }
    </style>
</head>
<body>
    <header class="g-header">
        <div class="g-header-container container">
            <div class="g-logo">
                <div class="g-logo-quad">
                    <span class="g-logo-blue"></span>
                    <span class="g-logo-red"></span>
                    <span class="g-logo-yellow"></span>
                    <span class="g-logo-green"></span>
                </div>
                <span style="font-weight: bold; font-size: 1.2rem;">Legend DX</span>
            </div>
            <nav class="g-nav">
                <a href="index.php" class="g-nav-item">Search</a>
                <a href="register_site.php" class="g-nav-item active">Register Site</a>
                <a href="analytics_dashboard.php" class="g-nav-item">Analytics</a>
            </nav>
        </div>
    </header>

    <main class="container container-sm">
        <h1>Register Your Site</h1>
        
        <?php if ($error): ?>
            <div class="g-panel g-panel-error">
                <h3><i class="fas fa-exclamation-circle"></i> Error</h3>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($siteRegistered): ?>
            <div class="g-panel g-panel-success">
                <h3><i class="fas fa-check-circle"></i> Success!</h3>
                <p>Your site has been successfully registered and crawled.</p>
            </div>
            
            <div class="crawl-results">
                <h2>Crawl Results</h2>
                
                <div class="crawl-stats">
                    <div class="stat-card">
                        <div class="stat-value"><?= count($crawlResults['pages']) ?></div>
                        <div class="stat-label">Pages Indexed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= count($crawlResults['words']) ?></div>
                        <div class="stat-label">Unique Words</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $crawlResults['total_words'] ?></div>
                        <div class="stat-label">Total Words</div>
                    </div>
                </div>
                
                <?php if (!empty($crawlResults['words'])): ?>
                    <h3>Top Words</h3>
                    <div class="word-cloud">
                        <?php
                        // Get top words by frequency
                        $topWords = array_slice($crawlResults['words'], 0, 50, true);
                        
                        // Find max frequency for scaling
                        $maxFreq = max($topWords);
                        
                        foreach ($topWords as $word => $freq) {
                            // Scale size from 1-5 based on frequency
                            $size = ceil(($freq / $maxFreq) * 5);
                            echo '<a href="index.php?word=' . urlencode($word) . '" class="word-tag word-tag-' . $size . '">' . 
                                htmlspecialchars($word) . '</a> ';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($crawlResults['pages'])): ?>
                    <h3>Indexed Pages</h3>
                    <ul class="pages-list">
                        <?php foreach (array_slice($crawlResults['pages'], 0, 10) as $page): ?>
                            <li class="page-item">
                                <div class="page-title"><?= htmlspecialchars($page['title']) ?></div>
                                <div class="page-url"><?= htmlspecialchars($page['url']) ?></div>
                            </li>
                        <?php endforeach; ?>
                        
                        <?php if (count($crawlResults['pages']) > 10): ?>
                            <li class="page-item" style="text-align: center;">
                                <em>And <?= count($crawlResults['pages']) - 10 ?> more pages...</em>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
                
                <div style="margin-top: var(--space-xl); text-align: center;">
                    <a href="index.php" class="g-button">Return to Search</a>
                    <a href="register_site.php" class="g-button g-button-outline" style="margin-left: var(--space-md);">Register Another Site</a>
                </div>
            </div>
        <?php else: ?>
            <p>Add your website to our search engine. We'll crawl your site to index its content and make it searchable.</p>
            
            <form method="POST" action="" class="g-form">
                <div class="g-form-group">
                    <label for="url" class="g-form-label">Website URL</label>
                    <input type="url" id="url" name="url" class="g-form-input" placeholder="https://example.com" value="<?= htmlspecialchars($url) ?>" required>
                    <div class="g-form-help">Enter the full URL including https://</div>
                </div>
                
                <div class="g-form-group">
                    <label for="title" class="g-form-label">Site Title</label>
                    <input type="text" id="title" name="title" class="g-form-input" placeholder="My Awesome Website" value="<?= htmlspecialchars($title) ?>" required>
                </div>
                
                <div class="g-form-group">
                    <label for="description" class="g-form-label">Site Description</label>
                    <textarea id="description" name="description" class="g-form-textarea" placeholder="Brief description of your website"><?= htmlspecialchars($description) ?></textarea>
                    <div class="g-form-help">This will appear in search results</div>
                </div>
                
                <div class="g-form-group">
                    <label for="keywords" class="g-form-label">Keywords</label>
                    <input type="text" id="keywords" name="keywords" class="g-form-input" placeholder="keyword1, keyword2, keyword3" value="<?= htmlspecialchars($keywords) ?>">
                    <div class="g-form-help">Comma-separated list of keywords relevant to your site</div>
                </div>
                
                <div class="g-form-group">
                    <label for="subject" class="g-form-label">Subject/Category</label>
                    <select id="subject" name="subject" class="g-form-select">
                        <option value="">Select a category...</option>
                        <option value="Technology" <?= $subject === 'Technology' ? 'selected' : '' ?>>Technology</option>
                        <option value="Business" <?= $subject === 'Business' ? 'selected' : '' ?>>Business</option>
                        <option value="Education" <?= $subject === 'Education' ? 'selected' : '' ?>>Education</option>
                        <option value="Entertainment" <?= $subject === 'Entertainment' ? 'selected' : '' ?>>Entertainment</option>
                        <option value="Health" <?= $subject === 'Health' ? 'selected' : '' ?>>Health</option>
                        <option value="Science" <?= $subject === 'Science' ? 'selected' : '' ?>>Science</option>
                        <option value="Sports" <?= $subject === 'Sports' ? 'selected' : '' ?>>Sports</option>
                        <option value="Travel" <?= $subject === 'Travel' ? 'selected' : '' ?>>Travel</option>
                        <option value="Other" <?= $subject === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                
                <div style="margin-top: var(--space-xl);">
                    <button type="submit" name="register" class="g-button">
                        <i class="fas fa-globe"></i> Register & Crawl Site
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </main>
    
    <footer style="background-color: var(--google-grey-light); border-top: 1px solid var(--google-grey-medium); padding: var(--space-lg) 0; margin-top: var(--space-xl); text-align: center; color: var(--google-grey-dark);">
        <div class="container">
            <p>&copy; 2025 Legend DX - All rights reserved</p>
        </div>
    </footer>
</body>
</html>