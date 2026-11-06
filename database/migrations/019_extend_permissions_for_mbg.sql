-- Tahap 5: permission dan pengaturan untuk modul sekolah, penerima manfaat, menu, dan gizi.
-- Permission lama tidak diubah. Slug baru ditambahkan agar modul MBG dapat diatur
-- lewat halaman Hak Akses tanpa mengubah kode aplikasi.

INSERT IGNORE INTO permissions (permission_name, permission_slug, module_name, description) VALUES
('Melihat data sekolah', 'schools.view', 'schools', 'Membuka daftar, detail, dan rekap sekolah penerima manfaat.'),
('Mendaftarkan sekolah', 'schools.create', 'schools', 'Menambah sekolah penerima manfaat baru.'),
('Mengubah data sekolah', 'schools.update', 'schools', 'Mengubah data sekolah dan hari pelayanannya.'),
('Mengarsipkan sekolah', 'schools.archive', 'schools', 'Mengarsipkan atau mengaktifkan kembali sekolah tanpa menghapus riwayat.'),
('Mengimpor data sekolah', 'schools.import', 'schools', 'Memuat berkas CSV sekolah penerima manfaat.'),
('Mengekspor data sekolah', 'schools.export', 'schools', 'Mengunduh daftar sekolah beserta rekap penerimanya.'),
('Melihat penerima manfaat', 'beneficiaries.view', 'beneficiaries', 'Membuka rekap jumlah penerima manfaat dan riwayat perubahannya.'),
('Mengelola penerima manfaat', 'beneficiaries.manage', 'beneficiaries', 'Mencatat dan mengubah jumlah penerima manfaat beserta alasannya.'),
('Mengekspor penerima manfaat', 'beneficiaries.export', 'beneficiaries', 'Mengunduh rekap dan riwayat perubahan penerima manfaat.'),
('Melihat data penerima individual', 'beneficiaries.individual.view', 'beneficiaries', 'Membuka mode data individual yang dibatasi, hanya atas dasar kewenangan.'),
('Melihat menu dan gizi', 'menus.view', 'menus', 'Membuka daftar, detail, dan rekap rencana menu MBG.'),
('Membuat menu', 'menus.create', 'menus', 'Membuat menu beserta daftar bahan per porsinya.'),
('Mengubah menu', 'menus.update', 'menus', 'Mengubah menu yang masih berstatus draft atau ditolak.'),
('Menyetujui menu', 'menus.approve', 'menus', 'Menyetujui atau menolak menu dan rencana menu yang diajukan.'),
('Mengajukan revisi menu', 'menus.revision', 'menus', 'Mengajukan menu yang sudah disetujui untuk direvisi dan disetujui ulang.'),
('Melihat jadwal menu', 'menu_schedules.view', 'menus', 'Membuka perencana menu harian dan mingguan serta kebutuhan bahan.'),
('Mengelola jadwal menu', 'menu_schedules.manage', 'menus', 'Menyusun, menyalin, dan mengubah rencana menu harian dan mingguan.'),
('Melihat analisis gizi', 'nutrition.view', 'nutrition', 'Membuka katalog bahan, nilai gizi, target gizi, dan hasil analisis.'),
('Mengelola bahan dan gizi', 'nutrition.manage', 'nutrition', 'Mengelola bahan makanan, nilai gizi, target gizi, dan aturan rekomendasi.'),
('Mengekspor data gizi', 'nutrition.export', 'nutrition', 'Mengunduh katalog bahan, analisis gizi, dan rekap kebutuhan bahan.');

-- Kepala SPPG memantau dan menyetujui sesuai kewenangan.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'kepala_sppg'
    AND p.permission_slug IN (
        'schools.view', 'schools.export', 'beneficiaries.view', 'beneficiaries.export',
        'menus.view', 'menus.approve', 'menu_schedules.view', 'nutrition.view', 'nutrition.export'
    )
);

-- Manajer/Koordinator mengelola operasional harian termasuk pengajuan menu.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'manajer_koordinator'
    AND p.permission_slug IN (
        'schools.view', 'schools.create', 'schools.update', 'schools.import', 'schools.export',
        'beneficiaries.view', 'beneficiaries.manage', 'beneficiaries.export',
        'menus.view', 'menus.create', 'menus.update', 'menus.revision',
        'menu_schedules.view', 'menu_schedules.manage', 'nutrition.view'
    )
);

-- Ahli Gizi mengelola menu, bahan, nilai gizi, dan target gizi.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'ahli_gizi'
    AND p.permission_slug IN (
        'schools.view', 'beneficiaries.view', 'beneficiaries.export',
        'menus.view', 'menus.create', 'menus.update', 'menus.revision',
        'menu_schedules.view', 'menu_schedules.manage',
        'nutrition.view', 'nutrition.manage', 'nutrition.export'
    )
);

-- Admin mengelola data sekolah dan administrasi penerima manfaat.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'admin'
    AND p.permission_slug IN (
        'schools.view', 'schools.create', 'schools.update', 'schools.archive',
        'schools.import', 'schools.export',
        'beneficiaries.view', 'beneficiaries.manage', 'beneficiaries.export',
        'menus.view', 'menu_schedules.view', 'nutrition.view'
    )
);

-- Koordinator Dapur Uji mencoba dan mengusulkan menu.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'koordinator_dapur_uji'
    AND p.permission_slug IN ('menus.view', 'menus.create', 'menus.update', 'nutrition.view')
);

-- Quality Control memeriksa menu dan catatan gizi hanya untuk dibaca.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'quality_control'
    AND p.permission_slug IN ('menus.view', 'menu_schedules.view', 'nutrition.view')
);

-- Staf Gudang membutuhkan rencana menu dan kebutuhan bahan untuk pengadaan (Prompt 6).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'staf_gudang'
    AND p.permission_slug IN ('schools.view', 'menu_schedules.view', 'nutrition.view')
);

-- Staf Produksi melihat menu yang terjadwal pada hari pelayanan.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'staf_produksi'
    AND p.permission_slug IN ('menus.view', 'menu_schedules.view')
);

-- Petugas Distribusi membutuhkan data sekolah dan jumlah porsi.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'petugas_distribusi'
    AND p.permission_slug IN ('schools.view', 'beneficiaries.view', 'menu_schedules.view')
);

-- Staf Keuangan membaca estimasi biaya menu untuk penyusunan anggaran operasional.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'staf_keuangan'
    AND p.permission_slug IN ('menus.view', 'menu_schedules.view')
);

-- Super Admin memperoleh seluruh permission yang terdaftar.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON r.role_slug = 'super_admin';

-- Mode data penerima manfaat. Nilai bawaan 'rekap' menjaga identitas siswa tetap terlindungi.
-- 'individual' hanya mengubah tampilan form, pemeriksaan akses tetap di sisi server.
INSERT IGNORE INTO settings (setting_key, setting_value, updated_by, updated_at) VALUES
('mbg_beneficiary_mode', 'rekap', NULL, NOW());
