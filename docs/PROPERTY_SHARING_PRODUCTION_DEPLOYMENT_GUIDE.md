# Property Sharing V1 — Production Deployment Guide

| Field | Value |
|-------|-------|
| Feature | Property Sharing (Find Your Home) |
| Scope | Version 1 — installed-app deep linking only |
| Out of scope | Deferred deep linking after install (Version 2) |
| Intended live host | `https://sys.saifholdinggroup.com` |
| Flutter package / bundle | `com.ainalreem.living` |
| Document type | Permanent deployment & configuration manual |
| Based on | Code as implemented in this repository (not generic assumptions) |

This guide is for releasing Property Sharing to the live Hostinger / production server. It is based on the **actual** PHP routes, rewrite rules, ERP settings, Flutter navigation, Android App Links, and iOS Universal Links in this project.

**Do not invent paths.** Where a value must come from your signing accounts (SHA-256, Apple Team ID, store listing URLs), this guide labels it as **YOU MUST OBTAIN**.

---

## 1. Implemented architecture

### 1.1 High-level flow

```text
Staff publishes unit (publish_to_mobile=1)
        │
        ▼
re_property_share_refs row ensured (opaque UUID share_code)
        │
        ▼
Share URL = {share_base_url}/p/{share_code}
        │
        ├─ App installed ──► App Link / Universal Link opens app
        │                         │
        │                         ▼
        │                   GET /api/mobile/property-share/{code}
        │                         │
        │            ┌────────────┴────────────┐
        │            ▼                         ▼
        │      available=true            available=false
        │      unit_id → existing        PropertyShareUnavailableScreen
        │      /find-home/rentals/{id}   ("This property is no longer available.")
        │
        └─ App not installed ──► Browser hits /p/{code}
                                      │
                                      ▼
                         property_share_redirect.php
                         302 → Play Store or App Store
                         (NO property HTML / landing page)
```

### 1.2 ERP Property Sharing settings

Location: **Settings → Mobile App Management → Property Sharing**

Implemented in:

- UI: [`settings.php`](../settings.php) (`tab=mobile_app`)
- Save action: `update_mobile_app`
- Storage: table `app_mobile_config` (global singleton, `id = 1`)
- Helper: [`includes/app_mobile_config_helper.php`](../includes/app_mobile_config_helper.php)
- Public read: `GET /api/customer/v1/app-config` → `property_share` object

Configurable fields (see §4 for exact values):

| DB column | Purpose |
|-----------|---------|
| `property_share_enabled` | Master on/off for sharing |
| `share_base_url` | HTTPS base for `/p/{share_code}` |
| `share_message_template` | Native share message with placeholders |
| `android_package_name` | Used in dynamic `assetlinks.json` |
| `android_sha256_fingerprints` | Used in dynamic `assetlinks.json` |
| `ios_team_id` | Used in dynamic AASA `appID` |
| `ios_bundle_id` | Used in dynamic AASA `appID` |
| `play_store_url` | Android no-app 302 target |
| `app_store_url` | iOS no-app 302 target |

### 1.3 `share_code` generation and storage

| Item | Implementation |
|------|----------------|
| Table | `re_property_share_refs` |
| Migration | [`migrations/re_property_share_refs.sql`](../migrations/re_property_share_refs.sql) |
| Runtime ensure | `re_property_share_ensure_schema()` in [`includes/property_share_helper.php`](../includes/property_share_helper.php) |
| Code format | UUID v4 style `CHAR(36)` |
| Property type (V1) | `long_term_rental` |
| Entity | `entity_id` = `re_units.id` |
| Company scope | `company_id` on the share ref |
| When created | On unit publish save (`units_add.php`), on staff unit view when published, and lazily when detail API asks for share meta |

External links use **only** `share_code`. Internal Flutter detail still uses numeric `unit_id` **after** resolve.

### 1.4 Resolve API

| Item | Value |
|------|-------|
| Method / path | `GET /api/mobile/property-share/{share_code}` |
| Handler | [`api/mobile/property_share.php`](../api/mobile/property_share.php) |
| Rewrite | [`api/mobile/.htaccess`](../api/mobile/.htaccess) → `property_share.php` |
| Core logic | `re_property_share_resolve()` |
| Auth | Public (no JWT) |
| Cache header | `Cache-Control: public, max-age=30` |

**Available response shape (success, no listing payload beyond ids):**

```json
{
  "success": true,
  "data": {
    "available": true,
    "property_type": "long_term_rental",
    "unit_id": 71
  }
}
```

**Unavailable response shape (no photos, rent, title, or other listing fields):**

```json
{
  "success": true,
  "data": {
    "available": false,
    "reason": "unpublished",
    "message": "This property is no longer available.",
    "property_type": null,
    "unit_id": null
  }
}
```

Eligibility for “available” matches Find Your Home list/detail (`publish_to_mobile = 1`, rental mode `long_term`/`both`, `status = vacant`, no active lease). Reasons include: `share_disabled`, `not_found`, `inactive`, `wrong_type`, `unpublished`, `occupied`, `unavailable`.

