# Stage 2 Prioritized Roadmap

**Stage:** 2 — Recommendations only. **Not implemented.**

Legend: Business value / Risk reduction = Low | Medium | High | Very High  
Effort = S | M | L | XL · Change risk = Low | Medium | High · Priority = P0–P3

---

## Quick wins

| ID | Recommendation | Biz value | Risk ↓ | Effort | Change risk | Priority |
|----|----------------|-----------|--------|--------|-------------|----------|
| Q1 | Remove/block `.fixperms.php` from non-local | High | Very High | S | Low | P0 |
| Q2 | Deny HTTP execution of `cron/*.php` (CLI + secret) | Very High | Very High | S | Low | P0 |
| Q3 | Require env JWT secrets (fail closed); restrict CORS | Very High | Very High | S | Low | P0 |
| Q4 | CSRF on `ajax_document_upload.php` + MIME checks | High | Very High | S | Low | P0 |
| Q5 | Verify `APP_ENV=prod` / display_errors off on live | High | High | S | Low | P0 |
| Q6 | Label Legacy RE UI “Historical”; IM primary copy | High | Medium | S | Low | P0 |
| Q7 | Add mobile drawer to RE layout (copy Construction) | High | Medium | M | Low | P0 |
| Q8 | Dedupe Bootstrap/Select2 on `lease_add.php` | Medium | Low | S | Low | P2 |
| Q9 | Stay login CSRF + rate limit | High | High | S | Low | P1 |
| Q10 | Fail closed when company missing on financial writes | Very High | High | M | Medium | P1 |
| Q11 | Empty-state + loading patterns on top RE lists | Medium | Low | S | Low | P2 |
| Q12 | Paginate `tenants.php` / `units.php` first | High | High | M | Low | P1 |

---

## Medium-term improvements

| ID | Recommendation | Biz value | Risk ↓ | Effort | Change risk | Priority |
|----|----------------|-----------|--------|--------|-------------|----------|
| M1 | Paginate all heavy RE lists + outstandings split | Very High | Very High | L | Medium | P0 |
| M2 | Wire dashboards to `CachingService` | High | High | M | Low | P1 |
| M3 | Persistent login rate limit; retire MD5/plaintext | Very High | Very High | M | Medium | P0 |
| M4 | Full AJAX CSRF audit + header tokens | High | Very High | M | Medium | P0 |
| M5 | Upload pipeline: MIME + no PHP in uploads | High | Very High | M | Medium | P0 |
| M6 | Extract Cleaning `accounts`/`operation` shared layout | Medium | Low | M | Medium | P1 |
| M7 | Page header standard across modules | Medium | Low | M | Low | P2 |
| M8 | Financial UI status vocabulary on IM screens | High | Medium | M | Low | P1 |
| M9 | Cron N+1 batching (collections, penalties) | High | High | M | Medium | P1 |
| M10 | Period-lock fail-closed | High | Medium | S | Low | P1 |
| M11 | Accessibility pass: labels, focus, modal ARIA on top 20 pages | Medium | Medium | M | Low | P2 |
| M12 | Capture confirmed commercial rules via Business Rule standard | Very High | High | M | Low | P1 |

---

## Strategic improvements

| ID | Recommendation | Biz value | Risk ↓ | Effort | Change risk | Priority |
|----|----------------|-----------|--------|--------|-------------|----------|
| S1 | Staff design-system adoption (tokens + shared shell) | High | Medium | L | Medium | P2 |
| S2 | Lease create wizard without removing fee/cheque flexibility | Very High | High | L | High | P1 |
| S3 | Unify bank-reco UX patterns (not necessarily one DB) | Medium | Medium | L | High | P2 |
| S4 | PDF brand template standardization | Medium | Low | M | Medium | P3 |
| S5 | Maker/checker policy implementation (after business rules confirmed) | High | High | L | High | P2 |
| S6 | Asset pipeline / self-host Bootstrap | Low | Low | L | Medium | P3 |
| S7 | RTL program if required | Medium | Low | L | Medium | P3 |

---

## Explicit non-implementation

Nothing in this roadmap was built in Stage 2.
