<?php
/**
 * Sync Queue Manager for Planet Product Sync 2.0
 * Tracks individual product sync status for resume capability
 * 
 * Author: H M Shahadul Islam
 * Author URI: https://github.com/shahadul878
 */

if (!defined('ABSPATH')) {
    exit;
}

class Planet_Sync_Queue {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'planet_sync_queue';

        $this->maybe_create_table();
    }
    
    /**
     * Create sync queue table
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_batch_id varchar(50) NOT NULL,
            product_slug varchar(255) NOT NULL,
            product_id varchar(100) DEFAULT NULL,
    		product_cat varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            result_message text DEFAULT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY sync_batch_id (sync_batch_id),
            KEY status (status),
            KEY product_slug (product_slug)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Ensure the sync queue table exists
     */
    private function maybe_create_table() {
        global $wpdb;

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_name
        ));

        if ($table_exists !== $this->table_name) {
            $this->create_table();
        }
    }
    
    /**
     * Add products to queue (alias for initialize_queue for backward compatibility)
     * 
     * @param array $product_list List of products to sync
     * @return array Result with batch_id and total count
     */
    public function add_products_to_queue($product_list) {
        return $this->initialize_queue($product_list);
    }
    
    /**
     * Initialize sync queue from product list
     * 
     * @param array $product_list List of products to sync
     * @return array Result with batch_id and total count
     */
    public function initialize_queue($product_list) {
        global $wpdb;
        
        // Generate unique batch ID
        $batch_id = 'sync_' . time();
        
        // Clear any pending queues older than 24 hours
        $wpdb->query("DELETE FROM {$this->table_name} 
                      WHERE status = 'pending' 
                      AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        
        // Insert all products into queue
        $inserted = 0;
        foreach ($product_list as $product_item) {
            $slug = $this->extract_slug($product_item);
            
            if (empty($slug)) {
                continue;
            }
            $product_cat = isset($product_item['1st_categories'] ) ? $product_item['1st_categories'][0]['slug'] : '';
            $product_id = isset($product_item['id']) ? $product_item['id'] : '';
            
            $result = $wpdb->insert(
                $this->table_name,
                array(
                    'sync_batch_id' => $batch_id,
                    'product_slug' => $slug,
					'product_cat' => $product_cat,
                    'product_id' => $product_id,
                    'status' => 'pending',
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result !== false) {
                $inserted++;
            }
        }
        
        // Store current batch ID
        update_option('planet_current_sync_batch', $batch_id, false);
        
        return array(
            'batch_id' => $batch_id,
            'total' => $inserted
        );
    }
    
    /**
     * Get next pending product from queue
     * 
     * @return object|null Queue item or null if none pending
     */
    public function get_next_pending() {
        global $wpdb;
        
        $batch_id = get_option('planet_current_sync_batch', '');
        
        if (empty($batch_id)) {
            return null;
        }
        
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE sync_batch_id = %s 
             AND status = 'pending' 
             ORDER BY id ASC 
             LIMIT 1",
            $batch_id
        ));
        
        return $product;
    }
    
    /**
     * Mark product as synced/failed/skipped
     * 
     * @param int $queue_id Queue item ID
     * @param string $result Status (synced, failed, skipped)
     * @param string $message Result message
     */
    public function mark_as_synced($queue_id, $result, $message = '') {
        global $wpdb;
        
        $wpdb->update(
            $this->table_name,
            array(
                'status' => $result,
                'result_message' => $message,
                'synced_at' => current_time('mysql')
            ),
            array('id' => $queue_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Increment attempt count for a queue item
     * 
     * @param int $queue_id Queue item ID
     */
    public function increment_attempts($queue_id) {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} 
             SET attempts = attempts + 1 
             WHERE id = %d",
            $queue_id
        ));
    }
    
    /**
     * Get sync statistics for a batch
     * 
     * @param string|null $batch_id Batch ID (uses current if null)
     * @return array Statistics array
     */
    public function get_statistics($batch_id = null) {
        global $wpdb;
        
        if (!$batch_id) {
            $batch_id = get_option('planet_current_sync_batch', '');
        }
        
        if (empty($batch_id)) {
            return array(
                'total' => 0,
                'pending' => 0,
                'synced' => 0,
                'failed' => 0,
                'skipped' => 0
            );
        }
        
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count 
             FROM {$this->table_name} 
             WHERE sync_batch_id = %s 
             GROUP BY status",
            $batch_id
        ), OBJECT_K);
        
        return array(
            'total' => array_sum(wp_list_pluck($stats, 'count')),
            'pending' => isset($stats['pending']) ? (int)$stats['pending']->count : 0,
            'synced' => isset($stats['synced']) ? (int)$stats['synced']->count : 0,
            'failed' => isset($stats['failed']) ? (int)$stats['failed']->count : 0,
            'skipped' => isset($stats['skipped']) ? (int)$stats['skipped']->count : 0
        );
    }
    
    /**
     * Check if sync is complete (no pending items)
     * 
     * @param string|null $batch_id Batch ID (uses current if null)
     * @return bool True if complete
     */
    public function is_complete($batch_id = null) {
        if (!$batch_id) {
            $batch_id = get_option('planet_current_sync_batch', '');
        }
        
        $stats = $this->get_statistics($batch_id);
        return $stats['pending'] === 0;
    }
    
    /**
     * Clean up completed batch
     * 
     * @param string $batch_id Batch ID to clean up
     */
    public function cleanup_batch($batch_id) {
        global $wpdb;
        
        // Keep records for history, just clean up options
        delete_option('planet_current_sync_batch');
        delete_option('planet_temp_product_list');
        delete_option('planet_sync_progress');
        delete_transient('planet_sync_progress_cache');
    }
    
    /**
     * Extract slug from product item
     * 
     * @param mixed $product_item Product item from list
     * @return string Product slug
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
     * Reset failed products to pending (for retry)
     * 
     * @param string|null $batch_id Batch ID (uses current if null)
     * @return int Number of products reset
     */
    public function reset_failed_to_pending($batch_id = null) {
        global $wpdb;
        
        if (!$batch_id) {
            $batch_id = get_option('planet_current_sync_batch', '');
        }
        
        if (empty($batch_id)) {
            return 0;
        }
        
        $updated = $wpdb->update(
            $this->table_name,
            array('status' => 'pending', 'attempts' => 0),
            array(
                'sync_batch_id' => $batch_id,
                'status' => 'failed'
            ),
            array('%s', '%d'),
            array('%s', '%s')
        );
        
        return $updated !== false ? $updated : 0;
    }
    
    /**
     * Get all failed products for a batch
     * 
     * @param string|null $batch_id Batch ID (uses current if null)
     * @return array Failed products
     */
    public function get_failed_products($batch_id = null) {
        global $wpdb;
        
        if (!$batch_id) {
            $batch_id = get_option('planet_current_sync_batch', '');
        }
        
        if (empty($batch_id)) {
            return array();
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE sync_batch_id = %s 
             AND status = 'failed' 
             ORDER BY id ASC",
            $batch_id
        ));
    }
    
    /**
     * Delete old sync batches (cleanup)
     * 
     * @param int $days_old Delete batches older than this many days
     * @return int Number of records deleted
     */
    public function delete_old_batches($days_old = 30) {
        global $wpdb;
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));
        
        return $deleted !== false ? $deleted : 0;
    }
}

