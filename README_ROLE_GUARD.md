## Worker Route Guard

This feature enforces role-based employee self-service access so front-line staff can only view their own records.

### How It Works
- `includes/auth.php` now loads `lib/Guard.php` on every request once a user session exists.
- Worker roles are defined via the `WORKER_ROLE_NAMES` constant (see `includes/config.php`).
- Allowed routes for workers are controlled by `WORKER_ROUTE_ALLOWLIST`. Wildcards are supported (`*`).
- On each request the Guard resolves the logged-in user's `employee_id` (preferring `user.employee_id`, falling back to `employees.user_id`), caches it in session, and:
  - Redirects workers to `/hr/employee_view.php?id=<their_id>` when they access non-allowlisted pages.
  - Blocks attempts to view another employee's data (including payslips).
  - Returns JSON 403 responses when the request advertises `Accept: application/json`.
  - Emits audit log entries for redirects, denials, and configuration gaps.

### Configuring Worker Roles or Routes
Edit `includes/config.php`:
```php
define('WORKER_ROLE_NAMES', ['Cleaner', 'Driver']);
define('WORKER_ROUTE_ALLOWLIST', [
    '/hr/employee_view.php',
    '/hr/employee_view.php*',
    // Add additional allowed paths here
]);
```
Add/remove entries as required and redeploy. No cache clearing is needed.

### Mapping Users to Employees
Workers must be linked to employee records via the new `user.employee_id` column.

1. Run the migration:  
   ```sql
   migrations/20251111_add_user_employee_link.sql
   ```
   (Use your standard migration runner or apply the SQL manually.)

2. Backfill existing accounts:
   ```bash
   php tools/backfill_user_employee_link.php
   ```
   The script tries to match on `employees.user_id`, email, then phone; unmatched users are listed in the output.

3. Creating new logins via `hr/user_create_for_employee.php` now updates both `employees.user_id` and `user.employee_id`.

If a worker account has no employee mapping, Guard will stop the request and emit an audit entry so administrators can fix the link.

### Testing
Lightweight regression tests live in `tests/guard_self_service_test.php`:
```bash
php tests/guard_self_service_test.php
```
They simulate redirects for cleaners, confirm admins are unaffected, and assert ownership enforcement.

