# Contract Template Setup Guide - Next Steps

## ✅ Installation Complete!

wkhtmltopdf is installed and working:
- Location: `/usr/local/bin/wkhtmltopdf`
- Version: `0.12.6 (with patched qt)`
- Test: ✅ PDF generation successful

---

## 📋 Step-by-Step Setup

### Step 1: Create Your HTML Template

1. **Go to Template Editor:**
   - Navigate to: `http://localhost/herosysgro/modules/realestate/lease_templates.php`
   - Or click "Contract Templates" in the Real Estate sidebar

2. **Create New Template:**
   - Click "New Template" button
   - Fill in:
     - **Template Name:** "2026 Tanacey Contract" (or your preferred name)
     - **Template Type:** "Standard"
     - **Description:** "Bilingual (English/Arabic) lease contract template"
     - **Template Format:** Select **"HTML Template (Advanced - for PDF)"**

3. **Paste Your Master HTML Template:**
   - The TinyMCE editor will open
   - Click "Insert Placeholder" button to see available placeholders
   - Paste your master HTML template (the one with all 31 clauses)
   - **Important:** Use double curly braces `{{PLACEHOLDER}}` format

4. **Available Placeholders:**
   - `{{LANDLORD_NAME}}` / `{{LANDLORD_NAME_AR}}`
   - `{{TENANT_NAME}}` / `{{TENANT_NAME_AR}}`
   - `{{PROPERTY_NAME}}` / `{{PROPERTY_NAME_AR}}`
   - `{{UNIT_NO}}` / `{{UNIT_NO_AR}}`
   - `{{START_DATE}}` / `{{START_DATE_AR}}`
   - `{{END_DATE}}` / `{{END_DATE_AR}}`
   - `{{ANNUAL_RENT}}` / `{{ANNUAL_RENT_AR}}`
   - `{{SECURITY_DEPOSIT}}` / `{{SECURITY_DEPOSIT_AR}}`
   - `{{EJARI_NUMBER}}`, `{{EJARI_DATE}}`, `{{EJARI_PROPERTY_CODE}}`
   - `{{LANDLORD_SIGNATURE}}`, `{{TENANT_SIGNATURE}}`, `{{COMPANY_STAMP}}`
   - `{{TODAY_DATE}}`

5. **Lock Legal Clauses (Optional):**
   - Clauses 1-31 should be marked with `data-locked="true"` or class `locked`
   - This prevents accidental editing (visual protection)

6. **Save Template:**
   - Check "Set as Default" if this is your main template
   - Click "Create Template"

---

### Step 2: Create/Edit a Lease

1. **Go to Leases:**
   - Navigate to: `http://localhost/herosysgro/modules/realestate/leases.php`
   - Click "New Lease" or edit an existing lease

2. **Fill in Lease Information:**
   - Select Unit and Tenant
   - Set dates and rent amounts
   - Configure payment installments

3. **Contract Details Section:**
   - **Select Template:** Choose your "2026 Tanacey Contract" template
   - **Ejari Information:**
     - Ejari Registration Number
     - Ejari Issue Date
     - Ejari Property Code
     - Upload Ejari Document (PDF) - optional
   - **Signatures & Stamps:**
     - Upload Landlord Signature (PNG/JPG)
     - Upload Tenant Signature (PNG/JPG)
     - Upload Company Stamp (PNG/JPG)

4. **Save the Lease**

---

### Step 3: Generate Contract PDF

1. **Go to Lease View:**
   - After saving, you'll be redirected to the lease view page
   - Or navigate to: `http://localhost/herosysgro/modules/realestate/lease_view.php?id=LEASE_ID`

2. **Generate Contract:**
   - Click "Generate Contract PDF" button
   - You'll be taken to the contract generation page

3. **Preview First (Optional):**
   - Click "Preview Contract" to see how it looks
   - Verify all placeholders are replaced correctly

4. **Generate PDF:**
   - Click "Generate Contract PDF" button
   - The system will:
     - Load your HTML template
     - Replace all placeholders with lease data
     - Insert signatures/stamps as base64 images
     - Generate PDF using wkhtmltopdf
     - Save to `uploads/contracts/`

5. **Download PDF:**
   - Once generated, click "Download PDF"
   - The PDF will be saved with name: `contract_LEASEID_TIMESTAMP.pdf`

---

## 🧪 Testing Checklist

- [ ] Template created and saved
- [ ] Template set as default
- [ ] Lease created with all required data
- [ ] Ejari information filled in
- [ ] Signatures uploaded (optional for testing)
- [ ] Contract preview works
- [ ] PDF generation successful
- [ ] PDF downloads correctly
- [ ] Arabic text renders properly
- [ ] Signatures appear in PDF (if uploaded)

---

## 🔍 Troubleshooting

### Issue: "Template not found"
- Make sure template is set as "Active"
- Check if template is set as "Default" or selected in lease

### Issue: "Placeholders not replaced"
- Verify placeholder format: `{{PLACEHOLDER}}` (double curly braces)
- Check that lease has all required data

### Issue: "PDF generation failed"
- Check PHP error logs
- Verify wkhtmltopdf is accessible: `which wkhtmltopdf`
- Check file permissions on `uploads/contracts/` directory

### Issue: "Arabic text not rendering"
- This should work automatically
- If issues persist, check font installation on server

### Issue: "Signatures not appearing"
- Verify signature images are uploaded
- Check file format (PNG/JPG)
- Ensure images are not corrupted

---

## 📝 Quick Reference

**Template Editor:**
- URL: `modules/realestate/lease_templates.php`
- Format: HTML Template (Advanced)
- Placeholders: Use `{{PLACEHOLDER}}` format

**Contract Generation:**
- URL: `modules/realestate/lease_contract_generate.php?lease_id=X`
- Preview: Available before generation
- Output: `uploads/contracts/contract_X_TIMESTAMP.pdf`

**Placeholder Format:**
- English: `{{LANDLORD_NAME}}`
- Arabic: `{{LANDLORD_NAME_AR}}`
- Dates: `{{START_DATE}}`, `{{END_DATE}}`, `{{TODAY_DATE}}`
- Financial: `{{ANNUAL_RENT}}`, `{{SECURITY_DEPOSIT}}`
- Ejari: `{{EJARI_NUMBER}}`, `{{EJARI_DATE}}`
- Signatures: `{{LANDLORD_SIGNATURE}}`, `{{TENANT_SIGNATURE}}`, `{{COMPANY_STAMP}}`

---

## 🎉 You're Ready!

Your contract template system is now fully set up and ready to use. Start by creating your HTML template with the master template you provided, then test it with a sample lease.

