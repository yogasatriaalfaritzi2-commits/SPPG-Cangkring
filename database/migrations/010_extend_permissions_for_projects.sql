-- Tahap 3: perluasan permission untuk dashboard dan modul proyek pembangunan.
-- Permission lama tidak diubah; grant baru ditambahkan lewat INSERT IGNORE agar migration dapat dijalankan ulang.

INSERT IGNORE INTO permissions (permission_name, permission_slug, module_name, description) VALUES
('Melihat dashboard', 'dashboard.view', 'dashboard', 'Membuka halaman dashboard utama.'),
('Mengarsipkan proyek', 'projects.archive', 'projects', 'Mengarsipkan atau memulihkan data proyek.'),
('Mengubah status proyek', 'projects.status.update', 'projects', 'Memperbarui status proyek beserta alasannya.'),
('Menginput progres proyek', 'projects.progress.update', 'projects', 'Mencatat dan memperbarui laporan progres proyek.'),
('Melihat dokumen proyek', 'projects.documents.view', 'projects', 'Membuka daftar dan mengunduh dokumen proyek.'),
('Mengunggah dokumen proyek', 'projects.documents.upload', 'projects', 'Mengunggah foto dan berkas dokumen proyek.'),
('Menghapus dokumen proyek', 'projects.documents.delete', 'projects', 'Menghapus berkas dokumen proyek.'),
('Mengelola tim proyek', 'projects.team.manage', 'projects', 'Menentukan penanggung jawab dan anggota tim proyek.');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_slug = 'dashboard.view';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'kepala_sppg'
    AND p.permission_slug IN ('projects.documents.view', 'projects.team.manage')
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'manajer_koordinator'
    AND p.permission_slug IN (
        'projects.archive', 'projects.status.update', 'projects.progress.update',
        'projects.documents.view', 'projects.documents.upload', 'projects.team.manage'
    )
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'pengawas_pembangunan'
    AND p.permission_slug IN ('projects.progress.update', 'projects.documents.view', 'projects.documents.upload')
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON (
    r.role_slug = 'admin'
    AND p.permission_slug IN ('projects.view', 'projects.documents.view')
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON r.role_slug = 'super_admin';
