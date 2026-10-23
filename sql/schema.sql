-- MySQL 8.0+ / MariaDB 10.6+, InnoDB, UTC. No token plaintext.
CREATE TABLE companies (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bling_connections (
    company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('disconnected','connected','reauthorization_required') NOT NULL DEFAULT 'disconnected',
    access_cipher TEXT NULL,
    refresh_cipher TEXT NULL,
    expires_at BIGINT UNSIGNED NULL,
    granted_scope TEXT NULL,
    refresh_in_flight TINYINT(1) NOT NULL DEFAULT 0,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_bling_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bling_oauth_states (
    state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    principal_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_generation BIGINT UNSIGNED NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_oauth_expiry (expires_at),
    CONSTRAINT fk_state_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
