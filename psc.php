<?php
/**
 * PHP Syntax Checker and Closer
 * 
 * This script helps identify and fix common syntax errors in PHP files,
 * particularly focusing on unclosed brackets, parentheses, and quotes.
 * 
 * Usage: Place this file in the same directory as your problematic PHP file,
 * then run: php syntax_checker.php path/to/your/file.php
 */

// Configuration
$file_to_check = isset($argv[1]) ? $argv[1] : 'ipmapper2.php';

// Check if file exists
if (!file_exists($file_to_check)) {
    die("Error: File '$file_to_check' not found.\n");
}

// Read file content
$content = file_get_contents($file_to_check);
if ($content === false) {
    die("Error: Could not read file '$file_to_check'.\n");
}

echo "Scanning file: $file_to_check\n";
echo "File size: " . filesize($file_to_check) . " bytes\n\n";

// Check for PHP syntax errors using PHP's syntax checking
echo "Running PHP syntax check...\n";
$output = array();
$return_var = 0;
exec("php -l $file_to_check", $output, $return_var);

foreach ($output as $line) {
    echo "$line\n";
}

if ($return_var === 0) {
    echo "No syntax errors detected by PHP linter.\n\n";
} else {
    echo "Syntax errors found by PHP linter.\n\n";
}

// Count brackets and parentheses
$brackets = [
    '{' => 0,
    '}' => 0,
    '(' => 0,
    ')' => 0,
    '[' => 0,
    ']' => 0
];

$bracket_pairs = [
    '{' => '}',
    '(' => ')',
    '[' => ']'
];

$bracket_positions = [
    '{' => [],
    '}' => [],
    '(' => [],
    ')' => [],
    '[' => [],
    ']' => []
];

// Skip bracket counting in strings and comments
$in_single_quote = false;
$in_double_quote = false;
$in_line_comment = false;
$in_block_comment = false;
$escape_next = false;

$line_number = 1;
$column_number = 1;

for ($i = 0; $i < strlen($content); $i++) {
    $char = $content[$i];
    $next_char = isset($content[$i+1]) ? $content[$i+1] : '';
    
    // Track line and column numbers
    if ($char === "\n") {
        $line_number++;
        $column_number = 1;
    } else {
        $column_number++;
    }
    
    // Handle string and comment detection
    if (!$in_line_comment && !$in_block_comment) {
        if ($char === '\\' && !$escape_next) {
            $escape_next = true;
            continue;
        }
        
        if ($char === "'" && !$in_double_quote && !$escape_next) {
            $in_single_quote = !$in_single_quote;
        } elseif ($char === '"' && !$in_single_quote && !$escape_next) {
            $in_double_quote = !$in_double_quote;
        }
    }
    
    if ($escape_next) {
        $escape_next = false;
        continue;
    }
    
    // Handle comments
    if (!$in_single_quote && !$in_double_quote) {
        if (!$in_block_comment && $char === '/' && $next_char === '/') {
            $in_line_comment = true;
        } elseif (!$in_line_comment && $char === '/' && $next_char === '*') {
            $in_block_comment = true;
        } elseif ($in_line_comment && $char === "\n") {
            $in_line_comment = false;
        } elseif ($in_block_comment && $char === '*' && $next_char === '/') {
            $in_block_comment = false;
        }
    }
    
    // Count brackets outside of strings and comments
    if (!$in_single_quote && !$in_double_quote && !$in_line_comment && !$in_block_comment) {
        if (isset($brackets[$char])) {
            $brackets[$char]++;
            $bracket_positions[$char][] = [
                'line' => $line_number,
                'column' => $column_number
            ];
        }
    }
}

// Check for mismatched brackets
echo "Bracket count analysis:\n";
$has_mismatch = false;

foreach ($bracket_pairs as $opening => $closing) {
    echo "  $opening: {$brackets[$opening]}, $closing: {$brackets[$closing]} ";
    
    if ($brackets[$opening] > $brackets[$closing]) {
        echo "- MISMATCH! Missing " . ($brackets[$opening] - $brackets[$closing]) . " closing $closing";
        $has_mismatch = true;
    } elseif ($brackets[$opening] < $brackets[$closing]) {
        echo "- MISMATCH! Extra " . ($brackets[$closing] - $brackets[$opening]) . " closing $closing";
        $has_mismatch = true;
    } else {
        echo "- OK";
    }
    
    echo "\n";
}

