<?php
/**
 * PHP Syntax Checker for Import File
 * Run this first to find syntax errors before attempting import
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== PHP SYNTAX CHECKER ===\n\n";

$import_file = __DIR__ . '/BRMSRA-Photo-Importer.php';

echo "Checking file: $import_file\n\n";

if (!file_exists($import_file)) {
    die("ERROR: File not found!\n");
}

// Method 1: Use php -l (lint) command
echo "Method 1: PHP Lint Check\n";
echo str_repeat("-", 40) . "\n";

$output = [];
$return_var = 0;
exec("php -l " . escapeshellarg($import_file) . " 2>&1", $output, $return_var);

foreach ($output as $line) {
    echo $line . "\n";
}

if ($return_var !== 0) {
    echo "\n✗ SYNTAX ERRORS FOUND!\n";
    echo "Fix these errors before running the import.\n";
    exit(1);
}

echo "\n✓ No syntax errors found by lint\n\n";

// Method 2: Try to include with error catching
echo "Method 2: Test Loading File\n";
echo str_repeat("-", 40) . "\n";

// Capture any errors during include
$error_handler_triggered = false;
$error_message = '';

set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$error_handler_triggered, &$error_message) {
    $error_handler_triggered = true;
    $error_message = "Error [$errno]: $errstr in $errfile on line $errline";
    return true;
});

try {
    include_once $import_file;
    
    if ($error_handler_triggered) {
        echo "✗ ERROR during include:\n";
        echo $error_message . "\n";
        exit(1);
    }
    
    echo "✓ File loaded successfully\n\n";
    
    // Method 3: Check if required functions exist
    echo "Method 3: Function Check\n";
    echo str_repeat("-", 40) . "\n";
    
    $required_functions = [
        'myplugin_run_import',
        'myplugin_wipe_and_import',
        'myplugin_delete_all_products_fast',
        'brmsra_process_chunk_with_errors',
        'brmsra_get_or_create_attachment_fast',
        'brmsra_convert_date',
        'brmsra_update_all_term_counts'
    ];
    
    $missing_functions = [];
    
    foreach ($required_functions as $func) {
        if (function_exists($func)) {
            echo "✓ $func()\n";
        } else {
            echo "✗ $func() - MISSING\n";
            $missing_functions[] = $func;
        }
    }
    
    if (!empty($missing_functions)) {
        echo "\n✗ Missing functions found!\n";
        echo "The file may be incomplete or corrupted.\n";
        exit(1);
    }
    
    echo "\n✓ All required functions exist\n\n";
    
    echo str_repeat("=", 50) . "\n";
    echo "✓✓✓ ALL CHECKS PASSED ✓✓✓\n";
    echo str_repeat("=", 50) . "\n";
    echo "\nYour import file is valid and ready to use!\n";
    
} catch (ParseError $e) {
    echo "✗ PARSE ERROR:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ EXCEPTION:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit(1);
}

restore_error_handler();
