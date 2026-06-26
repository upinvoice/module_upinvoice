-- Migration: record which auto-import rule queued an email-sourced file
-- Execute manually on existing installs:
--   mysql -u root -p erp < llx_upinvoice_files-add-import_rule_label.sql
ALTER TABLE llx_upinvoice_files ADD COLUMN import_rule_label VARCHAR(255) DEFAULT NULL AFTER source;
