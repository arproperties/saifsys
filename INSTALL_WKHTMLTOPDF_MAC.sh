#!/bin/bash
# wkhtmltopdf Installation Script for macOS
# Run this script to install wkhtmltopdf on your Mac

echo "=========================================="
echo "wkhtmltopdf Installation for macOS"
echo "=========================================="
echo ""

# Check if file exists
PKG_FILE="$HOME/Desktop/wkhtmltox-0.12.6-2.macos-cocoa.pkg"

if [ ! -f "$PKG_FILE" ]; then
    echo "❌ Error: Package file not found at: $PKG_FILE"
    echo "Please make sure the .pkg file is on your Desktop"
    exit 1
fi

echo "✅ Found package file: $PKG_FILE"
echo ""

# Remove quarantine attribute
echo "Step 1: Removing quarantine attribute..."
xattr -d com.apple.quarantine "$PKG_FILE" 2>/dev/null
echo "✅ Quarantine removed"
echo ""

# Install the package
echo "Step 2: Installing wkhtmltopdf..."
echo "You will be prompted for your Mac password"
echo ""

sudo installer -pkg "$PKG_FILE" -target /

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Installation completed successfully!"
    echo ""
    
    # Verify installation
    echo "Step 3: Verifying installation..."
    if command -v wkhtmltopdf &> /dev/null; then
        echo "✅ wkhtmltopdf is installed!"
        wkhtmltopdf --version
        echo ""
        echo "Installation path: $(which wkhtmltopdf)"
    else
        echo "⚠️  wkhtmltopdf command not found in PATH"
        echo "Checking common installation locations..."
        
        if [ -f "/usr/local/bin/wkhtmltopdf" ]; then
            echo "✅ Found at: /usr/local/bin/wkhtmltopdf"
        elif [ -f "/usr/bin/wkhtmltopdf" ]; then
            echo "✅ Found at: /usr/bin/wkhtmltopdf"
        else
            echo "❌ Could not find wkhtmltopdf"
            echo "Please check the installation manually"
        fi
    fi
else
    echo ""
    echo "❌ Installation failed"
    echo "Please try installing manually by double-clicking the .pkg file"
    exit 1
fi

echo ""
echo "=========================================="
echo "Installation Complete!"
echo "=========================================="
echo ""
echo "You can now test the contract generation system."

