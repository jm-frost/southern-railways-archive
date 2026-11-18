<?php
/**
 * BRMSRA Photo Importer - COMPLETE WITH ERROR HANDLING
 * 
 * Features:
 * - Fast bulk operations
 * - Full UTF-8 support (Spanish accents, curly quotes, etc)
 * - Comprehensive error tracking
 * - Automatic term count updates
 */

function myplugin_run_import() {
    global $wpdb;
  
    // SET UTF-8 CHARSET FIRST
    $wpdb->query("SET NAMES 'utf8mb4'");
    $wpdb->query("SET CHARACTER SET utf8mb4");

    $start_time = microtime(true);
    
    // ERROR TRACKING
    $errors = [
        'skipped_rows' => [],
        'failed_products' => [],
        'failed_tags' => [],
        'failed_photographers' => []
    ];
    $success_count = 0;

    // 1. NUCLEAR OPTION - KILL EVERYTHING
    if (!defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
    wp_defer_term_counting(true);
    wp_defer_comment_counting(true);
    wp_suspend_cache_invalidation(true);
    
    // Kill ALL WooCommerce overhead
    remove_all_actions('save_post');
    remove_all_actions('save_post_product');
    remove_all_actions('save_post_product_variation');
    remove_all_actions('woocommerce_process_product_meta');
    remove_all_actions('woocommerce_product_object_updated_props');
    remove_all_actions('transition_post_status');
    
    // Action Scheduler
    add_filter('action_scheduler_queue_runner_concurrent_batches', '__return_zero');
    add_filter('action_scheduler_queue_runner_batch_size', '__return_zero');
    add_filter('woocommerce_background_updater_enabled', '__return_false');
    
    @ini_set('memory_limit', '2048M');
    @ini_set('max_execution_time', '0');
    @set_time_limit(0);

    $file = __DIR__ . '/loadfile.csv';
    if (!file_exists($file)) {
        echo "ERROR: CSV not found\n";
        return;
    }

    // 2. PRE-LOAD ENTIRE CSV INTO MEMORY
    echo "Loading CSV into memory...\n";
    $csv_data = file_get_contents($file);
    $csv_data = preg_replace('/^\xEF\xBB\xBF/', '', $csv_data);
    $lines = explode("\n", $csv_data);
    $headers = str_getcsv(array_shift($lines), '|');
    echo "✓ Loaded " . count($lines) . " rows\n";

    // 3. PRE-CACHE EVERYTHING POSSIBLE
    echo "Pre-caching taxonomies...\n";
    
    $attribute_tax = 'pa_size_or_type';
    $values = [
        '6" x 4" Photo Print', '9" x 6" Photo Print', '12" x 8" Photo Print', 
        '15" x 10" Photo Print', '18" x 12" Photo Print', 
        'Digital Download – Personal use', 'Digital Download – Commercial use'
    ];
    $variation_prices = ['1.25', '4.00', '9.60', '18.00', '29.00', '2.00', '8.00'];
    
    $term_cache = [];
    $term_tt_ids = [];
    foreach ($values as $size) {
        $term = term_exists($size, $attribute_tax) ?: wp_insert_term($size, $attribute_tax);
        $t_id = is_array($term) ? $term['term_id'] : $term;
        $t_obj = get_term($t_id, $attribute_tax);
        $term_cache[$size] = $t_obj->slug;
        $term_tt_ids[$size] = $t_obj->term_taxonomy_id;
    }

    $photos_cat = get_term_by('name', 'Photographs', 'product_cat');
    $photos_cat_tt_id = $photos_cat ? $photos_cat->term_taxonomy_id : false;
    
    $variable_term = get_term_by('name', 'variable', 'product_type');
    $variable_tt_id = $variable_term ? $variable_term->term_taxonomy_id : false;

    // PRE-CACHE ALL EXISTING TAGS
    echo "Pre-caching existing tags...\n";
    $existing_tags = [];
    $tag_results = $wpdb->get_results(
        "SELECT t.term_id, t.name, tt.term_taxonomy_id 
        FROM $wpdb->terms t 
        INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
        WHERE tt.taxonomy = 'product_tag'"
    );
    foreach ($tag_results as $tag) {
        $existing_tags[strtolower(trim($tag->name))] = [
            'term_id' => $tag->term_id,
            'tt_id' => $tag->term_taxonomy_id
        ];
    }
    echo "✓ Cached " . count($existing_tags) . " existing tags\n";

    // PRE-CACHE ALL EXISTING PHOTOGRAPHERS
    echo "Pre-caching existing photographers...\n";
    $existing_photographers = [];
    $photog_results = $wpdb->get_results(
        "SELECT t.term_id, t.name, tt.term_taxonomy_id 
        FROM $wpdb->terms t 
        INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
        WHERE tt.taxonomy = 'photographer'"
    );
    foreach ($photog_results as $photog) {
        $existing_photographers[strtolower(trim($photog->name))] = [
            'term_id' => $photog->term_id,
            'tt_id' => $photog->term_taxonomy_id
        ];
    }
    echo "✓ Cached " . count($existing_photographers) . " existing photographers\n";

    // Pre-build static data
    $static_attr = serialize([
        $attribute_tax => [
            'name' => $attribute_tax, 'value' => '', 'is_visible' => 1, 
            'is_variation' => 1, 'is_taxonomy' => 1, 'position' => 0
        ]
    ]);

    // Get current time once
    $now = current_time('mysql');
    $now_gmt = current_time('mysql', 1);

    echo "\nStarting import with error tracking...\n\n";
    
    $row_count = 0;
    $chunk_size = 25;
    $chunk = [];

    // 4. MAIN LOOP WITH VALIDATION
    foreach ($lines as $line_num => $line) {
        if (empty(trim($line))) continue;
        
        $row_data = str_getcsv($line, '|');
        
        // VALIDATION: Check column count
        if (count($row_data) !== count($headers)) {
            $errors['skipped_rows'][] = [
                'line' => $line_num + 2,
                'reason' => 'Column mismatch',
                'expected' => count($headers),
                'got' => count($row_data)
            ];
            continue;
        }
        
        $row_count++;
        $data = array_combine($headers, $row_data);
        
        // VALIDATION: Required fields
        if (empty($data['Reference Number'])) {
            $errors['skipped_rows'][] = [
                'line' => $line_num + 2,
                'reason' => 'Missing Reference Number'
            ];
            continue;
        }
        
        if (empty($data['Title'])) {
            $errors['skipped_rows'][] = [
                'line' => $line_num + 2,
                'sku' => $data['Reference Number'],
                'reason' => 'Missing Title'
            ];
            continue;
        }
        
        $sku = $data['Reference Number'];

        if ($row_count % 10 === 0) {
            $elapsed = microtime(true) - $start_time;
            $rate = round($row_count / $elapsed, 2);
            echo "\rRow: $row_count | Success: $success_count | Rate: $rate/sec | SKU: $sku              ";
        }

        // Add to chunk with line number
        $chunk[] = [
            'data' => $data,
            'line_num' => $line_num + 2
        ];

        // Process chunk when full
        if (count($chunk) >= $chunk_size) {
            $result = brmsra_process_chunk_with_errors($chunk, $term_cache, $term_tt_ids, 
                $variable_tt_id, $photos_cat_tt_id, $existing_tags, $existing_photographers, 
                $static_attr, $variation_prices, $values, $attribute_tax, $now, $now_gmt);
            
            $success_count += $result['success'];
            $errors = array_merge_recursive($errors, $result['errors']);
            
            $chunk = [];
            wp_cache_flush();
        }
    }

    // Process remaining chunk
    if (!empty($chunk)) {
        $result = brmsra_process_chunk_with_errors($chunk, $term_cache, $term_tt_ids, 
            $variable_tt_id, $photos_cat_tt_id, $existing_tags, $existing_photographers, 
            $static_attr, $variation_prices, $values, $attribute_tax, $now, $now_gmt);
        
        $success_count += $result['success'];
        $errors = array_merge_recursive($errors, $result['errors']);
    }

    // CLEANUP
    wp_suspend_cache_invalidation(false);
    wp_defer_term_counting(false);
    wp_defer_comment_counting(false);
    
    // UPDATE TERM COUNTS (CRITICAL!)
    echo "\n\nUpdating term counts...\n";
    brmsra_update_all_term_counts();
    echo "✓ Term counts updated\n";
    
    $total_time = round(microtime(true) - $start_time, 2);
    $avg_rate = round($success_count / $total_time, 2);
    
    // DETAILED ERROR REPORT
    echo "\n\n" . str_repeat("=", 60) . "\n";
    echo "=== IMPORT COMPLETE ===\n";
    echo str_repeat("=", 60) . "\n";
    echo "Total rows processed: $row_count\n";
    echo "Successfully imported: $success_count\n";
    echo "Failed/Skipped: " . ($row_count - $success_count) . "\n";
    echo "Total time: {$total_time}s\n";
    echo "Average rate: {$avg_rate} rows/second\n";
    
    // Show errors
    if (!empty($errors['skipped_rows'])) {
        echo "\n⚠️  SKIPPED ROWS (" . count($errors['skipped_rows']) . "):\n";
        foreach (array_slice($errors['skipped_rows'], 0, 10) as $err) {
            echo "  Line {$err['line']}: {$err['reason']}";
            if (isset($err['sku'])) echo " (SKU: {$err['sku']})";
            echo "\n";
        }
        if (count($errors['skipped_rows']) > 10) {
            echo "  ... and " . (count($errors['skipped_rows']) - 10) . " more\n";
        }
    }
    
    if (!empty($errors['failed_products'])) {
        echo "\n⚠️  FAILED PRODUCTS (" . count($errors['failed_products']) . "):\n";
        foreach (array_slice($errors['failed_products'], 0, 10) as $err) {
            echo "  SKU {$err['sku']} (Line {$err['line']}): {$err['reason']}\n";
            if (isset($err['title'])) {
                echo "    Title: " . substr($err['title'], 0, 50) . "\n";
            }
        }
        if (count($errors['failed_products']) > 10) {
            echo "  ... and " . (count($errors['failed_products']) - 10) . " more\n";
        }
    }
    
    if (!empty($errors['failed_tags'])) {
        echo "\n⚠️  FAILED TAGS (" . count($errors['failed_tags']) . "):\n";
        $tag_summary = [];
        foreach ($errors['failed_tags'] as $err) {
            $tag_summary[$err['tag']] = ($tag_summary[$err['tag']] ?? 0) + 1;
        }
        foreach (array_slice($tag_summary, 0, 10, true) as $tag => $count) {
            echo "  '$tag' - failed $count times\n";
        }
    }
    
    if (!empty($errors['failed_photographers'])) {
        echo "\n⚠️  FAILED PHOTOGRAPHERS (" . count($errors['failed_photographers']) . "):\n";
        foreach (array_unique(array_column($errors['failed_photographers'], 'photographer')) as $photog) {
            echo "  '$photog'\n";
        }
    }
    
    echo "\nRecommendation: Regenerate product lookup tables in WooCommerce > Status > Tools\n";
}

/**
 * Process chunk with comprehensive error tracking and UTF-8 support
 */
function brmsra_process_chunk_with_errors($chunk, $term_cache, $term_tt_ids, $variable_tt_id, 
    $photos_cat_tt_id, &$existing_tags, &$existing_photographers, 
    $static_attr, $variation_prices, $values, $attribute_tax, $now, $now_gmt) {
    
    global $wpdb;
    
    // Set charset once per chunk
    $wpdb->query("SET NAMES 'utf8mb4'");
    
    $errors = [
        'failed_products' => [],
        'failed_tags' => [],
        'failed_photographers' => []
    ];
    $success_count = 0;
    
    foreach ($chunk as $item) {
        $data = $item['data'];
        $line_num = $item['line_num'];
        $sku = $data['Reference Number'];
        
        // A. INSERT PRODUCT with UTF-8 support
        $title = $data['Title'];
        $content = $data['Description'];
        $slug = sanitize_title($sku);
        
        $result = $wpdb->insert(
            $wpdb->posts,
            [
                'post_author' => 1,
                'post_date' => $now,
                'post_date_gmt' => $now_gmt,
                'post_content' => $content,
                'post_title' => $title,
                'post_status' => 'publish',
                'comment_status' => 'open',
                'ping_status' => 'closed',
                'post_name' => $slug,
                'post_modified' => $now,
                'post_modified_gmt' => $now_gmt,
                'post_type' => 'product',
                'comment_count' => 0
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );
        
        if ($result === false) {
            $errors['failed_products'][] = [
                'sku' => $sku,
                'line' => $line_num,
                'title' => substr($title, 0, 50),
                'reason' => 'INSERT failed: ' . $wpdb->last_error
            ];
            continue;
        }
        
        $product_id = $wpdb->insert_id;
        if (!$product_id) {
            $errors['failed_products'][] = [
                'sku' => $sku,
                'line' => $line_num,
                'reason' => 'No product ID returned'
            ];
            continue;
        }

        // B. IMAGE ATTACHMENT
        $image_url = "https://www.bluebell-railway-museum.co.uk/archive/photos2/" 
            . substr($sku, 0, 3) . "/" . substr($sku, -3) . ".jpg";
        $attachment_id = brmsra_get_or_create_attachment_fast($image_url, $now, $now_gmt);

        // C. PRODUCT META - bulk insert with esc_sql
        $meta_values = [];
        
        $core_meta = [
            '_sku' => $sku,
            '_product_attributes' => $static_attr,
            '_stock_status' => 'instock',
            '_price' => $variation_prices[0],
            '_thumbnail_id' => $attachment_id,
            'image_url' => '',
            '_image_url' => 'field_67b9c31f19be2',
        ];
        
        $acf_meta = [
            'description' => $data['Description'],
            'company' => $data['Company'],
            'class' => $data['Class'],
            'date' => $data['Date'],
            'number' => $data['Number'],
            'name' => $data['Name'],
            'location' => $data['Location'],
            'train_working' => $data['Train Working'],
            'other_information' => $data['OtherInformation'],
            'reference_number' => $sku,
            'day_of_week' => $data['Day of Week'],
            'holiday' => $data['Holiday'],
        ];
        
        if (!empty($data['Sort Date'])) {
            $conv = brmsra_convert_date($data['Sort Date']);
            if ($conv) $acf_meta['sort_date'] = $conv;
        }
        
        foreach (array_merge($core_meta, $acf_meta) as $key => $value) {
            if ($value === '' || $value === null) continue;
            $key_esc = esc_sql($key);
            $val_esc = esc_sql($value);
            $meta_values[] = "($product_id, '$key_esc', '$val_esc')";
        }
        
        if (!empty($meta_values)) {
            $wpdb->query(
                "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) 
                VALUES " . implode(',', $meta_values)
            );
        }

        // D. TERM RELATIONSHIPS
        $term_values = [];
        
        if ($variable_tt_id) {
            $term_values[] = "($product_id, $variable_tt_id, 0)";
        }
        if ($photos_cat_tt_id) {
            $term_values[] = "($product_id, $photos_cat_tt_id, 0)";
        }
        foreach ($term_tt_ids as $tt_id) {
            $term_values[] = "($product_id, $tt_id, 0)";
        }

        // TAGS with special character handling
        if (!empty($data['Tags'])) {
            foreach (array_map('trim', explode(',', $data['Tags'])) as $tag_name) {
                if (empty($tag_name)) continue;
                
                $tag_name = html_entity_decode($tag_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $tag_key = strtolower($tag_name);
                
                if (!isset($existing_tags[$tag_key])) {
                    $term = wp_insert_term($tag_name, 'product_tag');
                    if (!is_wp_error($term)) {
                        $existing_tags[$tag_key] = [
                            'term_id' => $term['term_id'],
                            'tt_id' => $term['term_taxonomy_id']
                        ];
                    } elseif ($term->get_error_code() === 'term_exists') {
                        $existing_term_id = $term->get_error_data('term_exists');
                        $existing_term = get_term($existing_term_id, 'product_tag');
                        if ($existing_term && !is_wp_error($existing_term)) {
                            $existing_tags[$tag_key] = [
                                'term_id' => $existing_term->term_id,
                                'tt_id' => $existing_term->term_taxonomy_id
                            ];
                        }
                    } else {
                        $errors['failed_tags'][] = [
                            'tag' => $tag_name,
                            'sku' => $sku,
                            'reason' => $term->get_error_message()
                        ];
                        continue;
                    }
                }
                
                if (isset($existing_tags[$tag_key])) {
                    $term_values[] = "($product_id, {$existing_tags[$tag_key]['tt_id']}, 0)";
                }
            }
        }

        // PHOTOGRAPHER with special character handling
        if (!empty($data['Photographer'])) {
            $photog_name = html_entity_decode(trim($data['Photographer']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $photog_key = strtolower($photog_name);
            
            if (!isset($existing_photographers[$photog_key])) {
                $term = wp_insert_term($photog_name, 'photographer');
                if (!is_wp_error($term)) {
                    $existing_photographers[$photog_key] = [
                        'term_id' => $term['term_id'],
                        'tt_id' => $term['term_taxonomy_id']
                    ];
                } elseif ($term->get_error_code() === 'term_exists') {
                    $existing_term_id = $term->get_error_data('term_exists');
                    $existing_term = get_term($existing_term_id, 'photographer');
                    if ($existing_term && !is_wp_error($existing_term)) {
                        $existing_photographers[$photog_key] = [
                            'term_id' => $existing_term->term_id,
                            'tt_id' => $existing_term->term_taxonomy_id
                        ];
                    }
                } else {
                    $errors['failed_photographers'][] = [
                        'photographer' => $photog_name,
                        'sku' => $sku,
                        'reason' => $term->get_error_message()
                    ];
                }
            }
            
            if (isset($existing_photographers[$photog_key])) {
                $term_values[] = "($product_id, {$existing_photographers[$photog_key]['tt_id']}, 0)";
            }
        }

        if (!empty($term_values)) {
            $wpdb->query(
                "INSERT IGNORE INTO $wpdb->term_relationships 
                (object_id, term_taxonomy_id, term_order) 
                VALUES " . implode(',', $term_values)
            );
        }

        // E. VARIATIONS with UTF-8 support
        foreach ($values as $idx => $size_name) {
            $slug_var = $term_cache[$size_name];
            $v_sku = $sku . '-' . $slug_var;
            $price = $variation_prices[$idx];
            $v_title = $data['Title'] . ' - ' . $size_name;
            
            $wpdb->query($wpdb->prepare("
                INSERT INTO $wpdb->posts 
                (post_author, post_date, post_date_gmt, post_title, post_status, 
                 post_parent, post_type, post_modified, post_modified_gmt, comment_count)
                VALUES 
                (1, %s, %s, %s, 'publish', 
                 %d, 'product_variation', %s, %s, 0)",
                $now, $now_gmt, $v_title, $product_id, $now, $now_gmt
            ));
            
            $v_id = $wpdb->insert_id;
            
            // Variation meta - bulk insert
            $v_meta = [
                "($v_id, '_sku', '" . esc_sql($v_sku) . "')",
                "($v_id, '_regular_price', '" . esc_sql($price) . "')",
                "($v_id, '_price', '" . esc_sql($price) . "')",
                "($v_id, '_stock_status', 'instock')",
                "($v_id, '_manage_stock', 'no')",
                "($v_id, 'attribute_$attribute_tax', '" . esc_sql($slug_var) . "')",
            ];
            
            if (stripos($size_name, 'Digital') !== false) {
                $v_meta[] = "($v_id, '_virtual', 'yes')";
            }
            
            $wpdb->query(
                "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) 
                VALUES " . implode(',', $v_meta)
            );
        }
        
        $success_count++;
    }
    
    return [
        'success' => $success_count,
        'errors' => $errors
    ];
}

/**
 * Ultra-fast attachment getter
 */
function brmsra_get_or_create_attachment_fast($image_url, $now, $now_gmt) {
    global $wpdb;
    static $cache = [];
    
    if (isset($cache[$image_url])) {
        return $cache[$image_url];
    }
    
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta 
        WHERE meta_key='_exmage_external_url' AND meta_value=%s LIMIT 1", 
        $image_url
    ));

    if (!$attachment_id) {
        $title = 'External - ' . basename($image_url);
        
        $wpdb->query($wpdb->prepare("
            INSERT INTO $wpdb->posts 
            (post_author, post_date, post_date_gmt, post_title, post_status, 
             post_type, post_mime_type, guid, post_modified, post_modified_gmt, comment_count)
            VALUES 
            (1, %s, %s, %s, 'inherit', 
             'attachment', 'image/jpeg', %s, %s, %s, 0)",
            $now, $now_gmt, $title, $image_url, $now, $now_gmt
        ));
        
        $attachment_id = $wpdb->insert_id;
        
        $metadata = serialize([
            'file' => $image_url, 'width' => 800, 'height' => 600,
            'sizes' => [
                'thumbnail' => ['file' => basename($image_url), 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg'],
                'woocommerce_single' => ['file' => basename($image_url), 'width' => 800, 'height' => 600, 'mime-type' => 'image/jpeg']
            ],
            'image_meta' => ['aperture' => '0', 'credit' => '', 'camera' => '', 'caption' => '', 
                'created_timestamp' => '0', 'copyright' => '', 'focal_length' => '0', 'iso' => '0', 
                'shutter_speed' => '0', 'title' => '', 'orientation' => '0', 'keywords' => []]
        ]);
        
        $url_esc = esc_sql($image_url);
        $meta_esc = esc_sql($metadata);
        
        $wpdb->query("
            INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES
            ($attachment_id, '_exmage_external_url', '$url_esc'),
            ($attachment_id, '_wp_attached_file', '$url_esc'),
            ($attachment_id, 'image_url', ''),
            ($attachment_id, '_image_url', 'field_67b9c31f19be2'),
            ($attachment_id, '_wp_attachment_metadata', '$meta_esc')
        ");
    }
    
    $cache[$image_url] = $attachment_id;
    return $attachment_id;
}

/**
 * Date converter
 */
function brmsra_convert_date($date) {
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    if (preg_match('/^(\d{2})\s([a-zA-Z]{3})\s(\d{4})$/', $date, $m)) {
        return "{$m[3]}-" . date('m', strtotime($m[2])) . "-{$m[1]}";
    }
    return false;
}

/**
 * Update all term counts - CRITICAL for filters to work
 */
function brmsra_update_all_term_counts() {
    global $wpdb;
    
    $taxonomies = [
        'product_cat',
        'product_tag',
        'pa_size_or_type',
        'photographer',
        'product_type'
    ];
    
    foreach ($taxonomies as $taxonomy) {
        $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->term_taxonomy tt 
            SET tt.count = (
                SELECT COUNT(DISTINCT tr.object_id) 
                FROM $wpdb->term_relationships tr
                INNER JOIN $wpdb->posts p ON tr.object_id = p.ID
                WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
                AND p.post_status = 'publish'
                AND p.post_type IN ('product', 'product_variation')
            )
            WHERE tt.taxonomy = %s",
            $taxonomy
        ));
    }
    
    wp_cache_flush();
    
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }
}

/**
 * FAST CATALOGUE WIPE - Direct SQL deletion
 * Run this BEFORE the import to clear all products quickly
 * 
 * WARNING: This is destructive! Make a database backup first.
 */
function myplugin_delete_all_products_fast() {
    global $wpdb;
    
    $start_time = microtime(true);
    
    echo "=== FAST PRODUCT CATALOGUE WIPE ===\n";
    echo "WARNING: This will permanently delete ALL products and variations!\n";
    echo "Press Ctrl+C within 5 seconds to cancel...\n\n";
    sleep(5);
    
    echo "Starting deletion...\n";

    // 1. Get all product and variation IDs in 'Photographs' category
    echo "Finding products and variations in 'Photographs' category...\n";
    
    // First, get the term_taxonomy_id for 'Photographs' category
    $photos_cat_tt_id = $wpdb->get_var(
        "SELECT tt.term_taxonomy_id 
        FROM $wpdb->terms t
        INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = 'product_cat' 
        AND (t.slug = 'photographs' OR t.slug = 'photos' OR t.name = 'Photographs')
        LIMIT 1"
    );
    
    if (!$photos_cat_tt_id) {
        echo "ERROR: 'Photographs' category not found!\n";
        echo "Available categories:\n";
        $cats = $wpdb->get_results(
            "SELECT t.name, t.slug FROM $wpdb->terms t
            INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
            WHERE tt.taxonomy = 'product_cat'"
        );
        foreach ($cats as $cat) {
            echo "  - {$cat->name} (slug: {$cat->slug})\n";
        }
        return;
    }
    
    echo "✓ Found 'Photographs' category (term_taxonomy_id: $photos_cat_tt_id)\n";
    
    // Get all products in this category
    $product_ids = $wpdb->get_col(
        "SELECT DISTINCT p.ID 
        FROM $wpdb->posts p
        INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id
        WHERE p.post_type = 'product'
        AND tr.term_taxonomy_id = $photos_cat_tt_id"
    );
    
    // Also get all variations of these products
    if (!empty($product_ids)) {
        $parent_ids = implode(',', array_map('intval', $product_ids));
        $variation_ids = $wpdb->get_col(
            "SELECT ID FROM $wpdb->posts 
            WHERE post_type = 'product_variation' 
            AND post_parent IN ($parent_ids)"
        );
        
        // Combine products and their variations
        $product_ids = array_merge($product_ids, $variation_ids);
    }
    
    $count = count($product_ids);
    echo "Found $count products/variations to delete\n";
    
    if (empty($product_ids)) {
        echo "No products found. Catalogue is already empty.\n";
        return;
    }

    // Convert to comma-separated list for SQL
    $ids_list = implode(',', array_map('intval', $product_ids));

    // 2. Delete postmeta (fastest)
    echo "Deleting product meta...\n";
    $wpdb->query(
        "DELETE FROM $wpdb->postmeta 
        WHERE post_id IN ($ids_list)"
    );
    echo "✓ Meta deleted\n";

    // 3. Delete term relationships
    echo "Deleting term relationships...\n";
    $wpdb->query(
        "DELETE FROM $wpdb->term_relationships 
        WHERE object_id IN ($ids_list)"
    );
    echo "✓ Term relationships deleted\n";

    // 4. Delete posts
    echo "Deleting posts...\n";
    $wpdb->query(
        "DELETE FROM $wpdb->posts 
        WHERE ID IN ($ids_list)"
    );
    echo "✓ Posts deleted\n";

    // 5. Clean up orphaned attachments (optional - images)
    echo "Finding orphaned product images...\n";
    $orphaned_attachments = $wpdb->get_col(
        "SELECT ID FROM $wpdb->posts 
        WHERE post_type = 'attachment' 
        AND post_parent IN ($ids_list)"
    );
    
    if (!empty($orphaned_attachments)) {
        $att_ids = implode(',', array_map('intval', $orphaned_attachments));
        
        echo "Deleting " . count($orphaned_attachments) . " orphaned images...\n";
        $wpdb->query("DELETE FROM $wpdb->postmeta WHERE post_id IN ($att_ids)");
        $wpdb->query("DELETE FROM $wpdb->posts WHERE ID IN ($att_ids)");
        echo "✓ Orphaned images deleted\n";
    }

    // 6. Clean up term counts
    echo "Updating term counts...\n";
    $wpdb->query(
        "UPDATE $wpdb->term_taxonomy tt 
        SET tt.count = (
            SELECT COUNT(*) FROM $wpdb->term_relationships tr 
            WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
        )
        WHERE tt.taxonomy IN ('product_cat', 'product_tag', 'pa_size_or_type', 'photographer', 'product_type')"
    );
    echo "✓ Term counts updated\n";

    // 7. Clear WooCommerce transients
    echo "Clearing WooCommerce caches...\n";
    $wpdb->query(
        "DELETE FROM $wpdb->options 
        WHERE option_name LIKE '_transient_wc_%' 
        OR option_name LIKE '_transient_timeout_wc_%'"
    );
    echo "✓ Transients cleared\n";

    $elapsed = round(microtime(true) - $start_time, 2);
    
    echo "\n=== ✓ DELETION COMPLETE ===\n";
    echo "Deleted: $count products/variations\n";
    echo "Time: {$elapsed}s\n";
    echo "\nYou can now run the import safely.\n";
}

/**
 * COMBINED FUNCTION - Wipe then Import
 * Use this for a clean import every time
 */
function myplugin_wipe_and_import() {
    echo "=== FULL CATALOGUE REPLACEMENT ===\n\n";
    
    // Step 1: Wipe
    myplugin_delete_all_products_fast();
    
    echo "\n" . str_repeat("=", 50) . "\n\n";
    
    // IMPORTANT: Clear the static caches after deletion
    // This forces the import to rebuild tag/photographer caches
    brmsra_clear_import_caches();
    
    // Step 2: Import
    myplugin_run_import();
    
    echo "\n=== REPLACEMENT COMPLETE ===\n";
}

/**
 * PARTIAL UPDATE - Only updates products in the CSV
 * Deletes and recreates ONLY products whose SKUs are in the CSV
 * Leaves all other products untouched
 */
function myplugin_partial_import() {
    global $wpdb;
    
    echo "=== PARTIAL UPDATE MODE ===\n\n";
    
    $start_time = microtime(true);
    
    // 1. Read CSV to get list of SKUs
    $file = __DIR__ . '/loadfile.csv';
    if (!file_exists($file)) {
        echo "ERROR: CSV not found\n";
        return;
    }
    
    echo "Reading CSV to identify products to update...\n";
    $csv_data = file_get_contents($file);
    $csv_data = preg_replace('/^\xEF\xBB\xBF/', '', $csv_data);
    $lines = explode("\n", $csv_data);
    $headers = str_getcsv(array_shift($lines), '|');
    
    // Extract all SKUs from CSV
    $csv_skus = [];
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $row_data = str_getcsv($line, '|');
        if (count($row_data) !== count($headers)) continue;
        $data = array_combine($headers, $row_data);
        if (!empty($data['Reference Number'])) {
            $csv_skus[] = $data['Reference Number'];
        }
    }
    
    echo "✓ Found " . count($csv_skus) . " SKUs in CSV\n";
    
// 2. Find existing products with these SKUs
    if (!empty($csv_skus)) {
        echo "Checking which products already exist...\n";

        // Build IN clause manually (safer than wpdb->prepare with large arrays)
        $sku_list = "'" . implode("','", array_map('esc_sql', $csv_skus)) . "'";
        $existing_product_ids = $wpdb->get_results(
            "SELECT post_id, meta_value as sku 
            FROM $wpdb->postmeta 
            WHERE meta_key='_sku' 
            AND meta_value IN ($sku_list)"
        );

        // 3. Delete ONLY these specific products
        if (!empty($existing_product_ids)) {
            $product_ids = array_column($existing_product_ids, 'post_id');

            echo "Deleting " . count($product_ids) . " existing products...\n";

            // Get variations too
            $parent_ids = implode(',', array_map('intval', $product_ids));
            $variation_ids = $wpdb->get_col(
                "SELECT ID FROM $wpdb->posts 
                WHERE post_type = 'product_variation' 
                AND post_parent IN ($parent_ids)"
            );

            $all_ids = array_merge($product_ids, $variation_ids);
            $ids_list = implode(',', array_map('intval', $all_ids));

            // Delete meta
            $wpdb->query("DELETE FROM $wpdb->postmeta WHERE post_id IN ($ids_list)");

            // Delete term relationships
            $wpdb->query("DELETE FROM $wpdb->term_relationships WHERE object_id IN ($ids_list)");

            // Delete posts
            $wpdb->query("DELETE FROM $wpdb->posts WHERE ID IN ($ids_list)");

            echo "✓ Deleted " . count($all_ids) . " products and variations\n\n";
        } else {
            echo "✓ No existing products to delete (all new)\n\n";
        }
    } else {
        echo "✓ No SKUs to process\n\n";
    }
    
    // 4. Now run the normal import
    echo str_repeat("=", 50) . "\n\n";
    echo "Starting import of " . count($csv_skus) . " products...\n\n";
    
    myplugin_run_import();
    
    $total_time = round(microtime(true) - $start_time, 2);
    
    echo "\n=== PARTIAL UPDATE COMPLETE ===\n";
    echo "Time: {$total_time}s\n";
    echo "Updated: " . count($csv_skus) . " products\n";
}


/**
 * Clear static caches used during import
 */
function brmsra_clear_import_caches() {
    // This doesn't actually clear anything since static vars are per-execution
    // But we document that tags/photographers will be re-cached
    echo "Note: Tag and photographer caches will be rebuilt during import\n";
}
