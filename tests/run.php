<?php
declare(strict_types=1);

/**
 * Uji fondasi SPPG Cangkring.
 *
 * Jalankan server lokal lebih dulu:
 *   php -S 127.0.0.1:8099 -t public public/router.php
 * lalu:
 *   php tests/run.php
 *
 * Variabel opsional: TEST_BASE_URL, ADMIN_USERNAME, ADMIN_PASSWORD.
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

$passed = 0;
$failed = 0;
$tempFiles = [];
$viewerId = null;
$viewerLogin = 'uji_otomatis';
$wrongLogin = 'uji_salah_sandi';
$throttleLogin = 'uji_throttle';

/** @param array<string, mixed>|null $data */
function http(
    string $method,
    string $path,
    ?array $data = null,
    array $headers = [],
    ?string $jar = null,
    bool $follow = false
): array {
    global $baseUrl;

    $ch = curl_init($baseUrl . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => 15,
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

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $headerLines = preg_split("/\r\n|\n|\r/", trim($rawHeaders)) ?: [];
    $parsedHeaders = [];
    foreach ($headerLines as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $parsedHeaders[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'headers' => $parsedHeaders,
        'error' => $error,
    ];
}

function csrfToken(string $html): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $match) === 1) {
        return $match[1];
    }

    return '';
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

    $file = sys_get_temp_dir() . '/sppg-test-' . bin2hex(random_bytes(6)) . '.txt';
    $tempFiles[] = $file;

    return $file;
}

function cleanup(): void
{
    global $tempFiles, $viewerId, $viewerLogin, $wrongLogin, $throttleLogin;

    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    try {
        $db = Database::connection();
        foreach ([$viewerLogin, $wrongLogin, $throttleLogin] as $login) {
            $db->prepare('DELETE FROM login_attempts WHERE login = :login')->execute(['login' => $login]);
        }
        if ($viewerId !== null) {
            $db->prepare('DELETE FROM user_activity_logs WHERE user_id = :id')->execute(['id' => $viewerId]);
            $db->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $viewerId]);
            $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $viewerId]);
        }
    } catch (Throwable) {
        // Pembersihan tidak boleh menutupi hasil uji.
    }
}

echo "Uji Fondasi SPPG Cangkring\n";
echo "Basis URL: {$baseUrl}\n";

