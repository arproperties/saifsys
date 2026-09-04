# Header and Footer Size - Where to Edit

Edit these values in **`modules/realestate/includes/contract_pdf_generator.php`**:

## Header Image Size
**Line ~347** (in `generateHeaderHTML`):
```php
$maxH = 50;   // Change to 45, 55, 60, etc. (pixels)
```

## Footer Image Size
**Line ~352** (in `generateFooterHTML`):
```php
$maxH = 28;   // Change to 25, 30, 35, etc. (pixels)
```

## Dompdf Block Limits (if using text fallback)
**Line ~890** (in `$dompdfStyles`):
- Header block: `max-height: 38px` 
- Footer block: `min-height: 24px`

---

**Note:** The template is stored in the database (Contract Templates). To use `CONTRACT_TEMPLATE_UPDATED.html`, copy its content into the template editor and save.
