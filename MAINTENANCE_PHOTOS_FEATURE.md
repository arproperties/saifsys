# Maintenance Request Photo Upload Feature

## 📸 Overview

The Maintenance Request system now supports photo uploads as evidence of completed work. Employees and managers can upload photos at any stage of the maintenance workflow (before, during, after, or completion).

---

## ✨ Features

### **Photo Upload**
- ✅ Upload photos when completing maintenance work
- ✅ Support for multiple photo types:
  - **Before Work** - Photos of the issue before repair
  - **During Work** - Photos showing work in progress
  - **After Work** - Photos after repair is done
  - **Completion** - Final completion evidence (default)
- ✅ Photo descriptions for context
- ✅ Image preview and gallery display
- ✅ Delete photos if needed

### **Photo Management**
- ✅ View all photos in a gallery on the maintenance request page
- ✅ Click photos to view full size
- ✅ See photo type badges (Before, During, After, Completion)
- ✅ View upload date and description
- ✅ Delete photos (with confirmation)

---

## 🗄️ Database

### **Table: `re_maintenance_photos`**

```sql
CREATE TABLE IF NOT EXISTS `re_maintenance_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `maintenance_request_id` INT(11) NOT NULL,
  `photo_type` ENUM('before', 'during', 'after', 'completion') NOT NULL DEFAULT 'completion',
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  ...
);
```

**Migration File:** `migrations/create_re_maintenance_photos_table.sql`

---

## 📁 File Storage

### **Directory Structure:**
```
uploads/
└── realestate/
    └── maintenance/
        └── {maintenance_request_id}/
            ├── photo1_timestamp_random.jpg
            ├── photo2_timestamp_random.png
            └── ...
```

### **File Naming:**
- Format: `{original_name}_{timestamp}_{random}.{extension}`
- Example: `repair_work_1704123456_a1b2c3d4.jpg`
- Sanitized to remove special characters

---

## 🔧 Technical Implementation

### **AJAX Endpoints:**

1. **Upload Photo:** `ajax_maintenance_photo_upload.php`
   - Accepts: `photo` (file), `maintenance_request_id`, `photo_type`, `description`
   - Returns: JSON with photo details
   - Validates: File type (images only), file size (max 10MB), MIME type

2. **Get Photos:** `ajax_get_maintenance_photos.php`
   - Accepts: `maintenance_request_id` (GET parameter)
   - Returns: JSON array of photos

3. **Delete Photo:** `ajax_delete_maintenance_photo.php`
   - Accepts: `photo_id` (POST)
   - Deletes file from disk and database record

### **File Validation:**
- **Allowed Types:** JPG, JPEG, PNG, GIF, WEBP
- **Max Size:** 10MB per photo
- **MIME Type Check:** Validates actual file type (not just extension)

---

## 📱 User Interface

### **Photo Section on Maintenance View Page:**
- Located below the Notes section
- Shows "Upload Photo" button (when request is not completed/cancelled)
- Displays photos in a responsive grid (3 columns on desktop, 2 on tablet, 1 on mobile)
- Each photo card shows:
  - Thumbnail (clickable to view full size)
  - Photo type badge
  - Original filename
  - Description (if provided)
  - Upload date and time
  - Delete button

### **Upload Photo Modal:**
- Accessible via "Upload Photo" button
- Fields:
  - **Photo Type** (dropdown: Before, During, After, Completion)
  - **Photo** (file input, accepts images only)
  - **Description** (optional textarea)
- Progress bar during upload
- Success/error messages

---

## 🚀 Usage Guide

### **For Employees:**

1. **Complete Maintenance Work**
   - Finish the repair/maintenance task
   - Take photos as evidence

2. **Upload Photos**
   - Go to the maintenance request view page
   - Click "Upload Photo" button
   - Select photo type (usually "Completion")
   - Choose photo file
   - Add description (optional)
   - Click "Upload Photo"

3. **Update Status to Completed**
   - Click "Update Status" button
   - Select "Completed"
   - Add completion notes
   - Enter actual cost (if different)
   - Submit

### **For Managers:**

1. **Review Completion Evidence**
   - View maintenance request
   - Scroll to "Completion Photos" section
   - Review all uploaded photos
   - Click photos to view full size
   - Verify work completion

2. **Manage Photos**
   - Delete incorrect or duplicate photos
   - View photo descriptions for context

---

## 🔒 Security & Permissions

- ✅ **Authentication Required:** Only logged-in users can upload/view photos
- ✅ **Module Access:** Requires Real Estate module access
- ✅ **Company Filtering:** Users can only view/upload photos for their company's requests
- ✅ **File Validation:** Strict file type and size validation
- ✅ **Path Traversal Protection:** Sanitized file paths
- ✅ **CSRF Protection:** Status updates protected (photo uploads use session auth)

---

## 📊 Photo Types Explained

| Type | When to Use | Purpose |
|------|-------------|---------|
| **Before** | Before starting work | Document the issue/problem |
| **During** | While working | Show work in progress |
| **After** | After repair | Show immediate result |
| **Completion** | Final completion | Final evidence of completed work |

---

## 🎯 Best Practices

### **When Uploading Photos:**
- ✅ Take clear, well-lit photos
- ✅ Include multiple angles if needed
- ✅ Add descriptive text in the description field
- ✅ Use "Completion" type for final evidence
- ✅ Upload photos immediately after completing work

### **Photo Quality:**
- ✅ Use good lighting
- ✅ Focus on the repaired area
- ✅ Include context (show the whole unit/area)
- ✅ Take before/after photos for comparison

---

## 🐛 Troubleshooting

### **Photo Not Uploading:**
1. Check file size (must be under 10MB)
2. Verify file type (JPG, PNG, GIF, WEBP only)
3. Check upload directory permissions (`uploads/realestate/maintenance/`)
4. Check browser console for errors

### **Photos Not Displaying:**
1. Verify file exists in upload directory
2. Check file path in database
3. Verify directory permissions (should be 777 or 755)
4. Check browser console for 404 errors

### **Permission Errors:**
```bash
# Fix upload directory permissions
chmod -R 777 uploads/realestate/maintenance/
```

---

## 📝 Database Migration

To enable this feature, run the migration:

```bash
mysql -u root herosysgro < migrations/create_re_maintenance_photos_table.sql
```

Or manually execute the SQL in `migrations/create_re_maintenance_photos_table.sql`

---

## ✅ Feature Status

**Status:** ✅ **Complete and Ready**

- ✅ Database table created
- ✅ Upload functionality implemented
- ✅ Photo gallery display
- ✅ Delete functionality
- ✅ File validation
- ✅ UI/UX complete
- ✅ Security implemented

---

## 🔮 Future Enhancements

Potential improvements:
- Bulk photo upload (multiple photos at once)
- Photo compression/optimization
- Photo annotations/markup
- Photo comparison slider (before/after)
- Photo download as ZIP
- Photo sharing via email
- Mobile app photo upload

---

**The maintenance photo upload feature is now fully functional!** 📸✨

