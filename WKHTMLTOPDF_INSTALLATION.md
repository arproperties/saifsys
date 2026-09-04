# wkhtmltopdf Installation Guide

## Migration Status: ✅ COMPLETED

The database migration has been successfully executed. All contract template system tables and fields have been added.

## wkhtmltopdf Installation

**Status:** ⚠️ Installation required (varies by OS)

Installation method depends on your operating system:
- **macOS (Development):** Manual download required
- **Linux (Production Server):** Package manager installation (recommended)

---

## 🖥️ Linux Server Installation (Production)

### Option 1: Ubuntu/Debian (Recommended)

```bash
# Update package list
sudo apt-get update

# Install dependencies
sudo apt-get install -y xvfb xfonts-75dpi xfonts-base libjpeg-turbo8 fontconfig

# Download and install wkhtmltopdf
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox_0.12.6.1-2.jammy_amd64.deb
# For Ubuntu 22.04 (Jammy) - adjust version for your Ubuntu release

# Install the package
sudo dpkg -i wkhtmltox_0.12.6.1-2.jammy_amd64.deb

# Fix any dependency issues
sudo apt-get install -f

# Verify installation
wkhtmltopdf --version
```

**For different Ubuntu versions:**
- Ubuntu 20.04 (Focal): `wkhtmltox_0.12.6.1-2.focal_amd64.deb`
- Ubuntu 18.04 (Bionic): `wkhtmltox_0.12.6.1-2.bionic_amd64.deb`
- Debian 11 (Bullseye): `wkhtmltox_0.12.6.1-2.bullseye_amd64.deb`

### Option 2: CentOS/RHEL/Fedora

```bash
# For CentOS 7 / RHEL 7
sudo yum install -y xorg-x11-fonts-75dpi xorg-x11-fonts-Type1
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox-0.12.6.1-2.centos7.x86_64.rpm
sudo rpm -ivh wkhtmltox-0.12.6.1-2.centos7.x86_64.rpm

# For CentOS 8 / RHEL 8 / Fedora
sudo dnf install -y xorg-x11-fonts-75dpi xorg-x11-fonts-Type1
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox-0.12.6.1-2.centos8.x86_64.rpm
sudo rpm -ivh wkhtmltox-0.12.6.1-2.centos8.x86_64.rpm

# Verify installation
wkhtmltopdf --version
```

### Option 3: Using Package Manager (Alternative)

Some distributions have wkhtmltopdf in their repositories:

```bash
# Ubuntu/Debian (older versions)
sudo apt-get install wkhtmltopdf

# Arch Linux
sudo pacman -S wkhtmltopdf-static

# Alpine Linux
sudo apk add wkhtmltopdf
```

**Note:** Repository versions may be older. Use GitHub releases for latest version.

### Option 4: Docker (Containerized Deployment)

If your server uses Docker:

```bash
# Pull the official image
docker pull wkhtmltopdf/wkhtmltopdf

# Or use in your Dockerfile
FROM php:8.1-apache
RUN apt-get update && apt-get install -y \
    xvfb xfonts-75dpi xfonts-base libjpeg-turbo8 fontconfig \
    && wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox_0.12.6.1-2.jammy_amd64.deb \
    && dpkg -i wkhtmltox_0.12.6.1-2.jammy_amd64.deb \
    && apt-get install -f
```

### Server Configuration Notes

1. **Web Server User Permissions:**
   ```bash
   # Ensure web server user can execute wkhtmltopdf
   which wkhtmltopdf
   # Should output: /usr/local/bin/wkhtmltopdf or /usr/bin/wkhtmltopdf
   ```

2. **Font Support (Important for Arabic):**
   ```bash
   # Install Arabic fonts
   sudo apt-get install fonts-arabeyes fonts-arabic fonts-kacst fonts-khmeros
   
   # Or for CentOS/RHEL
   sudo yum install google-noto-arabic-fonts
   ```

3. **Xvfb for Headless Servers:**
   ```bash
   # If running on headless server (no display)
   sudo apt-get install xvfb
   
   # Use xvfb-run wrapper
   xvfb-run -a wkhtmltopdf input.html output.pdf
   ```

4. **PHP Configuration:**
   ```php
   // The system automatically detects wkhtmltopdf
   // But you can set custom path in contract_pdf_generator.php if needed
   ```

---

## 💻 macOS Installation (Development)

### Option 1: Download Pre-built Binary (Recommended)

1. Visit: https://wkhtmltopdf.org/downloads.html
2. Download the macOS installer for your system:
   - For Intel Macs: `wkhtmltox-0.12.6-1.macos-cocoa.pkg`
   - For Apple Silicon: Check if available or use Rosetta
3. Install the `.pkg` file
4. Verify installation:
   ```bash
   which wkhtmltopdf
   wkhtmltopdf --version
   ```

