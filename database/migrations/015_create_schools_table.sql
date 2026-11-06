-- Tahap 5: sekolah penerima manfaat MBG.
-- Jenjang pendidikan dapat dikonfigurasi, sekolah tidak pernah dihapus permanen
-- setelah memiliki riwayat distribusi (gunakan arsip atau nonaktifkan).
-- Semua perintah bersifat idempoten sehingga aman dijalankan pada database berisi data.

CREATE TABLE IF NOT EXISTS school_levels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_code VARCHAR(20) NOT NULL,
    level_name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_school_levels_code (level_code),
    KEY idx_school_levels_list (is_active, sort_order, level_name),
    CONSTRAINT fk_school_levels_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_school_levels_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schools (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_code VARCHAR(30) NOT NULL,
    school_name VARCHAR(150) NOT NULL,
    npsn VARCHAR(10) NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    school_status ENUM('aktif', 'nonaktif', 'arsip') NOT NULL DEFAULT 'aktif',
    partnership_status ENUM('belum_ada', 'moa_aktif', 'moa_akan_habis', 'ditangguhkan', 'berakhir') NOT NULL DEFAULT 'belum_ada',
    partnership_start DATE NULL,
    partnership_end DATE NULL,
    address VARCHAR(500) NULL,
    province VARCHAR(80) NULL,
    regency VARCHAR(80) NULL,
    district VARCHAR(80) NULL,
    village VARCHAR(80) NULL,
    postal_code VARCHAR(10) NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    person_in_charge VARCHAR(120) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    notes VARCHAR(1000) NULL,
    archived_at DATETIME NULL,
    archived_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schools_code (school_code),
    UNIQUE KEY uq_schools_npsn (npsn),
    KEY idx_schools_status (school_status, partnership_status),
    KEY idx_schools_level (level_id, school_status),
    KEY idx_schools_region (regency, district, village),
    KEY idx_schools_name (school_name),
    KEY idx_schools_created (created_by),
    CONSTRAINT fk_schools_level FOREIGN KEY (level_id) REFERENCES school_levels (id) ON DELETE RESTRICT,
    CONSTRAINT fk_schools_archived FOREIGN KEY (archived_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_schools_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_schools_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_schools_partnership CHECK (partnership_end IS NULL OR partnership_start IS NULL OR partnership_end >= partnership_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hari pelayanan per sekolah. Tidak semua sekolah memiliki jadwal layanan yang sama,
-- jadi hari layanan dikonfigurasi dan bukan diasumsikan dari aplikasi.
CREATE TABLE IF NOT EXISTS school_service_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    service_weekday TINYINT UNSIGNED NOT NULL,
    service_time TIME NULL,
    meal_slot ENUM('sarapan', 'makan_pagi', 'makan_siang', 'makan_sore') NOT NULL DEFAULT 'makan_siang',
    portion_limit INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_schedules_day (school_id, service_weekday, meal_slot),
    KEY idx_service_schedules_weekday (service_weekday, is_active),
    CONSTRAINT fk_service_schedules_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
    CONSTRAINT fk_service_schedules_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_service_schedules_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_service_schedules_weekday CHECK (service_weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jenjang bawaan sesuai kebijakan MBG. Baris tambahan dapat dibuat lewat halaman
-- tanpa mengubah kode aplikasi; penonaktifan menjaga riwayat sekolah tetap terbaca.
INSERT IGNORE INTO school_levels (level_code, level_name, description, sort_order) VALUES
('PAUD', 'PAUD/TK', 'Pendidikan Anak Usia Dini, termasuk Taman Kanak-Kanak.', 10),
('SD', 'SD/MI', 'Sekolah Dasar atau Madrasah Ibtidaiyah.', 20),
('SMP', 'SMP/MTs', 'Sekolah Menengah Pertama atau Madrasah Tsanawiyah.', 30),
('SMA', 'SMA/MA', 'Sekolah Menengah Atas atau Madrasah Aliyah.', 40),
('SMK', 'SMK', 'Sekolah Menengah Kejuruan.', 50),
('SLB', 'SLB', 'Sekolah Luar Biasa.', 60),
('SETARA', 'Kesetaraan', 'Program Paket A, Paket B, atau Paket C dan kategori lain sesuai kebijakan.', 70);
