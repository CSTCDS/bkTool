-- Schema pour bkTool

CREATE TABLE IF NOT EXISTS accounts (
  id VARCHAR(191) PRIMARY KEY,
  name VARCHAR(255),
  balance DECIMAL(20,4) DEFAULT 0,
  currency VARCHAR(8),
  raw JSON,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS transactions (
  id VARCHAR(191) PRIMARY KEY,
  account_id VARCHAR(191),
  amount DECIMAL(20,4),
  currency VARCHAR(8),
  description TEXT,
  booking_date DATE,
  status VARCHAR(20) DEFAULT 'booked',
  raw JSON,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (account_id)
);

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(191) PRIMARY KEY,
  `value` TEXT
);
-- End of schema
