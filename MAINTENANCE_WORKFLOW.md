# Maintenance Request Workflow - Complete Guide

## 📋 Overview

The Real Estate Maintenance Request system provides a complete workflow from request creation to completion, with email notifications at key stages.

---

## 🔄 Complete Workflow Steps

### **Step 1: Request Creation** ✅
**Who:** Building Admin, Property Manager, or Tenant (via admin)

**Action:**
- Navigate to: `Maintenance` → `New Request`
- Fill in:
  - Unit (required)
  - Tenant (optional)
  - Request Date
  - Priority (Low, Medium, High, Urgent)
  - Category (e.g., Plumbing, Electrical, HVAC)
  - Description (required)
  - Estimated Cost (optional)
  - Notes (optional)

**System Action:**
- Request is created with status: **`pending`**
- Email notification is sent to **Maintenance Manager** (configured in Settings → Real Estate Email Notifications)
- Email includes:
  - Request details
  - Unit and tenant information
  - Direct link to Maintenance Queue

**Next:** Request appears in Maintenance Queue for assignment

---

### **Step 2: Assignment** ✅
**Who:** Maintenance Manager, Owner, or Admin

**Action:**
- Navigate to: `Maintenance` → `Maintenance Queue`
- Click **"Assign"** on a pending request
- Fill in assignment details:
  - Select Team Member (required)
  - Priority (can be updated)
  - Category (can be updated)
  - Description (can be updated)
  - Estimated Cost (can be updated)
  - Notes (optional)
- Click **"Assign & Start"**

**System Action:**
- Request status changes to: **`in_progress`**
- Request is assigned to selected team member
- **Email notification is sent to the assigned employee** with:
  - Request ID and priority
  - Unit location
  - Tenant contact information
  - Category and description
  - Notes and estimated cost
  - Direct link to view request details

**Next:** Assigned employee receives email and can view the request

---

### **Step 3: Work in Progress** ✅
**Who:** Assigned Employee or Manager

**Action:**
- Employee receives assignment email
- Clicks link to view request details
- Reviews all information
- Performs maintenance work

**System Action:**
- Request remains in **`in_progress`** status
- All details are visible in the request view page

**Next:** Employee completes work and updates status

---

### **Step 4: Status Update & Completion** ✅ (NEW)
**Who:** Assigned Employee, Manager, or Admin

**Action:**
- Navigate to: `Maintenance` → View Request (click "View" on any request)
- Click **"Update Status"** button
- Select new status:
  - **In Progress** - Continue working
  - **Completed** - Work is finished
  - **Cancelled** - Request is cancelled
- If marking as **Completed**:
  - Enter **Actual Cost** (optional - updates the cost if different from estimated)
  - Add **Completion Notes** (optional - describes work done, materials used, etc.)
- Click **"Update Status"**

**System Action:**
- Status is updated in database
- If completed:
  - `completed_at` timestamp is set
  - Actual cost is saved (if provided)
  - Completion notes are appended to request notes with timestamp
- Success message is displayed

**Next:** Request is marked as completed and appears in completed requests list

---

## 📊 Request Statuses

| Status | Description | Can Change To |
|--------|-------------|---------------|
| **Pending** | Request created, awaiting assignment | In Progress, Cancelled |
| **In Progress** | Assigned to employee, work ongoing | Completed, Cancelled |
| **Completed** | Work finished, request closed | (Final status) |
| **Cancelled** | Request cancelled, not completed | (Final status) |

---

## 📧 Email Notifications

### **1. New Request Notification**
- **Recipient:** Maintenance Manager (configured in Settings)
- **Trigger:** When a new maintenance request is created
- **Content:** Request details, unit, tenant, priority, description
- **Action Link:** View in Maintenance Queue

### **2. Assignment Notification** (NEW)
- **Recipient:** Assigned Employee
- **Trigger:** When a request is assigned to a team member
- **Content:** 
  - Request ID and priority
  - Unit location and building
  - Tenant contact information
  - Category, description, notes
  - Estimated cost
- **Action Link:** View Request Details

---

## 🎯 Key Features

### **For Managers:**
- ✅ Create maintenance requests
- ✅ View all requests in Maintenance Queue
- ✅ Assign requests to team members
- ✅ Update request details (priority, category, description, cost)
- ✅ Update request status
- ✅ Track completion and costs

### **For Employees:**
- ✅ Receive email notification when assigned
- ✅ View assigned request details
- ✅ Update request status (In Progress, Completed, Cancelled)
- ✅ Add completion notes
- ✅ Update actual cost

### **For All Users:**
- ✅ View maintenance request history
- ✅ Filter by status (All, Pending, In Progress, Completed, Cancelled)
- ✅ See completion dates and notes
- ✅ Track costs (estimated vs actual)

---

## 📍 Navigation Paths

### **Create Request:**
```
Dashboard → Maintenance → New Request
```

### **View Queue (Managers):**
```
Dashboard → Maintenance → Maintenance Queue
```

### **View All Requests:**
```
Dashboard → Maintenance → (All Maintenance Requests)
```

### **View Specific Request:**
```
Any request list → Click "View" button
```

### **Update Status:**
```
Request View Page → "Update Status" button → Select status → Submit
```

---

## 🔧 Configuration

### **Email Settings:**
1. Go to: `Settings` → `Real Estate Email Notifications` tab
2. Configure recipient email for:
   - **Maintenance Request** notifications (sent to manager when new request is created)
3. Email sender settings are configured in: `Settings` → `Email Configuration` tab

---

## 📝 Best Practices

### **When Creating Requests:**
- ✅ Provide clear, detailed descriptions
- ✅ Set appropriate priority (Urgent for emergencies)
- ✅ Include tenant contact information if available
- ✅ Add estimated cost if known

### **When Assigning:**
- ✅ Select appropriate team member based on category
- ✅ Update priority if needed
- ✅ Add any additional notes or instructions
- ✅ Verify estimated cost is reasonable

### **When Completing:**
- ✅ Add completion notes describing work done
- ✅ Update actual cost if different from estimated
- ✅ Mark as completed only when work is fully done
- ✅ Include details about materials used or issues encountered

---

## 🚀 Next Steps (Future Enhancements)

Potential future improvements:
- Photo/video attachments for requests
- Work order generation
- SLA tracking (time to completion)
- Preventive maintenance scheduling
- Vendor/contractor management
- Cost reporting and analytics
- Mobile app for field workers

---

## ✅ Current Status

**Phase 1 Complete:**
- ✅ Request creation
- ✅ Email notification to manager
- ✅ Assignment workflow
- ✅ Email notification to employee
- ✅ Status update functionality
- ✅ Completion workflow with notes and cost tracking

**The maintenance workflow is now fully functional!** 🎉

