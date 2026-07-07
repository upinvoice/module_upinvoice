-- Migration: count failed AI extraction attempts so the cron can auto-retry
-- transient API failures a limited number of times.
-- Apply with:
--   mysql -u root -p erp < llx_upinvoice_files-add-ai_retries.sql
ALTER TABLE llx_upinvoice_files ADD COLUMN ai_retries SMALLINT NOT NULL DEFAULT 0 AFTER import_error;
