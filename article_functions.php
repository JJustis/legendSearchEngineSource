<?php
// article_functions.php - Functions to handle article fetching and display
// Updated to handle base64 encoded and compressed content

// Function to decode article content
function decodeArticleContent($encodedContent) {
    try {
        // Base64 decode first
        $base64Decoded = base64_decode($encodedContent);
        
        // If decoding was successful, try to uncompress
        if ($base64Decoded !== false) {
            $uncompressed = gzuncompress($base64Decoded);
            
            // If uncompressing was successful, return the content
            if ($uncompressed !== false) {
                return $uncompressed;
            }
        }
        
        // If any step failed but we have the encoded content, return that as a fallback
        return $encodedContent;
    } catch (Exception $e) {
        // Return original content if an error occurs
        return $encodedContent;
    }
}

// Function to fetch articles from the database
function getArticles($limit = 10, $offset = 0, $genre = null) {
    // Database connection parameters
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'reservesphp';
    
    try {
        // Connect to the database
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Base query
        $query = "SELECT articleid, title, text, timestamp, signature, imagea, imageb, imagec, genre, rating, meta_title, meta_description FROM leaderposts";
        $params = [];
        
        // Add genre filter if specified
        if ($genre) {
            $query .= " WHERE genre = :genre";
            $params[':genre'] = $genre;
        }
        
        // Add sorting and pagination
        $query .= " ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";
        
        // Prepare and execute the query
        $stmt = $pdo->prepare($query);
        
        // Bind parameters
        if ($genre) {
            $stmt->bindParam(':genre', $genre, PDO::PARAM_STR);
        }
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        // Fetch all articles
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode content for each article
        foreach ($articles as &$article) {
            if (isset($article['text'])) {
                $article['text'] = decodeArticleContent($article['text']);
            }
        }
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) FROM leaderposts";
        if ($genre) {
            $countQuery .= " WHERE genre = :genre";
            $countStmt = $pdo->prepare($countQuery);
            $countStmt->bindParam(':genre', $genre, PDO::PARAM_STR);
        } else {
            $countStmt = $pdo->prepare($countQuery);
        }
        $countStmt->execute();
        $totalArticles = $countStmt->fetchColumn();
        
        return [
            'articles' => $articles,
            'total' => $totalArticles
        ];
    } catch (PDOException $e) {
        // Return empty array on error
        return [
            'articles' => [],
            'total' => 0,
            'error' => $e->getMessage()
        ];
    }
}

// Function to get a single article by ID
function getArticleById($articleId) {
    // Database connection parameters
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'reservesphp';
    
    try {
        // Connect to the database
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Query to fetch the specific article
        $stmt = $pdo->prepare("SELECT * FROM leaderposts WHERE articleid = :id");
        $stmt->bindParam(':id', $articleId, PDO::PARAM_INT);
        $stmt->execute();
        
        // Fetch the article
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Decode article content
        if ($article && isset($article['text'])) {
            $article['text'] = decodeArticleContent($article['text']);
        }
        
        return $article;
    } catch (PDOException $e) {
        return null;
    }
}

// Function to format article date in a readable format
function formatArticleDate($dateString) {
    // Check if the timestamp is a numeric Unix timestamp
    if (is_numeric($dateString)) {
        $date = new DateTime();
        $date->setTimestamp((int)$dateString);
    } else {
        // Try to parse the date string
        try {
            $date = new DateTime($dateString);
        } catch (Exception $e) {
            // If parsing fails, return the original string
            return $dateString;
        }
    }
    
    return $date->format('M d, Y');
}

// Function to generate excerpt from article content - now generating 300 words
function generateExcerpt($content, $wordCount = 300) {
    // Strip tags and trim
    $excerpt = strip_tags($content);
    
    // Split into words
    $words = preg_split('/\s+/', $excerpt);
    
    // If content is already shorter than the requested word count
    if (count($words) <= $wordCount) {
        return $excerpt;
    }
    
    // Take only the specified number of words
    $excerptWords = array_slice($words, 0, $wordCount);
    
    // Join back into a string and add ellipsis
    return implode(' ', $excerptWords) . '...';
}

// Function to get reading time based on content length
function getReadingTime($content) {
    $wordCount = str_word_count(strip_tags($content));
    $readingTime = max(1, ceil($wordCount / 200)); // Average reading speed: 200 words per minute, minimum 1 minute
    return $readingTime == 1 ? "1 min read" : "$readingTime mins read";
}

