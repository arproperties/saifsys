# Commercial ERP Readiness

**Stage:** 1.5  
**Date:** 2026-07-10  
**Question:** Can this architecture become a commercial ERP product without a rewrite?

**Overall verdict:** **Capable foundation, not yet commercial-ready.** Strong domain coverage and a real dual-ledger design; weak productization (idempotency, modular packaging, tests, plugin surface, onboarding docs).

Scores: 1 = not ready … 10 = commercial-grade.

---

## Maintainability — 4/10
| | |
|--|--|
| **Reason** | Large procedural PHP files; dual RE modes; three bank-reco clones; shared engine with many callers. |
| **Strengths** | Named helpers; some engines (receipt, invoice, cheque lifecycle); migrations folder. |
| **Weaknesses** | God-files (`accounting_integration.php`); legacy+IM coexistence; inconsistent APIs. |
| **Recommendation** | Facade services; freeze legacy; split integration file by domain event. |

## Scalability — 5/10
| | |
|--|--|
| **Reason** | Single MySQL schema; indexes present; no evidence of horizontal scale design. |
| **Strengths** | Company-scoped rows; caching service exists; schema snapshot tooling. |
| **Weaknesses** | High-churn tables; app-level dup checks; shared hosting assumptions. |
| **Recommendation** | Index/idempotency hardening; archive strategy for audit/outbox; avoid microservices. |

## Modularity — 5/10
| | |
|--|--|
| **Reason** | Module folders + MODULE_* registry exist; shared engine couples RE/CO/ARS. |
| **Strengths** | Clear module directories; RBAC module keys. |
| **Weaknesses** | Cross-requires into RE accounting from other modules; tasks not registered. |
| **Recommendation** | Stable accounting API boundary; modules depend on services not RE paths. |

## Accounting integrity — 5/10
| | |
|--|--|
| **Reason** | Double-entry present; period lock on shared; gaps in idempotency and some broken posters. |
| **Strengths** | Invoice Mode event model; engine duplicate check; audit tables. |
| **Weaknesses** | Cleaning dup risk; CN reference_id=0; legacy PDC without GL; refund signature bug. |
| **Recommendation** | Fix critical ATDs; Invoice Mode as sole new path; DB uniqueness after cleanup. |

## Performance — 5/10
| | |
|--|--|
| **Reason** | Works for current volumes (snapshot AI proxies); optimized views exist; risk as tenants grow. |
| **Strengths** | Indexes on journals/orders; dashboard cache. |
| **Weaknesses** | Heavy page scripts; dual outstandings queries; N+1 risk in large PHP pages. |
| **Recommendation** | Profile top reports; pagination standards; archive old journals. |

## Documentation — 4/10
| | |
|--|--|
| **Reason** | Many root markdown notes; Stage 1/1.5 audit pack improving; no single product architecture bible yet. |
| **Strengths** | Feature guides; inventory/SM docs; now `ERP_DECISIONS.md`. |
| **Weaknesses** | Scattered; some outdated vs Invoice Mode. |
| **Recommendation** | Master docs in later stage; mark legacy docs Historical. |

## Developer onboarding — 3/10
| | |
|--|--|
| **Reason** | New engineers must discover dual stacks and dual RE modes by reading code. |
| **Strengths** | Module map / decisions starting. |
| **Weaknesses** | No enforced architecture guide in Cursor yet; empty global header/footer. |
| **Recommendation** | Stage 3 rules/skills; onboarding checklist from decisions log. |

## Testing readiness — 2/10
| | |
|--|--|
| **Reason** | Minimal ad-hoc tests; no project PHPUnit suite found. |
| **Strengths** | Some diagnostics pages; accounting health service. |
| **Weaknesses** | No automated posting regression suite. |
| **Recommendation** | Pilot PHPUnit on JournalService adapters + Invoice Mode receipt confirm. |

## Deployment readiness — 5/10
| | |
|--|--|
| **Reason** | Shared hosting + `.htaccess.live`; migrations exist; sync tools powerful but dangerous. |
| **Strengths** | Deployment markdown guides; composer lock. |
| **Weaknesses** | Secrets in config files; manual migration discipline; no standard release checklist automation. |
| **Recommendation** | Env-based secrets; release-readiness skill later; never auto-run sync. |

## Licensing readiness — 3/10
| | |
|--|--|
| **Reason** | Multi-company exists; no clear commercial license entitlement engine found in this audit. |
| **Strengths** | Company/module access can gate features. |
| **Weaknesses** | Not productized as SKUs/editions. |
| **Recommendation** | Separate commercialization project on top of `module_access`. |

## Customization readiness — 4/10
| | |
|--|--|
| **Reason** | Settings keys and per-company COA allow some config; heavy code forks likely for clients. |
| **Strengths** | Branding; VAT config; accounting mode settings. |
| **Weaknesses** | No plugin hooks; logic in page scripts. |
| **Recommendation** | Event hooks around AccountingPostingService. |

## Plugin architecture readiness — 2/10
| | |
|--|--|
| **Reason** | Not found as a plugin system. |
| **Strengths** | Composer vendor for libraries. |
| **Weaknesses** | No extension API. |
| **Recommendation** | Defer plugins until service boundaries exist. |

## API readiness — 5/10
| | |
|--|--|
| **Reason** | Mobile + customer v1 + POS APIs exist; staff app is page-based. |
| **Strengths** | JWT customer API front controller. |
| **Weaknesses** | Accounting not exposed as stable public API; auth secret fallbacks. |
| **Recommendation** | Internal accounting API first; harden secrets. |

## Multi-company readiness — 6/10
| | |
|--|--|
| **Reason** | Core model is multi-company; gaps in fallbacks and some list queries. |
| **Strengths** | `user_companies`, `company_id` on financial headers. |
| **Weaknesses** | `?: 1` fallbacks; SM recurring list; reverse-by-id. |
| **Recommendation** | Fail-closed company context everywhere. |

## Multi-tenant readiness (SaaS future) — 2/10
| | |
|--|--|
| **Reason** | Multi-company ≠ SaaS multi-tenant isolation (shared DB, shared code, shared hosting). |
| **Strengths** | Company row isolation starting point. |
| **Weaknesses** | No tenant provisioning, noisy-neighbor controls, or per-tenant deploy. |
| **Recommendation** | Treat SaaS as a later product line; harden multi-company first. |

---

## Commercial readiness summary

| Theme | Score |
|-------|------:|
| Domain fit (RE, Cleaning, Construction, HR) | Strong |
| Product engineering maturity | Weak–moderate |
| Accounting control maturity | Moderate with critical gaps |
| Safe path to commercial | Incremental service boundaries + Invoice Mode-only RE + tests — **not a rewrite** |
