# .htaccess for Localhost vs Production

The main `.htaccess` is currently configured for **localhost** (XAMPP subfolder at `/herosysgro/`).

## Current (localhost)
- `RewriteBase /herosysgro/`
- `APP_BASE` set for paths starting with `/herosysgro`
- URLs: `http://localhost/herosysgro/login`

## When deploying to production (server root)
1. Change `RewriteBase` from `/herosysgro/` to `/`
2. Change APP_BASE condition: `RewriteCond %{REQUEST_URI} ^/herosysgro` → remove or set APP_BASE empty for root
3. In `operation/.htaccess`: Change `RewriteBase` to `/operation/` and fix DOCUMENT_ROOT paths (remove `/herosysgro`)

A copy of the production config may exist in `.htaccess.live` or similar. Swap files when switching environments.
