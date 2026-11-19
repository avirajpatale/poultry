-- Per-user data isolation: every register belongs to its owner (created_by).
-- Existing rows are assigned to the admin user (id 1) so nothing is lost.

UPDATE layer_daily_records SET created_by = 1 WHERE created_by IS NULL;
UPDATE money_transactions  SET created_by = 1 WHERE created_by IS NULL;

-- One record per DAY **per user** (was globally unique on record_date).
ALTER TABLE layer_daily_records
  DROP INDEX record_date,
  ADD UNIQUE KEY uq_ldr_date_user (record_date, created_by);

ALTER TABLE money_transactions ADD INDEX idx_owner (created_by);
