# Orphaned Products Management

## Overview

The **Orphaned Products** feature allows you to automatically manage local WooCommerce products that exist in your store but are no longer available in the remote Planet API. This is useful for maintaining a clean and synchronized product catalog.

## What are Orphaned Products?

Orphaned products are products that:
- Exist in your local WooCommerce store
- Were previously synced from the Planet API (have Planet metadata)
- No longer exist in the remote Planet API product list

## How it Works

### Detection Process

During each sync, after fetching the product list from the remote API, the plugin:

1. **Builds a list** of all remote product slugs and IDs
2. **Queries local products** that were synced by this plugin (identified by `_planet_product_hash` meta)
3. **Compares** local products with the remote list
4. **Identifies orphaned products** that don't exist in the remote list
5. **Takes action** based on your configured setting

### Important Notes

- **Only synced products are affected**: The plugin only checks products that were created/updated by the Planet Sync plugin
- **Manual products are safe**: Products created manually in WooCommerce (without Planet metadata) are never touched
- **Safe by default**: The default action is "Keep" (do nothing)

## Configuration

### Settings Location

Navigate to: **Products → Planet Sync 2.0 → Settings**

Look for the **"Orphaned Products Action"** setting.

### Available Actions

| Action | Description | Use Case |
|--------|-------------|----------|
| **Keep (Do Nothing)** | No action taken. Orphaned products remain as-is. | Default safe option. Use when you want to manually review products. |
| **Set to Draft** | Changes orphaned products to "Draft" status. | Good for temporarily hiding products while keeping them for reference. |
| **Move to Trash** | Moves orphaned products to WordPress trash. | Products can be restored within 30 days (WordPress default). |
| **Permanently Delete** | Immediately and permanently deletes orphaned products. | ⚠️ **Use with caution!** This cannot be undone. |

## Monitoring Orphaned Products

### Dashboard Section

The admin dashboard includes an **"Orphaned Products"** section that shows:
- Current action setting
- List of recently processed orphaned products
- Action taken on each product
- Timestamp of when the action was performed

### Logs

All orphaned product actions are logged in the **Recent Activity** section with:
- Product identifier (slug or ID)
- Action performed
- Descriptive message
- Timestamp

Example log messages:
- `Set to draft (orphaned): Product Name`
- `Moved to trash (orphaned): Product Name`
- `Permanently deleted (orphaned): Product Name`

## Workflow Example

### Scenario: Remote Product Discontinued

1. **Initial State**: Product "ABC-123" exists in both remote API and local store
2. **Remote Change**: Supplier discontinues "ABC-123" and removes it from their API
3. **Next Sync**: 
   - Plugin fetches remote product list (no longer includes "ABC-123")
   - Plugin detects "ABC-123" as orphaned
   - Plugin takes action based on setting:
     - **Keep**: Product remains published in your store
     - **Draft**: Product status changes to Draft
     - **Trash**: Product moved to trash
     - **Delete**: Product permanently removed

## Best Practices

### Recommended Settings by Use Case

#### E-commerce Store (Active Sales)
- **Action**: `Set to Draft` or `Move to Trash`
- **Reason**: Prevents customers from ordering discontinued products while preserving data

#### Product Catalog (Information Only)
- **Action**: `Keep (Do Nothing)`
- **Reason**: Historical products may still be valuable for reference

#### High-Volume Store (Fresh Catalog)
- **Action**: `Permanently Delete`
- **Reason**: Keeps database clean and reduces clutter
- **⚠️ Warning**: Make sure you have backups!

### Testing Recommendations

1. **Start with "Keep"**: Begin with the safe default
2. **Monitor logs**: Review which products would be affected
3. **Test with "Draft"**: Try the draft option first to see the impact
4. **Backup first**: Always backup your database before using "Delete"

## Technical Details

### Database Queries

The feature uses these checks to identify synced products:
- Products with `_planet_product_hash` meta (required)
- Products with `_planet_slug` meta (for matching)
- Products with `_planet_id` meta (for matching)

### Performance

- Runs during the "Fetch Product List" phase (Step 2 of sync)
- Executes a single optimized database query
- Minimal impact on sync performance
- Only processes products that were synced by this plugin

### SQL Query Example

```sql
SELECT DISTINCT p.ID, p.post_name, pm1.meta_value as planet_slug, pm2.meta_value as planet_id
FROM wp_posts p
INNER JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_planet_product_hash'
LEFT JOIN wp_postmeta pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_planet_slug'
LEFT JOIN wp_postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_planet_id'
WHERE p.post_type = 'product' 
AND p.post_status NOT IN ('trash', 'auto-draft')
GROUP BY p.ID
```

## Troubleshooting

### Products Not Being Detected

**Issue**: Orphaned products exist but aren't being detected

**Possible Causes**:
1. Products don't have Planet metadata (manually created)
2. Action is set to "Keep"
3. Product slugs/IDs still exist in remote API

**Solution**:
- Check if product has `_planet_product_hash` meta
- Enable debug mode to see detailed logs
- Verify product doesn't exist in remote API

### Too Many Products Being Flagged

**Issue**: Products are incorrectly identified as orphaned

**Possible Causes**:
1. Remote API temporarily unavailable
2. Slug/ID mismatch between systems
3. Recent remote data changes

**Solution**:
- Wait for next sync cycle
- Check API connection
- Review product slugs in database vs API

### Accidental Deletions

**Issue**: Products were permanently deleted unintentionally

**Solution**:
- Restore from database backup
- Change setting to safer option (Keep or Draft)
- Always test with Draft option first

## Version History

- **Version 2.0.1**: Orphaned Products feature introduced

## Support

For issues or questions about the Orphaned Products feature:
1. Check the logs in **Recent Activity** section
2. Enable **Debug Mode** for detailed error logging
3. Review the **Orphaned Products** dashboard section
4. Contact plugin support with log details

## See Also

- [README.md](README.md) - Main plugin documentation
- [REALTIME-SYNC.md](REALTIME-SYNC.md) - Real-time sync information
- [CATEGORY-COMPARISON.md](CATEGORY-COMPARISON.md) - Category sync details

