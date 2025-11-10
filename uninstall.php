<?php
/**
 * Uninstall script for Planet Product Sync 2.0
 * Cleans up all plugin data when uninstalled
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete options
delete_option('planet_sync_api_key');
delete_option('planet_sync_auto_sync');
delete_option('planet_sync_frequency');
delete_option('planet_sync_debug_mode');
delete_option('planet_temp_product_list');
delete_option('planet_temp_sync_started');
delete_option('planet_sync_progress');
delete_option('_last_planet_sync');

// Delete transients
$wpdb->query(
    "DELETE FROM {$wpdb->options} 
    WHERE option_name LIKE '_transient_planet_api_%' 
    OR option_name LIKE '_transient_timeout_planet_api_%'"
);

// Drop database tables
$log_table = $wpdb->prefix . 'planet_sync_log';
$wpdb->query("DROP TABLE IF EXISTS $log_table");

// Remove post meta from products
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} 
    WHERE meta_key IN (
        '_planet_product_hash',
        '_planet_id',
        'product_code',
        '_planet_slug',
        '_planet_category_ids',
        'applications_tab',
        'key_features_tab',
        'specifications_tab',
        '_planet_original_url'
    )"
);

// Remove term meta from categories
$wpdb->query(
    "DELETE FROM {$wpdb->termmeta} 
    WHERE meta_key = '_planet_category_id'"
);

// Clear any scheduled cron events
wp_clear_scheduled_hook('planet_auto_sync');

// Clear Action Scheduler actions if it exists
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('planet_auto_sync');
}

