-- Recurrent Income: the mode of payment of each entry (cash, card or cheque), kept in saifsys.
-- An entry with no row is cash. Run once on each saifsys database (local, then live).
CREATE TABLE IF NOT EXISTS rpay_entry_modes (
  entry_id     INT NOT NULL PRIMARY KEY,
  payment_mode VARCHAR(10) NOT NULL DEFAULT 'cash',
  set_by       INT NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