// Function to get all available genres/categories
function getArticleGenres() {
    // Database connection parameters
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'reservesphp';
    
    try {
        // Connect to the database
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Query to get all unique genres
        $stmt = $pdo->query("SELECT DISTINCT genre FROM leaderposts WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");
        
        // Fetch all genres
        $genres = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $genres;
    } catch (PDOException $e) {
        return [];
    }
}

// Function to render a single article card
function renderArticleCard($article) {
    $articleId = $article['articleid'];
    $title = htmlspecialchars($article['title']);
    $content = $article['text'];
    $excerpt = generateExcerpt($content, 300); // 300 words for excerpt
    $date = formatArticleDate($article['timestamp']);
    $readingTime = getReadingTime($content);
    $author = htmlspecialchars($article['signature'] ?: 'Unknown');
    $genre = htmlspecialchars($article['genre'] ?: 'Uncategorized');
    
    // Featured badge based on rating (if available)
    $featuredBadge = '';
    if (isset($article['rating']) && $article['rating'] >= 4) {
        $featuredBadge = '<span class="g-badge g-badge-yellow">Featured</span>';
    }
    
    // Featured image (if available)
    $featuredImage = '';
    if (!empty($article['imagea'])) {
        $imageUrl = htmlspecialchars($article['imagea']);
        $featuredImage = "<div class=\"article-image\"><img style=\"width:-moz-available;\" src=\"{$imageUrl}\" alt=\"{$title}\" /></div>";
    }
    
    // Generate URL-friendly slug from title
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
    $slug = trim($slug, '-');
    
    $html = <<<HTML
    <div class="g-panel g-panel-gradient-blue article-card">
        <div class="article-meta">
            <span class="article-date">{$date}</span>
            <span class="g-badge g-badge-blue">{$readingTime}</span>
            <span class="article-category">{$genre}</span>
            {$featuredBadge}
        </div>
        <h3 class="g-card-title"><a href="?page=articles&id={$articleId}&slug={$slug}">{$title}</a></h3>
        {$featuredImage}
        <p class="article-excerpt">{$excerpt}</p>
        <div class="article-footer">
            <div class="article-author">
                <i class="fas fa-user-circle"></i> {$author}
            </div>
            <a href="?page=articles&id={$articleId}&slug={$slug}" class="g-button g-button-outline">Read More</a>
        </div>
    </div>
HTML;
    
    return $html;
}

// Function to render article list with pagination
function renderArticleList($currentPage = 1, $perPage = 5, $genre = null) {
    $offset = ($currentPage - 1) * $perPage;
    $result = getArticles($perPage, $offset, $genre);
    $articles = $result['articles'];
    $totalArticles = $result['total'];
    $totalPages = ceil($totalArticles / $perPage);
    
    $output = '';
    
    // If we have an error, display it
    if (isset($result['error'])) {
        $output .= '<div class="g-panel g-panel-gradient-red"><p>Error loading articles: ' . htmlspecialchars($result['error']) . '</p></div>';
        return $output;
    }
    
    // If no articles found
    if (empty($articles)) {
        $output .= '<div class="g-panel g-panel-gradient"><p>No articles found.</p></div>';
        return $output;
    }
    
    // Render each article
    foreach ($articles as $article) {
        $output .= renderArticleCard($article);
    }
    
    // Pagination
    if ($totalPages > 1) {
        $output .= '<div class="g-search-pagination">';
        
        // Previous button
        $prevClass = ($currentPage <= 1) ? 'g-search-page-prev disabled' : 'g-search-page-prev';
        $prevLink = ($currentPage <= 1) ? '#' : '?page=articles&pg=' . ($currentPage - 1) . ($genre ? '&genre=' . urlencode($genre) : '');
        $output .= "<a href=\"{$prevLink}\" class=\"{$prevClass}\"><i class=\"fas fa-chevron-left\"></i></a>";
        
        // Page numbers
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $startPage + 4);
        
        for ($i = $startPage; $i <= $endPage; $i++) {
            $activeClass = ($i == $currentPage) ? 'active' : '';
            $pageLink = '?page=articles&pg=' . $i . ($genre ? '&genre=' . urlencode($genre) : '');
            $output .= "<a href=\"{$pageLink}\" class=\"g-search-page {$activeClass}\">{$i}</a>";
        }
        
        // Next button
        $nextClass = ($currentPage >= $totalPages) ? 'g-search-page-next disabled' : 'g-search-page-next';
        $nextLink = ($currentPage >= $totalPages) ? '#' : '?page=articles&pg=' . ($currentPage + 1) . ($genre ? '&genre=' . urlencode($genre) : '');
        $output .= "<a href=\"{$nextLink}\" class=\"{$nextClass}\"><i class=\"fas fa-chevron-right\"></i></a>";
        
        $output .= '</div>';
    }
    
    return $output;
}

