# Real-Time One-by-One Product Sync

## Overview

Planet Product Sync 2.0 now processes products **one by one** with **real-time log updates** visible in the admin interface. Each product is fully processed before moving to the next one.

## How It Works

### 3-Step Sync Process

#### Step 1: Initialize Sync
When you click "Start Full Sync", the system:
1. **Validates Level 1 Categories** - Checks and creates missing categories
2. **Fetches Product List** - Retrieves all products from Planet API
3. **Initializes Progress** - Sets up tracking for sequential processing

#### Step 2: Sequential Processing
The system then processes each product individually:
1. Fetch full product details via API
2. Generate MD5 hash of product data
3. Check if product exists in WooCommerce
4. Create new product OR update existing product (only if data changed)
5. Download and assign images
6. Assign categories
7. Store custom tabs (applications, key features, specifications)
8. **Log the result immediately**
9. Wait 10 seconds (API rate limiting)
10. Move to next product

#### Step 3: Completion
After all products are processed:
- Clean up temporary data
- Update last sync timestamp
- Display summary statistics
- Auto-reload page

## Real-Time Features

### Live Log Display
- **Instant Updates**: See each product as it's processed
- **Color-Coded Results**:
  - 🟢 Green = Created
  - 🔵 Blue = Updated
  - 🟡 Yellow = Skipped (no changes)
  - 🔴 Red = Error
- **Animated Entries**: New logs slide in with highlight effect
- **Auto-Scroll**: Table automatically scrolls to show latest entry

### Progress Bar
- **Real-Time Updates**: Progress bar updates after each product
- **Percentage Display**: Shows X/Y products processed
- **Stage Indicator**: Shows current sync stage

### Visual Feedback
- Button states change: "Initializing..." → "Processing..." → "Start Full Sync"
- Highlight animation on new log entries (yellow → white fade)
- Smooth scroll to keep latest logs visible

## AJAX Endpoints

### 1. `planet_start_sync`
Initializes the sync (Steps 1 & 2)
- Validates categories
- Fetches product list
- Returns total product count

### 2. `planet_process_next`
Processes the next product in queue
- Fetches full product details
- Creates or updates product
- Returns result and progress
- Called repeatedly until all products processed

### 3. `planet_get_recent_logs`
Retrieves recent log entries
- Used for refreshing log display
- Returns formatted log array

## Processing Flow

```
User Clicks "Start Full Sync"
         ↓
   Initialize Sync
   (Validate Categories + Fetch List)
         ↓
   Process Product #1
   (API → Create/Update → Log)
         ↓
   Wait 2 seconds
         ↓
   Process Product #2
   (API → Create/Update → Log)
         ↓
   Wait 2 seconds
         ↓
   Process Product #3
   ...continues...
         ↓
   All Products Complete
         ↓
   Show Summary → Reload Page
```

## Benefits

### For Users
1. **Transparency**: See exactly what's happening in real-time
2. **Progress Tracking**: Know how many products remain
3. **Error Detection**: Immediately see if a product fails
4. **No Timeouts**: Each product is a separate AJAX call (no PHP timeouts)
5. **Resumable**: If stopped, can resume from where it left off

### For Administrators
1. **Debugging**: Easy to identify problematic products
2. **Monitoring**: Watch sync progress without waiting for completion
3. **Control**: Can see if specific products are causing issues
4. **Logs**: Detailed logs stored in database for review

## Technical Details

### Rate Limiting
- **10-second delay** between products
- Prevents API overload
- Ensures stable performance
- Allows API server to process requests comfortably

### MD5 Hash Detection
- Only updates products when data actually changes
- Skips unchanged products for efficiency
- Saves processing time and database writes

### Error Handling
- Individual product errors don't stop the entire sync
- Each error is logged with details
- Sync continues to next product
- Summary shows total errors at end

### Memory Management
- Each product processed independently
- No memory buildup from processing large datasets
- Temporary data stored in WordPress options table
- Automatic cleanup after completion

## User Experience

### What You See
1. Click "Start Full Sync"
2. Message: "Sync initialized: X products to process"
3. Progress bar appears showing 0/X
4. Logs start appearing one by one:
   - "Created: Product Name 1"
   - "Updated: Product Name 2"
   - "Skipped: Product Name 3"
   - "Created: Product Name 4"
5. Progress bar updates: 4/X (10%)
6. Process continues until all done
7. Message: "Sync completed! Reloading page..."
8. Page refreshes with updated statistics

### Timing Example
For 100 products:
- Initialize: ~5 seconds
- Processing: 100 products × 10 seconds = ~16.7 minutes
- Total time: ~17 minutes

## Troubleshooting

### Sync Stops Mid-Way
- Progress is saved
- Refresh page
- Click "Start Full Sync" again
- Will resume from last processed product

### Logs Not Updating
- Check browser console for JavaScript errors
- Ensure WordPress AJAX is working
- Verify admin-ajax.php is accessible

### Slow Processing
- Normal: 10 seconds per product (rate limiting)
- API delays may add extra time
- Large images take longer to download
- This slower pace ensures API stability

## Comparison: Old vs New

| Feature | Old Method | New Method |
|---------|-----------|------------|
| Processing | All at once | One by one |
| Log Updates | After completion | Real-time |
| Progress | Polling every 2s | After each product |
| Visibility | Hidden | Fully visible |
| Timeout Risk | High (large batches) | None (individual) |
| Resumable | No | Yes |
| User Feedback | Minimal | Comprehensive |

## Database Impact

### Temporary Storage
- `planet_temp_product_list` - Product queue
- `planet_sync_progress` - Current position
- Both deleted after completion

### Logs
- Each product action logged to `wp_planet_sync_log`
- Logs persist for review
- Can be cleared via "Clear Logs" button

## Performance

### Optimized For
- ✅ Large product catalogs (500+ products)
- ✅ Slow API responses
- ✅ Limited server resources
- ✅ Shared hosting environments

### Resource Usage
- Low memory (one product at a time)
- No PHP timeouts (separate AJAX calls)
- Controlled API rate (10s delay)
- Efficient database queries (indexed logs)

## Best Practices

1. **Monitor First Sync**: Watch the logs to understand your product data
2. **Check Errors**: Review any errors in the logs
3. **Schedule Wisely**: Run during low-traffic periods
4. **Test Changes**: Use "Test API Connection" before full sync
5. **Review Logs**: Check logs after sync for any issues

## Future Enhancements

Potential additions:
- Pause/Resume button during sync
- Adjustable delay between products
- Selective product sync (by category)
- Batch size configuration
- Sync scheduling with specific times
- Email notification on completion
- Export sync report

