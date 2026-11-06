-- Move-Out damages: repair cost added later
--
-- A damage marked on the inspection checklist is recorded without a cost;
-- the cost is entered afterwards in Damages & Report. NULL = not costed yet
-- (0 stays a real "no charge"). The deposit settlement cannot be prepared
-- while a damage has no cost.
--
-- Safe to run more than once.

ALTER TABLE `re_move_out_damages`
  MODIFY `repair_cost` DECIMAL(10,2) NULL DEFAULT NULL;
