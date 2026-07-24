-- Migration: record which auto-import rule queued an email-sourced file
-- Execute manually on existing installs:
--   mysql -u root -p erp < llx_upinvoice_files-add-import_rule_label.sql
-- No AFTER clause: on module activation migration files run in alphabetical order,
-- so the anchor column may not exist yet when upgrading from an old version.
ALTER TABLE llx_upinvoice_files ADD COLUMN import_rule_label VARCHAR(255) DEFAULT NULL;
