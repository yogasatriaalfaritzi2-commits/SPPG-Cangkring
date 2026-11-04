<?php
declare(strict_types=1);

/**
 * Pengujian modul proyek pembangunan: dashboard, CRUD proyek, progres, dokumentasi,
 * tim, notifikasi internal, dan penegakan izin di sisi server.
 *
 * Jalankan server lokal lebih dulu:
 *   php -S 127.0.0.1:8099 -t public public/router.php
 * lalu:
 *   php tests/projects.php
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
// Tautan sidebar selalu memakai prefix URL aplikasi (mis. /SPPG-Cangkring/public pada
// XAMPP), jadi pencarian atribut href harus dibangun dari prefix yang sama supaya
// pemeriksaan "tersembunyi" tidak lulus karena bentuk URL-nya berbeda.
$pathPrefix = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');
$href = static fn (string $path): string => 'href="' . $pathPrefix . $path . '"';
$adminUser = (string) (getenv('ADMIN_USERNAME') ?: 'admin');
$adminPass = (string) (getenv('ADMIN_PASSWORD') ?: 'Admin#Sppg2026');

$password = 'Proyek#Uji2026';
$stamp = date('ymdHis');
$uploadDir = dirname(__DIR__) . '/storage/uploads/projects';

$pngBytes = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);
$pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

$passed = 0;
$failed = 0;
$tempFiles = [];
$createdUserIds = [];
$createdLogins = [];
$createdProjectIds = [];
$createdStored = [];
$testStartedAt = time();

function db(): PDO
{
    return Database::connection();
}

function parseHeaders(string $rawHeaders): array
{
    $parsed = [];
    foreach (preg_split("/\r\n|\n|\r/", trim($rawHeaders)) ?: [] as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $parsed[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
    }

    return $parsed;
}

/**
 * @param array<string, mixed>|null $data
 * @param array<int, string> $headers
 * @return array{status: int, body: string, headers: array<string, string>, error: string}
 */
function http(string $method, string $path, ?array $data = null, array $headers = [], ?string $jar = null): array
{
    global $baseUrl;

    $ch = curl_init($baseUrl . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30,
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

    return [
        'status' => $status,
        'body' => substr($raw, $headerSize),
        'headers' => parseHeaders(substr($raw, 0, $headerSize)),
        'error' => $error,
    ];
}

/**
 * Permintaan multipart untuk mengunggah berkas.
 *
 * @param array<string, string> $fields
 * @param array{name: string, type: string, path: string}|null $file
 * @return array{status: int, body: string, headers: array<string, string>, error: string}
 */
function upload(string $path, array $fields, ?array $file, string $jar): array
{
    global $baseUrl;

    $post = $fields;
    if ($file !== null) {
        $post['berkas'] = new CURLFile($file['path'], $file['type'], $file['name']);
    }

    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 90,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => substr($raw, $headerSize),
        'headers' => parseHeaders(substr($raw, 0, $headerSize)),
        'error' => $error,
    ];
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

    $file = sys_get_temp_dir() . '/sppg-proj-' . bin2hex(random_bytes(6)) . '.txt';
    $tempFiles[] = $file;

    return $file;
}

function locationOf(array $response): string
{
    return (string) ($response['headers']['location'] ?? '');
}

function clearAttempts(string $login): void
{
    db()->prepare('DELETE FROM login_attempts WHERE login = :login')->execute(['login' => $login]);
}

/** @return array<string, mixed> */
function loginAs(string $jar, string $login, string $secret): array
{
    clearAttempts($login);
    $page = http('GET', '/login', null, [], $jar);

    return http('POST', '/login', [
        '_token' => csrfToken($page['body']),
        'login' => $login,
        'password' => $secret,
    ], [], $jar);
}

function pageOn(string $jar, string $path = '/dashboard'): string
{
    return (string) http('GET', $path, null, [], $jar)['body'];
}

/** Token CSRF diambil dari halaman yang memuat formulir tujuan. */
function tokenFor(string $jar, string $path): string
{
    return csrfToken(pageOn($jar, $path));
}

function roleId(string $slug): int
{
    $statement = db()->prepare('SELECT id FROM roles WHERE role_slug = :slug');
    $statement->execute(['slug' => $slug]);

    return (int) ($statement->fetchColumn() ?: 0);
}

function userId(string $login): int
{
    $statement = db()->prepare('SELECT id FROM users WHERE username = :u');
    $statement->execute(['u' => $login]);

    return (int) ($statement->fetchColumn() ?: 0);
}

function makeUser(string $login, string $fullName, string $roleSlug): int
{
    global $password, $createdUserIds, $createdLogins;

    $statement = db()->prepare(
        "INSERT INTO users (full_name, username, email, password, role_id, status, created_at, updated_at)
         VALUES (:n, :u, :e, :p, :r, 'active', NOW(), NOW())"
    );
    $statement->execute([
        'n' => $fullName,
        'u' => $login,
        'e' => $login . '@example.test',
        'p' => password_hash($password, PASSWORD_DEFAULT),
        'r' => roleId($roleSlug),
    ]);

    $id = (int) db()->lastInsertId();
    $createdUserIds[] = $id;
    $createdLogins[] = $login;

    return $id;
}

function countLogs(string $activity): int
{
    global $testStartedAt;

    $statement = db()->prepare(
        'SELECT COUNT(*) FROM user_activity_logs
         WHERE activity = :activity AND created_at >= FROM_UNIXTIME(:ts)'
    );
    $statement->execute(['activity' => $activity, 'ts' => $testStartedAt]);

    return (int) $statement->fetchColumn();
}

function countNotifications(int $userId, string $type, int $projectId, string $dedupKey = ''): int
{
    if ($dedupKey === '') {
        $statement = db()->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE user_id = :user AND type = :type AND project_id = :project'
        );
        $statement->execute(['user' => $userId, 'type' => $type, 'project' => $projectId]);

        return (int) $statement->fetchColumn();
    }

    $statement = db()->prepare(
        'SELECT COUNT(*) FROM notifications
         WHERE user_id = :user AND type = :type AND project_id = :project AND dedup_key = :key'
    );
    $statement->execute([
        'user' => $userId,
        'type' => $type,
        'project' => $projectId,
        'key' => $dedupKey,
    ]);

    return (int) $statement->fetchColumn();
}

function unreadCount(int $userId): int
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user AND is_read = 0'
    );
    $statement->execute(['user' => $userId]);

    return (int) $statement->fetchColumn();
}

function countDocuments(int $projectId): int
{
    $statement = db()->prepare('SELECT COUNT(*) FROM project_documents WHERE project_id = :id');
    $statement->execute(['id' => $projectId]);

    return (int) $statement->fetchColumn();
}

function projectColumn(int $projectId, string $column): mixed
{
    if (!preg_match('/^[a-z_]+$/', $column)) {
        throw new RuntimeException('Nama kolom tidak dikenal.');
    }

    $statement = db()->prepare('SELECT ' . $column . ' FROM projects WHERE id = :id');
    $statement->execute(['id' => $projectId]);

    return $statement->fetchColumn();
}

/** @param array<string, mixed> $overrides */
function payload(array $overrides = []): array
{
    static $sequence = 0;
    $sequence++;

    global $stamp;

    return array_merge([
        'project_code' => 'UJI-' . $stamp . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'name' => 'Proyek Uji ' . $sequence,
        'description' => 'Deskripsi proyek uji otomatis.',
        'location' => 'Desa Cangkring',
        'address' => 'Jalan Uji Nomor 1',
        'latitude' => '-6.9000000',
        'longitude' => '109.7000000',
        'pic_name' => 'Penanggung Jawab Uji',
        'pic_user_id' => '',
        'contractor' => 'CV Uji Konstruksi',
        'start_date' => date('Y-m-d', strtotime('-10 day')),
        'target_date' => date('Y-m-d', strtotime('+20 day')),
        'budget_amount' => '250000000',
        'status' => 'planning',
        'status_reason' => '',
        'notes' => 'Catatan uji otomatis.',
    ], $overrides);
}

/**
 * Membuat proyek lewat jalur antarmuka yang sebenarnya.
 *
 * @param array<string, mixed> $overrides
 * @return array{id: int, code: string, status: int, location: string}
 */
function createProject(string $jar, array $overrides = []): array
{
    global $createdProjectIds;

    $data = payload($overrides);
    $response = http('POST', '/proyek/tambah', $data + ['_token' => tokenFor($jar, '/proyek/tambah')], [], $jar);

    $id = 0;
    if (preg_match('#/proyek/(\d+)$#', locationOf($response), $match) === 1) {
        $id = (int) $match[1];
    }
    if ($id > 0) {
        $createdProjectIds[] = $id;
    }

    return [
        'id' => $id,
        'code' => $id > 0 ? (string) projectColumn($id, 'project_code') : (string) $data['project_code'],
        'status' => $response['status'],
        'location' => locationOf($response),
    ];
}

