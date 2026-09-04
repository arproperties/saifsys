# Phase 3A — Operations Blueprint

Covers Housekeeping, Maintenance, readiness, unit status, guest requests, tasks.

---

## Housekeeping board

| View | Use |
|------|-----|
| Kanban | Dirty → In progress → Inspect → Ready |
| Schedule | By due time / checkout time |
| Assignment | By staff |

Fields: Unit, booking, priority, due time, SLA, assignee, evidence photo, complete confirm → Activity event.

## Maintenance board

Kanban: Open → Assigned → In progress → Blocked unit → Done.  
Link to calendar blocks; escalate overdue.

## Check-in readiness

Composite: unit Ready + deposit policy met + unpaid alert + guest docs. Shown on Command Center + Workspace.

## Check-out inspection

Checklist: damages → optional damage charge (adapter) → deposit decision → HK dirty.

## Guest requests / tasks

Lightweight task list linked to booking; mobile complete.

## Mobile

Large tap targets; offline-tolerant “queue sync later” **Needs confirmation** (not inventing offline store). Start with online-only + clear error on network fail.

## Activity Center

Every start/complete/assign/escalate writes activity via existing writers.
