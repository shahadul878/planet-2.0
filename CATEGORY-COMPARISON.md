# Category Comparison Feature

## Overview

The Category Comparison feature allows you to compare Planet API level 1 categories with WooCommerce product categories side-by-side, showing which categories match, have mismatches, or are missing.

## How to Use

### 1. Access the Feature

Navigate to **Products > Planet Sync 2.0** in WordPress admin, then scroll to the **Category Comparison** section.

### 2. Load Comparison

Click the **"Load Category Comparison"** button to fetch and compare categories:
- Fetches level 1 categories from Planet API
- Compares with WooCommerce top-level product categories
- Displays results in a detailed table

### 3. Review Results

The comparison shows:

#### Summary Statistics
- **Total Planet**: Number of categories from Planet API
- **Total WooCommerce**: Number of top-level WooCommerce categories
- **Matched**: Categories that exist in both systems with same name
- **Mismatch**: Categories linked by ID but with different names
- **Missing**: Planet categories not found in WooCommerce

#### Detailed Table
Each row shows:
- **Status**: Visual indicator (✓ Matched, ⚠ Mismatch, ✗ Missing)
- **Planet ID**: Original Planet category ID
- **Planet Category**: Category name from Planet API
- **WooCommerce ID**: WooCommerce term ID (or "-" if missing)
- **WooCommerce Category**: Category name in WooCommerce (or "-" if missing)
- **Products**: Number of products in the WooCommerce category

### 4. Create Missing Categories

If missing categories are detected:
1. The **"Create Missing Categories"** button appears
2. Click the button to automatically create all missing categories
3. Categories are created with:
   - Same name as Planet API
   - Parent set to 0 (top-level)
   - Planet ID stored in term meta (`_planet_category_id`)

## Status Types

### ✓ Matched (Green)
- Category exists in both systems
- Names match (case-insensitive)
- Either linked by Planet ID or matched by name
- **Action**: None needed

**Example:**
```
Planet: "Electronics" (ID: 123)
WooCommerce: "Electronics" (ID: 45)
Status: ✓ Matched
```

### ⚠ Mismatch (Orange)
- Category linked by Planet ID
- Names are different
- Might indicate renamed category
- **Action**: Review and decide if update needed

**Example:**
```
Planet: "Computing Devices" (ID: 123)
WooCommerce: "Computers" (ID: 45) [has Planet ID: 123]
Status: ⚠ Mismatch
```

### ✗ Missing (Red)
- Planet category doesn't exist in WooCommerce
- Not linked by ID
- Not found by name
- **Action**: Click "Create Missing Categories" button

**Example:**
```
Planet: "Smart Home" (ID: 456)
WooCommerce: - (not found)
Status: ✗ Missing
```

## Comparison Logic

The system uses a two-step matching process:

### Step 1: Match by Planet ID
```php
// Check if WooCommerce category has _planet_category_id meta
if (category has Planet ID stored) {
    if (names match) {
        Status: Matched
    } else {
        Status: Mismatch
    }
}
```

### Step 2: Match by Name
```php
// If no Planet ID match, try name matching (case-insensitive)
if (Planet name === WooCommerce name) {
    Status: Matched
} else {
    Status: Missing
}
```

## Visual Design

### Color Coding

**Matched Categories (Green)**
- Light green background
- Green status badge
- Indicates healthy sync state

**Mismatch Categories (Orange)**
- Light orange background
- Orange warning badge
- Requires attention

**Missing Categories (Red)**
- Light red background
- Red error badge
- Action required

### Summary Cards

Statistics displayed in cards with:
- Color-coded left borders
- Large numbers for quick scanning
- Grid layout (responsive)

### Comparison Table

- Sticky header (stays visible when scrolling)
- Hover effects on rows
- Color-coded rows by status
- Responsive design

## AJAX Endpoints

### Get Category Comparison
**Action**: `planet_get_category_comparison`

