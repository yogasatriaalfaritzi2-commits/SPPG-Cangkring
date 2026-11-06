-- Tahap 5: data penerima manfaat MBG.
-- Mode utama adalah rekap jumlah sehingga identitas pribadi siswa tidak perlu disimpan.
-- Mode individual tersedia opsional, terpisah, dan hanya dapat dibuka oleh pengguna
-- dengan izin khusus; NIK dan data sensitif lain sengaja tidak disediakan kolomnya.

CREATE TABLE IF NOT EXISTS beneficiary_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_code VARCHAR(20) NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_beneficiary_categories_code (category_code),
    KEY idx_beneficiary_categories_list (is_active, sort_order, category_name),
    CONSTRAINT fk_beneficiary_categories_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_beneficiary_categories_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rekap jumlah penerima per sekolah, kategori, dan kelompok kelas pada suatu tanggal berlaku.
-- Baris lama tidak diubah ketika jumlah berubah: tanggal berlaku baru membuat baris baru,
-- sehingga riwayat penambahan atau pengurangan tetap terbaca dan tidak ada penghitungan ganda
-- karena kombinasi (sekolah, kategori, kelompok, tanggal berlaku) bersifat unik.
CREATE TABLE IF NOT EXISTS beneficiaries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    class_group VARCHAR(60) NOT NULL DEFAULT '',
    beneficiary_count INT UNSIGNED NOT NULL DEFAULT 0,
    effective_date DATE NOT NULL,
    record_status ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_beneficiaries_scope (school_id, category_id, class_group, effective_date),
    KEY idx_beneficiaries_effective (effective_date, record_status),
    KEY idx_beneficiaries_category (category_id, record_status),
    KEY idx_beneficiaries_created (created_by),
    CONSTRAINT fk_beneficiaries_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT,
    CONSTRAINT fk_beneficiaries_category FOREIGN KEY (category_id) REFERENCES beneficiary_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_beneficiaries_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_beneficiaries_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_beneficiaries_count CHECK (beneficiary_count <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Setiap perubahan jumlah wajib disertai alasan dan mencatat petugas yang mengubah.
-- Nilai sebelum dan sesudah disimpan pada baris riwayat agar tren dari waktu ke waktu
-- dapat direkonstruksi meski baris rekapnya sendiri sudah diganti.
CREATE TABLE IF NOT EXISTS beneficiary_histories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    beneficiary_id BIGINT UNSIGNED NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    class_group VARCHAR(60) NOT NULL DEFAULT '',
    change_action ENUM('buat', 'ubah', 'nonaktifkan', 'aktifkan', 'hapus') NOT NULL,
    previous_count INT UNSIGNED NULL,
    new_count INT UNSIGNED NULL,
    previous_effective_date DATE NULL,
    new_effective_date DATE NULL,
    reason VARCHAR(500) NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_beneficiary_histories_scope (school_id, category_id, class_group, changed_at),
    KEY idx_beneficiary_histories_record (beneficiary_id),
    KEY idx_beneficiary_histories_actor (changed_by),
    CONSTRAINT fk_beneficiary_histories_record FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries (id) ON DELETE SET NULL,
    CONSTRAINT fk_beneficiary_histories_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
    CONSTRAINT fk_beneficiary_histories_category FOREIGN KEY (category_id) REFERENCES beneficiary_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_beneficiary_histories_actor FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mode individual opsional. Kolom dibatasi pada nama, kelompok, dan keterangan:
-- tidak ada NIK, nomor Kartu Keluarga, alamat rumah, atau data medis.
CREATE TABLE IF NOT EXISTS beneficiary_individuals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    class_group VARCHAR(60) NOT NULL DEFAULT '',
    full_name VARCHAR(120) NOT NULL,
    birth_year SMALLINT UNSIGNED NULL,
    record_status ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_beneficiary_individuals_name (school_id, category_id, class_group, full_name),
    KEY idx_beneficiary_individuals_status (school_id, record_status),
    CONSTRAINT fk_beneficiary_individuals_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT,
    CONSTRAINT fk_beneficiary_individuals_category FOREIGN KEY (category_id) REFERENCES beneficiary_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_beneficiary_individuals_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_beneficiary_individuals_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_beneficiary_individuals_year CHECK (birth_year IS NULL OR (birth_year BETWEEN 1900 AND 2100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kategori bawaan. Kategori tambahan dapat dibuat lewat halaman penerima manfaat.
INSERT IGNORE INTO beneficiary_categories (category_code, category_name, description, sort_order) VALUES
('SISWA', 'Siswa', 'Peserta didik penerima manfaat makanan.', 10),
('GURU', 'Guru dan tenaga kependidikan', 'Pendidik serta tenaga kependidikan yang ikut menerima manfaat.', 20),
('PIKET', 'Petugas piket sekolah', 'Petugas yang berjaga pada hari pelayanan.', 30);