if ($has_mismatch) {
    echo "\nBracket mismatches found! Here's how to fix them:\n";
    
    foreach ($bracket_pairs as $opening => $closing) {
        if ($brackets[$opening] > $brackets[$closing]) {
            $missing = $brackets[$opening] - $brackets[$closing];
            echo "Add $missing '$closing' at the end of the file to close the unclosed '$opening' brackets.\n";
            
            // Show locations of the unclosed brackets
            if ($missing <= 5) { // Only show details for a reasonable number of mismatches
                $unclosed_count = $brackets[$opening] - $brackets[$closing];
                $positions_to_show = array_slice($bracket_positions[$opening], -$unclosed_count);
                
                foreach ($positions_to_show as $idx => $pos) {
                    echo "  Unclosed $opening at line {$pos['line']}, column {$pos['column']}\n";
                }
            }
        } elseif ($brackets[$opening] < $brackets[$closing]) {
            echo "Remove " . ($brackets[$closing] - $brackets[$opening]) . " extra '$closing' from the file.\n";
        }
    }
    
    // Add the missing closing brackets to a fixed version of the file
    $fixed_content = $content;
    foreach ($bracket_pairs as $opening => $closing) {
        if ($brackets[$opening] > $brackets[$closing]) {
            $missing = $brackets[$opening] - $brackets[$closing];
            $fixed_content .= str_repeat($closing, $missing);
        }
    }
    
    // Save fixed content to a new file
    $fixed_filename = pathinfo($file_to_check, PATHINFO_FILENAME) . "_fixed." . pathinfo($file_to_check, PATHINFO_EXTENSION);
    file_put_contents($fixed_filename, $fixed_content);
    
    echo "\nFixed file created: $fixed_filename\n";
    echo "The fixed file has the missing closing brackets added at the end.\n";
    echo "Please review the file carefully as this is just an automatic fix.\n";
} else {
    echo "\nNo bracket mismatches found. The issue might be elsewhere.\n";
}

// Check PHP tag balance
$php_open_tags = substr_count($content, '<?php');
$php_short_open_tags = substr_count($content, '<?');
$php_close_tags = substr_count($content, '?>');

$total_open_tags = $php_open_tags + $php_short_open_tags;

echo "\nPHP tag analysis:\n";
echo "  <?php tags: $php_open_tags\n";
echo "  <? tags: $php_short_open_tags\n";
echo "  ?> tags: $php_close_tags\n";

if ($total_open_tags > $php_close_tags) {
    echo "  MISMATCH! Missing " . ($total_open_tags - $php_close_tags) . " closing PHP tags\n";
} elseif ($total_open_tags < $php_close_tags) {
    echo "  MISMATCH! Extra " . ($php_close_tags - $total_open_tags) . " closing PHP tags\n";
} else {
    echo "  PHP tags are balanced\n";
}

// Additional checks
echo "\nAdditional checks:\n";

// Check for incomplete PHP functions
$function_declarations = preg_match_all('/function\s+(\w+)\s*\(/i', $content, $matches);
echo "  Found $function_declarations function declarations\n";

// Check for common syntax issues
$semicolon_issues = preg_match_all('/(?<=[a-zA-Z0-9_])\s+\}/i', $content, $matches);
echo "  Potential missing semicolons: $semicolon_issues\n";

// Check for incomplete if/for/while statements
$control_structures = preg_match_all('/(if|for|foreach|while|do)\s*\(/i', $content, $matches);
echo "  Found $control_structures control structures\n";

// Check for incomplete string literals
$single_quotes = substr_count($content, "'");
$double_quotes = substr_count($content, '"');
echo "  Single quotes: $single_quotes (should be even)\n";
echo "  Double quotes: $double_quotes (should be even)\n";

if ($single_quotes % 2 !== 0) {
    echo "  WARNING: Odd number of single quotes - possibly unclosed string\n";
}

if ($double_quotes % 2 !== 0) {
    echo "  WARNING: Odd number of double quotes - possibly unclosed string\n";
}

// Final recommendations
echo "\nRecommendations:\n";
if ($has_mismatch) {
    echo "1. Use the fixed file that was created.\n";
    echo "2. Check the code around the unclosed brackets.\n";
} else if ($single_quotes % 2 !== 0 || $double_quotes % 2 !== 0) {
    echo "1. Look for unclosed string literals (quotes).\n";
} else {
    echo "1. Check for syntax errors like missing semicolons.\n";
    echo "2. Look for incomplete statements near the end of the file.\n";
    echo "3. Check for misplaced or missing quotes in string literals.\n";
}

echo "4. Consider using an IDE with PHP syntax highlighting and validation.\n";
echo "5. Break down large files into smaller, more manageable components.\n";