### Option 2: Build from Source

If pre-built binaries don't work:

```bash
# Install dependencies
brew install qt5

# Clone and build
git clone https://github.com/wkhtmltopdf/wkhtmltopdf.git
cd wkhtmltopdf
# Follow build instructions in README
```

### Option 3: Use Docker (Alternative)

If you prefer not to install locally, you can use Docker:

```bash
docker pull wkhtmltopdf/wkhtmltopdf
```

Then modify the PDF generator to use Docker instead of direct binary.

### Verification

After installation, verify it works:

```bash
wkhtmltopdf --version
```

You should see output like:
```
wkhtmltopdf 0.12.6 (with patched qt)
```

### System Configuration

The contract PDF generator will automatically detect wkhtmltopdf in these locations:
- `/usr/local/bin/wkhtmltopdf`
- `/usr/bin/wkhtmltopdf`
- `/opt/homebrew/bin/wkhtmltopdf` (Apple Silicon Homebrew)
- Any location in your system PATH

### Testing

Once installed, test the contract generation:
1. Go to a lease view page
2. Click "Generate Contract PDF"
3. The system will check if wkhtmltopdf is available
4. If not found, you'll see a warning message

## Next Steps

1. ✅ Database migration - COMPLETED
2. ⚠️ Install wkhtmltopdf - MANUAL INSTALLATION REQUIRED
3. Create HTML template in `lease_templates.php`
4. Test contract generation

## 🔧 Server-Specific Configuration

### Headless Server Setup (No Display)

If your server doesn't have a display (common in production):

```bash
# Install Xvfb (X Virtual Framebuffer)
sudo apt-get install xvfb  # Ubuntu/Debian
sudo yum install xorg-x11-server-Xvfb  # CentOS/RHEL

# The system will automatically use xvfb-run if available
```

The PDF generator automatically detects and uses `xvfb-run` on Linux servers.

### Font Configuration for Arabic

For proper Arabic text rendering:

```bash
# Ubuntu/Debian
sudo apt-get install fonts-arabeyes fonts-arabic fonts-kacst fonts-khmeros

# CentOS/RHEL
sudo yum install google-noto-arabic-fonts dejavu-sans-fonts

# Verify fonts
fc-list | grep -i arabic
```

### SELinux Configuration (CentOS/RHEL)

If SELinux is enabled, you may need to allow wkhtmltopdf:

```bash
# Check if SELinux is blocking
sudo ausearch -m avc -ts recent | grep wkhtmltopdf

# If needed, set permissive mode for testing
sudo setenforce 0  # Temporary
# Or create custom policy
```

### PHP Configuration

Ensure PHP can execute shell commands:

```php
// Check in php.ini
// disable_functions should NOT include exec, shell_exec, system
```

### Web Server Permissions

```bash
# Ensure web server user can access wkhtmltopdf
# Usually www-data (Apache) or nginx (Nginx)
sudo -u www-data which wkhtmltopdf
sudo -u www-data wkhtmltopdf --version
```

## 🐛 Troubleshooting

### Issue: wkhtmltopdf not found

```bash
# Check if installed
which wkhtmltopdf
wkhtmltopdf --version

# Find installation location
find /usr -name wkhtmltopdf 2>/dev/null
find /opt -name wkhtmltopdf 2>/dev/null

# Check PATH
echo $PATH

# If found but not in PATH, create symlink
sudo ln -s /path/to/wkhtmltopdf /usr/local/bin/wkhtmltopdf
```

### Issue: Permission denied

```bash
# Check permissions
ls -l $(which wkhtmltopdf)

# Make executable if needed
sudo chmod +x $(which wkhtmltopdf)

# Check web server user can access
sudo -u www-data wkhtmltopdf --version
```

### Issue: Arabic text not rendering

```bash
# Install Arabic fonts
sudo apt-get install fonts-arabic fonts-arabeyes

# Clear font cache
sudo fc-cache -fv

# Test with Arabic text
echo '<html><body><p>مرحبا</p></body></html>' > test.html
wkhtmltopdf test.html test.pdf
```

### Issue: Headless server errors

```bash
# Install Xvfb
sudo apt-get install xvfb

# Test with xvfb-run
xvfb-run -a wkhtmltopdf test.html test.pdf

# The system automatically uses xvfb-run on Linux
```

### Issue: PDF generation fails silently

Check PHP error logs:
```bash
tail -f /var/log/apache2/error.log  # Apache
tail -f /var/log/nginx/error.log    # Nginx
tail -f /var/log/php-fpm/error.log   # PHP-FPM
```

### Custom Path Configuration

If wkhtmltopdf is in a non-standard location, update the path in:
`modules/realestate/includes/contract_pdf_generator.php`

Modify the `findWkhtmltopdfPath()` method to include your custom path.

