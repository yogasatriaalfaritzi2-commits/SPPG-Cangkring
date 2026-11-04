-- Tahap 4: Rencana Anggaran Biaya (RAB) pembangunan SPPG.
-- Anggaran awal proyek tetap berada pada projects.budget_amount dan tidak diubah oleh modul ini.
-- Semua perintah bersifat idempoten sehingga aman dijalankan pada database yang sudah berisi data.

CREATE TABLE IF NOT EXISTS budget_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_code VARCHAR(20) NOT NULL,
    category_name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_budget_categories_code (category_code),
    KEY idx_budget_categories_list (is_active, sort_order, category_name),
    CONSTRAINT fk_budget_categories_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_categories_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rab_code VARCHAR(30) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'pending', 'approved', 'rejected', 'revised') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    revision_of BIGINT UNSIGNED NULL,
    notes VARCHAR(1000) NULL,
    submitted_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_budget_plans_code (rab_code),
    KEY idx_budget_plans_project (project_id, status),
    KEY idx_budget_plans_period (period_start, period_end),
    KEY idx_budget_plans_creator (created_by),
    KEY idx_budget_plans_submitter (submitted_by),
    KEY idx_budget_plans_reviewer (reviewed_by),
    KEY idx_budget_plans_revision (revision_of),
    CONSTRAINT fk_budget_plans_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_budget_plans_revision FOREIGN KEY (revision_of) REFERENCES budget_plans (id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_plans_submitted FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_plans_reviewed FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_plans_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_plans_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_budget_plans_total CHECK (total_amount >= 0),
    CONSTRAINT chk_budget_plans_period CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    budget_plan_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    item_code VARCHAR(30) NOT NULL,
    item_name VARCHAR(180) NOT NULL,
    volume DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unit VARCHAR(30) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_price DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    notes VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_budget_items_code (budget_plan_id, item_code),
    KEY idx_budget_items_plan (budget_plan_id, category_id, sort_order),
    KEY idx_budget_items_category (category_id),
    CONSTRAINT fk_budget_items_plan FOREIGN KEY (budget_plan_id) REFERENCES budget_plans (id) ON DELETE CASCADE,
    CONSTRAINT fk_budget_items_category FOREIGN KEY (category_id) REFERENCES budget_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_budget_items_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_items_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_budget_items_volume CHECK (volume >= 0),
    CONSTRAINT chk_budget_items_price CHECK (unit_price >= 0),
    CONSTRAINT chk_budget_items_total CHECK (total_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat alur persetujuan RAB. Satu baris untuk setiap tindakan pengajuan,
-- persetujuan, penolakan, atau permintaan revisi.
CREATE TABLE IF NOT EXISTS budget_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    budget_plan_id BIGINT UNSIGNED NOT NULL,
    action ENUM('submit', 'approve', 'reject', 'revise') NOT NULL,
    from_status VARCHAR(20) NOT NULL,
    to_status VARCHAR(20) NOT NULL,
    note VARCHAR(500) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_budget_approvals_plan (budget_plan_id, created_at),
    KEY idx_budget_approvals_actor (actor_id),
    CONSTRAINT fk_budget_approvals_plan FOREIGN KEY (budget_plan_id) REFERENCES budget_plans (id) ON DELETE CASCADE,
    CONSTRAINT fk_budget_approvals_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengajuan perubahan anggaran proyek. Nilai proyek hanya berubah setelah
-- revisi disetujui, sehingga setiap perubahan memiliki riwayat dan alasan.
CREATE TABLE IF NOT EXISTS budget_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_code VARCHAR(30) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    previous_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    proposed_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(500) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    requested_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_budget_revisions_code (revision_code),
    KEY idx_budget_revisions_project (project_id, status),
    KEY idx_budget_revisions_requester (requested_by),
    KEY idx_budget_revisions_decider (decided_by),
    CONSTRAINT fk_budget_revisions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_budget_revisions_requester FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_revisions_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_budget_revisions_previous CHECK (previous_amount >= 0),
    CONSTRAINT chk_budget_revisions_proposed CHECK (proposed_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO budget_categories (category_code, category_name, description, sort_order, is_active) VALUES
('PREP', 'Persiapan Pembangunan', 'Survei, papan proyek, bouwplank, dan persiapan lokasi.', 10, 1),
('TANAH', 'Pekerjaan Tanah dan Pondasi', 'Galian, urukan, pondasi batu kali atau footplat.', 20, 1),
('STRUKTUR', 'Struktur Bangunan', 'Sloof, kolom, balok, plat lantai, dan struktur beton bertulang.', 30, 1),
('DINDING', 'Dinding dan Lantai', 'Pasangan bata, plester, acian, keramik dinding dan lantai.', 40, 1),
('ATAP', 'Atap dan Plafon', 'Rangka, penutup atap, nisbat, dan plafon beserta rangka hollow.', 50, 1),
('LISTRIK', 'Instalasi Listrik', 'Panel, kabel, titik lampu, stop kontak, dan pentanahan.', 60, 1),
('SANITASI', 'Instalasi Air dan Sanitasi', 'Pipa air bersih, drainase, septic tank, dan kloset.', 70, 1),
('DAPUR', 'Area Dapur', 'Meja kerja, dinding keramik dapur, saluran limbah, dan pemisah lemak.', 80, 1),
('PERALATAN', 'Peralatan Dapur', 'Kompor induksi, rice cooker, chiller, freezer, dan timbangan.', 90, 1),
('GUDANG', 'Area Penyimpanan Bahan Pangan', 'Rak gudang kering, chiller bahan pangan, dan pelindung hama.', 100, 1),
('PENDUKUNG', 'Area Pendukung', 'Zona cuci tangan, tempat sampah, pagar, dan taman kerja.', 110, 1),
('FINISHING', 'Pekerjaan Finishing', 'Cat, pembersihan akhir, signage, dan perbaikan minor.', 120, 1);
