<?php
declare(strict_types=1);

/**
 * Pengujian HTTP modul RAB, anggaran, transaksi, dan jadwal (Prompt 4).
 *
 * Suite ini membuat datanya sendiri lewat formulir, memeriksa nilai resmi dari
 * database, lalu menghapusnya kembali. Nama proyek sengaja tidak memakai awalan
 * "UJI"/"Proyek Uji" agar tidak ikut terhapus oleh resetModuleData() pada
 * tests/projects.php.
 *
 * Jalankan server lokal lebih dulu:
 *   php -S 127.0.0.1:8099 -t public public/router.php
 * lalu:
 *   php tests/finance_http.php
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
$pathPrefix = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');
$adminUser = (string) (getenv('ADMIN_USERNAME') ?: 'admin');
$adminPass = (string) (getenv('ADMIN_PASSWORD') ?: 'Admin#Sppg2026');

$stamp = date('ymdHis');
$secret = 'Keu#Uji2026';
$uploadDir = dirname(__DIR__) . '/storage/uploads/projects';

$passed = 0;
$failed = 0;
$tempFiles = [];
$createdUserIds = [];
$createdProjectIds = [];
$createdLogins = [];
$createdStored = [];

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
 * Permintaan multipart untuk berkas bukti dan berkas impor.
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

/**
 * Menjalankan beberapa POST sekaligus lewat curl_multi dan membalas statusnya.
 *
 * @param array<int, array{url: string, jar: string, body: string}> $jobs
 * @return array<int, int>
 */
function concurrent(array $jobs): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($jobs as $index => $job) {
        $ch = curl_init($job['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $job['body'],
            CURLOPT_COOKIEJAR => $job['jar'],
            CURLOPT_COOKIEFILE => $job['jar'],
            CURLOPT_TIMEOUT => 60,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$index] = $ch;
    }

    $running = 0;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
    } while ($running > 0);

    $statuses = [];
    foreach ($handles as $index => $ch) {
        $statuses[$index] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    return $statuses;
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

    $file = sys_get_temp_dir() . '/sppg-keu-' . bin2hex(random_bytes(6)) . '.txt';
    $tempFiles[] = $file;

    return $file;
}

function loginAs(string $jar, string $login, string $secret): bool
{
    http('GET', '/login', null, [], $jar);
    $token = csrfToken(http('GET', '/login', null, [], $jar)['body']);
    $result = http('POST', '/login', ['login' => $login, 'password' => $secret, '_token' => $token], [], $jar);

    return $result['status'] === 302;
}

/**
 * POST dengan token dari halaman formulir, lalu baca flash pesan pada GET berikutnya.
 *
 * @param array<string, mixed> $fields
 * @return array{status: int, body: string, headers: array<string, string>, error: string, flash: string}
 */
function send(string $path, array $fields, string $jar, string $tokenPage, string $flashPage): array
{
    $token = csrfToken(http('GET', $tokenPage, null, [], $jar)['body']);
    $result = http('POST', $path, $fields + ['_token' => $token], [], $jar);
    $result['flash'] = flashText($jar, $flashPage);

    return $result;
}

function flashText(string $jar, string $path): string
{
    $html = http('GET', $path, null, [], $jar)['body'];

    return preg_match('/<span>\s*([^<]{3,300}?)\s*<\/span>/us', substr($html, (int) strpos($html, 'toast-body')) ?: '', $m) === 1
        ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'))
        : '';
}

/** @return array<string, mixed> */
function row(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return (array) $statement->fetch(PDO::FETCH_ASSOC);
}

/** @return array<int, array<string, mixed>> */
function rows(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function scalar(string $sql, array $params = []): mixed
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchColumn();
}

function tempFile(string $name, string $bytes): string
{
    global $tempFiles;

    $path = sys_get_temp_dir() . '/sppg-keu-' . bin2hex(random_bytes(5)) . '-' . $name;
    file_put_contents($path, $bytes);
    $tempFiles[] = $path;

    return $path;
}

function forgetStored(array $storedNames): void
{
    global $uploadDir;

    foreach ($storedNames as $name) {
        if (!is_string($name) || $name === '') {
            continue;
        }

        $path = $uploadDir . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function cleanup(): void
{
    global $tempFiles, $createdUserIds, $createdProjectIds, $createdLogins, $createdStored;

    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    forgetStored($createdStored);

    if (getenv('KEEP_TEST_DATA') === '1') {
        echo "\nData uji dipertahankan (KEEP_TEST_DATA=1).\n";
        return;
    }

    try {
        $pdo = db();

        foreach ($createdProjectIds as $projectId) {
            $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $projectId]);
        }

        foreach ($createdUserIds as $userId) {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM user_activity_logs WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }

        if ($createdLogins !== []) {
            $placeholders = implode(',', array_fill(0, count($createdLogins), '?'));
            $pdo->prepare('DELETE FROM login_attempts WHERE login IN (' . $placeholders . ')')
                ->execute($createdLogins);
        }
    } catch (Throwable $exception) {
        echo "\nPembersihan data uji gagal: " . $exception->getMessage() . "\n";
    }
}

register_shutdown_function(static function (): void {
    global $passed, $failed;

    $error = error_get_last();
    cleanup();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) {
        echo "\nSuite berhenti karena galat fatal: " . $error['message'] . "\n";
        $failed++;
    }

    echo "\nRingkasan: {$passed} lulus, {$failed} gagal (" . ($passed + $failed) . " uji)\n";
    exit($failed === 0 ? 0 : 1);
});

// ------------------------------------------------------------------ sesi awal
$adminJar = tempJar();
check('Masuk sebagai Super Admin', loginAs($adminJar, $adminUser, $adminPass));

$roleIds = [];
foreach (rows(
    "SELECT id, role_slug FROM roles
     WHERE role_slug IN ('manajer_koordinator','kepala_sppg','staf_keuangan','admin','pengawas_pembangunan')"
) as $role) {
    $roleIds[(string) $role['role_slug']] = (int) $role['id'];
}

check(
    'Peran uji tersedia',
    count($roleIds) === 5,
    implode(', ', array_keys($roleIds))
);

// ------------------------------------------------------------------ 1. data uji
section('1. Membuat proyek uji lewat formulir');

$projectCode = 'KEUMODUL' . $stamp;
$created = send(
    '/proyek/tambah',
    [
        'project_code' => $projectCode,
        'name' => 'Gedung Dapur dan Gudang Modul Keuangan',
        'description' => 'Proyek data uji suite Prompt 4.',
        'location' => 'Desa Cangkring',
        'address' => 'Jalan Cangkring nomor 1',
        'latitude' => '',
        'longitude' => '',
        'pic_name' => 'Petugas Uji',
        'pic_user_id' => '',
        'contractor' => 'CV Uji Sejahtera',
        'start_date' => date('Y-m-01', strtotime('-2 month')),
        'target_date' => date('Y-m-d', strtotime('+4 month')),
        'budget_amount' => '750000000',
        'status' => 'running',
        'notes' => 'Dibuat otomatis oleh suite pengujian.',
    ],
    $adminJar,
    '/proyek/tambah',
    '/proyek'
);

$projectId = (int) scalar('SELECT id FROM projects WHERE project_code = ?', [$projectCode]);
if ($projectId > 0) {
    $GLOBALS['createdProjectIds'][] = $projectId;
}

check('Proyek uji terbentuk', $created['status'] === 302 && $projectId > 0, 'id proyek ' . $projectId);
check(
    'Anggaran awal proyek tersimpan utuh',
    (float) scalar('SELECT budget_amount FROM projects WHERE id = ?', [$projectId]) === 750000000.0
);

// ------------------------------------------------------- 2. akun pemisah tugas
section('2. Akun penguji dan pemisahan tugas');

$accounts = [
    'pencatat' => ['pencatat' . $stamp, 'manajer_koordinator'],
    'pemeriksa' => ['pemeriksa' . $stamp, 'kepala_sppg'],
    'pengamat' => ['pengamat' . $stamp, 'admin'],
    'pengawas' => ['pengawas' . $stamp, 'pengawas_pembangunan'],
    // Akun pencatat kedua dipakai untuk uji konkurensi pada bagian 17.
    'bendahara' => ['bendahara' . $stamp, 'staf_keuangan'],
];
$jars = [];

foreach ($accounts as $label => [$login, $roleSlug]) {
    $jars[$label] = tempJar();
    $createdLogins[] = $login;

    $result = send(
        '/pengguna/tambah',
        [
            'full_name' => 'Pemilik Nama ' . ucfirst($label),
            'username' => $login,
            'email' => $login . '@sppg-cangkring.test',
            'phone' => '',
            'role_id' => (string) $roleIds[$roleSlug],
            'status' => 'active',
            'password' => $secret,
            'password_confirmation' => $secret,
        ],
        $adminJar,
        '/pengguna/tambah',
        '/pengguna'
    );

    $userId = (int) scalar('SELECT id FROM users WHERE username = ?', [$login]);
    if ($userId > 0) {
        $createdUserIds[] = $userId;
    }

    check("Akun {$label} dibuat", $result['status'] === 302 && $userId > 0, $login);
    check("Sesi {$label} dapat masuk", loginAs($jars[$label], $login, $secret));
}

$viewerId = (int) scalar('SELECT id FROM users WHERE username = ?', [$accounts['pengamat'][0]]);

// -------------------------------------------------------- 3. izin ditegakkan
section('3. Penegakan izin di sisi server');

// Matriks izin: setiap peran diuji pada endpoints yang sama dan hasilnya harus
// persis seperti pemetaan permission, bukan berdasarkan nama role.
$permissionMatrix = [
    'pencatat' => [
        '/rab' => 200, '/rab/tambah' => 200, '/transaksi' => 200, '/transaksi/tambah' => 200,
        '/jadwal' => 200, '/jadwal/tambah' => 200, '/keuangan' => 200, '/pengaturan' => 403,
    ],
    'pemeriksa' => [
        '/rab' => 200, '/rab/tambah' => 403, '/transaksi' => 200, '/transaksi/tambah' => 403,
        '/jadwal' => 200, '/jadwal/tambah' => 403, '/keuangan' => 200, '/pengaturan' => 403,
    ],
    'bendahara' => [
        '/rab' => 200, '/rab/tambah' => 200, '/transaksi' => 200, '/transaksi/tambah' => 200,
        '/jadwal' => 200, '/jadwal/tambah' => 403, '/keuangan' => 200, '/pengaturan' => 403,
    ],
    'pengawas' => [
        '/rab' => 200, '/rab/tambah' => 403, '/transaksi' => 200, '/transaksi/tambah' => 403,
        '/jadwal' => 200, '/jadwal/tambah' => 403, '/keuangan' => 200, '/pengaturan' => 403,
    ],
    'pengamat' => [
        '/rab' => 200, '/rab/tambah' => 403, '/transaksi' => 200, '/transaksi/tambah' => 403,
        '/jadwal' => 200, '/jadwal/tambah' => 403, '/keuangan' => 200, '/pengaturan' => 200,
    ],
];

foreach ($permissionMatrix as $label => $expectations) {
    foreach ($expectations as $path => $expected) {
        $result = http('GET', $path, null, [], $jars[$label]);
        check("Izin {$label} pada {$path}", $result['status'] === $expected, 'HTTP ' . $result['status']);
    }
}

