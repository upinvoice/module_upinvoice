-- Migration: add source column to llx_upinvoice_files
-- NULL and 'web' = manual upload (user triggers AI processing manually)
-- 'email' = queued automatically via EmailCollector (auto-processed by cron if UPINVOICE_AUTO_AI_PROCESSING is on)
ALTER TABLE llx_upinvoice_files ADD COLUMN source VARCHAR(32) DEFAULT NULL AFTER import_error;