### 1.5 `/p/{share_code}` redirect flow

| Item | Value |
|------|-------|
| Public URL | `https://sys.saifholdinggroup.com/p/{share_code}` |
| PHP handler | [`property_share_redirect.php`](../property_share_redirect.php) |
| Rewrite | Root [`.htaccess`](../.htaccess) / [`.htaccess.live`](../.htaccess.live): `^p/([A-Za-z0-9\-]+)/?$` → `property_share_redirect.php?code=$1` |
| Purpose | Infrastructure only — **not** a Unit Details or marketing webpage |
| Browser behaviour (V1) | Soft-touch resolve (errors logged), then **302** to store URL by User-Agent |
| If store URLs empty | Plain text: `Open this link on a device with Ain Al Reem Living installed.` |

Installed-app App Link / Universal Link verification requests also hit this host path; the OS opens the app instead of following the browser 302 when association succeeds.

### 1.6 Android App Links

| Item | Value |
|------|-------|
| Package | `com.ainalreem.living` ([`customer_app/android/app/build.gradle.kts`](../customer_app/android/app/build.gradle.kts)) |
| Manifest | [`customer_app/android/app/src/main/AndroidManifest.xml`](../customer_app/android/app/src/main/AndroidManifest.xml) |
| Production filter | `https` + host `sys.saifholdinggroup.com` + `pathPrefix="/p/"` + `android:autoVerify="true"` |
| Local debug filters | `http` hosts `10.0.2.2` / `localhost` / `127.0.0.1` + `/herosysgro/p/` (no `autoVerify`) |
| Association file | Dynamically served `/.well-known/assetlinks.json` (§5) |
| Flutter listener | `app_links` via [`PropertyShareDeepLinkBootstrap`](../customer_app/lib/core/property_share/property_share_deep_link.dart) |

### 1.7 iOS Universal Links

| Item | Value |
|------|-------|
| Bundle ID | `com.ainalreem.living` |
| Entitlements | [`customer_app/ios/Runner/Runner.entitlements`](../customer_app/ios/Runner/Runner.entitlements) |
| Associated Domain | `applinks:sys.saifholdinggroup.com` |
| Association file | Dynamically served `/.well-known/apple-app-site-association` (§5) |
| Flutter listener | Same `PropertyShareDeepLinkBootstrap` as Android |

### 1.8 Existing Unit Details routing

After a successful resolve, the app navigates to:

`/find-home/rentals/{unitId}` → existing `LongTermRentalDetailScreen`

Defined in:

- [`customer_app/lib/router/routes.dart`](../customer_app/lib/router/routes.dart) — `Routes.findHomeDetail`
- [`customer_app/lib/router/app_router.dart`](../customer_app/lib/router/app_router.dart)

**There is no second details screen for shared properties.**

Share CTA: share icon on the existing detail header (when `share.enabled` + `share_url` present). Message built from ERP template + listing fields already on screen.

Detail API also returns share meta on eligible units:

`GET /api/mobile/long-term-rentals/{unit_id}` → `share: { share_enabled, share_code, share_url }`

### 1.9 Unavailable-property handling

When resolve returns `available: false`, Flutter opens:

`/find-home/unavailable` → `PropertyShareUnavailableScreen`

Copy: **“This property is no longer available.”**  
Actions: **Browse Similar Properties** / **Back to Available Units** (both go to Find Home list).

Unpublished, occupied, inactive, sharing-disabled, invalid, or cross-company-ineligible units **must not** expose outdated listing data through the resolve API.

### 1.10 Play Store / App Store fallback

Handled only by [`property_share_redirect.php`](../property_share_redirect.php):

1. iOS UA → `app_store_url`
2. Android UA → `play_store_url`
3. Else → first non-empty of Play, then App Store
4. Else → plain-text fallback message

**V1 does not** restore the shared property after install (no deferred deep linking).

---

## 2. Complete file inventory

Legend: **Upload** = must be present on the live PHP document root (or rebuilt into store apps for mobile columns).

### 2.1 Created (new)

| Path | Purpose | Upload live? | Type |
|------|---------|--------------|------|
| `migrations/re_property_share_refs.sql` | Creates `re_property_share_refs` | Run on DB (not “web upload” alone) | SQL |
| `includes/property_share_helper.php` | Schema ensure, ensure code, resolve, URL/message helpers | **Yes** | PHP |
| `api/mobile/property_share.php` | Public resolve endpoint | **Yes** | PHP |
| `property_share_redirect.php` | `/p/{code}` store redirect (no landing page) | **Yes** | PHP |
| `property_share_well_known.php` | Dynamic assetlinks + AASA | **Yes** | PHP |
| `tests/property_share_resolve_test.php` | Smoke tests for unavailable resolve | Optional (not required for users) | PHP test |
| `customer_app/lib/core/property_share/property_share_deep_link.dart` | App Links listener + message builder | Via app release | Flutter |
| `customer_app/lib/features/find_home/data/property_share_repository.dart` | Resolve API client | Via app release | Flutter |
| `customer_app/lib/features/find_home/screens/property_share_unavailable_screen.dart` | Unavailable UI | Via app release | Flutter |
| `docs/PROPERTY_SHARING_PRODUCTION_DEPLOYMENT_GUIDE.md` | This guide | Optional | Docs |

