-- Raw material expense tracking — per user, separate from the money hisab.
-- Categories are created by the user (no seed data).

CREATE TABLE IF NOT EXISTS material_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL,
    created_by  INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mat_cat (name, created_by),
    INDEX idx_owner (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS material_expenses (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    txn_date      DATE NOT NULL,
    category_id   INT UNSIGNED NOT NULL,
    quantity      DECIMAL(10,2) NOT NULL,
    unit          VARCHAR(20) NOT NULL DEFAULT 'KG',
    rate          DECIMAL(12,2) NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,          -- quantity * rate, server-computed
    supplier      VARCHAR(120) NULL,
    payment_mode  ENUM('cash','upi','bank') NOT NULL DEFAULT 'cash',
    notes         VARCHAR(255) NULL,
    created_by    INT UNSIGNED NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_txn_date (txn_date),
    INDEX idx_owner (created_by),
    INDEX idx_cat (category_id),
    FOREIGN KEY (category_id) REFERENCES material_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
