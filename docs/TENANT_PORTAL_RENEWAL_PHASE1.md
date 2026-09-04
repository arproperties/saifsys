# Tenant Portal — Lease Renewal (Phase 1)

## Overview

Tenants use **Tenant Portal → Renewals** (`tenant_portal/renewals.php`) to view renewal notices, acknowledge receipt, respond (accept / negotiate / reject), upload documents, and (when ready) sign electronically. Actions sync to `re_lease_renewal_workflow` status and are audited in `re_renewal_portal_events`.

## Database

Run on each environment:

`migrations/tenant_portal_renewal_phase1.sql`

- Extends `re_lease_renewal_workflows.status` ENUM and adds portal/acknowledgement/decision/contract-ready columns.
- New tables: `re_renewal_portal_events`, `re_renewal_tenant_uploads`, `re_renewal_electronic_signatures` (one signature row per workflow).

**Negotiation thread (back-and-forth with tenant):**

`migrations/renewal_negotiation_thread.sql` → table `re_renewal_negotiation_messages` (tenant + staff messages). After migration, staff should use **Negotiation with tenant → Reply to tenant** on the workflow view; tenants see **Discussion with management** on the renewal detail page and can send further messages while status is **negotiation**, then **Accept renewal** or **Reject renewal** to finalize.

## Status transition rules (enforced in code)

- **Send notice:** `initiated` → `notice_sent`; resend keeps current status, updates `notice_sent_at` / `notice_sent_by`.
- **First portal open:** `notice_sent` → `viewed_by_tenant` (once).
- **Acknowledge:** only from `notice_sent`, `viewed_by_tenant`, `pending_response`, `negotiation` if not yet acknowledged (atomic UPDATE).
- **Tenant decision:** once only; allowed before `contract_ready` / `approved`; sets `accepted` / `negotiation` / `rejected`.
- **Mark contract ready (admin):** from `accepted`, `negotiation`, `approved`, `pending_response` only.
- **E-sign (tenant):** `contract_ready` → `signed` (unique signature per workflow; race-safe UPDATE).
- **Approve & convert (admin):** from `signed`, `approved`, or `accepted` only (atomic `WHERE status = ?`).
- **Admin reject:** not from `converted` / `rejected`.
- **Closed workflows:** no status changes from portal; admin response form blocked at `signed` / `contract_ready`; amounts locked after `signed`.

Admin actions that change workflow state are logged to `re_renewal_portal_events` with `legacy_user_id` = staff `user.id`.

## Status lifecycle (suggested)

| Status | Meaning |
|--------|---------|
| `notice_sent` | Notice emailed / PDF generated |
| `viewed_by_tenant` | Tenant opened the renewal detail (first view) |
| `acknowledged` | Tenant clicked **Acknowledge receipt** |
| `pending_response` | Awaiting tenant decision (optional intermediate) |
| `negotiation` | Tenant chose discussion / negotiation |
| `accepted` | Tenant accepted renewal terms |
| `rejected` | Tenant declined |
| `contract_ready` | Admin marked draft contract ready for tenant review/sign |
| `signed` | Tenant completed MVP e-sign |
| `completed` | Optional terminal state after signing (if used) |
| `converted` | Existing flow: lease converted after admin approval |

## Admin

- **Workflow view** (`modules/realestate/lease_renewal_workflow_view.php`): **Tenant portal** card shows milestones, uploads (mark reviewed), event log, e-sign summary.
- **Mark contract ready**: when draft lease exists, sets status to `contract_ready` so the tenant sees the contract preview and signing form.
- **Download tenant uploads**: `modules/realestate/renewal_admin_upload_download.php` (module + company check).

## Tenant URLs

- Inbox: `/tenant_portal/renewals.php`
- Detail (by workflow id): `/tenant_portal/renewal_detail.php?id={workflow_id}`

### Email deep links

Renewal notice emails (sent from **Renewal Workflow** view) replace these placeholders:

| Placeholder | Value |
|-------------|--------|
| `{RENEWAL_PORTAL_URL}` | Absolute URL to `renewal_detail.php?id={workflow_id}` |
| `{RENEWAL_PORTAL_LINK}` | HTML `<a>` pointing to the same URL |

**Production:** set `APP_BASE_URL` in `includes/config.php` (or environment) to your public site root, e.g. `https://yourdomain.com/herosysgro`. If unset, the URL is built from the current HTTP host and path (works when sending from the same server path tenants use).

Optional SQL to append portal text to **existing** DB templates: `migrations/renewal_notice_email_portal_deep_link.sql`.

Unauthenticated tenants hitting the link are redirected to portal login, then returned to the renewal detail page (`tenant_portal_next` session).

## E-sign MVP (internal)

- Typed full legal name + acceptance checkbox.
- Stored: tenant portal user id (and legacy id if applicable), timestamp, IP (and user-agent where implemented), workflow id.
- No third-party provider in Phase 1.

## Recommended sequence (admin → tenant)

1. **Send renewal notice** → tenant opens **Renewals** / email deep link, acknowledges, accepts (or negotiates).
2. **Mark contract ready** (admin) → tenant sees draft on renewal detail and can **e-sign** (typed name) if you use that step.
3. **Approve & convert** → system creates/links the **new active lease** (`new_lease_id`). The workflow row still stores the **original** `lease_id`.
4. **Generate tenancy contract** on the **new lease** in Real Estate (**Lease details → Generate contract**). That stores `re_leases.generated_contract_path`.
5. **Tenant Portal → Lease** shows **View / download PDF** via `tenant_portal/lease_download_contract.php` when a path exists. Optionally email the PDF from admin (`ajax_send_contract_email` / lease view).

**Important:** Portal e-sign applies to the **renewal workflow draft** (`contract_ready`), not automatically to the final generated tenancy PDF. Those are separate unless you always generate the PDF before “contract ready” and expose the same file.

### Visibility after convert

Renewal inbox and dashboard counts match workflows where **`lease_id` OR `new_lease_id`** equals the lease the tenant is viewing, so history still appears when the tenant switches to the converted lease.

## Signed tenancy contract (offline + PDF scan)

**Migration:** `migrations/tenancy_contract_tenant_scan_upload.sql` → table `re_tenancy_contract_tenant_uploads`.

1. Admin generates the official PDF → tenant downloads from **Lease**.
2. Tenant prints, signs, scans to **one PDF**, uploads on **Lease** (instructions are on that page).
3. Admin sees the file in **Lease → Documents** as **Tenant signed tenancy scan** (Portal badge); prints for landlord signature.
4. Admin uploads the **final fully executed** contract via **Upload Document** (e.g. type **Lease Agreement**). That row appears in **Tenant Portal → Documents** like any other `re_documents` lease file.

## Audit

All significant portal actions should log to `re_renewal_portal_events` with actor and request metadata. Signed actions also persist in workflow columns and/or `re_renewal_electronic_signatures`.