### 2.2 Modified (existing)

| Path | Purpose | Upload live? | Type |
|------|---------|--------------|------|
| `includes/app_mobile_config_helper.php` | Share columns ensure/save + `property_share` in app-config | **Yes** | PHP |
| `settings.php` | Property Sharing ERP form + save fields | **Yes** | PHP |
| `api/mobile/long_term_rentals.php` | Detail payload includes `share` meta | **Yes** | PHP |
| `api/mobile/.htaccess` | Rewrite `property-share` → `property_share.php` | **Yes** | Config |
| `.htaccess` | Localhost rewrite for `/p/` + `.well-known` | Local only (if using XAMPP file) | Config |
| `.htaccess.live` | **Live** rewrite for `/p/` + `.well-known` | **Yes** — deploy as live `.htaccess` when site is at document root | Config |
| `modules/realestate/units_add.php` | Ensure share_code on publish | **Yes** | PHP |
| `modules/realestate/unit_view.php` | Staff Copy Share Link | **Yes** | PHP |
| `customer_app/pubspec.yaml` | Adds `app_links` | Via app release | Flutter |
| `customer_app/lib/app.dart` | Wraps app with deep-link bootstrap | Via app release | Flutter |
| `customer_app/lib/router/routes.dart` | Unavailable route helpers | Via app release | Flutter |
| `customer_app/lib/router/app_router.dart` | Registers unavailable route | Via app release | Flutter |
| `customer_app/lib/core/app_runtime/app_runtime_config.dart` | Parses `property_share` from app-config | Via app release | Flutter |
| `customer_app/lib/features/find_home/models/long_term_rental_models.dart` | Share fields on detail model | Via app release | Flutter |
| `customer_app/lib/features/find_home/screens/long_term_rental_detail_screen.dart` | Share button + native sheet | Via app release | Flutter |
| `customer_app/android/app/src/main/AndroidManifest.xml` | App Links intent-filters | Via Android build | Android |
| `customer_app/ios/Runner/Runner.entitlements` | Associated Domains | Via iOS build | iOS |

### 2.3 Required for deployment (dependencies already in project)

| Path | Why needed | Upload? | Type |
|------|------------|---------|------|
| `includes/db_connect.php` | DB for resolve / well-known / redirect | Already live | PHP |
| `api/mobile/config.php` | JSON helpers for resolve API | Already live | PHP |
| `includes/url_helper.php` | Optional default share base path | Already live | PHP |
| Existing Find Home API + Flutter detail | Target of successful deep links | Already live / in app | PHP + Flutter |
| Store URL fields on `app_mobile_config` | No-app 302 | Configure in ERP | Config / DB |

### 2.4 Explicitly do **not** upload / create

| Item | Reason |
|------|--------|
| Physical `public/.well-known/assetlinks.json` | Dynamic PHP serves this; static duplicates can conflict |
| Physical `public/.well-known/apple-app-site-association` | Same — dynamic via rewrite |
| Property marketing HTML under `/p/` | Intentionally absent |
| Version 2 deferred-deep-link code | Out of scope |

---

## 3. Database deployment

### 3.1 Migration file

| Order | File | What it does |
|-------|------|--------------|
| 1 | `migrations/re_property_share_refs.sql` | `CREATE TABLE IF NOT EXISTS re_property_share_refs` (+ indexes) |

**Also required (no separate SQL file in repo):** additive columns on `app_mobile_config`, created at runtime by `app_mobile_config_ensure_share_columns()` when Mobile App settings / helpers load:

- `property_share_enabled`
- `share_base_url`
- `share_message_template`
- `android_package_name`
- `android_sha256_fingerprints`
- `ios_team_id`
- `ios_bundle_id`

Opening **Settings → Mobile App Management** once after code deploy (or calling any code path that runs `app_mobile_config_ensure_schema`) applies those columns safely if missing.

### 3.2 Table / indexes created (`re_property_share_refs`)

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT PK AI | |
| `company_id` | INT NOT NULL | Company isolation |
| `share_code` | CHAR(36) UNIQUE | Opaque public id |
| `property_type` | VARCHAR(40) | V1: `long_term_rental` |
| `entity_id` | INT | `re_units.id` for V1 |
| `is_active` | TINYINT(1) DEFAULT 1 | |
| `created_at` / `updated_at` | DATETIME | |

Indexes: `uq_share_code`, `uq_company_type_entity (company_id, property_type, entity_id)`, `idx_company_active`, `idx_entity`.

### 3.3 Safe live execution

