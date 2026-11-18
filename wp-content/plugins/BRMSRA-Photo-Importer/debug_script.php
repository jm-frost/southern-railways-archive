<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== IMPORT SCRIPT DEBUG ===\n\n";

// 1. Check WordPress location
$wp_load_path = dirname(__DIR__, 3) . '/wp-load.php';
echo "Step 1: Looking for WordPress at: $wp_load_path\n";

if (!file_exists($wp_load_path)) {
    // Try alternative path
    $wp_load_path = dirname(__DIR__, 2) . '/wp-load.php';
    echo "  Trying alternative: $wp_load_path\n";
    
    if (!file_exists($wp_load_path)) {
        die("ERROR: WordPress wp-load.php not found!\n");
    }
}

require_once $wp_load_path;
echo "✓ WordPress loaded\n\n";

// 2. Check CLI
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script must be run from command line.\n");
}
echo "✓ Running in CLI mode\n\n";

// 3. Set limits
set_time_limit(0);
ini_set('memory_limit', '1024M');
echo "✓ Limits set\n\n";

// 4. Check database
global $wpdb;
if (!$wpdb) {
    die("ERROR: WordPress database not available\n");
}

$db_test = $wpdb->get_var("SELECT 1");
if ($db_test !== '1') {
    die("ERROR: Database connection failed\n");
}
echo "✓ Database connected\n\n";

// 5. Check import file
$import_file = __DIR__ . '/BRMSRA-Photo-Importer.php';
echo "Step 2: Looking for import file at: $import_file\n";

if (!file_exists($import_file)) {
    die("ERROR: Import file not found\n");
}

require_once $import_file;
echo "✓ Import file loaded\n\n";

// 6. Check functions exist
if (!function_exists('myplugin_wipe_and_import')) {
    die("ERROR: Function myplugin_wipe_and_import() not found\n");
}
echo "✓ Import function exists\n\n";

// 7. Check CSV file
$csv_file = __DIR__ . '/loadfile.csv';
echo "Step 3: Looking for CSV at: $csv_file\n";

if (!file_exists($csv_file)) {
    die("ERROR: CSV file not found\n");
}
echo "✓ CSV file found\n\n";

// 8. Disable output buffering
while (ob_get_level()) ob_end_clean();

echo str_repeat("=", 50) . "\n";
echo "All checks passed! Starting import...\n";
echo str_repeat("=", 50) . "\n\n";

// 9. EXECUTE
try {
    myplugin_wipe_and_import();
    echo "\n✓ Import completed successfully!\n";
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}