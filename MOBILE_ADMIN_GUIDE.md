# Mobile App Admin Dashboard - Quick Guide

## 🎯 Access the Admin Panel

**URL:** `http://localhost/herosys/mobile_app_admin.php`

Or: `http://192.168.70.112/herosys/mobile_app_admin.php`

**Requirements:** You must be logged in to your HeroSys system with admin permissions.

---

## 📱 What You Can Manage

### 1. **Categories** 📁
- Create main categories (shown on home screen)
- Create sub-categories under main categories
- Set icons (use emojis)
- Control which categories appear on the mobile app home screen
- Reorder categories with sort order

**Example Structure:**
```
🧹 Cleaning (Main - Show on Home)
  ├── 🏠 Home Cleaning
  ├── 🛋️ Furniture Cleaning
  └── ✨ Home Deep Cleaning

🐛 Pest Control (Main - Show on Home)
  ├── 🦟 Pest Control
  └── 💧 Disinfection
```

### 2. **Services** 🔧
- Add services under any category
- Configure pricing:
  - **Display Price:** What customers see
  - **Base Price:** Starting price
  - **Price Per Unit:** Additional cost per hour/sqm/room
  - **Pricing Rule:** How price is calculated
- Set duration, min/max hours, professionals
- Enable/disable materials requirement
- Allow frequency discounts

**Pricing Examples:**
- **Hourly Service:** Home Cleaning
  - Base: 80 AED
  - Per Hour/Professional: 40 AED
  - 2 hours × 1 professional = 80 + (2 × 1 × 40) = 160 AED

- **Fixed Service:** Pest Control
  - Fixed Price: 250 AED (no matter the duration)

### 3. **Banners** 🎨
- Create promotional banners for app home screen
- Set banner image URL (800x300px recommended)
- Schedule banners with start/end dates
- Reorder with sort order
- Toggle active/inactive

### 4. **Pricing Rules** 💰
- View available pricing calculation methods
- Reference guide for how pricing works
- Use these when creating services

### 5. **Frequency Discounts** 📅
- Configure discounts for recurring bookings:
  - One Time: 0%
  - Every Two Weeks: 5%
  - Once a Week: 10%
  - Multiple Times a Week: 25%
- Toggle active/inactive
- Use the calculator to preview discounts

---

## 🚀 Quick Start Guide

### Step 1: Set Up Categories
1. Go to **Categories** tab
2. Create your main categories:
   - Name: "Cleaning"
   - Icon: 🧹
   - Check "Show on Home Screen"
   - Click Create
3. Create sub-categories:
   - Select parent category
   - Add sub-category name
   - Click Create

### Step 2: Add Services
1. Go to **Services** tab
2. Fill in service details:
   - Select Category
   - Service Name & Description
   - Set Display Price and Base Price
   - Choose Pricing Rule
   - Configure hours, professionals, etc.
3. Check options:
   - ✅ Requires Materials
   - ✅ Allows Frequency Discounts
4. Click Create Service

### Step 3: Create Banners
1. Go to **Banners** tab
2. Add promotional banner:
   - Title: "Welcome Offer"
   - Description: "Get 20% off!"
   - Image URL: (your banner image)
   - Set dates (optional)
3. Click Create Banner

### Step 4: Adjust Discounts
1. Go to **Frequency Discounts** tab
2. Update discount percentages
3. Enable/disable frequency options
4. Save changes

---

## 💡 Tips & Best Practices

### Categories:
- ✅ Keep main categories to 2-4 items for better UX
- ✅ Use clear, simple names
- ✅ Add emojis as icons for visual appeal
- ✅ Only set "Show on Home" for main categories

### Services:
- ✅ Write clear descriptions (customers see these)
- ✅ Use realistic pricing
- ✅ Set appropriate min/max hours
- ✅ Enable frequency discounts for recurring services
- ✅ Mark services that need materials

### Banners:
- ✅ Use high-quality images (800x300px)
- ✅ Keep text short and clear
- ✅ Schedule seasonal promotions
- ✅ Test images before publishing

### Pricing:
- ✅ Be transparent with customers
- ✅ Use hourly rates for flexible services
- ✅ Use fixed prices for standard services
- ✅ Offer good discounts for recurring bookings

---

## 📊 Understanding Pricing Calculations

### Hourly Rate Example:
```
Service: Home Cleaning
Base Price: 80 AED
Price Per Unit: 40 AED/hour/professional

Customer books:
- 3 hours
- 2 professionals
- Weekly frequency (10% discount)

Calculation:
1. Base: 80 AED
2. Hours & Workers: 3 × 2 × 40 = 240 AED
3. Subtotal: 80 + 240 = 320 AED
4. Weekly Discount: 320 × 10% = 32 AED
5. Final Price: 320 - 32 = 288 AED
```

### Fixed Price Example:
```
Service: Pest Control
Fixed Price: 250 AED

Calculation:
- Always 250 AED (no variations)
- Frequency discounts still apply if enabled
```

---

## 🔒 Security Notes

- Admin panel requires login + admin permissions
- All changes are logged in database
- Only active items appear in the mobile app
- You can safely deactivate items without deleting

---

## 📱 Testing Your Changes

1. Make changes in admin panel
2. Open your Flutter app
3. Pull to refresh on home screen
4. Changes appear immediately (no app restart needed!)

---

## 🆘 Common Issues

**Q: Changes don't appear in app?**
- Pull to refresh in the app
- Check if item is marked "Active"
- Verify category has services

**Q: Can't delete category?**
- Must delete all sub-categories first
- Or deactivate instead of deleting

**Q: Service price doesn't calculate correctly?**
- Check pricing rule matches your setup
- Verify base price and price per unit are set
- Test with the discount calculator

---

## 🎉 You're All Set!

Your mobile app admin dashboard is ready to use. Start by:
1. ✅ Reviewing existing categories
2. ✅ Adding/editing services
3. ✅ Creating promotional banners
4. ✅ Testing the mobile app

For advanced features or customization, check the main `ENHANCEMENT_GUIDE.md`.

**Happy Managing! 🚀**

