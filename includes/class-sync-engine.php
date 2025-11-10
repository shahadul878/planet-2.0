<?php
/**
 * Sync Engine for Planet Product Sync 2.0
 * Implements the 3-step sync workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Planet_Sync_Engine {
    
    private $api;
    private $logger;
    private $queue;
    
    public function __construct() {
        $this->api = new Planet_Sync_API();
        $this->logger = new Planet_Sync_Logger();
        $this->queue = new Planet_Sync_Queue();
    }
    
    /**
     * Step 1: Validate Level 1 Categories
     * Compare Planet L1 categories with WooCommerce and create missing ones
     * 
     * @return array Validation result
     */
    public function validate_level_1_categories() {
        $this->logger->log('category', 'info', '', 'Validating level 1 categories');
        
        // Fetch L1 categories from API
        $planet_categories = $this->api->get_first_level_categories();
        
        if ($planet_categories === false) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch level 1 categories from Planet API'
            );
        }
        
        // Get WooCommerce top-level categories
        $woo_categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'parent' => 0,
            'hide_empty' => false
        ));
        
        $woo_cats_by_name = array();
        foreach ($woo_categories as $cat) {
            $woo_cats_by_name[strtolower($cat->name)] = $cat;
        }
        
        $matches = 0;
        $created = 0;
        $errors = 0;
        
        foreach ($planet_categories as $planet_cat) {
            $cat_name = isset($planet_cat['name']) ? $planet_cat['name'] : '';
            $cat_id = isset($planet_cat['id']) ? $planet_cat['id'] : '';
            $cat_description = $this->extract_category_description($planet_cat);
            
            if (empty($cat_name)) {
                continue;
            }
            
            $cat_name_lower = strtolower($cat_name);
            
            if (isset($woo_cats_by_name[$cat_name_lower])) {
                // Category exists
                $matches++;
                $woo_cat = $woo_cats_by_name[$cat_name_lower];
                $update_args = array();
                
                if (!empty($cat_description)) {
                    $current_description = get_term_field('description', $woo_cat->term_id, 'product_cat');
                    if (is_wp_error($current_description)) {
                        $current_description = '';
                    }
                    if ($current_description !== $cat_description) {
                        $update_args['description'] = wp_kses_post($cat_description);
                    }
                }
                
                if (!empty($update_args)) {
                    $updated_term = wp_update_term($woo_cat->term_id, 'product_cat', $update_args);
                    if (is_wp_error($updated_term)) {
                        $errors++;
                        $this->logger->log('category', 'error', $cat_name, 'Failed to update category: ' . $updated_term->get_error_message());
                    } else {
                        $this->logger->log('category', 'update', $cat_name, 'Category details updated');
                    }
                } else {
                    $this->logger->log('category', 'skip', $cat_name, 'Category already up to date');
                }
                
                if ($cat_id) {
                    update_term_meta($woo_cat->term_id, '_planet_category_id', $cat_id);
                }
            } else {
                // Create new category
                $insert_args = array(
                    'parent' => 0
                );
                
                if (!empty($cat_description)) {
                    $insert_args['description'] = wp_kses_post($cat_description);
                }
                
                $term_data = wp_insert_term($cat_name, 'product_cat', $insert_args);
                
                if (is_wp_error($term_data)) {
                    $errors++;
                    $this->logger->log('category', 'error', $cat_name, 'Failed to create: ' . $term_data->get_error_message());
                } else {
                    $created++;
                    // Store Planet category ID as term meta
                    update_term_meta($term_data['term_id'], '_planet_category_id', $cat_id);
                    $this->logger->log('category', 'create', $cat_name, 'Created level 1 category');
                }
            }
        }
        
        return array(
            'success' => true,
            'total' => count($planet_categories),
            'matched' => $matches,
            'created' => $created,
            'errors' => $errors,
            'message' => sprintf('Validated %d categories: %d matched, %d created, %d errors', 
                count($planet_categories), $matches, $created, $errors)
        );
    }

    /**
     * Extract category description from API payload
     *
     * @param array $planet_category
     * @return string
     */
    private function extract_category_description($planet_category) {
        $possible_keys = array('description', 'desc', 'overview', 'content');
        
        foreach ($possible_keys as $key) {
            if (!empty($planet_category[$key]) && is_string($planet_category[$key])) {
                return $planet_category[$key];
            }
        }
        
        return '';
    }
    
    /**
     * Step 2: Fetch and Store Product List
     * Fetch basic product list from API and store temporarily
     * 
     * @return array Fetch result
     */
    public function fetch_product_list() {
        $this->logger->log('product', 'info', '', 'Fetching product list from API');
        
        // Fetch product list
        $products = $this->api->get_product_list();
        
        if ($products === false) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch product list from Planet API'
            );
        }
        
        if (empty($products) || !is_array($products)) {
            return array(
                'success' => false,
                'message' => 'Product list is empty or invalid'
            );
        }
        
        // Initialize sync queue
        $queue_result = $this->queue->initialize_queue($products);
        
        // Store product list in options table (for backward compatibility)
        update_option('planet_temp_product_list', $products, false);
		update_option('planet_product_list', $products);
        update_option('planet_temp_sync_started', time(), false);
        
        // Initialize progress with queue statistics
        $stats = $this->queue->get_statistics($queue_result['batch_id']);
        update_option('planet_sync_progress', array(
            'stage' => 'processing',
            'current' => 0,
            'total' => $stats['total'],
            'percentage' => 0,
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
            'pending' => $stats['total']
        ), false);
        
        $this->logger->log('product', 'info', '', sprintf('Initialized sync queue with %d products (Batch: %s)', $stats['total'], $queue_result['batch_id']));
        
        return array(
            'success' => true,
            'total' => $stats['total'],
            'batch_id' => $queue_result['batch_id'],
            'message' => sprintf('Fetched %d products', $stats['total'])
        );
    }
    
    /**
     * Step 3: Process All Products (Legacy - processes all at once)
     * Loop through product list, fetch full details, and create/update WooCommerce products
     * 
     * @return array Process result
     */
    public function process_all_products() {
        $product_list = get_option('planet_temp_product_list', array());
        
        if (empty($product_list)) {
            return array(
                'success' => false,
                'message' => 'No product list found. Run fetch_product_list() first.'
            );
        }
        
        $total = count($product_list);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        
        $this->logger->log('product', 'info', '', sprintf('Processing %d products', $total));
        
        foreach ($product_list as $index => $product_item) {
            // Update progress
            $current = $index + 1;
            update_option('planet_sync_progress', array(
                'stage' => 'processing',
                'current' => $current,
                'total' => $total,
                'percentage' => round(($current / $total) * 100)
            ), false);
            
            // Extract slug from product item
            $slug = $this->extract_slug($product_item);
            
            if (empty($slug)) {
                $errors++;
                $this->logger->log('product', 'error', '', 'Product slug not found in list item');
                continue;
            }
            
            // Fetch full product details
            $full_product = $this->api->get_product_by_slug($slug);
            
            if ($full_product === false) {
                $errors++;
                $this->logger->log('product', 'error', $slug, 'Failed to fetch product details');
                continue;
            }
            
            // Process the product
            $result = $this->process_single_product($slug, $full_product);
            
            switch ($result) {
                case 'created':
                    $created++;
                    break;
                case 'updated':
                    $updated++;
                    break;
                case 'skipped':
                    $skipped++;
                    break;
                case 'error':
                    $errors++;
                    break;
            }
            
            // Rate limiting - wait 4 seconds between API calls
            if ($current < $total) {
                sleep(4);
            }
        }
        
        // Clean up temp data
        delete_option('planet_temp_product_list');
        delete_option('planet_temp_sync_started');
        delete_option('planet_sync_progress');
        
        $this->logger->log('product', 'info', '', sprintf(
            'Processing complete: %d created, %d updated, %d skipped, %d errors',
            $created, $updated, $skipped, $errors
        ));
        
        return array(
            'success' => true,
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => sprintf('Processed %d products: %d created, %d updated, %d skipped, %d errors',
                $total, $created, $updated, $skipped, $errors)
        );
    }
    
    /**
     * Process next single product in queue (for real-time sync)
     * 
     * @return array Process result with product details
     */
    public function process_next_product() {
        // Get next pending product from queue
        $queue_item = $this->queue->get_next_pending();
        
        if (!$queue_item) {
            // No more products to process
            $stats = $this->queue->get_statistics();
            $batch_id = get_option('planet_current_sync_batch', '');
            
            if ($batch_id) {
                $this->queue->cleanup_batch($batch_id);
            }
            
            $this->logger->log('product', 'info', '', sprintf(
                'Sync complete: %d synced, %d failed, %d skipped',
                $stats['synced'], $stats['failed'], $stats['skipped']
            ));
            
            return array(
                'success' => true,
                'complete' => true,
                'message' => 'All products processed',
                'statistics' => $stats
            );
        }
        
        $slug = $queue_item->product_slug;
        
        // Increment attempt count
        $this->queue->increment_attempts($queue_item->id);
        
        // Fetch full product details
        $this->logger->log('product', 'info', $slug, 'Fetching product details from API');
        $full_product = $this->api->get_product_by_slug($slug);
        
        if ($full_product === false) {
            $this->queue->mark_as_synced($queue_item->id, 'failed', 'Failed to fetch product details from API');
            $this->logger->log('product', 'error', $slug, 'Failed to fetch product details from API');
            
            $stats = $this->queue->get_statistics();
            $this->update_progress($stats);
            
            return array(
                'success' => false,
                'complete' => false,
                'slug' => $slug,
                'result' => 'error',
                'message' => 'Failed to fetch product details',
                'current' => $stats['total'] - $stats['pending'],
                'total' => $stats['total'],
                'statistics' => $stats
            );
        }
          
        // Process the product
        $result = $this->process_single_product($slug, $full_product);
        
        // Mark as completed in queue
        $status_map = array(
            'created' => 'synced',
            'updated' => 'synced',
            'skipped' => 'skipped',
            'error' => 'failed'
        );
        $queue_status = isset($status_map[$result]) ? $status_map[$result] : 'failed';
        $message = ucfirst($result) . ': ' . (isset($full_product['desc']) ? $full_product['desc'] : $slug);
        
        $this->queue->mark_as_synced($queue_item->id, $queue_status, $message);
        
        // Get updated statistics
        $stats = $this->queue->get_statistics();
        $this->update_progress($stats);
        
        return array(
            'success' => true,
            'complete' => false,
            'slug' => $slug,
            'name' => isset($full_product['desc']) ? $full_product['desc'] : $slug,
            'result' => $result,
            'message' => $message,
            'current' => $stats['total'] - $stats['pending'],
            'total' => $stats['total'],
            'statistics' => $stats
        );
    }
    
    /**
     * Process a single product
     * 
     * @param string $slug Product slug
     * @param array $product_data Full product data
     * @return string Result (created|updated|skipped|error)
     */
    private function process_single_product($slug, $product_data) {
        try {
            // Generate MD5 hash of product data
            $current_hash = md5(json_encode($product_data));
            
            // Find existing product
            $existing_product = $this->find_existing_product($slug, $product_data);
            
            if ($existing_product) {
                // Check if product has changed
                $stored_hash = get_post_meta($existing_product->get_id(), '_planet_product_hash', true);
                
                // If hash is missing, update and add hash
                if (empty($stored_hash)) {
                    $this->update_woo_product($existing_product->get_id(), $product_data, $current_hash);
                    $this->logger->log('product', 'update', $slug, 'Product updated and hash added');
                    return 'updated';
                }
                
                // If hash exists and matches, skip update
                if ($stored_hash === $current_hash) {
                    $this->logger->log('product', 'skip', $slug, 'No changes detected');
                    return 'skipped';
                }
                
                // Hash exists but different, update product
                $this->update_woo_product($existing_product->get_id(), $product_data, $current_hash);
                $this->logger->log('product', 'update', $slug, 'Product updated');
                return 'updated';
            } else {
                // Create new product
                $product_id = $this->create_woo_product($product_data, $current_hash);
                if ($product_id) {
                    $this->logger->log('product', 'create', $slug, 'Product created');
                    return 'created';
                } else {
                    $this->logger->log('product', 'error', $slug, 'Failed to create product');
                    return 'error';
                }
            }
        } catch (Exception $e) {
            $this->logger->log('product', 'error', $slug, 'Exception: ' . $e->getMessage());
            return 'error';
        }
    }
    
    /**
     * Find existing product by slug or product code
     * 
     * @param string $slug Product slug
     * @param array $product_data Product data
     * @return WC_Product|null
     */
    private function find_existing_product($slug, $product_data) {
        // Try by post name (slug)
        $args = array(
            'post_type' => 'product',
            'name' => $slug,
            'posts_per_page' => 1,
            'fields' => 'ids'
        );
        $products = get_posts($args);
        
        if (!empty($products)) {
            return wc_get_product($products[0]);
        }

        // Try by product_code meta (Planet API 'name' field)
        $product_code = isset($product_data['name']) ? $product_data['name'] : '';
        if ($product_code) {
            global $wpdb;
            $product_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = 'product_code' AND meta_value = %s LIMIT 1",
                $product_code
            ));
            
            if ($product_id) {
                return wc_get_product($product_id);
            }
        }
        
        return null;
    }
    
    /**
     * Create new WooCommerce product
     * 
     * @param array $product_data Product data from API
     * @param string $hash MD5 hash
     * @return int|false Product ID or false
     */
    private function create_woo_product($product_data, $hash) {
        $product = new WC_Product_Simple();
        $overview = $this->download_and_replace_images_in_html($product_data['overview'], 0);
        // Set basic data using Planet API field names
        $product->set_name($product_data['desc'] ?? 'Untitled Product');  // 'desc' = Product Title
        $product->set_slug($product_data['slug'] ?? '');
        $product->set_description($overview ?? '');  // 'overview' = Product Description

        
//        // Set price (if provided by API)
//        if (isset($product_data['price'])) {
//            $product->set_regular_price($product_data['price']);
//        }
//        if (isset($product_data['sale_price'])) {
//            $product->set_sale_price($product_data['sale_price']);
//        }
//
//        // Set stock (if provided by API)
//        if (isset($product_data['stock_status'])) {
//            $product->set_stock_status($product_data['stock_status']);
//        } else {
//            $product->set_stock_status('instock');  // Default to in stock
//        }
//
//        if (isset($product_data['manage_stock']) && $product_data['manage_stock']) {
//            $product->set_manage_stock(true);
//            if (isset($product_data['stock_quantity'])) {
//                $product->set_stock_quantity($product_data['stock_quantity']);
//            }
//        }
        
        // Save product
        $product_id = $product->save();
        
        if (!$product_id) {
            return false;
        }
        
        // Set categories
        $this->sync_product_categories($product_id, $product_data);
        
        // Set images
        $this->sync_product_images($product_id, $product_data);
        
        // Store Planet meta data
        update_post_meta($product_id, '_planet_product_hash', $hash);
        update_post_meta($product_id, '_planet_id', $product_data['id'] ?? '');
        update_post_meta($product_id, 'product_code', $product_data['name'] ?? '');
        update_post_meta($product_id, '_planet_slug', $product_data['slug'] ?? '');
        
        // Store category IDs for reference
        // if (isset($product_data['category_ids'])) {
        //     update_post_meta($product_id, '_planet_category_ids', json_encode($product_data['category_ids']));
        // }

	    // Update additional custom fields
	    if (isset($product_data['applications'])) {
		    $applications = $this->download_and_replace_images_in_html($product_data['applications'], $product_id);
		    update_post_meta($product_id, 'applications_tab', $applications);
	    }
	    if (isset($product_data['keyfeatures'])) {
		    $keyfeatures = $this->download_and_replace_images_in_html($product_data['keyfeatures'], $product_id);
		    update_post_meta($product_id, 'key_features_tab', $keyfeatures);
	    }
	    if (isset($product_data['specifications'])) {
		    $specifications_html = $this->format_specifications_as_table($product_data['specifications']);
		    update_post_meta($product_id, 'specifications_tab', $specifications_html);
	    }
        
        return $product_id;
    }
    
    /**
     * Update existing WooCommerce product
     * 
     * @param int $product_id Product ID
     * @param array $product_data Product data from API
     * @param string $hash MD5 hash
     */
    private function update_woo_product($product_id, $product_data, $hash) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return;
        }
        
        $overview = $this->download_and_replace_images_in_html($product_data['overview'], $product_id);
        // Update basic data using Planet API field names
        $product->set_name($product_data['desc'] ?? 'Untitled Product');  // 'desc' = Product Title
        $product->set_description($overview?? '');  // 'overview' = Product Description
        
        // Update price (if provided by API)
