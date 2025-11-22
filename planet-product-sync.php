<?php
/**
 * Plugin Name: Planet Product Sync 2.0
 * Plugin URI: https://github.com/shahadul878
 * Description: Advanced product sync from Planet.com.tw API to WooCommerce with streamlined 3-step workflow and MD5 hash detection
 * Version: 2.0.1
 * Author: H M Shahadul Islam
 * Author URI: https://github.com/shahadul878
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: planet-product-sync
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PLANET_SYNC_VERSION', '2.0.1');
define('PLANET_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PLANET_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PLANET_SYNC_PLUGIN_FILE', __FILE__);
define('PLANET_API_BASE_URL', 'https://www.planet.com.tw');

// Check if WooCommerce is active
function planet_sync_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php _e('Planet Product Sync 2.0 requires WooCommerce to be installed and activated.', 'planet-product-sync'); ?></p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

// Include required files
function planet_sync_load_classes() {
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-database.php';
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-logger.php';
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-api.php';
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-sync-queue.php';
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-sync-engine.php';
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-admin.php';
}

// Plugin activation
function planet_sync_activate() {
    if (!planet_sync_check_woocommerce()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Planet Product Sync 2.0 requires WooCommerce to be installed and activated.', 'planet-product-sync'));
    }
    
    // Create database tables
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-database.php';
    Planet_Sync_Database::create_tables();
    
    // Create sync queue table
    require_once PLANET_SYNC_PLUGIN_DIR . 'includes/class-sync-queue.php';
    $queue = new Planet_Sync_Queue();
    $queue->create_table();
    
    // Set default options
    add_option('planet_sync_api_key', '6cbdd98d66a8d0d4d171ce12a75b4c90104214f7b116d5862b8f8e4d9b6ef663');
    add_option('planet_sync_auto_sync', 'no');
    add_option('planet_sync_frequency', 'hourly');
    add_option('planet_sync_daily_time', '02:00');
    add_option('planet_sync_debug_mode', 'no');
}
register_activation_hook(__FILE__, 'planet_sync_activate');

// Plugin deactivation
function planet_sync_deactivate() {
    // Clear scheduled events
    planet_sync_clear_cron_schedule();
    
    // Clear any temp data
    delete_option('planet_temp_product_list');
    delete_option('planet_temp_sync_started');
    delete_option('planet_sync_progress');
    delete_option('planet_current_sync_batch');
    delete_transient('planet_sync_progress_cache');
}
register_deactivation_hook(__FILE__, 'planet_sync_deactivate');

// Initialize plugin
function planet_sync_init() {
    if (!planet_sync_check_woocommerce()) {
        return;
    }
    
    planet_sync_load_classes();
    
    // Initialize admin interface
    if (is_admin()) {
        new Planet_Sync_Admin();
    }
    
    // Setup cron if auto-sync is enabled
    if (get_option('planet_sync_auto_sync') === 'yes') {
        planet_sync_setup_cron();
    }
}
add_action('plugins_loaded', 'planet_sync_init');

/**
 * Remove product from "New Products" table when manually edited
 * This hook fires when a product is saved/updated manually
 */
function planet_sync_remove_from_new_products_on_edit($post_id, $post, $update) {
    // Only process product post type
    if ($post->post_type !== 'product') {
        return;
    }
    
    // Skip if this is a new post (not an update)
    if (!$update) {
        return;
    }
    
    // Skip if sync is in progress (to avoid removing during sync)
    if (get_transient('planet_sync_in_progress')) {
        return;
    }
    
    // Skip autosaves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (wp_is_post_revision($post_id)) {
        return;
    }
    
    // Get product identifier
    $product = wc_get_product($post_id);
    if (!$product) {
        return;
    }
    
    // Try to get Planet slug from meta, otherwise use product slug
    $planet_slug = get_post_meta($post_id, '_planet_slug', true);
    $product_slug = $product->get_slug();
    $identifier = !empty($planet_slug) ? $planet_slug : $product_slug;
    
    if (empty($identifier)) {
        // Fallback to product ID
        $identifier = (string) $post_id;
    }
    
    // Delete 'create' log entries for this product
    global $wpdb;
    $log_table = $wpdb->prefix . 'planet_sync_log';
    
    // Delete by identifier (slug)
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$log_table} 
        WHERE type = 'product' 
        AND action = 'create' 
        AND slug_or_id = %s",
        $identifier
    ));
    
    // Also try with product ID as fallback
    if ($deleted === 0 && !is_numeric($identifier)) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$log_table} 
            WHERE type = 'product' 
            AND action = 'create' 
            AND slug_or_id = %d",
            $post_id
        ));
    }
}
add_action('save_post_product', 'planet_sync_remove_from_new_products_on_edit', 10, 3);

