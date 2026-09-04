# MCP Readiness (Do Not Install Yet)

**Stage:** 3  
**Status:** Documentation only. **Do NOT install or configure MCP in this stage.**

## Purpose
Prepare HeroSysgro for future Model Context Protocol integrations under human control.

---

## 1. Read-only MySQL MCP

| Field | Guidance |
|-------|----------|
| Benefits | Live schema/row inspection for audits; confirm Needs database confirmation items |
| Risks | Accidental writes; secret leakage; cross-company data exposure |
| Recommended permissions | **SELECT-only** DB user; no DDL/DML; restrict to non-prod first |
| Read-only requirements | Mandatory; verify tool cannot UPDATE/DELETE |
| Human approval | Required before enabling; per-environment |
| Safe strategy | Staging replica → approve → optional prod read replica later |

## 2. Browser automation MCP

| Field | Guidance |
|-------|----------|
| Benefits | UX regression checks; portal flows |
| Risks | Clicking destructive finance actions; CSRF/session issues |
| Permissions | Staging only; test users; no prod credentials in agent config |
| Approval | Required |
| Safe strategy | Dedicated QA company/tenant; block payment confirm selectors in prompts |

## 3. GitHub MCP

| Field | Guidance |
|-------|----------|
| Benefits | PR review, issues, release notes |
| Risks | Force-push; secret scanning misses; unwanted merges |
| Permissions | Least privilege; no admin; no secret decryption |
| Approval | Required for write actions |
| Safe strategy | Read PRs first; write only when user asks |

## 4. Figma MCP

| Field | Guidance |
|-------|----------|
| Benefits | Align UI with design system tokens |
| Risks | Ignoring Bootstrap constraints; inventing brand colours |
| Permissions | Read designs |
| Approval | Brand token changes need product approval |
| Safe strategy | Map Figma → `HEROSYSGRO_DESIGN_SYSTEM.md` tokens only |

## 5. Future HeroSysgro ERP MCP (custom)

| Field | Guidance |
|-------|----------|
| Benefits | Controlled tools: “trace posting”, “company-scoped query”, “run readonly diagnostics” |
| Risks | Exposing posting/repair tools; bypassing approvals |
| Permissions | Default deny; explicit allowlist of readonly tools |
| Approval | Architecture decision in `ERP_DECISIONS.md` before build |
| Safe strategy | Wrap skills as tools; never expose `gl_create_journal` or sync runners without dual approval |

---

## Global MCP rules for HeroSysgro

1. Prefer readonly.  
2. No production writes via MCP without explicit human approval each time.  
3. Never return secret values.  
4. Company isolation applies to every data tool.  
5. Financial mutations stay in approved application code paths, not MCP shortcuts.
