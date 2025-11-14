<?php
/**
 * Logger class for Planet Product Sync 2.0
 * Handles activity logging and statistics
 */

if (!defined('ABSPATH')) {
    exit;
}

class Planet_Sync_Logger {
    
    /**
     * Log an action
     * 
     * @param string $type Type of log (category, product, system)
     * @param string $action Action performed (create, update, delete, skip, error, info)
     * @param string $slug_or_id Slug or ID of the item
     * @param string $message Log message
     */
    public function log($type, $action, $slug_or_id, $message) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'planet_sync_log';
        
        $wpdb->insert(
            $table_name,
            array(
                'type' => $type,
                'action' => $action,
                'slug_or_id' => $slug_or_id,
                'message' => $message,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        // Debug mode logging
        if (get_option('planet_sync_debug_mode') === 'yes') {
            error_log(sprintf('[Planet Sync 2.0] [%s] [%s] %s: %s', $type, $action, $slug_or_id, $message));
        }
    }
    
    /**
     * Get recent logs
     * 
     * @param int $limit Number of logs to retrieve
     * @param string $type Filter by type (optional)
     * @param string $action Filter by action (optional)
     * @return array
     */
    public function get_recent_logs($limit = 600, $type = null, $action = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'planet_sync_log';
        
        $where = array();
        $prepare_args = array();
        
        if ($type) {
            $where[] = 'type = %s';
            $prepare_args[] = $type;
        }
        
        if ($action) {
            $where[] = 'action = %s';
            $prepare_args[] = $action;
        }
        
        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT * FROM $table_name $where_clause ORDER BY created_at DESC LIMIT %d";
        $prepare_args[] = $limit;
        
        if (!empty($prepare_args)) {
            return $wpdb->get_results($wpdb->prepare($sql, $prepare_args));
        }
        
        return $wpdb->get_results($wpdb->prepare($sql, $limit));
    }
    
    /**
     * Clear logs
     * 
     * @param string $before_date Clear logs before this date (Y-m-d format)
     */
    public function clear_logs($before_date = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'planet_sync_log';
        
        if ($before_date) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < %s",
                $before_date
            ));
        } else {
            $wpdb->query("TRUNCATE TABLE $table_name");
        }
    }
    
    /**
     * Get sync statistics
     * 
     * @return array
     */
    public function get_sync_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'planet_sync_log';
        
        // Get counts by action for the last sync
        $last_sync_time = get_option('_last_planet_sync');
        $last_sync_start = get_option('_last_planet_sync_start');
        
        if ($last_sync_time && $last_sync_start) {
            $start_date = date('Y-m-d H:i:s', $last_sync_start);
            $end_date = date('Y-m-d H:i:s', $last_sync_time);
            
            $stats = array(
                'last_sync' => $last_sync_time,
                'last_sync_formatted' => date('Y-m-d H:i:s', $last_sync_time),
                'categories_created' => 0,
                'categories_updated' => 0,
                'categories_skipped' => 0,
                'products_created' => 0,
                'products_updated' => 0,
                'products_skipped' => 0,
                'errors' => 0
            );
            
            // Get action counts between sync start and end
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT type, action, COUNT(*) as count FROM $table_name 
                WHERE created_at >= %s AND created_at <= %s
                GROUP BY type, action",
                $start_date,
                $end_date
            ));
            
            foreach ($results as $row) {
                $key = $row->type . '_' . $row->action;
                if (isset($stats[$key])) {
                    $stats[$key] = (int) $row->count;
                }
                if ($row->action === 'error') {
                    $stats['errors'] += (int) $row->count;
                }
            }
            
            return $stats;
        } elseif ($last_sync_time) {
            // Fallback: get last 1000 logs before last sync time
            $end_date = date('Y-m-d H:i:s', $last_sync_time);
            
            $stats = array(
                'last_sync' => $last_sync_time,
                'last_sync_formatted' => date('Y-m-d H:i:s', $last_sync_time),
                'categories_created' => 0,
                'categories_updated' => 0,
                'categories_skipped' => 0,
                'products_created' => 0,
                'products_updated' => 0,
                'products_skipped' => 0,
                'errors' => 0
            );
            
            // Get recent action counts
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT type, action, COUNT(*) as count FROM $table_name 
                WHERE created_at <= %s 
                AND type IN ('product', 'category')
                AND action IN ('create', 'update', 'skip', 'error')
                GROUP BY type, action 
                ORDER BY created_at DESC 
                LIMIT 1000",
                $end_date
            ));
            
            foreach ($results as $row) {
                $key = $row->type . '_' . $row->action;
                // Map 'create' to 'created'
                if ($row->action === 'create') {
                    $key = $row->type . '_created';
                } elseif ($row->action === 'update') {
                    $key = $row->type . '_updated';
                } elseif ($row->action === 'skip') {
                    $key = $row->type . '_skipped';
                }
                
                if (isset($stats[$key])) {
                    $stats[$key] = (int) $row->count;
                }
                if ($row->action === 'error') {
                    $stats['errors'] += (int) $row->count;
                }
            }
            
            return $stats;
        }
        
        return array(
            'last_sync' => null,
            'last_sync_formatted' => 'Never',
            'categories_created' => 0,
            'categories_updated' => 0,
            'categories_skipped' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'products_skipped' => 0,
            'errors' => 0
        );
    }
    
    /**
     * Get error logs
     * 
     * @param int $limit Number of errors to retrieve
     * @return array
     */
    public function get_errors($limit = 600) {
        return $this->get_recent_logs($limit, null, 'error');
    }
    
    /**
     * Search logs
     * 
     * @param string $search_term Search term
     * @param int $limit Number of results
     * @return array
     */
    public function search_logs($search_term, $limit = 600) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'planet_sync_log';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE slug_or_id LIKE %s OR message LIKE %s 
            ORDER BY created_at DESC 
            LIMIT %d",
            '%' . $wpdb->esc_like($search_term) . '%',
            '%' . $wpdb->esc_like($search_term) . '%',
            $limit
        ));
    }
}