//        if (isset($product_data['price'])) {
//            $product->set_regular_price($product_data['price']);
//        }
//        if (isset($product_data['sale_price'])) {
//            $product->set_sale_price($product_data['sale_price']);
//        }
//
//        // Update stock (if provided by API)
//        if (isset($product_data['stock_status'])) {
//            $product->set_stock_status($product_data['stock_status']);
//        }
//        if (isset($product_data['manage_stock']) && $product_data['manage_stock']) {
//            $product->set_manage_stock(true);
//            if (isset($product_data['stock_quantity'])) {
//                $product->set_stock_quantity($product_data['stock_quantity']);
//            }
//        }
        
        // Save product
        $product->save();
        // // Normalize first-level category IDs before syncing
        // $product_data['category_ids'] = $this->extract_first_level_category_ids($product_data);
        
        // // Update categories
        // // Normalize first-level category IDs before syncing
        // $product_data['category_ids'] = $this->extract_first_level_category_ids($product_data);
        
        // Update categories
        $this->sync_product_categories($product_id, $product_data);
        
        // Update images
        $this->sync_product_images($product_id, $product_data);
        
        // Update Planet meta data
        update_post_meta($product_id, '_planet_product_hash', $hash);
        update_post_meta($product_id, '_planet_id', $product_data['id'] ?? '');
        update_post_meta($product_id, 'product_code', $product_data['name'] ?? '');
        update_post_meta($product_id, '_planet_slug', $product_data['slug'] ?? '');
        
        // // Update category IDs for reference
        // if (isset($product_data['category_ids'])) {
        //     update_post_meta($product_id, '_planet_category_ids', json_encode($product_data['category_ids']));
        // }

	    // Update additional custom fields
	    if (isset($product_data['applications'])) {
		    $applications = $this->download_and_replace_images_in_html($product_data['applications'], $product_id);
		    update_post_meta($product_id, 'applications_tab', $applications);
	    }
	    if (isset($product_data['keyfeatures'])) {
		    $keyfeatures = $this->download_and_replace_images_in_html($product_data['keyfeatures'], $product_id);
		    update_post_meta($product_id, 'key_features_tab', $keyfeatures);
	    }
	    if (isset($product_data['specifications'])) {
		    $specifications_html = $this->format_specifications_as_table($product_data['specifications']);
		    update_post_meta($product_id, 'specifications_tab', $specifications_html);
	    }
    }
    
    /**
     * Sync product categories (Level 1 only)
     * 
     * @param int $product_id Product ID
     * @param array $product_data Product data
     */
    private function sync_product_categories($product_id, $product_data) {
        global $wpdb;

        $woo_term_ids = array();
        $category_slugs = array();

        

        // Fallback to queue table slugs when API payload lacks them
        if (empty($category_slugs)) {
            $slug_for_lookup = isset($product_data['slug']) ? $product_data['slug'] : '';

            if (empty($slug_for_lookup) && !empty($product_data['id'])) {
                $slug_for_lookup = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT product_slug FROM {$wpdb->prefix}planet_sync_queue WHERE product_id = %s ORDER BY id DESC LIMIT 1",
                        $product_data['id']
                    )
                );
            }

            if (!empty($slug_for_lookup)) {
                $queue_slugs = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT product_cat FROM {$wpdb->prefix}planet_sync_queue WHERE product_slug = %s AND product_cat <> '' ORDER BY id DESC",
                        $slug_for_lookup
                    )
                );

                if (!empty($queue_slugs)) {
                    foreach ($queue_slugs as $queue_slug) {
                        $category_slugs[] = sanitize_title($queue_slug);
                    }
                }
            }
        }

        if (!empty($category_slugs)) {
            foreach ($category_slugs as $slug) {
                $term = get_term_by('slug', $slug, 'product_cat');

                if ($term instanceof WP_Term) {
                    $woo_term_ids[] = (int) $term->term_id;
                    $this->logger->log('product', 'info', $product_id,
                        'Assigned to category (slug): ' . $slug);
                } else {
                    $this->logger->log('product', 'warning', $product_id,
                        'Category slug not found in WooCommerce: ' . $slug);
                }
            }
        }

        // Additional fallback to Planet category IDs if term slugs could not be resolved
        if (empty($woo_term_ids) && isset($product_data['category_ids']) && is_array($product_data['category_ids'])) {
            $planet_category_ids = $product_data['category_ids'];

            foreach ($planet_category_ids as $planet_id) {
                $terms = get_terms(array(
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false,
                    'meta_query' => array(
                        array(
                            'key' => '_planet_category_id',
                            'value' => $planet_id,
                            'compare' => '='
                        )
                    )
                ));

                if (!empty($terms) && !is_wp_error($terms)) {
                    $woo_term_ids[] = (int) $terms[0]->term_id;
                    $this->logger->log('product', 'info', $product_id,
                        'Assigned to category: ' . $terms[0]->name . ' (Planet ID: ' . $planet_id . ')');
                }
            }
        }

        $woo_term_ids = array_values(array_unique(array_filter($woo_term_ids)));

        if (!empty($woo_term_ids)) {
            $result = wp_set_object_terms($product_id, $woo_term_ids, 'product_cat');

            if (is_wp_error($result)) {
                $this->logger->log('product', 'error', $product_id,
                    'Failed to assign categories: ' . $result->get_error_message());
            } else {
                $this->logger->log('product', 'info', $product_id,
                    'Successfully assigned ' . count($woo_term_ids) . ' categories');
            }
        } else {
            $this->logger->log('product', 'info', $product_id,
                'No categories found to assign');
        }
    }
    
    /**
     * Extract first-level Planet category IDs from API product payload
     *
     * @param array $product_data
     * @return array
     */
    private function extract_first_level_category_ids($product_data) {
        $category_ids = array();
        
        if (!empty($product_data['1st_categories']) && is_array($product_data['1st_categories'])) {
            foreach ($product_data['1st_categories'] as $category) {
                if (!empty($category['id'])) {
                    $category_ids[] = (int) $category['id'];
                }
            }
        }
        
        return array_values(array_unique($category_ids));
    }
    
    
    /**
     * Sync product images
     * 
     * @param int $product_id Product ID
     * @param array $product_data Product data
     */
    private function sync_product_images($product_id, $product_data) {
        // Main image
        if (isset($product_data['image']) && !empty($product_data['image'])) {
            $attachment_id = $this->api->download_image($product_data['image']);
            if ($attachment_id) {
                set_post_thumbnail($product_id, $attachment_id);
            }
        }
        
        // Gallery images
        if (isset($product_data['gallery']) && is_array($product_data['gallery'])) {
            $gallery_ids = array();
            foreach ($product_data['gallery'] as $gallery_item) {
                // Extract image URL from gallery item (can be string or array with 'image' key)
                $image_url = is_array($gallery_item) && isset($gallery_item['image']) 
                    ? $gallery_item['image'] 
                    : $gallery_item;
                
                if (!empty($image_url) && is_string($image_url)) {
                    $attachment_id = $this->api->download_image($image_url);
                    if ($attachment_id) {
                        $gallery_ids[] = $attachment_id;
                    }
                }
            }
            if (!empty($gallery_ids)) {
                update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
            }
        }
    }
    
    /**
     * Extract slug from product list item
     * 
     * @param mixed $product_item Product item from list
     * @return string Slug
     */
    private function extract_slug($product_item) {
        if (is_array($product_item)) {
            return $product_item['slug'] ?? '';
        } elseif (is_string($product_item)) {
            return $product_item;
        }
        return '';
    }
    
    /**
     * Get current sync progress
     * With transient caching to reduce database queries
     * 
     * @return array Progress data
     */
    public function get_sync_progress() {
        // Try cache first (cached for 3 seconds)
        $cached = get_transient('planet_sync_progress_cache');
        if ($cached !== false) {
            return $cached;
        }
        
        $progress = get_option('planet_sync_progress', array());
        
        if (empty($progress)) {
            $result = array(
                'is_running' => false,
                'stage' => 'idle',
                'current' => 0,
                'total' => 0,
                'percentage' => 0,
                'synced' => 0,
                'failed' => 0,
                'skipped' => 0,
                'pending' => 0
            );
        } else {
            $result = array_merge(array('is_running' => true), $progress);
        }
        
        // Cache for 3 seconds to reduce database load
        set_transient('planet_sync_progress_cache', $result, 3);
        
        return $result;
    }
    
    /**
     * Update progress with queue statistics
     * 
     * @param array $stats Queue statistics
     */
    private function update_progress($stats) {
        $current = $stats['total'] - $stats['pending'];
        $total = $stats['total'];
        $percentage = $total > 0 ? round(($current / $total) * 100) : 0;
        
        update_option('planet_sync_progress', array(
            'stage' => 'processing',
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
            'synced' => $stats['synced'],
            'failed' => $stats['failed'],
            'skipped' => $stats['skipped'],
            'pending' => $stats['pending']
        ), false);
        
        // Clear cache
        delete_transient('planet_sync_progress_cache');
    }
    
    /**
     * Get queue statistics
     * 
     * @return array Queue statistics
     */
    public function get_queue_statistics() {
        return $this->queue->get_statistics();
    }
    
    /**
     * Retry failed products
     * 
     * @return int Number of products reset for retry
     */
    public function retry_failed_products() {
        $reset_count = $this->queue->reset_failed_to_pending();
        $this->logger->log('system', 'info', '', sprintf('Reset %d failed products for retry', $reset_count));
        
        // Update progress
        if ($reset_count > 0) {
            $stats = $this->queue->get_statistics();
            $this->update_progress($stats);
        }
        
        return $reset_count;
    }
    
    /**
     * Get failed products list
     * 
     * @return array Failed products
     */
    public function get_failed_products() {
        return $this->queue->get_failed_products();
    }

	/**
	 * Insert external media into WordPress Media Library (with URL & filename checks)
	 *
	 * @param string $image_url External image URL
	 * @param int    $post_id   Optional post ID to attach media
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure
	 */
	private function download_image($image_url, int $post_id = 0 ) {
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		global $wpdb;

		// Sanitize and extract filename
		$filename = basename( parse_url( $image_url, PHP_URL_PATH ) );

		// 1. Check if an attachment already exists by original URL
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_original_image_url' 
             AND meta_value = %s 
             LIMIT 1",
				$image_url
			)
		);
		if ( $attachment_id ) {
			return (int) $attachment_id;
		}

		// 2. Check if an attachment already exists by filename
		$existing = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_query'     => array(
				array(
					'key'     => '_wp_attached_file',
					'value'   => $filename,
					'compare' => 'LIKE',
				),
			),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );
		if ( ! empty( $existing ) ) {
			return $existing[0];
		}

		// 3. Download the file to a temporary location
		$tmp = download_url( $image_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// 4. Prepare file array for sideload
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// 5. Sideload the file into Media Library
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// Cleanup temp file if error
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $file_array['tmp_name'] );
			return $attachment_id;
		}

		// 6. Generate full attachment metadata (thumbnails, sizes)
		$attach_data = wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		// 7. Save original URL in custom meta
		update_post_meta( $attachment_id, '_original_image_url', $image_url );

		return $attachment_id;
	}

	/**
	 * Download and replace images in HTML
	 *
	 * @param string $html HTML content
	 * @param int $post_id Post ID
	 * @return string Updated HTML
	 */
	private function download_and_replace_images_in_html($html, $post_id) {
		if (empty($html)) return $html;

		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		$imgs = $dom->getElementsByTagName('img');
		$img_nodes = [];
		foreach ($imgs as $img) $img_nodes[] = $img;

		foreach ($img_nodes as $img) {
			$src = $img->getAttribute('src');
			if (empty($src) || strpos($src, home_url()) !== false) continue;

			if (strpos($src, 'http') !== 0) {
				if (!defined('PLANET_API_BASE_URL')) continue;
				$src = rtrim(PLANET_API_BASE_URL, '/') . '/' . ltrim($src, '/');
			}

			$attachment_id = $this->download_image($src, $post_id);

			if ($attachment_id && !is_wp_error($attachment_id)) {
				$new_url = wp_get_attachment_url($attachment_id);
				$img->setAttribute('src', $new_url);
			} else {
				$this->logger->log('system', 'error', '', sprintf('Failed to download image: ', $src));
			}
		}

		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body) {
			$html = '';
			foreach ($body->childNodes as $node) {
				$html .= $dom->saveHTML($node);
			}
			return $html;
		}

		return $dom->saveHTML();
	}


	/**
	 * Format specifications as HTML table
	 *
	 * @param array $specifications Specifications array
	 * @return string HTML table
	 */
	private function format_specifications_as_table($specifications = array()) {
		if (empty($specifications) || !is_array($specifications)) {
			return '';
		}

		$html = '<table style="width:100%;" class="common_table_sky">';

		foreach ($specifications as $spec_group) {
			if (empty($spec_group['title']) || empty($spec_group['details'])) {
				continue;
			}

			$html .= '<thead>';
			$html .= '<tr>';
			$html .= '<th colspan="2">' . esc_html($spec_group['title']) . '</th>';
			$html .= '</tr>';
			$html .= '</thead>';

			$html .= '<tbody>';
			foreach ($spec_group['details'] as $spec) {
				if (empty($spec['title']) || empty($spec['desc'])) {
					continue;
				}

				$desc = nl2br($spec['desc']);

				$html .= '<tr>';
				$html .= '<td class="align-middle">' . esc_html($spec['title']) . '</td>';
				$html .= '<td>' . $desc . '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody>';
		}

		$html .= '</table>';

		return $html;
	}
}

