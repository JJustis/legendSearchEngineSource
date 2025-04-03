<?php
/**
 * Script to import CSV data into the registered_sites table in legenddx database
 * Ensures only unique entries are added
 */

// Database connection configuration
$dbConfig = [
    'host'     => 'localhost',     // Replace with your database host
    'username' => 'root', // Replace with your database username
    'password' => '', // Replace with your database password
    'database' => 'legenddx'       // Your database name
];

// CSV file path
$csvFilePath = 'top10milliondomains.csv'; // Replace with the actual path to your CSV file

/**
 * Function to import CSV data to database
 * 
 * @param string $csvFilePath Path to the CSV file
 * @param array $dbConfig Database configuration parameters
 * @return void
 */
function importCsvToDb($csvFilePath, $dbConfig) {
    // Statistics counters
    $insertedCount = 0;
    $skippedCount = 0;
    
    try {
        // Create database connection
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']}", 
            $dbConfig['username'], 
            $dbConfig['password']
        );
        
        // Set PDO to throw exceptions on error
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "Connected to database: {$dbConfig['database']}\n";
        
        // Prepare the SQL queries
        $checkQuery = "SELECT COUNT(*) FROM registered_sites WHERE url = :domain";
        $insertQuery = "INSERT INTO registered_sites (url) VALUES (:domain)";
        
        $checkStmt = $pdo->prepare($checkQuery);
        $insertStmt = $pdo->prepare($insertQuery);
        
        // Open and read the CSV file
        if (($handle = fopen($csvFilePath, "r")) !== FALSE) {
            // Skip the header row
            fgetcsv($handle);
            
            // Process each row in the CSV file
            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($row) >= 3) {
                    // Check if domain already exists
                    $checkStmt->bindParam(':domain', $row[1]);
                    $checkStmt->execute();
                    $exists = (int)$checkStmt->fetchColumn();
                    
                    if ($exists === 0) {
                        // Domain doesn't exist, insert it
                        $insertStmt->bindParam(':domain', $row[1]);
                        $insertStmt->execute();
                        $insertedCount++;
                    } else {
                        echo "Domain {$row[1]} already exists in the database. Skipping.\n";
                        $skippedCount++;
                    }
                }
            }
            
            fclose($handle);
            echo "Import completed. {$insertedCount} records inserted, {$skippedCount} records skipped.\n";
        } else {
            echo "Could not open file: {$csvFilePath}\n";
        }
        
    } catch (PDOException $e) {
        echo "Database error: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Run the import process
importCsvToDb($csvFilePath, $dbConfig);

echo "Process completed.\n";
?>