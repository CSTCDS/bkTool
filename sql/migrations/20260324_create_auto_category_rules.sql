-- Migration: create table auto_category_rules and transaction_changes_log
-- Run this SQL against your database (via mysql CLI or phpmyadmin)

CREATE TABLE IF NOT EXISTS auto_category_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pattern TEXT NOT NULL,
  is_regex TINYINT(1) NOT NULL DEFAULT 0,
  category_id INT NOT NULL,
  scope_account_id INT DEFAULT NULL,
  priority INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (priority)
);

CREATE TABLE IF NOT EXISTS transaction_changes_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tx_id INT NOT NULL,
  old_category_id INT DEFAULT NULL,
  new_category_id INT DEFAULT NULL,
  rule_id INT DEFAULT NULL,
  user_id VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (tx_id),
  INDEX (rule_id)
);
