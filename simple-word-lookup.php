<?php
// Simple endpoint to look up words from the database as the user types
if (isset($_GET['term'])) {
    header('Content-Type: application/json');
    
    // Database connection parameters
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'reservephp';
    
    // Get search term from query
    $term = $_GET['term'] . '%'; // Add wildcard for LIKE query
    
    try {
        // Connect to the database
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Simple query to find matching words
        $stmt = $pdo->prepare("SELECT word FROM word WHERE word LIKE :term ORDER BY frequency DESC LIMIT 10");
        $stmt->bindParam(':term', $term, PDO::PARAM_STR);
        $stmt->execute();
        
        // Fetch results
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Return JSON response
        echo json_encode($results);
        exit;
    } catch (PDOException $e) {
        // Return error message
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Word Lookup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }
        .search-container {
            width: 600px;
            position: relative;
        }
        .search-input {
            width: 100%;
            padding: 12px 20px;
            border-radius: 24px;
            border: 1px solid #ddd;
            font-size: 16px;
            box-shadow: 0 1px 6px rgba(32, 33, 36, 0.28);
        }
        .word-predictions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border-radius: 0 0 24px 24px;
            box-shadow: 0 4px 6px rgba(32, 33, 36, 0.28);
            margin-top: 5px;
            z-index: 10;
            display: none;
        }
        .prediction-item {
            padding: 12px 20px;
            border-bottom: 1px solid #f1f1f1;
            cursor: pointer;
        }
        .prediction-item:hover {
            background-color: #f1f1f1;
        }
        .prediction-item:last-child {
            border-bottom: none;
            border-radius: 0 0 24px 24px;
        }
    </style>
</head>
<body>
    <h1>Word Lookup</h1>
    
    <div class="search-container">
        <input type="text" id="search-input" class="search-input" placeholder="Start typing..." autocomplete="off">
        <div id="word-predictions" class="word-predictions"></div>
    </div>
    
    <script>
        // Simple implementation to look up words as the user types
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            const predictionsContainer = document.getElementById('word-predictions');
            let debounceTimer;
            
            // As the user types, look up words in the database
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                // Clear previous timer
                clearTimeout(debounceTimer);
                
                // Hide predictions if query is empty
                if (!query) {
                    predictionsContainer.style.display = 'none';
                    return;
                }
                
                // Set debounce timer to avoid too many requests
                debounceTimer = setTimeout(() => {
                    // Make Ajax request to the server
                    fetch(`?term=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length > 0) {
                                // Generate predictions HTML
                                let predictionsHTML = '';
                                data.forEach(word => {
                                    predictionsHTML += `
                                        <div class="prediction-item" data-word="${word}">
                                            ${word}
                                        </div>
                                    `;
                                });
                                
                                // Show predictions
                                predictionsContainer.innerHTML = predictionsHTML;
                                predictionsContainer.style.display = 'block';
                                
                                // Add click events to predictions
                                document.querySelectorAll('.prediction-item').forEach(item => {
                                    item.addEventListener('click', function() {
                                        searchInput.value = this.getAttribute('data-word');
                                        predictionsContainer.style.display = 'none';
                                        
                                        // Could add a search action here
                                        alert('Selected: ' + searchInput.value);
                                    });
                                });
                            } else {
                                predictionsContainer.style.display = 'none';
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            predictionsContainer.style.display = 'none';
                        });
                }, 100); // Reduced debounce time to 100ms for more responsive feel
            });
            
            // Hide predictions when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !predictionsContainer.contains(e.target)) {
                    predictionsContainer.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>
