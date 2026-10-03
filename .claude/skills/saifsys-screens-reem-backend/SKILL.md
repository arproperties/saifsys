---
name: saifsys-screens-reem-backend
description: Build a new saifsys module as screens only, with the data and the rules kept in the Reem app (~/Sites/jarvis). Use whenever the user asks for a new module, page or feature in saifsys that Reem already has or should own, says things like "saifsys is frontend only", "my backend is in Reem", "same as building inventory", "link it with Reem", or pastes a spec that asks for new saifsys tables or an api/jarvis/v1 module for something Reem keeps. Covers checking Reem first, the door Reem opens for saifsys, the saifsys client and pages, the launcher lines, testing both sides on this Mac, and the upload lists.
---

# A saifsys module that is only screens, with Reem behind it

The user's rule, said on 2026-10-03 while building Building Inventory: "saifsys I want
to use as frontend layer only, because my backend + AI is already built in the Reem
application." One list, kept in Reem; saifsys draws it. Building Inventory
(`modules/building_inventory/`, Reem `server/inventory.js`) is the worked example —
copy its shape.

He wants short, plain answers, one question at a time, and no long breakdowns. He
uploads saifsys files himself and deploys Reem himself. Do not commit or deploy.

## 1. Read Reem before writing anything

Look in `~/Sites/jarvis/server/` for the feature (`grep -ril <word> server`). Three
cases:

- **Reem has it** (tables in `server/db.js`, a router in `server/<name>.js`): saifsys
  gets screens only. No saifsys tables, no SQL file, no `api/jarvis/v1` module.
- **Reem does not have it but should own it**: say so, and plan the backend in Reem
  first, then the screens.
- **It is saifsys's own data** (leases, payroll, bookings): this skill does not apply.

A pasted spec that asks for saifsys tables for something Reem keeps is wrong about
that. Say what you found in Reem and ask before building. This mistake cost a full
rebuild once.

## 2. Say the plan in a few lines and wait for yes

What Reem already has, what saifsys will show, what (if anything) must be added to
Reem. He reads "can we...?" as a question, and big changes need his yes first. Show
him the exact lines before touching a file that decides who sees a module
(`includes/module_access.php`, `includes/rbac_department.php`, `select-module.php`).

## 3. The Reem side

- **The door.** `server/fromSaifsys.js` checks the shared key (`SAIFSYS_DOOR_KEY` in
  Reem's `.env` = `REEM_DOOR_KEY` in saifsys `includes/config.php`) and turns the
  request into a Reem user. Mount the feature's existing router behind it in
  `server/index.js`, above `app.use('/api', requireUser)`:
  `app.use('/api/from-saifsys/<name>', fromSaifsys, <name>Routes);`
  The same router then serves Reem's own screen and saifsys, so the rules are
  written once.
- **Who is at the screen** is the HR employee code (`X-Saifsys-Employee`), matched
  through Reem's `hr_links`. Never by email: a saifsys user can type any email on
  their own profile.
- **Who may do what** stays Reem's rule (master, building administrator, ...).
  saifsys never decides it.
- New behaviour (like "take for a job") is a new function and route in Reem, with a
  test in `tests/`.
- Schema changes go in `server/db.js` as `ADD COLUMN IF NOT EXISTS` and idempotent
  `UPDATE`s; they run on every start, so they must be safe to run twice.

## 4. The saifsys side

Model on `modules/building_inventory/`:

- `includes/<x>_helper.php`: one function that calls Reem (`binv_reem()`): key and
  employee code in headers, short timeouts, Reem's own error sentence passed on as a
  `BinvError`. Reuse `binv_helper.php` rather than copying it when the new module
  can.
- Pages: `require_login`, then call Reem, then draw. `csrf_field()`/`csrf_verify()`,
  redirect after every POST, `h()` on everything, own slim layout header/footer.
- Show what Reem says. If Reem refuses, the page shows Reem's sentence. If Reem is
  down, the page says so and nothing else breaks.
- Files and photos stay in Reem; saifsys passes them through a PHP page, never a
  direct link.
- Launcher: a constant in `module_access.php` and `rbac_department.php`, the icon in
  `select-module.php`, and in `get_user_departments()` a try/catch that asks Reem
  (cached in the session for ten minutes, 4-second wait, any failure = not shown).
  Not in the role checkbox list in `settings.php`.
- Never edit `operation/*.php`. Never read or link the old inventory (`inv_*`,
  `modules/inventory`).

## 5. Test both sides on this Mac

- **Reem**: start the local Postgres and run its tests — see the memory
  `reem-local-test-db`. For a schema change, rehearse the upgrade: build a scratch
  database from `git show HEAD:server/db.js`, add sample rows, start the new
  `db.js` twice, check where the rows went.
- **saifsys**: Reem cannot run here, so write a small stand-in server in the
  scratchpad that answers like the Reem routes, point `REEM_URL` at it, run
  `php -S 127.0.0.1:8099 -t .`, and drive the pages with curl. Make a session with a
  script instead of logging in. Most local users are stopped by the attendance
  check-in pop-up; pick one who is not (an Admin with an inactive employee record)
  rather than checking anyone in.
- Test the launcher lines on a scratch copy of the site before applying them.
- `php -l` every file. Remove test rows, session files and scratch copies; stop the
  servers.

## 6. Hand over

Short, in this order: what it does now, files to upload to saifsys (new, changed, to
delete on live), Reem files to deploy, config lines to add by hand on live
(`REEM_URL`, `REEM_DOOR_KEY`, and `SAIFSYS_DOOR_KEY` in Reem's `.env`), what was not
tested. Remind him to compare changed saifsys files with the live copies first, to
deploy Reem before uploading saifsys, and that each person must be linked in Reem
(People → the person → HR link) or they will see "no account linked".

Say plainly whether a change was to the screen only or to how Reem stores and
decides things. He asks, and he wants the real change, not a screen that hides the
old behaviour.
