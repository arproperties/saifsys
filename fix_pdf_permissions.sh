#!/bin/bash

# Fix permissions for PDF generation
# This script fixes directory permissions so the web server can write files

echo "=========================================="
echo "🔧 Fixing PDF Generation Permissions"
echo "=========================================="
echo ""

BASE_DIR="/Applications/XAMPP/xamppfiles/htdocs/herosysgro"

# Fix temp directory
echo "1. Fixing temp directory permissions..."
sudo chmod -R 777 "$BASE_DIR/uploads/temp"
sudo chown -R daemon:admin "$BASE_DIR/uploads/temp" 2>/dev/null || sudo chown -R _www:_www "$BASE_DIR/uploads/temp" 2>/dev/null || echo "   Note: Could not change ownership (may need manual fix)"

# Fix contracts directory
echo "2. Fixing contracts directory permissions..."
sudo chmod -R 777 "$BASE_DIR/uploads/contracts"
sudo chown -R daemon:admin "$BASE_DIR/uploads/contracts" 2>/dev/null || sudo chown -R _www:_www "$BASE_DIR/uploads/contracts" 2>/dev/null || echo "   Note: Could not change ownership (may need manual fix)"

# Verify
echo ""
echo "3. Verifying permissions..."
ls -la "$BASE_DIR/uploads/temp" | head -3
ls -la "$BASE_DIR/uploads/contracts" | head -3

echo ""
echo "=========================================="
echo "✅ Done! Try generating the PDF again."
echo "=========================================="

