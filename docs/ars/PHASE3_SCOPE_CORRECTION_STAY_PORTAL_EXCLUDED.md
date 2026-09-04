# Phase 3 — Scope Correction: Stay Portal Excluded

**Status:** Approved / mandatory  
**Date:** 2026-07-17  
**Supersedes:** Any prior Phase 3 / Phase 3A / Phase 3B instruction that included Stay portal modernization, Stay design-system work, Phase 3D Stay Portal Experience, or guest-facing web redesign.

---

## 1. Business decision

The **ARS Stay web portal** (`/stay/`) is **no longer an active business channel**.

The **official guest-facing platform** is the published **mobile application** on:

- Apple App Store  
- Google Play Store  

Guests use the mobile app for booking, registration/login, booking management, profile, payments, and self-service.

---

## 2. Stay portal classification

| Classification | Meaning |
|----------------|---------|
| **LEGACY — INACTIVE BUSINESS CHANNEL — OUT OF MODERNIZATION SCOPE** | May remain in the codebase for compatibility. Must not be redesigned, modernized, or extended under Phase 3. |

**Do not delete** the Stay portal unless separately authorized.

**Do not:**

- Redesign, modernize, refactor, replace, or extend Stay UI  
- Create Stay-specific design system / components / prototypes  
- Allocate Phase 3 waves, a11y modernization, or responsive redesign to Stay  
- Load new Phase 3 assets (`#ars-app`, Tailwind build, Alpine/Lucide staff bundles) into Stay pages  
- Include `stay/` or `modules/ars/stay/` in Tailwind content scanning  

---

## 3. Mobile application protection

The published guest mobile app is **outside** Phase 3 UI redesign scope.

**Do not modify:**

- Mobile screens / design / auth behaviour  
- Guest booking, payment, authentication, or profile APIs  
- Existing mobile API contracts or behaviour  

Internal staff UI work must **preserve** all existing mobile/customer API contracts.

---

## 4. Official Phase 3 scope

Phase 3 is **exclusively** for the **internal ARS staff / admin** system:

Command Center, Reservations, Booking Wizard, Booking Workspace, Occupancy Calendar, staff Guests/Units, Operations (HK/Maint), Activity Center, Finance presentation, Reports, Settings, internal responsive/a11y.

The word **“Guest”** in Phase 3 means **staff-managed guest records**, not guest-facing web development.

---

## 5. Acceptance counting

| Surface | Modernization acceptance |
|---------|--------------------------|
| Internal ARS staff pages | In scope |
| Stay portal pages | **Excluded** — compatibility protection only |
| Mobile app / customer APIs | **Excluded** — no-change protection |

Stay pages are **not** counted toward Phase 3 modernization completion.

---

## 6. Roadmap update

| Item | Status |
|------|--------|
| Phase 3A | ✔ Approved |
| Phase 3B Wave 0 | Authorized (foundation only) |
| Phase 3B Waves 1–10 | Require separate per-wave authorization |
| Phase 3D Stay Portal Experience | **Removed** — does not exist |
| Stay portal redesign waves | **Removed** |

---

## 7. Wave 0 scope (updated)

Wave 0 builds the **internal staff** design-system foundation only:

- Scoped Tailwind under `#ars-app`  
- Tokens, Alpine, Lucide, PHP components, isolated showcase  
- Bootstrap coexistence proof on **staff** pages  
- Explicit **Stay portal no-change** and **mobile API no-change** verification  

Wave 0 does **not** redesign operational pages, replace routes, or touch Stay/mobile/financial core.

---

## 8. Related document corrections

Focused updates applied to:

- `PHASE3A_FINAL_EXPLANATION.md`  
- `PHASE3A_MODULE_UI_INVENTORY.md`  
- `PHASE3A_PAGE_MODERNIZATION_MATRIX.md`  
- `PHASE3A_RESPONSIVE_STRATEGY.md`  
- `PHASE3A_FRONTEND_ARCHITECTURE.md`  
- `PHASE3A_RISK_AND_REGRESSION_PLAN.md`  
- `PHASE3B_IMPLEMENTATION_PLAN.md`  

Canonical reference for this decision: **this file**.
