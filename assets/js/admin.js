/**
 * Planet Product Sync 2.0 - Admin JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Start Sync Button
        $('#start-sync-btn').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to start a full sync? This may take a while.')) {
                return;
            }
            
            startSync();
        });
        
        // Test Connection Button
        $('#test-connection-btn').on('click', function(e) {
            e.preventDefault();
            testConnection();
        });
        
        // Clear Logs Button
        $('#clear-logs-btn').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(planetSync.strings.confirm_clear)) {
                return;
            }
            
            clearLogs();
        });
        
        // Retry Failed Button
        $('#retry-failed-btn').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to retry all failed products?')) {
                return;
            }
            
            retryFailedProducts();
        });
        
        // Auto-load category comparison on page load
        loadCategoryComparison();
        
        // Create Missing Categories Button
        $('#create-missing-categories-btn').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to create all missing categories in WooCommerce?')) {
                return;
            }
            
            createMissingCategories();
        });
        
        // Check if sync is running on page load
        checkSyncStatus();
        
        // Background sync controls
        $('#pause-background-sync-btn').on('click', function(e) {
            e.preventDefault();
            pauseBackgroundSync();
        });
        
        $('#resume-background-sync-btn').on('click', function(e) {
            e.preventDefault();
            resumeBackgroundSync();
        });
        
        $('#cancel-background-sync-btn').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to cancel the background sync?')) {
                return;
            }
            cancelBackgroundSync();
        });
        
        $('#trigger-background-sync-btn').on('click', function(e) {
            e.preventDefault();
            triggerBackgroundSync();
        });
        
        // Always visible manual trigger button
        $('#trigger-background-sync-btn-always').on('click', function(e) {
            e.preventDefault();
            triggerBackgroundSync();
        });
        
        $('#get-background-debug-btn').on('click', function(e) {
            e.preventDefault();
            getBackgroundDebug();
        });
        
        // Check background sync status periodically if background method is selected
        const syncMethod = $('#planet_sync_method').length ? $('#planet_sync_method').val() : (planetSync.syncMethod || 'ajax');
        let backgroundSyncInterval = null;
        
        if (syncMethod === 'background') {
            // Check immediately on page load
            checkBackgroundSyncStatus();
            
            // Set up interval to check every 5 seconds
            backgroundSyncInterval = setInterval(function() {
                checkBackgroundSyncStatus();
            }, 5000);
            
            // Store interval ID so we can clear it if needed
            window.planetSyncBackgroundInterval = backgroundSyncInterval;
            
            // Add visual indicator that auto-refresh is active
            addAutoRefreshIndicator();
        }
    });
    
    /**
     * Start full sync (AJAX or Background based on settings)
     */
    function startSync() {
        const $btn = $('#start-sync-btn');
        const syncMethod = $('#planet_sync_method').length ? $('#planet_sync_method').val() : (planetSync.syncMethod || 'ajax');
        
        // Disable button and show loading
        $btn.prop('disabled', true).addClass('loading').text('Initializing...');
        
        if (syncMethod === 'background') {
            // Use background sync
            startBackgroundSync();
        } else {
            // Use AJAX sync (existing method)
            startAjaxSync();
        }
    }
    
    /**
     * Start AJAX sync (real-time one-by-one processing)
     */
    function startAjaxSync() {
        const $btn = $('#start-sync-btn');
        
        // Initialize sync (Step 1 & 2: Validate categories + Fetch product list)
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_start_sync',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', 'Sync initialized: ' + response.data.total_products + ' products to process');
                    $btn.text('Processing...');
                    
                    // Start processing products one by one
                    processNextProduct();
                } else {
                    showNotice('error', response.data.message || 'Sync initialization failed');
                    $btn.prop('disabled', false).removeClass('loading').text('Start Full Sync');
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading').text('Start Full Sync');
            }
        });
    }
    
    /**
     * Start background sync
     */
    function startBackgroundSync() {
        const $btn = $('#start-sync-btn');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_start_background_sync',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || 'Background sync started successfully');
                    $btn.prop('disabled', false).removeClass('loading').text('Start Full Sync');
                    // Reload page after a moment to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotice('error', response.data.message || 'Background sync failed to start');
                    $btn.prop('disabled', false).removeClass('loading').text('Start Full Sync');
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading').text('Start Full Sync');
            }
        });
    }
    
    /**
     * Check background sync status
     */
    function checkBackgroundSyncStatus() {
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_get_background_status',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    const status = response.data.status;
                    const progress = response.data.progress;
                    
                    // Update UI based on status
                    if (status.is_running || status.is_queued || status.is_processing) {
                        // Sync is active - update progress display
                        if (progress) {
                            // Update progress display with current statistics
                            updateProgressDisplay({
                                is_running: true,
                                stage: progress.stage || 'processing',
                                current: progress.current || 0,
                                total: progress.total || 0,
                                percentage: progress.percentage || 0,
                                synced: progress.synced || 0,
                                skipped: progress.skipped || 0,
                                failed: progress.failed || 0,
                                pending: progress.pending || 0
                            });
                        }
                    } else if (status.is_paused) {
                        // Sync is paused - show paused status
                        if (progress) {
                            updateProgressDisplay({
                                is_running: false,
                                stage: 'paused',
                                current: progress.current || 0,
                                total: progress.total || 0,
                                percentage: progress.percentage || 0,
                                synced: progress.synced || 0,
                                skipped: progress.skipped || 0,
                                failed: progress.failed || 0,
                                pending: progress.pending || 0
                            });
                        }
                    } else {
                        // Sync completed or not running
                        if (progress && progress.total > 0 && progress.pending === 0) {
                            // Sync completed - update final progress and reload after a moment
                            updateProgressDisplay({
                                is_running: false,
                                stage: 'complete',
                                current: progress.total,
                                total: progress.total,
                                percentage: 100,
                                synced: progress.synced || 0,
                                skipped: progress.skipped || 0,
                                failed: progress.failed || 0,
                                pending: 0
                            });
                            
                            // Clear the interval since sync is complete
                            if (window.planetSyncBackgroundInterval) {
                                clearInterval(window.planetSyncBackgroundInterval);
                                window.planetSyncBackgroundInterval = null;
                            }
                            
                            // Remove auto-refresh indicator
                            removeAutoRefreshIndicator();
                            
                            // Reload page after 3 seconds to show final results
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        } else if (!status.is_active && progress && progress.total === 0) {
                            // No sync in progress - clear progress display
                            $('.sync-progress-container').fadeOut();
                            
                            // Clear the interval if no sync is active
                            if (window.planetSyncBackgroundInterval) {
                                clearInterval(window.planetSyncBackgroundInterval);
                                window.planetSyncBackgroundInterval = null;
                            }
                            
                            // Remove auto-refresh indicator
                            removeAutoRefreshIndicator();
                        }
                    }
                }
            },
            error: function(xhr, status, error) {
                // Silently fail - don't show errors for background status checks
                console.error('Background sync status check failed:', error);
            }
        });
    }
    
    /**
     * Pause background sync
     */
    function pauseBackgroundSync() {
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_pause_background_sync',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || 'Background sync paused');
                    location.reload();
                } else {
                    showNotice('error', response.data.message || 'Failed to pause background sync');
                }
            }
        });
    }
    
    /**
     * Resume background sync
     */
    function resumeBackgroundSync() {
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_resume_background_sync',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || 'Background sync resumed');
                    location.reload();
                } else {
                    showNotice('error', response.data.message || 'Failed to resume background sync');
                }
            }
        });
    }
    
    /**
     * Cancel background sync
     */
    function cancelBackgroundSync() {
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_cancel_background_sync',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || 'Background sync cancelled');
                    location.reload();
                } else {
                    showNotice('error', response.data.message || 'Failed to cancel background sync');
                }
            }
        });
    }
    
    /**
     * Manually trigger background sync processing
     */
    function triggerBackgroundSync() {
        const $btn = $('#trigger-background-sync-btn, #trigger-background-sync-btn-always');
        const originalText = $btn.first().text();
        
        $btn.prop('disabled', true).addClass('loading');
        $btn.text('Triggering...');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_trigger_background_sync',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || 'Background sync triggered successfully');
                    $btn.text('Triggered!');
                    
                    // Reload page after 2 seconds to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotice('error', response.data.message || 'Failed to trigger background sync');
                    $btn.prop('disabled', false).removeClass('loading').text(originalText);
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading').text(originalText);
            }
        });
    }
    
    /**
     * Get background sync debug information
     */
    function getBackgroundDebug() {
        const $btn = $('#get-background-debug-btn');
        $btn.prop('disabled', true).addClass('loading');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_get_background_debug',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    const debug = response.data.debug;
                    const status = response.data.status;
                    let message = 'Debug Info:\n\n';
                    message += 'Queue Items: ' + debug.total_items + '\n';
                    message += 'Batches: ' + debug.batch_count + '\n';
                    message += 'Is Queued: ' + (debug.is_queued ? 'Yes' : 'No') + '\n';
                    message += 'Is Processing: ' + (debug.is_processing ? 'Yes' : 'No') + '\n';
                    message += 'Is Paused: ' + (debug.is_paused ? 'Yes' : 'No') + '\n';
                    message += 'Next Cron: ' + (debug.next_cron_formatted || 'Not scheduled') + '\n';
                    message += 'Cron Disabled: ' + (debug.cron_disabled ? 'Yes' : 'No') + '\n';
                    message += 'WC Background Process Loaded: ' + (response.data.wc_loaded ? 'Yes' : 'No') + '\n';
                    
                    alert(message);
                } else {
                    showNotice('error', response.data.message || 'Failed to get debug info');
                }
                $btn.prop('disabled', false).removeClass('loading');
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading');
            }
        });
    }
    
    /**
     * Process next product in queue (real-time)
     */
    function processNextProduct() {
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_process_next',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update progress
                    if (data.current && data.total) {
                        updateProgressDisplay({
                            is_running: true,
                            stage: 'processing',
                            current: data.current,
                            total: data.total,
                            percentage: Math.round((data.current / data.total) * 100)
                        });
                    }
                    
                    // Display log for this product
                    if (data.message) {
                        displayRealtimeLog(data);
                    }
                    
                    // Check if complete
                    if (data.complete) {
                        onSyncComplete();
                    } else {
                        // Process next product after 10 seconds (rate limiting)
                        setTimeout(function() {
                            processNextProduct();
                        }, 10000);
                    }
                } else {
                    showNotice('error', 'Failed to process product');
                    onSyncComplete();
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                onSyncComplete();
            }
        });
    }
    
    /**
     * Display real-time log entry
     */
    function displayRealtimeLog(data) {
        const $logsTable = $('.planet-logs-table tbody');
        
        if ($logsTable.length === 0) {
            return;
        }
        
        // Determine badge classes
        const resultClass = 'log-action-' + data.result;
        const typeClass = 'log-type-product';
        
        // Create log row
        const timestamp = new Date().toLocaleString();
        const $row = $('<tr>').html(`
            <td><span class="log-badge ${typeClass}">product</span></td>
            <td><span class="log-badge ${resultClass}">${data.result}</span></td>
            <td>${data.slug || ''}</td>
            <td>${data.message}</td>
            <td>${timestamp}</td>
        `);
        
        // Add to top of table with highlight animation
        $row.css('background-color', '#ffffcc');
        $logsTable.prepend($row);
        
        // Fade to normal after 2 seconds
        setTimeout(function() {
            $row.animate({ backgroundColor: '#ffffff' }, 1000);
        }, 2000);
        
        // Keep only last 50 rows
        $logsTable.find('tr:gt(49)').remove();
        
        // Scroll to top of logs
        $('.planet-logs-table').get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        
        // Update statistics display if available
        if (data.statistics) {
            updateStatisticsDisplay(data.statistics);
        }
    }
    
    /**
     * On sync complete
     */
    function onSyncComplete() {
        const $btn = $('#start-sync-btn');
        $btn.prop('disabled', false).removeClass('loading').text('Start Full Sync');
        
        showNotice('success', 'Sync completed! Reloading page...');
        
        // Reload page after 3 seconds
        setTimeout(function() {
            location.reload();
        }, 3000);
    }
    
    /**
     * Test API connection
     */
    function testConnection() {
        const $btn = $('#test-connection-btn');
        
        $btn.prop('disabled', true).addClass('loading');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_test_connection',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message + ' (' + response.data.categories_count + ' categories found)');
                } else {
                    showNotice('error', response.data.message || 'Connection failed');
                }
                $btn.prop('disabled', false).removeClass('loading');
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading');
            }
        });
    }
    
    /**
     * Clear logs
     */
    function clearLogs() {
        const $btn = $('#clear-logs-btn');
        
        $btn.prop('disabled', true).addClass('loading');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_clear_logs',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || 'Logs cleared');
                    
                    // Reload page after 1 second
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotice('error', 'Failed to clear logs');
                    $btn.prop('disabled', false).removeClass('loading');
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading');
            }
        });
    }
    
    /**
     * Load category comparison table
     */
    function loadCategoryComparison() {
        const $table = $('#category-comparison-table');
        const $createBtn = $('#create-missing-categories-btn');
        
        $table.html('<p style="color: #666; font-style: italic;">Loading category comparison...</p>');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_get_category_comparison',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayCategoryComparison(response.data);
                    
                    // Show create button if there are missing categories
                    if (response.data.summary.missing > 0) {
                        $createBtn.show();
                    } else {
                        $createBtn.hide();
                    }
                } else {
                    $table.html('<div class="planet-notice notice-error"><p>' + (response.data.message || 'Failed to load comparison') + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $table.html('<div class="planet-notice notice-error"><p>AJAX error: ' + error + '</p></div>');
            }
        });
    }
    
    /**
     * Display category comparison table
     */
    function displayCategoryComparison(data) {
        const $table = $('#category-comparison-table');
        const summary = data.summary;
        const comparison = data.comparison;
        
        // Build summary
        let html = '<div class="category-comparison-summary">';
        html += '<div class="summary-stats">';
        html += '<div class="stat-item"><span class="stat-label">Total Planet:</span> <span class="stat-value">' + summary.total_planet + '</span></div>';
        html += '<div class="stat-item stat-matched"><span class="stat-label">Matched:</span> <span class="stat-value">' + summary.matched + '</span></div>';
        html += '<div class="stat-item stat-mismatch"><span class="stat-label">Mismatch:</span> <span class="stat-value">' + summary.mismatch + '</span></div>';
        html += '<div class="stat-item stat-missing"><span class="stat-label">Missing:</span> <span class="stat-value">' + summary.missing + '</span></div>';
        html += '</div>';
        html += '</div>';
        
        // Build comparison table
        html += '<div class="category-comparison-table-wrapper">';
        html += '<table class="wp-list-table widefat fixed striped">';
        html += '<thead>';
        html += '<tr>';
        html += '<th>Status</th>';
        html += '<th>Planet ID</th>';
        html += '<th>Planet Category</th>';
        html += '<th>WooCommerce ID</th>';
        html += '<th>WooCommerce Category</th>';
        html += '<th>Products</th>';
        html += '</tr>';
        html += '</thead>';
        html += '<tbody>';
        
        if (comparison.length === 0) {
            html += '<tr><td colspan="6" style="text-align:center;">No categories found</td></tr>';
        } else {
            comparison.forEach(function(item) {
                const statusClass = 'status-' + item.status;
                let statusIcon = '';
                let statusText = '';
                
                switch(item.status) {
                    case 'matched':
                        statusIcon = '✓';
                        statusText = 'Matched';
                        break;
                    case 'mismatch':
                        statusIcon = '⚠';
                        statusText = 'Mismatch';
                        break;
                    case 'missing':
                        statusIcon = '✗';
                        statusText = 'Missing';
                        break;
                }
                
                html += '<tr class="' + statusClass + '">';
                html += '<td><span class="status-badge ' + statusClass + '">' + statusIcon + ' ' + statusText + '</span></td>';
                html += '<td>' + item.planet_id + '</td>';
                html += '<td><strong>' + item.planet_name + '</strong></td>';
                html += '<td>' + item.woo_id + '</td>';
                html += '<td>' + item.woo_name + '</td>';
                html += '<td>' + (item.woo_count || 0) + '</td>';
                html += '</tr>';
            });
        }
        
        html += '</tbody>';
        html += '</table>';
        html += '</div>';
        
        $table.html(html);
    }
    
    /**
     * Create missing categories
     */
    function createMissingCategories() {
        const $btn = $('#create-missing-categories-btn');
        const $table = $('#category-comparison-table');
        
        $btn.prop('disabled', true).addClass('loading');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_create_missing_categories',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message);
                    
                    // Reload comparison after 1 second
                    setTimeout(function() {
                        loadCategoryComparison();
                    }, 1000);
                } else {
                    showNotice('error', 'Failed to create categories');
                }
                $btn.prop('disabled', false).removeClass('loading');
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading');
            }
        });
    }
    
    /**
     * Note: Continuous polling removed to reduce server load.
     * Progress is now updated directly from process_next_product response.
     */
    
    /**
     * Update progress display
     */
    function updateProgressDisplay(progress) {
        // Check if progress section exists
        if ($('.sync-progress-container').length === 0) {
            // Create progress section if it doesn't exist
            const progressHtml = `
                <div class="planet-sync-card">
                    <h2>Sync Progress</h2>
                    <div class="sync-progress-container">
                        <div class="progress-info">
                            <span class="stage"></span>
                            <span class="counts"></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%"></div>
                        </div>
                        <div class="progress-percentage">0%</div>
                        <div class="sync-statistics"></div>
                    </div>
                </div>
            `;
            $('.planet-sync-container').prepend(progressHtml);
        }
        
        // Update progress values
        $('.progress-info .stage').text(progress.stage.charAt(0).toUpperCase() + progress.stage.slice(1));
        $('.progress-info .counts').text(progress.current + ' / ' + progress.total);
        $('.progress-fill').css('width', progress.percentage + '%');
        $('.progress-percentage').text(progress.percentage + '%');
        
        // Update statistics if available
        if (progress.synced !== undefined) {
            const stats = {
                total: progress.total,
                synced: progress.synced || 0,
                skipped: progress.skipped || 0,
                failed: progress.failed || 0,
                pending: progress.pending || 0
            };
            updateStatisticsDisplay(stats);
        }
    }
    
    /**
     * Update statistics display
     */
    function updateStatisticsDisplay(stats) {
        // Check if statistics section exists
        if ($('.sync-statistics').length === 0) {
            const statsHtml = `
                <div class="sync-statistics">
                    <div class="stat-item">
                        <span class="stat-label">Total:</span>
                        <span class="stat-value" id="stat-total">0</span>
                    </div>
                    <div class="stat-item stat-success">
                        <span class="stat-label">Synced:</span>
                        <span class="stat-value" id="stat-synced">0</span>
                    </div>
                    <div class="stat-item stat-warning">
                        <span class="stat-label">Skipped:</span>
                        <span class="stat-value" id="stat-skipped">0</span>
                    </div>
                    <div class="stat-item stat-error">
                        <span class="stat-label">Failed:</span>
                        <span class="stat-value" id="stat-failed">0</span>
                    </div>
                    <div class="stat-item stat-info">
                        <span class="stat-label">Pending:</span>
                        <span class="stat-value" id="stat-pending">0</span>
                    </div>
                </div>
            `;
            $('.sync-progress-container').append(statsHtml);
        }
        
        // Update values
        $('#stat-total').text(stats.total || 0);
        $('#stat-synced').text(stats.synced || 0);
        $('#stat-skipped').text(stats.skipped || 0);
        $('#stat-failed').text(stats.failed || 0);
        $('#stat-pending').text(stats.pending || 0);
        
        // Show retry button if there are failed products
        if (stats.failed > 0 && stats.pending === 0) {
            $('#retry-failed-btn').show();
        } else {
            $('#retry-failed-btn').hide();
        }
    }
    
    /**
     * Check sync status on page load (one-time check, no polling)
     */
    function checkSyncStatus() {
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_get_progress',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success && response.data.is_running) {
                    updateProgressDisplay(response.data);
                    
                    // If sync is running, resume processing
                    // This handles the case where user refreshed the page during sync
                    showNotice('info', 'Sync in progress, resuming...');
                    setTimeout(function() {
                        processNextProduct();
                    }, 2000);
                }
            }
        });
    }
    
    /**
     * Retry failed products
     */
    function retryFailedProducts() {
        const $btn = $('#retry-failed-btn');
        
        $btn.prop('disabled', true).addClass('loading');
        
        $.ajax({
            url: planetSync.ajaxUrl,
            method: 'POST',
            data: {
                action: 'planet_retry_failed',
                nonce: planetSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message);
                    
                    // Reload page after 2 seconds
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotice('error', response.data.message || 'Failed to retry products');
                    $btn.prop('disabled', false).removeClass('loading');
                }
            },
            error: function(xhr, status, error) {
                showNotice('error', 'AJAX error: ' + error);
                $btn.prop('disabled', false).removeClass('loading');
            }
        });
    }
    
    /**
     * Add auto-refresh indicator
     */
    function addAutoRefreshIndicator() {
        // Check if indicator already exists
        if ($('.auto-refresh-indicator').length > 0) {
            return;
        }
        
        const $indicator = $('<div class="auto-refresh-indicator" style="position: fixed; bottom: 20px; right: 20px; background: #2271b1; color: white; padding: 10px 15px; border-radius: 4px; font-size: 12px; z-index: 10000; box-shadow: 0 2px 5px rgba(0,0,0,0.2);"><span class="refresh-icon" style="display: inline-block; margin-right: 5px;">⟳</span>Auto-refresh active</div>');
        $('body').append($indicator);
        
        // Animate refresh icon
        setInterval(function() {
            $indicator.find('.refresh-icon').css('transform', 'rotate(360deg)');
            setTimeout(function() {
                $indicator.find('.refresh-icon').css('transform', 'rotate(0deg)');
            }, 500);
        }, 5000);
    }
    
    /**
     * Remove auto-refresh indicator
     */
    function removeAutoRefreshIndicator() {
        $('.auto-refresh-indicator').fadeOut(function() {
            $(this).remove();
        });
    }
    
    /**
     * Show notification
     */
    function showNotice(type, message) {
        const noticeClass = 'notice-' + type;
        const $notice = $('<div class="planet-notice ' + noticeClass + '"><p>' + message + '</p></div>');
        
        // Insert at top of container
        $('.planet-sync-container').prepend($notice);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
})(jQuery);

