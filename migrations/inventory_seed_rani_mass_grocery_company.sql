-- Optional: grocery company for POS / inventory demos (safe to re-run).
SET NAMES utf8mb4;

INSERT IGNORE INTO companies (name, code, business_type, is_active)
VALUES ('Rani Mass Grocery LLC', 'RANI_GROCERY', 'supermarket', 1);