**Request:**
```javascript
{
    action: 'planet_get_category_comparison',
    nonce: 'security_nonce'
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "comparison": [
            {
                "planet_id": "123",
                "planet_name": "Electronics",
                "woo_name": "Electronics",
                "woo_id": "45",
                "woo_count": 25,
                "status": "matched"
            }
        ],
        "summary": {
            "total_planet": 10,
            "total_woo": 8,
            "matched": 7,
            "mismatch": 1,
            "missing": 2
        }
    }
}
```

### Create Missing Categories
**Action**: `planet_create_missing_categories`

**Request:**
```javascript
{
    action: 'planet_create_missing_categories',
    nonce: 'security_nonce'
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "created": 2,
        "message": "Created 2 missing categories"
    }
}
```

## Use Cases

### Before First Sync
1. Load category comparison
2. Review missing categories
3. Create missing categories
4. Start product sync
5. All products will be properly categorized

### After Sync Issues
1. Load category comparison
2. Check for mismatches
3. Identify why products aren't categorized
4. Fix category names or create missing ones
5. Re-run product sync

### Regular Maintenance
1. Load comparison periodically
2. Check for new Planet categories
3. Create new categories as needed
4. Keep systems in sync

## Integration with Sync Process

The full sync automatically:
1. **Validates categories first** (Step 1)
2. Creates missing categories automatically
3. Then proceeds with product sync

But you can:
- Run comparison independently anytime
- Review categories before sync
- Manually create specific categories

## Technical Details

### Data Storage

**WooCommerce Categories:**
- Taxonomy: `product_cat`
- Parent: 0 (top-level only)
- Term Meta: `_planet_category_id` stores Planet ID

**Planet Categories:**
- Fetched from: `/getProduct1stCategoryList`
- Fields: `id`, `name`

### Performance

- Categories cached for 5 minutes (API class)
- Quick comparison (typically < 1 second)
- Minimal database queries
- Efficient matching algorithm

### Limitations

- Only compares **level 1** categories (top-level)
- Case-insensitive name matching
- No automatic category updates (manual review required for mismatches)
- No bulk delete of extra WooCommerce categories

## Best Practices

1. **Run Before First Sync**
   - Ensures all categories exist
   - Prevents orphaned products

2. **Review Mismatches**
   - Don't auto-update mismatch names
   - Manual review is safer
   - Consider if category was intentionally renamed

3. **Check Periodically**
   - New categories added to Planet
   - Keeps systems synchronized
   - Prevents future sync issues

4. **After API Changes**
   - If Planet adds new categories
   - If category names change
   - Verify before product sync

## Troubleshooting

### Comparison Doesn't Load
- Check API key is correct
- Test API connection
- Verify Planet API is accessible
- Check browser console for errors

### Missing Categories Not Created
- Check user permissions (need `manage_woocommerce`)
- Verify WooCommerce is active
- Check debug.log for errors
- Ensure database is writable

### Categories Show as Missing But Exist
- Names might have different case
- Extra spaces in names
- Special characters differences
- Try manual name match in WooCommerce

### All Categories Show Mismatch
- Planet IDs not stored in term meta
- First time comparison
- Click "Create Missing Categories" to fix

## Examples

### Perfect Match Scenario
```
✓ Electronics     (123) -> Electronics     (45) [25 products]
✓ Accessories     (124) -> Accessories     (46) [12 products]
✓ Home Appliances (125) -> Home Appliances (47) [8 products]
```

### Mixed Scenario
```
✓ Electronics     (123) -> Electronics     (45) [25 products]
⚠ Computing       (124) -> Computers       (46) [12 products]
✗ Smart Home      (125) -> - [not found]
```

### After Creating Missing
```
✓ Electronics     (123) -> Electronics     (45) [25 products]
⚠ Computing       (124) -> Computers       (46) [12 products]
✓ Smart Home      (125) -> Smart Home      (48) [0 products]  ← newly created
```

## Related Features

- **Full Sync**: Automatically validates categories (Step 1)
- **Category Assignment**: Products assigned during sync (Step 3)
- **Sync Logs**: Shows category creation in activity log

## Support

For issues with category comparison:
1. Check WordPress admin notices
2. Review sync logs for errors
3. Enable debug mode for detailed logging
4. Contact support with comparison screenshot