/** @return array<string, mixed> */
function projectRow(int $id): array
{
    $statement = db()->prepare('SELECT * FROM projects WHERE id = :id');
    $statement->execute(['id' => $id]);

    /** @var array<string, mixed> $row */
    $row = $statement->fetch() ?: [];

    return $row;
}

function fixture(string $name, string $bytes): string
{
    global $tempFiles;

    $path = sys_get_temp_dir() . '/sppg-fix-' . bin2hex(random_bytes(4)) . '-' . $name;
    file_put_contents($path, $bytes);
    $tempFiles[] = $path;

    return $path;
}

function forgetStoredFiles(array $names): void
{
    global $uploadDir;

    foreach ($names as $name) {
        $path = $uploadDir . '/' . $name;
        if (is_string($name) && $name !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

/** Membersihkan sisa data uji dari perjalanan sebelumnya sebelum suite dimulai. */
function resetModuleData(): void
{
    $pdo = db();

    $projectIds = $pdo->query(
        "SELECT id FROM projects WHERE project_code LIKE 'UJI%' OR name LIKE 'Proyek Uji%'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($projectIds !== []) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stored = $pdo->prepare(
            'SELECT stored_name FROM project_documents WHERE project_id IN (' . $placeholders . ')'
        );
        $stored->execute($projectIds);
        forgetStoredFiles($stored->fetchAll(PDO::FETCH_COLUMN));

        $pdo->prepare('DELETE FROM projects WHERE id IN (' . $placeholders . ')')->execute($projectIds);
    }

    $userIds = $pdo->query("SELECT id FROM users WHERE username LIKE 'uji%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM user_activity_logs WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }

    $pdo->exec("DELETE FROM login_attempts WHERE login LIKE 'uji%'");
}

function cleanup(): void
{
    global $tempFiles, $createdUserIds, $createdLogins, $createdProjectIds, $createdStored, $adminUser, $testStartedAt;

    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    forgetStoredFiles($createdStored);

    if (getenv('KEEP_TEST_DATA') === '1') {
        return;
    }

    try {
        $pdo = db();

        if ($createdProjectIds !== []) {
            $placeholders = implode(',', array_fill(0, count($createdProjectIds), '?'));
            $stored = $pdo->prepare(
                'SELECT stored_name FROM project_documents WHERE project_id IN (' . $placeholders . ')'
            );
            $stored->execute($createdProjectIds);
            forgetStoredFiles($stored->fetchAll(PDO::FETCH_COLUMN));

            $pdo->prepare('DELETE FROM projects WHERE id IN (' . $placeholders . ')')->execute($createdProjectIds);
        }

        foreach ($createdUserIds as $userId) {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM user_activity_logs WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }

        if ($createdLogins !== []) {
            $placeholders = implode(',', array_fill(0, count($createdLogins), '?'));
            $pdo->prepare('DELETE FROM login_attempts WHERE login IN (' . $placeholders . ')')
                ->execute($createdLogins);
        }

        // Sisa log yang timbul selama pengujian, termasuk aktivitas akun admin.
        $pdo->prepare(
            'DELETE FROM user_activity_logs
             WHERE created_at >= FROM_UNIXTIME(:ts)
               AND (user_id IS NULL OR user_id IN (SELECT id FROM users WHERE username = :admin))'
        )->execute(['ts' => $testStartedAt, 'admin' => $adminUser]);

        $pdo->prepare('UPDATE users SET last_login = NULL, last_login_ip = NULL WHERE username = :u')
            ->execute(['u' => $adminUser]);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Pembersihan data uji terganggu: ' . $exception->getMessage() . "\n");
    }
}

echo "Pengujian Modul Proyek Pembangunan SPPG Cangkring\n";
echo "Basis URL: {$baseUrl}\n";

try {
    $probe = http('GET', '/login');
    if ($probe['status'] === 0) {
        echo "\nServer tidak dapat dihubungi ({$probe['error']}).\n";
        echo "Mulai dulu: php -S 127.0.0.1:8099 -t public public/router.php\n";
        exit(1);
    }

    resetModuleData();

    $adminId = userId($adminUser);

    $pengawasId = makeUser('uji_pengawas', 'Pengawas Uji', 'pengawas_pembangunan');
    $manajerId = makeUser('uji_manajer', 'Manajer Uji', 'manajer_koordinator');
    $keuanganId = makeUser('uji_keuangan', 'Keuangan Uji', 'staf_keuangan');
    $kepalaId = makeUser('uji_kepala', 'Kepala Uji', 'kepala_sppg');
    $gudangId = makeUser('uji_gudang', 'Gudang Uji', 'staf_gudang');

    $jarPengawas = tempJar();
    $jarManajer = tempJar();
    $jarKeuangan = tempJar();
    $jarKepala = tempJar();
    $jarGudang = tempJar();
    $jarAdmin = tempJar();

    foreach ([
        [$jarPengawas, 'uji_pengawas'],
        [$jarManajer, 'uji_manajer'],
        [$jarKeuangan, 'uji_keuangan'],
        [$jarKepala, 'uji_kepala'],
        [$jarGudang, 'uji_gudang'],
        [$jarAdmin, $adminUser],
    ] as [$jar, $login]) {
        loginAs($jar, $login, $login === $adminUser ? $adminPass : $password);
    }

    section('A. Skema modul proyek dan penegakan izin dasar');

    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'projects', 'project_status_logs', 'project_progress_reports',
        'project_documents', 'project_team_assignments', 'notifications',
    ] as $table) {
        check("Tabel {$table} tersedia", in_array($table, $tables, true));
    }

    $columns = db()->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'project_code', 'name', 'description', 'location', 'address', 'latitude', 'longitude',
        'pic_name', 'pic_user_id', 'contractor', 'start_date', 'target_date', 'budget_amount',
        'status', 'progress', 'notes', 'is_archived',
    ] as $column) {
        check("Kolom projects.{$column} tersedia", in_array($column, $columns, true));
    }

    $uniqueCode = (int) db()->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
           AND COLUMN_NAME = 'project_code' AND NON_UNIQUE = 0"
    )->fetchColumn();
    check('Kode proyek unik di tingkat database', $uniqueCode === 1);

    $checks = db()->query(
        "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'"
    )->fetchAll(PDO::FETCH_COLUMN);
    check('Batas progres 0-100 ditegak CHECK constraint', in_array('chk_projects_progress', $checks, true));
    check('Urutan tanggal ditegak CHECK constraint', in_array('chk_projects_dates', $checks, true));

    $childTables = db()->query(
        "SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'projects'"
    )->fetchAll(PDO::FETCH_COLUMN);
    check('Tabel anak merujuk projects lewat FK', count($childTables) >= 4, implode(',', $childTables));

    $modulePermissions = db()->query(
        "SELECT permission_slug FROM permissions
         WHERE permission_slug IN (
            'dashboard.view', 'projects.view', 'projects.create', 'projects.update', 'projects.delete',
            'projects.archive', 'projects.status.update', 'projects.progress.update',
            'projects.documents.view', 'projects.documents.upload', 'projects.documents.delete',
            'projects.team.manage', 'budgets.view'
         )"
    )->fetchAll(PDO::FETCH_COLUMN);
    check('Izin modul proyek terdaftar lengkap', count($modulePermissions) === 13, count($modulePermissions) . ' izin');

    $totalPermissions = (int) db()->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
    $superGrants = (int) db()->query(
        "SELECT COUNT(*) FROM role_permissions rp
         INNER JOIN roles r ON r.id = rp.role_id WHERE r.role_slug = 'super_admin'"
    )->fetchColumn();
    check('Super Admin memegang seluruh izin', $superGrants === $totalPermissions, "{$superGrants}/{$totalPermissions}");

    $guest = http('GET', '/proyek', null, [], tempJar());
    check('Tamu dialihkan dari /proyek', $guest['status'] === 302 && str_contains(locationOf($guest), '/login'));

    check('Gudang ditolak 403 pada daftar proyek', http('GET', '/proyek', null, [], $jarGudang)['status'] === 403);
    check('Gudang ditolak 403 pada form tambah', http('GET', '/proyek/tambah', null, [], $jarGudang)['status'] === 403);
    $gudangPost = http('POST', '/proyek/tambah', payload() + ['_token' => tokenFor($jarGudang, '/dashboard')], [], $jarGudang);
    check('Gudang ditolak 403 pada POST tambah', $gudangPost['status'] === 403, (string) $gudangPost['status']);
    check('Keuangan boleh melihat daftar proyek', http('GET', '/proyek', null, [], $jarKeuangan)['status'] === 200);
    check('Keuangan ditolak 403 pada form tambah', http('GET', '/proyek/tambah', null, [], $jarKeuangan)['status'] === 403);
    check('Pengawas boleh membuka form tambah', http('GET', '/proyek/tambah', null, [], $jarPengawas)['status'] === 200);

    check('Menu Proyek tersembunyi bagi gudang', !str_contains(pageOn($jarGudang), $href('/proyek')));
    check('Menu Proyek tampil bagi keuangan', str_contains(pageOn($jarKeuangan), $href('/proyek')));
    check('Menu Notifikasi tampil bagi semua peran', str_contains(pageOn($jarGudang), $href('/notifikasi')));

    section('B. CRUD proyek dan validasi sisi server');

    $created = createProject($jarPengawas, ['status' => 'running']);
    $projectId = $created['id'];
    check('Proyek baru tersimpan', $projectId > 0, $created['code']);
    check('Pembuat proyek tercatat', (int) projectColumn($projectId, 'created_by') === $pengawasId);
    check(
        'Status awal proyek dicatat pada riwayat',
        (int) db()->query(
            "SELECT COUNT(*) FROM project_status_logs WHERE project_id = {$projectId} AND to_status = 'running'"
        )->fetchColumn() === 1
    );
    check('Audit mencatat pembuatan proyek', countLogs('projects.create') >= 1);
    check('Detail proyek dapat dibuka', http('GET', '/proyek/' . $projectId, null, [], $jarPengawas)['status'] === 200);
    check(
        'Penerima notifikasi memperoleh kabar proyek baru',
        countNotifications($keuanganId, 'project.created', $projectId) === 1
    );

    $duplicate = createProject($jarPengawas, ['project_code' => $created['code']]);
    check(
        'Kode proyek ganda ditolak',
        $duplicate['id'] === 0 && str_contains(pageOn($jarPengawas, '/proyek/tambah'), 'Kode proyek sudah dipakai proyek lain.')
    );

    $auto = createProject($jarPengawas, ['project_code' => '', 'name' => 'Proyek Uji Kode Otomatis']);
    check('Kode otomatis dihasilkan saat kode dikosongkan', $auto['id'] > 0 && str_starts_with($auto['code'], 'PRK-'), $auto['code']);

    $blankEdit = http('POST', '/proyek/' . $projectId . '/ubah', payload([
        'project_code' => '',
        'name' => 'Proyek Uji Nama Ubah',
    ]) + ['_token' => tokenFor($jarPengawas, '/proyek/' . $projectId . '/ubah')], [], $jarPengawas);
    check(
        'Kode kosong saat penyuntingan ditolak',
        $blankEdit['status'] === 302 && str_contains(pageOn($jarPengawas, '/proyek/' . $projectId . '/ubah'), 'Kode proyek wajib diisi.')
    );

    $validations = [
        'format kode' => [['project_code' => 'kode 1!'], 'Kode proyek harus 3-30 karakter'],
        'urutan tanggal' => [
            ['start_date' => date('Y-m-d'), 'target_date' => date('Y-m-d', strtotime('-1 day'))],
            'Target selesai tidak boleh lebih awal dari tanggal mulai.',
        ],
        'tanggal tidak ada' => [['start_date' => '2026-02-30'], 'Tanggal mulai wajib diisi dengan format yang benar'],
        'status' => [['status' => 'dibuat'], 'Status proyek tidak dikenali.'],
        'anggaran' => [['budget_amount' => 'abc'], 'Anggaran harus berupa angka'],
        'lintang' => [['latitude' => '195'], 'Lintang harus berupa angka'],
        'bujur' => [['longitude' => 'abc'], 'Bujur harus berupa angka'],
        'nama pendek' => [['name' => 'ab'], 'Nama proyek harus terdiri dari 3 sampai 150 karakter.'],
        'penanggung jawab' => [['pic_name' => 'x'], 'Nama penanggung jawab harus terdiri dari 3 sampai 120 karakter.'],
        'akun penanggung jawab' => [['pic_user_id' => '999999'], 'Penanggung jawab harus akun aktif yang terdaftar.'],
    ];

    foreach ($validations as $label => [$overrides, $needle]) {
        $attempt = createProject($jarPengawas, $overrides);
        check("Validasi {$label} ditolak", $attempt['id'] === 0);
        check("Pesan validasi {$label} muncul", str_contains(pageOn($jarPengawas, '/proyek/tambah'), $needle));
    }

    $beforeProgress = (int) projectColumn($projectId, 'progress');
    $updated = http('POST', '/proyek/' . $projectId . '/ubah', payload([
        'project_code' => $created['code'],
        'name' => 'Proyek Uji Nama Diperbarui',
        'location' => 'Desa Cangkring Wetan',
        'budget_amount' => '300000000',
        'progress' => '99',
    ]) + ['_token' => tokenFor($jarPengawas, '/proyek/' . $projectId . '/ubah')], [], $jarPengawas);
    $row = projectRow($projectId);
    check(
        'Penyuntingan proyek tersimpan',
        $updated['status'] === 302 && (string) $row['name'] === 'Proyek Uji Nama Diperbarui'
        && (float) $row['budget_amount'] === 300000000.0
    );
    check('Progres tidak berubah lewat formulir proyek', (int) $row['progress'] === $beforeProgress, (string) $row['progress']);
    check('Audit mencatat penyuntingan proyek', countLogs('projects.update') >= 1);

    $archiveForbidden = http(
        'POST',
        '/proyek/' . $projectId . '/arsip',
        ['_token' => tokenFor($jarPengawas, '/proyek/' . $projectId), 'archive' => '1'],
        [],
        $jarPengawas
    );
    check('Pengawas tanpa projects.archive ditolak 403', $archiveForbidden['status'] === 403);
    check('Percobaan arsip terlarang tidak mengubah data', (int) projectColumn($projectId, 'is_archived') === 0);

    $archived = http(
        'POST',
        '/proyek/' . $projectId . '/arsip',
        ['_token' => tokenFor($jarManajer, '/proyek/' . $projectId), 'archive' => '1'],
        [],
        $jarManajer
    );
    $archivedRow = projectRow($projectId);
    check(
        'Manajer dapat mengarsipkan proyek',
        $archived['status'] === 302 && (int) $archivedRow['is_archived'] === 1 && $archivedRow['archived_at'] !== null
    );
    check('Audit mencatat pengarsipan', countLogs('projects.archive') >= 1);
    check(
        'Proyek terarsip hilang dari daftar biasa',
        str_contains(pageOn($jarManajer, '/proyek?q=' . urlencode($created['code'])), 'Belum ada proyek')
    );
    check(
        'Proyek terarsip tampil dengan filter arsip',
        !str_contains(pageOn($jarManajer, '/proyek?archived=1&q=' . urlencode($created['code'])), 'Belum ada proyek')
    );
    check(
        'Detail terarsip menampilkan peringatan',
        str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'Proyek ini diarsipkan pada')
    );

    $statusBeforeLock = (string) projectColumn($projectId, 'status');

    $lockedProgress = http('POST', '/proyek/' . $projectId . '/progres', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'progress' => '40',
        'report_date' => date('Y-m-d'),
        'work_description' => 'Percobaan progres pada proyek terarsip.',
        'responsible_name' => 'Manajer Uji',
    ], [], $jarManajer);
    check(
        'Progres proyek terarsip ditolak server',
        $lockedProgress['status'] === 302
        && str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'tidak dapat dilaporkan progresnya')
    );

    $lockedStatus = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'status' => 'completed',
        'reason' => '',
    ], [], $jarManajer);
    check(
        'Status proyek terarsip ditolak server',
        $lockedStatus['status'] === 302
        && str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'tidak dapat diubah statusnya')
        && (string) projectColumn($projectId, 'status') === $statusBeforeLock
    );

    $lockedDocument = upload(
        '/proyek/' . $projectId . '/dokumen',
        ['_token' => tokenFor($jarManajer, '/proyek/' . $projectId), 'category' => 'supporting', 'title' => 'Dokumen proyek terarsip'],
        ['name' => 'terarsip.pdf', 'type' => 'application/pdf', 'path' => fixture('terarsip.pdf', $GLOBALS['pdfBytes'])],
        $jarManajer
    );
    check(
        'Dokumen proyek terarsip ditolak server',
        $lockedDocument['status'] === 302
        && str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'tidak dapat ditambah dokumen baru')
    );

    $lockedTeam = http('POST', '/proyek/' . $projectId . '/tim', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'user_id' => (string) $kepalaId,
        'position' => 'Anggota uji',
        'duties' => '',
    ], [], $jarManajer);
    check(
        'Penugasan tim proyek terarsip ditolak server',
        $lockedTeam['status'] === 302 && str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'keanggotaan timnya')
    );

    $restore = http(
        'POST',
        '/proyek/' . $projectId . '/arsip',
        ['_token' => tokenFor($jarManajer, '/proyek/' . $projectId), 'archive' => '0'],
        [],
        $jarManajer
    );
    check(
        'Proyek dapat dipulihkan dari arsip',
        $restore['status'] === 302 && (int) projectColumn($projectId, 'is_archived') === 0
        && countLogs('projects.unarchive') >= 1
    );

    check('Filter status dan sorting berjalan', str_contains(pageOn($jarManajer, '/proyek?status=planning&sort=budget&dir=desc'), $created['code']));
    check(
        'Percobaan SQL injection pada pencarian aman',
        str_contains(pageOn($jarManajer, '/proyek?q=' . urlencode("' OR 1=1 --")), 'Belum ada proyek')
    );
    check('Pagination diklamp', http('GET', '/proyek?page=999', null, [], $jarManajer)['status'] === 200);
    check(
        'Rentang tanggal filter diterima',
        http('GET', '/proyek?from=' . date('Y-m-01') . '&to=' . date('Y-m-t'), null, [], $jarManajer)['status'] === 200
    );

    $xssProject = createProject($jarPengawas, ['name' => '<script>alert(1)</script>']);
    $xssPage = pageOn($jarPengawas, '/proyek?q=' . urlencode('alert(1)'));
    check(
        'Nama proyek di-escape dari XSS',
        $xssProject['id'] > 0
        && str_contains($xssPage, '&lt;script&gt;alert(1)&lt;/script&gt;')
        && !str_contains($xssPage, '<script>alert(1)</script>')
    );

    check('Parameter id bukan angka menghasilkan 404', http('GET', '/proyek/abc', null, [], $jarManajer)['status'] === 404);
    check('Id proyek tak dikenal menghasilkan 404', http('GET', '/proyek/999999', null, [], $jarManajer)['status'] === 404);
    $deleteRoute = http('POST', '/proyek/' . $projectId . '/hapus', ['_token' => tokenFor($jarAdmin, '/proyek/' . $projectId)], [], $jarAdmin);
    check('Rute hapus proyek memang tidak disediakan', $deleteRoute['status'] === 404, (string) $deleteRoute['status']);

    section('C. Monitoring progres');

    // Proyek konstruksi dipindahkan ke status berjalan lewat jalur resmi sebelum progres dicatat.
    $startRunning = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'status' => 'running',
        'reason' => '',
    ], [], $jarManajer);
    check(
        'Status sedang berjalan dapat ditetapkan dari perencanaan',
        $startRunning['status'] === 302 && (string) projectColumn($projectId, 'status') === 'running'
    );

    $progressBase = (int) projectColumn($projectId, 'progress');
    $progressNeedles = [
        '150' => 'rentang 0 sampai 100 persen',
        '-5' => 'angka bulat 0 sampai 100',
        'abc' => 'angka bulat 0 sampai 100',
        '35.5' => 'angka bulat 0 sampai 100',
        '1000' => 'angka bulat 0 sampai 100',
    ];

    foreach ($progressNeedles as $value => $needle) {
        $attempt = http('POST', '/proyek/' . $projectId . '/progres', [
            '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
            'progress' => (string) $value,
            'report_date' => date('Y-m-d'),
            'work_description' => 'Percobaan validasi progres.',
            'responsible_name' => 'Pengawas Uji',
        ], [], $jarPengawas);
        check("Progres {$value} ditolak", $attempt['status'] === 302);
        check("Pesan progres {$value} muncul", str_contains(pageOn($jarPengawas, '/proyek/' . $projectId), $needle));
        check("Progres proyek tetap {$progressBase}% setelah ditolak", (int) projectColumn($projectId, 'progress') === $progressBase);
    }

    $noNote = http('POST', '/proyek/' . $projectId . '/progres', [
        '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
        'progress' => '60',
        'report_date' => date('Y-m-d'),
        'work_description' => '',
        'responsible_name' => 'Pengawas Uji',
    ], [], $jarPengawas);
    check(
        'Progres tanpa catatan pekerjaan ditolak',
        $noNote['status'] === 302 && str_contains(pageOn($jarPengawas, '/proyek/' . $projectId), 'Deskripsi pekerjaan wajib diisi')
    );

    $future = http('POST', '/proyek/' . $projectId . '/progres', [
        '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
        'progress' => '60',
        'report_date' => date('Y-m-d', strtotime('+3 day')),
        'work_description' => 'Percobaan tanggal laporan masa depan.',
        'responsible_name' => 'Pengawas Uji',
    ], [], $jarPengawas);
    check(
        'Tanggal laporan masa depan ditolak',
        $future['status'] === 302 && str_contains(pageOn($jarPengawas, '/proyek/' . $projectId), 'tidak boleh di masa depan')
    );

    $keuanganProgress = http('POST', '/proyek/' . $projectId . '/progres', [
        '_token' => tokenFor($jarKeuangan, '/proyek/' . $projectId),
        'progress' => '70',
        'report_date' => date('Y-m-d'),
        'work_description' => 'Percobaan progres oleh staf keuangan.',
        'responsible_name' => 'Keuangan Uji',
    ], [], $jarKeuangan);
    check('Keuangan tanpa projects.progress.update ditolak 403', $keuanganProgress['status'] === 403);
    check('Progres tetap tidak berubah', (int) projectColumn($projectId, 'progress') === $progressBase);

    $first = upload(
        '/proyek/' . $projectId . '/progres',
        [
            '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
            'progress' => '35',
            'report_date' => date('Y-m-d', strtotime('-2 day')),
            'work_description' => 'Pekerjaan struktur lantai dasar selesai 35 persen.',
            'completed_work' => 'Pasang besi beton sloof.',
            'obstacles' => 'Hujan deras dua hari.',
            'next_plan' => 'Pekerjaan kolom.',
            'responsible_name' => 'Pengawas Uji',
        ],
        ['name' => 'foto kondisi.png', 'type' => 'image/png', 'path' => fixture('kondisi.png', $GLOBALS['pngBytes'])],
        $jarPengawas
    );
    check('Laporan progres beserta foto tersimpan', $first['status'] === 302, locationOf($first));

    $reportStatement = db()->prepare(
        'SELECT * FROM project_progress_reports WHERE project_id = :id ORDER BY id ASC LIMIT 1'
    );
    $reportStatement->execute(['id' => $projectId]);
    $reportRow = $reportStatement->fetch() ?: [];
    check('Riwayat menyimpan progres sebelum', (int) ($reportRow['progress_before'] ?? -1) === $progressBase);
    check('Riwayat menyimpan progres sesudah', (int) ($reportRow['progress_after'] ?? -1) === 35);
    check('Riwayat menyimpan deskripsi pekerjaan', (string) ($reportRow['work_description'] ?? '') !== '');
    check('Riwayat menyimpan penanggung jawab', (string) ($reportRow['responsible_name'] ?? '') === 'Pengawas Uji');
    check('Progres proyek ikut diperbarui', (int) projectColumn($projectId, 'progress') === 35);
    check('Audit mencatat laporan progres', countLogs('progress.create') >= 1);

    $photoStatement = db()->prepare(
        "SELECT * FROM project_documents WHERE project_id = :id AND category = 'construction' ORDER BY id DESC LIMIT 1"
    );
    $photoStatement->execute(['id' => $projectId]);
    $photoRow = $photoStatement->fetch() ?: [];
    $GLOBALS['createdStored'][] = (string) ($photoRow['stored_name'] ?? '');
    check('Foto laporan tercatat sebagai dokumentasi', (string) ($photoRow['stored_name'] ?? '') !== '');
    check(
        'Foto ditautkan pada laporan progresnya',
        (int) ($photoRow['progress_report_id'] ?? 0) === (int) ($reportRow['id'] ?? 0)
    );
    check('Notifikasi progres terkirim', countNotifications($kepalaId, 'project.progress', $projectId) === 1);

    $sameValue = http('POST', '/proyek/' . $projectId . '/progres', [
        '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
        'progress' => '35',
        'report_date' => date('Y-m-d'),
        'work_description' => 'Pekerjaan kolom tetap pada 35 persen.',
        'responsible_name' => 'Pengawas Uji',
    ], [], $jarPengawas);
    $reportCount = (int) db()->query('SELECT COUNT(*) FROM project_progress_reports WHERE project_id = ' . $projectId)->fetchColumn();
    check('Laporan bernilai sama tetap memiliki riwayat', $sameValue['status'] === 302 && $reportCount === 2, (string) $reportCount);

    $detailPage = pageOn($jarPengawas, '/proyek/' . $projectId);
    preg_match('/data-chart-labels="([^"]*)"/', $detailPage, $detailLabel);
    $detailSeries = json_decode(html_entity_decode($detailLabel[1] ?? '', ENT_QUOTES, 'UTF-8'), true);
    check('Detail menampilkan grafik progres waktu', str_contains($detailPage, 'data-chart-type="line"'));
    check(
        'Deret grafik progres berisi tanggal laporan nyata',
        is_array($detailSeries) && count($detailSeries) >= 1
            && preg_match('/^\d{1,2} [A-Za-z]+ \d{4}$/', (string) $detailSeries[0]) === 1,
        json_encode($detailSeries, JSON_UNESCAPED_UNICODE) ?: '-',
    );
    check('Detail menampilkan riwayat progres', str_contains($detailPage, 'Pekerjaan struktur lantai dasar'));
    check('Detail menampilkan kendala lapangan', str_contains($detailPage, 'Hujan deras dua hari.'));
    check('Detail menampilkan target banding aktual', str_contains($detailPage, 'Target'));

    $full = http('POST', '/proyek/' . $projectId . '/progres', [
        '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
        'progress' => '100',
        'report_date' => date('Y-m-d'),
        'work_description' => 'Seluruh pekerjaan fisik tuntas 100 persen.',
        'responsible_name' => 'Pengawas Uji',
    ], [], $jarPengawas);
    check(
        'Progres 100 persen tidak mengubah status otomatis',
        $full['status'] === 302 && (int) projectColumn($projectId, 'progress') === 100
        && (string) projectColumn($projectId, 'status') === 'running'
    );
    check(
        'Pengingat konfirmasi status muncul',
        str_contains(pageOn($jarPengawas, '/proyek/' . $projectId), 'Status proyek tidak berubah otomatis')
    );

    section('D. Perubahan status beserta riwayatnya');

    $badStatus = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'status' => 'selesai',
        'reason' => '',
    ], [], $jarManajer);
    check(
        'Status tidak dikenal ditolak',
        $badStatus['status'] === 302 && str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'Status proyek tidak dikenali.')
    );

    $sameStatus = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'status' => 'running',
        'reason' => '',
    ], [], $jarManajer);
    check(
        'Status yang sama dengan sebelumnya ditolak',
        $sameStatus['status'] === 302 && str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'harus berbeda dari status saat ini')
    );

    $forbiddenStatus = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
        'status' => 'delayed',
        'reason' => 'Percobaan oleh pengawas.',
    ], [], $jarPengawas);
    check('Pengawas tanpa projects.status.update ditolak 403', $forbiddenStatus['status'] === 403);
    check('Status tetap berjalan', (string) projectColumn($projectId, 'status') === 'running');

    $noReason = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'status' => 'delayed',
        'reason' => '',
    ], [], $jarManajer);
    check(
        'Status tertunda tanpa alasan ditolak',
        $noReason['status'] === 302 && str_contains(pageOn($jarManajer, '/proyek/' . $projectId), 'wajib disertai alasan')
        && (string) projectColumn($projectId, 'status') === 'running'
    );

    $withReason = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'status' => 'delayed',
        'reason' => 'Pasokan baja tertahan di pelabuhan.',
    ], [], $jarManajer);
    $statusStatement = db()->prepare(
        'SELECT * FROM project_status_logs WHERE project_id = :id ORDER BY id DESC LIMIT 1'
    );
    $statusStatement->execute(['id' => $projectId]);
    $logRow = $statusStatement->fetch() ?: [];
    check(
        'Status berubah beserta alasan tersimpan',
        $withReason['status'] === 302 && (string) projectColumn($projectId, 'status') === 'delayed'
        && (string) ($logRow['reason'] ?? '') === 'Pasokan baja tertahan di pelabuhan.'
    );
    check('Riwayat status mencatat pengguna', (int) ($logRow['changed_by'] ?? 0) === $manajerId);
    check('Riwayat status mencatat status asal', (string) ($logRow['from_status'] ?? '') === 'running');
    check('Audit mencatat perubahan status', countLogs('projects.status') >= 1);
    check(
        'Notifikasi perubahan status terkirim',
        countNotifications($keuanganId, 'project.status', $projectId) >= 1,
        (string) countNotifications($keuanganId, 'project.status', $projectId)
    );

    $completed = http('POST', '/proyek/' . $projectId . '/status', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
        'status' => 'completed',
        'reason' => '',
    ], [], $jarManajer);
    check(
        'Status selesai boleh tanpa alasan',
        $completed['status'] === 302 && (string) projectColumn($projectId, 'status') === 'completed'
    );

    section('E. Dokumentasi, unggahan, dan pengamanan berkas');

    $documentBaseline = countDocuments($projectId);
    $storedBaseline = count(glob($uploadDir . '/*') ?: []);

    /**
     * @param array<string, string> $fields
     * @param array{name: string, type: string, path: string}|null $file
     */
    $rejectedUpload = static function (string $label, string $needle, array $fields, ?array $file, string $jar) use ($projectId): void {
        $response = upload('/proyek/' . $projectId . '/dokumen', $fields, $file, $jar);
        check($label . ' ditolak', $response['status'] === 302, (string) $response['status']);
        check(
            'Pesan ' . $label . ' muncul',
            str_contains(pageOn($jar, '/proyek/' . $projectId), $needle)
        );
    };

    $uploadFields = static fn (string $jar, string $category, string $title): array => [
        '_token' => tokenFor($jar, '/proyek/' . $projectId),
        'category' => $category,
        'title' => $title,
        'description' => '',
    ];

    $rejectedUpload(
        'Berkas PHP',
        'Jenis berkas tidak diizinkan',
        $uploadFields($jarManajer, 'supporting', 'Berkas berbahaya'),
        ['name' => 'shell.php', 'type' => 'application/x-httpd-php', 'path' => fixture('shell.php', "<?php echo 'x';")],
        $jarManajer
    );
    $rejectedUpload(
        'PNG ber-ekstensi jpg',
        'Isi berkas tidak sesuai dengan ekstensinya',
        $uploadFields($jarManajer, 'initial', 'Foto kondisi awal palsu'),
        ['name' => 'palsu.jpg', 'type' => 'image/jpeg', 'path' => fixture('palsu.jpg', $GLOBALS['pngBytes'])],
        $jarManajer
    );
    $rejectedUpload(
        'Isi PNG dalam berkas pdf',
        'Isi berkas tidak sesuai dengan ekstensinya',
        $uploadFields($jarManajer, 'contract', 'Kontrak berisi gambar'),
        ['name' => 'kontrak.pdf', 'type' => 'application/pdf', 'path' => fixture('kontrak-png.pdf', $GLOBALS['pngBytes'])],
        $jarManajer
    );
    $rejectedUpload(
        'Berkas melebihi batas ukuran',
        'Ukuran berkas harus antara',
        $uploadFields($jarManajer, 'supporting', 'Berkas besar'),
        [
            'name' => 'besar.pdf',
            'type' => 'application/pdf',
            'path' => fixture('besar.pdf', $GLOBALS['pdfBytes'] . str_repeat('A', 11 * 1048576)),
        ],
        $jarManajer
    );
    $rejectedUpload(
        'Kategori tak dikenal',
        'Kategori dokumen tidak dikenali',
        $uploadFields($jarManajer, 'rahasia', 'Dokumen kategori salah'),
        ['name' => 'surat.pdf', 'type' => 'application/pdf', 'path' => fixture('surat.pdf', $GLOBALS['pdfBytes'])],
        $jarManajer
    );
    $rejectedUpload(
        'Judul terlalu pendek',
        'Judul dokumen harus terdiri dari',
        $uploadFields($jarManajer, 'supporting', 'ab'),
        ['name' => 'pendek.pdf', 'type' => 'application/pdf', 'path' => fixture('pendek.pdf', $GLOBALS['pdfBytes'])],
        $jarManajer
    );
    $rejectedUpload(
        'Unggahan tanpa berkas',
        'wajib diunggah',
        $uploadFields($jarManajer, 'supporting', 'Tanpa berkas'),
        null,
        $jarManajer
    );

    check(
        'Seluruh unggahan ditolak sehingga metadata tidak bertambah',
        countDocuments($projectId) === $documentBaseline,
        (string) countDocuments($projectId)
    );
    check(
        'Berkas yang ditolak tidak tertinggal di penyimpanan',
        count(glob($uploadDir . '/*') ?: []) === $storedBaseline,
        (string) (count(glob($uploadDir . '/*') ?: []) - $storedBaseline)
    );

    $keuanganUpload = upload(
        '/proyek/' . $projectId . '/dokumen',
        ['_token' => tokenFor($jarKeuangan, '/proyek/' . $projectId), 'category' => 'supporting', 'title' => 'Dokumen keuangan'],
        ['name' => 'keuangan.pdf', 'type' => 'application/pdf', 'path' => fixture('keuangan.pdf', $GLOBALS['pdfBytes'])],
        $jarKeuangan
    );
    check('Keuangan tanpa projects.documents.upload ditolak 403', $keuanganUpload['status'] === 403);

    $uploaded = upload(
        '/proyek/' . $projectId . '/dokumen',
        [
            '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
            'category' => 'minutes',
            'title' => 'Berita acara pemeriksaan lapangan',
            'description' => 'Dokumen hasil pengujian otomatis.',
        ],
        ['name' => 'berita acara.pdf', 'type' => 'application/pdf', 'path' => fixture('berita-acara.pdf', $GLOBALS['pdfBytes'])],
        $jarManajer
    );
    check('Unggahan PDF valid diterima', $uploaded['status'] === 302, locationOf($uploaded));

    $documentStatement = db()->prepare(
        "SELECT * FROM project_documents WHERE project_id = :id AND category = 'minutes' ORDER BY id DESC LIMIT 1"
    );
    $documentStatement->execute(['id' => $projectId]);
    $document = $documentStatement->fetch() ?: [];
    $documentId = (int) ($document['id'] ?? 0);
    $storedName = (string) ($document['stored_name'] ?? '');
    $createdStored[] = $storedName;

    check('Metadata menyimpan nama asli berkas', (string) ($document['original_name'] ?? '') === 'berita acara.pdf');
    check('Metadata menyimpan kategori', (string) ($document['category'] ?? '') === 'minutes');
    check('Metadata menyimpan MIME hasil deteksi isi', (string) ($document['mime_type'] ?? '') === 'application/pdf');
    check('Metadata menyimpan ukuran berkas', (int) ($document['file_size'] ?? 0) > 0);
    check('Nama penyimpanan dibuat acak', preg_match('/^[a-f0-9]{40}\.pdf$/', $storedName) === 1, $storedName);
    check('Berkas tersimpan di folder non-public', is_file($uploadDir . '/' . $storedName));
    check('Pengunggah tercatat', (int) ($document['uploaded_by'] ?? 0) === $manajerId);
    check('Audit mencatat unggahan dokumen', countLogs('documents.upload') >= 1);
    check('Notifikasi dokumen baru terkirim', countNotifications($keuanganId, 'project.document', $projectId) === 1);

    $directAccess = http('GET', '/storage/uploads/projects/' . $storedName);
    check('Berkas tidak dapat diakses langsung lewat URL', $directAccess['status'] === 404, (string) $directAccess['status']);
    check(
        'Tidak ada salinan berkas di folder public',
        !is_file(dirname(__DIR__) . '/public/storage/uploads/projects/' . $storedName)
    );

    $downloaded = http('GET', '/dokumen/' . $documentId . '/unduh', null, [], $jarManajer);
    check('Unduhan mengembalikan 200', $downloaded['status'] === 200, (string) $downloaded['status']);
    check('Tipe konten sesuai MIME tersimpan', ($downloaded['headers']['content-type'] ?? '') === 'application/pdf');
    check(
        'Disposisi attachment memakai nama asli',
        str_contains((string) ($downloaded['headers']['content-disposition'] ?? ''), 'attachment')
        && str_contains((string) ($downloaded['headers']['content-disposition'] ?? ''), 'berita acara.pdf')
    );
    check('Isi unduhan identik dengan berkas', $downloaded['body'] === $GLOBALS['pdfBytes']);

    $inline = http('GET', '/dokumen/' . $documentId . '/tampilan', null, [], $jarManajer);
    check(
        'Pratinjau memakai disposisi inline',
        $inline['status'] === 200 && str_contains((string) ($inline['headers']['content-disposition'] ?? ''), 'inline')
    );
    check(
        'Gudang tanpa projects.documents.view ditolak 403',
        http('GET', '/dokumen/' . $documentId . '/unduh', null, [], $jarGudang)['status'] === 403
    );
    check(
        'Keuangan tanpa projects.documents.view ditolak 403',
        http('GET', '/dokumen/' . $documentId . '/unduh', null, [], $jarKeuangan)['status'] === 403
    );
    check('Id dokumen tak dikenal menghasilkan 404', http('GET', '/dokumen/999999/unduh', null, [], $jarManajer)['status'] === 404);

    $forbiddenDelete = http('POST', '/dokumen/' . $documentId . '/hapus', [
        '_token' => tokenFor($jarManajer, '/proyek/' . $projectId),
    ], [], $jarManajer);
    check('Manajer tanpa projects.documents.delete ditolak 403', $forbiddenDelete['status'] === 403);
    clearstatcache();
    check('Berkas masih ada setelah percobaan hapus terlarang', is_file($uploadDir . '/' . $storedName));

    $deleted = http('POST', '/dokumen/' . $documentId . '/hapus', [
        '_token' => tokenFor($jarAdmin, '/proyek/' . $projectId),
    ], [], $jarAdmin);
    check(
        'Yang berwenang dapat menghapus dokumen',
        $deleted['status'] === 302 && (int) db()->query('SELECT COUNT(*) FROM project_documents WHERE id = ' . $documentId)->fetchColumn() === 0
    );
    clearstatcache();
    $leftAfterDelete = $uploadDir . '/' . $storedName;
    check(
        'Berkas fisik ikut terhapus',
        !is_file($leftAfterDelete),
        $leftAfterDelete . ' ada=' . (is_file($leftAfterDelete) ? '1' : '0')
            . '| sisa isi folder=' . implode(',', array_map('basename', glob($uploadDir . '/*') ?: []))
    );
    check('Audit mencatat penghapusan dokumen', countLogs('documents.delete') >= 1);

    section('F. Tim proyek');

    $usersBefore = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $assigned = http('POST', '/proyek/' . $projectId . '/tim', [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
        'user_id' => (string) $gudangId,
        'position' => 'Staf pendukung uji',
        'duties' => 'Membantu pencatatan material.',
    ], [], $jarKepala);
    $assignmentStatement = db()->prepare(
        'SELECT * FROM project_team_assignments WHERE project_id = :id AND user_id = :user ORDER BY id DESC LIMIT 1'
    );
    $assignmentStatement->execute(['id' => $projectId, 'user' => $gudangId]);
    $assignmentRow = $assignmentStatement->fetch() ?: [];
    $memberAssignmentId = (int) ($assignmentRow['id'] ?? 0);

    check('Anggota tim ditugaskan', $assigned['status'] === 302 && $memberAssignmentId > 0);
    check('Penugasan menyimpan jabatan', (string) ($assignmentRow['position'] ?? '') === 'Staf pendukung uji');
    check('Penugasan menyimpan pengassign', (int) ($assignmentRow['assigned_by'] ?? 0) === $kepalaId);
    check('Penugasan tidak membuat akun duplikat', (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBefore);
    check('Audit mencatat penugasan tim', countLogs('team.assign') >= 1);

    $duplicateMember = http('POST', '/proyek/' . $projectId . '/tim', [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
        'user_id' => (string) $gudangId,
        'position' => 'Staf pendukung uji',
        'duties' => '',
    ], [], $jarKepala);
    check(
        'Anggota ganda ditolak',
        $duplicateMember['status'] === 302 && str_contains(pageOn($jarKepala, '/proyek/' . $projectId), 'sudah menjadi tim aktif proyek ini')
    );

    $unknownMember = http('POST', '/proyek/' . $projectId . '/tim', [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
        'user_id' => '999999',
        'position' => 'Anggota tak dikenal',
        'duties' => '',
    ], [], $jarKepala);
    check(
        'Anggota bukan akun terdaftar ditolak',
        $unknownMember['status'] === 302 && str_contains(pageOn($jarKepala, '/proyek/' . $projectId), 'harus akun aktif yang terdaftar')
    );

    $shortPosition = http('POST', '/proyek/' . $projectId . '/tim', [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
        'user_id' => (string) $keuanganId,
        'position' => 'ab',
        'duties' => '',
    ], [], $jarKepala);
    check(
        'Jabatan terlalu pendek ditolak',
        $shortPosition['status'] === 302 && str_contains(
            pageOn($jarKepala, '/proyek/' . $projectId),
            'Jabatan atau tugas utama harus terdiri dari 3 sampai 80 karakter'
        )
    );
    check('Penugasan gagal tidak meninggalkan baris', (int) db()->query(
        'SELECT COUNT(*) FROM project_team_assignments WHERE project_id = ' . $projectId
    )->fetchColumn() === 1);

    $lead = http('POST', '/proyek/' . $projectId . '/tim', [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
        'user_id' => (string) $manajerId,
        'position' => 'Koordinator pelaksana',
        'duties' => '',
        'is_lead' => '1',
    ], [], $jarKepala);
    $leadStatement = db()->prepare(
        'SELECT * FROM project_team_assignments WHERE project_id = :id AND user_id = :user ORDER BY id DESC LIMIT 1'
    );
    $leadStatement->execute(['id' => $projectId, 'user' => $manajerId]);
    $leadRow = $leadStatement->fetch() ?: [];
    $leadAssignmentId = (int) ($leadRow['id'] ?? 0);
    $leadCount = (int) db()->query(
        'SELECT COUNT(*) FROM project_team_assignments
         WHERE project_id = ' . $projectId . ' AND unassigned_at IS NULL AND is_lead = 1'
    )->fetchColumn();

    check(
        'Penetapan pemimpin menautkan akun ke proyek',
        $lead['status'] === 302 && $leadAssignmentId > 0 && $leadCount === 1
        && (int) projectColumn($projectId, 'pic_user_id') === $manajerId
    );
    check('Nama penanggung jawab ikut tersinkron', (string) projectColumn($projectId, 'pic_name') === 'Manajer Uji');

    $blockedUnassign = http('POST', '/proyek/' . $projectId . '/tim/' . $leadAssignmentId . '/hapus', [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
    ], [], $jarKepala);
    check(
        'Penanggung jawab tidak dapat dilepas tanpa pengganti',
        $blockedUnassign['status'] === 302 && str_contains(
            pageOn($jarKepala, '/proyek/' . $projectId),
            'Tetapkan penanggung jawab baru terlebih dahulu'
        )
    );

    $blockedDemote = http('POST', '/proyek/' . $projectId . '/tim/' . $leadAssignmentId, [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
        'position' => 'Anggota biasa',
        'duties' => '',
    ], [], $jarKepala);
    check(
        'Penanggung jawab tidak dapat diturunkan menjadi anggota biasa',
        $blockedDemote['status'] === 302 && str_contains(pageOn($jarKepala, '/proyek/' . $projectId), 'penanggung jawab proyek')
        && (int) db()->query('SELECT COUNT(*) FROM users WHERE id = ' . $manajerId)->fetchColumn() === 1
    );

    $changed = http('POST', '/proyek/' . $projectId . '/tim/' . $memberAssignmentId, [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
        'position' => 'Staf logistik uji',
        'duties' => 'Mencatat barang masuk.',
    ], [], $jarKepala);
    $changedStatement = db()->prepare('SELECT * FROM project_team_assignments WHERE id = :id');
    $changedStatement->execute(['id' => $memberAssignmentId]);
    $changedRow = $changedStatement->fetch() ?: [];
    check(
        'Penugasan dapat diubah',
        $changed['status'] === 302 && (string) ($changedRow['position'] ?? '') === 'Staf logistik uji'
        && countLogs('team.update') >= 1
    );

    $pengawasTeam = http('POST', '/proyek/' . $projectId . '/tim', [
        '_token' => tokenFor($jarPengawas, '/proyek/' . $projectId),
        'user_id' => (string) $kepalaId,
        'position' => 'Anggota tanpa izin',
        'duties' => '',
    ], [], $jarPengawas);
    check('Pengawas tanpa projects.team.manage ditolak 403', $pengawasTeam['status'] === 403);

    $unassigned = http('POST', '/proyek/' . $projectId . '/tim/' . $memberAssignmentId . '/hapus', [
        '_token' => tokenFor($jarKepala, '/proyek/' . $projectId),
    ], [], $jarKepala);
    $releasedStatement = db()->prepare('SELECT * FROM project_team_assignments WHERE id = :id');
    $releasedStatement->execute(['id' => $memberAssignmentId]);
    $releasedRow = $releasedStatement->fetch() ?: [];
    check(
        'Anggota non-penanggung jawab dapat dilepas',
        $unassigned['status'] === 302 && ($releasedRow['unassigned_at'] ?? null) !== null
        && (int) ($releasedRow['is_lead'] ?? 1) === 0
    );
    check(
        'Riwayat penugasan tetap tersimpan',
        (int) db()->query('SELECT COUNT(*) FROM project_team_assignments WHERE project_id = ' . $projectId)->fetchColumn() === 2
    );
    check('Audit mencatat pelepasan penugasan', countLogs('team.unassign') >= 1);

    $teamPage = pageOn($jarKepala, '/proyek/' . $projectId);
    check('Detail tim menampilkan anggota aktif', str_contains($teamPage, 'Koordinator pelaksana'));
    check('Detail tim menampilkan riwayat penugasan', str_contains($teamPage, 'Staf logistik uji'));

    section('G. Notifikasi internal');

    $inbox = http('GET', '/notifikasi', null, [], $jarKeuangan);
    check('Kotak masuk dapat dibuka', $inbox['status'] === 200, (string) $inbox['status']);
    check('Kotak masuk memuat judul notifikasi proyek', str_contains($inbox['body'], 'Proyek baru:'));
    check('Kotak masuk menampilkan penanda waktu', str_contains($inbox['body'], 'Waktu'));

    $foreign = (int) db()->query(
        'SELECT id FROM notifications WHERE user_id = ' . $adminId . ' AND project_id = ' . $projectId . ' LIMIT 1'
    )->fetchColumn();
    check('Pengguna berwenang lain ikut menerima notifikasi', $foreign > 0);

    $crossRead = http('POST', '/notifikasi/baca/' . $foreign, ['_token' => tokenFor($jarKepala, '/notifikasi')], [], $jarKepala);
    check('Notifikasi milik pengguna lain menghasilkan 404', $crossRead['status'] === 404, (string) $crossRead['status']);
    check(
        'Notifikasi milik pengguna lain tidak ikut terbaca',
        (int) db()->query('SELECT is_read FROM notifications WHERE id = ' . $foreign)->fetchColumn() === 0
    );

    $own = (int) db()->query(
        'SELECT id FROM notifications WHERE user_id = ' . $keuanganId . ' AND is_read = 0 LIMIT 1'
    )->fetchColumn();
    $read = http('POST', '/notifikasi/baca/' . $own, ['_token' => tokenFor($jarKeuangan, '/notifikasi')], [], $jarKeuangan);
    $readStatement = db()->prepare('SELECT * FROM notifications WHERE id = :id');
    $readStatement->execute(['id' => $own]);
    $readRow = $readStatement->fetch() ?: [];
    check(
        'Penanda sudah dibaca beserta waktunya tersimpan',
        $read['status'] === 302 && (int) ($readRow['is_read'] ?? 0) === 1 && ($readRow['read_at'] ?? null) !== null
    );

    $adminUnreadBefore = unreadCount($adminId);
    $readAll = http('POST', '/notifikasi/baca-semua', ['_token' => tokenFor($jarKepala, '/notifikasi')], [], $jarKepala);
    check(
        'Tandai semua dibaca hanya mempengaruhi kotak masuk sendiri',
        $readAll['status'] === 302 && unreadCount($kepalaId) === 0 && unreadCount($adminId) === $adminUnreadBefore
    );

    check('Filter belum dibaca berjalan', http('GET', '/notifikasi?filter=unread', null, [], $jarAdmin)['status'] === 200);
    check('Notifikasi jenis lain berlabel Indonesia', str_contains(pageOn($jarAdmin, '/notifikasi'), 'Status berubah:'));

    $late = createProject($jarPengawas, [
        'name' => 'Proyek Uji Terlambat',
        'status' => 'delayed',
        'start_date' => date('Y-m-d', strtotime('-40 day')),
        'target_date' => date('Y-m-d', strtotime('-5 day')),
    ]);
    $dueTarget = date('Y-m-d', strtotime('+4 day'));
    $due = createProject($jarPengawas, [
        'name' => 'Proyek Uji Mendatang',
        'status' => 'running',
        'start_date' => date('Y-m-d', strtotime('-3 day')),
        'target_date' => $dueTarget,
    ]);

    pageOn($jarKepala);
    pageOn($jarKepala);
    $lateKey = 'late-' . $late['id'] . '-' . date('Y-W');
    check('Proyek melewati target dinotifikasi', countNotifications($kepalaId, 'project.late', $late['id'], $lateKey) === 1);
    check('Pengingat tenggat tidak dobel walau dashboard dibuka berulang', countNotifications($kepalaId, 'project.late', $late['id'], $lateKey) === 1);

    $dueKey = 'due-' . $due['id'] . '-' . $dueTarget;
    check('Pengingat menjelang batas waktu terkirim', countNotifications($kepalaId, 'project.due', $due['id'], $dueKey) === 1);

    section('H. Dashboard utama');

    $dashboard = pageOn($jarManajer);
    $realTotal = (int) db()->query('SELECT COUNT(*) FROM projects WHERE is_archived = 0')->fetchColumn();
    $realRunning = (int) db()->query("SELECT COUNT(*) FROM projects WHERE is_archived = 0 AND status = 'running'")->fetchColumn();
    check('Dashboard memuat ringkasan pembangunan', str_contains($dashboard, 'Ringkasan'));
    check('Kartu total proyek ditampilkan', str_contains($dashboard, 'Total proyek'), (string) $realTotal);
    check('Kartu proyek berjalan ditampilkan', str_contains($dashboard, 'Sedang berjalan'), (string) $realRunning);
    check('Kartu anggaran pembangunan ditampilkan', str_contains($dashboard, 'Total anggaran pembangunan'));
    // Kartu realisasi dan sisa diisi oleh modul transaksi. Nilainya dinilai lewat pola
    // kartu (label lalu stat-value), bukan pencarian string bebas, supaya nilai dari
    // kartu lain tidak ikut membuat pemeriksaan ini tampak lulus.
    $finansialDashboard = \App\Models\BudgetSummary::globalOverview(\App\Models\FinancialTransaction::CENTER_CONSTRUCTION);
    preg_match('/Realisasi anggaran<\/p>\s*<p class="stat-value">([^<]*)</', $dashboard, $kartuRealisasi);
    preg_match('/Sisa anggaran<\/p>\s*<p class="stat-value">([^<]*)</', $dashboard, $kartuSisa);
    check(
        'Kartu realisasi anggaran sama dengan angka model',
        ($kartuRealisasi[1] ?? '') === rupiah((float) $finansialDashboard['realization']),
        'tampilan: ' . ($kartuRealisasi[1] ?? '-')
    );
    check(
        'Kartu sisa anggaran sama dengan angka model',
        ($kartuSisa[1] ?? '') === rupiah((float) $finansialDashboard['remaining']),
        'tampilan: ' . ($kartuSisa[1] ?? '-')
    );
    check(
        'Kartu keuangan tidak lagi berbunyi Belum tersedia',
        ($kartuRealisasi[1] ?? '') !== 'Belum tersedia' && ($kartuSisa[1] ?? '') !== 'Belum tersedia'
    );
    check('Metrik operasional MBG ditandai belum tersedia', substr_count($dashboard, 'Belum tersedia') >= 5);
    check('Grafik dipasang lewat atribut data', str_contains($dashboard, 'data-chart-type'));
    check('Chart.js dimuat sebagai aset lokal', str_contains($dashboard, 'vendor/chartjs/chart.umd.min.js'));

    // Atribut harus berisi JSON yang benar-benar dapat diurai browser; bentuk yang
    // terpotong oleh tanda kutip membuat grafik diam-diam tidak pernah dibuat.
    preg_match_all('/data-chart-labels="([^"]*)"/', $dashboard, $labelAttributes);
    $decodedLabels = array_map(
        static fn (string $raw): mixed => json_decode(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'), true),
        $labelAttributes[1] ?? [],
    );
    check(
        'Data grafik dapat diurai sebagai JSON',
        $decodedLabels !== [] && count($decodedLabels) >= 3
            && array_reduce(
                $decodedLabels,
                static fn (bool $carry, mixed $row): bool => $carry && is_array($row) && $row !== [],
                true,
            ),
        implode(' | ', array_map(static fn (mixed $r): string => json_encode($r, JSON_UNESCAPED_UNICODE) ?: '-', $decodedLabels)),
    );
    check('Grafik status proyek dibangun dari data nyata', str_contains($dashboard, 'Status proyek pembangunan'));
    check('Proyek terlambat muncul di dashboard', str_contains($dashboard, $late['code']));
    check('Jadwal kegiatan terdekat menampilkan proyek nyata', str_contains($dashboard, $due['code']));
    check('Bagian aktivitas terbaru ditampilkan', str_contains($dashboard, 'Aktivitas terbaru'));
    check('Tautan daftar aktivitas disembunyikan tanpa audit.view', !str_contains($dashboard, $href('/aktivitas')));
    check('Filter periode tersedia', str_contains($dashboard, 'Bulan ini'));

    $adminDashboard = pageOn($jarAdmin);
    check('Tautan ke daftar aktivitas disediakan bagi yang berhak', str_contains($adminDashboard, $href('/aktivitas')));
    check(
        'Feed aktivitas memuat entri modul proyek',
        str_contains($adminDashboard, 'proyek') && str_contains($adminDashboard, 'Proyek')
    );

    $gudangDashboard = pageOn($jarGudang);
    check(
        'Tanpa audit.view, feed aktivitas ditampilkan jujur',
        str_contains($gudangDashboard, 'Aktivitas tidak dapat ditampilkan')
        && !str_contains($gudangDashboard, 'Mengubah status proyek')
    );
    check('Dashboard peran tanpa hak proyek tetap dimuat', str_contains($gudangDashboard, 'Anda tidak memiliki akses ke data proyek') || str_contains($gudangDashboard, 'Ringkasan'));
    check(
        'Grafik proyek tidak dirender bila tidak berhak',
        !str_contains($gudangDashboard, 'Status proyek pembangunan')
    );

    foreach (['today', 'week', 'month', 'year', 'custom'] as $period) {
        $query = '/dashboard?period=' . $period;
        if ($period === 'custom') {
            $query .= '&from=' . date('Y-m-01') . '&to=' . date('Y-m-d');
        }
        check("Periode {$period} dimuat", http('GET', $query, null, [], $jarManajer)['status'] === 200);
    }
    check('Rentang tanggal tidak sah ditoleransi', http('GET', '/dashboard?period=custom&from=salah', null, [], $jarManajer)['status'] === 200);

    // Regresi: batas atas rentang sehari penuh pernah membuat grafik aktivitas
    // kosong melompong padahal log hari itu ada, dan dashboard tetap membalas 200.
    $todayBody = http('GET', '/dashboard?period=today', null, [], $jarManajer)['body'];
    $todayLogs = (int) db()->query(
        "SELECT COUNT(*) FROM user_activity_logs
         WHERE module IN ('projects', 'progress', 'documents', 'team') AND created_at >= CURDATE()"
    )->fetchColumn();
    $activityAt = strpos($todayBody, 'Aktivitas pembangunan per hari');
    $activitySlice = $activityAt === false ? '' : substr($todayBody, $activityAt, 1500);
    preg_match('/data-chart-labels="([^"]*)"/', $activitySlice, $activityLabel);
    $activitySeries = json_decode(html_entity_decode($activityLabel[1] ?? '', ENT_QUOTES, 'UTF-8'), true);
    check(
        'Periode hari ini tetap memuat deret aktivitas harian',
        $todayLogs === 0 || (is_array($activitySeries) && in_array(date('d/m'), $activitySeries, true)),
        json_encode($activitySeries, JSON_UNESCAPED_UNICODE) . ' | log proyek hari ini: ' . $todayLogs,
    );
    check(
        'Grafik aktivitas hari ini tidak dinyatakan kosong',
        $todayLogs === 0 || !str_contains($activitySlice, 'Belum ada aktivitas pembangunan pada periode ini.'),
        (string) $todayLogs,
    );

    section('I. Integrasi, CSRF, dan keamanan keluaran');

    $csrfLess = http('POST', '/proyek/tambah', payload(), [], $jarPengawas);
    check('POST proyek tanpa token CSRF ditolak 400', $csrfLess['status'] === 400, (string) $csrfLess['status']);

    $csrfUpload = upload(
        '/proyek/' . $projectId . '/dokumen',
        ['category' => 'supporting', 'title' => 'Tanpa token'],
        ['name' => 'tanpa-token.pdf', 'type' => 'application/pdf', 'path' => fixture('tanpa-token.pdf', $GLOBALS['pdfBytes'])],
        $jarPengawas
    );
    check('Unggahan tanpa token CSRF ditolak 400', $csrfUpload['status'] === 400, (string) $csrfUpload['status']);

    $deleteMethod = http(
        'DELETE',
        '/proyek',
        null,
        ['X-CSRF-TOKEN: ' . tokenFor($jarManajer, '/dashboard')],
        $jarManajer
    );
    check('Metode DELETE pada /proyek menghasilkan 405', $deleteMethod['status'] === 405, (string) $deleteMethod['status']);

    foreach (['/login', '/dashboard', '/profil', '/pengaturan', '/pengguna', '/hak-akses', '/aktivitas', '/notifikasi'] as $legacy) {
        $jar = in_array($legacy, ['/pengaturan', '/pengguna', '/hak-akses', '/aktivitas'], true) ? $jarAdmin : $jarManajer;
        $state = $legacy === '/login' ? http('GET', $legacy)['status'] : http('GET', $legacy, null, [], $jar)['status'];
        check("Halaman Prompt 1 dan 2 tetap berjalan pada {$legacy}", in_array($state, [200, 302], true), (string) $state);
    }

    $filterAudit = http('GET', '/aktivitas?module=projects', null, [], $jarAdmin);
    check('Audit dapat difilter per modul proyek', $filterAudit['status'] === 200 && str_contains($filterAudit['body'], 'projects.'));

    $tokenLeak = (int) db()->query(
        "SELECT COUNT(*) FROM user_activity_logs
         WHERE description LIKE '%_token%' OR description LIKE '%remember%' OR description LIKE '%password%'"
    )->fetchColumn();
    check('Token dan kata sandi tidak tercatat pada audit', $tokenLeak === 0, (string) $tokenLeak);

    $statement = db()->prepare('SELECT COUNT(*) FROM user_activity_logs WHERE description LIKE :needle');
    $statement->execute(['needle' => '%' . $password . '%']);
    check('Kata sandi uji tidak bocor ke log', (int) $statement->fetchColumn() === 0);

    $plainPasswords = (int) db()->query(
        "SELECT COUNT(*) FROM users WHERE password NOT LIKE '\$2y\$%' AND password NOT LIKE '\$argon2%'"
    )->fetchColumn();
    check('Seluruh kata sandi tersimpan sebagai hash', $plainPasswords === 0, (string) $plainPasswords);

    $projectPage = http('GET', '/proyek', null, [], $jarManajer);
    check('Header CSP tetap terkirim pada halaman proyek', str_contains((string) ($projectPage['headers']['content-security-policy'] ?? ''), "script-src 'self'"));
    check('Aset JS grafik tersedia', http('GET', '/assets/js/charts.js')['status'] === 200);
    check('Aset JS halaman proyek tersedia', http('GET', '/assets/js/projects.js')['status'] === 200);
    check('Aset JS dashboard tersedia', http('GET', '/assets/js/dashboard.js')['status'] === 200);
    check('Berkas Chart.js lokal tersedia', http('GET', '/assets/vendor/chartjs/chart.umd.min.js')['status'] === 200);
    check('Tidak ada skrip inline pada halaman proyek', preg_match('/<script(\s|>)(?![^>]*src=)/i', $projectPage['body']) === 0);

    echo "\nRingkasan: {$passed} lulus, {$failed} gagal (" . ($passed + $failed) . " uji)\n";
} finally {
    cleanup();
}

exit($failed === 0 ? 0 : 1);
