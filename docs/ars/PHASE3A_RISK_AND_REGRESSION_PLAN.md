# Phase 3A — Risk & Regression Plan

| Risk | Prevention | Test |
|------|------------|------|
| Accidental financial core edit | Freeze rule + PR checklist + protected file list | Diff vs Class A/B; refuse edits |
| Legacy CSS conflicts | Scoped `#ars-app`; coexistence plan | Visual check ERP + ARS |
| Route breakage | Parallel routes / redirects; no delete until wave done | Smoke all 26 pages |
| Permission leakage | Menu server-side; permission-review | Role matrix tests |
| AJAX contract breakage | UI wrappers only; same payloads | ajax smoke + 2D UAT |
| Mobile API breakage | Do not touch customer APIs | API smoke |
| Performance (calendar) | Virtualize; range queries | Large inventory test |
| A11y regression | Standard + wave checklist | Keyboard + axe |
| Missing actions | Matrix sign-off | Action inventory |
| Wrong status presentation | Semantic map + icon+text | Status dictionary QA |
| Old/new UI inconsistency | Wave migration; avoid mixed chrome mid-page | Design review |
| Browser compat | Target modern evergreen; test Safari/Chrome | Manual |
| Incomplete responsive | Mobile strategy acceptance | Device lab |
| Adapter left ON | Always restore OFF after tests | Flag assert in UAT |
| Drag-drop financial mutation | Forbidden without confirm+API | Explicit ban test |

**Regression suites to reuse:** Phase 2D UAT (24), Phase 2C subsets, Activity Center smoke, booking lifecycle smoke.


| Accidental Stay portal modernization | Scope correction doc; exclude from content scan & waves | Stay no-change checksum |
| Mobile API breakage from staff UI | Never edit customer APIs in Phase 3 UI waves | API no-change verify |
