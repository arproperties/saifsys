# Tenant Portal — Architecture & Design

**Document version:** 1.0  
**Scope:** Real Estate module only. Cleaning/internal users out of scope.  
**Stack:** PHP/MySQL, existing ERP auth and Real Estate schema.

---

## 1. Tenant Portal MVP — Feature List

| # | Feature | Description | Priority |
|---|--------|-------------|----------|
| 1 | **Registration & verification** | Controlled sign-up linked to existing lease; admin approval. | P0 |
| 2 | **Tenant dashboard** | Unit, lease summary (read-only), rent, dates, outstanding balance, notifications. | P0 |
| 3 | **Payments & financial (read-only + pay)** | View installments, cheques, penalties; download invoices/receipts; optional online pay. | P0 |
| 4 | **Extra services requests** | Request parking/storage/other; admin approves; charges created in ERP. | P1 |
| 5 | **Maintenance & service requests** | Create maintenance/cleaning/pest control; status tracking; visible in ERP. | P0 |
| 6 | **Documents** | View/download lease docs and notices; limited upload if allowed. | P0 |
| 7 | **Notifications** | Email (and optional SMS) to tenant and management for key events. | P0 |

---

## 2. Registration & Verification Flow

### 2.1 Allowed registration methods (recommended: **A + B**)

| Method | Description | Use case |
|--------|-------------|----------|
| **A. Lease number + registered email/phone** | Tenant enters lease number and email **or** phone that matches `re_tenants` / lease. System sends one-time verification link/code. After verification, tenant sets password. Account status = `pending_approval`. | Self-service, low admin effort. |
| **B. Admin invitation** | Admin (from Leases or Tenants) sends “Invite to portal” (email or SMS). Link contains signed token (lease_id + tenant_id + expiry). Tenant opens link, sets password. Account status = `pending_approval` or `approved` (configurable). | High control, preferred for corporate. |

**Recommendation:** Implement both. Default path: **A** for scale; **B** for selected tenants. Same backend: one tenant portal user per lease (or per tenant if one tenant has multiple leases — see 2.3).

### 2.2 Rules

- **No lease → no portal access.** Every tenant account is tied to at least one active (or recently expired) lease.
- **One tenant account per lease** (or one account per tenant with multiple leases — see 2.3).
- **Mandatory admin approval** after registration (configurable per company: auto-approve if invited by admin).
- **Verification:** Email and/or phone must match `re_tenants` (and optionally lease contact) to avoid hijack.

### 2.3 Registration flow (detailed)

```
[Tenant] Enter lease number + email OR phone
    → Validate against re_leases + re_tenants (lease_number, tenant email/phone)
    → If no match: "No lease found or contact mismatch. Contact management."
    → If match: Send verification email/SMS (link or code, e.g. 24h expiry)

[Tenant] Click link / enter code
    → Verify token; show "Set your password" form
    → Create user (user_type=tenant) + tenant_portal_account (tenant_id, lease_id, status=pending_approval)
    → Notify admin "Tenant X requested portal access for Lease Y"

[Admin] In ERP: Tenant Portal → Pending approvals
    → Approve or reject
    → On approve: status=approved; tenant can log in
    → Optional: Resend invite / revoke access
```

**Invitation flow (B):**

```
[Admin] Lease / Tenant → "Invite to portal"
    → Generate signed link (lease_id, tenant_id, expiry e.g. 7 days)
    → Send email/SMS to tenant

[Tenant] Opens link
    → If already has account: redirect to login
    → Else: Set password → create account (status=approved or pending per setting)
```

---

## 3. Database Impact (Reuse vs New Tables)

### 3.1 Reuse existing

| Existing table | Usage |
|----------------|--------|
| `user` | Store tenant portal users. Add `user_type` = `'tenant'` (or `'internal'`/`'tenant'`) and optionally `tenant_id` for quick lookup. |
| `user_companies` | Link tenant user to `company_id` (from lease) so company scoping stays consistent. |
| `user_roles` + `roles` | Add role e.g. `Tenant` (module = `realestate` or `tenant_portal`). One role per tenant user. |
| `re_tenants` | No structural change. Match by email/phone for registration. |
| `re_leases` | Source of lease_number, unit, dates, rent; filter all portal data by lease_id. |
| `re_lease_installments` | Read-only in portal; drive “pay” action via payment request. |
| `re_lease_cheques` | Read-only; show status (cleared/pending/bounced). |
| `re_payments` | Read-only; created only via ERP (or ERP-side handler for online pay). |
| `re_invoices` | Read-only; download PDF. |
| `re_maintenance_requests` | Create from portal (tenant_id, lease_id, unit_id); status visible in ERP. |
| `re_documents` | View/download by document_type and link to lease/unit; optional upload with policy. |

### 3.2 New tables

