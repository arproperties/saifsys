# Customer API v1 — local QA (XAMPP / `herosysgro`)

Base path examples assume the app lives at **`http://127.0.0.1/herosysgro/`**. Adjust host and folder if yours differs.

## Two URL styles

1. **Pretty URL** (requires Apache rewrite from [`.htaccess`](../.htaccess)):  
   `http://127.0.0.1/herosysgro/api/customer/v1/<path>`

2. **Front controller + query** (works without rewrite):  
   `http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=<path>`  
   Example: `?route=health` → same as `GET .../health`.

Use the same `route` value you would use after `/v1/` (no leading slash), e.g. `stay/units/5/quote`.

---

## Phase A — health & tokens

### GET health

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=health"
```

### POST auth/refresh

```bash
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=auth/refresh" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"<paste_refresh_from_login>"}'
```

### POST auth/logout

```bash
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=auth/logout" \
  -H "Content-Type: application/json" \
  -d '{}'
```

---

## Phase B — public stay (samples)

### GET stay/settings

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/settings"
```

### GET stay/units (optional dates)

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/units"
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/units&building=AYLA%20RESIDENCE"
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/units&check_in=2026-05-01&check_out=2026-05-08&guests=2"
```

### GET stay/units/{id}/quote

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/units/1/quote&check_in=2026-05-01&check_out=2026-05-08&promo_code="
```

---

## Phase C — guest auth & bookings

All successful responses use: `{ "ok": true, "data": { ... } }`.  
Errors: `{ "ok": false, "error": { "code": "...", "message": "...", "details": {} } }`.

Set **`CUSTOMER_API_JWT_SECRET`** in your environment for anything beyond quick local tests (see [`includes/customer_api.php`](../includes/customer_api.php)).

### POST auth/guest/register

```bash
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=auth/guest/register" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane.doe.qa@example.com",
    "phone": "+971500000000",
    "password": "secret12"
  }'
```

Duplicate email → `409`, `error.code`: `guest_email_taken`.

### POST auth/guest/login

```bash
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=auth/guest/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane.doe.qa@example.com",
    "password": "secret12"
  }'
```

Save `data.access_token` and `data.refresh_token` from the response.

### GET auth/guest/me

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=auth/guest/me" \
  -H "Authorization: Bearer <access_token>"
```

### GET stay/bookings

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/bookings" \
  -H "Authorization: Bearer <access_token>"

curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/bookings&status=pending&upcoming_only=true" \
  -H "Authorization: Bearer <access_token>"
```

### GET stay/bookings/{id}

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/bookings/42" \
  -H "Authorization: Bearer <access_token>"
```

### POST stay/bookings (Bearer required)

Use a real `unit_id` from `GET stay/units` and valid dates from `GET .../quote` (available).

```bash
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=stay/bookings" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{
    "unit_id": 1,
    "check_in": "2026-06-01",
    "check_out": "2026-06-08",
    "guests": 2,
    "special_requests": "Late check-in if possible",
    "promo_code": ""
  }'
```

Missing or invalid Bearer → `401`. Unit no longer available → `409`, `error.code`: `not_available`. Min-stay / rules → `422` (e.g. `error.code`: `min_stay`).

---

## Phase D-light — tenant auth & lease-scoped data

Use a **tenant** access token from `POST auth/tenant/login` (`data.access_token`). Guest tokens return `401` / `invalid_token` on these routes.

Lease context for **`GET /tenant/dashboard`** and **`GET /tenant/leases/{lease_id}/...`** is taken from the **`X-Tenant-Lease-Id`** header (numeric `re_leases.id`). For URL routes under `/tenant/leases/{lease_id}/`, the header **must equal** the `{lease_id}` in the path or you get `400` (`lease_header_mismatch`).

### POST auth/tenant/login

```bash
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=auth/tenant/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "tenant@example.com",
    "password": "your-password"
  }'
```

Legacy portal users whose login is **username** (not email) can use that username string in the `email` field. Wrong password → `401`, `error.code`: `invalid_credentials`.

### GET auth/tenant/me

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=auth/tenant/me" \
  -H "Authorization: Bearer <tenant_access_token>"
```

Optional: send **`X-Tenant-Lease-Id`** with a lease you are allowed to access; when valid, `data.active_lease_id` reflects that lease. Otherwise the first allowed lease is shown as active.

### POST tenant/leases/active

Validates `lease_id` is in your allowed list (same rules as the portal switcher). Stateless API: the client should send **`X-Tenant-Lease-Id`** on subsequent lease-scoped calls.

```bash
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=tenant/leases/active" \
  -H "Authorization: Bearer <tenant_access_token>" \
  -H "Content-Type: application/json" \
  -d '{"lease_id": 10}'
```

### GET tenant/dashboard

Requires **`X-Tenant-Lease-Id`** (the dashboard lease).

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=tenant/dashboard" \
  -H "Authorization: Bearer <tenant_access_token>" \
  -H "X-Tenant-Lease-Id: 10"
```

### GET tenant/leases/{lease_id}/installments | payments | penalties | invoices

Replace `10` with a real lease id. Header must match the path.

```bash
LEASE=10
TOK=<tenant_access_token>

curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=tenant/leases/${LEASE}/installments" \
  -H "Authorization: Bearer ${TOK}" \
  -H "X-Tenant-Lease-Id: ${LEASE}"

curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=tenant/leases/${LEASE}/payments" \
  -H "Authorization: Bearer ${TOK}" \
  -H "X-Tenant-Lease-Id: ${LEASE}"

curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=tenant/leases/${LEASE}/penalties" \
  -H "Authorization: Bearer ${TOK}" \
  -H "X-Tenant-Lease-Id: ${LEASE}"

curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/index.php?route=tenant/leases/${LEASE}/invoices" \
  -H "Authorization: Bearer ${TOK}" \
  -H "X-Tenant-Lease-Id: ${LEASE}"
```

**Invoice PDF** is not implemented (list metadata only). Missing header on lease-scoped routes → `400`, `error.code`: `missing_lease_header`. Lease not allowed for your account → `403`, `error.code`: `forbidden`.

---

## Pretty-URL equivalents

If rewrite is active, replace:

`.../api/customer/v1/index.php?route=PATH&query`

with:

`.../api/customer/v1/PATH?query`

Example:

```bash
curl -sS "http://127.0.0.1/herosysgro/api/customer/v1/health"
curl -sS -X POST "http://127.0.0.1/herosysgro/api/customer/v1/auth/guest/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"secret12"}'
```

---

## Contract reference

Full endpoint tables and payloads: [unified_customer_app_mvp_plan_and_api.md](unified_customer_app_mvp_plan_and_api.md).
