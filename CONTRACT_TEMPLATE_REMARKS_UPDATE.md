# Contract Template Updates - Match Original PDF

## Summary of Changes

The original 2026Tanacey contract.pdf has this Remarks layout:
1. **Remarks:** (title)
2. Two blank lines (for lease notes)
3. **Tenant's Phone:** ________ **Tenant's Emirates ID:** ________ (SAME row)
4. **Tenant's Email:** ________ (separate row)
5. "I undertake to act in accordance with this Contract and its Conditions."
6. Arabic: أتعهد بالعمل وفق هذه الاتفاقية وشروطها
7. Signatures

## 1. Add CSS (in your `<style>` block, inside the remarks-section rules)

```css
.remarks-notes-line {
    border-bottom: 1px solid #000;
    min-height: 18px;
    margin-bottom: 8px;
    padding-bottom: 2px;
}

.remarks-tenant-row .value {
    border-bottom: 1px solid #000;
    min-height: 18px;
    padding-bottom: 3px;
}
```

## 2. Replace Remarks Section HTML

Find this in your template:
```html
<!-- Remarks Section -->
<div class="remarks-section">
    <div class="remarks-title">Remarks:</div>
    <table class="remarks-table">
        <tr>
            <td class="label">Tenant's Phone:</td>
            <td class="value">{{TENANT_PHONE}}</td>
        </tr>
        ...
    </table>
    ...
</div>
```

Replace with:
```html
<!-- Remarks Section - matches original PDF -->
<div class="remarks-section">
    <div class="remarks-title">Remarks:</div>
    <div class="remarks-notes-line">{{LEASE_NOTES}}</div>
    <div class="remarks-notes-line"></div>
    <table class="remarks-table remarks-tenant-row">
        <tr>
            <td class="label" style="width: 25%;">Tenant's Phone:</td>
            <td class="value" style="width: 25%;">{{TENANT_PHONE}}</td>
            <td class="label" style="width: 25%;">Tenant's Emirates ID:</td>
            <td class="value" style="width: 25%;">{{TENANT_EMIRATES_ID}}</td>
        </tr>
    </table>
    <table class="remarks-table">
        <tr>
            <td class="label" style="width: 25%;">Tenant's Email:</td>
            <td class="value" style="width: 75%;">{{TENANT_EMAIL}}</td>
        </tr>
    </table>
    <div style="margin-top: 20px; text-align: center; font-size: 10.5px;">
        <div style="margin-bottom: 8px;">I undertake to act in accordance with this Contract and its Conditions.</div>
        <div class="ar" style="font-family: 'DejaVu Sans', 'Arial', sans-serif;">أتعهد بالعمل وفق هذه الاتفاقية وشروطها</div>
    </div>
</div>
```

## 3. Arabic Section Numbers (optional)

Your template uses `<span class="clause-number">1.</span>` before Arabic content. In RTL blocks (`.clause-ar`), the first element appears on the right. So the number should already display on the right. If not, you can wrap the Arabic clause in:

```html
<div class="clause-ar">
    <span class="clause-content">...Arabic text...</span>
    <span class="clause-number">1.</span>
</div>
```

And add CSS:
```css
.clause-ar {
    display: flex;
    flex-direction: row-reverse;
}
```

## 4. Font Change

Original template uses `'Amiri', 'Arial', serif` for Arabic. Dompdf may not have Amiri. Use `'DejaVu Sans', 'Arial', sans-serif` for consistent Arabic rendering in PDF (already supported by Dompdf).

## 5. Placeholder {{LEASE_NOTES}}

The `{{LEASE_NOTES}}` placeholder is replaced by the lease "Notes" field from the lease form. Ensure your `contract_pdf_generator.php` includes this placeholder (already added in earlier changes).
