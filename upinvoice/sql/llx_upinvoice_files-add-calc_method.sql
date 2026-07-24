-- Migration: store the VAT calculation method used to create the invoice
-- 1 = Method 1 (total of round), 2 = Method 2 (round of total)
-- Execute manually on existing installs:
--   mysql -u root -p erp < llx_upinvoice_files-add-calc_method.sql
-- No AFTER clause: on module activation migration files run in alphabetical order,
-- so the anchor column may not exist yet when upgrading from an old version.
ALTER TABLE llx_upinvoice_files ADD COLUMN calc_method TINYINT DEFAULT NULL;
