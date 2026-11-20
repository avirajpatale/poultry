-- Per-user preferences (reminders, defaults). Key/value, owner-scoped.
CREATE TABLE IF NOT EXISTS user_prefs (
    user_id     INT UNSIGNED NOT NULL,
    skey        VARCHAR(50) NOT NULL,
    sval        VARCHAR(255) NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, skey),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
