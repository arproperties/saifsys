# Security and Controls Audit

**Stage:** 2  
**Date:** 2026-07-10  
**Scope:** Read-only. **No secret values documented** — paths and secret *types* only.

## Evidence labels

Confirmed from code | Strong inference | Needs business confirmation | Not found

---

## Strengths (baseline)

| Control | Path / pattern | Evidence |
|---------|----------------|----------|
| Session cookies | httponly, SameSite, secure when HTTPS — `includes/auth.php` | Confirmed from code |
| CSRF on many forms | `csrf_field` / `csrf_verify` | Confirmed from code |
| Password upgrade | bcrypt/argon2 preferred on login | Confirmed from code |
| Module / role / permission layers | `module_access`, `require_role`, `permissions.php`, `rbac_department` | Confirmed from code |
| Worker sandbox | `lib/Guard.php` | Confirmed from code |
| RE period lock | `is_period_locked` / `re_fiscal_years` | Confirmed from code |
| Prepared statements dominant | PDO prepare/bind | Confirmed from code |
| Tenant portal CSRF + rate limit | `tenant_auth` / login | Confirmed from code |

---

## Issues

### SEC-01 — JWT secrets with hardcoded/placeholder fallbacks
| Field | Content |
|-------|---------|
| Pattern | `api/mobile/config.php` (`JWT_SECRET` env or fallback); `includes/customer_api.php` (`CUSTOMER_API_JWT_SECRET` or placeholder) |
| Evidence | Confirmed from code |
| Risk | **Critical** |
| Scenario | Forged mobile/customer tokens if fallback used |
| Business impact | Data breach; fraudulent bookings/portal access |
| Recommendation | Fail closed if env secret missing; rotate tokens |
| Priority | P0 |
| Immediate remediation needed | **Yes** (ops/config — not done in this audit) |

### SEC-02 — CORS `Access-Control-Allow-Origin: *` on APIs
| Field | Content |
|-------|---------|
| Pattern | Mobile + customer API config |
| Evidence | Confirmed from code |
| Risk | High |
| Scenario | Browser abuse with stolen Bearer tokens |
| Recommendation | Allowlist origins |
| Priority | P0 |
| Immediate | Yes (config) |

### SEC-03 — Web-reachable cron scripts without auth
| Field | Content |
|-------|---------|
| Pattern | `cron/send_outbox.php`, `cleanup_cache.php`, `process_scheduled_reports.php` (and similar) lack HTTP auth |
| Evidence | Confirmed from code |
| Risk | **Critical** |
| Scenario | Anyone triggers emails/reports/cache wipe |
| Recommendation | CLI-only + secret header + web-server deny |
| Priority | P0 |
| Immediate | Yes |

### SEC-04 — `.fixperms.php` web-callable chmod helper
| Field | Content |
|-------|---------|
| Pattern | Root `.fixperms.php` — no auth; chmod 0777 upload dirs |
| Evidence | Confirmed from code |
| Risk | **Critical** if on production |
| Scenario | World-writable uploads → webshell |
| Recommendation | Remove from non-local; never deploy |
| Priority | P0 |
| Immediate | Yes |

### SEC-05 — Login rate limit session-only; legacy password acceptance
| Field | Content |
|-------|---------|
| Pattern | `login.php` — 5/15min in session; still accepts MD5/plaintext then upgrades |
| Evidence | Confirmed from code |
| Risk | High |
| Scenario | Brute force via session rotate; weak hash guessing |
| Recommendation | Persistent rate limit; force reset non-bcrypt |
| Priority | P0 |
| Immediate | Plan yes |

### SEC-06 — Upload validation gaps + CSRF gap on RE document AJAX
| Field | Content |
|-------|---------|
| Pattern | `lease_add.php` extension-only; `ajax_document_upload.php` no CSRF; uploads may be web-reachable |
| Evidence | Confirmed from code |
| Risk | High |
| Scenario | Malicious file / CSRF upload |
| Recommendation | finfo MIME; CSRF; deny script execution in uploads |
| Priority | P0 |
| Immediate | Yes |

### SEC-07 — Stay portal login lacks CSRF and rate limit
| Field | Content |
|-------|---------|
| Pattern | `stay/login.php` |
| Evidence | Confirmed from code |
| Risk | High |
| Scenario | Brute force guest accounts |
| Recommendation | Match tenant portal controls |
| Priority | P1 |
| Immediate | Recommended |

