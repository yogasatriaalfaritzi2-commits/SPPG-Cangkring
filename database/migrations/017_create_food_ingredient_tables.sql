-- Tahap 5: katalog bahan makanan dan kandungan gizinya.
-- Nilai gizi tidak pernah dibuat otomatis. Setiap angka harus membawa sumber referensi
-- atau secara jujur ditandai sebagai estimasi, sehingga tampilan dapat menampilkan status
-- "Data gizi belum tersedia" ketika belum ada baris nilai untuk suatu bahan.

CREATE TABLE IF NOT EXISTS ingredient_categories (
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
    UNIQUE KEY uq_ingredient_categories_code (category_code),
    KEY idx_ingredient_categories_list (is_active, sort_order, category_name),
    CONSTRAINT fk_ingredient_categories_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ingredient_categories_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sumber data gizi yang dapat dipertanggungjawabkan. Nama referensi disimpan apa adanya
-- beserta penerbit dan tahunnya agar setiap angka dapat ditelusuri.
CREATE TABLE IF NOT EXISTS nutrition_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_code VARCHAR(30) NOT NULL,
    reference_name VARCHAR(200) NOT NULL,
    publisher VARCHAR(150) NULL,
    published_year SMALLINT UNSIGNED NULL,
    reference_url VARCHAR(255) NULL,
    needs_verification TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nutrition_references_code (reference_code),
    KEY idx_nutrition_references_list (is_active, published_year),
    CONSTRAINT fk_nutrition_references_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_nutrition_references_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_nutrition_references_year CHECK (published_year IS NULL OR (published_year BETWEEN 1900 AND 2100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ingredients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_code VARCHAR(30) NOT NULL,
    ingredient_name VARCHAR(150) NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    base_unit VARCHAR(20) NOT NULL DEFAULT 'gram',
    purchase_unit VARCHAR(20) NULL,
    conversion_factor DECIMAL(12, 4) NULL,
    edible_factor DECIMAL(6, 4) NULL,
    allergen_info VARCHAR(255) NULL,
    reference_price DECIMAL(18, 2) NULL,
    price_basis VARCHAR(40) NULL,
    price_reference VARCHAR(150) NULL,
    price_updated_at DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_food_ingredients_code (ingredient_code),
    KEY idx_food_ingredients_name (ingredient_name),
    KEY idx_food_ingredients_category (category_id, is_active),
    CONSTRAINT fk_food_ingredients_category FOREIGN KEY (category_id) REFERENCES ingredient_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_food_ingredients_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_food_ingredients_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_food_ingredients_conversion CHECK (conversion_factor IS NULL OR conversion_factor > 0),
    CONSTRAINT chk_food_ingredients_edible CHECK (edible_factor IS NULL OR (edible_factor > 0 AND edible_factor <= 1)),
    CONSTRAINT chk_food_ingredients_price CHECK (reference_price IS NULL OR reference_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris untuk satu zat gizi per bahan. reference_amount dan reference_unit adalah
-- dasar penyajian angka (umumnya per 100 gram), sehingga perhitungan sisi server memakai
-- rumus (berat bahan dalam satuan dasar / reference_amount) x nilai gizi.
CREATE TABLE IF NOT EXISTS ingredient_nutrients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_id BIGINT UNSIGNED NOT NULL,
    nutrient_code VARCHAR(30) NOT NULL,
    nutrient_value DECIMAL(14, 4) NOT NULL,
    nutrient_unit VARCHAR(12) NOT NULL,
    reference_amount DECIMAL(10, 3) NOT NULL DEFAULT 100.000,
    reference_unit VARCHAR(12) NOT NULL DEFAULT 'gram',
    value_source ENUM('referensi', 'estimasi') NOT NULL DEFAULT 'referensi',
    reference_id BIGINT UNSIGNED NULL,
    data_updated_at DATE NULL,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ingredient_nutrients_code (ingredient_id, nutrient_code),
    KEY idx_ingredient_nutrients_reference (reference_id),
    CONSTRAINT fk_ingredient_nutrients_ingredient FOREIGN KEY (ingredient_id) REFERENCES food_ingredients (id) ON DELETE CASCADE,
    CONSTRAINT fk_ingredient_nutrients_reference FOREIGN KEY (reference_id) REFERENCES nutrition_references (id) ON DELETE SET NULL,
    CONSTRAINT fk_ingredient_nutrients_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ingredient_nutrients_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ingredient_nutrients_value CHECK (nutrient_value >= 0),
    CONSTRAINT chk_ingredient_nutrients_amount CHECK (reference_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kategori bahan bawaan MBG.
INSERT IGNORE INTO ingredient_categories (category_code, category_name, description, sort_order) VALUES
('SEREALIA', 'Beras dan serealia', 'Beras, jagung, mie, roti, dan sejenisnya.', 10),
('DAGING', 'Daging', 'Daging sapi, kambing, ayam, dan turunannya.', 20),
('IKAN', 'Ikan dan hasil laut', 'Ikan, udang, cumi, dan hasil perikanan lainnya.', 30),
('TELUR', 'Telur', 'Telur ayam, telur bebek, dan sejenisnya.', 40),
('SAYUR', 'Sayuran', 'Sayuran daun, buah, dan umbi.', 50),
('BUAH', 'Buah-buahan', 'Buah segar dan olahan sederhana.', 60),
('SUSU', 'Susu dan produk olahan', 'Susu, yogurt, keju.', 70),
('KACANG', 'Kacang-kacangan', 'Kacang kedelai, tempe, tahu, dan kacang lainnya.', 80),
('BUMBU', 'Bumbu', 'Bumbu masak dan penyedap.', 90),
('PENDUKUNG', 'Bahan pendukung', 'Minyak goreng, gas, kemasan, dan bahan pembantu lain.', 100);

-- Referensi yang lazim dipakai ahli gizi di Indonesia. Angka gizinya tidak ikut dibundel
-- di sini karena harus dimasukkan sendiri setelah diverifikasi terhadap dokumen aslinya.
INSERT IGNORE INTO nutrition_references (reference_code, reference_name, publisher, published_year, needs_verification) VALUES
('TKPI-2017', 'Tabel Komposisi Pangan Indonesia 2017', 'Kementerian Kesehatan RI', 2017, 1),
('AKG-2019', 'Peraturan Menteri Kesehatan No. 28 Tahun 2019 tentang Angka Kecukupan Gizi', 'Kementerian Kesehatan RI', 2019, 1),
('PEDOMAN-MBG', 'Pedoman penyelenggaraan Makan Bergizi Gratis yang berlaku', 'Badan Gizi Nasional', 2025, 1),
('LOKAL', 'Data internal SPPG yang belum tertelusur ke dokumen resmi', NULL, NULL, 1);
