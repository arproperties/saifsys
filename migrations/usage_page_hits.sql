-- Usage tracker: which pages and staff-app actions are really used.
-- One row per day, per page, per person. Written by includes/usage_tracker.php,
-- read by admin/usage_report.php. Safe to run twice.
--
-- channel  web        a page opened in the browser
--          staff_web  the staff web app (/staff/)
--          driver_web the old driver web app (/driver/)
--          apk        an installed Android app
-- page     file path from the project root for web ("hr/payroll.php");
--          app/action for the apps ("cleaning/jobs/{id}/finish")
-- user_id  0 = nobody signed in (public and tenant pages)
-- views    opens (GET);  saves = form posts and other writes

CREATE TABLE IF NOT EXISTS usage_page_hits (
  hit_date DATE NOT NULL,
  channel  VARCHAR(20)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'web',
  page     VARCHAR(190) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  user_id  INT NOT NULL DEFAULT 0,
  views    INT UNSIGNED NOT NULL DEFAULT 0,
  saves    INT UNSIGNED NOT NULL DEFAULT 0,
  last_at  DATETIME NOT NULL,
  PRIMARY KEY (hit_date, channel, page, user_id),
  KEY idx_usage_page (page, hit_date),
  KEY idx_usage_user (user_id, hit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
