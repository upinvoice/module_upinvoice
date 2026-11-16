-- Migration: type of document of each file (orders since 1.5.0)
-- supplier_invoice (default, every file before 1.5.0), supplier_order, customer_order
-- No AFTER clause: on module activation migration files run in alphabetical order,
-- so the anchor column may not exist yet when upgrading from an old version.
ALTER TABLE llx_upinvoice_files ADD COLUMN doc_type VARCHAR(32) NOT NULL DEFAULT 'supplier_invoice';
