-- 1) egg sale rate remembered on each daily record (optional)
ALTER TABLE layer_daily_records
  ADD COLUMN IF NOT EXISTS egg_rate DECIMAL(6,2) NULL COMMENT '₹ per egg' AFTER eggs_sold;

-- 2) उधारी / credit register — who owes whom, per user
CREATE TABLE IF NOT EXISTS credit_entries (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    txn_date    DATE NOT NULL,
    person      VARCHAR(100) NOT NULL,
    direction   ENUM('they_owe_me','i_owe_them') NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    note        VARCHAR(255) NULL,
    settled     TINYINT(1) NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owner (created_by),
    INDEX idx_person (created_by, person, settled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
