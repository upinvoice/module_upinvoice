-- UpInvoice email import rules table
-- Execute manually: mysql -u root -p erp < llx_upinvoice_email_rules.sql
-- or run the CREATE directly from the DB console.

CREATE TABLE IF NOT EXISTS llx_upinvoice_email_rules (
  rowid INT AUTO_INCREMENT PRIMARY KEY,
  entity INT DEFAULT 1 NOT NULL,
  sender_contains VARCHAR(255) DEFAULT NULL,
  subject_contains VARCHAR(255) DEFAULT NULL,
  filename_pattern VARCHAR(255) DEFAULT NULL,
  formats VARCHAR(64) NOT NULL DEFAULT 'pdf',
  status TINYINT DEFAULT 1 NOT NULL,
  date_creation DATETIME NOT NULL,
  fk_user_creat INT NOT NULL,
  tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_upinvoice_email_rules_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
