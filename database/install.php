<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Database;
use App\Core\Env;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Halaman tidak ditemukan.\n");
}

function out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

$app = require dirname(__DIR__) . '/bootstrap/app.php';

$db = App::config('database');
$database = (string) $db['database'];

if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    out('GAGAL: nama database hanya boleh memuat huruf, angka, dan garis bawah.');
    exit(1);
}

out('=== Instalasi SPPG Cangkring ===');
out(sprintf('Server database : %s:%d', (string) $db['host'], (int) $db['port']));
out(sprintf('Nama database   : %s', $database));
out('');

$serverDsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    (string) $db['host'],
    (int) $db['port'],
    (string) $db['charset']
);

try {
    $server = new PDO(
        $serverDsn,
        (string) $db['username'],
        (string) $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    out('GAGAL: koneksi ke server database tidak berhasil.');
    out('Detail: ' . $exception->getMessage());
    out('Periksa DB_HOST, DB_PORT, DB_USERNAME, dan DB_PASSWORD pada file .env.');
    exit(1);
}

$server->exec(
    'CREATE DATABASE IF NOT EXISTS `' . $database . '` '
    . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
out('[OK] Database tersedia atau berhasil dibuat.');

$pdo = Database::connection();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        version VARCHAR(120) NOT NULL,
        executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_map('strval', $applied);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

$appliedCount = 0;
$skippedCount = 0;

foreach ($files as $file) {
    $version = basename($file);

    if (in_array($version, $applied, true)) {
        $skippedCount++;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        out('[GAGAL] Tidak dapat membaca ' . $version);
        exit(1);
    }

    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
    $statements = array_values(array_filter(
        array_map('trim', explode(';', $sql)),
        static fn (string $statement): bool => $statement !== ''
    ));

    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        $insert = $pdo->prepare('INSERT INTO migrations (version, executed_at) VALUES (?, NOW())');
        $insert->execute([$version]);

        $appliedCount++;
        out('[OK] Migrasi diterapkan: ' . $version);
    } catch (Throwable $exception) {
        out('[GAGAL] Migrasi ' . $version . ': ' . $exception->getMessage());
        exit(1);
    }
}

if ($appliedCount === 0) {
    out('[OK] Semua migrasi sudah diterapkan sebelumnya (' . $skippedCount . ' berkas).');
}

$roleCount = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
$permissionCount = (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
out(sprintf('[OK] Role tersedia: %d, permission tersedia: %d.', $roleCount, $permissionCount));

$superAdminRoleId = (int) $pdo->query(
    "SELECT id FROM roles WHERE role_slug = 'super_admin' LIMIT 1"
)->fetchColumn();

if ($superAdminRoleId === 0) {
    out('GAGAL: role Super Admin tidak ditemukan. Periksa migration 005.');
    exit(1);
}

$admin = $pdo->query("SELECT id, username FROM users WHERE username = 'admin' LIMIT 1")->fetch();
$newAdmin = false;
$adminPassword = '';

if ($admin === false) {
    $adminPassword = trim((string) Env::get('SEED_ADMIN_PASSWORD', ''));
    if ($adminPassword === '') {
        $adminPassword = 'Admin#Sppg2026';
    }

    if (strlen($adminPassword) < 8 || strlen($adminPassword) > 72) {
        out('GAGAL: SEED_ADMIN_PASSWORD harus terdiri dari 8 sampai 72 karakter.');
        exit(1);
    }

    if (preg_match('/[A-Za-z]/', $adminPassword) !== 1 || preg_match('/\d/', $adminPassword) !== 1) {
        out('GAGAL: SEED_ADMIN_PASSWORD harus memuat huruf dan angka.');
        exit(1);
    }

    $insertAdmin = $pdo->prepare(
        'INSERT INTO users (full_name, username, email, phone, password, role_id, status, created_at, updated_at)
         VALUES (?, ?, ?, NULL, ?, ?, ?, NOW(), NOW())'
    );
    $insertAdmin->execute([
        'Administrator SPPG',
        'admin',
        'admin@sppg-cangkring.local',
        password_hash($adminPassword, PASSWORD_DEFAULT),
        $superAdminRoleId,
        'active',
    ]);

    $adminId = (int) $pdo->lastInsertId();
    $newAdmin = true;
    out('[OK] Akun Super Admin dibuat.');
} else {
    $adminId = (int) $admin['id'];
    out('[OK] Akun administrator sudah tersedia; kata sandi tidak diubah.');
}

$activeSuperAdmins = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE r.role_slug = 'super_admin' AND u.status = 'active'"
)->fetchColumn();

if ($activeSuperAdmins === 0) {
    out('PERINGATAN: belum ada Super Admin aktif. Tentukan role Super Admin sebelum aplikasi dipakai.');
}

$defaultSettings = [
    'app_name' => 'SPPG Cangkring',
    'institution_name' => 'Satuan Pelayanan Pemenuhan Gizi Cangkring',
    'timezone' => 'Asia/Jakarta',
    'support_email' => '',
];

$insertSetting = $pdo->prepare(
    'INSERT IGNORE INTO settings (setting_key, setting_value, updated_by, updated_at)
     VALUES (?, ?, ?, NOW())'
);

foreach ($defaultSettings as $key => $value) {
    $insertSetting->execute([$key, $value, $adminId]);
}

out('[OK] Pengaturan awal tersedia.');
out('');
out('Instalasi selesai.');
out('Jalankan Apache, lalu buka http://localhost/SPPG-Cangkring/public/');
out('Atau jalankan server lokal: php -S 127.0.0.1:8099 -t public public/router.php');
out('');

if ($newAdmin) {
    out('Akun awal (Super Admin):');
    out('  Nama pengguna : admin');
    out('  Kata sandi    : ' . $adminPassword);
    out('Ubah kata sandi melalui menu Profil setelah masuk pertama kali.');
}
