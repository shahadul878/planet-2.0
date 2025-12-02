<?php
/**
 * Background Sync Process for Planet Product Sync 2.0
 * Handles background processing of product sync using WC_Background_Process
 * 
 * Author: H M Shahadul Islam
 * Author URI: https://github.com/shahadul878
 */

if (!defined('ABSPATH')) {
    exit;
}



class Planet_Background_Sync extends WP_Background_Process {
    
    /**
     * Status constants (inherited from parent, but defined here for reliability)
     */
    const STATUS_CANCELLED = 1;
    const STATUS_PAUSED = 2;
    
    /**
     * Action identifier
     * 
     * @var string
     */
    protected $action = 'planet_product_sync';
    
    /**
     * Prefix for queue identifier
     * 
     * @var string
     */
    protected $prefix = 'planet_sync';
    
    /**
     * Action Scheduler group name
     * 
     * @var string
     */
    protected $as_group = 'planet-sync';
    
    /**
     * Action Scheduler action hook for direct queue processing
     * 
     * @var string
     */
    protected $as_process_hook = 'planet_sync_process_queue_item';
    
    /**
     * Cron interval in minutes (default: 5)
     * 
     * @var int
     */
    protected $cron_interval = 5;

    /**
     * Maximum automatic retry attempts per product before marking as failed.
     * Filterable via 'planet_sync_max_attempts'.
     *
     * @var int
     */
    protected $max_attempts = 3;

    /**
     * Sleep time in seconds between products in background mode.
     * Kept low so more products are processed within the time window.
     *
     * @var int
     */
    protected $per_item_sleep = 1;
    
    /**
     * Constructor - register Action Scheduler hook for direct processing
     */
    public function __construct() {
        parent::__construct();
        
        // Register Action Scheduler hook for direct queue processing
        add_action($this->as_process_hook, array($this, 'process_queue_item_via_as'), 10, 0);
    }
    
    /**
     * Get cron interval in minutes
     * Wrapper method to ensure it's always available
     * 
     * @return int Interval in minutes
     */
    public function get_cron_interval() {
        // Use our own property or default
        $interval = isset($this->cron_interval) ? $this->cron_interval : 5;
        
        // Allow filtering if cron_interval_identifier is set
        if (isset($this->cron_interval_identifier)) {
            $interval = apply_filters($this->cron_interval_identifier, $interval);
        }
        
        // Ensure it's a valid integer
        $interval = is_int($interval) && $interval > 0 ? $interval : 5;
        
        return $interval;
    }
    