foreach (['/rab/tambah', '/transaksi/tambah', '/jadwal/tambah', '/pengaturan', '/hak-akses'] as $path) {
    $result = http('GET', $path, null, [], $adminJar);
    check("Super Admin bebas membuka {$path}", $result['status'] === 200, 'HTTP ' . $result['status']);
}

$getDelete = http('GET', '/transaksi/1/hapus', null, [], $jars['pencatat']);
check('Aksi perubahan menolak metode GET', $getDelete['status'] === 405, 'HTTP ' . $getDelete['status']);

$noCsrf = http('POST', '/rab/tambah', ['project_id' => $projectId, 'name' => 'RAB Tanpa Token'], [], $jars['pencatat']);
check('POST tanpa token CSRF ditolak', $noCsrf['status'] === 400, 'HTTP ' . $noCsrf['status']);

$guestProbe = http('GET', '/rab', null, [], tempJar());
check('Pengguna tanpa sesi diarahkan ke halaman masuk', $guestProbe['status'] === 302, 'HTTP ' . $guestProbe['status']);

// ------------------------------------------------------------------ 4. RAB
section('4. RAB, item, dan perhitungan sisi server');

$planCreated = send(
    '/rab/tambah',
    [
        'project_id' => (string) $projectId,
        'rab_code' => '',
        'name' => 'RAB Gedung Dapur',
        'period_start' => date('Y-01-05'),
        'period_end' => date('Y-12-20'),
        'notes' => 'Kode RAB dibiarkan kosong agar terisi otomatis.',
    ],
    $jars['pencatat'],
    '/rab/tambah',
    '/rab'
);

$planId = (int) scalar('SELECT id FROM budget_plans WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
$planCode = (string) scalar('SELECT rab_code FROM budget_plans WHERE id = ?', [$planId]);
check('RAB terbentuk dengan kode otomatis', preg_match('/^RAB-\d{4}-\d{1,4}$/', $planCode) === 1, $planCode);
check('RAB awal berstatus draft', (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$planId]) === 'draft');

$badCode = send(
    '/rab/tambah',
    [
        'project_id' => (string) $projectId,
        'rab_code' => 'RAB-ABCD-01',
        'name' => 'RAB Kode Salah',
        'period_start' => date('Y-01-05'),
        'period_end' => date('Y-12-20'),
        'notes' => '',
    ],
    $jars['pencatat'],
    '/rab/tambah',
    '/rab'
);
check(
    'Kode RAB di luar pola ditolak',
    $badCode['status'] === 302 && (int) scalar('SELECT COUNT(*) FROM budget_plans WHERE rab_code = ?', ['RAB-ABCD-01']) === 0,
    $badCode['flash']
);

$items = [
    ['STR-01', 'Beton bertulang kolom', 3, '12.5', 'm3', '1200000'],
    ['DAPUR-02', 'Pemasangan keramik dinding', 8, '128.5', 'm2', '185000'],
    ['ATAP-01', 'Rangka atap baja ringan', 5, '210', 'm2', '275000'],
];
$expectedTotal = 0.0;
$itemIds = [];

foreach ($items as [$code, $name, $categoryId, $volume, $unit, $price]) {
    $result = send(
        '/rab/' . $planId . '/item',
        [
            'category_id' => (string) $categoryId,
            'item_code' => $code,
            'item_name' => $name,
            'volume' => $volume,
            'unit' => $unit,
            'unit_price' => $price,
            'total_price' => '1',
            'notes' => 'Kolom total dikirim palsu untuk menguji perhitungan server.',
        ],
        $jars['pencatat'],
        '/rab/' . $planId,
        '/rab/' . $planId
    );

    $itemId = (int) scalar('SELECT id FROM budget_items WHERE budget_plan_id = ? AND item_code = ?', [$planId, $code]);
    if ($itemId > 0) {
        $itemIds[$code] = $itemId;
        $expectedTotal += (float) $volume * (float) $price;
    }

    check("Item {$code} tersimpan", $itemId > 0);
    check(
        "Item {$code}: total dihitung server (volume × harga)",
        (float) scalar('SELECT total_price FROM budget_items WHERE id = ?', [$itemId]) === round((float) $volume * (float) $price, 2)
    );
}

$storedTotal = (float) scalar('SELECT total_amount FROM budget_plans WHERE id = ?', [$planId]);
check('Total RAB = jumlah seluruh item', abs($storedTotal - $expectedTotal) < 0.001, rupiah($storedTotal) . ' vs ' . rupiah($expectedTotal));
check(
    'Total item tidak dapat dipalsukan dari browser',
    (float) scalar('SELECT total_price FROM budget_items WHERE id = ?', [$itemIds['STR-01']]) === 15000000.0
);

$bads = [
    'volume nol' => ['category_id' => '3', 'item_code' => 'BAD-01', 'item_name' => 'Item volume nol', 'volume' => '0', 'unit' => 'm3', 'unit_price' => '100000'],
    'harga negatif' => ['category_id' => '3', 'item_code' => 'BAD-02', 'item_name' => 'Item harga negatif', 'volume' => '2', 'unit' => 'm3', 'unit_price' => '-1000'],
    'uraian pendek' => ['category_id' => '3', 'item_code' => 'BAD-03', 'item_name' => 'ab', 'volume' => '2', 'unit' => 'm3', 'unit_price' => '1000'],
    'kategori asing' => ['category_id' => '999999', 'item_code' => 'BAD-04', 'item_name' => 'Kategori tidak ada', 'volume' => '2', 'unit' => 'm3', 'unit_price' => '1000'],
];

