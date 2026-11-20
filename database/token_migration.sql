-- Bearer tokens for the Android app (session cookies don't work reliably
-- inside a Capacitor WebView). Browser sessions are unchanged.
CREATE TABLE IF NOT EXISTS api_tokens (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,            -- sha256 of the token returned to the app
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    UNIQUE KEY uq_token (token_hash),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
