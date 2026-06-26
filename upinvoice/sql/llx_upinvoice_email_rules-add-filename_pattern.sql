-- Migration: add filename_pattern (glob with * and ?) to email import rules
-- Execute manually on existing installs:
--   mysql -u root -p erp < llx_upinvoice_email_rules-add-filename_pattern.sql
ALTER TABLE llx_upinvoice_email_rules ADD COLUMN filename_pattern VARCHAR(255) DEFAULT NULL AFTER subject_contains;
