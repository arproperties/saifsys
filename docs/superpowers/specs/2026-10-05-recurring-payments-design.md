# Recurring Payments — design

Date: 2026-10-05

## Purpose

Track money that should come in every month from things inside a building — for
example the washing machine rented out in Ayla, or a shop in the boys camp. The user
makes one entry once; the system creates that month's payment line by itself on a
chosen day, with status Pending, and the user marks it Paid when the money arrives.

It is a tracker only. It does not create invoices and does not post to accounting.

## Where things live

Decided by the user: Reem keeps the data and the rules, saifsys only draws the
screens. Same shape as Building Inventory (`modules/building_inventory/`, Reem
`server/inventory.js`). No saifsys tables, no SQL file, no `api/jarvis/v1` module.

On the Windows PC Reem's code is `D:/Francis/projects/2026/Apps/Solutions/ai-agent`
(dev server on port 3001, Postgres in Docker on 5433), so both halves are built and
tested here.

## An entry

| Field | Notes |
|---|---|
| Title | required, e.g. "Washing machine rent" |
| Building | required, picked from Reem's building list |
| Shop / unit / other | free text, optional, e.g. "Shop 3", "Ground floor laundry" |
| Amount | required, more than 0, AED |
| Day of month | required, 1–31 |
| Auto-create | on/off, default on |
| First month | default the current month |
| Status | active or paused |
| Notes | optional |

## A payment

One line per entry per month: the month, the due date, the amount (copied from the
entry when the line is created), and status **pending** (default) or **paid**, with
who marked it paid and when, and an optional note.

## Rules (all decided in Reem)

- On each entry's day of the month, Reem creates that month's payment as Pending.
- One payment per entry per month, never two. Enforced by a unique key on
  (entry, month), so running the creator twice is harmless.
- Catch-up: if Reem was not running on the day, the next run creates every missed
  month from the entry's first month up to today.
- Day 29–31 in a shorter month means the last day of that month.
- Auto-create off, or entry paused: no new payments. Existing ones stay.
- Changing an entry's amount or day affects future payments only: a payment already
  created keeps its amount and due date. Title, building and shop/unit show as they
  are now.
- "Create now" makes this month's payment by hand, for an entry with auto-create off
  or ahead of its day.
- A payment can be marked Paid, and set back to Pending.
- An entry with payments is not deleted, only paused. An entry with none can be
  deleted.
- Who may see and change: Reem's own rule. Assumed: the master, and the building
  administrator for their own buildings. saifsys never decides this.

## Reem side

- `server/db.js`: two tables, `recurring_payments` (the entries) and
  `recurring_payment_dues` (the monthly lines), added with `CREATE TABLE IF NOT
  EXISTS` so they are safe on every start.
- `server/recurringPayments.js`:
  - the creator: one function that, given today's date, inserts every missing due
    line. Called on start and once a day.
  - the router:
    - `GET /` — payments for a month, filter by building and status, with pending
      and paid totals
    - `GET /entries`, `POST /entries`, `PUT /entries/:id`, `DELETE /entries/:id`
    - `POST /dues/:id/paid`, `POST /dues/:id/pending`
    - `GET /buildings` — the buildings this person may use
- `server/index.js`: mount the router for Reem's own use, and behind the door:
  `app.use('/api/from-saifsys/recurring-payments', fromSaifsys, recurringPaymentsRoutes);`
  above `app.use('/api', requireUser)`.
- The person at the screen is the HR employee code (`X-Saifsys-Employee`), matched
  through `hr_links`.
- Refusals are plain sentences; saifsys shows them as they are.
- Tests in `tests/`: the creator (normal month, short month, catch-up, paused,
  auto-create off, run twice), and the permission rule.

## saifsys side

New folder `modules/recurring_payments/`:

- `index.php` — the month's payments: building, title, shop/unit, due date, amount,
  status; pending and paid totals; filters for month, building, status; Mark paid /
  Set pending buttons.
- `entries.php` — the list of entries, with pause/resume and delete.
- `entry_form.php` — add and edit an entry.
- `includes/rpay_helper.php` — one function that calls Reem at
  `/api/from-saifsys/recurring-payments`, reusing the call, the employee-code lookup
  and the error class from `modules/building_inventory/includes/binv_helper.php`.
- `includes/rpay_layout_header.php`, `rpay_layout_footer.php` — slim layout.

Every page: `require_login`, call Reem, draw. `csrf_field()` / `csrf_verify()`,
redirect after every POST, `h()` on all output. If Reem refuses, the page shows
Reem's sentence. If Reem is down, the page says so.

Launcher: a constant in `includes/module_access.php` and
`includes/rbac_department.php`, an icon in `select-module.php`, and in
`get_user_departments()` a try/catch that asks Reem whether to show it (session
cache ten minutes, 4-second wait, any failure = not shown). The exact lines are
shown to the user before these three files are touched.

## Testing

- Reem: its own tests against the local Postgres on the Mac.
- saifsys: a stand-in server in the scratchpad that answers like the Reem routes,
  `REEM_URL` pointed at it, pages driven with curl using a scripted session.
  `php -l` on every file. Launcher lines tried on a scratch copy first.

## Order of work

1. Reem: tables, creator, routes, tests (Mac).
2. saifsys: helper and the three pages, against the stand-in.
3. Launcher lines, after the user's yes.
4. Hand-over: saifsys upload list, Reem deploy list, config lines; Reem deployed
   before saifsys is uploaded.

## Not included

Invoices, receipts, accounting posting, part payments, reminders or messages to the
payer, frequencies other than monthly, linking to tenants or lease contracts.