try {
    $probe = http('GET', '/login');
    if ($probe['status'] === 0) {
        echo "\nServer tidak dapat dihubungi ({$probe['error']}).\n";
        echo "Mulai dulu: php -S 127.0.0.1:8099 -t public public/router.php\n";
        exit(1);
    }

    section('Koneksi database');

    $db = null;
    try {
        $db = Database::connection();
        check('Koneksi PDO ke MySQL/MariaDB', true, (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION));
    } catch (Throwable $e) {
        check('Koneksi PDO ke MySQL/MariaDB', false, $e->getMessage());
    }

    if ($db !== null) {
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'users', 'roles', 'permissions', 'role_permissions', 'user_activity_logs', 'password_resets',
            'settings', 'login_attempts', 'migrations', 'projects', 'project_status_logs',
            'project_progress_reports', 'project_documents', 'project_team_assignments', 'notifications',
        ] as $table) {
            check("Tabel {$table} tersedia", in_array($table, $tables, true));
        }

        $applied = $db->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = array_map('basename', glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: []);
        sort($files);
        $missing = array_values(array_diff($files, $applied));
        check(
            'Migration terpasang lengkap',
            $missing === [],
            count($applied) . ' dari ' . count($files) . ' berkas'
                . ($missing === [] ? '' : '; belum jalan: ' . implode(', ', $missing))
        );

        check(
            'Prepared statement native (emulasi nonaktif)',
            (int) $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0
        );

        $badHash = (int) $db->query(
            'SELECT COUNT(*) FROM users WHERE password NOT LIKE \'$2y$%\' AND password NOT LIKE \'$argon2%\''
        )->fetchColumn();
        check('Kata sandi tersimpan sebagai hash', $badHash === 0, "{$badHash} akun bermasalah");

        $adminCount = (int) $db->query(
            "SELECT COUNT(*) FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.role_slug = 'super_admin' AND u.status = 'active' AND r.is_active = 1"
        )->fetchColumn();
        check('Akun Super Admin aktif tersedia', $adminCount >= 1, "{$adminCount} akun");
    }

    section('Halaman publik dan keamanan');

    $jarGuest = tempJar();
    $loginPage = http('GET', '/login', null, [], $jarGuest);
    check('GET /login merespons 200', $loginPage['status'] === 200, (string) $loginPage['status']);
    check('Judul halaman masuk tampil', str_contains($loginPage['body'], 'Masuk'));
    check('Formulir memuat token CSRF', csrfToken($loginPage['body']) !== '');
    check('Header X-Frame-Options: DENY', ($loginPage['headers']['x-frame-options'] ?? '') === 'DENY');
    check('Header X-Content-Type-Options: nosniff', ($loginPage['headers']['x-content-type-options'] ?? '') === 'nosniff');
    check('Header Content-Security-Policy terkirim', isset($loginPage['headers']['content-security-policy']));

    $cookie = (string) ($loginPage['headers']['set-cookie'] ?? '');
    check('Cookie sesi HttpOnly', stripos($cookie, 'httponly') !== false);
    check('Cookie sesi SameSite=Lax', stripos($cookie, 'samesite=lax') !== false);

    $asset = http('GET', '/assets/css/app.css');
    check('Aset CSS tersedia', $asset['status'] === 200, (string) $asset['status']);
    check('Aset CSS bertipe text/css', str_starts_with((string) ($asset['headers']['content-type'] ?? ''), 'text/css'));

    $guestDashboard = http('GET', '/dashboard', null, [], $jarGuest);
    check('Tamu dialihkan dari /dashboard', $guestDashboard['status'] === 302
        && str_contains((string) ($guestDashboard['headers']['location'] ?? ''), '/login'), (string) $guestDashboard['status']);

    $noToken = http('POST', '/login', ['login' => 'x', 'password' => 'x'], [], $jarGuest);
    check('POST tanpa token CSRF ditolak 400', $noToken['status'] === 400, (string) $noToken['status']);

    section('Autentikasi');

    $jarAdmin = tempJar();
    $form = http('GET', '/login', null, [], $jarAdmin);
    $token = csrfToken($form['body']);

    $wrong = http('POST', '/login', ['_token' => $token, 'login' => $wrongLogin, 'password' => 'salah'], [], $jarAdmin);
    $wrongPage = http('GET', '/login', null, [], $jarAdmin);
    check('Kata sandi salah ditolak', $wrong['status'] === 302);
    check(
        'Pesan galat tidak membocorkan akun',
        str_contains($wrongPage['body'], 'Nama pengguna atau kata sandi tidak sesuai.')
    );

    $token = csrfToken($wrongPage['body']);
    $ok = http('POST', '/login', ['_token' => $token, 'login' => $adminUser, 'password' => $adminPass], [], $jarAdmin);
    check('Login admin berhasil', $ok['status'] === 302
        && str_contains((string) ($ok['headers']['location'] ?? ''), '/dashboard'), (string) $ok['status']);

    $dashboard = http('GET', '/dashboard', null, [], $jarAdmin);
    check('GET /dashboard terautentikasi 200', $dashboard['status'] === 200, (string) $dashboard['status']);
    check('Dashboard menandai modul belum tersedia', str_contains($dashboard['body'], 'Belum tersedia'));
    check('Dashboard tidak memuat data operasional fiktif', !str_contains($dashboard['body'], 'Target tercapai'));

    $profile = http('GET', '/profil', null, [], $jarAdmin);
    check('GET /profil 200', $profile['status'] === 200, (string) $profile['status']);

    $settings = http('GET', '/pengaturan', null, [], $jarAdmin);
    check('GET /pengaturan (admin) 200', $settings['status'] === 200, (string) $settings['status']);

    $token = csrfToken($profile['body']);
    $wrongCurrent = http('POST', '/profil/kata-sandi', [
        '_token' => $token,
        'current_password' => 'bukan-kata-sandi',
        'new_password' => 'Baru#12345',
        'new_password_confirmation' => 'Baru#12345',
    ], [], $jarAdmin);
    $profileAfter = http('GET', '/profil', null, [], $jarAdmin);
    check('Validasi kata sandi lama berjalan', $wrongCurrent['status'] === 302
        && str_contains($profileAfter['body'], 'Kata sandi saat ini tidak sesuai.'));

    $token = csrfToken($profileAfter['body']);
    $method = http('DELETE', '/dashboard', null, ['X-CSRF-TOKEN: ' . $token], $jarAdmin);
    check('Metode tidak diizinkan menghasilkan 405', $method['status'] === 405, (string) $method['status']);

    $jarViewer = tempJar();
    $viewerForm = http('GET', '/login', null, [], $jarViewer);
    $viewerToken = csrfToken($viewerForm['body']);

    $db = Database::connection();
    $limitedRoleId = (int) $db->query("SELECT id FROM roles WHERE role_slug = 'staf_keuangan'")->fetchColumn();
    $db->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $viewerLogin]);
    $db->prepare(
        'INSERT INTO users (username, full_name, email, password, role_id, status, created_at, updated_at)
         VALUES (:u, :n, :e, :p, :r, \'active\', NOW(), NOW())'
    )->execute([
        'u' => $viewerLogin,
        'n' => 'Akun Uji Otomatis',
        'e' => $viewerLogin . '@example.test',
        'p' => password_hash('Uji#Otomatis2026', PASSWORD_DEFAULT),
        'r' => $limitedRoleId,
    ]);
    $viewerId = (int) $db->lastInsertId();

    $viewerLoginResponse = http('POST', '/login', [
        '_token' => $viewerToken,
        'login' => $viewerLogin,
        'password' => 'Uji#Otomatis2026',
    ], [], $jarViewer);
    check('Login akun peran terbatas berhasil', $viewerLoginResponse['status'] === 302);

    $viewerDashboard = http('GET', '/dashboard', null, [], $jarViewer);
    check('Peran terbatas dapat membuka /dashboard', $viewerDashboard['status'] === 200, (string) $viewerDashboard['status']);
    check('Menu Pengaturan tidak ditampilkan untuk peran terbatas', !str_contains($viewerDashboard['body'], 'href="' . $baseUrl . '/pengaturan"'));
    check('Menu Pengguna tidak ditampilkan untuk peran terbatas', !str_contains($viewerDashboard['body'], 'href="' . $baseUrl . '/pengguna"'));

    $forbidden = http('GET', '/pengaturan', null, [], $jarViewer);
    check('Peran terbatas ditolak 403 di /pengaturan', $forbidden['status'] === 403, (string) $forbidden['status']);

    $viewerToken = csrfToken($viewerDashboard['body']);
    $logout = http('POST', '/logout', ['_token' => $viewerToken], [], $jarViewer);
    check('Logout mengalihkan 302', $logout['status'] === 302);
    $afterLogout = http('GET', '/dashboard', null, [], $jarViewer);
    check('Sesi berakhir setelah logout', $afterLogout['status'] === 302
        && str_contains((string) ($afterLogout['headers']['location'] ?? ''), '/login'));

    section('Pembatasan percobaan masuk');

    $jarThrottle = tempJar();
    $throttleForm = http('GET', '/login', null, [], $jarThrottle);
    $throttleToken = csrfToken($throttleForm['body']);
    for ($i = 0; $i < 5; $i++) {
        http('POST', '/login', [
            '_token' => $throttleToken,
            'login' => $throttleLogin,
            'password' => 'salah-' . $i,
        ], [], $jarThrottle);
    }
    $throttledResponse = http('POST', '/login', [
        '_token' => $throttleToken,
        'login' => $throttleLogin,
        'password' => 'salah-lagi',
    ], [], $jarThrottle);
    $throttlePage = http('GET', '/login', null, [], $jarThrottle);
    check('Percobaan masuk dibatasi setelah 5 kegagalan', str_contains(
        $throttlePage['body'],
        'Terlalu banyak percobaan masuk yang gagal.'
    ));

    section('Halaman galat');

    $missing = http('GET', '/halaman-tidak-ada', null, [], $jarAdmin);
    check('Halaman tidak dikenal 404', $missing['status'] === 404, (string) $missing['status']);
    check('Halaman 404 memakai tata letak aplikasi', str_contains($missing['body'], 'Halaman tidak ditemukan'));
} finally {
    cleanup();
}

echo "\nRingkasan: {$passed} lulus, {$failed} gagal (" . ($passed + $failed) . " uji)\n";
exit($failed === 0 ? 0 : 1);