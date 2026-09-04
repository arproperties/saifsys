# .htaccess – Live vs Localhost

The project includes two setups so you can run the same code on live (server root) and locally (XAMPP subfolder).

## Live server (app at document root, e.g. https://yourdomain.com/)

- Root: .htaccess is already set for live (RewriteBase /).
- operation/: operation/.htaccess is set for live (RewriteBase /operation/).
- No change needed when uploading to your main root.

## Localhost (XAMPP in subfolder, e.g. http://localhost/herosysgro/)

- Root: Copy .htaccess.localhost over .htaccess (or rename).
- operation/: Copy operation/.htaccess.localhost over operation/.htaccess.
- So: cp .htaccess.localhost .htaccess  and  cp operation/.htaccess.localhost operation/.htaccess

## Switching

- Going to live: use the current .htaccess and operation/.htaccess (they are the live versions).
- Going to local: replace them with the .htaccess.localhost and operation/.htaccess.localhost contents.
