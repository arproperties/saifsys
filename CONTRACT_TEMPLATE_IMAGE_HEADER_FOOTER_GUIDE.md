# Contract Template - Image Header/Footer Setup Guide

## 📋 Overview

Your contract template now supports **header and footer on every page** using wkhtmltopdf's built-in header/footer features.

For **EXACT matching** to your original PDF, you can use **images** for the header and footer instead of HTML text.

## ✅ Current Implementation (HTML Header/Footer)

The system now automatically:
- ✅ Creates header HTML with company name, subtitle, properties
- ✅ Creates footer HTML with contact information
- ✅ Displays header/footer on EVERY page
- ✅ Fixed empty page 6 issue
- ✅ Proper pagination control

## 🎨 Option: Use Images for Exact Matching

If you want to match your original PDF **exactly** using images:

### Step 1: Extract Header/Footer Images

1. Open your original PDF: `2026Tanacey contract.pdf`
2. Take a screenshot or export the **header section** (first page top)
3. Take a screenshot or export the **footer section** (any page bottom)
4. Save as:
   - `uploads/contracts/header.png` (or `.jpg`)
   - `uploads/contracts/footer.png` (or `.jpg`)

### Step 2: Update Code to Use Images

Edit: `modules/realestate/includes/contract_pdf_generator.php`

**Option A: Using Base64 Encoded Images (Recommended)**

```php
private function generateHeaderHTML($lease = []) {
    // Read header image and convert to base64
    $headerImagePath = $this->basePath . '/uploads/contracts/header.png';
    if (file_exists($headerImagePath)) {
        $imageData = file_get_contents($headerImagePath);
        $imageInfo = getimagesize($headerImagePath);
        $mimeType = $imageInfo['mime'];
        $base64 = base64_encode($imageData);
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 0; }
        img { width: 100%; height: auto; display: block; }
    </style>
</head>
<body>
    <img src="data:' . $mimeType . ';base64,' . $base64 . '" alt="Header">
</body>
</html>';
    }
    
    // Fallback to text header
    $companyName = $lease['company_name'] ?? 'AIN AL REEM PROPERTIES L.L.C.';
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 0; font-family: "DejaVu Sans", "Arial", sans-serif; font-size: 9px; text-align: center; }
        .header-content { border-bottom: 2px solid #000; padding-bottom: 10px; }
        .company-name { font-size: 13px; font-weight: bold; margin-bottom: 4px; text-transform: uppercase; }
        .company-subtitle { font-size: 9.5px; margin-bottom: 3px; }
        .company-details { font-size: 9px; margin-top: 6px; }
    </style>
</head>
<body>
    <div class="header-content">
        <div class="company-name">' . htmlspecialchars($companyName) . '</div>
        <div class="company-subtitle">Real Estate and Management Company</div>
        <div class="company-subtitle">PROPERTIES</div>
        <div class="company-details">Plot No.: _______________</div>
    </div>
</body>
</html>';
}

private function generateFooterHTML() {
    // Read footer image and convert to base64
    $footerImagePath = $this->basePath . '/uploads/contracts/footer.png';
    if (file_exists($footerImagePath)) {
        $imageData = file_get_contents($footerImagePath);
        $imageInfo = getimagesize($footerImagePath);
        $mimeType = $imageInfo['mime'];
        $base64 = base64_encode($imageData);
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 0; }
        img { width: 100%; height: auto; display: block; }
    </style>
</head>
<body>
    <img src="data:' . $mimeType . ';base64,' . $base64 . '" alt="Footer">
</body>
</html>';
    }
    
    // Fallback to text footer
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 8px 0 0 0; font-family: "DejaVu Sans", "Arial", sans-serif; font-size: 8.5px; text-align: center; border-top: 1px solid #ddd; }
        .footer-content div { margin: 2px 0; }
    </style>
</head>
<body>
    <div class="footer-content">
        <div>Phone: +971 544603667; +971 562436573</div>
        <div>Email: ar.properties15@gmail.com</div>
        <div>Address: OFFICE P01, AYLA RESIDENCE, AL BARSHA SOUTH FOURTH, DUBAI</div>
    </div>
</body>
</html>';
}
```

**Option B: Using File Paths (Requires --enable-local-file-access)**

```php
private function generateHeaderHTML($lease = []) {
    $headerImagePath = '/full/path/to/uploads/contracts/header.png';
    if (file_exists($headerImagePath)) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 0; }
        img { width: 100%; height: auto; display: block; }
    </style>
</head>
<body>
    <img src="file://' . $headerImagePath . '" alt="Header">
</body>
</html>';
    }
    // ... fallback code
}
```

### Step 3: Adjust Margins

If using images, you may need to adjust margins in `generatePDF()` method:

```php
$options = [
    '--page-size A4',
    '--margin-top 40mm',     // Adjust based on header image height
    '--margin-bottom 35mm',  // Adjust based on footer image height
    '--margin-left 15mm',
    '--margin-right 15mm',
    // ... rest of options
];
```

## 📐 Recommended Image Dimensions

For A4 paper (210mm x 297mm) with 15mm side margins:

- **Header Image**: 
  - Width: 180mm (210mm - 30mm margins)
  - Height: ~25-30mm (adjust based on your header)
  - Format: PNG (with transparency) or JPG
  - Resolution: 300 DPI for print quality

- **Footer Image**:
  - Width: 180mm (210mm - 30mm margins)
  - Height: ~20-25mm (adjust based on your footer)
  - Format: PNG (with transparency) or JPG
  - Resolution: 300 DPI for print quality

## 🔧 Testing

1. Place header/footer images in `uploads/contracts/`
2. Update the code as shown above
3. Generate a test PDF
4. Verify header/footer appear on every page
5. Adjust margins if needed

## ⚠️ Notes

- **Image files must be accessible** to wkhtmltopdf
- **Base64 encoding** works best (no file path issues)
- **PNG with transparency** recommended for clean borders
- **300 DPI** recommended for print quality
- **File permissions**: Ensure images are readable

## 🚀 Current Status

- ✅ Header/Footer on every page implemented
- ✅ Empty page 6 issue fixed
- ✅ Pagination improved
- ✅ Ready for image-based header/footer

If you need help implementing image-based headers/footers, let me know and I can update the code for you!

