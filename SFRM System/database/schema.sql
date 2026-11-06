-- =====================================================================
-- Student Financial Records Management System  |  MySQL 8.x schema
-- Run in MySQL Workbench: File > Open SQL Script > click the lightning bolt
-- =====================================================================

CREATE DATABASE IF NOT EXISTS sfrms
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE sfrms;

-- ---------------------------------------------------------------------
-- 1. USERS  (login accounts; passwords stored as password_hash() output)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100)  NOT NULL,
  username      VARCHAR(50)   NOT NULL UNIQUE,
  email         VARCHAR(120)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,            -- never plain text / MD5
  role          ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. STUDENTS
-- ---------------------------------------------------------------------
CREATE TABLE students (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_no  VARCHAR(20)  NOT NULL UNIQUE,
  first_name  VARCHAR(60)  NOT NULL,
  last_name   VARCHAR(60)  NOT NULL,
  program     VARCHAR(100) NOT NULL,
  year_level  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  email       VARCHAR(120) NULL,
  contact_no  VARCHAR(20)  NULL,
  status      ENUM('enrolled','graduated','dropped') NOT NULL DEFAULT 'enrolled',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_students_name (last_name, first_name),
  INDEX idx_students_program (program)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. FEE CATEGORIES  (used for the "filter by category" requirement)
-- ---------------------------------------------------------------------
CREATE TABLE fee_categories (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(80) NOT NULL UNIQUE,
  description    VARCHAR(255) NULL,
  default_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. TRANSACTIONS  (the main business records: payments received)
-- ---------------------------------------------------------------------
CREATE TABLE transactions (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_no       VARCHAR(30) NOT NULL UNIQUE,
  student_id       INT UNSIGNED NOT NULL,
  category_id      INT UNSIGNED NOT NULL,
  amount           DECIMAL(10,2) NOT NULL CHECK (amount > 0),
  payment_method   ENUM('cash','gcash','bank_transfer','check') NOT NULL DEFAULT 'cash',
  transaction_date DATE NOT NULL,
  status           ENUM('paid','pending','voided') NOT NULL DEFAULT 'paid',
  remarks          VARCHAR(255) NULL,
  created_by       INT UNSIGNED NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tx_student  FOREIGN KEY (student_id)  REFERENCES students(id)       ON DELETE RESTRICT,
  CONSTRAINT fk_tx_category FOREIGN KEY (category_id) REFERENCES fee_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_user     FOREIGN KEY (created_by)  REFERENCES users(id)          ON DELETE RESTRICT,
  INDEX idx_tx_date (transaction_date),
  INDEX idx_tx_status (status),
  INDEX idx_tx_category (category_id),
  INDEX idx_tx_student (student_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. ACTIVITY LOGS  (audit trail: who, what, date, time)
--    username is copied in so the log survives even if a user is removed.
-- ---------------------------------------------------------------------
CREATE TABLE activity_logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  username   VARCHAR(50)  NOT NULL,
  action     ENUM('LOGIN','LOGOUT','LOGIN_FAILED','CREATE','UPDATE','DELETE',
                  'BACKUP','RESTORE','REPORT','OTHER') NOT NULL,
  details    VARCHAR(500) NOT NULL,
  ip_address VARCHAR(45)  NULL,
  log_date   DATE NOT NULL DEFAULT (CURRENT_DATE),
  log_time   TIME NOT NULL DEFAULT (CURRENT_TIME),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_log_created (created_at),
  INDEX idx_log_user (user_id),
  INDEX idx_log_action (action)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. LOGIN ATTEMPTS  (limit attempts / lockout against brute force)
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(50) NOT NULL,
  ip_address   VARCHAR(45) NOT NULL,
  was_success  TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. BACKUP HISTORY  (records every backup file the admin creates)
-- ---------------------------------------------------------------------
CREATE TABLE backup_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename    VARCHAR(255) NOT NULL,
  backup_type ENUM('database','files','full') NOT NULL,
  size_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SEED DATA: fee categories only (safe to run)
-- ---------------------------------------------------------------------
INSERT INTO fee_categories (name, description, default_amount) VALUES
  ('Tuition',       'Per-semester tuition payment',   15000.00),
  ('Miscellaneous', 'Library, laboratory, and other school fees', 2500.00),
  ('Registration',  'Enrollment / registration fee',   500.00),
  ('Graduation',    'Graduation and diploma fees',    3000.00),
  ('Other',         'Other school-related payments',      0.00);

-- ---------------------------------------------------------------------
-- FIRST ADMIN ACCOUNT  (do NOT store a plain password in this file)
-- 1) Generate a hash on your computer:
--      php -r "echo password_hash('ChangeThisPassword!', PASSWORD_DEFAULT);"
-- 2) Paste the output between the quotes below, remove the leading "-- ",
--    then run it.
-- ---------------------------------------------------------------------
-- INSERT INTO users (full_name, username, email, password_hash, role)
-- VALUES ('System Administrator', 'admin', 'admin@example.com', 'PASTE_HASH_HERE', 'admin');
