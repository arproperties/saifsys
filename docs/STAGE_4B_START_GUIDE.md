# Stage 4B Start Guide — How to Enhance the ERP

**Audience:** Developers and Cursor agents working on HeroSysgro after Engineering Platform V1 freeze.  
**Prerequisite:** Read `docs/ENGINEERING_PLATFORM_V1.md` and `docs/ERP_DECISIONS.md`.

This guide does **not** implement features. It defines how every future enhancement must be executed.

---

## Mandatory workflow

```
Business Problem
    ↓
Planning
    ↓
Impact Review
    ↓
Human Approval
    ↓
Implementation
    ↓
Review
    ↓
Testing
    ↓
Acceptance
    ↓
Merge
    ↓
Documentation
    ↓
Update ERP_DECISIONS if required
```

---

## Step details

### 1. Business Problem
State the user/ops/finance problem. If commercial policy is unclear, **do not invent** — open a Draft entry process via `docs/BUSINESS_RULE_CAPTURE_STANDARD.md` and get human confirmation before coding policy.

### 2. Planning
- Identify modules and ledger stack (Cleaning `gl_*` vs Shared `re_*`).
- For RE: Invoice Mode only for new work; Legacy = historical.
- Map to Evolution Roadmap phase (prefer **1A → 1B → 1C** before Phase 2).
- List files likely touched.

### 3. Impact Review
Run the matching workflow from `docs/cursor-audit/REVIEW_WORKFLOWS.md`:

| Change type | Workflow |
|-------------|----------|
| Posting / allocation / VAT / deposits / refunds / CN | **WF-1** |
| Migrations / schema / sync tools | **WF-2** |
| Release / deploy | **WF-3** |
| Financial UI / multi-step money UX | **WF-4** |
| API / portal | **WF-5** |
| Bugfix | **WF-6** |
| Lists / reports / cron / PDF / dashboards | **WF-7** |

Use Skills named in that workflow. Invite readonly Agents for deep review.

### 4. Human Approval
**Mandatory before:**
- Any posting / reversal / allocation / VAT / deposit / refund behaviour change  
- Applying migrations or running sync/repair/posting scripts  
- Production deploy  
- New public API endpoints  
- Changing who may post/reverse/refund (no invented monetary thresholds)  
- Changing official report totals or recognition basis  
- Contradicting or superseding an Accepted decision  

### 5. Implementation
- Follow `docs/ERP_DEVELOPMENT_GUIDE.md` and active `.cursor/rules`.
- Prefer existing helpers; correct journal API signatures.
- Fail closed on missing company for financial writes (target).
- Do not extend Legacy RE accounting.
- Do not rewrite the ERP.

### 6. Review
- `code-review` skill + readonly `code-reviewer` agent.
- Re-run domain skills if money/security/schema touched.
- Agents **report only** — they do not implement.

### 7. Testing
- Manual scenarios from `regression-analysis`.
- For official reports: `financial-report-validation`.
- Prefer fixtures; no unapproved production experiments.

### 8. Acceptance
Product/finance owner confirms behaviour matches Confirmed rules and Approved plan.

### 9. Merge
Only after blockers cleared and approvals recorded.

### 10. Documentation
Update relevant `docs/cursor-audit/*` if reality changed. Do not leave contradictions.

### 11. Update ERP_DECISIONS if required
Append a new DEC when architecture, ledger boundaries, or platform policy changes. Never silently contradict Accepted decisions — supersede explicitly.

---

## When to use Rules

- Automatically when editing matching files (globs / alwaysApply).  
- Treat as mandatory constraints, not suggestions.  
- Hierarchy map: `.cursor/README.md`.

## When to use Skills

- Explicitly at Impact Review, Review, Testing, and Release steps.  
- Always use the skill named by the active WF-*.  
- Official financial reports → always include `financial-report-validation`.

## When to involve Agents

- Deep readonly review (architecture, accounting, security, performance, UI, release).  
- After non-trivial diffs (`code-reviewer`).  
- Never as an implementation substitute.

## When human approval is mandatory

See step 4. If unsure, escalate — do not assume approval.

## When to update ERP_DECISIONS

- New Accepted architectural choice  
- Superseding an old decision  
- Formal program to change ledger separation, IM/Legacy policy, or platform freeze exceptions  

## When to update BUSINESS_RULE_CAPTURE_STANDARD / confirmed rules

- After a human confirms a commercial/operational policy  
- Status moves Draft → Confirmed with Approver + Date  
- Never promote Strong inference to Confirmed without a human  

---

## Phase guidance for Stage 4B

Start with Evolution Roadmap:

1. **Phase 1A** — Security / ops / infrastructure  
2. **Phase 1B** — Accounting integrity / company isolation  
3. **Phase 1C** — Low-risk performance / usability  

**Do not start Phase 2 service extraction until Phase 1B is accepted.**

---

## Stop conditions (any enhancement)

- Would invent a business rule  
- Would extend Legacy RE accounting  
- Would mix Cleaning and Shared ledgers incorrectly  
- Would run sync/repair/posting tools without explicit approval  
- Would bypass WF human-approval gates  