// Function to render a full article page
function renderSingleArticle($articleId) {
    $article = getArticleById($articleId);
    
    if (!$article) {
        return '<div class="g-panel g-panel-gradient-red"><p>Article not found.</p></div>';
    }
    
    $title = htmlspecialchars($article['title']);
    $content = nl2br($article['text']); // Convert newlines to <br>
    $date = formatArticleDate($article['timestamp']);
    $readingTime = getReadingTime($article['text']);
    $author = htmlspecialchars($article['signature'] ?: 'Unknown');
    $genre = htmlspecialchars($article['genre'] ?: 'Uncategorized');
    
    // Process images
    $imagesHtml = '';
    $imageSources = [];
    
    if (!empty($article['imagea'])) {
        $imageSources[] = $article['imagea'];
    }
    
    if (!empty($article['imageb'])) {
        $imageSources[] = $article['imageb'];
    }
    
    if (!empty($article['imagec'])) {
        $imageSources[] = $article['imagec'];
    }
    
    if (!empty($imageSources)) {
        $imagesHtml .= '<div class="article-images">';
        
        // If there's just one image, display it large
        if (count($imageSources) == 1) {
            $imagesHtml .= '<div class="article-main-image">';
            $imagesHtml .= '<img style="height: fit-content;width: -moz-available;" height="100%" width="auto" src="' . htmlspecialchars($imageSources[0]) . '" alt="' . $title . '" />';
            $imagesHtml .= '</div>';
        } 
        // If there are multiple images, create a grid
        else {
            $imagesHtml .= '<div class="article-image-grid">';
            foreach ($imageSources as $src) {
                $imagesHtml .= '<div class="article-grid-image">';
                $imagesHtml .= '<img style="height: fit-content;width: -moz-available;" height="100%" width="auto" src="' . htmlspecialchars($src) . '" alt="' . $title . '" />';
                $imagesHtml .= '</div>';
            }
            $imagesHtml .= '</div>';
        }
        
        $imagesHtml .= '</div>';
    }
    
    // Add metadata if available
    $metaHtml = '';
    if (!empty($article['meta_keywords'])) {
        $keywords = explode(',', $article['meta_keywords']);
        $metaHtml .= '<div class="article-tags">';
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if (!empty($keyword)) {
                $metaHtml .= '<span class="article-tag">' . htmlspecialchars($keyword) . '</span>';
            }
        }
        $metaHtml .= '</div>';
    }
    
    // Add cost/item info if available
    $itemInfoHtml = '';
    if (!empty($article['itemname']) || !empty($article['cost'])) {
        $itemInfoHtml .= '<div class="article-item-info">';
        
        if (!empty($article['itemname'])) {
            $itemInfoHtml .= '<div class="article-item-name">' . htmlspecialchars($article['itemname']) . '</div>';
        }
        
        if (!empty($article['cost'])) {
            $itemInfoHtml .= '<div class="article-item-cost">' . htmlspecialchars($article['cost']) . '</div>';
        }
        
        if (!empty($article['size'])) {
            $itemInfoHtml .= '<div class="article-item-size">Size: ' . htmlspecialchars($article['size']) . '</div>';
        }
        
        $itemInfoHtml .= '</div>';
    }
    
    $html = <<<HTML
    <div class="g-panel g-panel-gradient single-article">
        <h1 class="article-title">{$title}</h1>
        <div class="article-meta">
            <span class="article-date">{$date}</span>
            <span class="g-badge g-badge-blue">{$readingTime}</span>
            <span class="article-category">{$genre}</span>
            <span class="article-author"><i class="fas fa-user-circle"></i> {$author}</span>
        </div>
        
        {$imagesHtml}
        {$itemInfoHtml}
        
        <div class="article-content">
            {$content}
        </div>
        
        {$metaHtml}
        
        <div class="article-footer">
            <a href="?page=articles" class="g-button g-button-outline">Back to Articles</a>
            <div class="article-share">
                <span>Share: </span>
                <a href="#" onclick="window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(window.location.href), 'facebook-share', 'width=580,height=296'); return false;"><i class="fab fa-facebook"></i></a>
                <a href="#" onclick="window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent('{$title}') + '&url=' + encodeURIComponent(window.location.href), 'twitter-share', 'width=550,height=235'); return false;"><i class="fab fa-twitter"></i></a>
                <a href="#" onclick="window.open('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(window.location.href), 'linkedin-share', 'width=580,height=296'); return false;"><i class="fab fa-linkedin"></i></a>
            </div>
        </div>
    </div>
HTML;
    
    return $html;
}