1. Take a full MySQL backup.
2. Confirm you are on the **production** database used by `includes/config.php` / Hostinger.
3. From a machine with DB access:

```bash
mysql -u YOUR_USER -p YOUR_DATABASE < migrations/re_property_share_refs.sql
```

Or paste/run the SQL inside Hostinger phpMyAdmin.

4. Deploy PHP code (§6).
5. Log into ERP as Owner/Admin → **Settings → Mobile App Management** → open the tab (triggers column ensure) → Save once after filling fields.

`CREATE TABLE IF NOT EXISTS` is **idempotent**. Column ensure skips existing columns. **No `UPDATE`/`DELETE` on `re_units` listing data.**

### 3.4 Verification SQL

```sql
SHOW TABLES LIKE 're_property_share_refs';

SHOW CREATE TABLE re_property_share_refs\G

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'app_mobile_config'
  AND COLUMN_NAME IN (
    'property_share_enabled',
    'share_base_url',
    'share_message_template',
    'android_package_name',
    'android_sha256_fingerprints',
    'ios_team_id',
    'ios_bundle_id'
  )
ORDER BY COLUMN_NAME;

SELECT id, property_share_enabled, share_base_url,
       android_package_name, ios_bundle_id,
       LEFT(android_sha256_fingerprints, 40) AS sha_prefix,
       ios_team_id IS NOT NULL AS has_team_id
FROM app_mobile_config
WHERE id = 1;
```

### 3.5 Rollback considerations

| Action | Effect |
|--------|--------|
| Immediate kill-switch | Uncheck **Enable Property Sharing** in ERP (no code remove) |
| Soft disable refs | `UPDATE re_property_share_refs SET is_active = 0;` (optional) |
| Hard rollback table | `DROP TABLE IF EXISTS re_property_share_refs;` — **only** after kill-switch; does **not** delete units/listings |
| Remove config columns | Not required; leaving columns empty/disabled is safe |

Dropping the share table does **not** damage `re_units`, media, leases, or Find Your Home listing fields.

### 3.6 Data safety confirmation

Property Sharing V1 is **additive**:

- No changes to Invoice Mode / journals
- No rewrite of existing unit rows except normal publish edits already used by Find Your Home
- Share refs are a new side table keyed by `(company_id, property_type, entity_id)`

---

## 4. ERP configuration

Path: **Settings → Mobile App Management** (Owner/Admin).

Save with **Save Mobile App Settings**. Public API: `GET /api/customer/v1/app-config`.

### 4.1 Field-by-field

#### Enable Property Sharing

| | |
|--|--|
| Control | Switch `property_share_enabled` |
| Production value | **On** |
| When Off | Resolve returns unavailable (`share_disabled`); Share hidden; no `share_url` on detail |

#### Share Base URL

| | |
|--|--|
| Intended production value | `https://sys.saifholdinggroup.com` |
| Effect | Share links become `https://sys.saifholdinggroup.com/p/{share_code}` |
| If blank | Helper derives current request host + `get_base_path()` (OK for local; **do not rely on blank for production App Links**) |

**Must match** the host baked into AndroidManifest / iOS Associated Domains: `sys.saifholdinggroup.com`.

#### Share Message Template

Paste the approved template from §10 into the ERP textarea.

Supported placeholders only: `{title}` `{building}` `{location}` `{rent}` `{url}`.

#### Android Package Name

| | |
|--|--|
| Actual app value | `com.ainalreem.living` |
| Source of truth | `customer_app/android/app/build.gradle.kts` → `applicationId` |

#### Android SHA-256 Fingerprints

| | |
|--|--|
| Format | Colon-separated hex, e.g. `AA:BB:CC:...` |
| Multiple | One per line **or** comma/semicolon/whitespace separated (PHP splits on `[\s,;]+`) |
| Local testing | Debug keystore SHA-256 (**YOU MUST OBTAIN** from your machine) |
| Production (Play App Signing) | **App signing key certificate** SHA-256 from Play Console (**YOU MUST OBTAIN**) — usually **not** only the upload key |
| Do not paste | Private keys, keystore passwords, or `.jks` files |

#### iOS Team ID

| | |
|--|--|
| Format | 10-character Apple Team ID |
| Source | Apple Developer Membership (**YOU MUST OBTAIN**) |
| Used as | AASA `appID` = `{TEAM_ID}.{BUNDLE_ID}` |

#### iOS Bundle ID

| | |
|--|--|
| Actual app value | `com.ainalreem.living` |
| Source | Xcode / `PRODUCT_BUNDLE_IDENTIFIER` |

#### Google Play URL

| | |
|--|--|
| Format example | `https://play.google.com/store/apps/details?id=com.ainalreem.living` |
| Actual listing URL | **YOU MUST OBTAIN** from Play Console when the listing exists |
| Used when | Android browser opens `/p/{code}` without app |

#### Apple App Store URL

