# Unified customer mobile app — MVP plan and API specification

**Status:** Approved (2026). This single document locks MVP scope, Flutter stack decisions, and the **API contract for Phases A, B, C, and D-light**.  
**Implementation:** PHP API and Flutter app follow this spec; keep responses aligned as routes are built.

---

## Part 0 — Locked decisions

### 0.1 Authentication UX (mobile)

- **Backend:** Keep **two** auth stacks for MVP (no merge of identity stores).
  - **Tenant (long-term):** `tenant_portal_users` (+ optional legacy `tenant_portal_accounts` / main `user`) as today in [tenant_portal/includes/tenant_auth.php](../tenant_portal/includes/tenant_auth.php).
  - **ARS guest:** `portal_users` + `ars_guests` as today in [stay/includes/portal_auth.php](../stay/includes/portal_auth.php).
- **Mobile app:** One **welcome** screen with two explicit choices:
  1. **I have a lease** → tenant login + tenant API context.
  2. **I want to book / manage a stay** → public browse and/or guest login + ARS API context.

### 0.2 Flutter stack (MVP)

| Layer | Choice |
|--------|--------|
| Routing | **go_router** |
| HTTP | **dio** |
| State | **Riverpod** |

### 0.3 MVP phases included in this spec

| Phase | Name | In MVP spec |
|-------|------|-------------|
| **A** | API foundation (versioning, auth tokens, errors) | Yes |
| **B** | Public ARS browsing (list, search, unit, quote, settings) | Yes |
| **C** | Guest auth + booking flow | Yes |
| **D-light** | Tenant auth + dashboard + payments + invoices (read-only) | Yes |

### 0.4 Explicitly NOT in MVP (defer)

Do **not** implement in the same MVP delivery as above:

- Maintenance, cleaning, pest, extra services  
- Renewals, document uploads, move-out  
- Stripe / online card payments  
- Push notifications  
- Cheques (unless added later as D-light+)  
- **Invoice PDF download is out of MVP** — invoices are **list-only** in D-light (no `.../invoices/{id}/pdf` in this delivery).

### 0.5 Product / API locks (pre-implementation, locked)

These choices override earlier “optional” wording elsewhere in the document:

1. **Guest booking creation (mobile MVP):** `POST /stay/bookings` **requires a valid guest Bearer token**. The app does **not** support anonymous booking creation; guests must register or log in (Phase C) before creating a booking.
2. **Tenant lease scope:** Tenant routes that need lease context **must** send header **`X-Tenant-Lease-Id`** with the active `re_leases.id`. The server validates it on every request (JWT is not sufficient alone).
3. **Invoice PDFs:** **Not in MVP** — invoices endpoint returns metadata only; **no PDF download** route in v1 MVP.
4. **Implementation order:** Ship **Phase A + Phase B** first (`health`, auth foundation, `stay/settings`, `stay/buildings`, `stay/units`, `stay/units/{id}`, `stay/units/{id}/quote`), then **Phase C**, then **D-light**. Keep the live API aligned with this document as each phase lands.

---

## Part 1 — Architecture summary (reference)

### 1.1 Existing portals (read-only context)

