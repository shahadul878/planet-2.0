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
        add_action('wp_ajax_planet_start_background_sync', array($this, 'ajax_start_background_sync'));
        add_action('wp_ajax_planet_get_background_status', array($this, 'ajax_get_background_status'));
        add_action('wp_ajax_planet_pause_background_sync', array($this, 'ajax_pause_background_sync'));
        add_action('wp_ajax_planet_resume_background_sync', array($this, 'ajax_resume_background_sync'));
        add_action('wp_ajax_planet_cancel_background_sync', array($this, 'ajax_cancel_background_sync'));
        add_action('wp_ajax_planet_trigger_background_sync', array($this, 'ajax_trigger_background_sync'));
        add_action('wp_ajax_planet_get_background_debug', array($this, 'ajax_get_background_debug'));
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
        register_setting('planet_sync_settings', 'planet_sync_daily_time');
        register_setting('planet_sync_settings', 'planet_sync_debug_mode');
        register_setting('planet_sync_settings', 'planet_sync_method'); // 'ajax' or 'background'
        register_setting('planet_sync_settings', 'planet_sync_orphaned_action'); // Action for orphaned products
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
            'syncMethod' => get_option('planet_sync_method', 'ajax'),
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
        

        $stats = $this->logger->get_sync_stats();
        $progress = $this->sync_engine->get_sync_progress();
        $auto_sync_info = $this->get_next_auto_sync_info();
        switch ($auto_sync_info['frequency']) {
            case 'daily':
                $frequency_label = sprintf(
                    esc_html__('Daily at %s', 'planet-product-sync'),
                    esc_html($auto_sync_info['daily_time'])
                );
                break;
            case 'twice_daily':
                $frequency_label = esc_html__('Twice daily (every 12 hours)', 'planet-product-sync');
                break;
            case 'weekly':
                $frequency_label = esc_html__('Weekly', 'planet-product-sync');
                break;
            case 'hourly':
            default:
                $frequency_label = esc_html__('Hourly', 'planet-product-sync');
                break;
        }
        
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
                            <div class="stat-value date-time"><?php echo esc_html($stats['last_sync_formatted']); ?></div>
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
                        <div class="stat-box">
                            <div class="stat-label"><?php _e('Auto Sync', 'planet-product-sync'); ?></div>
                            <div class="stat-value <?php echo $auto_sync_info['enabled'] ? 'stat-success' : 'stat-error'; ?>">
                                <?php echo $auto_sync_info['enabled']
                                    ? esc_html__('Enabled', 'planet-product-sync')
                                    : esc_html__('Disabled', 'planet-product-sync'); ?>
                            </div>
                            <div class="stat-note">
                                <?php
                                $method_label = ($auto_sync_info['sync_method'] === 'background')
                                    ? esc_html__('Background', 'planet-product-sync')
                                    : esc_html__('AJAX', 'planet-product-sync');
                                if ($auto_sync_info['enabled']) {
                                    if (!empty($auto_sync_info['next_timestamp'])) {
                                        $next_formatted = wp_date(
                                            get_option('date_format') . ' ' . get_option('time_format'),
                                            $auto_sync_info['next_timestamp']
                                        );
                                        printf(
                                            '<strong>%s</strong><br><span>%s • %s</span>',
                                            esc_html(sprintf(__('Next: %s', 'planet-product-sync'), $next_formatted)),
                                            esc_html(sprintf(__('Method: %s', 'planet-product-sync'), $method_label)),
                                            esc_html($frequency_label)
                                        );
                                    } else {
                                        printf(
                                            '<span>%s</span><br><span>%s • %s</span>',
                                            esc_html__('Next run not scheduled yet.', 'planet-product-sync'),
                                            esc_html(sprintf(__('Method: %s', 'planet-product-sync'), $method_label)),
                                            esc_html($frequency_label)
                                        );
                                    }
                                } else {
                                    esc_html_e('Auto sync is disabled.', 'planet-product-sync');
                                }
                                ?>
                            </div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label"><?php _e('Sync Frequency', 'planet-product-sync'); ?></div>
                            <div class="stat-value stat-info"><?php echo esc_html($frequency_label); ?></div>
                            <?php if ($auto_sync_info['frequency'] === 'daily'): ?>
                                <div class="stat-note">
                                    <?php printf(
                                        esc_html__('Daily run time: %s', 'planet-product-sync'),
                                        esc_html($auto_sync_info['daily_time'])
                                    ); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="planet-actions">
                        <?php 
                        $sync_method = get_option('planet_sync_method', 'ajax');
                        $bg_status = planet_sync_get_background_status();
                        ?>
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
                        
                        <?php if ($sync_method === 'background'): ?>
                        <div class="background-sync-controls">
                            <strong><?php _e('Background Sync Controls:', 'planet-product-sync'); ?></strong>
                            <?php if ($bg_status['is_running']): ?>
                                <?php if ($bg_status['is_paused']): ?>
                                    <button type="button" id="resume-background-sync-btn" class="button button-primary">
                                        <?php _e('Resume Sync', 'planet-product-sync'); ?>
                                    </button>
                                <?php else: ?>
                                    <button type="button" id="pause-background-sync-btn" class="button button-secondary">
                                        <?php _e('Pause Sync', 'planet-product-sync'); ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" id="cancel-background-sync-btn" class="button button-secondary">
                                    <?php _e('Cancel Sync', 'planet-product-sync'); ?>
                                </button>
                            <?php elseif ($bg_status['is_queued'] && !$bg_status['is_processing']): ?>
                                <button type="button" id="trigger-background-sync-btn" class="button button-primary">
                                    <?php _e('Manually Trigger Processing', 'planet-product-sync'); ?>
                                </button>
                                <p class="description" style="margin-top: 5px;">
                                    <?php _e('Queue has items but processing hasn\'t started. Click to manually trigger.', 'planet-product-sync'); ?>
                                </p>
                            <?php endif; ?>
                            
                            <!-- Always visible manual trigger button -->
                            <span class="manual-trigger-wrapper">
                                <button type="button" id="trigger-background-sync-btn-always" class="button button-secondary">
                                    <?php _e('Manual Trigger', 'planet-product-sync'); ?>
                                </button>
                                <span class="planet-tooltip-wrapper">
                                    <span class="dashicons dashicons-info" 
                                          title="<?php esc_attr_e('Manually trigger background sync processing (useful if queue is stuck or not processing automatically).', 'planet-product-sync'); ?>"></span>
                                    <span class="planet-tooltip">
                                        <?php _e('Manually trigger background sync processing (useful if queue is stuck or not processing automatically).', 'planet-product-sync'); ?>
                                    </span>
                                </span>
                            </span>
                            
                            <button type="button" id="get-background-debug-btn" class="button button-secondary" style="margin-left: 10px;">
                                <?php _e('Debug Info', 'planet-product-sync'); ?>
                            </button>
                            <span class="background-sync-status" style="margin-left: 10px; color: #666;">
                                <?php 
                                if ($bg_status['is_processing']) {
                                    _e('Processing...', 'planet-product-sync');
                                } elseif ($bg_status['is_queued']) {
                                    _e('Queued', 'planet-product-sync');
                                } elseif ($bg_status['is_paused']) {
                                    _e('Paused', 'planet-product-sync');
                                } else {
                                    _e('Idle', 'planet-product-sync');
                                }
                                ?>
                            </span>
                        </div>
                        <?php endif; ?>
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
                
                <!-- Orphaned Products Section -->
                <div class="planet-sync-card">
                    <h2><?php _e('Orphaned Products', 'planet-product-sync'); ?></h2>
                    <p><?php _e('Products that exist locally but not in the remote Planet API. Action taken based on your settings.', 'planet-product-sync'); ?></p>
                    <?php $this->render_orphaned_products_info(); ?>
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
                                        <option value="1min" <?php selected(get_option('planet_sync_frequency'), '1min'); ?>>
                                            <?php _e('Every 1 Minute (testing only, may be heavy)', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="5min" <?php selected(get_option('planet_sync_frequency'), '5min'); ?>>
                                            <?php _e('Every 5 Minutes', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="hourly" <?php selected(get_option('planet_sync_frequency'), 'hourly'); ?>>
                                            <?php _e('Hourly', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="twice_daily" <?php selected(get_option('planet_sync_frequency'), 'twice_daily'); ?>>
                                            <?php _e('Twice Daily', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="daily" <?php selected(get_option('planet_sync_frequency'), 'daily'); ?>>
                                            <?php _e('Daily', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="weekly" <?php selected(get_option('planet_sync_frequency'), 'weekly'); ?>>
                                            <?php _e('Weekly', 'planet-product-sync'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="planet_sync_daily_time"><?php _e('Daily Sync Time', 'planet-product-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="time"
                                           id="planet_sync_daily_time"
                                           name="planet_sync_daily_time"
                                           value="<?php echo esc_attr(get_option('planet_sync_daily_time', '02:00')); ?>">
                                    <p class="description">
                                        <?php _e('Choose the local time for the daily sync to start.', 'planet-product-sync'); ?>
                                    </p>
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
                            
                            <tr>
                                <th scope="row">
                                    <label for="planet_sync_method"><?php _e('Sync Method', 'planet-product-sync'); ?></label>
                                </th>
                                <td>
                                    <select id="planet_sync_method" name="planet_sync_method">
                                        <option value="ajax" <?php selected(get_option('planet_sync_method', 'ajax'), 'ajax'); ?>>
                                            <?php _e('AJAX (Real-time with browser open)', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="background" <?php selected(get_option('planet_sync_method', 'ajax'), 'background'); ?>>
                                            <?php _e('Background (Runs without browser)', 'planet-product-sync'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php _e('AJAX: Real-time updates, requires browser to stay open. Background: Runs via WP-Cron, no browser needed. Background method is recommended for scheduled/automated syncs.', 'planet-product-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="planet_sync_orphaned_action"><?php _e('Orphaned Products Action', 'planet-product-sync'); ?></label>
                                </th>
                                <td>
                                    <select id="planet_sync_orphaned_action" name="planet_sync_orphaned_action">
                                        <option value="keep" <?php selected(get_option('planet_sync_orphaned_action', 'keep'), 'keep'); ?>>
                                            <?php _e('Keep (Do Nothing)', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="draft" <?php selected(get_option('planet_sync_orphaned_action', 'keep'), 'draft'); ?>>
                                            <?php _e('Set to Draft', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="trash" <?php selected(get_option('planet_sync_orphaned_action', 'keep'), 'trash'); ?>>
                                            <?php _e('Move to Trash', 'planet-product-sync'); ?>
                                        </option>
                                        <option value="delete" <?php selected(get_option('planet_sync_orphaned_action', 'keep'), 'delete'); ?>>
                                            <?php _e('Permanently Delete', 'planet-product-sync'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php _e('What to do with local products that exist in WooCommerce but not in the remote Planet API. "Keep" means no action will be taken. Note: Only products synced by this plugin (with Planet metadata) will be affected.', 'planet-product-sync'); ?>
                                    </p>
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
        $new_products = $this->logger->get_recent_logs(600, 'product', 'create');
        
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
     * Render orphaned products information
     */
    private function render_orphaned_products_info() {
        $orphaned_action = get_option('planet_sync_orphaned_action', 'keep');
        $orphaned_logs = $this->logger->get_recent_logs(100, 'product', 'delete');
        
        // Filter for orphaned products (those with "orphaned" in message)
        $orphaned_products = array_filter($orphaned_logs, function($log) {
            return stripos($log->message, 'orphaned') !== false;
        });
        
        $action_labels = array(
            'keep' => __('Keep (No Action)', 'planet-product-sync'),
            'draft' => __('Set to Draft', 'planet-product-sync'),
            'trash' => __('Move to Trash', 'planet-product-sync'),
            'delete' => __('Permanently Delete', 'planet-product-sync')
        );
        
        ?>
        <div style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <strong><?php _e('Current Action:', 'planet-product-sync'); ?></strong> 
            <?php echo esc_html($action_labels[$orphaned_action] ?? $orphaned_action); ?>
            <br>
            <small><?php _e('You can change this in the Settings section below.', 'planet-product-sync'); ?></small>
        </div>
        
        <?php if (empty($orphaned_products)): ?>
            <p><?php _e('No orphaned products found in recent syncs.', 'planet-product-sync'); ?></p>
        <?php else: ?>
        <div class="planet-logs-table">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Product Identifier', 'planet-product-sync'); ?></th>
                        <th><?php _e('Action', 'planet-product-sync'); ?></th>
                        <th><?php _e('Message', 'planet-product-sync'); ?></th>
                        <th><?php _e('Time', 'planet-product-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orphaned_products as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log->slug_or_id); ?></td>
                        <td><span class="log-badge log-action-<?php echo esc_attr($log->action); ?>"><?php echo esc_html($log->action); ?></span></td>
                        <td><?php echo esc_html($log->message); ?></td>
                        <td><?php echo esc_html($log->created_at); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif;
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

        if (isset($_POST['planet_sync_daily_time'])) {
            update_option('planet_sync_daily_time', $this->sanitize_time_value($_POST['planet_sync_daily_time']));
        }
        
        update_option('planet_sync_debug_mode', isset($_POST['planet_sync_debug_mode']) ? 'yes' : 'no');
        
        if (isset($_POST['planet_sync_method'])) {
            update_option('planet_sync_method', sanitize_text_field($_POST['planet_sync_method']));
        }
        
        if (isset($_POST['planet_sync_orphaned_action'])) {
            update_option('planet_sync_orphaned_action', sanitize_text_field($_POST['planet_sync_orphaned_action']));
        }

        if (function_exists('planet_sync_setup_cron')) {
            planet_sync_setup_cron(true);
        }
    }

    /**
     * Sanitize a HH:MM formatted time string
     *
     * @param string $value
     * @return string
     */
    private function sanitize_time_value($value) {
        $value = trim((string) $value);

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return $value;
        }

        return '02:00';
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
    
    /**
     * AJAX: Start background sync
     */
    public function ajax_start_background_sync() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $result = planet_sync_start_background();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get background sync status
     */
    public function ajax_get_background_status() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $status = planet_sync_get_background_status();
        $progress = $this->sync_engine->get_sync_progress();
        
        wp_send_json_success(array(
            'status' => $status,
            'progress' => $progress
        ));
    }
    
    /**
     * AJAX: Pause background sync
     */
    public function ajax_pause_background_sync() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        if (!class_exists('Planet_Background_Sync')) {
            wp_send_json_error(array('message' => 'Background sync not available'));
        }
        
        $background_sync = new Planet_Background_Sync();
        $background_sync->pause();
        
        wp_send_json_success(array('message' => 'Background sync paused'));
    }
    
    /**
     * AJAX: Resume background sync
     */
    public function ajax_resume_background_sync() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        if (!class_exists('Planet_Background_Sync')) {
            wp_send_json_error(array('message' => 'Background sync not available'));
        }
        
        $background_sync = new Planet_Background_Sync();
        $background_sync->resume();
        
        wp_send_json_success(array('message' => 'Background sync resumed'));
    }
    
    /**
     * AJAX: Cancel background sync
     */
    public function ajax_cancel_background_sync() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        if (!class_exists('Planet_Background_Sync')) {
            wp_send_json_error(array('message' => 'Background sync not available'));
        }
        
        $background_sync = new Planet_Background_Sync();
        $background_sync->cancel();
        
        // Cleanup
        delete_transient('planet_sync_in_progress');
        delete_option('planet_sync_progress');
        delete_transient('planet_sync_progress_cache');
        
        wp_send_json_success(array('message' => 'Background sync cancelled'));
    }
    
    /**
     * AJAX: Manually trigger background sync processing
     */
    public function ajax_trigger_background_sync() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        if (!class_exists('Planet_Background_Sync')) {
            wp_send_json_error(array('message' => 'Background sync not available'));
        }
        
        $background_sync = new Planet_Background_Sync();
        
        // Try to trigger processing
        $triggered = $background_sync->trigger_processing();
        
        if ($triggered) {
            wp_send_json_success(array('message' => 'Background sync processing triggered successfully'));
        } else {
            $status = $background_sync->get_status();
            $message = 'Failed to trigger processing. ';
            if ($status['is_processing']) {
                $message .= 'Process is already running.';
            } elseif (!$status['is_queued']) {
                $message .= 'Queue is empty.';
            } else {
                $message .= 'Unknown error.';
            }
            wp_send_json_error(array('message' => $message, 'status' => $status));
        }
    }
    
    /**
     * AJAX: Get background sync debug information
     */
    public function ajax_get_background_debug() {
        check_ajax_referer('planet_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        if (!class_exists('Planet_Background_Sync')) {
            wp_send_json_error(array('message' => 'Background sync not available'));
        }
        
        $background_sync = new Planet_Background_Sync();
        $debug_info = $background_sync->get_debug_info();
        $status = $background_sync->get_status();
        
        wp_send_json_success(array(
            'debug' => $debug_info,
            'status' => $status,
            'wc_loaded' => class_exists('WC_Background_Process'),
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
        ));
    }

    /**
     * Get information about the next scheduled auto sync
     *
     * @return array
     */
    private function get_next_auto_sync_info() {
        $enabled      = get_option('planet_sync_auto_sync', 'no') === 'yes';
        $frequency    = get_option('planet_sync_frequency', 'hourly');
        $daily_time   = get_option('planet_sync_daily_time', '02:00');
        $sync_method  = get_option('planet_sync_method', 'ajax');
        $next_run     = false;

        if ($enabled) {
            if (function_exists('planet_sync_is_action_scheduler_ready') && planet_sync_is_action_scheduler_ready()) {
                $next_run = as_next_scheduled_action('planet_auto_sync');
            }

            if (!$next_run) {
                $next_run = wp_next_scheduled('planet_auto_sync');
            }
        }

        return array(
            'enabled' => $enabled,
            'frequency' => $frequency,
            'daily_time' => $daily_time,
            'sync_method' => $sync_method,
            'next_timestamp' => $next_run,
        );
    }
}

