-- Tahap 3: tim proyek dan notifikasi internal.
-- Keanggotaan tim menautkan pengguna yang sudah ada di tabel users, tanpa membuat akun baru.

CREATE TABLE IF NOT EXISTS project_team_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    position VARCHAR(80) NOT NULL,
    duties VARCHAR(255) NULL,
    is_lead TINYINT(1) NOT NULL DEFAULT 0,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unassigned_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_team_project (project_id, unassigned_at),
    KEY idx_team_user (user_id, unassigned_at),
    KEY idx_team_assigner (assigned_by),
    CONSTRAINT fk_team_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_assigner FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(255) NULL,
    link VARCHAR(190) NULL,
    project_id BIGINT UNSIGNED NULL,
    dedup_key VARCHAR(100) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_dedup (user_id, type, dedup_key),
    KEY idx_notification_inbox (user_id, is_read, created_at),
    KEY idx_notification_project (project_id),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
