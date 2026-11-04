<?php
declare(strict_types=1);

/**
 * Pengujian autentikasi, RBAC, manajemen pengguna, hak akses, dan log aktivitas.
 *
 * Jalankan server lokal lebih dulu:
 *   php -S 127.0.0.1:8099 -t public public/router.php
 * lalu:
 *   php tests/rbac.php
 *
 * Variabel opsional: TEST_BASE_URL, ADMIN_USERNAME, ADMIN_PASSWORD, KEEP_TEST_DATA=1.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Skrip ini hanya boleh dijalankan dari CLI.\n");
    exit(1);
}

use App\Core\Database;

require dirname(__DIR__) . '/bootstrap/app.php';

$baseUrl = rtrim((string) (getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8099'), '/');
$adminUser = (string) (getenv('ADMIN_USERNAME') ?: 'admin');
$adminPass = (string) (getenv('ADMIN_PASSWORD') ?: 'Admin#Sppg2026');

$password = 'Rahasia#Uji2026';
$newPassword = 'Baru#Uji2026';

$passed = 0;
$failed = 0;
$tempFiles = [];
$createdUserIds = [];
$createdRoleIds = [];
$createdLogins = [];
$testStartedAt = time();
$adminSessionId = null;

function db(): PDO
{
    return Database::connection();
}

/** @param array<string, mixed>|null $data */
function http(string $method, string $path, ?array $data = null, array $headers = [], ?string $jar = null): array
{
    global $baseUrl;

    $ch = curl_init($baseUrl . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20,
    ];

    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($data !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    if ($headers !== []) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $options);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    $headerLines = preg_split("/\r\n|\n|\r/", trim(substr($raw, 0, $headerSize))) ?: [];
    $parsed = [];
    foreach ($headerLines as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $parsed[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
    }

    return ['status' => $status, 'body' => substr($raw, $headerSize), 'headers' => $parsed, 'error' => $error];
}

function csrfToken(string $html): string
{
    return preg_match('/name="_token"\s+value="([^"]+)"/', $html, $match) === 1 ? $match[1] : '';
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  [LULUS] {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
        return;
    }

    $failed++;
    echo "  [GAGAL] {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

function tempJar(): string
{
    global $tempFiles;

    $file = sys_get_temp_dir() . '/sppg-rbac-' . bin2hex(random_bytes(6)) . '.txt';
    $tempFiles[] = $file;

    return $file;
}

function locationOf(array $response): string
{
    return (string) ($response['headers']['location'] ?? '');
}

function sessionIdFrom(string $jar): string
{
    foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        // curl menandai cookie HttpOnly dengan awalan khusus pada baris yang sama.
        $line = (string) preg_replace('/^#HttpOnly_/', '', $line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = preg_split('/\t/' , $line) ?: [];
        if (count($parts) >= 7 && $parts[5] === 'sppg_session') {
            return (string) $parts[6];
        }
    }

    return '';
}

function clearAttempts(string $login): void
{
    $statement = db()->prepare('DELETE FROM login_attempts WHERE login = :login');
    $statement->execute(['login' => $login]);
}

/**
 * @return array<string, mixed> respons POST /login
 */
function loginAs(string $jar, string $login, string $secret): array
{
    clearAttempts($login);
    $page = http('GET', '/login', null, [], $jar);
    $token = csrfToken($page['body']);

    return http('POST', '/login', ['_token' => $token, 'login' => $login, 'password' => $secret], [], $jar);
}

function followSession(string $jar): array
{
    return http('GET', '/dashboard', null, [], $jar);
}

function flashOn(string $jar, string $path = '/dashboard'): string
{
    $response = http('GET', $path, null, [], $jar);

    return (string) $response['body'];
}

function permissionId(string $slug): int
{
    $row = db()->prepare('SELECT id FROM permissions WHERE permission_slug = :slug');
    $row->execute(['slug' => $slug]);
    $id = $row->fetchColumn();

    return $id === false ? 0 : (int) $id;
}

function roleId(string $slug): int
{
    $row = db()->prepare('SELECT id FROM roles WHERE role_slug = :slug');
    $row->execute(['slug' => $slug]);
    $id = $row->fetchColumn();

    return $id === false ? 0 : (int) $id;
}

function grantPermissions(int $roleRowId, array $permissionIds): void
{
    $delete = db()->prepare('DELETE FROM role_permissions WHERE role_id = :role');
    $delete->execute(['role' => $roleRowId]);

    $insert = db()->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)');
    foreach ($permissionIds as $permissionId) {
        $insert->execute(['role' => $roleRowId, 'permission' => $permissionId]);
    }
}

function countLogs(string $activity): int
{
    global $testStartedAt;

    $statement = db()->prepare(
        'SELECT COUNT(*) FROM user_activity_logs WHERE activity = :activity AND created_at >= FROM_UNIXTIME(:ts)'
    );
    $statement->execute(['activity' => $activity, 'ts' => $testStartedAt]);

    return (int) $statement->fetchColumn();
}

/**
 * Pulihkan kondisi awal: hapus sisa data uji dari perjalanan sebelumnya
 * dan aktifkan kembali peran bawaan yang mungkin sempat dinonaktifkan.
 */
function resetTestData(PDO $pdo): void
{
    $userIds = $pdo->query("SELECT id FROM users WHERE username LIKE 'rbac\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM user_activity_logs WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }

    $roleIds = $pdo->query("SELECT id FROM roles WHERE role_slug LIKE 'rbac\\_%' OR role_slug = 'koordinator_dapur_uji'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($roleIds as $roleRowId) {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :id')->execute(['id' => $roleRowId]);
        $pdo->prepare('DELETE FROM roles WHERE id = :id')->execute(['id' => $roleRowId]);
    }

    $pdo->exec('UPDATE roles SET is_active = 1');
    $pdo->exec("DELETE FROM login_attempts WHERE login LIKE 'rbac\\_%'");
    $pdo->exec("DELETE FROM user_activity_logs WHERE description LIKE '%rbac\\_%'");
}

function cleanup(): void
{
    global $tempFiles, $createdUserIds, $createdRoleIds, $createdLogins, $testStartedAt, $adminUser;

    if (getenv('KEEP_TEST_DATA') === '1') {
        return;
    }

    try {
        $pdo = db();

        foreach ($createdUserIds as $userId) {
            $pdo->prepare('DELETE FROM user_activity_logs WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }

        foreach ($createdRoleIds as $roleRowId) {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :id')->execute(['id' => $roleRowId]);
            $pdo->prepare('DELETE FROM roles WHERE id = :id')->execute(['id' => $roleRowId]);
        }

        $placeholders = implode(',', array_fill(0, max(1, count($createdLogins)), '?'));
        if ($createdLogins !== []) {
            $pdo->prepare('DELETE FROM login_attempts WHERE login IN (' . $placeholders . ')')
                ->execute($createdLogins);
        }

        // Log yang timbul selama pengujian, termasuk aktivitas milik akun admin.
        $pdo->prepare(
            "DELETE FROM user_activity_logs
             WHERE created_at >= FROM_UNIXTIME(:ts)
               AND (user_id IS NULL OR user_id IN (SELECT id FROM users WHERE username = :admin))"
        )->execute(['ts' => $testStartedAt, 'admin' => $adminUser]);

        $pdo->prepare('UPDATE users SET last_login = NULL, last_login_ip = NULL WHERE username = :u')
            ->execute(['u' => $adminUser]);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Pembersihan data uji terganggu: ' . $exception->getMessage() . "\n");
    }

    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

echo "Pengujian Autentikasi & RBAC SPPG Cangkring\n";
echo "Basis URL: {$baseUrl}\n";

try {
    $probe = http('GET', '/login');
    if ($probe['status'] === 0) {
        echo "\nServer tidak dapat dihubungi ({$probe['error']}).\n";
        echo "Mulai dulu: php -S 127.0.0.1:8099 -t public public/router.php\n";
        exit(1);
    }

    section('A. Struktur database');

    $pdo = db();
    resetTestData($pdo);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'users', 'roles', 'permissions', 'role_permissions', 'user_activity_logs', 'password_resets',
        'settings', 'login_attempts', 'migrations', 'projects', 'project_status_logs',
        'project_progress_reports', 'project_documents', 'project_team_assignments', 'notifications',
    ] as $table) {
        check("Tabel {$table} tersedia", in_array($table, $tables, true));
    }

    $columns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id', 'full_name', 'username', 'email', 'phone', 'photo', 'role_id', 'status', 'last_login', 'created_at', 'updated_at'] as $column) {
        check("Kolom users.{$column} tersedia", in_array($column, $columns, true));
    }

    $applied = $pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = array_map('basename', glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: []);
    sort($files);
    $missing = array_values(array_diff($files, $applied));
    check(
        'Seluruh migration tercatat',
        $missing === [],
        count($applied) . ' dari ' . count($files) . ' berkas'
            . ($missing === [] ? '' : '; belum jalan: ' . implode(', ', $missing))
    );

    $roleCount = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
    check('Sebelas role awal tersedia', $roleCount === 11, "{$roleCount} role");

    $permissionCount = (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
    check('Daftar izin awal tersedia', $permissionCount >= 33, "{$permissionCount} izin");

    $orphanPermissions = (int) $pdo->query('SELECT COUNT(*) FROM permissions WHERE module_name IS NULL OR module_name = \'\'')->fetchColumn();
    check('Setiap izin memiliki modul', $orphanPermissions === 0);

    $foreignKeys = $pdo->query(
        "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND REFERENCED_TABLE_NAME IS NOT NULL"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    check('FK users.role_id -> roles', in_array('roles', $foreignKeys, true));

    $uniqueIndex = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username' AND NON_UNIQUE = 0"
    )->fetchColumn();
    check('Username bersifat unik', (int) $uniqueIndex === 1);

    $badPasswords = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE password NOT LIKE '\$2y\$%' AND password NOT LIKE '\$argon2%'"
    )->fetchColumn();
    check('Kata sandi tersimpan sebagai hash', $badPasswords === 0, "{$badPasswords} akun tanpa hash");

    $allIds = (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
    $superGrants = (int) $pdo->query(
        'SELECT COUNT(*) FROM role_permissions rp INNER JOIN roles r ON r.id = rp.role_id WHERE r.role_slug = \'super_admin\''
    )->fetchColumn();
    check('Super Admin memegang seluruh izin', $superGrants === $allIds, "{$superGrants}/{$allIds}");

    $manageHolders = (int) $pdo->query(
        "SELECT COUNT(*) FROM role_permissions rp
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE p.permission_slug = 'permissions.manage'"
    )->fetchColumn();
    check('permissions.manage hanya dimiliki satu peran', $manageHolders === 1, "{$manageHolders} peran");

    $deleteHolders = (int) $pdo->query(
        "SELECT COUNT(*) FROM role_permissions rp
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE p.permission_slug = 'users.delete'"
    )->fetchColumn();
    check('users.delete hanya dimiliki Super Admin', $deleteHolders === 1, "{$deleteHolders} peran");

    section('B. Login, sesi, dan batas percobaan');

    $jarGuest = tempJar();
    $guestPengguna = http('GET', '/pengguna', null, [], $jarGuest);
    check('Tamu dialihkan dari /pengguna', $guestPengguna['status'] === 302 && str_contains(locationOf($guestPengguna), '/login'));

    $jarInactive = tempJar();
    $inactiveLogin = 'rbac_nonaktif';
    $inactiveRole = roleId('staf_keuangan');
    $create = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password, role_id, status, created_at, updated_at)
         VALUES ('Akun Nonaktif Uji', :u, :e, :p, :r, 'inactive', NOW(), NOW())"
    );
    $create->execute([
        'u' => $inactiveLogin,
        'e' => $inactiveLogin . '@example.test',
        'p' => password_hash($password, PASSWORD_DEFAULT),
        'r' => $inactiveRole,
    ]);
    $createdUserIds[] = (int) $pdo->lastInsertId();
    $createdLogins[] = $inactiveLogin;

    $inactiveResponse = loginAs($jarInactive, $inactiveLogin, $password);
    $inactivePage = http('GET', '/login', null, [], $jarInactive);
    // Nama akun uji sengaja memuat kata "nonaktif"; yang dilarang adalah pesan yang membocorkan status akun.
    check(
        'Akun nonaktif ditolak dengan pesan aman',
        $inactiveResponse['status'] === 302
        && str_contains($inactivePage['body'], 'Nama pengguna atau kata sandi tidak sesuai.')
        && !str_contains($inactivePage['body'], 'dinonaktifkan'),
        (string) $inactiveResponse['status']
    );
    check('Login gagal dicatat pada audit log', countLogs('auth.login.gagal') >= 1);

    $wrongLogin = 'rbac_salah';
    $jarWrong = tempJar();
    $wrongResponse = loginAs($jarWrong, $adminUser, 'kata-sandi-palsu-1');
    $wrongPage = http('GET', '/login', null, [], $jarWrong);
    check(
        'Kata sandi salah ditolak dengan pesan aman',
        $wrongResponse['status'] === 302 && str_contains($wrongPage['body'], 'Nama pengguna atau kata sandi tidak sesuai.')
    );

    $jarAdmin = tempJar();
    $preLoginPage = http('GET', '/login', null, [], $jarAdmin);
    $preLoginId = sessionIdFrom($jarAdmin);
    $adminResponse = loginAs($jarAdmin, $adminUser, $adminPass);
    $adminSessionId = sessionIdFrom($jarAdmin);
    check('Login Super Admin berhasil', $adminResponse['status'] === 302 && str_contains(locationOf($adminResponse), '/dashboard'));
    check('ID sesi berganti setelah login', $preLoginId !== '' && $adminSessionId !== '' && $preLoginId !== $adminSessionId);
    $adminCookie = (string) ($adminResponse['headers']['set-cookie'] ?? '');
    check('Cookie sesi HttpOnly', stripos($adminCookie, 'httponly') !== false);
    check('Cookie sesi SameSite=Lax', stripos($adminCookie, 'samesite=lax') !== false);
    check('Login sukses dicatat pada audit log', countLogs('auth.login') >= 1);

    $dashboard = followSession($jarAdmin);
    check('Super Admin dapat membuka /dashboard', $dashboard['status'] === 200, (string) $dashboard['status']);

    $missingPage = http('GET', '/pengguna/999999', null, [], $jarAdmin);
    check('ID pengguna tidak dikenal menghasilkan 404', $missingPage['status'] === 404, (string) $missingPage['status']);

    $badId = http('GET', '/pengguna/abc', null, [], $jarAdmin);
    check('Parameter bukan angka ditolak 404', $badId['status'] === 404, (string) $badId['status']);

    // Kedaluwarsa sesi: mundurkan last_activity pada berkas sesi server.
    $savePath = (string) ini_get('session.save_path');
    $sessionFile = rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $adminSessionId;
    if (is_file($sessionFile)) {
        $raw = (string) file_get_contents($sessionFile);
        $aged = preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 10800) . ';', $raw) ?? $raw;
        file_put_contents($sessionFile, $aged);

        $expired = http('GET', '/pengguna', null, [], $jarAdmin);
        $expiredPage = http('GET', '/login', null, [], $jarAdmin);
        check(
            'Sesi kedaluwarsa karena menganggur',
            $expired['status'] === 302 && str_contains($expiredPage['body'], 'Sesi Anda telah berakhir.')
        );
    } else {
        check('Sesi kedaluwarsa karena menganggur', false, "berkas sesi tidak ditemukan di {$savePath}");
    }

    $adminResponse = loginAs($jarAdmin, $adminUser, $adminPass);
    check('Login ulang setelah sesi berakhir berhasil', $adminResponse['status'] === 302);

    $throttleLogin = 'rbac_throttle';
    $jarThrottle = tempJar();
    clearAttempts($throttleLogin);
    $createdLogins[] = $throttleLogin;
    $form = http('GET', '/login', null, [], $jarThrottle);
    $token = csrfToken($form['body']);
    for ($i = 0; $i < 5; $i++) {
        http('POST', '/login', ['_token' => $token, 'login' => $throttleLogin, 'password' => 'salah-' . $i], [], $jarThrottle);
    }
    $blocked = http('POST', '/login', ['_token' => $token, 'login' => $throttleLogin, 'password' => $password], [], $jarThrottle);
    $blockedPage = http('GET', '/login', null, [], $jarThrottle);
    check(
        'Percobaan masuk dibatasi setelah lima kegagalan',
        $blocked['status'] === 302 && str_contains($blockedPage['body'], 'Terlalu banyak percobaan masuk yang gagal.')
    );
    clearAttempts($throttleLogin);

    section('C. Pemeriksaan izin di sisi server');

    $staffLogin = 'rbac_staff';
    $staffRole = roleId('staf_keuangan');
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password, role_id, status, created_at, updated_at)
         VALUES ('Staf Keuangan Uji', :u, :e, :p, :r, 'active', NOW(), NOW())"
    );
    $insert->execute([
        'u' => $staffLogin,
        'e' => $staffLogin . '@example.test',
        'p' => password_hash($password, PASSWORD_DEFAULT),
        'r' => $staffRole,
    ]);
    $staffId = (int) $pdo->lastInsertId();
    $createdUserIds[] = $staffId;
    $createdLogins[] = $staffLogin;

    $jarStaff = tempJar();
    check('Login staf keuangan berhasil', loginAs($jarStaff, $staffLogin, $password)['status'] === 302);
    check('Staf dapat membuka /dashboard', followSession($jarStaff)['status'] === 200);
    check('Staf dapat membuka /profil', http('GET', '/profil', null, [], $jarStaff)['status'] === 200);
    check('Staf ditolak 403 pada /pengguna', http('GET', '/pengguna', null, [], $jarStaff)['status'] === 403);
    check('Staf ditolak 403 pada /pengaturan', http('GET', '/pengaturan', null, [], $jarStaff)['status'] === 403);
    check('Staf ditolak 403 pada /hak-akses', http('GET', '/hak-akses', null, [], $jarStaff)['status'] === 403);
    check('Staf ditolak 403 pada /aktivitas', http('GET', '/aktivitas', null, [], $jarStaff)['status'] === 403);

    $staffDashboard = followSession($jarStaff);
    $staffToken = csrfToken($staffDashboard['body']);
    $directPost = http(
        'POST',
        '/pengguna/' . $staffId . '/status',
        ['_token' => $staffToken, 'status' => 'inactive'],
        [],
        $jarStaff
    );
    check('POST langsung ke tindakan terlarang ditolak 403', $directPost['status'] === 403, (string) $directPost['status']);

    $staffRoleStatus = $pdo->prepare('SELECT status FROM users WHERE id = :id');
    $staffRoleStatus->execute(['id' => $staffId]);
    check('Percobaan POST terlarang tidak mengubah data', (string) $staffRoleStatus->fetchColumn() === 'active');

    check(
        'Menu administrasi tidak dirender untuk staf',
        !str_contains($staffDashboard['body'], '/pengaturan"') && !str_contains($staffDashboard['body'], '/hak-akses"')
    );

    $adminRole = roleId('admin');
    $userAdminLogin = 'rbac_usadmin';
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password, role_id, status, created_at, updated_at)
         VALUES ('Admin Tata Usaha Uji', :u, :e, :p, :r, 'active', NOW(), NOW())"
    );
    $insert->execute([
        'u' => $userAdminLogin,
        'e' => $userAdminLogin . '@example.test',
        'p' => password_hash($password, PASSWORD_DEFAULT),
        'r' => $adminRole,
    ]);
    $userAdminId = (int) $pdo->lastInsertId();
    $createdUserIds[] = $userAdminId;
    $createdLogins[] = $userAdminLogin;

    $jarUserAdmin = tempJar();
    loginAs($jarUserAdmin, $userAdminLogin, $password);
    $adminSuperId = (int) $pdo->query("SELECT id FROM users WHERE username = {$pdo->quote($adminUser)}")->fetchColumn();
    check('Peran admin dapat membuka /pengguna', http('GET', '/pengguna', null, [], $jarUserAdmin)['status'] === 200);
    check('Peran admin dapat membuka /pengaturan', http('GET', '/pengaturan', null, [], $jarUserAdmin)['status'] === 200);
    check('Peran admin ditolak pada /hak-akses', http('GET', '/hak-akses', null, [], $jarUserAdmin)['status'] === 403);
    check(
        'Peran admin tidak dapat menyunting akun Super Admin',
        http('GET', '/pengguna/' . $adminSuperId, null, [], $jarUserAdmin)['status'] === 403
    );
    check(
        'Peran admin tidak dapat menghapus akun',
        http('POST', '/pengguna/' . $staffId . '/hapus', ['_token' => csrfToken(followSession($jarUserAdmin)['body'])], [], $jarUserAdmin)['status'] === 403
    );

    section('D. CSRF, validasi, danescaping keluaran');

    $noToken = http('POST', '/pengguna/tambah', ['full_name' => 'Tanpa Token'], [], $jarAdmin);
    check('POST tanpa token CSRF ditolak 400', $noToken['status'] === 400, (string) $noToken['status']);

    $wrongToken = http(
        'POST',
        '/pengguna/tambah',
        ['_token' => 'token-palsu', 'full_name' => 'Token Palsu'],
        [],
        $jarAdmin
    );
    check('Token CSRF yang salah ditolak 400', $wrongToken['status'] === 400, (string) $wrongToken['status']);

    $listPage = http('GET', '/pengguna', null, [], $jarAdmin);
    $headerToken = csrfToken($listPage['body']);
    $headerResponse = http(
        'POST',
        '/pengguna/' . $staffId . '/status',
        ['status' => 'inactive'],
        ['X-CSRF-TOKEN: ' . $headerToken],
        $jarAdmin
    );
    $staffState = $pdo->prepare('SELECT status FROM users WHERE id = :id');
    $staffState->execute(['id' => $staffId]);
    check(
        'Token melalui header X-CSRF-TOKEN diterima',
        $headerResponse['status'] === 302 && (string) $staffState->fetchColumn() === 'inactive'
    );

    $staffState->execute(['id' => $staffId]);
    $enableToken = csrfToken(http('GET', '/pengguna', null, [], $jarAdmin)['body']);
    http('POST', '/pengguna/' . $staffId . '/status', ['_token' => $enableToken, 'status' => 'active'], [], $jarAdmin);

    $createToken = csrfToken(http('GET', '/pengguna/tambah', null, [], $jarAdmin)['body']);
    $duplicate = http('POST', '/pengguna/tambah', [
        '_token' => $createToken,
        'full_name' => 'Duplikat Username',
        'username' => $staffLogin,
        'email' => 'duplikat-' . $staffLogin . '@example.test',
        'phone' => '',
        'role_id' => (string) $staffRole,
        'status' => 'active',
        'password' => $password,
        'password_confirmation' => $password,
    ], [], $jarAdmin);
    $duplicatePage = http('GET', '/pengguna/tambah', null, [], $jarAdmin);
    check('Username ganda ditolak', str_contains($duplicatePage['body'], 'Nama pengguna sudah dipakai akun lain.'), (string) $duplicate['status']);

    $emailToken = csrfToken($duplicatePage['body']);
    http('POST', '/pengguna/tambah', [
        '_token' => $emailToken,
        'full_name' => 'Duplikat Surel',
        'username' => 'rbac_dupmail',
        'email' => $staffLogin . '@example.test',
        'phone' => '',
        'role_id' => (string) $staffRole,
        'status' => 'active',
        'password' => $password,
        'password_confirmation' => $password,
    ], [], $jarAdmin);
    $emailPage = http('GET', '/pengguna/tambah', null, [], $jarAdmin);
    check('Surel ganda ditolak', str_contains($emailPage['body'], 'Alamat surel sudah dipakai akun lain.'));

    $weakToken = csrfToken($emailPage['body']);
    http('POST', '/pengguna/tambah', [
        '_token' => $weakToken,
        'full_name' => 'Kata Sandi Lemah',
        'username' => 'rbac_lemah',
        'email' => 'rbac_lemah@example.test',
        'phone' => '',
        'role_id' => (string) $staffRole,
        'status' => 'active',
        'password' => 'abcdefgh',
        'password_confirmation' => 'abcdefgh',
    ], [], $jarAdmin);
    $weakPage = http('GET', '/pengguna/tambah', null, [], $jarAdmin);
    check('Kata sandi tanpa angka ditolak', str_contains($weakPage['body'], 'harus memuat minimal satu angka.'));

    $phoneToken = csrfToken($weakPage['body']);
    http('POST', '/pengguna/tambah', [
        '_token' => $phoneToken,
        'full_name' => 'Telepon Salah',
        'username' => 'rbac_telp',
        'email' => 'rbac_telp@example.test',
        'phone' => 'abc-def',
        'role_id' => (string) $staffRole,
        'status' => 'active',
        'password' => $password,
        'password_confirmation' => $password,
    ], [], $jarAdmin);
    $phonePage = http('GET', '/pengguna/tambah', null, [], $jarAdmin);
    check('Format telepon tidak wajar ditolak', str_contains($phonePage['body'], 'Format nomor telepon tidak wajar'));

    $roleToken = csrfToken($phonePage['body']);
    http('POST', '/pengguna/tambah', [
        '_token' => $roleToken,
        'full_name' => 'Peran Salah',
        'username' => 'rbac_peran',
        'email' => 'rbac_peran@example.test',
        'phone' => '',
        'role_id' => '99999',
        'status' => 'active',
        'password' => $password,
        'password_confirmation' => $password,
    ], [], $jarAdmin);
    $rolePage = http('GET', '/pengguna/tambah', null, [], $jarAdmin);
    check('Peran yang tidak terdaftar ditolak', str_contains($rolePage['body'], 'Peran yang dipilih tidak terdaftar'));

    $injection = http('GET', '/pengguna?q=' . urlencode("' OR 1=1 --"), null, [], $jarAdmin);
    check(
        'Percobaan SQL injection pada pencarian aman',
        $injection['status'] === 200 && str_contains($injection['body'], 'Tidak ada pengguna yang cocok')
    );

    $xssName = '<script>alert(1)</script>';
    $xssToken = csrfToken(http('GET', '/pengguna/tambah', null, [], $jarAdmin)['body']);
    http('POST', '/pengguna/tambah', [
        '_token' => $xssToken,
        'full_name' => $xssName,
        'username' => 'rbac_xss',
        'email' => 'rbac_xss@example.test',
        'phone' => '081234567890',
        'role_id' => (string) $staffRole,
        'status' => 'active',
        'password' => $password,
        'password_confirmation' => $password,
    ], [], $jarAdmin);
    $xssList = http('GET', '/pengguna?q=rbac_xss', null, [], $jarAdmin);
    $xssUserId = (int) $pdo->query('SELECT id FROM users WHERE username = \'rbac_xss\'')->fetchColumn();
    if ($xssUserId > 0) {
        $createdUserIds[] = $xssUserId;
    }
    check(
        'Nama pengguna di-escape (tanpa skrip berjalan)',
        $xssUserId > 0
        && str_contains($xssList['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
        && !str_contains($xssList['body'], '<script>alert(1)</script>')
    );

    $xssToken = csrfToken($xssList['body']);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $xssUserId]);

    section('E. Siklus hidup akun pengguna');

    $newLogin = 'rbac_baru';
    $formToken = csrfToken(http('GET', '/pengguna/tambah', null, [], $jarAdmin)['body']);
    $created = http('POST', '/pengguna/tambah', [
        '_token' => $formToken,
        'full_name' => 'Pengguna Baru Uji',
        'username' => $newLogin,
        'email' => $newLogin . '@example.test',
        'phone' => '+62 812-3456-7890',
        'role_id' => (string) roleId('staf_gudang'),
        'status' => 'active',
        'password' => $password,
        'password_confirmation' => $password,
    ], [], $jarAdmin);
    $newUserId = (int) $pdo->query('SELECT id FROM users WHERE username = \'rbac_baru\'')->fetchColumn();
    if ($newUserId > 0) {
        $createdUserIds[] = $newUserId;
    }
    $createdLogins[] = $newLogin;

    check('Penambahan pengguna menghasilkan 302', $created['status'] === 302, (string) $created['status']);
    check('Baris pengguna baru tersimpan', $newUserId > 0);

    $storedPassword = (string) $pdo->query('SELECT password FROM users WHERE id = ' . $newUserId)->fetchColumn();
    check('Kata sandi baru tersimpan sebagai hash', str_starts_with($storedPassword, '$2y$') || str_starts_with($storedPassword, '$argon2'));
    check('Telepon dinormalisasi', (string) $pdo->query('SELECT phone FROM users WHERE id = ' . $newUserId)->fetchColumn() === '081234567890');
    check('Audit mencatat penambahan pengguna', countLogs('users.create') >= 1);

    $jarNew = tempJar();
    $newLoginResponse = loginAs($jarNew, $newLogin, $password);
    check('Pengguna baru dapat masuk', $newLoginResponse['status'] === 302, (string) $newLoginResponse['status']);
    check(
        'Pengguna baru diarahkan ke dashboard',
        str_contains(locationOf($newLoginResponse), '/dashboard'),
        locationOf($newLoginResponse)
    );

    $gudangRole = roleId('staf_gudang');
    check('Peran staf gudang hanya punya izin terkait inventaris', http('GET', '/pengguna', null, [], $jarNew)['status'] === 403);

    $editToken = csrfToken(http('GET', '/pengguna/' . $staffId, null, [], $jarAdmin)['body']);
    $keuanganRole = roleId('staf_keuangan');
    $giziRole = roleId('ahli_gizi');
    http('POST', '/pengguna/' . $staffId, [
        '_token' => $editToken,
        'full_name' => 'Staf Keuangan Uji',
        'username' => $staffLogin,
        'email' => $staffLogin . '@example.test',
        'phone' => '',
        'role_id' => (string) $giziRole,
        'status' => 'active',
    ], [], $jarAdmin);
    $staffRoleNow = (int) $pdo->query('SELECT role_id FROM users WHERE id = ' . $staffId)->fetchColumn();
    check('Perubahan peran tersimpan', $staffRoleNow === $giziRole, (string) $staffRoleNow);
    check('Audit mencatat perubahan peran', countLogs('users.role') >= 1);

    $staffAfterRoleChange = http('GET', '/pengguna', null, [], $jarStaff);
    check('Peran baru langsung berlaku pada sesi lama', $staffAfterRoleChange['status'] === 403, (string) $staffAfterRoleChange['status']);

    $pdo->prepare('UPDATE users SET role_id = :role WHERE id = :id')->execute(['role' => $keuanganRole, 'id' => $staffId]);

    $resetToken = csrfToken(http('GET', '/pengguna/' . $newUserId, null, [], $jarAdmin)['body']);
    $reset = http('POST', '/pengguna/' . $newUserId . '/kata-sandi', [
        '_token' => $resetToken,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ], [], $jarAdmin);
    $jarReset = tempJar();
    check(
        'Reset kata sandi oleh yang berwenang berhasil',
        $reset['status'] === 302 && loginAs($jarReset, $newLogin, $newPassword)['status'] === 302
    );
    $jarOld = tempJar();
    check('Kata sandi lama tidak berlaku lagi', loginAs($jarOld, $newLogin, $password)['status'] === 302
        && str_contains(http('GET', '/login', null, [], $jarOld)['body'], 'Nama pengguna atau kata sandi tidak sesuai.'));
    check('Audit mencatat penggantian kata sandi tanpa menyimpan nilainya', countLogs('users.password') >= 1);

    $leakCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM user_activity_logs WHERE description LIKE '%{$password}%' OR description LIKE '%{$newPassword}%' OR user_agent LIKE '%{$password}%'"
    )->fetchColumn();
    check('Kata sandi tidak pernah tercatat di log', $leakCount === 0, "{$leakCount} kebocoran");

    $selfDeactivateToken = csrfToken(http('GET', '/pengguna/' . $adminSuperId, null, [], $jarAdmin)['body']);
    $selfDeactivate = http('POST', '/pengguna/' . $adminSuperId . '/status', [
        '_token' => $selfDeactivateToken,
        'status' => 'inactive',
    ], [], $jarAdmin);
    $adminState = (string) $pdo->query('SELECT status FROM users WHERE id = ' . $adminSuperId)->fetchColumn();
    check(
        'Super Admin tidak dapat menonaktifkan akun sendiri',
        $adminState === 'active' && str_contains(flashOn($jarAdmin, '/pengguna/' . $adminSuperId), 'akun sendiri')
    );

    $selfDeleteToken = csrfToken(http('GET', '/pengguna/' . $adminSuperId, null, [], $jarAdmin)['body']);
    http('POST', '/pengguna/' . $adminSuperId . '/hapus', ['_token' => $selfDeleteToken], [], $jarAdmin);
    check(
        'Super Admin tidak dapat menghapus akun sendiri',
        (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . $adminSuperId)->fetchColumn() === 1
    );

    $deactivateToken = csrfToken(http('GET', '/pengguna', null, [], $jarAdmin)['body']);
    http('POST', '/pengguna/' . $newUserId . '/status', ['_token' => $deactivateToken, 'status' => 'inactive'], [], $jarAdmin);
    $jarDisabled = tempJar();
    check(
        'Akun yang dinonaktifkan langsung ditolak saat masuk',
        loginAs($jarDisabled, $newLogin, $newPassword)['status'] === 302
        && str_contains(http('GET', '/login', null, [], $jarDisabled)['body'], 'Nama pengguna atau kata sandi tidak sesuai.')
    );

    $enableToken = csrfToken(http('GET', '/pengguna', null, [], $jarAdmin)['body']);
    http('POST', '/pengguna/' . $newUserId . '/status', ['_token' => $enableToken, 'status' => 'active'], [], $jarAdmin);
    check('Akun dapat diaktifkan kembali', loginAs($jarDisabled, $newLogin, $newPassword)['status'] === 302);

    $logoutToken = csrfToken(followSession($jarDisabled)['body']);
    $logout = http('POST', '/logout', ['_token' => $logoutToken], [], $jarDisabled);
    check('Logout mengalihkan ke halaman masuk', $logout['status'] === 302 && str_contains(locationOf($logout), '/login'));
    check('Sesi berakhir setelah logout', http('GET', '/dashboard', null, [], $jarDisabled)['status'] === 302);

    $deleteToken = csrfToken(http('GET', '/pengguna', null, [], $jarAdmin)['body']);
    http('POST', '/pengguna/' . $newUserId . '/hapus', ['_token' => $deleteToken], [], $jarAdmin);
    check('Pengguna dapat dihapus oleh yang berwenang', (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . $newUserId)->fetchColumn() === 0);
    check('Audit mencatat penghapusan', countLogs('users.delete') >= 1);

    $paged = http('GET', '/pengguna?page=999&role=0&status=active', null, [], $jarAdmin);
    check('Parameter pagination diklamp', $paged['status'] === 200);

    section('F. Pengelolaan peran dan hak akses');

    $hakPage = http('GET', '/hak-akses', null, [], $jarAdmin);
    check('/hak-akses dapat dibuka Super Admin', $hakPage['status'] === 200, (string) $hakPage['status']);
    check('Halaman hak akses menampilkan matriks izin', str_contains($hakPage['body'], 'Matriks izin'));

    $roleToken = csrfToken($hakPage['body']);
    http('POST', '/hak-akses/peran', [
        '_token' => $roleToken,
        'role_name' => 'Koordinator Dapur Uji',
        'role_slug' => '',
        'description' => 'Peran hasil pengujian otomatis.',
    ], [], $jarAdmin);

    $ujiRoleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'Koordinator Dapur Uji'")->fetchColumn();
    if ($ujiRoleId > 0) {
        $createdRoleIds[] = $ujiRoleId;
    }
    check('Peran baru dibuat lewat antarmuka', $ujiRoleId > 0);

    $ujiRoleRow = $pdo->query('SELECT role_slug, is_active FROM roles WHERE id = ' . $ujiRoleId)->fetch();
    check('Slug peran dihasilkan otomatis', (string) $ujiRoleRow['role_slug'] === 'koordinator_dapur_uji', (string) $ujiRoleRow['role_slug']);
    check('Peran baru belum memiliki izin', (int) $pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . $ujiRoleId)->fetchColumn() === 0);

    $duplicateToken = csrfToken(http('GET', '/hak-akses', null, [], $jarAdmin)['body']);
    http('POST', '/hak-akses/peran', [
        '_token' => $duplicateToken,
        'role_name' => 'Koordinator Dapur Uji',
        'role_slug' => 'koordinator_dapur_uji',
        'description' => '',
    ], [], $jarAdmin);
    check(
        'Nama peran ganda ditolak',
        str_contains(flashOn($jarAdmin, '/hak-akses'), 'Nama peran sudah digunakan')
    );

    $viewUsersId = permissionId('users.view');
    $reportsViewId = permissionId('reports.view');
    $manageId = permissionId('permissions.manage');

    $matrixPage = http('GET', '/hak-akses?peran=' . $ujiRoleId, null, [], $jarAdmin);
    $grantToken = csrfToken($matrixPage['body']);
    $grant = http('POST', '/hak-akses/peran/' . $ujiRoleId . '/izin', [
        '_token' => $grantToken,
        'permissions' => [(string) $viewUsersId, (string) $reportsViewId],
    ], [], $jarAdmin);
    $grantedNow = $pdo->query('SELECT permission_id FROM role_permissions WHERE role_id = ' . $ujiRoleId)
        ->fetchAll(PDO::FETCH_COLUMN);
    sort($grantedNow);
    $expected = [$viewUsersId, $reportsViewId];
    sort($expected);
    check(
        'Penyimpanan matriks izin tersimpan persis',
        $grant['status'] === 302 && array_map('intval', $grantedNow) === $expected,
        implode(',', $grantedNow)
    );
    check('Audit mencatat perubahan hak akses', countLogs('roles.permissions') >= 1);

    $lockedToken = csrfToken(http('GET', '/hak-akses?peran=' . $ujiRoleId, null, [], $jarAdmin)['body']);
    $locked = http('POST', '/hak-akses/peran/' . $ujiRoleId . '/izin', [
        '_token' => $lockedToken,
        'permissions' => [(string) $viewUsersId, (string) $manageId],
    ], [], $jarAdmin);
    $manageLeaked = (int) $pdo->query(
        'SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . $ujiRoleId . ' AND permission_id = ' . $manageId
    )->fetchColumn();
    check(
        'Percobaan memberi permissions.manage ke peran biasa ditolak',
        $locked['status'] === 403 && $manageLeaked === 0,
        (string) $locked['status']
    );

    $ujiLogin = 'rbac_ujiperan';
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password, role_id, status, created_at, updated_at)
         VALUES ('Pengguna Peran Uji', :u, :e, :p, :r, 'active', NOW(), NOW())"
    );
    $insert->execute([
        'u' => $ujiLogin,
        'e' => $ujiLogin . '@example.test',
        'p' => password_hash($password, PASSWORD_DEFAULT),
        'r' => $ujiRoleId,
    ]);
    $ujiUserId = (int) $pdo->lastInsertId();
    $createdUserIds[] = $ujiUserId;
    $createdLogins[] = $ujiLogin;

    $jarUji = tempJar();
    loginAs($jarUji, $ujiLogin, $password);
    check(
        'Izin baru langsung berlaku tanpa ubah kode',
        http('GET', '/pengguna', null, [], $jarUji)['status'] === 200
    );

    $revokeToken = csrfToken(http('GET', '/hak-akses?peran=' . $ujiRoleId, null, [], $jarAdmin)['body']);
    http('POST', '/hak-akses/peran/' . $ujiRoleId . '/izin', ['_token' => $revokeToken, 'permissions' => []], [], $jarAdmin);
    check(
        'Pencabutan izin langsung berlaku pada permintaan berikutnya',
        http('GET', '/pengguna', null, [], $jarUji)['status'] === 403
    );

    $superToken = csrfToken(http('GET', '/hak-akses?peran=' . roleId('super_admin'), null, [], $jarAdmin)['body']);
    http('POST', '/hak-akses/peran/' . roleId('super_admin') . '/izin', [
        '_token' => $superToken,
        'permissions' => [],
    ], [], $jarAdmin);
    check(
        'Super Admin selalu memegang seluruh izin',
        (int) $pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . roleId('super_admin'))->fetchColumn() === $permissionCount
    );

    // Peran hanya bisa diuji ditolak bila benar-benar masih dipakai satu akun aktif.
    $holderLogin = 'rbac_pemegang';
    $holder = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password, role_id, status, created_at, updated_at)
         VALUES ('Pemegang Peran Uji', :u, :e, :p, :r, 'active', NOW(), NOW())"
    );
    $holder->execute([
        'u' => $holderLogin,
        'e' => $holderLogin . '@example.test',
        'p' => password_hash($password, PASSWORD_DEFAULT),
        'r' => $gudangRole,
    ]);
    $holderId = (int) $pdo->lastInsertId();
    $createdUserIds[] = $holderId;
    $createdLogins[] = $holderLogin;

    $deactivateToken = csrfToken(http('GET', '/hak-akses?peran=' . $gudangRole, null, [], $jarAdmin)['body']);
    http('POST', '/hak-akses/peran/' . $gudangRole, [
        '_token' => $deactivateToken,
        'edit_role_name' => 'Staf Gudang',
        'edit_description' => 'Menjaga persediaan.',
    ], [], $jarAdmin);
    check(
        'Peran yang masih dipakai akun aktif tidak dapat dinonaktifkan',
        (int) $pdo->query('SELECT is_active FROM roles WHERE id = ' . $gudangRole)->fetchColumn() === 1
        && str_contains(flashOn($jarAdmin, '/hak-akses?peran=' . $gudangRole), 'masih dipakai akun aktif')
    );
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $holderId]);

    $superDeactivateToken = csrfToken(http('GET', '/hak-akses?peran=' . roleId('super_admin'), null, [], $jarAdmin)['body']);
    http('POST', '/hak-akses/peran/' . roleId('super_admin'), [
        '_token' => $superDeactivateToken,
        'edit_role_name' => 'Super Admin',
        'edit_description' => (string) $pdo->query('SELECT description FROM roles WHERE id = ' . roleId('super_admin'))->fetchColumn(),
    ], [], $jarAdmin);
    check(
        'Peran Super Admin tidak dapat dinonaktifkan',
        (int) $pdo->query('SELECT is_active FROM roles WHERE id = ' . roleId('super_admin'))->fetchColumn() === 1
    );

    $renameToken = csrfToken(http('GET', '/hak-akses?peran=' . $ujiRoleId, null, [], $jarAdmin)['body']);
    http('POST', '/hak-akses/peran/' . $ujiRoleId, [
        '_token' => $renameToken,
        'edit_role_name' => 'Koordinator Dapur Uji',
        'edit_description' => 'Deskripsi diperbarui oleh pengujian.',
        'edit_is_active' => '1',
    ], [], $jarAdmin);
    check(
        'Deskripsi peran dapat diperbarui',
        str_contains(
            (string) $pdo->query('SELECT description FROM roles WHERE id = ' . $ujiRoleId)->fetchColumn(),
            'diperbarui oleh pengujian'
        )
    );

    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $ujiUserId]);
    $deactivateEmptyToken = csrfToken(http('GET', '/hak-akses?peran=' . $ujiRoleId, null, [], $jarAdmin)['body']);
    http('POST', '/hak-akses/peran/' . $ujiRoleId, [
        '_token' => $deactivateEmptyToken,
        'edit_role_name' => 'Koordinator Dapur Uji',
        'edit_description' => 'Dinonaktifkan setelah uji.',
    ], [], $jarAdmin);
    check(
        'Peran tanpa pengguna dapat dinonaktifkan',
        (int) $pdo->query('SELECT is_active FROM roles WHERE id = ' . $ujiRoleId)->fetchColumn() === 0
    );

    $inactiveAssignToken = csrfToken(http('GET', '/pengguna/tambah', null, [], $jarAdmin)['body']);
    http('POST', '/pengguna/tambah', [
        '_token' => $inactiveAssignToken,
        'full_name' => 'Peran Nonaktif',
        'username' => 'rbac_perannonaktif',
        'email' => 'rbac_perannonaktif@example.test',
        'phone' => '',
        'role_id' => (string) $ujiRoleId,
        'status' => 'active',
        'password' => $password,
        'password_confirmation' => $password,
    ], [], $jarAdmin);
    check(
        'Peran nonaktif tidak dapat diberikan ke akun baru',
        str_contains(flashOn($jarAdmin, '/pengguna/tambah'), 'sedang dinonaktifkan')
    );

    section('G. Halaman audit dan sisa pemeriksaan');

    $activityPage = http('GET', '/aktivitas', null, [], $jarAdmin);
    check('/aktivitas dapat dibuka Super Admin', $activityPage['status'] === 200, (string) $activityPage['status']);
    check('Halaman audit memuat entri aktivitas', str_contains($activityPage['body'], 'auth.login'));

    $moduleFiltered = http('GET', '/aktivitas?module=users', null, [], $jarAdmin);
    check('Filter modul pada halaman audit berhasil', $moduleFiltered['status'] === 200
        && str_contains($moduleFiltered['body'], 'users.create'));

    $searchFiltered = http('GET', '/aktivitas?q=' . urlencode('peran'), null, [], $jarAdmin);
    check('Pencarian pada halaman audit berhasil', $searchFiltered['status'] === 200);

    $staffActivity = http('GET', '/aktivitas?module=roles', null, [], $jarStaff);
    check('Halaman audit tertutup bagi peran tanpa audit.view', $staffActivity['status'] === 403);

    $tokenLeak = (int) $pdo->query(
        "SELECT COUNT(*) FROM user_activity_logs WHERE description LIKE '%_token%' OR description LIKE '%remember%'"
    )->fetchColumn();
    check('Token rahasia tidak tercatat pada log', $tokenLeak === 0, "{$tokenLeak} entri");

    check(
        'Minimal satu Super Admin aktif tetap tersisa',
        (int) $pdo->query(
            "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE r.role_slug = 'super_admin' AND u.status = 'active'"
        )->fetchColumn() >= 1
    );

    $intended = tempJar();
    $blocked = http('GET', '/hak-akses', null, [], $intended);
    check('Tamu disimpan ke url.intended lalu dialihkan', $blocked['status'] === 302 && str_contains(locationOf($blocked), '/login'));
    $intentLogin = loginAs($intended, $adminUser, $adminPass);
    check(
        'Tujuan awal dituju setelah login',
        $intentLogin['status'] === 302 && str_contains(locationOf($intentLogin), '/hak-akses')
    );

    echo "\nRingkasan: {$passed} lulus, {$failed} gagal (" . ($passed + $failed) . " uji)\n";
} finally {
    cleanup();
}

exit($failed === 0 ? 0 : 1);