| Portal | Path | Auth | Core data |
|--------|------|------|-----------|
| Tenant | [tenant_portal/](../tenant_portal/) | Session: `tenant_portal_user_id` or legacy approved `tenant_portal_accounts` | `re_leases`, `re_lease_installments`, `re_payments`, `re_invoices`, … |
| ARS stay | [stay/](../stay/) | Session: `portal_user_id` + `portal_guest_id` for guests; public pages need no login | `re_units` (listed short-term), `ars_unit_photos`, `ars_bookings`, `ars_booking_payments`, [modules/ars/includes/*.php](../modules/ars/includes/) |

### 1.2 Existing JSON (reuse reference)

- [stay/ajax_check_availability.php](../stay/ajax_check_availability.php) — GET, JSON quote + availability (no auth). Logic: `ars_check_availability`, `ars_validate_minimum_stay`, `ars_calculate_booking_price_v2`, promo validation.

### 1.3 Wrapper strategy (implementation note for later)

Introduce a **thin** API layer (e.g. `api/customer/v1/` or `api/v1/customer/`) that:

- Validates JWT (or access token) and maps to tenant or guest context.
- Calls **existing** includes: [modules/ars/includes/ars_availability.php](../modules/ars/includes/ars_availability.php), [ars_pricing.php](../modules/ars/includes/ars_pricing.php), [ars_helpers.php](../modules/ars/includes/ars_helpers.php) (`getArsCompanyId`, `getArsSettings`, `expirePendingBookings`, etc.).
- Reuses tenant login SQL from [tenant_portal/login.php](../tenant_portal/login.php) and lease loading from [tenant_portal/includes/tenant_lease_loader.php](../tenant_portal/includes/tenant_lease_loader.php) / [tenant_auth.php](../tenant_portal/includes/tenant_auth.php).
- Does **not** duplicate booking INSERT logic — extract from [stay/book.php](../stay/book.php) into a callable service when implementing Phase C.

---

## Part 2 — Global API conventions (Phase A)

### 2.1 Base URL and versioning

- **Prefix:** `/api/customer/v1` (example; adjust to match deployment base path, e.g. `/herosysgro/api/customer/v1`).
- **Version:** `v1` in path; breaking changes → `v2`.

### 2.2 Content type and encoding

- **Request:** `application/json` for bodies (except future multipart).
- **Response:** `application/json; charset=utf-8`.

### 2.3 Standard success envelope

All successful JSON responses:

```json
{
  "ok": true,
  "data": { }
}
```

`data` may be an object, array, or null.

### 2.4 Standard error envelope

```json
{
  "ok": false,
  "error": {
    "code": "STRING_MACHINE_CODE",
    "message": "Human-readable English message",
    "details": { }
  }
}
```

**Suggested HTTP status mapping**

| HTTP | When |
|------|------|
| 400 | Validation / bad parameters |
| 401 | Missing or invalid access token |
| 403 | Valid token but not allowed for resource |
| 404 | Resource not found |
| 409 | Conflict (e.g. dates no longer available) |
| 422 | Business rule failure (wrapped in body) |
| 429 | Rate limit |
| 500 | Server error |

### 2.5 Authentication (access + refresh)

- **Mechanism:** HTTP `Authorization: Bearer <access_token>`.
- **Access token:** JWT (recommended) or opaque server-stored token; short TTL (e.g. 15–60 minutes).
- **Refresh token:** Longer TTL; sent only to `POST /auth/refresh` (body or httpOnly cookie — **prefer httpOnly cookie for refresh** on web; for Flutter **secure storage** for refresh token is acceptable).

**Claims (JWT payload) — minimum**

| Claim | Tenant | Guest (ARS) |
|-------|--------|--------------|
| `typ` | `tenant` | `guest` |
| `sub` | `tpu:{tenant_portal_users.id}` or legacy user id strategy | `pu:{portal_users.id}` |
| `cid` | `company_id` (lease company) | ARS `company_id` |
| `lease_ids` | array of allowed `re_leases.id` (optional; can load server-side) | omit |
| `lease_id` | current active lease id (optional) | omit |
| `gid` | omit | `ars_guests.id` |

Server must **always** re-check lease/booking ownership on each request; do not trust client-only IDs without DB check.

### 2.6 Public vs authenticated routes

| Route class | Authorization |
|-------------|----------------|
| Phase B public stay | None, optional `X-Client-Id` for rate limit |
| Phase C guest booking | Guest Bearer after login |
| Phase D-light tenant | Tenant Bearer after login; lease-scoped routes also require **`X-Tenant-Lease-Id`** (see §0.5) |

### 2.7 CSRF

- Mobile JSON API: **no** browser session CSRF; rely on Bearer + HTTPS.
- Existing PHP portals keep their CSRF for HTML forms unchanged.

### 2.8 Rate limiting (recommended)

- Stricter limits on: `POST /auth/guest/login`, `POST /auth/tenant/login`, `POST /auth/guest/register`, `GET /stay/units/*/quote`, `POST /stay/bookings`.

---

## Part 3 — Phase A: API foundation (endpoints)

### A.1 Health

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/customer/v1/health` | Public | Returns `{ "ok": true, "data": { "version": "v1" } }` |

### A.2 Token lifecycle

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/api/customer/v1/auth/refresh` | Refresh token (body or cookie per implementation) | Returns new access (+ optionally new refresh). |
| POST | `/api/customer/v1/auth/logout` | Bearer or refresh | Invalidate refresh token server-side if using revocation table. |

**POST `/auth/refresh` — request (example)**

```json
{
  "refresh_token": "<string>"
}
```

**Response `data`**

```json
{
  "access_token": "<jwt>",
  "expires_in": 3600,
  "token_type": "Bearer"
}
```

**Dependencies:** New token service + optional `customer_refresh_tokens` table (recommended) or stateless JWT-only with short access TTL.

---

## Part 4 — Phase B: Public ARS browsing

All paths prefixed with `/api/customer/v1` below.

### B.1 ARS public settings

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/stay/settings` | Public | Currency, default VAT rate, company display name for ARS. |

**Implementation:** [getArsCompanyId](../modules/ars/includes/ars_helpers.php), [getArsSettings](../modules/ars/includes/ars_helpers.php) (same as [stay/index.php](../stay/index.php)).

**Response `data` (example)**

```json
{
  "company_id": 1,
  "currency": "AED",
  "vat_rate": 5.0,
  "display_name": "ARS Rentals"
}
```

### B.2 Buildings filter list

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/stay/buildings` | Public | Distinct building names for listed short-term units. |

**Implementation:** Same query as [stay/index.php](../stay/index.php) buildings block.

**Response `data`**

```json
{
  "buildings": ["AYLA RESIDENCE", "..."]
}
```

### B.3 Listed units (browse grid — optional dates)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/stay/units` | Public | Query listed units; optional `building` filter; **no** availability filter unless dates provided. |

**Query parameters**

| Param | Required | Description |
|-------|------------|-------------|
| `building` | No | Exact building name filter |
| `check_in`, `check_out` | No | ISO date `YYYY-MM-DD`; if both set, filter to available + priced like [stay/search.php](../stay/search.php) |

**Response `data` — unit summary object (each item)**

```json
{
  "id": 123,
  "unit_number": "211",
  "unit_type": "studio",
  "area_sqm": 45,
  "listing_title": "Stylish Luxury Studio",
  "short_description": "...",
  "building_name": "AYLA RESIDENCE",
  "building_address": "...",
  "nightly_rate": 228.0,
  "monthly_rate": 6825.0,
  "max_guests": 2,
  "rental_mode": "short_term",
  "primary_photo_url": "/uploads/...",
  "amenities": ["Gym", "swimming pool"],
  "quote": null
}
```

When `check_in` / `check_out` present and valid, set `quote` to same structure as **B.5** inner quote (or omit if unavailable).

**Implementation:** SQL from [stay/index.php](../stay/index.php) / [stay/search.php](../stay/search.php); `amenities` from JSON decode `amenities_json`; `primary_photo_url` must be **absolute** or **base-relative** per client agreement (recommend full URL built from request host + [get_application_web_root](../includes/url_helper.php)).

**Dependencies:** `re_units`, `re_buildings`, `ars_unit_photos`.

### B.4 Unit detail

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/stay/units/{id}` | Public | Full unit row + all photos + amenities + optional calendar hint. |

**Response `data` (example)**

```json
{
  "unit": { },
  "photos": [
    { "id": 1, "file_path": "uploads/...", "url": "https://...", "is_primary": true, "sort_order": 0 }
  ],
  "amenities": ["Gym"],
  "calendar": {
    "year": 2026,
    "month": 4,
    "days": { "1": "available", "2": "blocked" }
  }
}
```

**Implementation:** [stay/unit.php](../stay/unit.php) — `ars_unit_month_availability` for `calendar` (optional for MVP; can be phase B.1 later to reduce payload).

**Dependencies:** [ars_availability.php](../modules/ars/includes/ars_availability.php) `ars_unit_month_availability`.

### B.5 Quote (availability + pricing)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/stay/units/{id}/quote` | Public | Same contract as [stay/ajax_check_availability.php](../stay/ajax_check_availability.php). |

**Query parameters**

| Param | Required |
|-------|----------|
| `check_in` | Yes |
| `check_out` | Yes |
| `promo_code` | No |

**Success `data` (align with existing JSON keys)**

Mirror current script output shape, normalized into envelope:

```json
{
  "available": true,
  "nights": 7,
  "subtotal": 0,
  "vat": 0,
  "total": 0,
  "effective_nightly": 0,
  "rules": [],
  "currency": "AED",
  "vat_rate": 5
}
```

When unavailable:

```json
{
  "ok": true,
  "data": {
    "available": false,
    "reason": "Unit is not available for these dates.",
    "conflicts": []
  }
}
```

**Implementation:** Direct port of [stay/ajax_check_availability.php](../stay/ajax_check_availability.php).

**Dependencies:** `ars_check_availability`, `ars_validate_minimum_stay`, `ars_get_nightly_breakdown`, `ars_validate_promo_code`, `ars_calculate_booking_price_v2`.

---

## Part 5 — Phase C: Guest auth + booking

### C.1 Guest registration

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/guest/register` | Public | Create `ars_guests` + `portal_users` like [stay/book.php](../stay/book.php) register branch. |

**Request body**

```json
{
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "phone": "+971...",
  "password": "min-6-chars"
}
```

**Response `data`**

```json
{
  "access_token": "<jwt>",
  "refresh_token": "<string>",
  "expires_in": 3600,
  "guest": {
    "id": 1,
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com"
  }
}
```

**Errors:** duplicate email (`code: guest_email_taken`), validation.

**Implementation:** Transaction from [stay/book.php](../stay/book.php) lines ~100–108; after success issue tokens instead of `portal_login_set_session`.

### C.2 Guest login

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/guest/login` | Public | Email + password → `portal_users` + guest. |

**Request**

```json
{
  "email": "jane@example.com",
  "password": "..."
}
```

**Response:** Same token + `guest` shape as C.1.

**Implementation:** [stay/book.php](../stay/book.php) login branch (~75–84) adapted.

### C.3 Guest session (me)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/auth/guest/me` | Guest Bearer | Profile summary. |

**Response `data`**

```json
{
  "portal_user_id": 1,
  "guest_id": 1,
  "email": "...",
  "display_name": "Jane Doe",
  "phone": "...",
  "nationality": null
}
```

**Implementation:** Join `portal_users` + `ars_guests` as [stay/profile.php](../stay/profile.php).

### C.4 Guest profile update (optional MVP)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| PATCH | `/auth/guest/me` | Guest Bearer | Update names, phone, nationality (not email in MVP or with extra verification). |

**Implementation:** [stay/profile.php](../stay/profile.php) `update_profile`.

### C.5 Guest password change (optional MVP)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/guest/me/password` | Guest Bearer | `current_password`, `new_password`. |

**Implementation:** [stay/profile.php](../stay/profile.php) `change_password`.

### C.6 List bookings

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/stay/bookings` | Guest Bearer | List `ars_bookings` for `guest_id`. |

**Query:** optional `status`, `upcoming_only=true|false`.

**Response `data`**

```json
{
  "bookings": [
    {
      "id": 1,
      "booking_number": "BS-00001",
      "unit_id": 123,
      "listing_title": "...",
      "building_name": "...",
      "check_in": "2026-05-01",
      "check_out": "2026-05-08",
      "nights": 7,
      "status": "pending",
      "total_amount": "0.00",
      "balance_due": "0.00",
      "payment_status": "unpaid",
      "primary_photo_url": "..."
    }
  ]
}
```

**Implementation:** [stay/dashboard.php](../stay/dashboard.php) query.

### C.7 Booking detail

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/stay/bookings/{id}` | Guest Bearer | Must verify `guest_id` on row. |

**Response `data`**

```json
{
  "booking": { },
  "unit": { },
  "payments": [ ]
}
```

**Implementation:** [stay/booking.php](../stay/booking.php) main query + `ars_booking_payments` list.

### C.8 Create booking

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/stay/bookings` | **Guest Bearer required** | **Locked for mobile MVP:** anonymous booking is **not** supported in the app. Guest must complete Phase C login/register first; server rejects missing/invalid Bearer with `401`. |

**Request body (guest Bearer)**

```json
{
  "unit_id": 123,
  "check_in": "2026-05-01",
  "check_out": "2026-05-08",
  "guests": 2,
  "special_requests": "",
  "promo_code": ""
}
```

**Response `data`**

```json
{
  "booking_id": 1,
  "booking_number": "BS-00001",
  "status": "pending",
  "expires_at": "2026-04-13T12:00:00Z"
}
```

**Implementation:** Core INSERT from [stay/book.php](../stay/book.php) post block; call `expirePendingBookings` before insert as today.

**Dependencies:** `ars_bookings`, pricing helpers, `generateBookingNumber`, `ars_increment_promo_usage`.

**Errors:** `409` unavailable, `422` min stay / validation.

---

## Part 6 — Phase D-light: Tenant auth + dashboard + payments + invoices

### D.1 Tenant login

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/tenant/login` | Public | Email + password → `tenant_portal_users` approved; optional legacy path. |

**Request**

```json
{
  "email": "tenant@example.com",
  "password": "..."
}
```

**Response `data`**

```json
{
  "access_token": "<jwt>",
  "refresh_token": "<string>",
  "expires_in": 3600,
  "tenant": {
    "tenant_portal_user_id": 1,
    "display_name": "...",
    "company_id": 1,
    "tenant_id": 5,
    "leases": [
      { "lease_id": 10, "lease_number": "L-001", "unit_number": "12A", "building_name": "..." }
    ],
    "active_lease_id": 10
  }
}
```

**Implementation:** [tenant_portal/login.php](../tenant_portal/login.php) POST logic; populate `leases` via `re_leases` for `tenant_id` + `company_id` as [tenant_auth.php](../tenant_portal/includes/tenant_auth.php) `current_tenant_lease_ids`.

### D.2 Tenant refresh / me

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/auth/tenant/me` | Tenant Bearer | Returns same tenant + leases + current lease summary. |

### D.3 Set active lease

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/tenant/leases/active` | Tenant Bearer | Body `{ "lease_id": 10 }` must be in allowed list. |

**Implementation:** Same as [tenant_portal/switch_lease.php](../tenant_portal/switch_lease.php) session update (server stores preferred lease in token refresh or stateless: client sends `X-Lease-Id` header — **recommend** `lease_id` query param or header on each tenant request).

**Locked pattern:** Require header **`X-Tenant-Lease-Id: 10`** on all `/tenant/*` routes that need lease context; validate against the tenant’s allowed leases in the DB (do not trust the header alone without lookup).

### D.4 Dashboard aggregate

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/tenant/dashboard` | Tenant Bearer + lease scope | Same aggregates as [tenant_portal/dashboard.php](../tenant_portal/dashboard.php) (outstanding rent, penalties, next installment, lease expiry flag, **omit** maintenance/cleaning/pest counts for MVP). |

**Response `data` (example fields)**

```json
{
  "lease": { "lease_id": 10, "start_date": "", "end_date": "", "unit_number": "", "building_name": "" },
  "financial": {
    "outstanding_rent": "0.00",
    "outstanding_penalties": "0.00",
    "next_installment": { "installment_date": "", "amount": "", "status": "" }
  },
  "lease_expiry": {
    "flag": "active",
    "days_to_end": 200
  }
}
```

**Implementation:** SQL blocks from dashboard.php lines ~36–90; **exclude** counts for cleaning/pest/maintenance/renewal or return zeros.

### D.5 Payments: installments + payment history

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/tenant/leases/{lease_id}/installments` | Tenant Bearer + lease | Rows from `re_lease_installments`. |

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/tenant/leases/{lease_id}/payments` | Tenant Bearer + lease | Rows from `re_payments`. |

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/tenant/leases/{lease_id}/penalties` | Tenant Bearer + lease | Optional; from `re_billing_items` item_type `penalty` as [tenant_portal/payments.php](../tenant_portal/payments.php). |

**Response:** paginated arrays + `outstanding` summary object.

**Implementation:** [tenant_portal/payments.php](../tenant_portal/payments.php).

### D.6 Invoices list

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/tenant/leases/{lease_id}/invoices` | Tenant Bearer + lease | List from `re_invoices` as [tenant_portal/invoices.php](../tenant_portal/invoices.php). |

**Response item fields:** `id`, `invoice_number`, `invoice_date`, `due_date`, `total_amount`, `paid_amount`, `outstanding_amount`, `status`.

**Invoice PDF:** **Not in MVP** — locked; see §0.5. No PDF route in v1 MVP.

---

## Part 7 — Endpoint summary table (MVP only)

| Phase | Method | Path | Auth |
|-------|--------|------|------|
| A | GET | `/api/customer/v1/health` | Public |
| A | POST | `/api/customer/v1/auth/refresh` | Refresh |
| A | POST | `/api/customer/v1/auth/logout` | Bearer/refresh |
| B | GET | `/api/customer/v1/stay/settings` | Public |
| B | GET | `/api/customer/v1/stay/buildings` | Public |
| B | GET | `/api/customer/v1/stay/units` | Public |
| B | GET | `/api/customer/v1/stay/units/{id}` | Public |
| B | GET | `/api/customer/v1/stay/units/{id}/quote` | Public |
| C | POST | `/api/customer/v1/auth/guest/register` | Public |
| C | POST | `/api/customer/v1/auth/guest/login` | Public |
| C | GET | `/api/customer/v1/auth/guest/me` | Guest |
| C | PATCH | `/api/customer/v1/auth/guest/me` | Guest (optional) |
| C | POST | `/api/customer/v1/auth/guest/me/password` | Guest (optional) |
| C | GET | `/api/customer/v1/stay/bookings` | Guest |
| C | GET | `/api/customer/v1/stay/bookings/{id}` | Guest |
| C | POST | `/api/customer/v1/stay/bookings` | Guest Bearer **required** |
| D | POST | `/api/customer/v1/auth/tenant/login` | Public |
| D | GET | `/api/customer/v1/auth/tenant/me` | Tenant |
| D | POST | `/api/customer/v1/tenant/leases/active` | Tenant |
| D | GET | `/api/customer/v1/tenant/dashboard` | Tenant + `X-Tenant-Lease-Id` |
| D | GET | `/api/customer/v1/tenant/leases/{lease_id}/installments` | Tenant + `X-Tenant-Lease-Id` |
| D | GET | `/api/customer/v1/tenant/leases/{lease_id}/payments` | Tenant + `X-Tenant-Lease-Id` |
| D | GET | `/api/customer/v1/tenant/leases/{lease_id}/penalties` | Tenant + `X-Tenant-Lease-Id` |
| D | GET | `/api/customer/v1/tenant/leases/{lease_id}/invoices` | Tenant + `X-Tenant-Lease-Id` (list only) |

---

## Part 8 — Files / modules to add or wrap (implementation checklist)

When coding starts (not now):

1. **New router entry** — single front controller or Apache rewrite to `api/customer/v1/index.php` (or Symfony-style — team choice).
2. **`CustomerApiAuthService`** — JWT sign/verify, map to guest vs tenant.
3. **`CustomerStayController`** — wraps `getArsCompanyId`, `getArsSettings`, unit listing, quote (reuse ajax script logic).
4. **`CustomerGuestAuthController`** — wraps portal login/register from stay.
5. **`CustomerBookingService`** — extracted from [stay/book.php](../stay/book.php).
6. **`CustomerTenantAuthController`** — wraps [tenant_portal/login.php](../tenant_portal/login.php).
7. **`CustomerTenantLeaseController`** — dashboard + payments + invoices queries from tenant portal pages.
8. **OpenAPI 3.1 YAML** — generate from this document for Flutter `dio` + codegen optional.

---

## Part 9 — Recommended next step (after document approval)

1. Add OpenAPI file alongside this doc (`docs/openapi_customer_v1.yaml`) matching Parts 2–7 (optional but useful for Flutter).
2. Implement **Phase A + B** first, in order: **health** → **auth foundation** (`POST /auth/refresh`, `POST /auth/logout`) → **`GET /stay/settings`** → **`GET /stay/buildings`** → **`GET /stay/units`** → **`GET /stay/units/{id}`** → **`GET /stay/units/{id}/quote`**. Then **Phase C**, then **D-light**, per §0.5.
3. Flutter spike: welcome screen + public browse calling the same base URL as the PHP API.

---

*Document version: 1.0 — locked MVP scope and API surface for Phases A, B, C, D-light.*