| | |
|--|--|
| Format example | `https://apps.apple.com/app/idXXXXXXXXX` |
| Actual listing URL | **YOU MUST OBTAIN** from App Store Connect |
| Used when | iOS browser / Safari opens `/p/{code}` without app |

### 4.2 Example vs required

| Field | Example / known project value | Must obtain from accounts |
|-------|-------------------------------|---------------------------|
| Share Base URL | `https://sys.saifholdinggroup.com` | Confirm DNS points here |
| Package / Bundle | `com.ainalreem.living` | Already in repo |
| SHA-256 | *(pattern only)* | Debug + Play signing certs |
| iOS Team ID | *(e.g. `AB12CD34EF` pattern)* | Apple Developer |
| Store URLs | formats above | Play / App Store Connect |

---

## 5. `.well-known` configuration

### 5.1 What these files are

| Endpoint | Platform | Role |
|----------|----------|------|
| `/.well-known/assetlinks.json` | Android | Digital Asset Links — proves this website may open `com.ainalreem.living` for matching HTTPS paths |
| `/.well-known/apple-app-site-association` | iOS | AASA — lists which app IDs may claim Universal Links for paths |

### 5.2 Dynamic vs physical — **this project uses DYNAMIC generation**

There are **no** checked-in static files under a `.well-known/` directory for Property Sharing.

| Public URL | Served by | Rewrite |
|------------|-----------|---------|
| `/.well-known/assetlinks.json` | [`property_share_well_known.php?type=assetlinks`](../property_share_well_known.php) | `.htaccess` / `.htaccess.live` |
| `/.well-known/apple-app-site-association` | [`property_share_well_known.php?type=aasa`](../property_share_well_known.php) | same |

Rewrite rules (live file excerpt):

```apache
RewriteRule ^\.well-known/assetlinks\.json$ property_share_well_known.php?type=assetlinks [L,QSA]
RewriteRule ^\.well-known/apple-app-site-association$ property_share_well_known.php?type=aasa [L,QSA]
RewriteRule ^p/([A-Za-z0-9\-]+)/?$ property_share_redirect.php?code=$1 [L,QSA]
```

Content is built from ERP `app_mobile_config` (package, fingerprints, team id, bundle id).

### 5.3 Do I upload physical `.well-known` files?

**No.** Do **not** create:

- `/.well-known/assetlinks.json` as a static file on disk  
- `/.well-known/apple-app-site-association` as a static file on disk  

Static files can bypass PHP, serve stale fingerprints, or break content-type / Apple path rules. Upload **`property_share_well_known.php`** + the rewrite rules instead.

### 5.4 Expected public URLs (production)

```text
https://sys.saifholdinggroup.com/.well-known/assetlinks.json
https://sys.saifholdinggroup.com/.well-known/apple-app-site-association
```

### 5.5 Content types and extensions

| Endpoint | Extension in URL | Content-Type set by PHP |
|----------|------------------|-------------------------|
| assetlinks | `.json` allowed / required by Google | `application/json; charset=utf-8` |
| AASA | **No** `.json` extension in the public URL | `application/json; charset=utf-8` |

Cache: `Cache-Control: public, max-age=300` (5 minutes). After changing fingerprints/Team ID, wait for cache expiry or purge CDN/Hostinger cache.

AASA payload includes paths `/p/*` and `/*/p/*` (covers subfolder installs if ever used).

---

## 6. Live server / Hostinger deployment

### 6.1 How to identify the correct document root (do not guess)

On Hostinger (or any host), the ERP document root is the directory that already contains the live HeroSysgro entrypoints, for example:

- `settings.php`
- `index.php` / `login.php`
- `includes/db_connect.php`
- `api/mobile/`

**Identify it by:**

1. Hostinger hPanel → **Websites** → your domain → **Document Root** / file manager path (often `public_html` or a subdomain folder).
2. Confirm that opening `https://sys.saifholdinggroup.com/settings.php` (or `/login`) serves **this** ERP.
3. Confirm `api/mobile/long_term_rentals.php` (or pretty `/api/mobile/long-term-rentals`) already works for Find Your Home.

Property Sharing PHP files (`property_share_redirect.php`, `property_share_well_known.php`) must sit in **that same directory** as `settings.php` (project root), because rewrites target them relatively.

If the live site uses the **document-root** layout (domain points at project root), deploy [`.htaccess.live`](../.htaccess.live) as the live `.htaccess` (or merge its Property Sharing rules into the existing live `.htaccess`).  
If the live site still runs under a `/herosysgro` subfolder, use the localhost-style `.htaccess` with `RewriteBase /herosysgro/` — but production App Links in the current app binary expect **root host** `sys.saifholdinggroup.com` with path `/p/...` (not `/herosysgro/p/...`).

### 6.2 Numbered Hostinger process