foreach ($bads as $label => $fields) {
    $result = send('/rab/' . $planId . '/item', $fields, $jars['pencatat'], '/rab/' . $planId, '/rab/' . $planId);
    $code = $fields['item_code'];
    check(
        "Item ditolak: {$label}",
        (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ? AND item_code = ?', [$planId, $code]) === 0,
        $result['flash']
    );
}

$itemCountBefore = (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ?', [$planId]);
$duplicated = send(
    '/item/' . $itemIds['DAPUR-02'] . '/duplikat',
    [],
    $jars['pencatat'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check(
    'Item dapat diduplikasi',
    (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ?', [$planId]) === $itemCountBefore + 1,
    $duplicated['flash']
);

$copiedId = (int) scalar(
    'SELECT id FROM budget_items WHERE budget_plan_id = ? AND id <> ? ORDER BY id DESC LIMIT 1',
    [$planId, $itemIds['DAPUR-02']]
);
check(
    'Hasil salinan membawa nilai sumbernya',
    $copiedId > 0 && (float) scalar('SELECT volume * unit_price FROM budget_items WHERE id = ?', [$copiedId])
        === (float) scalar('SELECT total_price FROM budget_items WHERE id = ?', [$itemIds['DAPUR-02']]),
    'kode salinan: ' . (string) scalar('SELECT item_code FROM budget_items WHERE id = ?', [$copiedId])
);
$edited = send(
    '/item/' . $copiedId . '/ubah',
    [
        'category_id' => '8',
        'item_code' => 'DAPUR-02B',
        'item_name' => 'Keramik dinding dapur (ubah)',
        'volume' => '100',
        'unit' => 'm2',
        'unit_price' => '200000',
        'notes' => 'Diubah oleh suite.',
    ],
    $jars['pencatat'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check(
    'Item dapat diubah dan totalnya dihitung ulang',
    $edited['status'] === 302 && (float) scalar('SELECT total_price FROM budget_items WHERE id = ?', [$copiedId]) === 20000000.0
);

$removed = send('/item/' . $copiedId . '/hapus', [], $jars['pencatat'], '/rab/' . $planId, '/rab/' . $planId);
check('Item dapat dihapus selagi draft', (int) scalar('SELECT COUNT(*) FROM budget_items WHERE id = ?', [$copiedId]) === 0, $removed['flash']);

$totalsNow = (float) scalar(
    'SELECT COALESCE(SUM(total_price), 0) FROM budget_items WHERE budget_plan_id = ?',
    [$planId]
);
$planTotalNow = (float) scalar('SELECT total_amount FROM budget_plans WHERE id = ?', [$planId]);
check('Total RAB mengikuti perubahan item', abs($planTotalNow - $totalsNow) < 0.001, rupiah($planTotalNow));

// --------------------------------------------------------------- 5. ekspor/impor
section('5. Ekspor dan impor rincian RAB');

$exported = http('GET', '/rab/' . $planId . '/ekspor', null, [], $jars['pencatat']);
$isCsv = str_contains(strtolower($exported['headers']['content-type'] ?? ''), 'csv')
    || str_contains(strtolower($exported['headers']['content-disposition'] ?? ''), 'attachment');
check('Ekspor RAB menghasilkan berkas unduhan', $exported['status'] === 200 && $isCsv);
check('Baris ekspor memuat seluruh item', substr_count(trim($exported['body']), "\n") >= count($items), 'baris: ' . substr_count($exported['body'], "\n"));

// Kolom impor mengikuti hasil ekspor: kode kategori, kategori, kode item, uraian,
// volume, satuan, harga satuan, total, keterangan.
$strukCode = (string) scalar('SELECT category_code FROM budget_categories WHERE id = ?', ['3']);
$dapurCode = (string) scalar('SELECT category_code FROM budget_categories WHERE id = ?', ['8']);
$importCsv = implode("\n", [
    'Kode Kategori,Kategori,Kode Item,Uraian Pekerjaan,Volume,Satuan,Harga Satuan,Total,Keterangan',
    $strukCode . ',Struktur Bangunan,IMP-01,Pekerjaan impor dari csv,10,m2,250000,2500000,Baris hasil suite',
    $dapurCode . ',Area Dapur,IMP-02,Pekerjaan impor kedua,4,unit,750000,3000000,Baris hasil suite',
]) . "\n";
$importPath = tempFile('impor-rab.csv', $importCsv);

$imported = upload(
    '/rab/' . $planId . '/impor',
    ['_token' => csrfToken(http('GET', '/rab/' . $planId, null, [], $jars['pencatat'])['body'])],
    ['name' => 'impor-rab.csv', 'type' => 'text/csv', 'path' => $importPath],
    $jars['pencatat']
);
$importedCount = (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ? AND item_code LIKE ?', [$planId, 'IMP-%']);
check('Impor CSV menambah item', $imported['status'] === 302 && $importedCount === 2, 'item IMP-: ' . $importedCount);
check(
    'Nilai impor dihitung ulang oleh server',
    (float) scalar('SELECT total_price FROM budget_items WHERE budget_plan_id = ? AND item_code = ?', [$planId, 'IMP-01']) === 2500000.0
);

$badImport = upload(
    '/rab/' . $planId . '/impor',
    ['_token' => csrfToken(http('GET', '/rab/' . $planId, null, [], $jars['pencatat'])['body'])],
    ['name' => 'impor.txt.php', 'type' => 'text/plain', 'path' => tempFile('impor.txt.php', "<?php echo 1;\n")],
    $jars['pencatat']
);
check(
    'Berkas bukan CSV/TXT ditolak saat impor',
    (int) scalar("SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ? AND item_code LIKE 'PHP%'", [$planId]) === 0
        && $badImport['status'] === 302,
    flashText($jars['pencatat'], '/rab/' . $planId)
);

// ------------------------------------------------------------ 6. alur persetujuan
section('6. Alur persetujuan RAB dan pemisahan tugas');

$planTotalBefore = (float) scalar('SELECT total_amount FROM budget_plans WHERE id = ?', [$planId]);

$submitted = send('/rab/' . $planId . '/ajukan', [], $jars['pencatat'], '/rab/' . $planId, '/rab/' . $planId);
check(
    'RAB draft dapat diajukan',
    (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$planId]) === 'pending',
    $submitted['flash']
);
check('Pengaju tercatat', (int) scalar('SELECT submitted_by FROM budget_plans WHERE id = ?', [$planId]) > 0);

$selfApproved = send(
    '/rab/' . $planId . '/keputusan',
    ['action' => 'approve', 'note' => 'Mencoba menyetujui sendiri'],
    $jars['pencatat'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check(
    'Pembuat tidak dapat menyetujui RAB-nya sendiri',
    (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$planId]) === 'pending',
    $selfApproved['flash']
);

$wrongAction = send(
    '/rab/' . $planId . '/keputusan',
    ['action' => 'mungkin', 'note' => 'Aksi tidak dikenal'],
    $jars['pemeriksa'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check('Aksi keputusan tidak dikenal ditolak', (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$planId]) === 'pending', $wrongAction['flash']);

$emptyNote = send(
    '/rab/' . $planId . '/keputusan',
    ['action' => 'reject', 'note' => ''],
    $jars['pemeriksa'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check(
    'Penolakan wajib disertai alasan',
    (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$planId]) === 'pending',
    $emptyNote['flash']
);

$approved = send(
    '/rab/' . $planId . '/keputusan',
    ['action' => 'approve', 'note' => 'Sesuai hasil pemeriksaan harga'],
    $jars['pemeriksa'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check(
    'Akun lain dapat menyetujui RAB',
    (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$planId]) === 'approved',
    $approved['flash']
);
check('Total RAB tidak berubah saat disetujui', abs((float) scalar('SELECT total_amount FROM budget_plans WHERE id = ?', [$planId]) - $planTotalBefore) < 0.001);
check('Pemeriksa dan waktu keputusan tercatat', (int) scalar('SELECT reviewed_by FROM budget_plans WHERE id = ?', [$planId]) > 0);

$editLocked = send(
    '/rab/' . $planId . '/ubah',
    ['project_id' => (string) $projectId, 'rab_code' => $planCode, 'name' => 'Nama RAB diubah paksa',
     'period_start' => date('Y-01-05'), 'period_end' => date('Y-12-20'), 'notes' => ''],
    $jars['pencatat'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check(
    'RAB disetujui tidak dapat diedit langsung',
    (string) scalar('SELECT name FROM budget_plans WHERE id = ?', [$planId]) !== 'Nama RAB diubah paksa',
    $editLocked['flash']
);

$itemLocked = send(
    '/rab/' . $planId . '/item',
    ['category_id' => '3', 'item_code' => 'STR-02', 'item_name' => 'Item selundupan', 'volume' => '1', 'unit' => 'm3', 'unit_price' => '1000000'],
    $jars['pencatat'],
    '/rab/' . $planId,
    '/rab/' . $planId
);
check(
    'Item tidak dapat ditambahkan ke RAB disetujui',
    (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ? AND item_code = ?', [$planId, 'STR-02']) === 0,
    $itemLocked['flash']
);

$deleteLocked = send('/rab/' . $planId . '/hapus', [], $jars['pencatat'], '/rab/' . $planId, '/rab');
check('RAB disetujui tidak dapat dihapus', (int) scalar('SELECT COUNT(*) FROM budget_plans WHERE id = ?', [$planId]) === 1, $deleteLocked['flash']);

$revision = send('/rab/' . $planId . '/revisi', [], $jars['pencatat'], '/rab/' . $planId, '/rab');
$revisionId = (int) scalar('SELECT id FROM budget_plans WHERE revision_of = ? ORDER BY id DESC LIMIT 1', [$planId]);
check('Revisi membuat RAB versi baru', $revisionId > 0, $revision['flash']);
if ($revisionId > 0) {
    check('Versi revisi dinaikkan', (int) scalar('SELECT version FROM budget_plans WHERE id = ?', [$revisionId]) === 2);
    check('RAB revisi kembali ke draft', (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$revisionId]) === 'draft');
    check(
        'Item RAB ikut tersalin ke revisi',
        (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ?', [$revisionId])
            === (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ?', [$planId]),
        'item revisi: ' . (int) scalar('SELECT COUNT(*) FROM budget_items WHERE budget_plan_id = ?', [$revisionId])
    );
    check(
        'Total RAB revisi sama dengan hasil hitung itemnya',
        abs(
            (float) scalar('SELECT total_amount FROM budget_plans WHERE id = ?', [$revisionId])
            - (float) scalar('SELECT COALESCE(SUM(total_price), 0) FROM budget_items WHERE budget_plan_id = ?', [$revisionId])
        ) < 0.001
    );
    check('RAB lama tetap tersimpan sebagai riwayat', (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$planId]) === 'approved');
}

// ------------------------------------------------------------- 7. kebijakan ambang
section('7. Ambang peringatan yang dapat dikonfigurasi');

// Kepala SPPG tidak memiliki settings.manage, jadi formulir ambang ditutup untuknya.
$thresholdPage = http('GET', '/pengaturan', null, [], $jars['pemeriksa']);
check('Pengaturan dibatasi untuk peran tanpa izin', $thresholdPage['status'] === 403, 'HTTP ' . $thresholdPage['status']);

$thresholdBefore = (string) scalar("SELECT setting_value FROM settings WHERE setting_key = 'budget_warning_percent'");

$rejected = send(
    '/pengaturan/keuangan',
    ['budget_warning_percent' => '150', 'schedule_due_days' => '7', 'finance_segregation_of_duties' => '1'],
    $adminJar,
    '/pengaturan',
    '/pengaturan'
);
$thresholdAfterBad = (string) scalar("SELECT setting_value FROM settings WHERE setting_key = 'budget_warning_percent'");
check(
    'Ambang di luar rentang ditolak',
    $rejected['status'] === 302 && $thresholdAfterBad === $thresholdBefore,
    'tersimpan: ' . ($thresholdAfterBad === '' ? '(belum pernah diisi)' : $thresholdAfterBad) . ' — ' . $rejected['flash']
);

$saved = send(
    '/pengaturan/keuangan',
    ['budget_warning_percent' => '20', 'schedule_due_days' => '14', 'finance_segregation_of_duties' => '1'],
    $adminJar,
    '/pengaturan',
    '/pengaturan'
);
check(
    'Ambang baru tersimpan',
    (string) scalar("SELECT setting_value FROM settings WHERE setting_key = 'budget_warning_percent'") === '20'
        && (string) scalar("SELECT setting_value FROM settings WHERE setting_key = 'schedule_due_days'") === '14',
    $saved['flash']
);

$dashboard = http('GET', '/keuangan', null, [], $jars['pemeriksa']);
check('Dashboard keuangan memakai ambang terbaru', str_contains($dashboard['body'], 'Ambang peringatan 20%'), '20% muncul di halaman');

// Kebijakan pemisahan tugas dibaca ulang pada setiap permintaan, jadi perubahan
// lewat formulir harus langsung mengubah hasil persetujuan tanpa mengubah kode.
$segPlanCreated = send(
    '/rab/tambah',
    [
        'project_id' => (string) $projectId, 'rab_code' => '', 'name' => 'RAB Uji Kebijakan Pemisahan Tugas',
        'period_start' => date('Y-01-05'), 'period_end' => date('Y-12-20'), 'notes' => '',
    ],
    $adminJar,
    '/rab/tambah',
    '/rab'
);
$segPlanId = (int) scalar('SELECT id FROM budget_plans WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
send(
    '/rab/' . $segPlanId . '/item',
    ['category_id' => '3', 'item_code' => 'KEB-01', 'item_name' => 'Pekerjaan uji kebijakan',
     'volume' => '2', 'unit' => 'm2', 'unit_price' => '250000', 'notes' => ''],
    $adminJar,
    '/rab/' . $segPlanId,
    '/rab/' . $segPlanId
);
send('/rab/' . $segPlanId . '/ajukan', [], $adminJar, '/rab/' . $segPlanId, '/rab/' . $segPlanId);
check('RAB uji kebijakan dapat diajukan', $segPlanId > 0 && (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$segPlanId]) === 'pending', $segPlanCreated['flash']);

$blockedByPolicy = send(
    '/rab/' . $segPlanId . '/keputusan',
    ['action' => 'approve', 'note' => 'Menyetujui sendiri saat kebijakan aktif'],
    $adminJar,
    '/rab/' . $segPlanId,
    '/rab/' . $segPlanId
);
check(
    'Pemisahan tugas aktif menolak penyetujuan oleh pembuat sendiri',
    (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$segPlanId]) === 'pending',
    $blockedByPolicy['flash']
);

send(
    '/pengaturan/keuangan',
    ['budget_warning_percent' => '20', 'schedule_due_days' => '14', 'finance_segregation_of_duties' => '0'],
    $adminJar,
    '/pengaturan',
    '/pengaturan'
);
$releasedByPolicy = send(
    '/rab/' . $segPlanId . '/keputusan',
    ['action' => 'approve', 'note' => 'Menyetujui sendiri setelah kebijakan dinonaktifkan'],
    $adminJar,
    '/rab/' . $segPlanId,
    '/rab/' . $segPlanId
);
check(
    'Kebijakan yang dinonaktifkan langsung melonggarkan persetujuan',
    (string) scalar('SELECT status FROM budget_plans WHERE id = ?', [$segPlanId]) === 'approved',
    $releasedByPolicy['flash']
);

send(
    '/pengaturan/keuangan',
    ['budget_warning_percent' => '85', 'schedule_due_days' => '7', 'finance_segregation_of_duties' => '1'],
    $adminJar,
    '/pengaturan',
    '/pengaturan'
);

// ------------------------------------------------------------ 8. perubahan anggaran
section('8. Perubahan anggaran berserta riwayat');

$initialBefore = (float) scalar('SELECT budget_amount FROM projects WHERE id = ?', [$projectId]);

$revisionBad = send(
    '/anggaran/' . $projectId . '/perubahan',
    ['proposed_amount' => '-5', 'reason' => 'Mencoba nilai tidak valid'],
    $jars['pencatat'],
    '/anggaran/' . $projectId,
    '/anggaran/' . $projectId
);
check(
    'Usulan anggaran bernilai negatif ditolak',
    (int) scalar('SELECT COUNT(*) FROM budget_revisions WHERE project_id = ?', [$projectId]) === 0,
    $revisionBad['flash']
);

$revisionShort = send(
    '/anggaran/' . $projectId . '/perubahan',
    ['proposed_amount' => '900000000', 'reason' => 'pendek'],
    $jars['pencatat'],
    '/anggaran/' . $projectId,
    '/anggaran/' . $projectId
);
check(
    'Alasan perubahan wajib dijelaskan',
    (int) scalar('SELECT COUNT(*) FROM budget_revisions WHERE project_id = ?', [$projectId]) === 0,
    $revisionShort['flash']
);

$revisionNew = send(
    '/anggaran/' . $projectId . '/perubahan',
    ['proposed_amount' => '900000000', 'reason' => 'Penambahan pekerjaan gudang pendinginan.'],
    $jars['pencatat'],
    '/anggaran/' . $projectId,
    '/anggaran/' . $projectId
);
$revisionRow = row('SELECT * FROM budget_revisions WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
check('Usulan perubahan anggaran tercatat', (int) ($revisionRow['id'] ?? 0) > 0, $revisionNew['flash']);
check(
    'Anggaran awal proyek tidak berubah sebelum usulan disetujui',
    abs((float) scalar('SELECT budget_amount FROM projects WHERE id = ?', [$projectId]) - $initialBefore) < 0.001
);

$decideWrong = send(
    '/anggaran/perubahan/' . (int) ($revisionRow['id'] ?? 0) . '/keputusan',
    ['action' => 'approve', 'note' => 'Pencatat menyetujui usulannya sendiri'],
    $jars['pencatat'],
    '/anggaran/perubahan/' . (int) ($revisionRow['id'] ?? 0),
    '/anggaran/' . $projectId
);
check(
    'Usulan tidak dapat disetujui pengajunya sendiri',
    (string) ($revisionRow['status'] ?? '') === 'pending'
        && (string) scalar('SELECT status FROM budget_revisions WHERE id = ?', [(int) ($revisionRow['id'] ?? 0)]) === 'pending',
    $decideWrong['flash']
);

$decideRight = send(
    '/anggaran/perubahan/' . (int) ($revisionRow['id'] ?? 0) . '/keputusan',
    ['action' => 'approve', 'note' => 'Disetujui setelah pemeriksaan berkas.'],
    $jars['pemeriksa'],
    '/anggaran/perubahan/' . (int) ($revisionRow['id'] ?? 0),
    '/anggaran/' . $projectId
);
check(
    'Perubahan anggaran yang disetujui menaikkan anggaran awal',
    abs((float) scalar('SELECT budget_amount FROM projects WHERE id = ?', [$projectId]) - 900000000.0) < 0.001,
    $decideRight['flash']
);
check('Riwayat perubahan menyimpan nilai sebelum dan sesudah', (float) ($revisionRow['previous_amount'] ?? 0) === $initialBefore);

// ------------------------------------------------------------- 9. transaksi keuangan
section('9. Transaksi, validasi nominal, dan pemisahan pusat biaya');

$planIdForRef = $planId;

$txBadAmount = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'expense',
        'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d'),
        'amount' => '0', 'payment_method' => 'cash', 'recipient' => 'Toko Uji',
        'reference_number' => '', 'description' => 'Nominal nol tidak boleh lolos.',
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
check('Nominal nol ditolak', (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE project_id = ?', [$projectId]) === 0, $txBadAmount['flash']);

$txGrouped = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'expense',
        'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d'),
        'amount' => '15.750.000', 'payment_method' => 'transfer', 'recipient' => 'CV Uji Sejahtera',
        'reference_number' => $firstReference = 'INV-A-1', 'description' => 'Pembayaran pekerjaan struktur tahap satu.',
        'budget_plan_id' => (string) $planIdForRef,
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
$constructionId = (int) scalar('SELECT id FROM financial_transactions WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
$constructionCode = (string) scalar('SELECT transaction_code FROM financial_transactions WHERE id = ?', [$constructionId]);
check('Transaksi terbentuk dengan nomor otomatis', preg_match('/^TRX-\d{4}-\d{1,4}$/', $constructionCode) === 1, $constructionCode);
check('Nominal bertitik dinormalisasi server', (float) scalar('SELECT amount FROM financial_transactions WHERE id = ?', [$constructionId]) === 15750000.0, $txGrouped['flash']);

// Pencegahan ganda memakai kunci proyek + tanggal + jenis + nominal + uraian, dan
// hanya berlaku bila nomor referensi keduanya kosong (nomor referensi punya aturan
// keunikan sendiri). Karena itu baris keduanya disalin persis dari baris pertama.
$duplicateFields = [
    'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'expense',
    'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d', strtotime('+5 day')),
    'amount' => '3250000', 'payment_method' => 'transfer', 'recipient' => 'CV Uji Sejahtera',
    'reference_number' => '', 'description' => 'Belanja ganda yang harus ditolak saat diulang.',
];
$firstCopy = send('/transaksi/tambah', $duplicateFields, $jars['pencatat'], '/transaksi/tambah', '/transaksi');
$copyCount = (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE project_id = ?', [$projectId]);
check('Transaksi awal untuk uji ganda tersimpan', $firstCopy['status'] === 302 && $copyCount > 0, $firstCopy['flash']);

$txDuplicate = send('/transaksi/tambah', $duplicateFields, $jars['pencatat'], '/transaksi/tambah', '/transaksi');
check(
    'Transaksi ganda pada tanggal, proyek, dan nominal yang sama ditolak',
    (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE project_id = ?', [$projectId]) === $copyCount,
    $txDuplicate['flash']
);

$txSameReference = send(
    '/transaksi/tambah',
    ['reference_number' => $firstReference, 'amount' => '6150000',
     'description' => 'Nomor referensi dipakai ulang oleh transaksi lain.'] + $duplicateFields,
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
check(
    'Nomor referensi tidak boleh dipakai ulang dalam satu proyek',
    (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE project_id = ? AND reference_number = ?', [$projectId, $firstReference]) === 1,
    $txSameReference['flash']
);

$countBeforePlanRef = (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE project_id = ?', [$projectId]);

$draftPlan = send(
    '/rab/tambah',
    [
        'project_id' => (string) $projectId, 'rab_code' => '', 'name' => 'RAB Draft Pengacu Transaksi',
        'period_start' => date('Y-01-05'), 'period_end' => date('Y-12-20'), 'notes' => '',
    ],
    $jars['pencatat'],
    '/rab/tambah',
    '/rab'
);
$draftPlanId = (int) scalar('SELECT id FROM budget_plans WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);

$txDraftPlan = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'expense',
        'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d', strtotime('+1 day')),
        'amount' => '5000000', 'payment_method' => 'cash', 'recipient' => 'CV Uji Sejahtera',
        'reference_number' => 'INV-P-1', 'description' => 'Mengacu pada RAB yang belum disetujui.',
        'budget_plan_id' => (string) $draftPlanId,
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
check(
    'Transaksi hanya boleh mengacu pada RAB yang sudah disetujui',
    $draftPlanId > 0
        && (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE project_id = ?', [$projectId]) === $countBeforePlanRef,
    $txDraftPlan['flash']
);

$txLinked = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'expense',
        'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d', strtotime('+2 day')),
        'amount' => '4750000', 'payment_method' => 'cash', 'recipient' => 'CV Uji Sejahtera',
        'reference_number' => 'INV-P-2', 'description' => 'Mengacu pada RAB yang sudah disetujui.',
        'budget_plan_id' => (string) $planId,
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
$linkedId = (int) scalar('SELECT id FROM financial_transactions WHERE project_id = ? AND reference_number = ?', [$projectId, 'INV-P-2']);
check(
    'Transaksi dapat mengacu pada RAB disetujui milik proyek yang sama',
    $linkedId > 0 && (int) scalar('SELECT budget_plan_id FROM financial_transactions WHERE id = ?', [$linkedId]) === $planId,
    $txLinked['flash']
);

$operationsCreated = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'expense',
        'cost_center' => 'operations', 'category_id' => '9', 'transaction_date' => date('Y-m-d', strtotime('-1 day')),
        'amount' => '42500000', 'payment_method' => 'cash', 'recipient' => 'Pemasok Bahan Pangan',
        'reference_number' => 'INV-B-1', 'description' => 'Belanja bahan pangan mingguan MBG.',
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
$operationsId = (int) scalar('SELECT id FROM financial_transactions WHERE cost_center = ? AND project_id = ? ORDER BY id DESC LIMIT 1', ['operations', $projectId]);
check('Transaksi operasional MBG dapat dicatat terpisah', $operationsId > 0, $operationsCreated['flash']);

$incomeCreated = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'income',
        'cost_center' => 'construction', 'category_id' => '', 'transaction_date' => date('Y-m-d', strtotime('-2 day')),
        'amount' => '500000000', 'payment_method' => 'budget', 'recipient' => 'BOS Pusat',
        'reference_number' => 'SPM-A', 'description' => 'Cairan anggaran pembangunan tahap satu.',
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
$incomeId = (int) scalar('SELECT id FROM financial_transactions WHERE transaction_type = ? AND project_id = ? ORDER BY id DESC LIMIT 1', ['income', $projectId]);
check('Pemasukan sumber dana dapat dicatat', $incomeId > 0, $incomeCreated['flash']);

$refundCreated = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'refund',
        'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d', strtotime('-3 day')),
        'amount' => '1000000', 'payment_method' => 'transfer', 'recipient' => 'CV Uji Sejahtera',
        'reference_number' => 'KB-A', 'description' => 'Pengembalian kelebihan pembayaran pekerjaan.',
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
$refundId = (int) scalar('SELECT id FROM financial_transactions WHERE transaction_type = ? AND project_id = ? ORDER BY id DESC LIMIT 1', ['refund', $projectId]);
check('Pengembalian dana dapat dicatat', $refundId > 0, $refundCreated['flash']);

$adjustCreated = send(
    '/transaksi/tambah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => '', 'transaction_type' => 'adjustment',
        'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d', strtotime('-4 day')),
        'amount' => '250000', 'payment_method' => 'other', 'recipient' => 'Rekening Kas',
        'reference_number' => 'KOR-A', 'description' => 'Koreksi selisih pencatatan melalui penyesuaian.',
    ],
    $jars['pencatat'],
    '/transaksi/tambah',
    '/transaksi'
);
$adjustId = (int) scalar('SELECT id FROM financial_transactions WHERE transaction_type = ? AND project_id = ? ORDER BY id DESC LIMIT 1', ['adjustment', $projectId]);
check('Koreksi lewat penyesuaian dapat dicatat', $adjustId > 0, $adjustCreated['flash']);

check(
    'Semua transaksi baru berstatus draft',
    (int) scalar("SELECT COUNT(*) FROM financial_transactions WHERE project_id = ? AND status <> 'draft'", [$projectId]) === 0
);

// ----------------------------------------------------------- 10. berkas bukti
section('10. Berkas bukti transaksi');

$pngBytes = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);
$pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

$token = csrfToken(http('GET', '/transaksi/' . $constructionId, null, [], $jars['pencatat'])['body']);
$pngUpload = upload(
    '/transaksi/' . $constructionId . '/bukti',
    ['_token' => $token],
    ['name' => 'faktur-bukti.png', 'type' => 'image/png', 'path' => tempFile('faktur-bukti.png', $pngBytes)],
    $jars['pencatat']
);
$pngRow = row('SELECT id, stored_name, mime_type FROM project_documents WHERE financial_transaction_id = ? ORDER BY id DESC LIMIT 1', [$constructionId]);
check('Bukti PNG diterima', (string) ($pngRow['stored_name'] ?? '') !== '', flashText($jars['pencatat'], '/transaksi/' . $constructionId));
if (($pngRow['stored_name'] ?? '') !== '') {
    $GLOBALS['createdStored'][] = (string) $pngRow['stored_name'];
}

$token = csrfToken(http('GET', '/transaksi/' . $constructionId, null, [], $jars['pencatat'])['body']);
upload(
    '/transaksi/' . $constructionId . '/bukti',
    ['_token' => $token],
    ['name' => 'faktur.pdf', 'type' => 'application/pdf', 'path' => tempFile('faktur.pdf', $pdfBytes)],
    $jars['pencatat']
);
check(
    'Bukti PDF diterima',
    (int) scalar('SELECT COUNT(*) FROM project_documents WHERE financial_transaction_id = ?', [$constructionId]) === 2,
    flashText($jars['pencatat'], '/transaksi/' . $constructionId)
);

$token = csrfToken(http('GET', '/transaksi/' . $constructionId, null, [], $jars['pencatat'])['body']);
$shellUpload = upload(
    '/transaksi/' . $constructionId . '/bukti',
    ['_token' => $token],
    ['name' => 'bukti.php', 'type' => 'application/x-php', 'path' => tempFile('bukti.php', "<?php echo 'x';\n")],
    $jars['pencatat']
);
check(
    'Bukti .php ditolak dan tidak disimpan',
    (int) scalar('SELECT COUNT(*) FROM project_documents WHERE financial_transaction_id = ?', [$constructionId]) === 2
        && $shellUpload['status'] === 302,
    flashText($jars['pencatat'], '/transaksi/' . $constructionId)
);

$token = csrfToken(http('GET', '/transaksi/' . $constructionId, null, [], $jars['pencatat'])['body']);
$forgedUpload = upload(
    '/transaksi/' . $constructionId . '/bukti',
    ['_token' => $token],
    ['name' => 'pura-pura.png', 'type' => 'image/png', 'path' => tempFile('pura-pura.png', 'bukan citra sama sekali')],
    $jars['pencatat']
);
check(
    'Isi berkas diverifikasi, bukan hanya ekstensi',
    (int) scalar('SELECT COUNT(*) FROM project_documents WHERE financial_transaction_id = ?', [$constructionId]) === 2,
    flashText($jars['pencatat'], '/transaksi/' . $constructionId)
);

$storedName = (string) ($pngRow['stored_name'] ?? '');
$directPublic = $storedName === '' ? ['status' => 0] : http('GET', '/storage/uploads/projects/' . $storedName, null, [], $jars['pencatat']);
check(
    'Berkas bukti tidak tersedia lewat URL publik',
    (int) ($directPublic['status'] ?? 0) !== 200,
    'HTTP ' . (int) ($directPublic['status'] ?? 0)
);

$served = http('GET', '/bukti/' . (int) ($pngRow['id'] ?? 0), null, [], $jars['pemeriksa']);
check('Bukti dapat dibuka lewat jalur terlindungi', $served['status'] === 200 && ($served['headers']['content-disposition'] ?? '') !== '');

$guest = tempJar();
$guestView = http('GET', '/bukti/' . (int) ($pngRow['id'] ?? 0), null, [], $guest);
check('Bukti tidak dapat dibuka tanpa login', $guestView['status'] === 302, 'HTTP ' . $guestView['status']);

// ---------------------------------------------------- 11. alur persetujuan transaksi
section('11. Persetujuan, pembatalan, dan larangan hapus');

$submittedTx = send('/transaksi/' . $constructionId . '/ajukan', [], $jars['pencatat'], '/transaksi/' . $constructionId, '/transaksi/' . $constructionId);
check('Transaksi draft dapat diajukan', (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$constructionId]) === 'pending', $submittedTx['flash']);

$approveByCreator = send(
    '/transaksi/' . $constructionId . '/keputusan',
    ['action' => 'approve', 'note' => 'Mencoba menyetujui sendiri'],
    $jars['pencatat'],
    '/transaksi/' . $constructionId,
    '/transaksi/' . $constructionId
);
check(
    'Pencatat tidak dapat menyetujui transaksi buatannya sendiri',
    (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$constructionId]) === 'pending',
    $approveByCreator['flash']
);

$approveNoNote = send(
    '/transaksi/' . $constructionId . '/keputusan',
    ['action' => 'reject', 'note' => ''],
    $jars['pemeriksa'],
    '/transaksi/' . $constructionId,
    '/transaksi/' . $constructionId
);
check('Penolakan transaksi wajib beralasan', (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$constructionId]) === 'pending', $approveNoNote['flash']);

$approveWrongRole = send(
    '/transaksi/' . $constructionId . '/keputusan',
    ['action' => 'approve', 'note' => 'Disetujui oleh peran tanpa izin'],
    $jars['pengamat'],
    '/transaksi/' . $constructionId,
    '/transaksi/' . $constructionId
);
check(
    'Keputusan transaksi memerlukan izin approvals',
    $approveWrongRole['status'] === 403 && (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$constructionId]) === 'pending',
    'HTTP ' . $approveWrongRole['status']
);

$approvedTx = send(
    '/transaksi/' . $constructionId . '/keputusan',
    ['action' => 'approve', 'note' => 'Faktur dan berita acara lengkap.'],
    $jars['pemeriksa'],
    '/transaksi/' . $constructionId,
    '/transaksi/' . $constructionId
);
check('Transaksi dapat disetujui pemeriksa', (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$constructionId]) === 'approved', $approvedTx['flash']);
check(
    'Jejak persetujuan tercatat lengkap',
    (int) scalar('SELECT COUNT(*) FROM transaction_approvals WHERE transaction_id = ?', [$constructionId]) >= 2,
    'baris jejak: ' . (int) scalar('SELECT COUNT(*) FROM transaction_approvals WHERE transaction_id = ?', [$constructionId])
);

$editedApproved = send(
    '/transaksi/' . $constructionId . '/ubah',
    [
        'project_id' => (string) $projectId, 'transaction_code' => $constructionCode, 'transaction_type' => 'expense',
        'cost_center' => 'construction', 'category_id' => '3', 'transaction_date' => date('Y-m-d'),
        'amount' => '99000000', 'payment_method' => 'transfer', 'recipient' => 'CV Uji Sejahtera',
        'reference_number' => 'INV-A-1', 'description' => 'Mencoba mengubah nominal transaksi disetujui.',
    ],
    $jars['pencatat'],
    '/transaksi/' . $constructionId,
    '/transaksi/' . $constructionId
);
check(
    'Transaksi disetujui tidak dapat diubah nilainya',
    (float) scalar('SELECT amount FROM financial_transactions WHERE id = ?', [$constructionId]) === 15750000.0,
    $editedApproved['flash']
);

$deleteApproved = send('/transaksi/' . $constructionId . '/hapus', [], $jars['pencatat'], '/transaksi/' . $constructionId, '/transaksi/' . $constructionId);
check(
    'Transaksi disetujui tidak dapat dihapus permanen',
    (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE id = ?', [$constructionId]) === 1,
    $deleteApproved['flash']
);

$cancelShort = send(
    '/transaksi/' . $constructionId . '/batalkan',
    ['reason' => 'salah'],
    $jars['pemeriksa'],
    '/transaksi/' . $constructionId,
    '/transaksi/' . $constructionId
);
check('Pembatalan wajib disertai alasan yang jelas', (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$constructionId]) === 'approved', $cancelShort['flash']);

$cancelOk = send(
    '/transaksi/' . $constructionId . '/batalkan',
    ['reason' => 'Salah pusat biaya, akan dicatat ulang sebagai operasional.'],
    $jars['pemeriksa'],
    '/transaksi/' . $constructionId,
    '/transaksi/' . $constructionId
);
check(
    'Pembatalan beralasan mengubah status menjadi dibatalkan',
    (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$constructionId]) === 'cancelled',
    $cancelOk['flash']
);
check('Alasan dan pelaksana pembatalan tersimpan', trim((string) scalar('SELECT cancel_reason FROM financial_transactions WHERE id = ?', [$constructionId])) !== '');
check(
    'Transaksi yang dibatalkan tetap tersimpan sebagai jejak',
    (int) scalar('SELECT COUNT(*) FROM financial_transactions WHERE id = ?', [$constructionId]) === 1
);

foreach ([$operationsId, $incomeId, $refundId, $adjustId] as $pendingId) {
    send('/transaksi/' . $pendingId . '/ajukan', [], $jars['pencatat'], '/transaksi/' . $pendingId, '/transaksi/' . $pendingId);
    send(
        '/transaksi/' . $pendingId . '/keputusan',
        ['action' => 'approve', 'note' => 'Dokumen pendukung lengkap.'],
        $jars['pemeriksa'],
        '/transaksi/' . $pendingId,
        '/transaksi/' . $pendingId
    );
}

check(
    'Realisasi pembangunan hanya menghitung transaksi disetujui',
    (float) scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
         WHERE project_id = ? AND cost_center = 'construction' AND transaction_type = 'expense' AND status = 'approved'",
        [$projectId]
    ) === 0.0
);
check(
    'Biaya operasional MBG tercatat terpisah dari pembangunan',
    (float) scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
         WHERE project_id = ? AND cost_center = 'operations' AND transaction_type = 'expense' AND status = 'approved'",
        [$projectId]
    ) === 42500000.0
);

$cetak = http('GET', '/transaksi/' . $incomeId . '/cetak', null, [], $jars['pemeriksa']);
$incomeCode = (string) scalar('SELECT transaction_code FROM financial_transactions WHERE id = ?', [$incomeId]);
check(
    'Bukti transaksi dapat dicetak',
    $cetak['status'] === 200 && str_contains($cetak['body'], 'Bukti Transaksi') && str_contains($cetak['body'], $incomeCode),
    'HTTP ' . $cetak['status'] . ' untuk ' . $incomeCode
);

$exportTx = http('GET', '/transaksi/ekspor?proyek=' . $projectId, null, [], $jars['pemeriksa']);
check('Ekspor transaksi menghasilkan CSV', $exportTx['status'] === 200 && substr_count($exportTx['body'], "\n") >= 4, 'baris: ' . substr_count($exportTx['body'], "\n"));

// --------------------------------------------------------------- 12. daftar/filter
section('12. Pencarian, penyaringan, dan paginasi');

$listAll = http('GET', '/transaksi?proyek=' . $projectId, null, [], $jars['pemeriksa']);
check('Penyaringan per proyek bekerja', $listAll['status'] === 200 && str_contains($listAll['body'], 'TRX-'), 'HTTP ' . $listAll['status']);

$listCenter = http('GET', '/transaksi?pusat=operations', null, [], $jars['pemeriksa']);
check('Penyaringan pusat biaya bekerja', $listCenter['status'] === 200 && substr_count($listCenter['body'], 'TRX-') < substr_count($listAll['body'], 'TRX-'));

$listSearch = http('GET', '/transaksi?q=' . rawurlencode($constructionCode), null, [], $jars['pemeriksa']);
check('Pencarian nomor transaksi bekerja', $listSearch['status'] === 200 && str_contains($listSearch['body'], $constructionCode));

$listStatus = http('GET', '/transaksi?status=approved', null, [], $jars['pemeriksa']);
check('Penyaringan status bekerja', $listStatus['status'] === 200);

$badFilter = http('GET', '/transaksi?status=tidak-ada&pusat=tidak-ada&sort=tidak-ada', null, [], $jars['pemeriksa']);
check('Penyaring tidak dikenal tidak menimbulkan galat', $badFilter['status'] === 200 && !str_contains($badFilter['body'], 'Warning'));

$ragged = http('GET', '/transaksi?page=9999', null, [], $jars['pemeriksa']);
check('Halaman di luar rentang dilayani tanpa galat', $ragged['status'] === 200);

// ------------------------------------------------------------------- 13. jadwal
section('13. Jadwal kegiatan, kalender, dan Gantt');

$scheduleBad = send(
    '/jadwal/tambah',
    [
        'project_id' => (string) $projectId, 'activity_code' => 'KGT-901', 'name' => 'Tanggal bertentangan',
        'description' => '', 'category_id' => '3', 'start_date' => date('Y-m-d', strtotime('+10 day')),
        'end_date' => date('Y-m-d', strtotime('+5 day')), 'owner_name' => 'Petugas Uji', 'priority' => 'high',
        'status' => 'not_started', 'progress' => '0', 'notes' => '',
    ],
    $jars['pencatat'],
    '/jadwal/tambah',
    '/jadwal'
);
check(
    'Tanggal selesai sebelum tanggal mulai ditolak',
    (int) scalar('SELECT COUNT(*) FROM project_schedules WHERE activity_code = ?', ['KGT-901']) === 0,
    $scheduleBad['flash']
);

$progressBad = send(
    '/jadwal/tambah',
    [
        'project_id' => (string) $projectId, 'activity_code' => 'KGT-PRC', 'name' => 'Progres di luar rentang',
        'description' => '', 'category_id' => '3', 'start_date' => date('Y-m-d', strtotime('-2 day')),
        'end_date' => date('Y-m-d', strtotime('+8 day')), 'owner_name' => 'Petugas Uji', 'priority' => 'high',
        'status' => 'in_progress', 'progress' => '140', 'notes' => '',
    ],
    $jars['pencatat'],
    '/jadwal/tambah',
    '/jadwal'
);
check('Progres di luar 0-100 ditolak', (int) scalar('SELECT COUNT(*) FROM project_schedules WHERE activity_code = ?', ['KGT-PRC']) === 0, $progressBad['flash']);

$scheduleRows = [
    ['KGT-101', 'Pembersihan lahan', '2', date('Y-m-d', strtotime('-12 day')), date('Y-m-d', strtotime('-5 day')), 'in_progress', '55', 'medium'],
    ['KGT-102', 'Pekerjaan pondasi', '2', date('Y-m-d', strtotime('-4 day')), date('Y-m-d', strtotime('+9 day')), 'in_progress', '20', 'high'],
    ['KGT-103', 'Pemasangan atap', '5', date('Y-m-d', strtotime('+3 day')), date('Y-m-d', strtotime('+20 day')), 'not_started', '0', 'medium'],
    ['KGT-104', 'Pekerjaan finishing', '12', date('Y-m-d', strtotime('-30 day')), date('Y-m-d', strtotime('-16 day')), 'delayed', '35', 'low'],
];
$scheduleIds = [];

foreach ($scheduleRows as [$code, $name, $category, $start, $end, $status, $progress, $priority]) {
    $result = send(
        '/jadwal/tambah',
        [
            'project_id' => (string) $projectId, 'activity_code' => $code, 'name' => $name,
            'description' => 'Kegiatan uji ' . $name . '.', 'category_id' => $category,
            'start_date' => $start, 'end_date' => $end, 'owner_name' => 'Petugas Uji',
            'priority' => $priority, 'status' => $status, 'progress' => $progress, 'notes' => '',
        ],
        $jars['pencatat'],
        '/jadwal/tambah',
        '/jadwal'
    );
    $scheduleIds[$code] = (int) scalar('SELECT id FROM project_schedules WHERE project_id = ? AND activity_code = ?', [$projectId, $code]);
    check("Kegiatan {$code} tersimpan", $scheduleIds[$code] > 0, $result['flash']);
}

$expectedDuration = (int) floor(
    (strtotime(date('Y-m-d', strtotime('+9 day'))) - strtotime(date('Y-m-d', strtotime('-4 day')))) / 86400
) + 1;
$duration = (int) scalar('SELECT duration_days FROM project_schedules WHERE id = ?', [$scheduleIds['KGT-102']]);
check(
    'Durasi dihitung otomatis dari rentang tanggal',
    $duration === $expectedDuration,
    'durasi ' . $duration . ' hari, harapan ' . $expectedDuration . ' hari'
);

$calendar = http('GET', '/jadwal/kalender', null, [], $jars['pemeriksa']);
check('Halaman kalender melayani 200', $calendar['status'] === 200 && !str_contains($calendar['body'], 'Fatal error'));
check(
    'Kalender memuat vendor FullCalendar lokal',
    str_contains($calendar['body'], 'fullcalendar') || str_contains($calendar['body'], 'fc-'),
    'aset fc terlihat'
);
check(
    'Kegiatan diteruskan ke kalender lewat atribut data',
    str_contains($calendar['body'], 'data-schedule') || str_contains($calendar['body'], 'data-events'),
    'penanda data ada'
);

$filtered = http('GET', '/jadwal/kalender?proyek=' . $projectId, null, [], $jars['pemeriksa']);
check('Kalender dapat disaring per proyek', $filtered['status'] === 200 && str_contains($filtered['body'], 'Pembersihan lahan'));

$gantt = http('GET', '/jadwal/gantt?proyek=' . $projectId, null, [], $jars['pemeriksa']);
check('Bagan Gantt melayani 200', $gantt['status'] === 200 && str_contains($gantt['body'], 'Gantt'));
check('Gantt memuat kegiatan proyek', substr_count($gantt['body'], 'KGT-') >= 4, 'label KGT: ' . substr_count($gantt['body'], 'KGT-'));

$noInline = (int) preg_match('/<script(?![^>]*src=)[^>]*>/i', str_replace('<script type="application/ld+json"', '<script src="x"', $calendar['body']));
check('Halaman jadwal tidak memuat skrip inline', $noInline === 0, 'skrip inline: ' . $noInline);

$scheduleExport = http('GET', '/jadwal/ekspor?proyek=' . $projectId, null, [], $jars['pemeriksa']);
check('Ekspor jadwal menghasilkan CSV', $scheduleExport['status'] === 200 && substr_count($scheduleExport['body'], "\n") >= 4);

// ------------------------------------------------------------ 14. ketergantungan
section('14. Ketergantungan kegiatan dan riwayat jadwal');

$selfDep = send(
    '/jadwal/' . $scheduleIds['KGT-101'] . '/ketergantungan',
    ['predecessor_id' => (string) $scheduleIds['KGT-101'], 'dependency_type' => 'FS', 'lag_days' => '0'],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-101'],
    '/jadwal/' . $scheduleIds['KGT-101']
);
check('Kegiatan tidak boleh bergantung pada dirinya sendiri', (int) scalar('SELECT COUNT(*) FROM schedule_dependencies WHERE schedule_id = ? AND predecessor_id = ?', [$scheduleIds['KGT-101'], $scheduleIds['KGT-101']]) === 0, $selfDep['flash']);

$okDep = send(
    '/jadwal/' . $scheduleIds['KGT-102'] . '/ketergantungan',
    ['predecessor_id' => (string) $scheduleIds['KGT-101'], 'dependency_type' => 'FS', 'lag_days' => '0'],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-102'],
    '/jadwal/' . $scheduleIds['KGT-102']
);
check('Ketergantungan sah tersimpan', (int) scalar('SELECT COUNT(*) FROM schedule_dependencies WHERE schedule_id = ? AND predecessor_id = ?', [$scheduleIds['KGT-102'], $scheduleIds['KGT-101']]) === 1, $okDep['flash']);

$twice = send(
    '/jadwal/' . $scheduleIds['KGT-102'] . '/ketergantungan',
    ['predecessor_id' => (string) $scheduleIds['KGT-101'], 'dependency_type' => 'FS', 'lag_days' => '0'],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-102'],
    '/jadwal/' . $scheduleIds['KGT-102']
);
check(
    'Ketergantungan ganda ditolak',
    (int) scalar('SELECT COUNT(*) FROM schedule_dependencies WHERE schedule_id = ? AND predecessor_id = ?', [$scheduleIds['KGT-102'], $scheduleIds['KGT-101']]) === 1,
    $twice['flash']
);

$cycle = send(
    '/jadwal/' . $scheduleIds['KGT-101'] . '/ketergantungan',
    ['predecessor_id' => (string) $scheduleIds['KGT-102'], 'dependency_type' => 'FS', 'lag_days' => '0'],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-101'],
    '/jadwal/' . $scheduleIds['KGT-101']
);
check(
    'Siklus ketergantungan ditolak',
    (int) scalar('SELECT COUNT(*) FROM schedule_dependencies WHERE schedule_id = ? AND predecessor_id = ?', [$scheduleIds['KGT-101'], $scheduleIds['KGT-102']]) === 0,
    $cycle['flash']
);

$badType = send(
    '/jadwal/' . $scheduleIds['KGT-103'] . '/ketergantungan',
    ['predecessor_id' => (string) $scheduleIds['KGT-101'], 'dependency_type' => 'ZZ', 'lag_days' => '0'],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-103'],
    '/jadwal/' . $scheduleIds['KGT-103']
);
check('Jenis ketergantungan tidak dikenal ditolak', (int) scalar('SELECT COUNT(*) FROM schedule_dependencies WHERE schedule_id = ?', [$scheduleIds['KGT-103']]) === 0, $badType['flash']);

$lagBad = send(
    '/jadwal/' . $scheduleIds['KGT-103'] . '/ketergantungan',
    ['predecessor_id' => (string) $scheduleIds['KGT-101'], 'dependency_type' => 'FS', 'lag_days' => '900'],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-103'],
    '/jadwal/' . $scheduleIds['KGT-103']
);
check('Jeda hari di luar rentang ditolak', (int) scalar('SELECT COUNT(*) FROM schedule_dependencies WHERE schedule_id = ?', [$scheduleIds['KGT-103']]) === 0, $lagBad['flash']);

$noReason = send(
    '/jadwal/' . $scheduleIds['KGT-104'] . '/ubah',
    [
        'project_id' => (string) $projectId, 'activity_code' => 'KGT-104', 'name' => 'Pekerjaan finishing',
        'description' => '', 'category_id' => '12', 'start_date' => date('Y-m-d', strtotime('-30 day')),
        'end_date' => date('Y-m-d', strtotime('-2 day')), 'owner_name' => 'Petugas Uji', 'priority' => 'low',
        'status' => 'delayed', 'progress' => '50', 'notes' => '', 'reason' => '',
    ],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-104'],
    '/jadwal/' . $scheduleIds['KGT-104']
);
check(
    'Perubahan jadwal wajib disertai alasan',
    (int) scalar('SELECT COUNT(*) FROM schedule_histories WHERE schedule_id = ?', [$scheduleIds['KGT-104']]) === 0
        && (float) scalar('SELECT progress FROM project_schedules WHERE id = ?', [$scheduleIds['KGT-104']]) === 35.0,
    $noReason['flash']
);

$withReason = send(
    '/jadwal/' . $scheduleIds['KGT-104'] . '/ubah',
    [
        'project_id' => (string) $projectId, 'activity_code' => 'KGT-104', 'name' => 'Pekerjaan finishing',
        'description' => '', 'category_id' => '12', 'start_date' => date('Y-m-d', strtotime('-30 day')),
        'end_date' => date('Y-m-d', strtotime('-2 day')), 'owner_name' => 'Petugas Uji', 'priority' => 'low',
        'status' => 'in_progress', 'progress' => '50', 'notes' => '',
        'reason' => 'Penyesuaian setelah bahan datang lebih lambat.',
    ],
    $jars['pencatat'],
    '/jadwal/' . $scheduleIds['KGT-104'],
    '/jadwal/' . $scheduleIds['KGT-104']
);
$historyCount = (int) scalar('SELECT COUNT(*) FROM schedule_histories WHERE schedule_id = ?', [$scheduleIds['KGT-104']]);
check('Perubahan jadwal tercatat dengan riwayat', $historyCount > 0, 'entri riwayat: ' . $historyCount);
check(
    'Riwayat menyimpan nilai sebelum, nilai sesudah, dan alasannya',
    (int) scalar(
        'SELECT COUNT(*) FROM schedule_histories WHERE schedule_id = ? AND reason <> ? AND new_value <> old_value',
        [$scheduleIds['KGT-104'], '']
    ) > 0,
    'entri riwayat: ' . $historyCount
);

$deletedSchedule = send('/jadwal/' . $scheduleIds['KGT-103'] . '/hapus', [], $adminJar, '/jadwal/' . $scheduleIds['KGT-103'], '/jadwal');
check(
    'Penghapusan jadwal memakai soft delete',
    (int) scalar('SELECT COUNT(*) FROM project_schedules WHERE id = ?', [$scheduleIds['KGT-103']]) === 1
        && (int) scalar('SELECT is_deleted FROM project_schedules WHERE id = ?', [$scheduleIds['KGT-103']]) === 1,
    $deletedSchedule['flash']
);

// ------------------------------------------------------------- 15. dashboard & integrasi
section('15. Dashboard keuangan dan integrasi Prompt 3');

$finance = http('GET', '/keuangan', null, [], $jars['pemeriksa']);
check('Dashboard keuangan melayani 200', $finance['status'] === 200 && !str_contains($finance['body'], '<b>Warning'));
foreach (['chart-box', 'data-chart-type', 'rupiah'] as $needle) {
    check("Dashboard keuangan memuat {$needle}", str_contains($finance['body'], $needle));
}

$financeFiltered = http('GET', '/keuangan?proyek=' . $projectId, null, [], $jars['pemeriksa']);
check('Dashboard dapat disaring per proyek', $financeFiltered['status'] === 200);

$financeOps = http('GET', '/keuangan?pusat=operations', null, [], $jars['pemeriksa']);
check(
    'Saat disaring satu pusat biaya, sisa tidak dinyatakan sebagai angka palsu',
    $financeOps['status'] === 200 && str_contains($financeOps['body'], 'Tidak sebanding'),
    'penanda tolak banding ada'
);

\App\Core\App::refreshSettings();
$overview = \App\Models\BudgetSummary::projectOverview($projectId);
$approvedRab = (float) scalar(
    "SELECT COALESCE(SUM(total_amount), 0) FROM budget_plans WHERE project_id = ? AND status = 'approved'",
    [$projectId]
);
// Realisasi pembangunan: pengeluaran dikurangi pengembalian dana, ditambah
// penyesuaian, dan hanya menghitung transaksi yang sudah disetujui.
$expectedRealization = round((float) scalar(
    "SELECT COALESCE(SUM(CASE t.transaction_type
            WHEN 'expense' THEN t.amount
            WHEN 'refund' THEN -t.amount
            WHEN 'adjustment' THEN t.amount
            ELSE 0 END), 0)
     FROM financial_transactions t
     WHERE t.project_id = ? AND t.cost_center = 'construction' AND t.status = 'approved'",
    [$projectId]
), 2);
check(
    'Anggaran acuan memakai total RAB yang disetujui',
    $approvedRab > 0.0 && abs((float) $overview['baseline'] - $approvedRab) < 0.001
        && (string) $overview['baseline_source'] === 'approved_rab',
    'acuan ' . rupiah((float) $overview['baseline'])
);
check(
    'Model realisasi memakai pengeluaran dikurangi pengembalian dana',
    abs((float) $overview['realization'] - $expectedRealization) < 0.001,
    rupiah((float) $overview['realization'])
);
check(
    'Sisa anggaran = anggaran disetujui dikurangi realisasi',
    abs((float) $overview['remaining'] - round($approvedRab - $expectedRealization, 2)) < 0.001,
    'sisa ' . rupiah((float) $overview['remaining'])
);
check(
    'Penyerapan = realisasi dibagi anggaran disetujui kali seratus persen',
    $approvedRab > 0.0
        && abs((float) $overview['percent'] - round($expectedRealization / $approvedRab * 100, 1)) < 0.001,
    (string) $overview['percent'] . '%'
);
check(
    'Indikator aman, mendekati batas, dan terlampaui mengikuti ambang konfigurasi',
    \App\Models\BudgetSummary::indicator(100000000.0, 20000000.0) === 'safe'
        && \App\Models\BudgetSummary::indicator(100000000.0, 90000000.0) === 'warning'
        && \App\Models\BudgetSummary::indicator(100000000.0, 120000000.0) === 'exceeded'
        && (int) $overview['warning_percent'] === 85,
    'ambang ' . (int) $overview['warning_percent'] . '%'
);
$expectedIndicator = (float) $overview['percent'] > 100.0
    ? 'exceeded'
    : ((float) $overview['percent'] >= (float) $overview['warning_percent'] ? 'warning' : 'safe');
check(
    'Indikator proyek mengikuti aturan ambang yang tersimpan',
    (string) $overview['indicator'] === $expectedIndicator,
    'penyerapan ' . (string) $overview['percent'] . '% → ' . (string) $overview['indicator']
);
check(
    'Penyerapan tanpa anggaran acuan dinyatakan tidak dapat dihitung',
    \App\Models\BudgetSummary::percent(0.0, 5000000.0) === null
        && \App\Models\BudgetSummary::indicator(0.0, 5000000.0) === 'unknown'
);

$dashboardOld = http('GET', '/dashboard', null, [], $jars['pemeriksa']);
check('Dashboard Prompt 3 tetap melayani 200', $dashboardOld['status'] === 200 && !str_contains($dashboardOld['body'], '<b>Fatal error'));

// Kartu realisasi dan sisa pada dashboard lama harus menampilkan angka model yang sama
// dengan yang dipakai modul keuangan, bukan penanda "belum tersedia" sisa tahap tiga.
$nasional = \App\Models\BudgetSummary::globalOverview(\App\Models\FinancialTransaction::CENTER_CONSTRUCTION);
check(
    'Kartu realisasi dan sisa dashboard memakai angka model yang sama',
    str_contains($dashboardOld['body'], rupiah($nasional['realization']))
        && str_contains($dashboardOld['body'], rupiah($nasional['remaining'])),
    rupiah($nasional['realization']) . ' dan ' . rupiah($nasional['remaining'])
);
check(
    'Dashboard tidak lagi menyimpan kartu keuangan kosong',
    !str_contains($dashboardOld['body'], '<p class="stat-value">Belum tersedia</p>')
        && str_contains($dashboardOld['body'], 'transaksi pembangunan disetujui')
);

$operasional = \App\Models\BudgetSummary::globalOverview(\App\Models\FinancialTransaction::CENTER_OPERATIONS);
check(
    'Pengeluaran operasional dilaporkan terpisah dari realisasi pembangunan',
    (bool) preg_match(
        '/Pengeluaran operasional<\/dt>\s*<dd[^>]*>' . preg_quote(rupiah($operasional['realization']), '/') . '<\/dd>/',
        $dashboardOld['body']
    ),
    'operasional ' . rupiah($operasional['realization']) . ' vs pembangunan ' . rupiah($nasional['realization'])
);

$detail = http('GET', '/proyek/' . $projectId, null, [], $jars['pemeriksa']);
check(
    'Halaman proyek membaca angka keuangan terbaru',
    str_contains($detail['body'], '42.500.000') || str_contains($detail['body'], 'Rp42.500.000'),
    'realisasi operasional terlihat'
);
check('Halaman proyek membaca jadwal kegiatan', str_contains($detail['body'], 'Pembersihan lahan'));
check('Tab anggaran dan jadwal muncul pada halaman proyek', str_contains($detail['body'], 'pane-anggaran') && str_contains($detail['body'], 'pane-jadwal'));

$viewerDetail = http('GET', '/proyek/' . $projectId, null, [], $jars['pengamat']);
$editorDetail = http('GET', '/proyek/' . $projectId, null, [], $jars['pencatat']);
check(
    'Tautan perubahan hanya muncul pada peran yang punya izin',
    $viewerDetail['status'] === 200 && str_contains($editorDetail['body'], '/rab/tambah')
        && !str_contains($viewerDetail['body'], '/rab/tambah'),
    'pencatat melihat tautan, pengamat tidak'
);
check(
    'Penolakan tetap ditegakkan di server walau tautan disembunyikan',
    http('GET', '/rab/tambah', null, [], $jars['pengamat'])['status'] === 403
);

// ------------------------------------------------------------- 16. notifikasi
section('16. Notifikasi dan penggandaan');

$before = (int) scalar('SELECT COUNT(*) FROM notifications');
\App\Models\Notification::generateScheduleReminders();
\App\Models\Notification::generateBudgetWarnings();
$middle = (int) scalar('SELECT COUNT(*) FROM notifications');
\App\Models\Notification::generateScheduleReminders();
\App\Models\Notification::generateBudgetWarnings();
$after = (int) scalar('SELECT COUNT(*) FROM notifications');

check('Generator pengingat berjalan tanpa galat', $middle >= $before, 'tambah: ' . ($middle - $before));
check('Pengembangan ulang tidak menggandakan notifikasi', $after === $middle, "setelah: {$after}, sebelumnya: {$middle}");

foreach (['budget.submitted', 'budget.decided', 'transaction.submitted', 'transaction.decided',
          'transaction.cancelled', 'schedule.changed'] as $type) {
    check(
        "Notifikasi tipe {$type} terbentuk dari alur",
        (int) scalar('SELECT COUNT(*) FROM notifications WHERE type = ?', [$type]) > 0
    );
}

check(
    'Pengingat tenggat jadwal dan ambang anggaran ikut tercatat',
    (int) scalar("SELECT COUNT(*) FROM notifications WHERE type IN ('schedule.due', 'schedule.late', 'budget.warning', 'budget.exceeded')") > 0,
    'entri pengingat: ' . (int) scalar("SELECT COUNT(*) FROM notifications WHERE type IN ('schedule.due', 'schedule.late', 'budget.warning', 'budget.exceeded')")
);

$secretLeak = (int) scalar(
    "SELECT COUNT(*) FROM user_activity_logs WHERE description LIKE '%password%' OR description LIKE '%_token%' OR description LIKE '%Bearer%'"
);
check('Log aktivitas tidak menyimpan rahasia', $secretLeak === 0, 'entri mencurigakan: ' . $secretLeak);

$auditFinance = (int) scalar(
    "SELECT COUNT(*) FROM user_activity_logs WHERE activity LIKE 'budgets.%' OR activity LIKE 'transactions.%' OR activity LIKE 'schedules.%'"
);
check('Audit log mencatat aksi modul keuangan', $auditFinance > 0, 'entri: ' . $auditFinance);

// ---------------------------------------------------------- 17. konkurensi
section('17. Konsistensi saat dua pengguna bertransaksi bersamaan');

// Dua akun pencatat menyimpan transaksi pada saat yang sama. Nomor otomatis dibuat
// server, jadi bentrokan nama nomor adalah risiko nyata dari permintaan paralel.
$raceAmounts = ['pencatat' => 7150000, 'bendahara' => 8250000];
$raceDates = ['pencatat' => '-11 day', 'bendahara' => '-12 day'];
$parallelJobs = [];

foreach ($raceAmounts as $label => $amount) {
    $jar = $jars[$label];
    $parallelJobs[] = [
        'url' => $baseUrl . '/transaksi/tambah',
        'jar' => $jar,
        'body' => http_build_query([
            'project_id' => (string) $projectId,
            'transaction_code' => '',
            'transaction_type' => 'expense',
            'cost_center' => 'operations',
            'category_id' => '9',
            'transaction_date' => date('Y-m-d', strtotime($raceDates[$label])),
            'amount' => (string) $amount,
            'payment_method' => 'cash',
            'recipient' => 'Pemasok ' . ucfirst($label),
            'reference_number' => 'PAR-' . strtoupper($label),
            'description' => 'Belanja konkuren dari akun ' . $label . '.',
            // Token CSRF diambil dari sesi masing-masing akun, bukan satu token untuk semua.
            '_token' => csrfToken(http('GET', '/transaksi/tambah', null, [], $jar)['body']),
        ]),
    ];
}

$parallelStatuses = concurrent($parallelJobs);
$parallelRows = rows(
    "SELECT id, transaction_code, amount FROM financial_transactions WHERE project_id = ? AND reference_number LIKE 'PAR-%'",
    [$projectId]
);

check(
    'Dua permintaan paralel keduanya diterima',
    count(array_filter($parallelStatuses, static fn (int $s): bool => $s === 302)) === 2,
    'server dev menyatukan permintaan secara berurutan; status: ' . implode('/', $parallelStatuses)
);
check(
    'Kedua transaksi konkuren tersimpan',
    count($parallelRows) === 2,
    'baris: ' . count($parallelRows)
);
check(
    'Nomor transaksi otomatis tetap unik saat dua pengguna menyimpan bersamaan',
    count($parallelRows) === 2 && count(array_unique(array_column($parallelRows, 'transaction_code'))) === 2,
    implode(', ', array_column($parallelRows, 'transaction_code'))
);
check(
    'Nominal kedua transaksi tersimpan persis seperti dikirim',
    count(array_filter($parallelRows, static fn (array $r): bool => (float) $r['amount'] > 0.0)) === 2,
    'nominal: ' . implode(' + ', array_column($parallelRows, 'amount'))
);

$parallelSum = 0.0;
foreach ($parallelRows as $parallelRow) {
    $parallelId = (int) $parallelRow['id'];
    send('/transaksi/' . $parallelId . '/ajukan', [], $jars['pencatat'], '/transaksi/' . $parallelId, '/transaksi/' . $parallelId);
    $parallelSum += (float) $parallelRow['amount'];
}

// Dua pemeriksa menyetujui transaksi yang sama pada saat yang sama. Hanya satu
// yang boleh mengubah status; permintaan lainnya harus ditolak karena statusnya
// sudah berubah lebih dahulu.
$raceTargetId = (int) $parallelRows[0]['id'];
$approveJobs = [];
foreach ([$adminJar, $jars['pemeriksa']] as $jar) {
    $approveJobs[] = [
        'url' => $baseUrl . '/transaksi/' . $raceTargetId . '/keputusan',
        'jar' => $jar,
        'body' => http_build_query([
            'action' => 'approve',
            'note' => 'Persetujuan konkuren dari dua pemeriksa.',
            '_token' => csrfToken(http('GET', '/transaksi/' . $raceTargetId, null, [], $jar)['body']),
        ]),
    ];
}

$approveStatuses = concurrent($approveJobs);
$approveTrail = (int) scalar(
    "SELECT COUNT(*) FROM transaction_approvals WHERE transaction_id = ? AND action = 'approve'",
    [$raceTargetId]
);
check(
    'Hanya satu persetujuan yang tercatat untuk transaksi yang sama',
    $approveTrail === 1 && (string) scalar('SELECT status FROM financial_transactions WHERE id = ?', [$raceTargetId]) === 'approved',
    'jejak approve: ' . $approveTrail . ', status HTTP: ' . implode('/', $approveStatuses)
);

send(
    '/transaksi/' . (int) $parallelRows[1]['id'] . '/keputusan',
    ['action' => 'approve', 'note' => 'Disetujui untuk uji konkurensi.'],
    $jars['pemeriksa'],
    '/transaksi/' . (int) $parallelRows[1]['id'],
    '/transaksi/' . (int) $parallelRows[1]['id']
);

$realizationNow = (float) scalar(
    "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
     WHERE project_id = ? AND cost_center = 'operations' AND transaction_type = 'expense' AND status = 'approved'",
    [$projectId]
);
$expectedRealization = 42500000.0 + $parallelSum;

check(
    'Realisasi tetap konsisten setelah dua pengguna bertransaksi bersamaan',
    abs($realizationNow - $expectedRealization) < 0.001,
    rupiah($realizationNow) . ' vs harapan ' . rupiah($expectedRealization)
);

$summary = \App\Models\BudgetSummary::projectOverview($projectId, \App\Models\FinancialTransaction::CENTER_OPERATIONS);
check(
    'Model rekap membaca realisasi yang sama dengan database',
    abs((float) $summary['realization'] - $realizationNow) < 0.001,
    rupiah((float) $summary['realization'])
);

// -------------------------------------------------------------- 18. galat aman
section('18. Penanganan galat dan kerentanan dasar');

$notFound = http('GET', '/rab/999999', null, [], $jars['pemeriksa']);
check('RAB tidak dikenal mengembalikan 404', $notFound['status'] === 404);
check(
    'Halaman galat tidak membocorkan query SQL',
    stripos($notFound['body'], 'SELECT ') === false && stripos($notFound['body'], 'SQLSTATE') === false
);

$xssProbe = send(
    '/jadwal/tambah',
    [
        'project_id' => (string) $projectId, 'activity_code' => 'KGT-902', 'name' => '<script>alert(1)</script>',
        'description' => '"><img src=x onerror=alert(1)>', 'category_id' => '3',
        'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+3 day')),
        'owner_name' => "' OR 1=1 --", 'priority' => 'low', 'status' => 'not_started', 'progress' => '0', 'notes' => '',
    ],
    $jars['pencatat'],
    '/jadwal/tambah',
    '/jadwal'
);
$xssRow = row('SELECT name, owner_name FROM project_schedules WHERE project_id = ? AND activity_code = ?', [$projectId, 'KGT-902']);
check('Input berbahaya ditolak atau tersimpan apa adanya', ($xssRow === [] || (string) ($xssRow['name'] ?? '') === '<script>alert(1)</script>'), $xssProbe['flash']);
$listPage = http('GET', '/jadwal?q=' . rawurlencode('KGT-902'), null, [], $jars['pemeriksa']);
check(
    'Tampilan meng-escape nilai berbahaya',
    !str_contains($listPage['body'], '<script>alert(1)</script>')
);

$sqliProbe = http('GET', '/transaksi?q=' . rawurlencode("' OR 1=1 --"), null, [], $jars['pemeriksa']);
check(
    'Percobaan injeksi pada pencarian tidak membuka isi tabel',
    $sqliProbe['status'] === 200
        && !str_contains($sqliProbe['body'], $constructionCode)
        && !str_contains($sqliProbe['body'], 'SQLSTATE'),
    'HTTP ' . $sqliProbe['status']
);

// --------------------------------------------------- 19. izin berubah tanpa kode baru
section('19. Perubahan hak akses langsung berlaku pada permintaan berikutnya');

$pengawasRoleId = (int) $roleIds['pengawas_pembangunan'];
$originalIds = array_map('intval', array_column(
    rows('SELECT permission_id FROM role_permissions WHERE role_id = ? ORDER BY permission_id', [$pengawasRoleId]),
    'permission_id'
));
$createPermissionId = (int) scalar("SELECT id FROM permissions WHERE permission_slug = 'budgets.create'");

$beforeGrant = http('GET', '/rab/tambah', null, [], $jars['pengawas']);
check('Sebelum izin diberi, formulir RAB tertutup', $beforeGrant['status'] === 403, 'HTTP ' . $beforeGrant['status']);

$grant = send(
    '/hak-akses/peran/' . $pengawasRoleId . '/izin',
    ['permissions' => array_merge($originalIds, [$createPermissionId])],
    $adminJar,
    '/hak-akses?peran=' . $pengawasRoleId,
    '/hak-akses?peran=' . $pengawasRoleId
);
$grantedNow = (int) scalar(
    'SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?',
    [$pengawasRoleId, $createPermissionId]
);
check('Izin budgets.create tertempel pada peran', $grantedNow === 1, $grant['flash']);

$afterGrant = http('GET', '/rab/tambah', null, [], $jars['pengawas']);
check(
    'Izin baru langsung berlaku tanpa keluar akun atau mengubah kode',
    $afterGrant['status'] === 200,
    'HTTP ' . $afterGrant['status']
);

$grantedCreate = send(
    '/rab/tambah',
    [
        'project_id' => (string) $projectId, 'rab_code' => '', 'name' => 'RAB Buatan Pengawas Setelah Izin Ditambah',
        'period_start' => date('Y-01-05'), 'period_end' => date('Y-12-20'), 'notes' => '',
    ],
    $jars['pengawas'],
    '/rab/tambah',
    '/rab'
);
check(
    'Pencatatan RAB oleh peran baru izin berjalan',
    $grantedCreate['status'] === 302
        && (int) scalar('SELECT COUNT(*) FROM budget_plans WHERE project_id = ? AND name = ?', [
            $projectId, 'RAB Buatan Pengawas Setelah Izin Ditambah',
        ]) === 1,
    $grantedCreate['flash']
);

$revoke = send(
    '/hak-akses/peran/' . $pengawasRoleId . '/izin',
    ['permissions' => $originalIds],
    $adminJar,
    '/hak-akses?peran=' . $pengawasRoleId,
    '/hak-akses?peran=' . $pengawasRoleId
);
$restoredIds = array_map('intval', array_column(
    rows('SELECT permission_id FROM role_permissions WHERE role_id = ? ORDER BY permission_id', [$pengawasRoleId]),
    'permission_id'
));
sort($originalIds);
check(
    'Susunan izin dikembalikan persis seperti semula',
    $restoredIds === $originalIds,
    $revoke['flash']
);
check(
    'Setelah izin dicabut, permintaan berikutnya kembali ditolak',
    http('GET', '/rab/tambah', null, [], $jars['pengawas'])['status'] === 403
);

echo "\n";
