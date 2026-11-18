<?php
// Load WordPress core
require_once dirname(__DIR__, 3) . '/wp-load.php'; 

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$file_path = __DIR__ . '/delete_list.txt';

if (!file_exists($file_path)) {
    die("Error: delete_list.txt not found.\n");
}

// Read the file lines into an array
$raw_lines = file($file_path);
$skus_to_delete = [];

foreach ($raw_lines as $line) {
    // 1. Strip the BOM and hidden characters using a Regex
    // This keeps only letters and numbers, removing the BOM and whitespace
    $clean_sku = preg_replace('/[^A-Za-z0-9]/', '', $line);
    
    if (!empty($clean_sku)) {
        $skus_to_delete[] = $clean_sku;
    }
}

echo "Starting deletion of " . count($skus_to_delete) . " products...\n";

$deleted_count = 0;
$not_found_count = 0;

foreach ($skus_to_delete as $sku) {
    global $wpdb;

    // We use a clean SKU here
    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT pm.post_id
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = '_sku'
           AND pm.meta_value = %s
           AND p.post_type = 'product'
         LIMIT 1",
        $sku
    ));

    if ($product_id) {
        $status = wp_delete_post($product_id, true);
        if ($status) {
            echo "🗑️ Deleted SKU: [$sku] (ID: $product_id)\n";
            $deleted_count++;
        }
    } else {
        echo "ℹ️ SKU not found: [$sku]\n";
        $not_found_count++;
    }
}

echo "\n--- Finished ---\n";
echo "Successfully Deleted: $deleted_count\n";
echo "Not Found: $not_found_count\n";