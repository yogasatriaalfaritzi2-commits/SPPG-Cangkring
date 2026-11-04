-- Tahap 3: riwayat progres proyek pembangunan.
-- Setiap perubahan progres tercatat lengkap dengan kondisi sebelum dan sesudah.

CREATE TABLE IF NOT EXISTS project_progress_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    progress_before TINYINT UNSIGNED NOT NULL DEFAULT 0,
    progress_after TINYINT UNSIGNED NOT NULL DEFAULT 0,
    work_description TEXT NOT NULL,
    completed_work TEXT NULL,
    obstacles TEXT NULL,
    next_plan TEXT NULL,
    responsible_name VARCHAR(120) NOT NULL,
    reported_by BIGINT UNSIGNED NULL,
    reported_by_name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_progress_project_date (project_id, report_date),
    KEY idx_progress_reporter (reported_by),
    CONSTRAINT fk_progress_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_reporter FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_progress_before CHECK (progress_before <= 100),
    CONSTRAINT chk_progress_after CHECK (progress_after <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