1. **Backup** database + current PHP tree.
2. **Apply SQL** `migrations/re_property_share_refs.sql` (§3).
3. **Upload / sync PHP + config files** from §2 (Created + Modified PHP/config). Ensure `property_share_*.php` are at document root next to `settings.php`.
4. **Ensure rewrite rules** for `.well-known` and `/p/` exist in the **active** `.htaccess` (copy from `.htaccess.live` if live is at root).
5. Confirm **HTTPS** is forced for `sys.saifholdinggroup.com` (App Links / Universal Links require HTTPS).
6. Confirm **mod_rewrite** is enabled (standard on Hostinger Apache).
7. **Folder permissions**: PHP files readable by web user; no need for a writable `.well-known` directory (dynamic). Do not make `uploads/` executable for PHP (existing policy).
8. **DNS**: `sys.saifholdinggroup.com` must resolve to this Hostinger site (same host as AndroidManifest / Associated Domains).
9. Log into ERP → **Mobile App Management** → fill Property Sharing + store URLs → **Save**.
10. **Verify** `.well-known` and a sample `/p/{code}` with `curl` (§11) **before** shipping a new store build that depends on verification.
11. **Then** build/upload Android and iOS releases that include App Links / Associated Domains (§7–8).
12. After store release: re-test installed-app open + no-app store redirect.
13. Clear Hostinger / CDN cache if `.well-known` still shows old empty fingerprints.

### 6.3 Configuration order (critical)

1. Live PHP + rewrites online  
2. DB migration  
3. ERP fields saved (especially SHA-256, Team ID, share base URL, store URLs)  
4. Confirm `.well-known` JSON looks correct in a browser/`curl`  
5. Ship mobile builds that match package/bundle/host  
6. Wait for Google/Apple association (can take hours; Apple often caches AASA)

### 6.4 Before uploading a new Android/iOS build

- [ ] Share Base URL = `https://sys.saifholdinggroup.com`
- [ ] assetlinks lists correct package + production SHA-256(s)
- [ ] AASA `appID` = `{TeamID}.com.ainalreem.living`
- [ ] Manifest host still `sys.saifholdinggroup.com`
- [ ] Entitlements still `applinks:sys.saifholdinggroup.com`

### 6.5 After deployment

- [ ] Staff Copy Share Link works on a published vacant unit  
- [ ] Resolve API available/unavailable cases  
- [ ] Installed device deep link  
- [ ] Uninstalled / browser → store 302  

---

## 7. Android configuration

### 7.1 Package name

`com.ainalreem.living`

### 7.2 Intent filter (production)

From `AndroidManifest.xml`:

- `android:autoVerify="true"`
- `VIEW` + `DEFAULT` + `BROWSABLE`
- `https://sys.saifholdinggroup.com` + `pathPrefix="/p/"`

Debug-only HTTP filters for emulator/localhost do **not** use `autoVerify`.

### 7.3 How to obtain SHA-256

**Debug (local testing):**

```bash
keytool -list -v -alias androiddebugkey \
  -keystore ~/.android/debug.keystore \
  -storepass android -keypass android
```

Copy the **SHA-256** fingerprint into ERP (colon form).

**Upload key (your release keystore):**

```bash
keytool -list -v -keystore /path/to/your-upload.keystore -alias YOUR_ALIAS
```

**Play App Signing (production App Links):**

1. Google Play Console → App → **Setup → App signing**
2. Copy **App signing key certificate** → SHA-256  
3. Also keep **Upload key certificate** SHA-256 if you still install upload-signed APKs outside Play

### 7.4 Which fingerprint goes where

| Scenario | Enter in ERP |
|----------|--------------|
| Local debug builds | Debug SHA-256 |
| Internal sideload of upload-signed AAB/APK | Upload key SHA-256 |
| Users installing from Play (normal production) | **App signing key** SHA-256 from Play Console |

You may enter **multiple** fingerprints (debug + upload + app signing) so local and production both verify. Separate by newline or comma.

### 7.5 Rebuild / release steps

1. Confirm ERP assetlinks content via curl.  
2. `flutter build appbundle` (or APK) with release signing.  
3. Upload to Play Console.  
4. After install from Play, verify App Link (Settings → Apps → Open by default / `adb shell pm get-app-links com.ainalreem.living`).

---

## 8. iOS configuration

### 8.1 Identifiers

| Item | Value |
|------|-------|
| Bundle ID | `com.ainalreem.living` |
| Team ID | **YOU MUST OBTAIN** from Apple Developer |
| Associated Domains | `applinks:sys.saifholdinggroup.com` in `Runner.entitlements` |

### 8.2 Apple Developer / Xcode checklist

- [ ] App ID has **Associated Domains** capability enabled  
- [ ] Provisioning profile regenerated after enabling capability  
- [ ] Xcode Signing uses the correct Team  
- [ ] Entitlements file included (`CODE_SIGN_ENTITLEMENTS = Runner/Runner.entitlements`)  
- [ ] ERP AASA shows `"appID": "TEAMID.com.ainalreem.living"`  

### 8.3 AASA verification

```bash
curl -I https://sys.saifholdinggroup.com/.well-known/apple-app-site-association
curl -s https://sys.saifholdinggroup.com/.well-known/apple-app-site-association
```

