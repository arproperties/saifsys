# macOS PDF Generation Fix

## Issue
wkhtmltopdf on macOS requires a display server. The error "QPainter::begin(): Returned false" or "could not create nib directory" indicates it needs X11/XQuartz.

## Solution: Install XQuartz

XQuartz provides the X11 display server that wkhtmltopdf needs on macOS.

### Installation:

```bash
brew install --cask xquartz
```

After installation:
1. **Restart your Mac** (or at least log out and back in)
2. XQuartz will start automatically when needed

### Verify Installation:

```bash
# Check if XQuartz is installed
brew list --cask xquartz

# Test wkhtmltopdf
echo '<html><body><h1>Test</h1></body></html>' > test.html
wkhtmltopdf test.html test.pdf
```

### Alternative: Use Direct Command (If you have a display)

If you're running the web server with a GUI session, you can try setting the DISPLAY variable:

```bash
export DISPLAY=:0
```

But XQuartz is the recommended solution for macOS.

## After Installing XQuartz

1. Restart your Mac or log out/in
2. Try generating a contract PDF again
3. It should work without errors

## For Production (Linux Server)

On Linux servers, use `xvfb-run` which is automatically detected and used by the system.