    /**
     * Process a single product from the queue
     * 
     * @param string $product_slug Product slug to process
     * @return mixed False to remove from queue, or item to retry
     */
    protected function task($product_slug) {
        // Check if cancelled
        if ($this->is_cancelled()) {
            return false; // Remove from queue
        }
        
        // Check if paused
        if ($this->is_paused()) {
            return $product_slug; // Return item to retry later
        }
        
        // Set transient to indicate sync is in progress
        set_transient('planet_sync_in_progress', true, 3600);
        
        $sync_engine = new Planet_Sync_Engine();
        $logger = new Planet_Sync_Logger();
        
        // Log that we're processing this product
        $logger->log('product', 'info', $product_slug, 'Processing product in background');
        
        // Find queue item first to track attempts
        global $wpdb;
        $queue_table = $wpdb->prefix . 'planet_sync_queue';
        $batch_id = get_option('planet_current_sync_batch', '');
        
        // If batch_id is missing, try to find it from the queue table
        if (empty($batch_id)) {
            $found_batch = $wpdb->get_var($wpdb->prepare(
                "SELECT sync_batch_id FROM {$queue_table} 
                WHERE product_slug = %s 
                AND (status = 'pending' OR status = 'failed')
                ORDER BY created_at DESC
                LIMIT 1",
                $product_slug
            ));
            
            if ($found_batch) {
                $batch_id = $found_batch;
                // Restore the option for future use
                update_option('planet_current_sync_batch', $batch_id, false);
                $logger->log('product', 'info', $product_slug, 'Recovered batch_id from queue table: ' . $batch_id);
            }
        }
        
        $queue_item = null;
        if (!empty($batch_id)) {
            $queue_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$queue_table} 
                WHERE sync_batch_id = %s 
                AND product_slug = %s 
                AND (status = 'pending' OR status = 'failed')
                LIMIT 1",
                $batch_id,
                $product_slug
            ));
        } else {
            $logger->log('product', 'warning', $product_slug, 'No batch_id found - queue item tracking may not work');
        }
        
        // Fetch full product details
        $full_product = $sync_engine->api->get_product_by_slug($product_slug);
        
        // Allow max attempts to be filtered
        $max_attempts = (int) apply_filters('planet_sync_max_attempts', $this->max_attempts);
        if ($max_attempts < 1) {
            $max_attempts = 1;
        }

        if ($full_product === false) {
            $logger->log('product', 'error', $product_slug, 'Failed to fetch product details from API');
            
            // Increment attempts if queue item exists
            if ($queue_item) {
                $sync_engine->queue->increment_attempts($queue_item->id);
                
                // Get updated attempt count
                $updated_item = $wpdb->get_row($wpdb->prepare(
                    "SELECT attempts FROM {$queue_table} WHERE id = %d",
                    $queue_item->id
                ));
                
                $attempts = $updated_item ? (int)$updated_item->attempts : ($queue_item->attempts + 1);
                
                // Mark as failed after max attempts
                if ($attempts >= $max_attempts) {
                    $sync_engine->queue->mark_as_synced($queue_item->id, 'failed', 'Failed to fetch product details from API after ' . $attempts . ' attempts');
                    $logger->log('product', 'error', $product_slug, sprintf('Max retry attempts (%d) reached, marking as failed', $attempts));
                    return false; // Remove from queue
                }
            }
            
            // Retry this item
            $logger->log('product', 'warning', $product_slug, sprintf('Will retry (attempt %d of %d)', $queue_item ? ($queue_item->attempts + 1) : 1, $max_attempts));
            return $product_slug;
        }
        
        // Process the product
        try {
            $result = $sync_engine->process_single_product($product_slug, $full_product);
        } catch (Exception $e) {
            $logger->log('product', 'error', $product_slug, 'Exception during product processing: ' . $e->getMessage());
            $result = 'error';
            
            // Increment attempts if queue item exists
            if ($queue_item) {
                $sync_engine->queue->increment_attempts($queue_item->id);
                $updated_item = $wpdb->get_row($wpdb->prepare(
                    "SELECT attempts FROM {$queue_table} WHERE id = %d",
                    $queue_item->id
                ));
                $attempts = $updated_item ? (int)$updated_item->attempts : ($queue_item->attempts + 1);
                
                if ($attempts >= $max_attempts) {
                    $sync_engine->queue->mark_as_synced($queue_item->id, 'failed', 'Exception: ' . $e->getMessage());
                    return false; // Remove from queue after max attempts
                }
            }
            
            return $product_slug; // Retry on exception
        }
        
        // Update queue item if it exists (we already have it from above)
        if (!$queue_item && !empty($batch_id)) {
            // Try to find it again in case it wasn't found earlier
            $queue_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$queue_table} 
                WHERE sync_batch_id = %s 
                AND product_slug = %s 
                LIMIT 1",
                $batch_id,
                $product_slug
            ));
        }
        
        if ($queue_item) {
            $status_map = array(
                'created' => 'synced',
                'updated' => 'synced',
                'skipped' => 'skipped',
                'error' => 'failed'
            );
            $queue_status = isset($status_map[$result]) ? $status_map[$result] : 'failed';
            $product_name = isset($full_product['desc']) ? $full_product['desc'] : $product_slug;
            
            switch ($result) {
                case 'skipped':
                    $message = 'Skipped - No change detected: ' . $product_name;
                    break;
                case 'updated':
                    $message = 'Updated: ' . $product_name;
                    break;
                case 'created':
                    $message = 'Created: ' . $product_name;
                    break;
                case 'error':
                    $message = 'Error processing: ' . $product_name;
                    break;
                default:
                    $message = ucfirst($result) . ': ' . $product_name;
            }
            
            $sync_engine->queue->mark_as_synced($queue_item->id, $queue_status, $message);
        } else {
            // Queue item not found - log warning but continue processing
            $logger->log('product', 'warning', $product_slug, sprintf(
                'Queue item not found in batch %s - product processed but not tracked in queue table',
                $batch_id ?: 'none'
            ));
        }
        
        // Update progress statistics
        $stats = $sync_engine->queue->get_statistics();
        $sync_engine->update_progress($stats);
        
        // Rate limiting - wait briefly between products.
        // Use a small, filterable delay so we can tune performance vs. load.
        $sleep_seconds = apply_filters('planet_sync_background_sleep_seconds', $this->per_item_sleep);
        $sleep_seconds = max(0, (int) $sleep_seconds);
        if ($sleep_seconds > 0) {
            sleep($sleep_seconds);
        }
        
        // Handle result and retry logic
        if ($result === 'error') {
            // Increment attempts if queue item exists
            if ($queue_item) {
                $sync_engine->queue->increment_attempts($queue_item->id);
                
                // Get updated attempt count
                $updated_item = $wpdb->get_row($wpdb->prepare(
                    "SELECT attempts FROM {$queue_table} WHERE id = %d",
                    $queue_item->id
                ));
                
                $attempts = $updated_item ? (int)$updated_item->attempts : ($queue_item->attempts + 1);
                
                // Mark as failed after max attempts
                if ($attempts >= $max_attempts) {
                    $sync_engine->queue->mark_as_synced($queue_item->id, 'failed', 'Error processing product after ' . $attempts . ' attempts');
                    $logger->log('product', 'error', $product_slug, sprintf('Max retry attempts (%d) reached, marking as failed', $attempts));
                    return false; // Remove from queue after max attempts
                }
                
                $logger->log('product', 'warning', $product_slug, sprintf('Will retry on error (attempt %d of %d)', $attempts, $max_attempts));
            } else {
                $logger->log('product', 'warning', $product_slug, 'Error processing product, will retry (no queue item found for attempt tracking)');
            }
            
            // Retry on error
            return $product_slug;
        }
        
        // Success - remove from queue
        return false;
    }
    
    /**
     * Called when queue is complete
     * Handles automatic retry of failed products once before final cleanup.
     */
    protected function complete() {
        $logger = new Planet_Sync_Logger();
        $sync_engine = new Planet_Sync_Engine();

        // Determine current batch
        $batch_id = get_option('planet_current_sync_batch', '');
        $stats    = $sync_engine->queue->get_statistics($batch_id);

        // Automatic second-wave retry for failed products (once per batch)
        if ($batch_id && !empty($stats['failed'])) {
            $retry_flag_key = 'planet_sync_retry_done_' . $batch_id;
            $retry_done     = get_option($retry_flag_key, 'no') === 'yes';

            if (!$retry_done) {
                // Get failed products for this batch
                $failed_products = $sync_engine->queue->get_failed_products($batch_id);
                $failed_count    = is_array($failed_products) ? count($failed_products) : 0;

                if ($failed_count > 0) {
                    // Reset failed items to pending and clear attempts
                    $reset_count = $sync_engine->queue->reset_failed_to_pending($batch_id);

                    $logger->log('system', 'info', '', sprintf(
                        'Automatically retrying %d failed products in batch %s (reset %d items to pending)',
                        $failed_count,
                        $batch_id,
                        $reset_count
                    ));

                    // Push failed product slugs back into the background queue
                    foreach ($failed_products as $item) {
                        if (!empty($item->product_slug)) {
                            $this->push_to_queue($item->product_slug);
                        }
                    }

                    // Save and dispatch a new processing run
                    $this->save();

                    // Prefer HTTP dispatch; if that fails, our fallback AS worker will handle it
                    $dispatch_result = $this->dispatch();
                    if (is_wp_error($dispatch_result)) {
                        $logger->log('system', 'warning', '', sprintf(
                            'Dispatch for automatic retry failed: %s - queue will be processed by cron/Action Scheduler',
                            $dispatch_result->get_error_message()
                        ));
                    } else {
                        $logger->log('system', 'info', '', 'Automatic retry dispatch started for failed products');
                    }

                    // Mark that we already retried this batch to avoid infinite loops
                    update_option($retry_flag_key, 'yes', false);

                    // Do NOT run final cleanup yet; the new run will call complete() again
                    return;
                }
            }
        }

        // If we reach here, either there are no failed items or we've already retried once.
        parent::complete();

        // Final cleanup
        if ($batch_id) {
            $sync_engine->queue->cleanup_batch($batch_id);
        }

        delete_transient('planet_sync_in_progress');
        delete_option('planet_sync_progress');
        delete_transient('planet_sync_progress_cache');

        // Update last sync time
        update_option('_last_planet_sync', current_time('timestamp'));

        // Recalculate final statistics after cleanup
        $final_stats = $sync_engine->queue->get_statistics($batch_id);

        // Log completion
        $logger->log('system', 'sync_complete', '', sprintf(
            'Background sync completed: %d synced, %d failed, %d skipped',
            $final_stats['synced'], $final_stats['failed'], $final_stats['skipped']
        ));

        // Fire action for other plugins/themes
        do_action('planet_sync_background_complete', $final_stats);
    }
    
    /**
     * Check if queue has items
     * 
     * @return bool
     */
    public function is_queued() {
        return !$this->is_queue_empty();
    }
    
    /**
     * Check if process is currently running
     * 
     * @return bool
     */
    public function is_processing() {
        if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
            return true;
        }
        return false;
    }
    
    /**
     * Get the internal status value (protected method from parent)
     * This is a helper to access the parent's protected get_status() method
     * 
     * @return int
     */
    protected function get_internal_status() {
        global $wpdb;

        $status_key = $this->identifier . '_status';
        
        if ( is_multisite() ) {
            $status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM $wpdb->sitemeta WHERE meta_key = %s AND site_id = %d LIMIT 1",
                    $status_key,
                    get_current_network_id()
                )
            );
        } else {
            $status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1",
                    $status_key
                )
            );
        }

        return absint( $status );
    }
    
    /**
     * Check if process is paused
     * 
     * @return bool
     */
    public function is_paused() {
        return $this->get_internal_status() === self::STATUS_PAUSED;
    }
    
    /**
     * Check if process is cancelled
     * 
     * @return bool
     */
    public function is_cancelled() {
        return $this->get_internal_status() === self::STATUS_CANCELLED;
    }
    
    /**
     * Check if process is active (queued, processing, paused, or cancelling)
     * 
     * @return bool
     */
    public function is_active() {
        return $this->is_queued() || $this->is_processing() || $this->is_paused() || $this->is_cancelled();
    }
    
    /**
     * Check if background sync is running
     * 
     * @return bool
     */
    public function is_running() {
        return $this->is_queued() || $this->is_processing();
    }
    
    /**
     * Pause the background process
     */
    public function pause() {
        parent::pause();
    }
    
    /**
     * Resume the background process
     */
    public function resume() {
        parent::resume();
    }
    
    /**
     * Cancel the background process
     */
    public function cancel() {
        parent::cancel();
        
        // Additional cleanup for sync-specific data
        delete_transient('planet_sync_in_progress');
        delete_option('planet_sync_progress');
        delete_transient('planet_sync_progress_cache');
    }
    
    /**
     * Get sync status
     * 
     * @return array Status information
     */
    public function get_status() {
        return array(
            'is_queued' => $this->is_queued(),
            'is_processing' => $this->is_processing(),
            'is_paused' => $this->is_paused(),
            'is_cancelled' => $this->is_cancelled(),
            'is_active' => $this->is_active(),
        );
    }
    
    /**
     * Override dispatch to ensure Action Scheduler is scheduled even if HTTP request fails
     * 
     * @return mixed Result of parent dispatch
     */
    public function dispatch() {
        // Always schedule the Action Scheduler healthcheck first
        $this->schedule_event();
        
        // Then try the HTTP dispatch
        $result = parent::dispatch();
        
        // If HTTP dispatch fails, log it but don't fail completely
        // Use Action Scheduler to process queue items directly
        if (is_wp_error($result)) {
            $logger = new Planet_Sync_Logger();
            $error_msg = $result->get_error_message();
            $logger->log('system', 'warning', '', sprintf(
                'Background sync HTTP dispatch failed: %s - Using Action Scheduler fallback',
                $error_msg
            ));
            
            // Schedule Action Scheduler to process queue items directly
            // This is more reliable than HTTP dispatch
            if (!$this->is_processing() && !$this->is_queue_empty()) {
                $this->schedule_queue_item_processing();
            }
        } else {
            $logger = new Planet_Sync_Logger();
            $logger->log('system', 'info', '', 'Background sync dispatched successfully via HTTP');
        }
        
        return $result;
    }
    
    /**
     * Manually trigger processing (useful when HTTP dispatch fails)
     * This directly calls the handle() method to process the queue
     * 
     * @return bool True if processing started, false otherwise
     */
    public function trigger_processing() {
        // Check if already processing
        if ($this->is_processing()) {
            $logger = new Planet_Sync_Logger();
            $logger->log('system', 'info', '', 'Background sync already processing, skipping trigger');
            return false;
        }
        
        // Check if queue is empty
        if ($this->is_queue_empty()) {
            $logger = new Planet_Sync_Logger();
            $logger->log('system', 'info', '', 'Background sync queue is empty, nothing to process');
            return false;
        }
        
        // Ensure cron is scheduled for future runs
        $this->schedule_event();
        
        $logger = new Planet_Sync_Logger();
        $logger->log('system', 'info', '', 'Manually triggering background sync processing');
        
        // Use reflection to call protected handle() method
        try {
            $reflection = new ReflectionClass($this);
            $handle_method = $reflection->getMethod('handle');
            $handle_method->setAccessible(true);
            
            // Call handle() which will process the queue
            $handle_method->invoke($this);
            
            return true;
        } catch (ReflectionException $e) {
            $logger->log('system', 'error', '', 'Failed to trigger processing via reflection: ' . $e->getMessage());
            
            // Fallback: try dispatch again
            $result = $this->dispatch();
            return !is_wp_error($result);
        }
    }
    
    /**
     * Manually trigger the cron healthcheck
     * Useful for debugging or when cron isn't running
     * 
     * @return bool True if healthcheck was triggered
     */
    public function trigger_healthcheck() {
        $hook = $this->cron_hook_identifier;
        
        // Check if queue is empty
        if ($this->is_queue_empty()) {
            $logger = new Planet_Sync_Logger();
            $logger->log('system', 'info', '', 'Healthcheck skipped: queue is empty');
            return false;
        }
        
        // Check if already processing
        if ($this->is_processing()) {
            $logger = new Planet_Sync_Logger();
            $logger->log('system', 'info', '', 'Healthcheck skipped: process already running');
            return false;
        }
        
        $logger = new Planet_Sync_Logger();
        $logger->log('system', 'info', '', 'Manually triggering cron healthcheck');
        
        // Manually call the healthcheck handler
        $this->handle_cron_healthcheck();
        
        return true;
    }
    
    /**
     * Check if Action Scheduler is initialized and ready to use
     * 
     * @return bool
     */
    protected function is_action_scheduler_ready() {
        if (!function_exists('as_next_scheduled_action')) {
            return false;
        }
        
        // Check if Action Scheduler class exists and is initialized
        if (class_exists('ActionScheduler') && method_exists('ActionScheduler', 'is_initialized')) {
            return ActionScheduler::is_initialized();
        }
        
        // Fallback: if class doesn't exist, assume not ready
        return false;
    }
    
    /**
     * Override schedule_event to use Action Scheduler instead of WordPress cron
     * 
     * @return void
     */
    protected function schedule_event() {
        // Check if Action Scheduler is available and initialized
        if ($this->is_action_scheduler_ready()) {
            // Check if already scheduled
            $next_scheduled = as_next_scheduled_action($this->cron_hook_identifier, array(), $this->as_group);
            
            if (!$next_scheduled) {
                // Use parent's interval calculation
                $interval = $this->get_cron_interval();
                $start_time = time() + ($interval * MINUTE_IN_SECONDS);
                
                // Schedule recurring action with Action Scheduler
                $action_id = as_schedule_recurring_action(
                    $start_time,
                    $interval * MINUTE_IN_SECONDS,
                    $this->cron_hook_identifier,
                    array(),
                    $this->as_group,
                    false,
                    10
                );
                
                $logger = new Planet_Sync_Logger();
                if ($action_id) {
                    $logger->log('system', 'info', '', sprintf(
                        'Scheduled background sync healthcheck via Action Scheduler: %s (interval: %d minutes, action ID: %d)',
                        $this->cron_hook_identifier,
                        $interval,
                        $action_id
                    ));
                } else {
                    $logger->log('system', 'error', '', sprintf(
                        'Failed to schedule Action Scheduler healthcheck for: %s',
                        $this->cron_hook_identifier
                    ));
                }
            }
        } else {
            // Fallback to WordPress cron if Action Scheduler is not available
            if (!wp_next_scheduled($this->cron_hook_identifier)) {
                $interval = $this->get_cron_interval();
                $start_time = time() + ($interval * MINUTE_IN_SECONDS);
                
                wp_schedule_event($start_time, $this->cron_interval_identifier, $this->cron_hook_identifier);
                
                $logger = new Planet_Sync_Logger();
                $logger->log('system', 'info', '', sprintf(
                    'Scheduled background sync healthcheck via WordPress cron (fallback): %s (interval: %d minutes)',
                    $this->cron_hook_identifier,
                    $interval
                ));
            }
        }
    }
    
    /**
     * Schedule an immediate Action Scheduler action as fallback
     * 
     * @return void
     */
    protected function schedule_immediate_as_action() {
        if ($this->is_action_scheduler_ready() && function_exists('as_schedule_single_action')) {
            // Check if already scheduled
            $next_scheduled = as_next_scheduled_action($this->cron_hook_identifier, array(), $this->as_group);
            
            if (!$next_scheduled || $next_scheduled > time() + 60) {
                // Schedule to run in 5 seconds
                $action_id = as_schedule_single_action(
                    time() + 5,
                    $this->cron_hook_identifier,
                    array(),
                    $this->as_group,
                    false,
                    5 // Higher priority for immediate actions
                );
                
                if ($action_id) {
                    $logger = new Planet_Sync_Logger();
                    $logger->log('system', 'info', '', sprintf(
                        'Scheduled immediate Action Scheduler fallback action (ID: %d)',
                        $action_id
                    ));
                }
            }
        }
    }
    
    /**
     * Schedule Action Scheduler action to process a single queue item
     * This is more reliable than HTTP dispatch
     * 
     * @return void
     */
    protected function schedule_queue_item_processing() {
        if ($this->is_action_scheduler_ready() && function_exists('as_schedule_single_action')) {
            // Check if already scheduled
            $next_scheduled = as_next_scheduled_action($this->as_process_hook, array(), $this->as_group);
            
            if (!$next_scheduled || $next_scheduled > time() + 10) {
                // Schedule to run immediately (or in 2 seconds to avoid conflicts)
                $action_id = as_schedule_single_action(
                    time() + 2,
                    $this->as_process_hook,
                    array(),
                    $this->as_group,
                    false,
                    10 // High priority
                );
                
                if ($action_id) {
                    $logger = new Planet_Sync_Logger();
                    $logger->log('system', 'info', '', sprintf(
                        'Scheduled queue item processing via Action Scheduler (ID: %d)',
                        $action_id
                    ));
                }
            }
        }
    }
    
    /**
     * Process a single queue item via Action Scheduler
     * This method is called directly by Action Scheduler
     * 
     * @return void
     */
    public function process_queue_item_via_as() {
        $logger = new Planet_Sync_Logger();
        
        // Check if already processing
        if ($this->is_processing()) {
            $logger->log('system', 'info', '', 'Queue processing skipped: already running');
            return;
        }
        
        // Check if queue is empty
        if ($this->is_queue_empty()) {
            $logger->log('system', 'info', '', 'Queue processing skipped: queue is empty');
            $this->clear_scheduled_event();
            return;
        }
        
        // Check if cancelled or paused
        if ($this->is_cancelled() || $this->is_paused()) {
            $logger->log('system', 'info', '', 'Queue processing skipped: cancelled or paused');
            return;
        }
        
        $logger->log('system', 'info', '', 'Processing queue item via Action Scheduler');
        
        try {
            // Get a batch (one item at a time to avoid timeout)
            $batch = $this->get_batch();
            
            if (empty($batch->data)) {
                $logger->log('system', 'info', '', 'No items in batch');
                return;
            }
            
            // Process first item in batch
            $first_key = array_key_first($batch->data);
            $first_item = $batch->data[$first_key];
            
            $logger->log('system', 'info', '', sprintf('Processing item: %s', $first_item));
            
            // Lock process
            $this->lock_process();
            
            // Process the item
            $task_result = $this->task($first_item);
            
            // Update batch
            if (false !== $task_result) {
                $batch->data[$first_key] = $task_result;
                $this->update($batch->key, $batch->data);
            } else {
                unset($batch->data[$first_key]);
                if (!empty($batch->data)) {
                    $this->update($batch->key, $batch->data);
                } else {
                    $this->delete($batch->key);
                }
            }
            
            // Unlock process
            $this->unlock_process();
            
            // Schedule next item if queue not empty
            if (!$this->is_queue_empty()) {
                $this->schedule_queue_item_processing();
            } else {
                // Queue complete
                $this->complete();
                $logger->log('system', 'info', '', 'Queue processing complete');
            }
            
        } catch (Exception $e) {
            $this->unlock_process();
            $logger->log('system', 'error', '', 'Exception processing queue item: ' . $e->getMessage());
            // Schedule retry
            if (!$this->is_queue_empty()) {
                $this->schedule_queue_item_processing();
            }
        } catch (Error $e) {
            $this->unlock_process();
            $logger->log('system', 'error', '', 'Fatal error processing queue item: ' . $e->getMessage());
            // Schedule retry
            if (!$this->is_queue_empty()) {
                $this->schedule_queue_item_processing();
            }
        }
    }
    
    /**
     * Override clear_scheduled_event to use Action Scheduler
     * 
     * @return void
     */
    protected function clear_scheduled_event() {
        // Check if Action Scheduler is available and initialized
        if ($this->is_action_scheduler_ready() && function_exists('as_unschedule_all_actions')) {
            // Unschedule all healthcheck actions
            $unscheduled_healthcheck = as_unschedule_all_actions($this->cron_hook_identifier, array(), $this->as_group);
            
            // Also unschedule queue processing actions
            $unscheduled_queue = as_unschedule_all_actions($this->as_process_hook, array(), $this->as_group);
            
            $logger = new Planet_Sync_Logger();
            if ($unscheduled_healthcheck > 0 || $unscheduled_queue > 0) {
                $logger->log('system', 'info', '', sprintf(
                    'Cleared %d healthcheck and %d queue processing Action Scheduler action(s)',
                    $unscheduled_healthcheck,
                    $unscheduled_queue
                ));
            }
        } else {
            // Fallback to WordPress cron
            $timestamp = wp_next_scheduled($this->cron_hook_identifier);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $this->cron_hook_identifier);
            }
        }
    }
    
    /**
     * Override handle_cron_healthcheck to add better logging and error handling
     * Action Scheduler doesn't like exit() calls, so we handle it differently
     * 
     * @return void
     */
    public function handle_cron_healthcheck() {
        $start_time = microtime(true);
        $logger = new Planet_Sync_Logger();
        $is_cron = wp_doing_cron();
        
        $logger->log('system', 'info', '', sprintf(
            'Healthcheck triggered (via %s)',
            $is_cron ? 'WordPress cron' : 'Action Scheduler'
        ));
        
        try {
            // Quick check: is already processing?
            if ($this->is_processing()) {
                $logger->log('system', 'info', '', 'Background process already running, skipping healthcheck');
                if ($is_cron) {
                    exit;
                }
                return;
            }
            
            // Quick check: is queue empty?
            if ($this->is_queue_empty()) {
                $logger->log('system', 'info', '', 'Queue is empty, clearing scheduled event');
                $this->clear_scheduled_event();
                if ($is_cron) {
                    exit;
                }
                return;
            }
            
            $logger->log('system', 'info', '', 'Starting background process from healthcheck');
            
            // Try HTTP dispatch first (preferred method)
            $dispatch_result = $this->dispatch();
            $elapsed = round((microtime(true) - $start_time) * 1000, 2);
            
            if (is_wp_error($dispatch_result)) {
                $logger->log('system', 'warning', '', sprintf(
                    'HTTP dispatch failed in healthcheck (took %sms): %s - Using Action Scheduler fallback',
                    $elapsed,
                    $dispatch_result->get_error_message()
                ));
                
                // HTTP dispatch failed - use Action Scheduler to process queue items
                // This ensures products are imported even when HTTP requests fail
                if (!$this->is_processing() && !$this->is_queue_empty()) {
                    // Schedule Action Scheduler action to process queue items directly
                    $this->schedule_queue_item_processing();
                    $logger->log('system', 'info', '', 'Scheduled queue processing via Action Scheduler');
                }
            } else {
                $logger->log('system', 'info', '', sprintf(
                    'Background process dispatched via HTTP from healthcheck (took %sms)',
                    $elapsed
                ));
            }
            
            // Only exit if called via WordPress cron (not Action Scheduler)
            if ($is_cron) {
                exit;
            }
            
            // For Action Scheduler, just return normally
            return;
            
        } catch (Exception $e) {
            $elapsed = round((microtime(true) - $start_time) * 1000, 2);
            $logger->log('system', 'error', '', sprintf(
                'Exception in healthcheck (took %sms): %s | Trace: %s',
                $elapsed,
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            // Re-throw for Action Scheduler to catch and mark as failed
            if (!$is_cron) {
                throw $e;
            }
            // For WordPress cron, exit on error
            exit;
        } catch (Error $e) {
            $elapsed = round((microtime(true) - $start_time) * 1000, 2);
            $logger->log('system', 'error', '', sprintf(
                'Fatal error in healthcheck (took %sms): %s | Trace: %s',
                $elapsed,
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            // Re-throw for Action Scheduler to catch and mark as failed
            if (!$is_cron) {
                throw $e;
            }
            // For WordPress cron, exit on error
            exit;
        }
    }
    
    
    /**
     * Get debug information about the queue
     * 
     * @return array Debug information
     */
    public function get_debug_info() {
        global $wpdb;
        
        $table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
        $column = is_multisite() ? 'meta_key' : 'option_name';
        $key = $wpdb->esc_like($this->identifier . '_batch_') . '%';
        
        $batches = $wpdb->get_results($wpdb->prepare(
            "SELECT {$column} as key_name, option_value as value 
            FROM {$table} 
            WHERE {$column} LIKE %s",
            $key
        ));
        
        $total_items = 0;
        foreach ($batches as $batch) {
            $data = maybe_unserialize($batch->value);
            if (is_array($data)) {
                $total_items += count($data);
            }
        }
        
        // Check both Action Scheduler and WordPress cron
        $next_as = false;
        $next_cron = false;
        
        if ($this->is_action_scheduler_ready()) {
            $next_as = as_next_scheduled_action($this->cron_hook_identifier, array(), $this->as_group);
        }
        
        $next_cron = wp_next_scheduled($this->cron_hook_identifier);
        
        // Use Action Scheduler if available, otherwise fallback to cron
        $next_scheduled = $next_as ? $next_as : $next_cron;
        
        return array(
            'identifier' => $this->identifier,
            'cron_hook' => $this->cron_hook_identifier,
            'is_queued' => $this->is_queued(),
            'is_processing' => $this->is_processing(),
            'is_paused' => $this->is_paused(),
            'is_cancelled' => $this->is_cancelled(),
            'batch_count' => count($batches),
            'total_items' => $total_items,
            'next_as' => $next_as,
            'next_as_formatted' => $next_as ? wp_date('Y-m-d H:i:s', $next_as) : 'Not scheduled',
            'next_cron' => $next_cron,
            'next_cron_formatted' => $next_cron ? wp_date('Y-m-d H:i:s', $next_cron) : 'Not scheduled',
            'next_scheduled' => $next_scheduled,
            'next_scheduled_formatted' => $next_scheduled ? wp_date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled',
            'scheduler_type' => $next_as ? 'Action Scheduler' : ($next_cron ? 'WordPress Cron' : 'None'),
            'process_lock' => get_site_transient($this->identifier . '_process_lock'),
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'action_scheduler_available' => function_exists('as_next_scheduled_action'),
            'action_scheduler_initialized' => $this->is_action_scheduler_ready(),
        );
    }
}

