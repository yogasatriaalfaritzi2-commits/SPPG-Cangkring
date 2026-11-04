-- Tahap 3: dokumentasi proyek pembangunan.
-- Berkas fisik disimpan di luar folder public; tabel ini hanya menyimpan metadata.

CREATE TABLE IF NOT EXISTS project_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    progress_report_id BIGINT UNSIGNED NULL,
    category ENUM('initial', 'construction', 'result', 'supporting', 'minutes', 'contract') NOT NULL DEFAULT 'supporting',
    title VARCHAR(150) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(64) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_documents_stored (stored_name),
    KEY idx_documents_project (project_id, category),
    KEY idx_documents_uploader (uploaded_by),
    KEY idx_documents_progress (progress_report_id),
    CONSTRAINT fk_documents_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_progress FOREIGN KEY (progress_report_id) REFERENCES project_progress_reports (id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