Expect HTTP 200, `Content-Type: application/json`, valid JSON, **no** unexpected redirects to HTML login pages.

### 8.4 Rebuild / archive

1. Save ERP Team ID + Bundle ID.  
2. Confirm AASA public URL.  
3. Archive in Xcode / `flutter build ipa`.  
4. Upload to App Store Connect / TestFlight.  
5. Install from TestFlight/App Store (Universal Links behave more reliably than pure local debug).  

### 8.5 Apple caching

Apple CDN may cache AASA for extended periods. After correcting Team ID / paths, allow time and reinstall the app. Validation tools (Apple CDN / third-party AASA validators) help confirm what Apple sees.

---

## 9. Store redirect configuration

### 9.1 Behaviour

| Case | Behaviour |
|------|-----------|
| Android, app not installed, browser opens `/p/{code}` | 302 → `play_store_url` |
| iOS, app not installed | 302 → `app_store_url` |
| Store URL missing | Plain text fallback (no store) |
| App installed + verified link | OS opens app; Flutter resolves |

### 9.2 URL formats

**Play:**

```text
https://play.google.com/store/apps/details?id=com.ainalreem.living
```

**App Store:**

```text
https://apps.apple.com/app/idYOUR_NUMERIC_APP_ID
```

### 9.3 Before apps are publicly available

- You may still deploy PHP + `.well-known` early.  
- Store redirects will 302 to draft/unpublished listing URLs if you paste them — Google/Apple may show “not found” until published.  
- Safe test: use TestFlight / internal testing tracks, or temporarily point store URLs at known public pages only for infrastructure tests (remember to set real store URLs before marketing shares).  
- Prefer validating **installed** deep links with internal builds before mass sharing.

---

## 10. Share message template

### 10.1 Approved template (paste into ERP)

```text
🏡 {title}

📍 {building}
{location}

💰 Annual Rent: {rent}

View full details, photos and features:
{url}

Shared via Ain Al Reem Living
```

### 10.2 Supported placeholders only

| Placeholder | Source | If empty |
|-------------|--------|----------|
| `{title}` | Listing title / display title | Blank string in that spot |
| `{building}` | Building name | Blank |
| `{location}` | Building address / location | Blank |
| `{rent}` | Formatted annual rent (app: `N AED / year`) or empty | Blank (emoji line may look sparse) |
| `{url}` | `https://sys.saifholdinggroup.com/p/{share_code}` | Share aborted if URL missing |

**Do not invent** placeholders such as `{bedrooms}`, `{photos}`, `{company}` — they are not replaced by `buildPropertyShareMessage` / `re_property_share_format_message`.

Flutter builds the outgoing share text from the ERP template (fetched via app-config) using on-screen listing fields + `share_url` from the detail API.

---

## 11. Testing checklist

### 11.1 Functional

| # | Test | Expected |
|---|------|----------|
| 1 | Open available published vacant unit in app | Share icon visible |
| 2 | Tap Share | Native share sheet |
| 3 | Inspect message | Approved template + correct `/p/{uuid}` URL |
| 4 | Open link on Android with app installed | App opens → existing Unit Details |
| 5 | Open link on iOS with app installed | Same |
| 6 | Confirm screen | `LongTermRentalDetailScreen` only (no alternate details UI) |
| 7 | Open link without app (Android browser) | Lands on Play Store URL |
| 8 | Open link without app (iOS Safari) | Lands on App Store URL |
| 9 | Unpublish unit (`publish_to_mobile=0`) | Resolve `available=false`, reason `unpublished`; app unavailable screen; **no** listing fields in resolve JSON |
| 10 | Occupy / active lease | Unavailable (`occupied` / eligibility fail) |
| 11 | Set `is_active=0` on share ref | Unavailable `inactive` |
| 12 | Disable Property Sharing in ERP | Unavailable `share_disabled`; Share hidden |
| 13 | Invalid / short code | Unavailable `not_found` |
| 14 | Share ref company ≠ unit company (if forced) | Unavailable / not found — no cross-company listing leak |
| 15 | Staff unit view Copy Share Link | Copies production `/p/...` URL when sharing enabled + published |

### 11.2 Association endpoints

```bash
curl -sS -D- -o /tmp/assetlinks.json \
  https://sys.saifholdinggroup.com/.well-known/assetlinks.json

curl -sS -D- -o /tmp/aasa.json \
  https://sys.saifholdinggroup.com/.well-known/apple-app-site-association

# Inspect bodies
cat /tmp/assetlinks.json
cat /tmp/aasa.json
```

Checks:

- HTTP 200  
- `Content-Type: application/json`  
- assetlinks contains `com.ainalreem.living` and expected SHA-256 list  
- AASA `appID` is `TEAMID.com.ainalreem.living`  
- Paths include `/p/*`

### 11.3 Resolve API