### SEC-08 — CSRF helper POST-body only; AJAX inconsistent
| Field | Content |
|-------|---------|
| Pattern | `csrf_verify()` on `$_POST['_csrf']`; some AJAX OK (accounts bank reco), some not |
| Evidence | Confirmed from code |
| Risk | High |
| Scenario | Cross-site state change on missing-token endpoints |
| Recommendation | Header token support; audit all POST AJAX |
| Priority | P0/P1 |
| Immediate | Audit + fix gaps |

### SEC-09 — Company fallback `?: 1` and set_company without central enforce
| Field | Content |
|-------|---------|
| Pattern | Widespread `current_company_id($conn) ?: 1`; `set_current_company` does not itself validate access |
| Evidence | Confirmed from code |
| Risk | Medium–High |
| Scenario | Wrong-company reads/writes |
| Recommendation | Fail closed; validate in setter |
| Priority | P1 |
| Immediate | Recommended for financial writes |

### SEC-10 — Period lock fail-open on exception
| Field | Content |
|-------|---------|
| Pattern | `is_period_locked` returns false on DB error |
| Evidence | Confirmed from code |
| Risk | Medium |
| Scenario | Post into closed period when check fails |
| Recommendation | Fail closed |
| Priority | P1 |
| Immediate | Recommended |

### SEC-11 — CSP allows `'unsafe-inline'`
| Field | Content |
|-------|---------|
| Pattern | `auth.php` headers |
| Evidence | Confirmed from code |
| Risk | Medium |
| Scenario | XSS more damaging |
| Recommendation | Nonces gradually |
| Priority | P2 |
| Immediate | No |

### SEC-12 — Per-page authorization discipline
| Field | Content |
|-------|---------|
| Pattern | No front controller; each PHP must include gates |
| Evidence | Confirmed from code / Strong inference |
| Risk | Medium–High |
| Scenario | New endpoint forgets `require_*` |
| Recommendation | Checklist + future static review skill |
| Priority | P1 |
| Immediate | Process |

### SEC-13 — Approvals exist but not true maker/checker
| Field | Content |
|-------|---------|
| Pattern | Expense/WO/tenant-service approvals; same elevated roles often both sides |
| Evidence | Confirmed from code |
| Risk | Medium |
| Scenario | Self-approval of financial documents |
| Recommendation | Needs business confirmation for segregation rules — do not invent thresholds |
| Priority | P1 |
| Immediate | Policy |

### SEC-14 — Impersonation
| Field | Content |
|-------|---------|
| Pattern | — |
| Evidence | **Not found** |
| Risk | — |
| Recommendation | N/A |

### SEC-15 — SQL injection
| Field | Content |
|-------|---------|
| Pattern | Dominant prepared statements; occasional int interpolation anti-pattern |
| Evidence | Confirmed from code |
| Risk | Low–Medium where raw |
| Recommendation | Bind all parameters |
| Priority | P2 |

### SEC-16 — XSS
| Field | Content |
|-------|---------|
| Pattern | Widespread local `h()` htmlspecialchars |
| Evidence | Confirmed from code |
| Risk | Medium if missed on new fields |
| Recommendation | Standard escape helper always |
| Priority | P2 |

### SEC-17 — Duplicate submissions / race
| Field | Content |
|-------|---------|
| Pattern | Limited idempotency (journals partial); double-click risk |
| Evidence | Confirmed from code / Strong inference |
| Risk | Medium |
| Recommendation | Idempotency keys on money posts |
| Priority | P2 |

### SEC-18 — PDF/download access
| Field | Content |
|-------|---------|
| Pattern | Mixed; some authenticated generators; upload direct access risk |
| Evidence | Confirmed from code / Strong inference |
| Risk | Medium–High |
| Recommendation | Authz on every download; no public upload exec |
| Priority | P1 |

### SEC-19 — APP_ENV / display_errors
| Field | Content |
|-------|---------|
| Pattern | `includes/config.php` — if left as `dev` on live |
| Evidence | Confirmed from code |
| Risk | High if misconfigured |
| Recommendation | Enforce prod on live |
| Priority | P0 |
| Immediate | Ops verify |

---

## Explicit non-changes

No security fixes applied in Stage 2. Secrets not exposed.
