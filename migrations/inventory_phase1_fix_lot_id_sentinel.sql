-- Fix: inv_onhand PRIMARY KEY requires lot_id NOT NULL in MySQL; NULL inserts fail with Error 1048.
-- Use 0 = "no lot" for on-hand and stock moves (inv_lots ids are auto-increment from 1).

SET NAMES utf8mb4;

UPDATE inv_onhand SET lot_id = 0 WHERE lot_id IS NULL;
UPDATE inv_stock_moves SET lot_id = 0 WHERE lot_id IS NULL;

ALTER TABLE inv_onhand
  MODIFY COLUMN lot_id INT NOT NULL DEFAULT 0 COMMENT '0 = no lot';

ALTER TABLE inv_stock_moves
  MODIFY COLUMN lot_id INT NOT NULL DEFAULT 0 COMMENT '0 = no lot';