```bash
# Replace CODE with a real share_code from ERP unit view or DB
curl -sS "https://sys.saifholdinggroup.com/api/mobile/property-share/CODE" | jq .

# Redirect (follow off to see Location)
curl -sS -D- -o /dev/null \
  -A "Mozilla/5.0 (Linux; Android 13)" \
  "https://sys.saifholdinggroup.com/p/CODE"
```

### 11.4 Android verification helpers

```bash
adb shell pm get-app-links com.ainalreem.living
# Optional force re-verify after fixing assetlinks:
adb shell pm verify-app-links --re-verify com.ainalreem.living
```

### 11.5 Local smoke (dev)

```bash
php tests/property_share_resolve_test.php
```

---

## 12. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Link opens browser, not app | Association failed / wrong SHA / Team ID / host mismatch | Fix ERP well-known; reinstall app; wait for cache |
| Android verification fails | Missing Play **app signing** SHA-256; assetlinks empty; HTTP not HTTPS | Add correct fingerprints; confirm curl JSON |
| iOS Universal Link ignored | Wrong Team ID; Associated Domains missing; AASA 404/HTML; opened from Notes incorrectly | Fix AASA; reinstall; long-press link → Open in App |
| Wrong SHA-256 | Used upload key but users have Play-signed app (or vice versa) | Enter **both** as needed |
| Incorrect Team ID | Typo in ERP | Correct and wait for Apple cache |
| `.well-known` 404 | Rewrite missing; wrong document root; file not uploaded | Deploy `property_share_well_known.php` + htaccess rules |
| Wrong content type | Static file or middleware rewriting to HTML | Ensure dynamic PHP path; no login redirect on `.well-known` |
| Redirect loop | CDN/HTTPS misconfig on `/p/` | Inspect `curl -D-` Location chain |
| Store URL missing | Empty Play/App fields | Fill ERP store URLs |
| Unexpected unavailable | Unpublished, occupied, sharing off, inactive ref | Check unit publish/status/leases + ERP toggle |
| Cached association | `max-age=300` + Apple/Google CDN | Wait; purge Hostinger cache; reinstall app |
| Localhost limitations | Production App Links host is `sys.saifholdinggroup.com`; local HTTP filters are debug-only and not autoVerify | Use production host for real verification |

---

## 13. Release and rollback checklist

### 13.1 Pre-release

- [ ] DB backup taken  
- [ ] `re_property_share_refs` migration applied  
- [ ] PHP + `.htaccess` Property Sharing rules live  
- [ ] ERP Property Sharing enabled + Share Base URL set  
- [ ] SHA-256 / Team ID / Bundle / Package saved  
- [ ] Store URLs saved (or consciously deferred)  
- [ ] Approved share message template saved  
- [ ] `.well-known` curl checks pass  
- [ ] Resolve API smoke pass  
- [ ] No deferred-deep-link V2 work included  

### 13.2 Deployment order

1. Backup  
2. SQL migration  
3. Upload PHP/config  
4. Confirm rewrites  
5. Configure ERP  
6. Verify `.well-known` + `/p/` + resolve  
7. Release Android/iOS builds  
8. Post-release device tests  

### 13.3 Post-release verification

- [ ] Share from app on a live published unit  
- [ ] Deep link cold start → Unit Details  
- [ ] Unavailable path for unpublished unit  
- [ ] Browser without app → correct store  

### 13.4 Rollback / kill-switch

**Immediate (preferred):** Settings → Mobile App Management → **Enable Property Sharing = Off** → Save.

Effect without removing code:

- Resolve returns unavailable  
- Share CTAs / URLs suppressed  
- Existing unit/listing data untouched  

**Code rollback:** redeploy previous PHP tree and previous app binaries; optional `DROP TABLE re_property_share_refs` only if you intentionally remove share refs (§3.5).

**Safe to revert:** Property Sharing PHP files, rewrite lines, Flutter share modules, Manifest/entitlements changes.  
**Do not** drop `re_units` or Find Your Home columns as part of this rollback.

---

## Appendix A — Public URL map (production)

| URL | Handler |
|-----|---------|
| `https://sys.saifholdinggroup.com/p/{share_code}` | `property_share_redirect.php` |
| `https://sys.saifholdinggroup.com/.well-known/assetlinks.json` | `property_share_well_known.php?type=assetlinks` |
| `https://sys.saifholdinggroup.com/.well-known/apple-app-site-association` | `property_share_well_known.php?type=aasa` |
| `https://sys.saifholdinggroup.com/api/mobile/property-share/{share_code}` | `api/mobile/property_share.php` |
| `https://sys.saifholdinggroup.com/api/customer/v1/app-config` | Includes `property_share` settings |

## Appendix B — Version boundary

| In V1 | Not in V1 |
|-------|-----------|
| Installed-app App Links / Universal Links | Play Install Referrer deferred handoff |
| Store 302 when app missing | Auto-open shared unit after first install |
| Unavailable-safe resolve | Marketing property landing HTML |

---

*End of Property Sharing V1 Production Deployment Guide.*
