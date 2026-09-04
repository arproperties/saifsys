# Cursor Engineering Strategy (Self-Improvement)

**Stage:** 3  
**Purpose:** Keep future AI sessions consistent as HeroSysgro evolves.

---

## 1. How Cursor should learn from future tasks

1. Prefer **Accepted decisions** and **Confirmed** audit evidence over chat memory.  
2. After a significant architectural choice, append `docs/ERP_DECISIONS.md`.  
3. After a confirmed commercial policy, record it via `BUSINESS_RULE_CAPTURE_STANDARD.md` (Confirmed status only).  
4. Update the relevant `docs/cursor-audit/*` file when code reality changes — do not leave contradictions.  
5. Add or tighten a **rule/skill** only when a recurring mistake appears (avoid rule sprawl).

---

## 2. Confirmed business rules → permanent memory

```
Workshop / ticket confirmation
  → Draft rule (capture template)
  → Human Approved by + Date
  → Status Confirmed
  → Agents may treat as normative
```

Never promote Strong inference to Confirmed without a human.

---

## 3. How ERP_DECISIONS evolves

- New Accepted decision required before contradicting an old one.  
- Use Status `Superseded` with pointer to new DEC-ID.  
- Proposed items (e.g. DEC-009 idempotency) become Accepted only after implementation policy is agreed.

---

## 4. How audit documents evolve

| Trigger | Action |
|---------|--------|
| Posting path change | Update ACCOUNTING_ARCHITECTURE + EVENT_CATALOG |
| New module | Update ERP_MODULE_MAP + MODULE_DEPENDENCY_MAP + module rule if needed |
| Security incident | Update SECURITY_AND_CONTROLS + security rule |
| UI shell change | Update UI inventory + design system |

Mark outdated sections explicitly rather than silent overwrite.

---

## 5. Retiring deprecated rules

1. Mark decision/rule Deprecated with reason.  
2. Remove or narrow `.cursor/rules` globs that encode the old behaviour.  
3. Keep historical note in decisions log.  
4. Do not delete Legacy RE code solely because a rule was retired — needs deprecation program.

---

## 6. Integrating new modules

1. Register MODULE_* if first-class.  
2. Document ledger stack (Cleaning vs Shared vs none).  
3. Add dependency edges.  
4. Add module rule **only** if behaviour differs from generic rules.  
5. Add skills only if review procedure is specialized.

---

## 7. Session consistency checklist (agents)

- [ ] Read relevant DEC-*  
- [ ] Identify ledger stack and company scope  
- [ ] RE money work → Invoice Mode  
- [ ] Unclear policy → capture standard, do not invent  
- [ ] High-risk path → `REVIEW_WORKFLOWS.md` (WF-1…WF-7)  
- [ ] Official reports → `financial-report-validation`  
- [ ] Agents remain readonly / report-first  
- [ ] No unapproved tool runs  
- [ ] Modules without dedicated rules (Legal/Barber/Grocery/Tasks) → generic rules per `.cursor/README.md`  

---

## 8. Anti-patterns

- Duplicating the same guidance in 10 rules  
- Encoding speculative business rules in alwaysApply rules  
- Letting chat “preferences” override Accepted decisions