| Table | Purpose |
|-------|--------|
| `tenant_portal_accounts` | Links `user_id` (tenant) to `tenant_id`, `lease_id`, `company_id`; status (pending_approval, approved, suspended, revoked); approved_at, approved_by. One row per user-lease (or one per user if multi-lease). |
| `tenant_portal_invites` | Invitation tokens: lease_id, tenant_id, token_hash, expires_at, used_at, created_by. |
| `tenant_portal_verification_codes` | For method A: lease_id, email_or_phone, code_hash, expires_at, used_at. |
| `tenant_extra_service_requests` | Tenant-requested extra services (parking, storage, etc.): tenant_id, lease_id, service_type, description, status (pending, approved, rejected), approved_by, approved_at, billing_item_id or link to ERP charge. |
| `tenant_portal_audit_log` | Action log: user_id, lease_id, action, entity_type, entity_id, ip, created_at. |

### 3.3 Optional `user` table change

- Add column: `user_type` ENUM('internal','tenant') NOT NULL DEFAULT 'internal'.
- Or add `tenant_id` INT NULL; non-NULL implies tenant user (and can replace separate table for single-lease-per-user if you prefer).

Recommended: **add `user_type`** and keep **`tenant_portal_accounts`** for multi-lease support and approval state.

---

## 4. Permission Model

### 4.1 Tenant role

- Single role, e.g. **Tenant** (in `roles`: name = 'Tenant', module = 'realestate' or 'tenant_portal').
- All tenant users get this role only; no internal roles.

### 4.2 Data scope

- **Every query** in the portal is scoped by:
  - `lease_id` IN (SELECT lease_id FROM tenant_portal_accounts WHERE user_id = ? AND status = 'approved')
  - And by `company_id` from the same table (or from lease).
- Tenant sees **only** data for their approved lease(s): one lease for MVP; later multiple if you allow one account per tenant with many leases.

### 4.3 Allowed actions (summary)

| Area | Allowed | Not allowed |
|------|--------|-------------|
| Dashboard | View unit, lease, rent, dates, balance | Edit lease/unit |
| Installments / payments | View; create “payment request” or “pay” intent | Direct insert into re_payments; access accounting |
| Invoices / receipts | View, download | Edit, delete |
| Cheques | View status | Edit, delete |
| Extra services | Submit request | Approve; create charges |
| Maintenance | Create request; view own requests | Assign, close, edit others’ |
| Documents | View/download allowed docs; upload if policy allows | Delete; access other tenants’ docs |

### 4.4 Security

- Tenant role is **read-only by default**; write only where explicitly allowed (requests, optional uploads).
- All tenant actions go through **tenant_portal_audit_log**.
- No access to accounting internals (journal, GL, posting). Payment creates a **payment request** or triggers an ERP-controlled flow.

---

## 5. Integration Points

### 5.1 Leases

- **Read:** Lease summary, unit, tenant, start/end, rent, status. Source: `re_leases` + `re_units` + `re_buildings` + `re_tenants`.
- **Outstanding balance:** From installments and payments (existing logic in ERP); portal only displays.
- **No direct write** to `re_leases` from portal.

### 5.2 Payments & financial

- **Installments:** Read from `re_lease_installments` (filter by lease_id).
- **Payments:** Read from `re_payments`; display receipt/invoice links.
- **Cheques:** Read from `re_lease_cheques`; show status (cleared/pending/bounced).
- **Invoices:** Read from `re_invoices`; download PDF via existing or new endpoint.
- **Pay online:** If enabled, tenant action creates a **payment request** (new table or status in ERP). ERP (or payment gateway) creates `re_payments` and updates installments; accounting posting remains ERP-controlled.

### 5.3 Maintenance

- **Create request:** Insert into `re_maintenance_requests` with tenant_id, lease_id, unit_id, category, priority, description; status = pending.
- **List / track:** Select own requests by lease_id (and tenant_id). Same table as ERP; ERP Maintenance module sees all.
- **Categories:** Reuse existing category list (e.g. from `re_maintenance_requests` or config).

### 5.4 Extra services

- **Tenant:** Insert into `tenant_extra_service_requests` (tenant_id, lease_id, service_type, description).
- **Admin:** In ERP, approve/reject; on approve create billing/charge in ERP (e.g. `re_billing_items` or lease-level adjustment) and optionally update lease (e.g. additional parking flag).
- **Tenant:** Sees new charges in “Payments & financial” once created in ERP.

### 5.5 Documents

- **View/download:** From `re_documents` where entity linked to tenant’s lease/unit (and document_type in allowed list, e.g. lease contract, notice).
- **Upload:** If allowed, insert into `re_documents` with entity_type = e.g. tenant_upload, lease_id, restricted permissions.

### 5.6 Notifications

- Reuse existing `re_email_*` and notification helpers.
- Events: e.g. reminder sent, payment received, maintenance status change, extra service approved. Recipients: tenant (email/phone) and management (existing rules).

---

## 6. UI Page List (Screens)

### 6.1 Public (unauthenticated)