// Function to render genre/category filter buttons
function renderGenreFilters($currentGenre = null) {
    $genres = getArticleGenres();
    
    $html = '<div class="article-categories">';
    
    // Add "All" category
    $allActive = ($currentGenre === null) ? 'active' : '';
    $html .= '<a href="?page=articles" class="article-category ' . $allActive . '">All</a>';
    
    // Add each genre
    foreach ($genres as $genre) {
        if (empty($genre)) continue;
        
        $active = ($currentGenre === $genre) ? 'active' : '';
        $html .= '<a href="?page=articles&genre=' . urlencode($genre) . '" class="article-category ' . $active . '">' . htmlspecialchars($genre) . '</a>';
    }
    
    $html .= '</div>';
    
    return $html;
}

// Function to search articles
function searchArticles($searchTerm, $limit = 10, $offset = 0) {
    // Database connection parameters
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'reservesphp';
    
    try {
        // Connect to the database
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Prepare the search term for LIKE query
        $searchTerm = "%$searchTerm%";
        
        // Query to search articles
        $query = "SELECT articleid, title, text, timestamp, signature, imagea, genre 
                  FROM leaderposts 
                  WHERE title LIKE :search 
                     OR meta_keywords LIKE :search 
                     OR meta_description LIKE :search 
                  ORDER BY timestamp DESC 
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        // Fetch all matching articles
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode content for each article
        foreach ($articles as &$article) {
            if (isset($article['text'])) {
                $article['text'] = decodeArticleContent($article['text']);
            }
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(*) FROM leaderposts 
                       WHERE title LIKE :search 
                          OR meta_keywords LIKE :search 
                          OR meta_description LIKE :search";
        
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
        $countStmt->execute();
        $totalArticles = $countStmt->fetchColumn();
        
        return [
            'articles' => $articles,
            'total' => $totalArticles
        ];
    } catch (PDOException $e) {
        return [
            'articles' => [],
            'total' => 0,
            'error' => $e->getMessage()
        ];
    }
}

// Function to render search results
function renderSearchResults($searchTerm, $currentPage = 1, $perPage = 5) {
    $offset = ($currentPage - 1) * $perPage;
    $result = searchArticles($searchTerm, $perPage, $offset);
    $articles = $result['articles'];
    $totalArticles = $result['total'];
    $totalPages = ceil($totalArticles / $perPage);
    
    $output = '<h2>Search Results for "' . htmlspecialchars($searchTerm) . '"</h2>';
    $output .= '<p>' . $totalArticles . ' article(s) found</p>';
    
    // If we have an error, display it
    if (isset($result['error'])) {
        $output .= '<div class="g-panel g-panel-gradient-red"><p>Error searching articles: ' . htmlspecialchars($result['error']) . '</p></div>';
        return $output;
    }
    
    // If no articles found
    if (empty($articles)) {
        $output .= '<div class="g-panel g-panel-gradient"><p>No articles found matching your search.</p></div>';
        return $output;
    }
    
    // Render each article
    foreach ($articles as $article) {
        $output .= renderArticleCard($article);
    }
    
    // Pagination
    if ($totalPages > 1) {
        $output .= '<div class="g-search-pagination">';
        
        // Previous button
        $prevClass = ($currentPage <= 1) ? 'g-search-page-prev disabled' : 'g-search-page-prev';
        $prevLink = ($currentPage <= 1) ? '#' : '?page=articles&action=search&q=' . urlencode($searchTerm) . '&pg=' . ($currentPage - 1);
        $output .= "<a href=\"{$prevLink}\" class=\"{$prevClass}\"><i class=\"fas fa-chevron-left\"></i></a>";
        
        // Page numbers
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $startPage + 4);
        
        for ($i = $startPage; $i <= $endPage; $i++) {
            $activeClass = ($i == $currentPage) ? 'active' : '';
            $pageLink = '?page=articles&action=search&q=' . urlencode($searchTerm) . '&pg=' . $i;
            $output .= "<a href=\"{$pageLink}\" class=\"g-search-page {$activeClass}\">{$i}</a>";
        }
        
        // Next button
        $nextClass = ($currentPage >= $totalPages) ? 'g-search-page-next disabled' : 'g-search-page-next';
        $nextLink = ($currentPage >= $totalPages) ? '#' : '?page=articles&action=search&q=' . urlencode($searchTerm) . '&pg=' . ($currentPage + 1);
        $output .= "<a href=\"{$nextLink}\" class=\"{$nextClass}\"><i class=\"fas fa-chevron-right\"></i></a>";
        
        $output .= '</div>';
    }
    
    return $output;
}
?>