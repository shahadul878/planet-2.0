<?php
/**
 * API handler for Planet Product Sync 2.0
 * Handles all communication with Planet.com.tw API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Planet_Sync_API {
    
    private $api_base_url = 'https://www.planet.com.tw/api';
    private $api_key;
    private $logger;
    
    public function __construct() {
        $this->api_key = get_option('planet_sync_api_key', '6cbdd98d66a8d0d4d171ce12a75b4c90104214f7b116d5862b8f8e4d9b6ef663');
        $this->logger = new Planet_Sync_Logger();
    }
    
    /**
     * Generic API fetch method
     * 
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @param int $retry_count Number of retries
     * @return mixed API response or false on failure
     */
    public function fetch($endpoint, $params = array(), $retry_count = 3) {
        // Check transient cache first
        $cache_key = 'planet_api_' . md5($endpoint . serialize($params));
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $url = $this->api_base_url . $endpoint;
        
        // Add query parameters
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        $args = array(
            'headers' => array(
                'APIKey' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30,
            'sslverify' => true
        );
        
        $attempt = 0;
        $last_error = '';
        
        while ($attempt < $retry_count) {
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                $attempt++;
                
                if ($attempt < $retry_count) {
                    sleep(2); // Wait 2 seconds before retry
                }
                continue;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code !== 200) {
                $last_error = 'HTTP ' . $status_code;
                $attempt++;
                
                if ($attempt < $retry_count) {
                    sleep(2);
                }
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $last_error = 'Invalid JSON response';
                $attempt++;
                continue;
            }
            
            // Cache successful response for 5 minutes
            set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
            
            return $data;
        }
        
        // All retries failed
        $this->logger->log('api', 'error', $endpoint, 'API request failed: ' . $last_error);
        return false;
    }
    
    /**
     * Get product list
     * 
     * @param array $params Query parameters
     * @return array|false
     */
    public function get_product_list($params = array()) {
        $response = $this->fetch('/getProductList', $params);
        
        if ($response === false) {
            return false;
        }
        
        // The API might return data in different formats, handle both
        if (isset($response['data'])) {
            return $response['data'];
        }
        
        return $response;
    }
    
    /**
     * Get product by slug
     * 
     * @param string $slug Product slug
     * @return array|false
     */
    public function get_product_by_slug($slug) {
        $response = $this->fetch('/getProductBySlug', array('slug' => $slug));
        
        if ($response === false) {
            return false;
        }
        
        // Handle different response formats
        if (isset($response['data'])) {
            return $response['data'];
        }
        
        return $response;
    }
    
    /**
     * Get category list
     * 
     * @param int $level Category level (1, 2, or 3)
     * @return array|false
     */
    public function get_category_list($level = 1) {
        $level_names = array(
            1 => '1st',
            2 => '2nd',
            3 => '3rd'
        );
        
        if (!isset($level_names[$level])) {
            $this->logger->log('api', 'error', 'category', 'Invalid category level: ' . $level);
            return false;
        }
        
        $endpoint = '/getProduct' . $level_names[$level] . 'CategoryList';
        $response = $this->fetch($endpoint);
        
        if ($response === false) {
            return false;
        }
        
        // Handle different response formats
        if (isset($response['data'])) {
            return $response['data'];
        }
        
        return $response;
    }
    
    /**
     * Get 1st level categories
     * 
     * @return array|false
     */
    public function get_first_level_categories() {
        return $this->get_category_list(1);
    }
    
    /**
     * Get 2nd level categories
     * 
     * @return array|false
     */
    public function get_second_level_categories() {
        return $this->get_category_list(2);
    }
    
    /**
     * Get 3rd level categories
     * 
     * @return array|false
     */
    public function get_third_level_categories() {
        return $this->get_category_list(3);
    }
    
    /**
     * Clear API cache
     */
    public function clear_cache() {
        global $wpdb;
        
        // Delete all transients starting with 'planet_api_'
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_planet_api_%' 
            OR option_name LIKE '_transient_timeout_planet_api_%'"
        );
    }
    
    /**
     * Test API connection
     * 
     * @return array Result with success status and message
     */
    public function test_connection() {
        $categories = $this->get_first_level_categories();
        
        if ($categories === false) {
            return array(
                'success' => false,
                'message' => 'Failed to connect to Planet API'
            );
        }
        
        return array(
            'success' => true,
            'message' => 'Successfully connected to Planet API',
            'categories_count' => is_array($categories) ? count($categories) : 0
        );
    }
    
    /**
     * Download remote image and return local attachment ID
     * 
     * @param string $image_url Remote image URL
     * @param string $filename Desired filename
     * @return int|false Attachment ID or false on failure
     */
    public function download_image($image_url, $filename = '') {
        if (empty($image_url)) {
            return false;
        }
        
        // Check if image already exists by URL
        $existing = $this->get_attachment_by_url($image_url);
        if ($existing) {
            return $existing;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Download file
        $temp_file = download_url($image_url);
        
        if (is_wp_error($temp_file)) {
            $this->logger->log('api', 'error', $image_url, 'Failed to download image: ' . $temp_file->get_error_message());
            return false;
        }
        
        // Get file extension
        $file_ext = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        
        if (empty($filename)) {
            $filename = basename(parse_url($image_url, PHP_URL_PATH));
        } else {
            // Ensure filename has extension
            if (!preg_match('/\.' . $file_ext . '$/', $filename)) {
                $filename .= '.' . $file_ext;
            }
        }
        
        // Prepare file array
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file
        );
        
        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, 0);
        
        // Clean up temp file
        if (file_exists($temp_file)) {
            @unlink($temp_file);
        }
        
        if (is_wp_error($attachment_id)) {
            $this->logger->log('api', 'error', $image_url, 'Failed to sideload image: ' . $attachment_id->get_error_message());
            return false;
        }
        
        // Store original URL as meta
        update_post_meta($attachment_id, '_planet_original_url', $image_url);
        
        return $attachment_id;
    }
    
    /**
     * Get attachment by original URL
     * 
     * @param string $url Original URL
     * @return int|false Attachment ID or false
     */
    private function get_attachment_by_url($url) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_planet_original_url' 
            AND meta_value = %s 
            LIMIT 1",
            $url
        ));
        
        return $attachment_id ? (int) $attachment_id : false;
    }
}

