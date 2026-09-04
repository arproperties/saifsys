# Construction Company — Chart of Accounts Setup

If you ran `construction_chart_of_accounts.sql` **before** the construction company had a base chart of accounts, the construction-specific accounts (1515, 5125, 2145, 2125) were **not** inserted (because parent accounts 1000, 2100, 5000 did not exist). Fix it by doing the following in order.

---

## Step 1: Base chart of accounts (run first)

**File:** `migrations/seed_real_estate_chart_of_accounts.sql`

This file lives in the **migrations** folder. It seeds the full chart of accounts (1000, 1100, 1110, 1210, 2000, 2100, 5000, etc.) for **one company**.

1. Open **`migrations/seed_real_estate_chart_of_accounts.sql`**.
2. Find the line near the top:
   ```sql
   SET @company_id = 1; -- Default company ID, change as needed
   ```
3. Change `1` to your **construction company ID** (e.g. `2` if Madar Alwadi is company 2):
   ```sql
   SET @company_id = 2; -- Construction company
   ```
4. Run the script once (phpMyAdmin, MySQL command line, or your DB tool):
   ```bash
   mysql -u root -p your_database_name < migrations/seed_real_estate_chart_of_accounts.sql
   ```
   Or in phpMyAdmin: open the file, change `@company_id`, then click “Go”.

That creates the **base** COA for the construction company (1000, 2100, 5000, 1210, 1110, etc.).

---

## Step 2: Construction-specific accounts (run second)

**File:** `migrations/construction_chart_of_accounts.sql`

1. Open **`migrations/construction_chart_of_accounts.sql`**.
2. Set the same construction company ID at the top:
   ```sql
   SET @company_id = 2;  -- Same as Step 1
   ```
3. Run the script once:
   ```bash
   mysql -u root -p your_database_name < migrations/construction_chart_of_accounts.sql
   ```

That adds **1515** Construction in Progress, **5125** Project COGS, **2145** Contractor Payable, **2125** Retention Payable.

---

## How to find your construction company ID

In your database:

```sql
SELECT id, name, code, business_type FROM companies WHERE business_type = 'construction';
```

Use that `id` for `@company_id` in both scripts.

---

## Summary

| Order | File | What it does |
|-------|------|--------------|
| 1 | `seed_real_estate_chart_of_accounts.sql` | Creates base COA (1000, 1210, 2100, 5000, …) for the construction company. |
| 2 | `construction_chart_of_accounts.sql` | Adds construction accounts (1515, 5125, 2145, 2125). |

After both steps, contractor payments and project costs will post to the accounting engine correctly for that company.
