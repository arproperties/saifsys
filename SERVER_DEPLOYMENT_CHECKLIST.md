# Server Deployment Checklist - Contract Template System

## Pre-Deployment

### 1. Database Migration ✅
- [x] Migration script executed
- [x] All tables and fields created
- [ ] Verify with: `SHOW TABLES LIKE 're_contract_templates';`

### 2. wkhtmltopdf Installation

#### Ubuntu/Debian Server:
```bash
sudo apt-get update
sudo apt-get install -y xvfb xfonts-75dpi xfonts-base libjpeg-turbo8 fontconfig
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox_0.12.6.1-2.jammy_amd64.deb
sudo dpkg -i wkhtmltox_0.12.6.1-2.jammy_amd64.deb
sudo apt-get install -f
```

#### CentOS/RHEL Server:
```bash
sudo yum install -y xorg-x11-fonts-75dpi xorg-x11-fonts-Type1
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox-0.12.6.1-2.centos8.x86_64.rpm
sudo rpm -ivh wkhtmltox-0.12.6.1-2.centos8.x86_64.rpm
```

### 3. Font Installation (Arabic Support)
```bash
# Ubuntu/Debian
sudo apt-get install fonts-arabeyes fonts-arabic fonts-kacst

# CentOS/RHEL
sudo yum install google-noto-arabic-fonts
sudo fc-cache -fv
```

### 4. Xvfb Installation (Headless Servers)
```bash
# Ubuntu/Debian
sudo apt-get install xvfb

# CentOS/RHEL
sudo yum install xorg-x11-server-Xvfb
```

## Verification Steps

### 1. Verify wkhtmltopdf Installation
```bash
which wkhtmltopdf
wkhtmltopdf --version
# Should output: wkhtmltopdf 0.12.6.1 (with patched qt)
```

### 2. Test PDF Generation
```bash
echo '<html><body><h1>Test</h1><p>مرحبا (Arabic test)</p></body></html>' > /tmp/test.html
wkhtmltopdf /tmp/test.html /tmp/test.pdf
ls -lh /tmp/test.pdf
```

### 3. Test as Web Server User
```bash
# Apache
sudo -u www-data wkhtmltopdf --version

# Nginx
sudo -u nginx wkhtmltopdf --version
```

### 4. Test Xvfb (if headless)
```bash
xvfb-run -a wkhtmltopdf /tmp/test.html /tmp/test.pdf
```

## File Permissions

### 1. Upload Directories
```bash
mkdir -p /path/to/herosysgro/uploads/contracts
mkdir -p /path/to/herosysgro/uploads/leases/ejari
mkdir -p /path/to/herosysgro/uploads/leases/signatures
mkdir -p /path/to/herosysgro/uploads/leases/stamps

# Set permissions
chown -R www-data:www-data /path/to/herosysgro/uploads
chmod -R 755 /path/to/herosysgro/uploads
```

### 2. PHP Configuration
```bash
# Check php.ini
# Ensure these are NOT in disable_functions:
# - exec
# - shell_exec
# - system
```

## Post-Deployment Testing

### 1. Create Test Template
- Go to: `modules/realestate/lease_templates.php`
- Create new HTML template
- Test placeholder insertion

### 2. Test Contract Generation
- Create/edit a lease
- Fill Ejari information
- Upload test signatures
- Generate contract PDF
- Verify PDF output

### 3. Check Error Logs
```bash
# Apache
tail -f /var/log/apache2/error.log

# Nginx
tail -f /var/log/nginx/error.log

# PHP-FPM
tail -f /var/log/php-fpm/error.log
```

## Common Issues & Solutions

### Issue: "wkhtmltopdf not found"
**Solution:** Verify installation and PATH
```bash
which wkhtmltopdf
# If not found, add to PATH or create symlink
```

### Issue: "Permission denied"
**Solution:** Check web server user permissions
```bash
sudo -u www-data wkhtmltopdf --version
```

### Issue: "Arabic text not rendering"
**Solution:** Install Arabic fonts and clear cache
```bash
sudo apt-get install fonts-arabic
sudo fc-cache -fv
```

### Issue: "No display" errors
**Solution:** Install and use Xvfb
```bash
sudo apt-get install xvfb
# System automatically uses xvfb-run on Linux
```

## Security Considerations

1. **File Upload Security:**
   - Validate file types (PDF for Ejari, PNG/JPG for signatures)
   - Limit file sizes
   - Scan uploaded files for malware

2. **Path Traversal Protection:**
   - Validate all file paths
   - Use absolute paths for uploads
   - Restrict access to upload directories

3. **Command Injection Prevention:**
   - All user input is escaped with `escapeshellarg()`
   - Paths are validated before use

## Performance Optimization

1. **Caching:**
   - Generated PDFs are stored in `uploads/contracts/`
   - Re-generate only when lease data changes

2. **Queue System (Future Enhancement):**
   - For high-volume: Consider queue system for PDF generation
   - Use background jobs for large batches

## Monitoring

Monitor these metrics:
- PDF generation success rate
- Average generation time
- Disk space usage in `uploads/contracts/`
- Error log entries related to wkhtmltopdf

## Backup

Include in backups:
- `uploads/contracts/` - Generated PDFs
- `uploads/leases/` - Ejari documents, signatures
- Database: `re_contract_templates` table
- Database: `re_leases` table (with new fields)

## Tenant Portal — Lease Renewal (Phase 1)

After deploying portal renewal features, run:

- `migrations/tenant_portal_renewal_phase1.sql`
- `migrations/renewal_negotiation_thread.sql` (negotiation messages between tenant and staff)

Verify:

- `SHOW TABLES LIKE 're_renewal_%';` — should include `re_renewal_portal_events`, `re_renewal_tenant_uploads`, `re_renewal_electronic_signatures`, `re_renewal_negotiation_messages`
- `DESCRIBE re_lease_renewal_workflows;` — portal / acknowledgement columns present

See `docs/TENANT_PORTAL_RENEWAL_PHASE1.md` for status flow and admin steps.

### Tenant Portal renewal — email deep link (optional)

- Set `APP_BASE_URL` in `includes/config.php` (full origin + path) so renewal emails contain correct absolute links.
- Optional: run `migrations/renewal_notice_email_portal_deep_link.sql` once to append `{RENEWAL_PORTAL_LINK}` to existing `renewal_notice` templates that do not already include it.

