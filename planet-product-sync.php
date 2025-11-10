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
    add_option('planet_sync_debug_mode', 'no');
}
register_activation_hook(__FILE__, 'planet_sync_activate');

// Plugin deactivation
function planet_sync_deactivate() {
    // Clear scheduled events
    $timestamp = wp_next_scheduled('planet_auto_sync');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'planet_auto_sync');
    }
    
    // Clear Action Scheduler if exists
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('planet_auto_sync');
    }
    
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

// Setup automatic sync
function planet_sync_setup_cron() {
    $frequency = get_option('planet_sync_frequency', 'hourly');
    
    // Use Action Scheduler if available (better for large datasets)
    if (function_exists('as_next_scheduled_action')) {
        if (!as_next_scheduled_action('planet_auto_sync')) {
            $interval = ($frequency === 'daily') ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
            as_schedule_recurring_action(time(), $interval, 'planet_auto_sync');
        }
    } else {
        // Fallback to WP-Cron
        if (!wp_next_scheduled('planet_auto_sync')) {
            wp_schedule_event(time(), $frequency, 'planet_auto_sync');
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
