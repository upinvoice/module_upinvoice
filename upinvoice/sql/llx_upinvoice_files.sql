-- Table structure for table 'llx_upinvoice_files'
CREATE TABLE IF NOT EXISTS llx_upinvoice_files (
  rowid INT AUTO_INCREMENT PRIMARY KEY,
  entity INT DEFAULT 1 NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_size INT NOT NULL,
  file_type VARCHAR(128),
  api_json MEDIUMTEXT,
  date_creation DATETIME NOT NULL,
  fk_user_creat INT NOT NULL,
  date_modification DATETIME,
  fk_user_modif INT,
  import_step TINYINT DEFAULT 1,
  fk_supplier INT,
  fk_invoice INT,
  status TINYINT DEFAULT 0, -- 0:pending, 1:processed, -1:error
  processing TINYINT DEFAULT 0, -- 0:not processing, 1:processing
  import_error TEXT,
  source VARCHAR(32) DEFAULT NULL, -- NULL or 'web': manual upload; 'email': queued via EmailCollector
  import_rule_label VARCHAR(255) DEFAULT NULL, -- snapshot of the auto-import rule that queued an email file
  file_hash VARCHAR(64) DEFAULT NULL, -- SHA-256 hex digest for content-based deduplication
  calc_method TINYINT DEFAULT NULL, -- VAT calculation method used to create the invoice (1=total of round, 2=round of total)
  tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;