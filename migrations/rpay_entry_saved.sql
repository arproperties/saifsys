-- Recurrent Income: when each entry was first saved in saifsys (the "Created on" column).
-- Only entries saved from now on have a row; older entries show no date. Run once on each saifsys database.
CREATE TABLE IF NOT EXISTS rpay_entry_saved (
  entry_id   INT NOT NULL PRIMARY KEY,
  saved_at   DATETIME NOT NULL,
  saved_by   INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
