CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(191) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT DEFAULT NULL,
    autoload TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    KEY idx_settings_autoload (autoload)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone_number VARCHAR(40) DEFAULT NULL,
    company VARCHAR(190) DEFAULT NULL,
    job_title VARCHAR(190) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'editor',
    is_email_verified TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME DEFAULT NULL,
    email_verification_token VARCHAR(120) DEFAULT NULL,
    email_verification_expires_at DATETIME DEFAULT NULL,
    password_reset_token VARCHAR(120) DEFAULT NULL,
    password_reset_expires_at DATETIME DEFAULT NULL,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    two_factor_secret VARCHAR(64) DEFAULT NULL,
    two_factor_pending_secret VARCHAR(64) DEFAULT NULL,
    backup_codes_json LONGTEXT DEFAULT NULL,
    backup_codes_generated_at DATETIME DEFAULT NULL,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    key_prefix VARCHAR(16) NOT NULL,
    key_last_four CHAR(4) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_api_keys_key_hash (key_hash),
    KEY idx_api_keys_user_created_at (user_id, created_at),
    CONSTRAINT fk_api_keys_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    user_agent TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    browser VARCHAR(64) DEFAULT NULL,
    operating_system VARCHAR(64) DEFAULT NULL,
    device VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    last_active_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoked_reason VARCHAR(191) DEFAULT NULL,
    KEY idx_sessions_user_id (user_id),
    KEY idx_sessions_token_hash (token_hash),
    KEY idx_sessions_user_active (user_id, revoked_at, last_active_at),
    CONSTRAINT fk_sessions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS urls (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    short_code VARCHAR(64) NOT NULL UNIQUE,
    alias VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(191) DEFAULT NULL,
    destination_url TEXT NOT NULL,
    password_value VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    utm_source VARCHAR(120) DEFAULT NULL,
    utm_medium VARCHAR(120) DEFAULT NULL,
    utm_campaign VARCHAR(120) DEFAULT NULL,
    utm_term VARCHAR(120) DEFAULT NULL,
    utm_content VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_urls_user_id (user_id),
    KEY idx_urls_user_status (user_id, status),
    KEY idx_urls_status (status),
    KEY idx_urls_created_at (created_at),
    CONSTRAINT fk_urls_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clicks (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    url_id VARCHAR(40) NOT NULL,
    clicked_at DATETIME NOT NULL,
    visitor_hash CHAR(64) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    country_code VARCHAR(8) DEFAULT NULL,
    country_name VARCHAR(120) DEFAULT NULL,
    city_name VARCHAR(120) DEFAULT NULL,
    device VARCHAR(64) DEFAULT NULL,
    browser VARCHAR(64) DEFAULT NULL,
    operating_system VARCHAR(64) DEFAULT NULL,
    referrer_name VARCHAR(191) DEFAULT NULL,
    referrer_domain VARCHAR(191) DEFAULT NULL,
    referrer_category VARCHAR(64) DEFAULT NULL,
    utm_source VARCHAR(120) DEFAULT NULL,
    utm_medium VARCHAR(120) DEFAULT NULL,
    utm_campaign VARCHAR(120) DEFAULT NULL,
    utm_term VARCHAR(120) DEFAULT NULL,
    utm_content VARCHAR(120) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    KEY idx_clicks_url_id (url_id),
    KEY idx_clicks_clicked_at (clicked_at),
    KEY idx_clicks_url_clicked_at (url_id, clicked_at),
    CONSTRAINT fk_clicks_url_id FOREIGN KEY (url_id) REFERENCES urls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    type VARCHAR(64) NOT NULL,
    message TEXT DEFAULT NULL,
    link_id VARCHAR(40) DEFAULT NULL,
    metadata LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_logs_created_at (created_at),
    KEY idx_audit_logs_user_created_at (user_id, created_at),
    KEY idx_audit_logs_link_id (link_id),
    CONSTRAINT fk_audit_logs_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_logs_link_id FOREIGN KEY (link_id) REFERENCES urls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    url TEXT NOT NULL,
    events LONGTEXT NOT NULL,
    secret VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_webhooks_user_id (user_id),
    KEY idx_webhooks_user_active (user_id, is_active),
    CONSTRAINT fk_webhooks_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
