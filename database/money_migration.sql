-- Simplified "Money / हिसाब" table for the daily register app.
-- Keeps one row per income or expense. Existing `expenses` rows are
-- migrated below so no history is lost.

CREATE TABLE IF NOT EXISTS money_transactions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    txn_date        DATE NOT NULL,
    type            ENUM('income','expense') NOT NULL,
    category        VARCHAR(60) NOT NULL DEFAULT 'Other',
    description     VARCHAR(255) NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_mode    ENUM('cash','upi','bank') NOT NULL DEFAULT 'cash',
    legacy_expense_id BIGINT UNSIGNED NULL COMMENT 'set when migrated from expenses table',
    created_by      INT UNSIGNED NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_txn_date (txn_date),
    INDEX idx_type (type),
    UNIQUE KEY uq_legacy_expense (legacy_expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing active expenses (idempotent via legacy_expense_id unique key)
INSERT INTO money_transactions (txn_date, type, category, description, amount, payment_mode, legacy_expense_id, created_by)
SELECT e.expense_date, 'expense',
       COALESCE(c.name, 'Other'),
       e.description,
       e.amount,
       CASE WHEN e.payment_mode IN ('cash','upi','bank') THEN e.payment_mode ELSE 'cash' END,
       e.id,
       e.created_by
FROM expenses e
LEFT JOIN expense_categories c ON c.id = e.category_id
WHERE e.status = 'active'
  AND NOT EXISTS (SELECT 1 FROM money_transactions m WHERE m.legacy_expense_id = e.id);
