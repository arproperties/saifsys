-- Phase 5 — Retail POS UI: payment method on pos_sales.
SET NAMES utf8mb4;

ALTER TABLE pos_sales
  ADD COLUMN payment_method VARCHAR(20) NULL COMMENT 'cash|card' AFTER grand_total_incl;
