# Clean URLs Implementation

## Overview
This document describes the clean URLs implementation that removes query parameters from URLs throughout the HZ System, improving security and user experience. URLs now show only the page name (without .php extension), while filter states are managed via session storage.

## What Changed

### 1. URL Structure
**Before:**
- `operation.php?tab=ladies&worker_id=1&page=1`
- `account.php?tab=invoices&status=issued&q=search`

**After:**
- `operation`
- `account`

### 2. Key Components

#### `.htaccess` File
- Removes `.php` extension from URLs
- Handles URL rewriting to map clean URLs to PHP files
- Maintains query string support for backward compatibility

#### URL Helper (`includes/url_helper.php`)
- `clean_url()` - Generates clean URLs
- `store_filter_state()` - Stores filters in session
- `get_filter_state()` - Retrieves filters from session
- `merge_get_with_session()` - Merges GET params with session (backward compatibility)

#### Filter Storage (`accounts/ajax/store_filters.php`)
- AJAX endpoint for storing filter state in session
- Used by JavaScript to maintain filter state without URL parameters

#### JavaScript Helper (`includes/clean_urls.js`)
- Provides utilities for clean URL navigation
- Handles tab navigation with POST requests
- Updates browser history to clean URLs

### 3. Updated Pages

#### Main Navigation Pages
- `operation.php` - Updated to use clean URLs and session-based tab selection
- `account.php` - Updated to use clean URLs and session-based tab selection

#### Operation Pages
- `operation/ladies.php` - Uses session-based filters
- `operation/workorder_list.php` - Uses session-based filters with clean pagination
- All navigation links updated to remove `.php` extension

#### Account Pages
- `accounts/invoices.php` - Updated to use clean URLs
- `accounts/payments.php` - Updated to use clean URLs

## How It Works

### Tab Navigation
1. User clicks a tab link (e.g., "Ladies", "Invoices")
2. JavaScript intercepts the click
3. Filter state is stored in session via AJAX
4. Form is submitted via POST with tab parameter
5. Server reads tab from POST or session
6. URL remains clean (no query parameters)

### Filter Management
1. User applies filters (search, dates, status, etc.)
2. JavaScript stores filters in session via AJAX
3. Form is submitted via POST
4. Server retrieves filters from session
5. Page displays filtered results
6. URL remains clean

### Pagination
1. User clicks pagination link
2. JavaScript captures current filter state
3. Filters + page number stored in session
4. Form submitted via POST
5. Server retrieves filters and page from session
6. URL remains clean

## Backward Compatibility

The implementation maintains backward compatibility:
- GET parameters still work (merged with session filters)
- Old URLs with query strings are automatically handled
- Existing bookmarks continue to function

## Security Benefits

1. **No Sensitive Data in URLs**: Filter parameters, IDs, and search terms are not visible in the URL
2. **Reduced Information Disclosure**: URLs don't reveal system structure or data relationships
3. **Session-Based State**: Filter state is stored server-side in sessions
4. **Cleaner URLs**: Easier to share and bookmark without exposing internal state

## Testing Checklist

- [ ] Navigation between tabs works correctly
- [ ] Filters persist across page navigation
- [ ] Pagination works with filters
- [ ] Search functionality works
- [ ] Status filters work
- [ ] Date range filters work
- [ ] Reset/clear filters works
- [ ] Browser back/forward buttons work
- [ ] Direct URL access works (backward compatibility)
- [ ] All links use clean URLs (no .php extension)

## Notes

- Filter state is stored in PHP sessions, so it persists across page loads
- Session data is cleared when user logs out
- For print/export functions that need query strings, they still work but use clean URLs where possible
- The `.htaccess` file handles URL rewriting automatically

## Future Enhancements

- Consider implementing URL hash-based routing for even cleaner URLs
- Add filter state persistence across browser sessions (optional)
- Implement filter presets that can be saved and loaded

