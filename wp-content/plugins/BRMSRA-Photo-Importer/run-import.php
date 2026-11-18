<?php

// Load WordPress core
require_once dirname(__DIR__, 3) . '/wp-load.php'; 

// Only allow running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// 1. LIFT LIMITS: Ensure the script doesn't die for large imports
set_time_limit(0); 
ini_set('memory_limit', '1024M'); 

// 2. Import the plugin's main import function
require_once __DIR__ . '/BRMSRA-Photo-Importer.php';

// Disable output buffering so you see progress in real-time
while (ob_get_level()) ob_end_clean();

echo "Starting import...\n";

// 3. EXECUTE
// myplugin_wipe_and_import(); 

// 4. PARTIAL IMPORT
myplugin_partial_import();

echo "Import completed.\n";