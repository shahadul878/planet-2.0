# Planet Product Sync 2.0

Advanced product synchronization plugin for WooCommerce that syncs products from Planet.com.tw API using a streamlined 3-step workflow with MD5 hash detection for efficient change tracking.

## Features

- **3-Step Sync Workflow**: Validate categories, fetch product list, process products
- **MD5 Hash Detection**: Only update products when actual changes are detected
- **Level 1 Category Support**: Automatically validates and creates level 1 categories
- **Smart Duplicate Detection**: Checks by slug, SKU, and product code before creating
- **Progress Tracking**: Real-time sync progress with visual feedback
- **Comprehensive Logging**: Detailed activity logs for debugging and monitoring
- **Modern Admin Interface**: Clean, responsive dashboard with statistics
- **API Rate Limiting**: Built-in delays to respect API rate limits
- **Image Management**: Downloads and caches product images locally

## Installation

1. Upload the `planet-product-sync-2.0` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Products > Planet Sync 2.0** in the admin menu
4. Configure your API key and settings

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## How It Works

### Step 1: Category Validation
- Fetches level 1 categories from Planet API (`/getProduct1stCategoryList`)
- Compares with existing WooCommerce product categories
- Creates missing categories automatically
- Stores Planet category IDs as term meta for reference

### Step 2: Product List Fetch
- Retrieves all products from Planet API (`/getProductList`)
- Stores product list temporarily in WordPress options table
- Records sync start timestamp

### Step 3: Product Processing
- Loops through each product in the list
- Fetches full product details (`/getProductBySlug?slug=...`)
- Generates MD5 hash of product data
- Compares with stored hash to detect changes
- Creates new products or updates existing ones
- Downloads and assigns product images
- Assigns products to appropriate categories
- Implements 2-second delay between API calls

## Admin Interface

### Dashboard Section
- Last sync timestamp
- Products created/updated/skipped counts
- Error tracking
- Quick action buttons

### Category Validation
- Validate level 1 categories
- View matched/missing categories
- Create missing categories with one click

### Sync Progress
- Real-time progress bar
- Current stage indicator
- Product processing counter

### Activity Logs
- Type-based filtering (product, category, system, api)
- Action-based filtering (create, update, skip, error)
- Timestamp tracking
- Searchable logs

### Settings
- API Key configuration
- Auto-sync enable/disable
- Sync frequency (hourly/daily)
- Debug mode toggle

## API Endpoints Used

1. **getProduct1stCategoryList** - Fetch level 1 categories
2. **getProductList** - Fetch basic product list
3. **getProductBySlug** - Fetch full product details by slug

## API Field Mapping

### Product Fields

| Planet API Field | WooCommerce Field | Post Meta Key |
|-----------------|-------------------|---------------|
| `id` | - | `_planet_id` |
| `name` | - | `product_code` |
| `slug` | Product Slug (post_name) | `_planet_slug` |
| `desc` | Product Title | - |
| `image` | Featured Image | - |
| `gallery` | Product Gallery | - |
| `overview` | Product Description | - |
| `applications` | - | `applications_tab` |
| `keyfeatures` | - | `key_features_tab` |
| `specifications` | - | `specifications_tab` |

## Data Storage

### Options Table
- `planet_sync_api_key` - API authentication key
- `planet_sync_auto_sync` - Auto-sync enabled/disabled
- `planet_sync_frequency` - Sync frequency setting
- `planet_sync_daily_time` - Preferred start time for daily sync (HH:MM)
- `planet_sync_debug_mode` - Debug mode enabled/disabled
- `planet_temp_product_list` - Temporary product list during sync
- `planet_sync_progress` - Current sync progress
- `_last_planet_sync` - Last successful sync timestamp

### Post Meta (Products)
- `_planet_product_hash` - MD5 hash for change detection
- `_planet_id` - Original Planet product ID
- `product_code` - Original Planet product code (from 'name' field)
- `_planet_slug` - Original Planet product slug
- `_planet_category_ids` - JSON array of Planet category IDs
- `applications_tab` - Applications content
- `key_features_tab` - Key features content
- `specifications_tab` - Specifications content

### Term Meta (Categories)
- `_planet_category_id` - Original Planet category ID

### Custom Tables
- `wp_planet_sync_log` - Activity logs and statistics

## Usage

### Manual Sync
1. Go to **Products > Planet Sync 2.0**
2. Click **Start Full Sync** button
3. Monitor progress in real-time
4. Review results in activity logs

### Automatic Sync
1. Enable **Auto Sync** in settings
2. Choose sync frequency (hourly/daily) and set the daily start time
3. Save settings
4. Plugin will automatically sync on schedule

### Category Validation
1. Click **Validate Categories** button
2. Review validation results
3. Missing categories are created automatically

## AJAX Actions

- `planet_validate_categories` - Validate level 1 categories
- `planet_start_sync` - Start full sync process
- `planet_get_progress` - Get current sync progress
- `planet_clear_logs` - Clear all activity logs
- `planet_test_connection` - Test API connectivity

## Hooks & Filters

The plugin triggers the following action:
- `planet_auto_sync` - Fired during scheduled automatic sync

## Uninstallation

When the plugin is uninstalled, it automatically:
- Removes all plugin options
- Drops custom database tables
- Clears API cache transients
- Removes planet-specific post/term meta
- Cancels scheduled cron events

## Developer Notes

### File Structure
```
planet-product-sync-2.0/
├── planet-product-sync.php      # Main plugin file
├── uninstall.php                # Cleanup on uninstall
├── includes/
│   ├── class-database.php       # Database table management
│   ├── class-logger.php         # Logging functionality
│   ├── class-api.php            # API communication
│   ├── class-sync-engine.php    # Core sync logic
│   └── class-admin.php          # Admin interface
└── assets/
    ├── css/admin.css            # Admin styles
    └── js/admin.js              # Admin scripts
```

### MD5 Hash Logic
The plugin generates an MD5 hash of the entire product data JSON. This hash is stored as post meta and compared on subsequent syncs. If the hash matches, the product is skipped, saving processing time.

### Rate Limiting
A 10-second delay is implemented between product API calls to respect rate limits and prevent server overload.

### Error Handling
All errors are logged to the database with detailed messages. Enable debug mode to also log errors to PHP error log.

## Support

For issues, feature requests, or contributions, please contact:
- Email: shahadul.islam1@gmail.com
- GitHub: https://github.com/shahadul878

## Credits

- Author: H M Shahadul Islam
- Company: Codereyes
- Version: 2.0.0
- License: GPL v2 or later

## Changelog

### Version 2.0.0
- Complete rewrite with 3-step workflow
- Added MD5 hash detection for efficient updates
- Implemented level 1 category validation
- Added real-time progress tracking
- Modern admin interface with statistics
- Comprehensive activity logging
- Smart duplicate detection
- Image caching and management

