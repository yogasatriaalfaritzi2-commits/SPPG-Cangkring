-- Tahap 4: jadwal pelaksanaan pembangunan beserta ketergantungan dan riwayat perubahannya.
-- Kegiatan menautkan kategori pekerjaan pada budget_categories agar RAB, transaksi, dan jadwal
-- memakai satu pengelompokan pekerjaan yang sama.

CREATE TABLE IF NOT EXISTS project_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    activity_code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(1000) NULL,
    category_id BIGINT UNSIGNED NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    duration_days INT UNSIGNED NOT NULL DEFAULT 1,
    owner_user_id BIGINT UNSIGNED NULL,
    owner_name VARCHAR(120) NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    status ENUM('not_started', 'in_progress', 'delayed', 'completed', 'cancelled') NOT NULL DEFAULT 'not_started',
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notes VARCHAR(1000) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by BIGINT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schedules_code (project_id, activity_code),
    KEY idx_schedules_calendar (start_date, end_date),
    KEY idx_schedules_project (project_id, status, is_deleted),
    KEY idx_schedules_owner (owner_user_id),
    KEY idx_schedules_category (category_id),
    KEY idx_schedules_deleted (is_deleted, deleted_at),
    CONSTRAINT fk_schedules_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_schedules_category FOREIGN KEY (category_id) REFERENCES budget_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_schedules_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_schedules_deleter FOREIGN KEY (deleted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_schedules_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_schedules_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_schedules_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_schedules_progress CHECK (progress <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schedule_id adalah pekerjaan setelahnya (successor) dan predecessor_id adalah
-- pekerjaan yang harus lebih dahulu selesai. Jenis FS = selesai-ke-mulai,
-- SS = mulai-ke-mulai, FF = selesai-ke-selesai. lag_days adalah jeda hari.
CREATE TABLE IF NOT EXISTS schedule_dependencies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    predecessor_id BIGINT UNSIGNED NOT NULL,
    dependency_type ENUM('FS', 'SS', 'FF') NOT NULL DEFAULT 'FS',
    lag_days INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schedule_dependency (schedule_id, predecessor_id),
    KEY idx_dependency_predecessor (predecessor_id),
    KEY idx_dependency_creator (created_by),
    CONSTRAINT fk_dependency_successor FOREIGN KEY (schedule_id) REFERENCES project_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_dependency_predecessor FOREIGN KEY (predecessor_id) REFERENCES project_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_dependency_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_dependency_not_self CHECK (schedule_id <> predecessor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Setiap perubahan jadwal dicatat per bidang beserta alasannya, termasuk perubahan
-- tanggal, penanggung jawab, progres, dan ketergantungan pekerjaan.
CREATE TABLE IF NOT EXISTS schedule_histories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    field_name VARCHAR(60) NOT NULL,
    old_value VARCHAR(255) NULL,
    new_value VARCHAR(255) NULL,
    reason VARCHAR(500) NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_schedule_histories_activity (schedule_id, changed_at),
    KEY idx_schedule_histories_changer (changed_by),
    CONSTRAINT fk_schedule_histories_activity FOREIGN KEY (schedule_id) REFERENCES project_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_histories_changer FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
