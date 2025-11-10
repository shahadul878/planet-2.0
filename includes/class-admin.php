<?php
/**
 * Admin interface for Planet Product Sync 2.0
 * Handles admin dashboard, settings, and UI
 */

if (!defined('ABSPATH')) {
    exit;
}

class Planet_Sync_Admin {
    
    private $logger;
    private $api;
    private $sync_engine;
    
    public function __construct() {
        $this->logger = new Planet_Sync_Logger();
        $this->api = new Planet_Sync_API();
        $this->sync_engine = new Planet_Sync_Engine();
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Register AJAX handlers
        add_action('wp_ajax_planet_validate_categories', array($this, 'ajax_validate_categories'));
        add_action('wp_ajax_planet_get_category_comparison', array($this, 'ajax_get_category_comparison'));
        add_action('wp_ajax_planet_create_missing_categories', array($this, 'ajax_create_missing_categories'));
        add_action('wp_ajax_planet_start_sync', array($this, 'ajax_start_sync'));
        add_action('wp_ajax_planet_process_next', array($this, 'ajax_process_next'));
        add_action('wp_ajax_planet_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_planet_get_recent_logs', array($this, 'ajax_get_recent_logs'));
        add_action('wp_ajax_planet_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_planet_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_planet_retry_failed', array($this, 'ajax_retry_failed'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=product',
            __('Planet Sync 2.0', 'planet-product-sync'),
            __('Planet Sync 2.0', 'planet-product-sync'),
            'manage_woocommerce',
            'planet-sync-2',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('planet_sync_settings', 'planet_sync_api_key');
        register_setting('planet_sync_settings', 'planet_sync_auto_sync');
        register_setting('planet_sync_settings', 'planet_sync_frequency');
        register_setting('planet_sync_settings', 'planet_sync_debug_mode');
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'product_page_planet-sync-2') {
            return;
        }
        
        wp_enqueue_style(
            'planet-sync-admin',
            PLANET_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PLANET_SYNC_VERSION
        );
        
        wp_enqueue_script(
            'planet-sync-admin',
            PLANET_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            PLANET_SYNC_VERSION,
            true
        );
        
        wp_localize_script('planet-sync-admin', 'planetSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('planet_sync_nonce'),
            'strings' => array(
                'syncing' => __('Syncing...', 'planet-product-sync'),
                'success' => __('Sync completed successfully', 'planet-product-sync'),
                'error' => __('An error occurred', 'planet-product-sync'),
                'confirm_clear' => __('Are you sure you want to clear all logs?', 'planet-product-sync')
            )
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Handle settings save
        if (isset($_POST['planet_sync_save_settings']) && check_admin_referer('planet_sync_settings')) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'planet-product-sync') . '</p></div>';
        }
        $this->maybe_migrate_product_list();

        $stats = $this->logger->get_sync_stats();
        $progress = $this->sync_engine->get_sync_progress();
        
        ?>
        <div class="wrap planet-sync-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="planet-sync-container">
                
                <!-- Dashboard Section -->
                <div class="planet-sync-card">
                    <h2><?php _e('Sync Dashboard', 'planet-product-sync'); ?></h2>
                    <div class="planet-stats-grid">
                        <div class="stat-box">
                            <div class="stat-label"><?php _e('Last Sync', 'planet-product-sync'); ?></div>
                            <div class="stat-value"><?php echo esc_html($stats['last_sync_formatted']); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label"><?php _e('Products Created', 'planet-product-sync'); ?></div>
                            <div class="stat-value stat-success"><?php echo esc_html($stats['products_created']); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label"><?php _e('Products Updated', 'planet-product-sync'); ?></div>
                            <div class="stat-value stat-info"><?php echo esc_html($stats['products_updated']); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label"><?php _e('Products Skipped', 'planet-product-sync'); ?></div>
                            <div class="stat-value stat-warning"><?php echo esc_html($stats['products_skipped']); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label"><?php _e('Errors', 'planet-product-sync'); ?></div>
                            <div class="stat-value stat-error"><?php echo esc_html($stats['errors']); ?></div>
                        </div>
                    </div>
                    
                    <div class="planet-actions">
                        <button type="button" id="start-sync-btn" class="button button-primary button-large">
                            <?php _e('Start Full Sync', 'planet-product-sync'); ?>
                        </button>
                        <button type="button" id="test-connection-btn" class="button button-secondary">
                            <?php _e('Test API Connection', 'planet-product-sync'); ?>
                        </button>
                        <button type="button" id="retry-failed-btn" class="button button-secondary" style="display:none;">
                            <?php _e('Retry Failed Products', 'planet-product-sync'); ?>
                        </button>
                        <button type="button" id="clear-logs-btn" class="button button-secondary">
                            <?php _e('Clear Logs', 'planet-product-sync'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Sync Progress Section -->
                <?php if ($progress['is_running']): ?>
                <div class="planet-sync-card">
                    <h2><?php _e('Sync Progress', 'planet-product-sync'); ?></h2>
                    <div class="sync-progress-container">
                        <div class="progress-info">
                            <span class="stage"><?php echo esc_html(ucfirst($progress['stage'])); ?></span>
                            <span class="counts"><?php echo esc_html($progress['current']) . ' / ' . esc_html($progress['total']); ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo esc_attr($progress['percentage']); ?>%"></div>
                        </div>
                        <div class="progress-percentage"><?php echo esc_html($progress['percentage']); ?>%</div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Category Comparison Section -->
                <div class="planet-sync-card">
                    <h2><?php _e('Category Comparison', 'planet-product-sync'); ?></h2>
                    <p><?php _e('Compare Planet API level 1 categories with WooCommerce product categories.', 'planet-product-sync'); ?></p>
                    <div class="planet-actions" style="margin-bottom: 15px;">
                        <button type="button" id="create-missing-categories-btn" class="button button-primary" style="display:none;">
                            <?php _e('Create Missing Categories', 'planet-product-sync'); ?>
                        </button>
                    </div>
                    <div id="category-comparison-table"></div>
                </div>
                
                <!-- Sync Log Section -->
                <div class="planet-sync-card">
                    <h2><?php _e('Recent Activity', 'planet-product-sync'); ?></h2>
                    <?php $this->render_sync_logs(); ?>
                </div>
                
                <!-- New Products Section -->
                <div class="planet-sync-card">
                    <h2><?php _e('New Products', 'planet-product-sync'); ?></h2>
                    <?php $this->render_new_products_table(); ?>
                </div>
                
                <!-- Settings Section -->
                <div class="planet-sync-card">
                    <h2><?php _e('Settings', 'planet-product-sync'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('planet_sync_settings'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="planet_sync_api_key"><?php _e('API Key', 'planet-product-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="planet_sync_api_key" 
                                           name="planet_sync_api_key" 
                                           value="<?php echo esc_attr(get_option('planet_sync_api_key')); ?>" 
                                           class="regular-text">
                                    <p class="description"><?php _e('Enter your Planet API key', 'planet-product-sync'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="planet_sync_auto_sync"><?php _e('Auto Sync', 'planet-product-sync'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               id="planet_sync_auto_sync" 
                                               name="planet_sync_auto_sync" 
                                               value="yes" 
                                               <?php checked(get_option('planet_sync_auto_sync'), 'yes'); ?>>
                                        <?php _e('Enable automatic sync', 'planet-product-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="planet_sync_frequency"><?php _e('Sync Frequency', 'planet-product-sync'); ?></label>
                                </th>
                                <td>
                                    <select id="planet_sync_frequency" name="planet_sync_frequency">
                                        <option value="hourly" <?php selected(get_option('planet_sync_frequency'), 'hourly'); ?>>
                                            <?php _e('Hourly', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="daily" <?php selected(get_option('planet_sync_frequency'), 'daily'); ?>>
                                            <?php _e('Daily', 'planet-product-sync'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="planet_sync_debug_mode"><?php _e('Debug Mode', 'planet-product-sync'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               id="planet_sync_debug_mode" 
                                               name="planet_sync_debug_mode" 
                                               value="yes" 
                                               <?php checked(get_option('planet_sync_debug_mode'), 'yes'); ?>>
                                        <?php _e('Enable debug logging', 'planet-product-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" 
                                   name="planet_sync_save_settings" 
                                   class="button button-primary" 
                                   value="<?php _e('Save Settings', 'planet-product-sync'); ?>">
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Migrate product list from option to custom table (runs once automatically)
     */
    private function maybe_migrate_product_list() {
        if (get_option('planet_product_snapshots_migrated') === 'yes') {
            return;
        }
        
        $products = get_option('produplanet_product_list', array());
        
        if (empty($products) || !is_array($products)) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'planet_product_snapshots';
        
        foreach ($products as $product) {
            $wpdb->insert(
                $table_name,
                array(
                    'product_id'       => isset($product['id']) ? (int) $product['id'] : 0,
                    'name'             => $product['name'] ?? '',
                    'slug'             => $product['slug'] ?? '',
                    'description'      => $product['desc'] ?? '',
                    'release_date'     => !empty($product['release_date']) ? $product['release_date'] : null,
                    'parents'          => !empty($product['parents']) ? wp_json_encode($product['parents']) : null,
                    'first_categories' => !empty($product['1st_categories']) ? wp_json_encode($product['1st_categories']) : null,
                    'snapshot_data'    => wp_json_encode($product),
                    'created_at'       => current_time('mysql'),
                    'updated_at'       => current_time('mysql'),
                ),
                array(
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                )
            );
        }
        
        update_option('planet_product_snapshots_migrated', 'yes');
    }
    
    /**
     * Render sync logs
     */
    private function render_sync_logs() {
        $logs = $this->logger->get_recent_logs(50);
        
        if (empty($logs)) {
            echo '<p>' . __('No activity yet.', 'planet-product-sync') . '</p>';
            return;
        }
        
        ?>
        <div class="planet-logs-table">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Type', 'planet-product-sync'); ?></th>
                        <th><?php _e('Action', 'planet-product-sync'); ?></th>
                        <th><?php _e('Item', 'planet-product-sync'); ?></th>
                        <th><?php _e('Message', 'planet-product-sync'); ?></th>
                        <th><?php _e('Time', 'planet-product-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><span class="log-badge log-type-<?php echo esc_attr($log->type); ?>"><?php echo esc_html($log->type); ?></span></td>
                        <td><span class="log-badge log-action-<?php echo esc_attr($log->action); ?>"><?php echo esc_html($log->action); ?></span></td>
                        <td><?php echo esc_html($log->slug_or_id); ?></td>
                        <td><?php echo esc_html($log->message); ?></td>
                        <td><?php echo esc_html($log->created_at); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render table of newly created products
     */
    private function render_new_products_table() {
        $new_products = $this->logger->get_recent_logs(20, 'product', 'create');
        
        if (empty($new_products)) {
            echo '<p>' . esc_html__('No new products synced yet.', 'planet-product-sync') . '</p>';
            return;
        }
        ?>
        <div class="planet-logs-table">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Product', 'planet-product-sync'); ?></th>
                        <th><?php _e('Identifier', 'planet-product-sync'); ?></th>
                        <th><?php _e('Message', 'planet-product-sync'); ?></th>
                        <th><?php _e('Created At', 'planet-product-sync'); ?></th>
                        <th><?php _e('Actions', 'planet-product-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($new_products as $log): ?>
                        <?php
                            $product_id = $this->resolve_product_id($log->slug_or_id);
                            $product_title = $product_id ? get_the_title($product_id) : '';
                            $edit_link = $product_id ? get_edit_post_link($product_id, '') : '';
                        ?>
                        <tr>
                            <td>
                                <?php
                                    if ($product_title) {
                                        echo esc_html($product_title);
                                    } else {
                                        echo '<em>' . esc_html__('Unknown product', 'planet-product-sync') . '</em>';
                                    }
                                ?>
                            </td>
                            <td><?php echo esc_html($log->slug_or_id); ?></td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td>
                                <?php if ($edit_link): ?>
                                    <a class="button button-small" href="<?php echo esc_url($edit_link); ?>"><?php _e('Edit', 'planet-product-sync'); ?></a>
                                <?php else: ?>
                                    <span style="color:#999;"><?php _e('Not found', 'planet-product-sync'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Resolve product ID from slug, SKU, or stored Planet metadata
     *
     * @param string $identifier
     * @return int
     */
    private function resolve_product_id($identifier) {
        global $wpdb;
        
        if (empty($identifier)) {
            return 0;
        }
        
        // Direct numeric ID
        if (ctype_digit($identifier)) {
            $post = get_post((int) $identifier);
            if ($post && $post->post_type === 'product') {
                return (int) $post->ID;
            }
        }
        
        // Match by post_name (slug)
        $products = get_posts(array(
            'name'           => sanitize_title($identifier),
            'post_type'      => 'product',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'numberposts'    => 1,
            'fields'         => 'ids',
            'suppress_filters' => true,
        ));
        if (!empty($products)) {
            return (int) $products[0];
        }
        
        // Match by SKU
        $sku_id = wc_get_product_id_by_sku($identifier);
        if ($sku_id) {
            return (int) $sku_id;
        }
        
        // Match by Planet slug meta
        $planet_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_planet_slug' AND meta_value = %s LIMIT 1",
            $identifier
        ));
        if ($planet_id) {
            return (int) $planet_id;
        }
        
        return 0;
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        if (isset($_POST['planet_sync_api_key'])) {
            update_option('planet_sync_api_key', sanitize_text_field($_POST['planet_sync_api_key']));
        }
        
        update_option('planet_sync_auto_sync', isset($_POST['planet_sync_auto_sync']) ? 'yes' : 'no');
        
        if (isset($_POST['planet_sync_frequency'])) {
            update_option('planet_sync_frequency', sanitize_text_field($_POST['planet_sync_frequency']));
        }
        
        update_option('planet_sync_debug_mode', isset($_POST['planet_sync_debug_mode']) ? 'yes' : 'no');
    }
    
    /**
     * AJAX: Validate categories
     */
    public function ajax_validate_categories() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $result = $this->sync_engine->validate_level_1_categories();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get category comparison data
     */
    public function ajax_get_category_comparison() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Fetch Planet categories
        $planet_categories = $this->api->get_first_level_categories();
        
        if ($planet_categories === false) {
            wp_send_json_error(array('message' => 'Failed to fetch Planet categories'));
            return;
        }
        
        // Get WooCommerce categories
        $woo_categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'parent' => 0,
            'hide_empty' => false
        ));
        
        // Build comparison data
        $comparison = array();
        $woo_cats_by_name = array();
        $woo_cats_by_id = array();
        
        foreach ($woo_categories as $cat) {
            $woo_cats_by_name[strtolower($cat->name)] = $cat;
            $planet_id = get_term_meta($cat->term_id, '_planet_category_id', true);
            if ($planet_id) {
                $woo_cats_by_id[$planet_id] = $cat;
            }
        }
        
        $missing_count = 0;
        $matched_count = 0;
        $mismatch_count = 0;
        
        foreach ($planet_categories as $planet_cat) {
            $planet_id = isset($planet_cat['id']) ? $planet_cat['id'] : '';
            $planet_name = isset($planet_cat['name']) ? $planet_cat['name'] : '';
            $planet_name_lower = strtolower($planet_name);
            
            $status = 'missing';
            $woo_cat_name = '-';
            $woo_cat_id = '-';
            $woo_cat_count = 0;
            
            // Check by Planet ID first
            if ($planet_id && isset($woo_cats_by_id[$planet_id])) {
                $woo_cat = $woo_cats_by_id[$planet_id];
                $woo_cat_name = $woo_cat->name;
                $woo_cat_id = $woo_cat->term_id;
                $woo_cat_count = $woo_cat->count;
                
                if (strtolower($woo_cat->name) === $planet_name_lower) {
                    $status = 'matched';
                    $matched_count++;
                } else {
                    $status = 'mismatch';
                    $mismatch_count++;
                }
            }
            // Check by name
            elseif (isset($woo_cats_by_name[$planet_name_lower])) {
                $woo_cat = $woo_cats_by_name[$planet_name_lower];
                $woo_cat_name = $woo_cat->name;
                $woo_cat_id = $woo_cat->term_id;
                $woo_cat_count = $woo_cat->count;
                $status = 'matched';
                $matched_count++;
            } else {
                $missing_count++;
            }
            
            $comparison[] = array(
                'planet_id' => $planet_id,
                'planet_name' => $planet_name,
                'woo_name' => $woo_cat_name,
                'woo_id' => $woo_cat_id,
                'woo_count' => $woo_cat_count,
                'status' => $status
            );
        }
        
        wp_send_json_success(array(
            'comparison' => $comparison,
            'summary' => array(
                'total_planet' => count($planet_categories),
                'total_woo' => count($woo_categories),
                'matched' => $matched_count,
                'mismatch' => $mismatch_count,
                'missing' => $missing_count
            )
        ));
    }
    
    /**
     * AJAX: Create missing categories
     */
    public function ajax_create_missing_categories() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $result = $this->sync_engine->validate_level_1_categories();
        
        wp_send_json_success(array(
            'created' => $result['created'],
            'message' => sprintf('Created %d missing categories', $result['created'])
        ));
    }
    
    /**
     * AJAX: Start sync (Step 1 & 2 only - prepare for real-time processing)
     */
    public function ajax_start_sync() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        try {
            // Record sync start time
            update_option('_last_planet_sync_start', current_time('timestamp'));
            
            // Step 1: Validate Level 1 Categories
            $this->logger->log('system', 'info', '', 'Step 1: Validating level 1 categories');
            $category_validation = $this->sync_engine->validate_level_1_categories();
            
            if (!$category_validation['success']) {
                wp_send_json_error(array('message' => $category_validation['message']));
                return;
            }
            
            // Step 2: Fetch Product List
            $this->logger->log('system', 'info', '', 'Step 2: Fetching product list');
            $fetch_result = $this->sync_engine->fetch_product_list();
            
            if (!$fetch_result['success']) {
                wp_send_json_error(array('message' => $fetch_result['message']));
                return;
            }
            
            // Initialize progress
            update_option('planet_sync_progress', array(
                'stage' => 'processing',
                'current' => 0,
                'total' => $fetch_result['total'],
                'percentage' => 0
            ), false);
            
            wp_send_json_success(array(
                'message' => 'Sync initialized successfully',
                'total_products' => $fetch_result['total'],
                'categories' => $category_validation
            ));
        } catch (Exception $e) {
            $this->logger->log('system', 'error', '', 'Sync initialization failed: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Process next product (real-time one-by-one)
     */
    public function ajax_process_next() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $result = $this->sync_engine->process_next_product();
        
        // If complete, update last sync time
        if (isset($result['complete']) && $result['complete']) {
            update_option('_last_planet_sync', current_time('timestamp'));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get progress
     */
    public function ajax_get_progress() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $progress = $this->sync_engine->get_sync_progress();
        wp_send_json_success($progress);
    }
    
    /**
     * AJAX: Get recent logs (for real-time display)
     */
    public function ajax_get_recent_logs() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        $logs = $this->logger->get_recent_logs($limit);
        
        // Format logs for display
        $formatted_logs = array();
        foreach ($logs as $log) {
            $formatted_logs[] = array(
                'id' => $log->id,
                'type' => $log->type,
                'action' => $log->action,
                'slug_or_id' => $log->slug_or_id,
                'message' => $log->message,
                'created_at' => $log->created_at
            );
        }
        
        wp_send_json_success(array('logs' => $formatted_logs));
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $this->logger->clear_logs();
        wp_send_json_success(array('message' => 'Logs cleared successfully'));
    }
    
    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $result = $this->api->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Retry failed products
     */
    public function ajax_retry_failed() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $count = $this->sync_engine->retry_failed_products();
        
        if ($count > 0) {
            wp_send_json_success(array(
                'message' => sprintf('Reset %d failed products for retry. Click "Start Full Sync" to resume.', $count),
                'count' => $count
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'No failed products found to retry'
            ));
        }
    }
}

