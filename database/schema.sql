-- ============================================================
-- SPPG Cangkring - Skema database lengkap (referensi)
-- Cara yang disarankan: php database/install.php
-- Berkas ini merangkum hasil seluruh migration 001-005 supaya struktur
-- akhir mudah dibaca. Akun Super Admin dibuat oleh installer, bukan oleh
-- berkas ini, karena kata sandi harus lewat password_hash().
-- Semua perintah bersifat idempoten (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

CREATE DATABASE IF NOT EXISTS `sppg_cangkring`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `sppg_cangkring`;

-- ------------------------------------------------------------
-- Pencatatan versi migration
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `migrations` (
    `version` VARCHAR(120) NOT NULL,
    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Peran (RBAC)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `roles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_name` VARCHAR(80) NOT NULL,
    `role_slug` VARCHAR(80) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_name` (`role_name`),
    UNIQUE KEY `uq_roles_slug` (`role_slug`),
    KEY `idx_roles_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pengguna
-- role_id -> roles (RESTRICT: peran yang masih dipakai tidak bisa dihapus)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(120) NOT NULL,
    `username` VARCHAR(60) NOT NULL,
    `email` VARCHAR(160) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(30) NULL,
    `photo` VARCHAR(255) NULL,
    `role_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `last_login` DATETIME NULL,
    `last_login_ip` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_id` (`role_id`),
    KEY `idx_users_status` (`status`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Hak akses dan matriks peran-izin
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `permission_name` VARCHAR(120) NOT NULL,
    `permission_slug` VARCHAR(120) NOT NULL,
    `module_name` VARCHAR(60) NOT NULL,
    `description` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permissions_slug` (`permission_slug`),
    KEY `idx_permissions_module` (`module_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id` BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_permission` (`role_id`, `permission_id`),
    KEY `idx_role_permissions_permission` (`permission_id`),
    CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pengaturan aplikasi
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `updated_by` BIGINT UNSIGNED NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`),
    KEY `fk_settings_user` (`updated_by`),
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Audit log (kolom user_agent ditambahkan pada migration 005;
-- tabel ini adalah hasil RENAME activity_logs -> user_activity_logs)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NULL,
    `activity` VARCHAR(60) NOT NULL,
    `module` VARCHAR(60) NOT NULL DEFAULT 'sistem',
    `description` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_logs_created` (`created_at`),
    KEY `idx_logs_user` (`user_id`),
    KEY `idx_logs_action` (`activity`),
    KEY `idx_activity_module` (`module`),
    KEY `idx_activity_user_time` (`user_id`, `created_at`),
    CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pembatasan percobaan masuk
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `login` VARCHAR(190) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `successful` TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempt_lookup` (`login`, `ip_address`, `attempted_at`),
    KEY `idx_attempt_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Token reset kata sandi (struktur siap pakai; belum dipakai alur
-- "lupa kata sandi" karena belum ada layanan surel)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_resets_token` (`token_hash`),
    KEY `idx_password_resets_user` (`user_id`),
    KEY `idx_password_resets_expires` (`expires_at`),
    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Data awal: 11 peran dan 25 izin
-- ============================================================

INSERT IGNORE INTO `roles` (`role_name`, `role_slug`, `description`) VALUES
('Super Admin', 'super_admin', 'Kendali penuh terhadap sistem dan pengaturan hak akses.'),
('Kepala SPPG', 'kepala_sppg', 'Memantau keseluruhan pembangunan dan operasional MBG.'),
('Manajer/Koordinator', 'manajer_koordinator', 'Mengelola kegiatan sesuai tanggung jawab koordinasi.'),
('Staf Keuangan', 'staf_keuangan', 'Mengelola anggaran dan transaksi keuangan.'),
('Pengawas Pembangunan', 'pengawas_pembangunan', 'Mengelola dan memantau progres pembangunan.'),
('Staf Gudang', 'staf_gudang', 'Mengelola stok, bahan pangan, dan inventaris.'),
('Ahli Gizi', 'ahli_gizi', 'Mengelola perencanaan menu dan informasi gizi.'),
('Staf Produksi', 'staf_produksi', 'Mengelola proses produksi makanan.'),
('Petugas Quality Control', 'quality_control', 'Melakukan pemeriksaan kualitas dan keamanan pangan.'),
('Petugas Distribusi', 'petugas_distribusi', 'Mengelola pengiriman dan konfirmasi penerimaan.'),
('Admin', 'admin', 'Mengelola data dan administrasi sesuai izin yang diberikan.');

INSERT IGNORE INTO `permissions` (`permission_name`, `permission_slug`, `module_name`, `description`) VALUES
('Melihat pengguna', 'users.view', 'users', 'Membuka daftar dan detail pengguna.'),
('Menambah pengguna', 'users.create', 'users', 'Membuat akun pengguna baru.'),
('Mengubah pengguna', 'users.update', 'users', 'Mengubah profil, role, dan status pengguna.'),
('Menghapus pengguna', 'users.delete', 'users', 'Menghapus akun pengguna.'),
('Melihat proyek', 'projects.view', 'projects', 'Membuka data proyek pembangunan.'),
('Menambah proyek', 'projects.create', 'projects', 'Membuat data proyek pembangunan.'),
('Mengubah proyek', 'projects.update', 'projects', 'Memperbarui data proyek pembangunan.'),
('Menghapus proyek', 'projects.delete', 'projects', 'Menghapus data proyek pembangunan.'),
('Melihat anggaran', 'budgets.view', 'budgets', 'Membuka data anggaran.'),
('Mengelola anggaran', 'budgets.manage', 'budgets', 'Mengubah data anggaran dan transaksi.'),
('Melihat sekolah', 'schools.view', 'schools', 'Membuka data sekolah penerima manfaat.'),
('Mengelola sekolah', 'schools.manage', 'schools', 'Mengubah data sekolah penerima manfaat.'),
('Melihat menu', 'menus.view', 'menus', 'Membuka perencanaan menu dan gizi.'),
('Mengelola menu', 'menus.manage', 'menus', 'Mengubah perencanaan menu dan gizi.'),
('Melihat inventaris', 'inventory.view', 'inventory', 'Membuka data stok dan inventaris.'),
('Mengelola inventaris', 'inventory.manage', 'inventory', 'Mengubah data stok dan inventaris.'),
('Melihat produksi', 'production.view', 'production', 'Membuka data proses produksi.'),
('Mengelola produksi', 'production.manage', 'production', 'Mengubah data proses produksi.'),
('Mengelola quality control', 'quality_control.manage', 'quality_control', 'Mencatat pemeriksaan kualitas dan keamanan pangan.'),
('Mengelola distribusi', 'distribution.manage', 'distribution', 'Mengelola pengiriman dan konfirmasi penerimaan.'),
('Melihat laporan', 'reports.view', 'reports', 'Membuka laporan sistem.'),
('Mengekspor laporan', 'reports.export', 'reports', 'Mengekspor laporan ke berkas.'),
('Mengelola pengaturan', 'settings.manage', 'settings', 'Mengubah pengaturan aplikasi.'),
('Mengelola hak akses', 'permissions.manage', 'permissions', 'Mengatur role dan permission.'),
('Melihat audit log', 'audit.view', 'audit', 'Membuka riwayat aktivitas pengguna.');

-- Super Admin selalu memegang seluruh izin. Peran lain memakai hak akses
-- bawaan yang dapat diubah dari halaman Hak Akses tanpa menyunting kode.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON (
    r.role_slug = 'super_admin'
    OR (r.role_slug = 'kepala_sppg' AND p.permission_slug IN (
        'users.view', 'projects.view', 'budgets.view', 'schools.view', 'menus.view',
        'inventory.view', 'production.view', 'reports.view', 'reports.export', 'audit.view'
    ))
    OR (r.role_slug = 'manajer_koordinator' AND p.permission_slug IN (
        'projects.view', 'projects.update', 'budgets.view', 'schools.view', 'menus.view',
        'inventory.view', 'production.view', 'quality_control.manage', 'distribution.manage', 'reports.view'
    ))
    OR (r.role_slug = 'staf_keuangan' AND p.permission_slug IN (
        'projects.view', 'budgets.view', 'budgets.manage', 'reports.view', 'reports.export'
    ))
    OR (r.role_slug = 'pengawas_pembangunan' AND p.permission_slug IN (
        'projects.view', 'projects.create', 'projects.update', 'reports.view'
    ))
    OR (r.role_slug = 'staf_gudang' AND p.permission_slug IN (
        'inventory.view', 'inventory.manage', 'reports.view'
    ))
    OR (r.role_slug = 'ahli_gizi' AND p.permission_slug IN (
        'menus.view', 'menus.manage', 'reports.view'
    ))
    OR (r.role_slug = 'staf_produksi' AND p.permission_slug IN (
        'menus.view', 'inventory.view', 'production.view', 'production.manage'
    ))
    OR (r.role_slug = 'quality_control' AND p.permission_slug IN (
        'production.view', 'quality_control.manage', 'reports.view'
    ))
    OR (r.role_slug = 'petugas_distribusi' AND p.permission_slug IN (
        'distribution.manage', 'reports.view'
    ))
    OR (r.role_slug = 'admin' AND p.permission_slug IN (
        'users.view', 'users.create', 'users.update', 'schools.manage',
        'settings.manage', 'reports.view', 'audit.view'
    ))
);
