-- Tahap 5: perencanaan menu MBG, jadwal menu, alur persetujuan, target gizi, dan aturan rekomendasi.
-- Menu yang sudah disetujui tidak dapat diubah langsung: perubahannya dicatat sebagai
-- revisi bernomor yang harus disetujui ulang.

CREATE TABLE IF NOT EXISTS menu_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_code VARCHAR(20) NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    meal_slot ENUM('sarapan', 'makan_pagi', 'makan_siang', 'makan_sore') NOT NULL DEFAULT 'makan_siang',
    description VARCHAR(255) NULL,
    requires_verification TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_categories_code (category_code),
    KEY idx_menu_categories_list (is_active, sort_order, category_name),
    CONSTRAINT fk_menu_categories_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_categories_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estimasi gizi dan biaya pada tabel ini selalu dihitung ulang di sisi server dari
-- daftar bahan, bukan diambil dari input browser. Kolom bertanda NULL berarti bahan
-- belum memiliki angka gizi dan status gizi ditampilkan sebagai belum tersedia.
CREATE TABLE IF NOT EXISTS menus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu_code VARCHAR(30) NOT NULL,
    menu_name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    photo_path VARCHAR(255) NULL,
    menu_status ENUM('draft', 'pending', 'approved', 'rejected', 'scheduled', 'done', 'cancelled') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    revision_of BIGINT UNSIGNED NULL,
    allergen_info VARCHAR(500) NULL,
    nutrition_notes VARCHAR(1000) NULL,
    estimated_energy_kcal DECIMAL(12, 2) NULL,
    estimated_protein_g DECIMAL(12, 3) NULL,
    estimated_carb_g DECIMAL(12, 3) NULL,
    estimated_fat_g DECIMAL(12, 3) NULL,
    estimated_fiber_g DECIMAL(12, 3) NULL,
    estimated_cost_per_serving DECIMAL(18, 2) NULL,
    nutrition_status ENUM('lengkap', 'sebagian', 'belum_tersedia') NOT NULL DEFAULT 'belum_tersedia',
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
    UNIQUE KEY uq_menus_code (menu_code),
    KEY idx_menus_status (menu_status, category_id),
    KEY idx_menus_name (menu_name),
    KEY idx_menus_revision (revision_of),
    KEY idx_menus_creator (created_by),
    KEY idx_menus_reviewer (reviewed_by),
    CONSTRAINT fk_menus_category FOREIGN KEY (category_id) REFERENCES menu_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_menus_revision FOREIGN KEY (revision_of) REFERENCES menus (id) ON DELETE SET NULL,
    CONSTRAINT fk_menus_submitted FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menus_reviewed FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menus_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menus_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_menus_energy CHECK (estimated_energy_kcal IS NULL OR estimated_energy_kcal >= 0),
    CONSTRAINT chk_menus_cost CHECK (estimated_cost_per_serving IS NULL OR estimated_cost_per_serving >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_ingredients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu_id BIGINT UNSIGNED NOT NULL,
    ingredient_id BIGINT UNSIGNED NOT NULL,
    quantity_per_serving DECIMAL(14, 4) NOT NULL,
    quantity_unit VARCHAR(20) NOT NULL DEFAULT 'gram',
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_ingredients_item (menu_id, ingredient_id),
    KEY idx_menu_ingredients_ingredient (ingredient_id),
    CONSTRAINT fk_menu_ingredients_menu FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_ingredients_ingredient FOREIGN KEY (ingredient_id) REFERENCES food_ingredients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_menu_ingredients_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_ingredients_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_menu_ingredients_quantity CHECK (quantity_per_serving > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu_id BIGINT UNSIGNED NOT NULL,
    action ENUM('submit', 'approve', 'reject', 'revise', 'schedule', 'cancel', 'complete') NOT NULL,
    from_status VARCHAR(20) NOT NULL,
    to_status VARCHAR(20) NOT NULL,
    note VARCHAR(500) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_menu_approvals_menu (menu_id, created_at),
    KEY idx_menu_approvals_actor (actor_id),
    CONSTRAINT fk_menu_approvals_menu FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_approvals_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Isi usulan revisi disimpan sebagai salinan berformat JSON beserta alasan perubahan,
-- sehingga persetujuan ulang dapat dibandingkan dengan kondisi menu sebelumnya.
CREATE TABLE IF NOT EXISTS menu_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_code VARCHAR(30) NOT NULL,
    menu_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    previous_status VARCHAR(20) NOT NULL,
    proposed_json MEDIUMTEXT NOT NULL,
    estimated_energy_kcal DECIMAL(12, 2) NULL,
    estimated_cost_per_serving DECIMAL(18, 2) NULL,
    revision_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    requested_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_revisions_code (revision_code),
    KEY idx_menu_revisions_menu (menu_id, revision_status),
    KEY idx_menu_revisions_requester (requested_by),
    KEY idx_menu_revisions_decider (decided_by),
    CONSTRAINT fk_menu_revisions_menu FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_revisions_requester FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_revisions_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_menu_revisions_energy CHECK (estimated_energy_kcal IS NULL OR estimated_energy_kcal >= 0),
    CONSTRAINT chk_menu_revisions_cost CHECK (estimated_cost_per_serving IS NULL OR estimated_cost_per_serving >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_code VARCHAR(30) NOT NULL,
    service_date DATE NOT NULL,
    menu_id BIGINT UNSIGNED NOT NULL,
    meal_slot ENUM('sarapan', 'makan_pagi', 'makan_siang', 'makan_sore') NOT NULL DEFAULT 'makan_siang',
    schedule_status ENUM('draft', 'pending', 'approved', 'scheduled', 'cancelled', 'done') NOT NULL DEFAULT 'draft',
    portion_total INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost_total DECIMAL(18, 2) NULL,
    copy_from_id BIGINT UNSIGNED NULL,
    submitted_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_schedules_code (schedule_code),
    UNIQUE KEY uq_menu_schedules_slot (service_date, menu_id, meal_slot),
    KEY idx_menu_schedules_date (service_date, meal_slot),
    KEY idx_menu_schedules_status (schedule_status, service_date),
    KEY idx_menu_schedules_menu (menu_id),
    CONSTRAINT fk_menu_schedules_menu FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE RESTRICT,
    CONSTRAINT fk_menu_schedules_copy FOREIGN KEY (copy_from_id) REFERENCES menu_schedules (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_schedules_submitted FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_schedules_reviewed FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_schedules_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_schedules_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_menu_schedules_cost CHECK (estimated_cost_total IS NULL OR estimated_cost_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_schedule_schools (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    portion_count INT UNSIGNED NOT NULL,
    class_group VARCHAR(60) NOT NULL DEFAULT '',
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schedule_schools_scope (schedule_id, school_id, class_group),
    KEY idx_schedule_schools_school (school_id),
    CONSTRAINT fk_schedule_schools_schedule FOREIGN KEY (schedule_id) REFERENCES menu_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_schools_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT,
    CONSTRAINT fk_schedule_schools_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_schedule_schools_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_schedule_schools_portion CHECK (portion_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Target gizi per kelompok sasaran. Satu standar tidak dipakai untuk semua usia,
-- jadi target disimpan per kelompok (jenjang, umur, jenis kelamin) beserta sumbernya.
CREATE TABLE IF NOT EXISTS nutrition_target_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(30) NOT NULL,
    group_name VARCHAR(120) NOT NULL,
    level_id BIGINT UNSIGNED NULL,
    age_min TINYINT UNSIGNED NULL,
    age_max TINYINT UNSIGNED NULL,
    sex ENUM('l', 'p', 'semua') NOT NULL DEFAULT 'semua',
    notes VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_target_groups_code (group_code),
    KEY idx_target_groups_level (level_id, is_active),
    CONSTRAINT fk_target_groups_level FOREIGN KEY (level_id) REFERENCES school_levels (id) ON DELETE SET NULL,
    CONSTRAINT fk_target_groups_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_target_groups_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_target_groups_age CHECK (age_min IS NULL OR age_max IS NULL OR age_max >= age_min)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nutrition_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    nutrient_code VARCHAR(30) NOT NULL,
    nutrient_unit VARCHAR(12) NOT NULL,
    min_value DECIMAL(14, 4) NULL,
    max_value DECIMAL(14, 4) NULL,
    target_basis ENUM('per_porsi', 'per_hari') NOT NULL DEFAULT 'per_porsi',
    reference_id BIGINT UNSIGNED NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nutrition_targets_scope (group_id, nutrient_code, target_basis),
    KEY idx_nutrition_targets_reference (reference_id),
    CONSTRAINT fk_nutrition_targets_group FOREIGN KEY (group_id) REFERENCES nutrition_target_groups (id) ON DELETE CASCADE,
    CONSTRAINT fk_nutrition_targets_reference FOREIGN KEY (reference_id) REFERENCES nutrition_references (id) ON DELETE SET NULL,
    CONSTRAINT fk_nutrition_targets_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_nutrition_targets_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_nutrition_targets_min CHECK (min_value IS NULL OR min_value >= 0),
    CONSTRAINT chk_nutrition_targets_range CHECK (max_value IS NULL OR min_value IS NULL OR max_value >= min_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aturan rekomendasi menu bersifat terbuka dan dapat diubah ahli gizi atau administrator.
-- Aturan bawaan hanya memeriksa kelengkapan struktur menu, bukan menyatakan nilai gizi resmi.
CREATE TABLE IF NOT EXISTS menu_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_code VARCHAR(30) NOT NULL,
    rule_name VARCHAR(150) NOT NULL,
    rule_type ENUM('kategori_min', 'kategori_max', 'gizi_min', 'gizi_maks', 'variasi_min', 'biaya_maks') NOT NULL,
    applies_codes VARCHAR(255) NULL,
    threshold_value DECIMAL(14, 4) NULL,
    applies_to ENUM('menu', 'mingguan') NOT NULL DEFAULT 'menu',
    message VARCHAR(255) NOT NULL,
    rule_severity ENUM('info', 'peringatan', 'harus') NOT NULL DEFAULT 'peringatan',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_rules_code (rule_code),
    KEY idx_menu_rules_list (is_active, applies_to, rule_type),
    CONSTRAINT fk_menu_rules_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_rules_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_menu_rules_threshold CHECK (threshold_value IS NULL OR threshold_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO menu_categories (category_code, category_name, meal_slot, description, requires_verification, sort_order) VALUES
('SARAPAN', 'Menu sarapan', 'sarapan', 'Pilihan menu pagi bila sekolah dilayani pada slot sarapan.', 0, 10),
('UTAMA', 'Menu makan utama', 'makan_siang', 'Menu utama yang dilayani pada siang hari.', 0, 20),
('ALTERNATIF', 'Menu alternatif', 'makan_siang', 'Pengganti bila bahan utama tidak tersedia atau ada alergi.', 0, 30),
('KHUSUS', 'Menu khusus', 'makan_siang', 'Menu dengan kebutuhan tertentu, hanya dipakai setelah diverifikasi ahli gizi.', 1, 40);

INSERT IGNORE INTO menu_rules (rule_code, rule_name, rule_type, applies_codes, threshold_value, applies_to, message, rule_severity) VALUES
('RULE_PROTEIN_HEWANI', 'Menu perlu sumber protein hewani', 'kategori_min', 'DAGING,IKAN,TELUR,SUSU', 1, 'menu', 'Menu belum memuat bahan dari kategori protein hewani (daging, ikan, telur, atau susu).', 'harus'),
('RULE_SAYUR', 'Menu perlu sayuran', 'kategori_min', 'SAYUR', 1, 'menu', 'Menu belum memuat sayuran.', 'harus'),
('RULE_CARBO', 'Menu perlu sumber karbohidrat', 'kategori_min', 'SEREALIA', 1, 'menu', 'Menu belum memuat beras atau serealia sebagai sumber karbohidrat.', 'peringatan'),
('RULE_BUAH', 'Menu perlu buah', 'kategori_min', 'BUAH', 1, 'menu', 'Menu belum memuat buah.', 'info'),
('RULE_MINIM_MINYAK', 'Batasan bahan berminyak', 'kategori_max', 'PENDUKUNG', 3, 'menu', 'Menu memakai lebih dari tiga bahan pendukung, periksa komposisinya.', 'info'),
('RULE_VARIASI', 'Variasi bahan mingguan', 'variasi_min', NULL, 8, 'mingguan', 'Rencana seminggu hanya memakai sedikit jenis bahan. Tambah variasi bahan.', 'peringatan'),
('RULE_BIAYA', 'Batas estimasi biaya per porsi', 'biaya_maks', NULL, NULL, 'menu', 'Estimasi biaya per porsi melewati batas yang dikonfigurasi.', 'peringatan');
