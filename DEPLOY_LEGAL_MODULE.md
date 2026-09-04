# Legal Department Module — Live Server Deployment Guide

Upload **everything below in one deployment** (Phase 1 + Phase 2 + standalone module restructure).

## 1. Database (run in this order)

```bash
mysql -u USER -p DATABASE < migrations/re_legal_module_phase1.sql
mysql -u USER -p DATABASE < migrations/re_document_types_seed.sql
mysql -u USER -p DATABASE < migrations/re_legal_module_phase2.sql
```

| File | Purpose |
|------|---------|
| `migrations/re_legal_module_phase1.sql` | Cases, links, events, notices, owners, document enum |
| `migrations/re_document_types_seed.sql` | Legal document types (Court Filing, Judgment, etc.) |
| `migrations/re_legal_module_phase2.sql` | Hearings, counsel, costs + RBAC migration to standalone module |

## 2. New folder — upload entire `modules/legal/`

```
modules/legal/
├── index.php
├── ajax_legal_link_options.php
├── assets/
│   └── legal.css
├── includes/
│   ├── legal_helper.php
│   ├── legal_notice_pdf_generator.php
│   ├── legal_layout_header.php
│   ├── legal_layout_footer.php
│   └── legal_counsel_form.php
├── legal_dashboard.php
├── legal_cases.php
├── legal_case_add.php
├── legal_case_edit.php
├── legal_case_view.php
├── legal_notices.php
├── legal_notice_add.php
├── legal_notice_view.php
├── legal_notice_pdf.php
├── legal_calendar.php
├── legal_hearing_add.php
├── legal_billing_cheques.php
├── legal_documents.php
├── legal_counsel.php
├── legal_counsel_add.php
├── legal_counsel_edit.php
└── legal_reports.php
```

## 3. Writable upload directories (create on server)

```
uploads/legal_notices/          (chmod 775 or 777)
uploads/temp/legal_notices/     (fallback for PDF temp)
uploads/temp/mpdf/              (if not already present)
```

## 4. Core system files (modified)

```
includes/module_access.php      — MODULE_LEGAL, routing, company filter
includes/rbac_department.php      — DEPT_LEGAL, standalone module registry
settings.php                      — Legal Module checkbox group in role settings
```

## 5. Real Estate integration files (modified)

```
modules/realestate/billing_cheques.php          — Escalate / Legal Case buttons → ../legal/; legal layout mode
modules/realestate/billing_cheque_view.php      — Legal team read access
modules/realestate/documents.php                — Legal layout mode + legal_case filter
modules/realestate/documents_upload.php         — legal_case related type; legal team upload + return redirect
modules/realestate/documents_view.php           — Legal team read access
modules/realestate/documents_file.php           — Legal team read access
modules/realestate/documents_tags.php           — Legal team read access
modules/realestate/ajax_get_related_items.php   — legal_case option
modules/realestate/includes/re_layout_header.php — Legal nav removed (now standalone)
```

## 5b. Legal module files (modified for PDC + Documents)

```
modules/legal/includes/legal_layout_header.php  — Nav: Post-Dated Cheques, Documents
modules/legal/legal_dashboard.php               — Quick links to PDC + Documents
modules/legal/legal_case_view.php               — Link to legal_documents.php for case
```

## 6. Files to DELETE on live (if previously uploaded under realestate)

```
modules/realestate/legal_dashboard.php
modules/realestate/legal_cases.php
modules/realestate/legal_case_add.php
modules/realestate/legal_case_edit.php
modules/realestate/legal_case_view.php
modules/realestate/legal_notices.php
modules/realestate/legal_notice_add.php
modules/realestate/legal_notice_view.php
modules/realestate/legal_notice_pdf.php
modules/realestate/ajax_legal_link_options.php
modules/realestate/includes/legal_helper.php
modules/realestate/includes/legal_notice_pdf_generator.php
```

## 7. User setup (Settings → Roles)

1. Create or edit a **Legal Team** role.
2. Under **Legal Module**, check **Legal Department** only (do NOT assign Real Estate departments).
3. Assign the role to legal staff users for the Real Estate company.
4. On login, users with only the Legal module land directly on `/modules/legal/legal_dashboard.php` and see **only** the Legal sidebar.

Owners/Admins still see all modules via Switch Module.

## 8. Post-deploy smoke test

- [ ] Login as legal user → lands on Legal Dashboard (not Real Estate)
- [ ] Create a case, add a notice, generate PDF
- [ ] Schedule a hearing on Calendar
- [ ] Add external counsel
- [ ] Record a cost on a case
- [ ] Open Reports page
- [ ] From Post-Dated Cheques (as user with legal access): Escalate bounced cheque → pre-filled case form
- [ ] Legal sidebar → **Post-Dated Cheques**: filter, view cheque, open lease/tenant documents, escalate/create case
- [ ] Legal sidebar → **Documents**: browse legal case + lease + tenant documents; upload returns to Legal module
