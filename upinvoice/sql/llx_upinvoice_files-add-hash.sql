-- Migration: add file_hash column to llx_upinvoice_files
-- SHA-256 hex digest of the raw file content, used for content-based deduplication.
-- NULL for records imported before this migration (fallback dedup uses original_filename + file_size).
-- No AFTER clause: on module activation migration files run in alphabetical order,
-- so the anchor column may not exist yet when upgrading from an old version.
ALTER TABLE llx_upinvoice_files ADD COLUMN file_hash VARCHAR(64) DEFAULT NULL;
