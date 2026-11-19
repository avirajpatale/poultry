-- Auth upgrade: email login, registration approval workflow, password reset.
-- Existing users keep working: 'active' status is preserved.

-- pending = registered, awaiting admin approval
-- rejected = admin rejected the registration
-- disabled = deactivated account
ALTER TABLE users
  MODIFY COLUMN status ENUM('active','pending','rejected','disabled') NOT NULL DEFAULT 'active';

-- Password reset tokens (email verification).
CREATE TABLE IF NOT EXISTS password_resets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(120) NOT NULL,
    token_hash  CHAR(64)     NOT NULL,          -- sha256 of the emailed token
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