// Calculate next daily timestamp using site timezone
function planet_sync_get_next_daily_timestamp($time_string) {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time_string)) {
        $time_string = '02:00';
    }

    list($hour, $minute) = array_map('intval', explode(':', $time_string));

    if (function_exists('wp_timezone')) {
        $timezone = wp_timezone();
    } else {
        $timezone_string = get_option('timezone_string');
        if (empty($timezone_string)) {
            $timezone_string = 'UTC';
        }
        $timezone = new DateTimeZone($timezone_string);
    }
    $now      = new DateTime('now', $timezone);
    $target   = clone $now;
    $target->setTime($hour, $minute, 0);

    if ($target <= $now) {
        $target->modify('+1 day');
    }

    return $target->getTimestamp();
}

// Remove any scheduled sync events
function planet_sync_clear_cron_schedule() {
    while ($timestamp = wp_next_scheduled('planet_auto_sync')) {
        wp_unschedule_event($timestamp, 'planet_auto_sync');
    }

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('planet_auto_sync');
    }
}

// Setup automatic sync
function planet_sync_setup_cron($force = false) {
    $auto_sync = get_option('planet_sync_auto_sync', 'no');

    if ($auto_sync !== 'yes') {
        if ($force) {
            planet_sync_clear_cron_schedule();
        }
        return;
    }

    $frequency   = get_option('planet_sync_frequency', 'hourly');
    $daily_time  = get_option('planet_sync_daily_time', '02:00');
    $interval    = ($frequency === 'daily') ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
    $start_time  = ($frequency === 'daily')
        ? planet_sync_get_next_daily_timestamp($daily_time)
        : time() + HOUR_IN_SECONDS;

    if (function_exists('as_next_scheduled_action')) {
        $next = as_next_scheduled_action('planet_auto_sync');

        if ($force) {
            as_unschedule_all_actions('planet_auto_sync');
            $next = false;
        } elseif ($frequency === 'daily' && $next) {
            $expected = planet_sync_get_next_daily_timestamp($daily_time);
            if (abs($next - $expected) > 120) {
                as_unschedule_all_actions('planet_auto_sync');
                $next = false;
            }
        }

        if (!$next) {
            as_schedule_recurring_action($start_time, $interval, 'planet_auto_sync');
        }
    } else {
        $next = wp_next_scheduled('planet_auto_sync');

        if ($force) {
            planet_sync_clear_cron_schedule();
            $next = false;
        } elseif ($frequency === 'daily' && $next) {
            $expected = planet_sync_get_next_daily_timestamp($daily_time);
            if (abs($next - $expected) > 120) {
                planet_sync_clear_cron_schedule();
                $next = false;
            }
        }

        if (!$next) {
            $recurrence = ($frequency === 'daily') ? 'daily' : 'hourly';
            wp_schedule_event($start_time, $recurrence, 'planet_auto_sync');
        }
    }
}

// Main sync function
function planet_sync_all() {
    try {
        $logger = new Planet_Sync_Logger();
        $logger->log('system', 'sync_start', '', 'Starting Planet Sync 2.0');
        
        $sync_engine = new Planet_Sync_Engine();
        
        // Step 1: Validate Level 1 Categories
        $logger->log('system', 'info', '', 'Step 1: Validating level 1 categories');
        $category_validation = $sync_engine->validate_level_1_categories();
        
        if (!$category_validation['success']) {
            throw new Exception($category_validation['message']);
        }
        
        // Step 2: Fetch Product List
        $logger->log('system', 'info', '', 'Step 2: Fetching product list');
        $fetch_result = $sync_engine->fetch_product_list();
        
        if (!$fetch_result['success']) {
            throw new Exception($fetch_result['message']);
        }
        
        // Step 3: Process All Products
        $logger->log('system', 'info', '', 'Step 3: Processing all products');
        $process_result = $sync_engine->process_all_products();
        
        // Update last sync time
        update_option('_last_planet_sync', current_time('timestamp'));
        
        $logger->log('system', 'sync_complete', '', 'Sync completed successfully');
        
        return array(
            'success' => true,
            'categories' => $category_validation,
            'products' => $process_result
        );
    } catch (Exception $e) {
        if (isset($logger)) {
            $logger->log('system', 'error', '', 'Sync failed: ' . $e->getMessage());
        }
        
        // Clear temp data on error
        delete_option('planet_temp_product_list');
        delete_option('planet_temp_sync_started');
        delete_option('planet_sync_progress');
        delete_transient('planet_sync_in_progress');
        
        return array(
            'success' => false,
            'error' => $e->getMessage()
        );
    }
}

// Cron callback
function planet_sync_all_cron() {
    planet_sync_all();
}
add_action('planet_auto_sync', 'planet_sync_all_cron');

// Helper function to get MD5 hash
function planet_get_md5_hash($data) {
    return md5(json_encode($data));
}