| Screen | Route / file | Purpose |
|--------|--------------|---------|
| Portal landing / login | e.g. `tenant_portal/login.php` | Branded landing; link to “Register” and “Log in”. |
| Register | `tenant_portal/register.php` | Lease number + email or phone; send verification. |
| Verify & set password | `tenant_portal/verify.php?token=...` | From email/SMS link; set password; show “Pending approval”. |
| Invitation accept | `tenant_portal/invite.php?token=...` | From admin invite link; set password; optional auto-approve. |

### 6.2 Authenticated tenant (all behind tenant auth + lease scope)

| Screen | Route / file | Purpose |
|--------|--------------|---------|
| Dashboard | `tenant_portal/dashboard.php` | Unit, lease summary, rent, dates, outstanding balance, recent activity, notifications. |
| Lease details | `tenant_portal/lease.php` | Read-only lease + unit details. |
| Installments & payments | `tenant_portal/payments.php` | List installments; paid/pending; link to receipts. |
| Invoices | `tenant_portal/invoices.php` | List invoices; download PDF. |
| Cheques | `tenant_portal/cheques.php` | List cheques; status. |
| Pay / payment request | `tenant_portal/pay.php` or in payments | Initiate pay or payment request (if enabled). |
| Extra services | `tenant_portal/extra_services.php` | List requests; form to submit new (parking, storage, other). |
| Maintenance requests | `tenant_portal/maintenance.php` | List own requests; form to create (category, priority, description). |
| Documents | `tenant_portal/documents.php` | List allowed docs; view/download; upload if allowed. |
| Notifications | `tenant_portal/notifications.php` | Optional: list of notifications (or only email). |
| Profile / password | `tenant_portal/profile.php` | Change password; optional contact preferences. |
| Logout | `tenant_portal/logout.php` | Clear session; redirect to portal login. |

### 6.3 ERP-side (admin)

| Screen | Location | Purpose |
|--------|----------|---------|
| Pending portal approvals | e.g. under Real Estate or Settings | List pending_approval accounts; approve/reject. |
| Invite tenant to portal | From lease or tenant detail | Send invite link (email/SMS). |
| (Optional) Portal users | Same area | List tenant users; revoke/suspend. |

---

## 7. Technical & Architectural Rules

- **Reuse `user` table:** Add `user_type` = 'tenant'; one role (Tenant); link to lease(s) via `tenant_portal_accounts`.
- **All data filtered by** lease_id (and company_id) derived from `tenant_portal_accounts` for current user.
- **Portal can be:**
  - **Option A:** Separate frontend under `/tenant_portal/` (or subdomain), tenant-only layout and menu.
  - **Option B:** Same ERP frontend with tenant layout and routing (e.g. `?portal=tenant` or role-based layout).  

Recommendation: **Option A** for clear separation and security (different entry URL, no ERP menu).

- **Auth:** Same session mechanism as ERP; after login, detect tenant user and redirect to portal dashboard. Tenant must not access `/modules/realestate/*` or other internal routes (enforced by role and route checks).
- **PHP/MySQL:** All new logic in PHP; queries parameterized (PDO); no raw SQL from frontend.
- **Secure by design:** CSRF on forms; rate limit login/register; audit log for sensitive actions; no bypass of ERP workflows.

---

## 8. File / Module Layout (suggested)

```
/tenant_portal/
  index.php              → redirect to dashboard or login
  login.php
  register.php
  verify.php
  invite.php
  logout.php
  dashboard.php
  lease.php
  payments.php
  invoices.php
  cheques.php
  pay.php
  extra_services.php
  maintenance.php
  documents.php
  notifications.php
  profile.php
  includes/
    tenant_auth.php      → require_tenant_login(), current_tenant_lease_id()
    tenant_layout_header.php
    tenant_layout_footer.php
  api/                   → optional AJAX endpoints (e.g. load installments, submit request)
```

ERP side:

- `modules/realestate/tenant_portal_approvals.php` (or under settings)
- Links from `lease_view.php` / tenant view: “Invite to portal”
- No change to existing lease/payment/maintenance logic except where they consume portal-created data (maintenance, extra service requests).

---

## 9. Migration Checklist (high level)

1. Add `user_type` to `user` (if not exists); add role `Tenant` in `roles`.
2. Create tables: `tenant_portal_accounts`, `tenant_portal_invites`, `tenant_portal_verification_codes`, `tenant_extra_service_requests`, `tenant_portal_audit_log`.
3. Implement registration (A + B) and approval flow.
4. Implement tenant login and session (redirect tenant users to portal).
5. Build portal screens with lease-scoped read/write as above.
6. Integrate payments (view + optional pay flow), maintenance, documents, extra services.
7. Add ERP screens: pending approvals, invite action.
8. Notifications: plug into existing email (and optional SMS) for tenant and management.

This design keeps the tenant portal **controlled**, **lease-centric**, and **ERP-integrated** without bypassing workflows or exposing accounting internals.
