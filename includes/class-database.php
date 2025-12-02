<?php
/**
 * Database handler for Planet Product Sync 2.0
 * Manages database table creation and schema
 */

if (!defined('ABSPATH')) {
    exit;
}

class Planet_Sync_Database {
    
    /**
     * Create necessary database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Sync log table
        $log_table_name = $wpdb->prefix . 'planet_sync_log';
        
        $log_sql = "CREATE TABLE IF NOT EXISTS $log_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            slug_or_id varchar(255) DEFAULT NULL,
            message text DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($log_sql);
    }
    
    /**
     * Drop all plugin tables
     */
    public static function drop_tables() {
        global $wpdb;
        
        $log_table_name = $wpdb->prefix . 'planet_sync_log';
        
        $wpdb->query("DROP TABLE IF EXISTS $log_table_name");
    }
    
    /**
     * Check if tables exist
     * 
     * @return bool
     */
    public static function tables_exist() {
        global $wpdb;
        
        $log_table_name = $wpdb->prefix . 'planet_sync_log';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$log_table_name'") === $log_table_name;
        
        return $table_exists;
    }
}

