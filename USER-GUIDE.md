# Planet Product Sync 2.0 - User Guide

A complete guide to using the Planet Product Sync plugin for WooCommerce.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Initial Setup](#initial-setup)
3. [Understanding the Dashboard](#understanding-the-dashboard)
4. [Running Your First Sync](#running-your-first-sync)
5. [Category Management](#category-management)
6. [Automatic Sync Setup](#automatic-sync-setup)
7. [Monitoring & Logs](#monitoring--logs)
8. [Settings Explained](#settings-explained)
9. [Common Tasks](#common-tasks)
10. [Troubleshooting](#troubleshooting)
11. [Best Practices](#best-practices)

---

## Getting Started

### What is Planet Product Sync?

Planet Product Sync 2.0 is a WooCommerce plugin that automatically syncs products from the Planet.com.tw API to your WooCommerce store. It keeps your product catalog up-to-date with the latest information from Planet's supplier database.

### Key Benefits

- ✅ **Automatic Updates**: Products stay synchronized with Planet's database
- ✅ **Smart Detection**: Only updates products when changes are detected
- ✅ **Category Management**: Automatically creates and manages product categories
- ✅ **Image Handling**: Downloads and manages product images
- ✅ **Comprehensive Logging**: Track every sync operation
- ✅ **Scheduled Syncs**: Set it and forget it with automatic scheduling

---

## Initial Setup

### Step 1: Plugin Installation

1. **Upload the plugin:**
   - Go to WordPress Admin → **Plugins** → **Add New**
   - Click **Upload Plugin**
   - Choose the `planet-product-sync-2.0.zip` file
   - Click **Install Now**

2. **Activate the plugin:**
   - After installation, click **Activate Plugin**
   - You'll see a success message

### Step 2: Access the Plugin

1. In your WordPress admin sidebar, hover over **Products**
2. Click **Planet Sync 2.0**
3. You'll see the main dashboard

### Step 3: Configure API Key

1. Scroll down to the **Settings** section
2. Find the **API Key** field
3. Enter your Planet API key (provided by Planet.com.tw)
4. Click **Save Settings**

### Step 4: Test Connection

1. At the top of the page, click **Test API Connection**
2. Wait a few seconds
3. You should see a success message confirming the connection

✅ **Congratulations!** Your plugin is now ready to use.

---

## Understanding the Dashboard

When you open **Products → Planet Sync 2.0**, you'll see several sections:

### 1. Sync Dashboard

This is your main control panel showing:

- **Last Sync**: When the last sync was completed
- **Products Created**: Number of new products added
- **Products Updated**: Number of existing products updated
- **Products Skipped**: Products that had no changes
- **Errors**: Number of errors encountered
- **Auto Sync**: Whether automatic sync is enabled
- **Sync Frequency**: How often auto sync runs

**Action Buttons:**
- **Start Full Sync**: Manually trigger a complete sync
- **Test API Connection**: Verify API connectivity
- **Clear Logs**: Remove all activity logs

### 2. Sync Progress

When a sync is running, this section shows:
- Progress bar
- Current stage (processing, fetching, etc.)
- Number of products processed
- Percentage complete

### 3. Category Comparison

Shows comparison between Planet categories and your WooCommerce categories:
- Matched categories
- Missing categories
- Mismatch indicators

**Button:**
- **Create Missing Categories**: Automatically create categories that don't exist

### 4. Recent Activity

Displays the last 50 sync operations:
- Type (product, category, system)
- Action (create, update, skip, error)
- Item name or identifier
- Message describing what happened
- Timestamp

### 5. New Products

Shows recently created products with:
- Product name
- Identifier (slug)
- Creation time
- Edit button to view/edit the product

### 6. Settings

Configuration options for the plugin (detailed below)

---

## Running Your First Sync

### Manual Sync Process

Follow these steps for a successful first sync:

#### Step 1: Validate Categories

Before syncing products, ensure categories are ready:

1. Look at the **Category Comparison** section
2. The plugin will automatically fetch Planet categories
3. Review the comparison table:
   - **Green (Matched)**: Category exists in both systems ✅
   - **Yellow (Mismatch)**: Names don't match ⚠️
   - **Red (Missing)**: Category needs to be created ❌

4. If you see missing categories, click **Create Missing Categories**
5. Wait for confirmation that categories were created

#### Step 2: Start the Sync

1. Scroll to the **Sync Dashboard** section
2. Click the **Start Full Sync** button
3. The plugin will begin the 3-step process:

   **Step 1: Validate Categories**
   - Checks all level 1 categories
   - Creates any missing categories
   - Updates category information

   **Step 2: Fetch Product List**
   - Retrieves list of all products from Planet API
   - Stores the list for processing
   - Displays total number of products found

   **Step 3: Process Products**
   - Goes through each product one by one
   - Fetches detailed information
   - Creates or updates the product in WooCommerce
   - Downloads product images
   - Assigns categories

4. **Watch the progress:**
   - The **Sync Progress** section will appear
   - Progress bar shows completion percentage
   - Counter shows products processed
   - Real-time updates appear in **Recent Activity**

5. **Wait for completion:**
   - A full sync can take several hours (depending on product count)
   - **AJAX Method**: Keep browser window open
   - **Background Method**: You can close the browser

#### Step 3: Review Results

After sync completes:

1. Check the **Sync Dashboard** statistics:
   - Note how many products were created
   - Note how many were updated
   - Check if there were any errors

2. Review **Recent Activity**:
   - Look for error messages (red badges)
   - Verify products were processed correctly

3. Check **New Products** section:
   - See list of newly created products
   - Click **Edit** to review individual products

4. Visit your WooCommerce Products page:
   - Go to **Products** → **All Products**
   - Verify products appear correctly
   - Check images, descriptions, and categories

---

## Category Management

### Understanding Categories

The plugin syncs **Level 1 categories** from Planet's system to your WooCommerce product categories. These are the main, top-level categories.

### Automatic Category Creation

When you run a sync, the plugin automatically:
1. Fetches Level 1 categories from Planet API
2. Compares them with your WooCommerce categories
3. Creates any missing categories
4. Updates category descriptions if changed

### Manual Category Validation

To manually check categories:

1. Go to **Planet Sync 2.0** dashboard
2. Look at the **Category Comparison** section
3. Wait for the table to load (fetches from API)
4. Review the comparison:

   | Status | Meaning | Action |
   |--------|---------|--------|
   | Matched | Category exists with same name | None needed |
   | Mismatch | Category exists but name differs | Review manually |
   | Missing | Category doesn't exist | Click "Create Missing" |

5. Click **Create Missing Categories** if needed
6. Wait for success confirmation

### Viewing Categories

To see your product categories:
1. Go to **Products** → **Categories**
2. You'll see all categories including Planet synced ones
3. Planet categories have a `_planet_category_id` meta field

---

## Automatic Sync Setup

Set up automatic syncing so your products stay updated without manual intervention.

### Enable Auto Sync

1. Go to **Planet Sync 2.0** dashboard
2. Scroll to **Settings** section
3. Check the **Enable automatic sync** checkbox
4. Choose your **Sync Frequency**:
   - **Every 1 Minute**: For testing only ⚠️
   - **Every 5 Minutes**: Very frequent updates
   - **Hourly**: Good for active catalogs ⭐ Recommended
   - **Twice Daily**: Moderate update frequency
   - **Daily**: Light update frequency
   - **Weekly**: Minimal update frequency

5. If you chose **Daily**, set the **Daily Sync Time**:
   - Choose a time when site traffic is low (e.g., 2:00 AM)
   - Uses your WordPress timezone setting

6. Choose **Sync Method**:
   - **AJAX**: Real-time with browser open
   - **Background**: Runs without browser ⭐ Recommended for auto sync

7. Click **Save Settings**

### How Auto Sync Works

Once enabled:
- The plugin schedules automatic syncs using WordPress Cron
- Syncs run in the background at the specified frequency
- No browser window needs to be open (Background method)
- Each sync follows the same 3-step process
- Results are logged in **Recent Activity**

### Monitoring Auto Sync

Check if auto sync is working:

1. Look at **Sync Dashboard** → **Auto Sync** box:
   - Shows "Enabled" or "Disabled"
   - Shows next scheduled run time
   - Shows sync method being used

2. Check **Recent Activity** after scheduled time:
   - Look for system logs like "Starting Planet Sync 2.0"
   - Verify products were processed

3. Review **Last Sync** timestamp:
   - Should update after each auto sync
   - Compare with schedule to ensure it ran

### Disable Auto Sync

To turn off automatic syncing:
1. Go to **Settings** section
2. Uncheck **Enable automatic sync**
3. Click **Save Settings**
4. Manual syncs still work normally

---

## Monitoring & Logs

### Recent Activity

The **Recent Activity** section shows real-time sync operations:

#### Log Types

| Badge Color | Type | Description |
|------------|------|-------------|
| Blue | Product | Product-related operations |
| Green | Category | Category operations |
| Orange | System | Plugin system messages |
| Gray | API | API communication logs |

#### Action Types

| Badge | Action | Meaning |
|-------|--------|---------|
| Green | Create | New item was created |
| Blue | Update | Existing item was updated |
| Yellow | Skip | Item was skipped (no changes) |
| Red | Error | An error occurred |
| Gray | Info | Informational message |

#### Reading Logs

Each log entry shows:
- **Type**: What kind of operation
- **Action**: What was done
- **Item**: Product slug or category name
- **Message**: Detailed description
- **Time**: When it happened

**Example log entries:**
```
Product | Create | abc-product-123 | Product created | 2024-01-15 14:23:11
Product | Skip   | xyz-item-456    | Skipped - No change detected | 2024-01-15 14:23:15
Category| Create | Electronics     | Created level 1 category | 2024-01-15 14:20:05
System  | Info   |                 | Starting Planet Sync 2.0 | 2024-01-15 14:20:00
```

### Understanding Statistics

**Products Created**
- Brand new products added to your store
- Check **New Products** section to see them

**Products Updated**
- Existing products that had changes
- Changes detected by MD5 hash comparison
- Could be name, description, images, etc.

**Products Skipped**
- Products that exist but had no changes
- This is normal and efficient!
- Saves processing time

**Errors**
- Problems during sync
- Check logs for red "error" badges
- Common causes: API timeout, network issues, invalid data

### Clearing Logs

To remove old logs:
1. Click **Clear Logs** button
2. Confirm the action
3. All logs are permanently deleted
4. Statistics are reset
5. Products remain unaffected

**Note:** Clearing logs doesn't affect your products, only the activity history.

---

## Settings Explained

### API Key
**What it is:** Your authentication key for Planet.com.tw API  
**How to get it:** Contact Planet.com.tw support  
**Required:** Yes ✅

### Auto Sync
**What it is:** Enable automatic scheduled syncing  
**Default:** Disabled  
**Recommended:** Enable for production sites ⭐

### Sync Frequency
**What it is:** How often auto sync runs  
**Options:**
- Every 1 Minute (testing only)
- Every 5 Minutes
- Hourly ⭐ Recommended
- Twice Daily
- Daily
- Weekly

**Recommendation:** Start with "Hourly" for active catalogs, adjust based on your needs

### Daily Sync Time
**What it is:** Time of day for daily syncs (if frequency is "Daily")  
**Format:** HH:MM (24-hour format)  
**Default:** 02:00 (2:00 AM)  
**Recommendation:** Choose a time when your site has low traffic

### Sync Method
**What it is:** How sync processes run  
**Options:**
- **AJAX**: Real-time updates, browser must stay open
- **Background**: Runs via WordPress Cron, no browser needed ⭐

**When to use AJAX:**
- Manual syncs
- Testing
- When you want to watch real-time progress

**When to use Background:**
- Automatic scheduled syncs ⭐ Recommended
- Large product catalogs
- Long-running syncs

### Debug Mode
**What it is:** Detailed logging to PHP error log  
**Default:** Disabled  
**When to enable:**
- Troubleshooting errors
- Support requests
- Development

**Note:** Creates large log files, disable after troubleshooting

---

## Common Tasks

### Task 1: Add New Products from Planet

**Scenario:** New products added to Planet's system

**Steps:**
1. Click **Start Full Sync**
2. Wait for sync to complete
3. Check **New Products** section
4. Review and publish new products if needed

### Task 2: Update Existing Products

**Scenario:** Product information changed on Planet's side

**Steps:**
1. Run a sync (manual or wait for auto sync)
2. Plugin automatically detects changes using MD5 hash
3. Only changed products are updated
4. Check **Recent Activity** for "Update" actions

### Task 3: Add New Categories

**Scenario:** Planet added new product categories

**Steps:**
1. Go to **Category Comparison** section
2. Wait for table to load
3. New categories will show as "Missing" (red)
4. Click **Create Missing Categories**
5. Confirm categories were created

### Task 4: Check Sync Status

**Scenario:** You want to know when last sync ran

**Steps:**
1. Look at **Sync Dashboard**
2. Check **Last Sync** timestamp
3. Review statistics for that sync
4. Check **Auto Sync** box for next scheduled run

### Task 5: Fix Failed Sync

**Scenario:** Sync had errors

**Steps:**
1. Check **Recent Activity** for red "Error" badges
2. Read error messages
3. Common fixes:
   - **API timeout**: Wait and try again
   - **Network error**: Check internet connection
   - **Invalid data**: Contact Planet support
4. Enable **Debug Mode** in Settings for more details
5. Click **Start Full Sync** to retry

### Task 6: Verify Product Images

**Scenario:** Check if images downloaded correctly

**Steps:**
1. Go to **Products** → **All Products**
2. Look at product thumbnails
3. If images are missing:
   - Check Recent Activity for image download errors
   - Verify image URLs in Planet API
   - Re-run sync to retry downloads

### Task 7: Review New Products

**Scenario:** Check products created in last sync

**Steps:**
1. Go to **New Products** section
2. See list of recently created products
3. Click **Edit** on any product to review:
   - Title and description
   - Images
   - Categories
   - Custom fields (Applications, Key Features, Specifications)
4. Make any manual adjustments needed
5. Publish or keep as draft

---

## Troubleshooting

### Problem: "Failed to connect to API"

**Possible Causes:**
- Invalid API key
- Network/firewall issues
- Planet API is down

**Solutions:**
1. Verify API key in Settings
2. Click **Test API Connection**
3. Check your hosting firewall settings
4. Contact Planet support to verify API status
5. Check WordPress error logs

### Problem: "No products found"

**Possible Causes:**
- No products in Planet system
- API returning empty list
- API key doesn't have product access

**Solutions:**
1. Verify products exist in Planet system
2. Test API connection
3. Check API key permissions with Planet
4. Review Recent Activity logs for errors

### Problem: Products not updating

**Possible Causes:**
- No actual changes in Planet data
- MD5 hash preventing unnecessary updates
- Sync skipping products

**Solutions:**
1. This is normal if data hasn't changed
2. Check Recent Activity for "Skip" messages
3. Product data is identical = Skip (this is efficient!)
4. If you need to force update, clear product hash meta

### Problem: Images not downloading

**Possible Causes:**
- Invalid image URLs
- Hosting blocks external downloads
- File permissions issue

**Solutions:**
1. Check Recent Activity for download errors
2. Verify image URLs work in browser
3. Check WordPress media upload permissions
4. Contact hosting about external URL access
5. Check `wp-content/uploads/` folder permissions

### Problem: Sync takes too long

**Possible Causes:**
- Large number of products
- Slow API responses
- AJAX method timing out

**Solutions:**
1. Switch to **Background** sync method
2. Use auto sync instead of manual
3. Increase PHP max_execution_time
4. Be patient - large catalogs take hours
5. Check Recent Activity to verify it's progressing

### Problem: Categories not creating

**Possible Causes:**
- Permission issues
- Duplicate category names
- Database error

**Solutions:**
1. Check WordPress user has admin permissions
2. Review Recent Activity for category errors
3. Manually check **Products** → **Categories**
4. Try creating a test category manually
5. Check database connection

### Problem: Automatic sync not running

**Possible Causes:**
- WordPress Cron not working
- Auto sync disabled
- Hosting disabled cron

**Solutions:**
1. Verify **Auto Sync** is checked in Settings
2. Check if WordPress Cron is functioning:
   - Install "WP Crontrol" plugin
   - Check for "planet_auto_sync" event
3. Contact hosting about cron jobs
4. Try switching to AJAX method temporarily

### Problem: "Out of memory" errors

**Possible Causes:**
- PHP memory limit too low
- Processing too many products at once

**Solutions:**
1. Increase PHP memory limit in wp-config.php:
   ```php
   define('WP_MEMORY_LIMIT', '256M');
   ```
2. Contact hosting to increase limits
3. Use Background sync method
4. Process products in smaller batches

---

## Best Practices

### 1. Regular Syncing

**Recommendation:** Enable auto sync with hourly frequency

**Why:** Keeps products up-to-date without manual intervention

**Setup:**
- Enable auto sync in Settings
- Choose "Hourly" frequency
- Select "Background" method
- Save and forget!

### 2. Monitor Logs

**Recommendation:** Check Recent Activity weekly

**Why:** Catch errors early before they cause issues

**What to check:**
- Number of errors in last week
- Products being created/updated as expected
- No repeated error patterns

### 3. Backup Before Major Changes

**Recommendation:** Backup database before first sync or major updates

**Why:** Safety net in case something goes wrong

**How:**
- Use WordPress backup plugin
- Or hosting's backup feature
- Or manual database export

### 4. Test in Staging First

**Recommendation:** If possible, test on staging site

**Why:** See results before affecting live site

**Process:**
1. Set up plugin on staging
2. Run full sync
3. Review results
4. Then deploy to production

### 5. Start with Manual Sync

**Recommendation:** Run first sync manually before enabling auto sync

**Why:** See how it works and verify setup

**Steps:**
1. Install and configure plugin
2. Run manual sync with **Start Full Sync**
3. Review results and fix any issues
4. Then enable auto sync

### 6. Use Background Method for Auto Sync

**Recommendation:** Always use Background method for automatic syncs

**Why:** 
- Doesn't require browser to be open
- More reliable for long syncs
- Better for scheduled operations

**When to use AJAX:**
- Only for manual syncs when you want real-time feedback

### 7. Set Realistic Sync Times

**Recommendation:** For daily sync, choose low-traffic time

**Why:** Avoid performance impact during peak hours

**Best times:**
- 2:00 AM - 5:00 AM (default)
- After midnight
- Early morning

### 8. Keep API Key Secure

**Recommendation:** Don't share your API key

**Why:** Protects your Planet account

**Security tips:**
- Don't commit to version control
- Don't share in screenshots
- Rotate periodically

### 9. Review New Products

**Recommendation:** Periodically check New Products section

**Why:** Ensure products are created correctly

**What to verify:**
- Titles are correct
- Descriptions are complete
- Images loaded properly
- Categories assigned correctly
- Custom fields populated

### 10. Enable Debug Mode Only When Needed

**Recommendation:** Keep debug mode OFF normally

**Why:** Creates large log files, impacts performance

**When to enable:**
- Troubleshooting specific errors
- Support requested it
- Development/testing

**Remember to disable after troubleshooting!**

---

## Quick Reference

### Dashboard URL
`/wp-admin/admin.php?page=planet-sync-2`

### Key Actions
- **Start Sync**: Click "Start Full Sync" button
- **Test Connection**: Click "Test API Connection" button
- **Create Categories**: Click "Create Missing Categories" button
- **Clear Logs**: Click "Clear Logs" button

### Recommended Settings for Production
```
✅ Auto Sync: Enabled
✅ Sync Frequency: Hourly
✅ Sync Method: Background
✅ Debug Mode: Disabled
```

### Support Checklist
When contacting support, provide:
- [ ] Recent Activity logs (screenshot or export)
- [ ] Error messages (exact text)
- [ ] Settings configuration
- [ ] Last successful sync time
- [ ] Steps you tried

---

## Getting Help

### Documentation
- **README.md** - Technical documentation
- **USER-GUIDE.md** - This guide
- **REALTIME-SYNC.md** - Real-time sync details
- **CATEGORY-COMPARISON.md** - Category sync information

### Support Contacts
- **Author**: H M Shahadul Islam
- **Email**: shahadul.islam1@gmail.com
- **GitHub**: https://github.com/shahadul878
- **Company**: Codereyes

### Before Contacting Support

1. **Check Recent Activity** for error messages
2. **Enable Debug Mode** and reproduce the issue
3. **Take screenshots** of the problem
4. **Note the exact steps** that caused the issue
5. **Check this guide** for troubleshooting steps

---

## Glossary

**API** - Application Programming Interface, how the plugin communicates with Planet

**Sync** - Synchronization, the process of updating local products with remote data

**AJAX** - Real-time sync method requiring browser to stay open

**Background** - Sync method that runs via WordPress Cron without browser

**Cron** - Scheduled task system in WordPress

**MD5 Hash** - Unique fingerprint of product data used to detect changes

**Slug** - URL-friendly product identifier (e.g., "product-name-123")

**SKU** - Stock Keeping Unit, product identifier code

**Meta** - Additional data stored with products/categories

**Transient** - Temporary cached data in WordPress

**WP-Cron** - WordPress built-in task scheduler

---

**Thank you for using Planet Product Sync 2.0!** 🚀

If you have questions or suggestions, please don't hesitate to reach out.

Happy syncing!

