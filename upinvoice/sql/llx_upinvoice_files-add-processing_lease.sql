-- Migration: processing lease (owner token + start date) and Idempotency-Key of the
-- current API attempt, so a file is never sent twice to the API (two tabs, two users,
-- web + cron) and a retry after a lost response is not charged again.
-- Applied when the module is enabled again; or manually with:
--   mysql -u root -p erp < llx_upinvoice_files-add-processing_lease.sql
ALTER TABLE llx_upinvoice_files ADD COLUMN processing_token VARCHAR(32) DEFAULT NULL AFTER processing;
ALTER TABLE llx_upinvoice_files ADD COLUMN processing_started DATETIME DEFAULT NULL AFTER processing_token;
ALTER TABLE llx_upinvoice_files ADD COLUMN idempotency_key VARCHAR(64) DEFAULT NULL AFTER processing_started;
