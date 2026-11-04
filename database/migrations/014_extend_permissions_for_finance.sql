-- Tahap 4: permission dan pengaturan untuk modul RAB, anggaran, transaksi, dan jadwal.
-- Permission lama tidak diubah. Slug baru ditambahkan agar modul keuangan dapat diatur
-- lewat halaman Hak Akses tanpa mengubah kode aplikasi.

INSERT IGNORE INTO permissions (permission_name, permission_slug, module_name, description) VALUES
('Membuat RAB', 'budgets.create', 'budgets', 'Membuat RAB baru dan mengimpor rinciannya.'),
('Mengubah RAB', 'budgets.update', 'budgets', 'Mengubah RAB, item, dan menghapus item pada RAB berstatus draft.'),
('Menyetujui RAB', 'budgets.approve', 'budgets', 'Menyetujui atau menolak RAB yang diajukan.'),
('Mengajukan revisi anggaran', 'budgets.revision', 'budgets', 'Mengajukan RAB untuk persetujuan dan mengajukan perubahan anggaran proyek.'),
('Mengekspor RAB dan anggaran', 'budgets.export', 'budgets', 'Mengunduh rincian RAB dan rekap anggaran.'),
('Melihat transaksi keuangan', 'transactions.view', 'transactions', 'Membuka daftar, detail, dan bukti transaksi.'),
('Mencatat transaksi', 'transactions.create', 'transactions', 'Mencatat pengeluaran, pemasukan, pengembalian, dan penyesuaian.'),
('Mengubah transaksi', 'transactions.update', 'transactions', 'Mengubah transaksi yang masih berstatus draft atau ditolak.'),
('Menyetujui transaksi', 'transactions.approve', 'transactions', 'Menyetujui atau menolak transaksi yang diajukan.'),
('Membatalkan transaksi', 'transactions.cancel', 'transactions', 'Membatalkan transaksi yang sudah disetujui beserta alasannya.'),
('Mengekspor transaksi', 'transactions.export', 'transactions', 'Mengunduh daftar transaksi dan mencetak bukti transaksi.'),
('Melihat jadwal pembangunan', 'schedules.view', 'schedules', 'Membuka kalender, daftar kegiatan, dan Gantt Chart.'),
('Membuat jadwal kegiatan', 'schedules.create', 'schedules', 'Menambah kegiatan beserta ketergantungan pekerjaannya.'),
('Mengubah jadwal kegiatan', 'schedules.update', 'schedules', 'Mengubah tanggal, penanggung jawab, progres, dan ketergantungan.'),
('Menghapus jadwal kegiatan', 'schedules.delete', 'schedules', 'Menghapus kegiatan yang belum memiliki riwayat pekerjaan.'),
('Mengekspor jadwal', 'schedules.export', 'schedules', 'Mengunduh rekap jadwal dan ketergantungan kegiatan.');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'kepala_sppg'
    AND p.permission_slug IN (
        'budgets.view', 'budgets.approve', 'budgets.revision', 'budgets.export',
        'transactions.view', 'transactions.approve', 'transactions.cancel', 'transactions.export',
        'schedules.view', 'schedules.export'
    )
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'manajer_koordinator'
    AND p.permission_slug IN (
        'budgets.view', 'budgets.create', 'budgets.update', 'budgets.manage', 'budgets.revision', 'budgets.export',
        'transactions.view', 'transactions.create', 'transactions.update', 'transactions.export',
        'schedules.view', 'schedules.create', 'schedules.update', 'schedules.export'
    )
);

-- Staf Keuangan mencatat dan mengajukan transaksi tetapi tidak menyetujuinya sendiri.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'staf_keuangan'
    AND p.permission_slug IN (
        'budgets.view', 'budgets.create', 'budgets.update', 'budgets.manage', 'budgets.revision', 'budgets.export',
        'transactions.view', 'transactions.create', 'transactions.update', 'transactions.cancel', 'transactions.export',
        'schedules.view'
    )
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'pengawas_pembangunan'
    AND p.permission_slug IN (
        'budgets.view', 'transactions.view',
        'schedules.view', 'schedules.update', 'schedules.export'
    )
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'admin'
    AND p.permission_slug IN ('budgets.view', 'transactions.view', 'schedules.view')
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON r.role_slug = 'super_admin';

-- Ambang peringatan anggaran, kebijakan pemisahan tugas, dan jangka pengingat jadwal.
INSERT IGNORE INTO settings (setting_key, setting_value, updated_by, updated_at) VALUES
('budget_warning_percent', '85', NULL, NOW()),
('finance_segregation_of_duties', '1', NULL, NOW()),
('schedule_due_days', '7', NULL, NOW());
