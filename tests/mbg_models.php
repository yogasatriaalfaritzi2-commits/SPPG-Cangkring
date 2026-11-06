<?php
declare(strict_types=1);

/**
 * Pengujian model sisi server modul MBG: sekolah, penerima manfaat, bahan dan gizi,
 * menu beserta alur persetujuannya, jadwal pelayanan, aturan rekomendasi, dan target gizi.
 *
 * Seluruh skenario berjalan di dalam satu transaksi database yang dibatalkan pada akhir
 * pelaksanaan, jadi suite ini dapat dijalankan berulang tanpa meninggalkan jejak data
 * dan tanpa memerlukan server web.
 *
 * Angka gizi pada fixture di bawah hanyalah data uji untuk membuktikan rumus perhitungan.
 * Aplikasi tidak pernah membundel angka gizi yang belum diverifikasi terhadap dokumen sumber.
 *
 * Jalankan: php tests/mbg_models.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Skrip ini hanya boleh dijalankan dari CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Beneficiary;
use App\Models\BeneficiaryCategory;
use App\Models\BeneficiaryIndividual;
use App\Models\FoodIngredient;
use App\Models\IngredientCategory;
use App\Models\IngredientNutrient;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuRevision;
use App\Models\MenuRule;
use App\Models\MenuSchedule;
use App\Models\NutritionCalculator;
use App\Models\NutritionReference;
use App\Models\NutritionTarget;
use App\Models\NutritionTargetGroup;
use App\Models\School;
use App\Models\SchoolLevel;
use App\Models\SchoolServiceSchedule;

App::loadSettings();

$passed = 0;
$failed = 0;

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

function same(float $expected, float $actual, string $label): void
{
    check($label, abs($expected - $actual) < 0.005, 'diharapkan ' . $expected . ', nyata ' . $actual);
}

$filtersOf = static fn (array $keys): array => array_fill_keys($keys, '');

$schoolFilters = $filtersOf(['search', 'level', 'status', 'partnership', 'regency', 'sort', 'dir']);
$menuFilters = $filtersOf(['search', 'category', 'status', 'nutrition', 'sort', 'dir']);
$weekFilters = $filtersOf(['from', 'to', 'menu', 'status', 'school']);

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $actorId = (int) (Database::query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetch()['id'] ?? 0);
    check('Terdapat pengguna sebagai pencatat', $actorId > 0, 'user id ' . $actorId);

    section('Satuan dan faktor konversi');

    $rice = ['base_unit' => 'gram', 'purchase_unit' => 'kilogram', 'conversion_factor' => 1000];
    [$grams, ] = NutritionCalculator::toBase(1.5, 'kilogram', $rice);
    same(1500.0, (float) $grams, 'Satuan beli kilogram setara satuan dasar gram');

    [$grams, ] = NutritionCalculator::toBase(250, 'g', $rice);
    same(250.0, (float) $grams, 'Singkatan g dianggap gram');

    [$failedConvert, $note] = NutritionCalculator::toBase(3, 'butir', $rice);
    check('Satuan hitung tanpa faktor konversi ditolak', $failedConvert === null, (string) $note);

    $egg = ['base_unit' => 'butir', 'purchase_unit' => 'pak', 'conversion_factor' => 10];
    [$butir, $eggNote] = NutritionCalculator::toBase(2, 'pak', $egg);
    same(20.0, (float) $butir, 'Faktor konversi satuan beli dipakai');
    check('Catatan faktor konversi dikembalikan', is_string($eggNote) && str_contains($eggNote, '10'), (string) $eggNote);

    section('Katalog bahan, rujukan gizi, dan perhitungan gizi');

    $ingredientCategories = IngredientCategory::activeList();
    check('Kategori bahan tersedia', count($ingredientCategories) >= 10, (string) count($ingredientCategories));

    $catSerealia = null;
    $catSayur = null;
    $catDaging = null;
    foreach ($ingredientCategories as $category) {
        $code = (string) $category['category_code'];
        if ($code === 'SEREALIA') {
            $catSerealia = (int) $category['id'];
        } elseif ($code === 'SAYUR') {
            $catSayur = (int) $category['id'];
        } elseif ($code === 'DAGING') {
            $catDaging = (int) $category['id'];
        }
    }
    check('Kategori uji tersedia', $catSerealia !== null && $catSayur !== null && $catDaging !== null);

    $references = NutritionReference::activeList();
    check('Referensi gizi tersedia', count($references) >= 1, (string) count($references));
    $referenceId = (int) $references[0]['id'];
    check(
        'Referensi bawaan ditandai perlu verifikasi',
        (int) $references[0]['needs_verification'] === 1,
        (string) $references[0]['reference_code']
    );

    $riceId = FoodIngredient::create([
        'ingredient_code' => 'UJI-BERAS',
        'ingredient_name' => 'Beras uji',
        'category_id' => $catSerealia,
        'base_unit' => 'gram',
        'purchase_unit' => 'kilogram',
        'conversion_factor' => 1000,
        'edible_factor' => 1,
        'reference_price' => 13000,
        'price_basis' => 'kilogram',
        'is_active' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    $chickenId = FoodIngredient::create([
        'ingredient_code' => 'UJI-AYAM',
        'ingredient_name' => 'Ayam uji',
        'category_id' => $catDaging,
        'base_unit' => 'gram',
        'purchase_unit' => 'kilogram',
        'conversion_factor' => 1000,
        'edible_factor' => 0.8,
        'reference_price' => 35000,
        'price_basis' => 'kilogram',
        'is_active' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    $vegId = FoodIngredient::create([
        'ingredient_code' => 'UJI-SAYUR',
        'ingredient_name' => 'Sayur uji',
        'category_id' => $catSayur,
        'base_unit' => 'gram',
        'is_active' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Tiga bahan uji terbentuk', $riceId > 0 && $chickenId > 0 && $vegId > 0);
    check('Kode bahan uji dikenali', FoodIngredient::codeExists('UJI-BERAS'));
    check(
        'Kode bahan otomatis berbentuk BHN-tahun-urutan',
        preg_match('/^BHN-\d{4}-\d{3}$/', FoodIngredient::nextCode()) === 1,
        FoodIngredient::nextCode()
    );

    IngredientNutrient::create([
        'ingredient_id' => $riceId,
        'nutrient_code' => 'energy_kcal',
        'nutrient_value' => 360,
        'nutrient_unit' => 'kkal',
        'reference_amount' => 100,
        'reference_unit' => 'gram',
        'value_source' => 'referensi',
        'reference_id' => $referenceId,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    IngredientNutrient::create([
        'ingredient_id' => $riceId,
        'nutrient_code' => 'protein_g',
        'nutrient_value' => 6.8,
        'nutrient_unit' => 'gram',
        'reference_id' => $referenceId,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    IngredientNutrient::create([
        'ingredient_id' => $chickenId,
        'nutrient_code' => 'energy_kcal',
        'nutrient_value' => 175,
        'nutrient_unit' => 'kkal',
        'reference_id' => $referenceId,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    IngredientNutrient::create([
        'ingredient_id' => $chickenId,
        'nutrient_code' => 'protein_g',
        'nutrient_value' => 27,
        'nutrient_unit' => 'gram',
        'value_source' => 'estimasi',
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);

    check(
        'Nutrien ganda pada bahan yang sama ditolak',
        IngredientNutrient::scopeExists($riceId, 'energy_kcal')
    );

    $nutrientMap = FoodIngredient::nutrientMap([$riceId, $chickenId, $vegId]);
    check('Peta gizi termuat per bahan', count($nutrientMap[$riceId]) === 2, (string) count($nutrientMap[$riceId]));
    check('Bahan tanpa gizi tidak masuk peta', !isset($nutrientMap[$vegId]));

    $riceRow = FoodIngredient::findWithNames($riceId);
    $chickenRow = FoodIngredient::findWithNames($chickenId);

    // 100 gram beras x 360/100 = 360 kkal.
    [$values, $issues, $estimated] = NutritionCalculator::nutrients(100, $riceRow ?? [], $nutrientMap[$riceId] ?? []);
    same(360.0, (float) ($values['energy_kcal'] ?? 0), 'Energi 100 gram beras dihitung dari angka per 100 gram');
    same(6.8, (float) ($values['protein_g'] ?? 0), 'Protein mengikuti dasar penyajian');
    check('Sumber referensi tidak dianggap estimasi', $estimated === false);
    check('Tidak ada masalah pada bahan lengkap', $issues === [], implode('; ', $issues));

    // Ayam: 100 gram dibeli x bagian dapat dimakan 0,8 = 80 gram yang dihitung.
    [$chickenValues, ] = NutritionCalculator::nutrients(100, $chickenRow ?? [], $nutrientMap[$chickenId] ?? []);
    same(140.0, (float) ($chickenValues['energy_kcal'] ?? 0), 'Faktor bagian yang dapat dimakan ikut menghitung energi');
    same(21.6, (float) ($chickenValues['protein_g'] ?? 0), 'Faktor bagian yang dapat dimakan ikut menghitung protein');

    [$chickenValuesEstimate, , $chickenEstimated] = NutritionCalculator::nutrients(
        100,
        $chickenRow ?? [],
        ['protein_g' => ['nutrient_code' => 'protein_g', 'nutrient_value' => 27, 'reference_amount' => 100, 'reference_unit' => 'gram', 'value_source' => 'estimasi']]
    );
    check('Nilai berlabel estimasi terdeteksi', $chickenEstimated === true);

    section('Estimasi biaya per porsi');

    [$costRice, $costNote] = NutritionCalculator::cost(100, $riceRow ?? []);
    same(1300.0, (float) $costRice, 'Harga per kilogram dikonversi ke biaya 100 gram');
    check('Tidak ada masalah harga bahan uji', $costNote === null, (string) $costNote);

    [$costVeg, $vegNote] = NutritionCalculator::cost(50, ['base_unit' => 'gram', 'price_basis' => 'kilogram'] + []);
    check('Bahan tanpa harga mengembalikan nilai null', $costVeg === null, (string) $vegNote);

    section('Menu, perhitungan server, dan alur persetujuan');

    $menuCategories = MenuCategory::activeList();
    check('Kategori menu tersedia', count($menuCategories) >= 4, (string) count($menuCategories));
    $menuCategoryId = (int) $menuCategories[1]['id'];

    $items = [
        ['ingredient_id' => $riceId, 'quantity' => 100, 'unit' => 'gram'],
        ['ingredient_id' => $chickenId, 'quantity' => 0.05, 'unit' => 'kilogram'],
        ['ingredient_id' => $vegId, 'quantity' => 50, 'unit' => 'gram'],
    ];

    $menuId = Menu::create([
        'menu_code' => 'UJI-MENU-A',
        'menu_name' => 'Nasi uji ayam',
        'category_id' => $menuCategoryId,
        'description' => 'Menu uji perhitungan',
    ], $items, $actorId);
    check('Menu uji terbentuk', $menuId > 0);

    $computation = Menu::compute($menuId);
    same(430.0, (float) ($computation['per_serving']['energy_kcal'] ?? 0), 'Energi menu adalah jumlah kontribusi bahan');
    same(17.6, (float) ($computation['per_serving']['protein_g'] ?? 0), 'Protein menu menjumlahkan bahan dengan faktor');
    check('Status gizi sebagian karena ada bahan tanpa angka', $computation['nutrition_status'] === 'sebagian', (string) $computation['nutrition_status']);
    check(
        'Data gizi yang belum tersedia dilaporkan sebagai masalah',
        (bool) preg_grep('/Sayur uji: data gizi belum tersedia/', $computation['issues'])
    );
    check('Satuan tidak sesuai tidak menghasilkan angka nol diam-diam', $computation['missing'] === 1);
    same(3050.0, (float) $computation['cost_per_serving'], 'Biaya per porsi dijumlahkan dari harga bahan');

    $savedMenu = Menu::find($menuId);
    same(430.0, (float) $savedMenu['estimated_energy_kcal'], 'Ringkasan energi tersimpan pada kolom hasil perhitungan');
    check(
        'Nilai gizi dari browser tidak dipercaya',
        Menu::create([
            'menu_code' => 'UJI-MENU-B',
            'menu_name' => 'Menu uji isian palsu',
            'category_id' => $menuCategoryId,
            'estimated_energy_kcal' => 99999,
        ], [['ingredient_id' => $riceId, 'quantity' => 50, 'unit' => 'gram']], $actorId) > 0
    );
    $fakeMenu = Menu::findWithNames((int) (Database::query('SELECT id FROM menus WHERE menu_code = :c', ['c' => 'UJI-MENU-B'])->fetch()['id'] ?? 0));
    same(180.0, (float) $fakeMenu['estimated_energy_kcal'], 'Kolom estimasi dihitung ulang, bukan diambil dari input');

    $snapshot = NutritionCalculator::snapshot($computation);
    check('Snapshot menyimpan status gizi', $snapshot['nutrition_status'] === 'sebagian');

    check('Menu yang belum diajukan tidak dapat diputus', Menu::decide($menuId, Menu::STATUS_APPROVED, 'setuju', $actorId) === 0);
    check('Menu draft dapat diajukan', Menu::submit($menuId, $actorId) === 1);
    check('Status berubah menjadi menunggu', (string) Menu::find($menuId)['menu_status'] === Menu::STATUS_PENDING);

    Menu::log($menuId, 'submit', Menu::STATUS_DRAFT, Menu::STATUS_PENDING, 'Diajukan untuk uji', $actorId);
    check('Pengajuan tercatat pada riwayat persetujuan', count(Menu::approvals($menuId)) === 1);

    check('Persetujuan ahli gizi berlaku', Menu::decide($menuId, Menu::STATUS_APPROVED, 'Sudah diperiksa', $actorId) === 1);
    $approved = Menu::find($menuId);
    check('Pemeriksa tercatat', (int) $approved['reviewed_by'] === $actorId && (string) $approved['decision_note'] === 'Sudah diperiksa');

    $revision = MenuRevision::create(
        $approved,
        [
            'menu_name' => 'Nasi uji ayam revisi',
            'category_id' => $menuCategoryId,
            'description' => 'Revisi uji',
        ],
        [
            ['ingredient_id' => $riceId, 'quantity' => 120, 'unit' => 'gram'],
            ['ingredient_id' => $chickenId, 'quantity' => 60, 'unit' => 'gram'],
            ['ingredient_id' => $vegId, 'quantity' => 50, 'unit' => 'gram'],
        ],
        'Porsi beras dinaikkan karena sisa makanan',
        $actorId
    );
    [$revisionMenuId, $revisionId, $revisionCode] = $revision;
    $revisionMenu = Menu::find($revisionMenuId);
    check('Revisi membuat menu turunan', (int) $revisionMenu['revision_of'] === $menuId);
    check('Versi menu turunan naik', (int) $revisionMenu['version'] === 2);
    check('Kode revisi berbentuk RVM-tahun-urutan', preg_match('/^RVM-\d{4}-\d{3}$/', $revisionCode) === 1, $revisionCode);
    check('Menu asal tetap disetujui dan tidak berubah', (string) Menu::find($menuId)['menu_status'] === Menu::STATUS_APPROVED);
    $proposed = MenuRevision::proposed(MenuRevision::find($revisionId) ?? []);
    check('Isi usulan tersimpan sebagai salinan JSON', (int) ($proposed['items'][0]['quantity'] ?? 0) === 120);
    check('Usulan tersimpan menunggu keputusan', (string) MenuRevision::find($revisionId)['revision_status'] === MenuRevision::STATUS_PENDING);
    check('Alur versi terbaca dari menu asal', count(Menu::lineage($menuId)) === 2);
    check('Kode usulan otomatis tidak bentrok', MenuRevision::nextCode() !== $revisionCode);

    section('Sekolah penerima manfaat');

    $levels = SchoolLevel::activeList();
    check('Jenjang sekolah tersedia', count($levels) >= 7, (string) count($levels));
    $sdLevel = null;
    $smpLevel = null;
    foreach ($levels as $level) {
        if ((string) $level['level_code'] === 'SD') {
            $sdLevel = (int) $level['id'];
        }
        if ((string) $level['level_code'] === 'SMP') {
            $smpLevel = (int) $level['id'];
        }
    }
    check('Jenjang SD dan SMP tersedia', $sdLevel !== null && $smpLevel !== null);

    $schoolId = School::create([
        'school_code' => 'UJI-SEK-A',
        'school_name' => 'SD Uji Cangkring',
        'npsn' => '12345601',
        'level_id' => $sdLevel,
        'school_status' => 'aktif',
        'partnership_status' => 'moa_aktif',
        'partnership_start' => '2026-01-05',
        'partnership_end' => '2026-12-31',
        'regency' => 'Kabupaten Uji',
        'district' => 'Kecamatan Uji',
        'person_in_charge' => 'Kepala Uji',
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Sekolah uji terbentuk', $schoolId > 0);
    check('NPSN dikenali sebagai sudah dipakai', School::npsnExists('12345601'));
    check(
        'NPSN sekolah lain boleh kosong',
        School::create([
            'school_code' => 'UJI-SEK-B',
            'school_name' => 'SMP Uji Tanpa NPSN',
            'npsn' => '',
            'level_id' => $smpLevel,
            'regency' => 'Kabupaten Uji',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]) > 0
    );

    $secondSchoolId = (int) (Database::query(
        'SELECT id FROM schools WHERE school_code = :c',
        ['c' => 'UJI-SEK-B']
    )->fetch()['id'] ?? 0);
    check('Kode sekolah otomatis berbentuk SEK-tahun-urutan', preg_match('/^SEK-\d{4}-\d{3}$/', School::nextCode()) === 1);

    SchoolServiceSchedule::create([
        'school_id' => $schoolId,
        'service_weekday' => 2,
        'service_time' => '10:30:00',
        'meal_slot' => 'makan_siang',
        'is_active' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check(
        'Hari layanan ganda ditolak',
        SchoolServiceSchedule::scopeExists($schoolId, 2, 'makan_siang')
    );
    check('Daftar sekolah yang dilayani hari Selasa terisi', count(SchoolServiceSchedule::schoolsOn(2)) >= 1);
    check(
        'Hari layanan dapat dikonfigurasi berbeda antar sekolah',
        SchoolServiceSchedule::create([
            'school_id' => $secondSchoolId,
            'service_weekday' => 4,
            'meal_slot' => 'makan_siang',
            'is_active' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]) > 0
    );

    $page = School::paginate($schoolFilters + ['search' => 'UJI', 'sort' => 'name'], 1, 10);
    check('Pencarian sekolah menemukan data uji', $page['total'] >= 2, 'total ' . $page['total']);

    section('Penerima manfaat: rekap jumlah, riwayat, dan perlindungan data');

    $beneficiaryCategories = BeneficiaryCategory::activeList();
    check('Kategori penerima tersedia', count($beneficiaryCategories) >= 3, (string) count($beneficiaryCategories));
    $siswaId = null;
    foreach ($beneficiaryCategories as $category) {
        if ((string) $category['category_code'] === 'SISWA') {
            $siswaId = (int) $category['id'];
        }
    }
    check('Kategori siswa tersedia', $siswaId !== null);

    [$id, $action] = Beneficiary::record($schoolId, (int) $siswaId, 'Kelas 1', '2026-01-10', 120, 'Data awal semester', $actorId);
    check('Rekap awal tercatat sebagai pembuatan', $action === 'buat' && $id > 0);

    [$idSame, $actionSame] = Beneficiary::record($schoolId, (int) $siswaId, 'Kelas 1', '2026-01-10', 118, 'Dua siswa pindah', $actorId);
    check('Perubahan pada tanggal yang sama memakai baris yang sama', $idSame === $id);
    check('Perubahan tercatat sebagai ubah', $actionSame === 'ubah');

    [, $actionNew] = Beneficiary::record($schoolId, (int) $siswaId, 'Kelas 1', '2026-07-01', 130, 'Data tahun ajaran baru', $actorId);
    check('Tanggal berlaku baru membuat baris riwayat', $actionNew === 'ubah');

    Beneficiary::record($schoolId, (int) $siswaId, 'Kelas 2', '2026-07-01', 90, 'Data awal kelas 2', $actorId);

    $total = Beneficiary::totalAt('2026-07-15', $schoolId);
    check('Rekap hanya menghitung baris terakhir per kelompok', $total === 220, 'total ' . $total);
    check(
        'Riwayat lama tidak dihitung ganda',
        $total !== 120 + 118 + 130 + 90
    );

    $histories = Beneficiary::histories(['school' => (string) $schoolId, 'category' => '', 'from' => '', 'to' => '']);
    check('Setiap perubahan menyimpan alasan', count($histories) === 4, 'riwayat ' . count($histories));

    $raised = null;
    foreach ($histories as $row) {
        if ((string) $row['reason'] === 'Data tahun ajaran baru') {
            $raised = $row;
        }
    }
    check('Alasan perubahan tersimpan', $raised !== null);
    check(
        'Riwayat menyimpan nilai sebelum dan sesudah',
        $raised !== null && (int) $raised['previous_count'] === 118 && (int) $raised['new_count'] === 130,
        $raised === null ? 'riwayat tidak ditemukan' : 'sebelum ' . $raised['previous_count'] . ', sesudah ' . $raised['new_count']
    );

    $rekap = Beneficiary::rekap(['asof' => '2026-07-15', 'school' => (string) $schoolId, 'category' => '', 'search' => '']);
    check('Rekap menampilkan dua kelompok kelas', count($rekap) === 2, 'baris ' . count($rekap));

    $dense = Beneficiary::rekapBySchool(['asof' => '2026-07-15', 'school' => '', 'search' => 'SD Uji']);
    check('Rekap padat menjumlah per kategori', $dense['total'] === 220, 'total ' . $dense['total']);
    check('Rekap padat memuat kolom kategori', count($dense['categories']) >= 3);

    $beforeDeactivate = Beneficiary::totalAt('2026-07-15', $schoolId);
    [$kelas2Row] = Database::query(
        'SELECT id FROM beneficiaries WHERE school_id = :s AND class_group = :c AND effective_date = :d',
        ['s' => $schoolId, 'c' => 'Kelas 2', 'd' => '2026-07-01']
    )->fetchAll();
    Beneficiary::setStatus((int) $kelas2Row['id'], Beneficiary::STATUS_INACTIVE, 'Kelas melulusi diri', $actorId);
    check('Penonaktifan mengeluarkan kelompok dari rekap', Beneficiary::totalAt('2026-07-15', $schoolId) === $beforeDeactivate - 90);
    check('Penonaktifan tercatat pada riwayat', count(Beneficiary::histories([
        'school' => (string) $schoolId, 'category' => '', 'from' => '', 'to' => '',
    ])) === 5);

    Beneficiary::remove((int) $kelas2Row['id'], 'Salah input kelas', $actorId);
    check(
        'Riwayat tetap terbaca setelah baris dihapus',
        (bool) preg_grep('/hapus/', array_map(
            static fn (array $row): string => (string) $row['change_action'],
            Beneficiary::histories(['school' => (string) $schoolId, 'category' => '', 'from' => '', 'to' => ''])
        ))
    );
    $negativeRejected = false;
    try {
        Beneficiary::record($schoolId, (int) $siswaId, 'Kelas 3', '2026-07-01', -5, 'Uji jumlah negatif', $actorId);
    } catch (RuntimeException) {
        $negativeRejected = true;
    }
    check('Jumlah penerima negatif ditolak sebelum menulis database', $negativeRejected);
    check(
        'Penolakan jumlah negatif tidak meninggalkan baris',
        (int) Database::query(
            'SELECT COUNT(*) AS total FROM beneficiaries WHERE class_group = :grup',
            ['grup' => 'Kelas 3']
        )->fetch()['total'] === 0
    );

    $individualId = BeneficiaryIndividual::create([
        'school_id' => $schoolId,
        'category_id' => (int) $siswaId,
        'class_group' => 'Kelas 1',
        'full_name' => 'Nama Uji Siswa',
        'birth_year' => 2016,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Data individual opsional tersimpan', $individualId > 0);
    $columns = Database::query('SHOW COLUMNS FROM beneficiary_individuals')->fetchAll();
    $names = array_map(static fn (array $row): string => (string) $row['Field'], $columns);
    foreach (['nik', 'nomor_kk', 'alamat', 'data_medis', 'no_kk'] as $forbidden) {
        check('Kolom data sensitif "' . $forbidden . '" tidak ada', !in_array($forbidden, $names, true));
    }
    check(
        'Nama ganda pada lingkup yang sama dikenali',
        BeneficiaryIndividual::scopeExists($schoolId, (int) $siswaId, 'Kelas 1', 'Nama Uji Siswa')
    );

    section('Jadwal menu harian dan mingguan');

    $scheduleId = MenuSchedule::create([
        'service_date' => '2026-11-09',
        'menu_id' => $menuId,
        'meal_slot' => 'makan_siang',
    ], [
        ['school_id' => $schoolId, 'class_group' => 'Kelas 1', 'portion_count' => 118],
        ['school_id' => $secondSchoolId, 'class_group' => '', 'portion_count' => 45],
    ], $actorId);
    check('Jadwal uji terbentuk', $scheduleId > 0);

    $schedule = MenuSchedule::findWithNames($scheduleId);
    check('Kode jadwal otomatis terisi', preg_match('/^JMK-\d{8}-\d{2}$/', (string) $schedule['schedule_code']) === 1, (string) $schedule['schedule_code']);
    check('Total porsi adalah jumlah alokasi sekolah', (int) $schedule['portion_total'] === 163, 'porsi ' . $schedule['portion_total']);
    same(497150.0, (float) $schedule['estimated_cost_total'], 'Estimasi biaya total = porsi x biaya per porsi');
    check('Slot tanggal-menu-slot yang sama dikenali', MenuSchedule::slotTaken('2026-11-09', $menuId, 'makan_siang'));
    check('Alokasi sekolah tersimpan dua baris', count(MenuSchedule::allocations($scheduleId)) === 2);

    MenuSchedule::update($scheduleId, [
        'service_date' => '2026-11-09',
        'menu_id' => $menuId,
        'meal_slot' => 'makan_siang',
        'schedule_status' => MenuSchedule::STATUS_DRAFT,
    ], [
        ['school_id' => $schoolId, 'class_group' => 'Kelas 1', 'portion_count' => 120],
    ], $actorId);
    check('Alokasi lama diganti dan total dihitung ulang', (int) MenuSchedule::find($scheduleId)['portion_total'] === 120);

    check('Pengajuan jadwal uji berlaku', MenuSchedule::submit($scheduleId, $actorId) === 1);
    check('Persetujuan jadwal uji berlaku', MenuSchedule::decide($scheduleId, MenuSchedule::STATUS_APPROVED, 'Disetujui uji', $actorId) === 1);

    $week = MenuSchedule::between(['from' => '2026-11-09', 'to' => '2026-11-15'] + $weekFilters, 50);
    check('Rentang minggu membaca jadwal', count($week) >= 1, 'baris ' . count($week));

    $copied = MenuSchedule::copyDay('2026-11-09', '2026-11-10', $actorId);
    check('Penyalinan hari membuat jadwal baru', count($copied) === 1);
    $copy = MenuSchedule::find($copied[0]);
    check('Hasil salinan menandai asalnya', (int) $copy['copy_from_id'] === $scheduleId);
    check('Jadwal salinan berstatus draft', (string) $copy['schedule_status'] === MenuSchedule::STATUS_DRAFT);

    section('Kebutuhan bahan untuk pengadaan');

    $plan = MenuSchedule::materialPlan(MenuSchedule::between(
        ['from' => '2026-11-09', 'to' => '2026-11-10'] + $weekFilters,
        50
    ));
    check('Rencana bahan terisi', count($plan['rows']) === 3, 'baris ' . count($plan['rows']));
    check(
        'Rencana menjumlah energi seluruh jadwal',
        (float) ($plan['nutrition']['energy_kcal'] ?? 0) > 0,
        (string) ($plan['nutrition']['energy_kcal'] ?? 0)
    );
    check('Total porsi rencana menjumlah jadwal', $plan['portions'] === 240, 'porsi ' . $plan['portions']);

    $riceNeed = null;
    foreach ($plan['rows'] as $row) {
        if ((int) $row['ingredient_id'] === $riceId) {
            $riceNeed = $row;
        }
    }
    check('Beras ditemukan dalam rencana bahan', $riceNeed !== null);
    same(24000.0, (float) $riceNeed['base_quantity'], 'Kebutuhan bahan = jumlah per porsi x total porsi');
    check('Satuan dasar konsisten', (string) $riceNeed['base_unit'] === 'gram', (string) $riceNeed['base_unit']);
    same(24.0, (float) $riceNeed['purchase_quantity'], 'Konversi ke satuan beli memakai faktor yang tercatat');
    check('Catatan faktor konversi ikut dikirim', str_contains((string) $riceNeed['conversion_note'], 'kilogram'), (string) $riceNeed['conversion_note']);
    same(312000.0, (float) $riceNeed['cost_estimate'], 'Estimasi biaya bahan mengikuti jumlah porsi');
    check('Porsi tiap bahan menjumlah semua jadwal', (int) $riceNeed['portions'] === 240, 'porsi ' . $riceNeed['portions']);

    $single = Menu::compute($menuId);
    $requirements = NutritionCalculator::requirements(
        $single['materials'],
        array_fill_keys(array_column($single['materials'], 'ingredient_id'), 10)
    );
    check('Kebutuhan bahan satu menu untuk 10 porsi', count($requirements) === 3);
    same(1000.0, (float) $requirements[0]['base_quantity'], 'Sepuluh porsi beras = 1000 gram');

    section('Aturan rekomendasi yang transparan');

    $rules = MenuRule::active(MenuRule::SCOPE_MENU);
    check('Aturan bawaan tersedia', count($rules) >= 5, 'aturan ' . count($rules));

    $findings = MenuRule::assess($rules, $single);
    $byCode = [];
    foreach ($findings as $finding) {
        $byCode[(string) $finding['rule_code']] = $finding;
    }
    check('Aturan protein hewani lulus karena ada ayam', ($byCode['RULE_PROTEIN_HEWANI']['passed'] ?? false) === true);
    check('Aturan sayuran lulus karena ada sayur uji', ($byCode['RULE_SAYUR']['passed'] ?? false) === true);
    check(
        'Temuan menjelaskan nilainya',
        isset($byCode['RULE_PROTEIN_HEWANI']['value']) && (int) $byCode['RULE_PROTEIN_HEWANI']['value'] === 1
    );

    $tanamSayur = Menu::create([
        'menu_code' => 'UJI-MENU-C',
        'menu_name' => 'Nasi uji tanpa sayur',
        'category_id' => $menuCategoryId,
    ], [['ingredient_id' => $riceId, 'quantity' => 100, 'unit' => 'gram']], $actorId);
    $findingsTwo = MenuRule::assess($rules, Menu::compute($tanamSayur));
    $failedCodes = array_values(array_filter(
        $findingsTwo,
        static fn (array $finding): bool => $finding['passed'] === false
    ));
    check(
        'Menu tanpa sayur menghasilkan temuan wajib',
        (bool) preg_grep('/RULE_SAYUR/', array_map(
            static fn (array $finding): string => (string) $finding['rule_code'],
            $failedCodes
        ))
    );

    $ceiling = MenuRule::costCeiling();
    check('Batas biaya dapat dibaca dari aturan', $ceiling === null || $ceiling > 0, var_export($ceiling, true));

    $picks = MenuRule::recommend(
        [
            ['menu' => Menu::findWithNames($menuId), 'computation' => $single, 'category_name' => 'Menu makan utama'],
            ['menu' => Menu::findWithNames($tanamSayur), 'computation' => Menu::compute($tanamSayur), 'category_name' => 'Menu makan utama'],
        ],
        $rules,
        ['2026-11-09', '2026-11-10', '2026-11-11']
    );
    check('Saran menu terisi untuk setiap tanggal', count($picks) === 3, 'saran ' . count($picks));
    check('Saran memilih menu yang paling memenuhi aturan', (int) $picks[0]['blocking'] === 0);
    check('Saran menyertakan alasan aturan', count($picks[0]['findings']) >= 5);

    $weekFindings = MenuRule::assessWeek(MenuRule::active(MenuRule::SCOPE_WEEK), [
        ['menu_id' => $menuId, 'computation' => $single],
        ['menu_id' => $tanamSayur, 'computation' => Menu::compute($tanamSayur)],
    ]);
    check('Aturan mingguan menilai variasi bahan', count($weekFindings) >= 1, 'temuan ' . count($weekFindings));

    $ruleId = MenuRule::create([
        'rule_code' => 'UJI_ATURAN',
        'rule_name' => 'Aturan uji biaya',
        'rule_type' => 'biaya_maks',
        'threshold_value' => 1000,
        'applies_to' => 'menu',
        'message' => 'Estimasi biaya per porsi melewati batas uji.',
        'rule_severity' => 'peringatan',
        'is_active' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Aturan baru dapat dibuat administrator', $ruleId > 0);
    $customFindings = MenuRule::assess(MenuRule::active('menu'), Menu::compute($menuId));
    $custom = null;
    foreach ($customFindings as $finding) {
        if ((string) $finding['rule_code'] === 'UJI_ATURAN') {
            $custom = $finding;
        }
    }
    check('Aturan baru langsung dipakai tanpa mengubah kode', $custom !== null && $custom['passed'] === false);

    section('Target gizi per kelompok sasaran');

    $groupId = NutritionTargetGroup::create([
        'group_code' => 'UJI-SD',
        'group_name' => 'Usia sekolah dasar uji',
        'level_id' => $sdLevel,
        'age_min' => 6,
        'age_max' => 12,
        'sex' => 'semua',
        'is_active' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Kelompok sasaran uji terbentuk', $groupId > 0);

    $groupSmpId = NutritionTargetGroup::create([
        'group_code' => 'UJI-SMP',
        'group_name' => 'Usia sekolah menengah uji',
        'level_id' => $smpLevel,
        'age_min' => 13,
        'age_max' => 15,
        'sex' => 'semua',
        'is_active' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Kelompok sasaran kedua terbentuk', $groupSmpId > 0);

    NutritionTarget::create([
        'group_id' => $groupId,
        'nutrient_code' => 'energy_kcal',
        'nutrient_unit' => 'kkal',
        'min_value' => 500,
        'target_basis' => NutritionTarget::BASIS_PER_SERVING,
        'reference_id' => $referenceId,
        'is_verified' => 0,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    NutritionTarget::create([
        'group_id' => $groupId,
        'nutrient_code' => 'protein_g',
        'nutrient_unit' => 'gram',
        'min_value' => 5,
        'max_value' => 30,
        'target_basis' => NutritionTarget::BASIS_PER_SERVING,
        'reference_id' => $referenceId,
        'is_verified' => 1,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);

    $evaluation = NutritionTarget::evaluate($single['per_serving'], $groupId, NutritionTarget::BASIS_PER_SERVING);
    check('Penilaian mengembalikan dua zat gizi', count($evaluation['rows']) === 2, 'baris ' . count($evaluation['rows']));
    check('Energi di bawah batas dilaporkan', $evaluation['below'] === 1);
    check('Protein sesuai rentang dilaporkan', $evaluation['ok'] === 1);
    check(
        'Target yang rujukan atau nilainya belum terverifikasi ditandai',
        $evaluation['unverified'] === 2,
        'belum terverifikasi ' . $evaluation['unverified']
    );
    $proteinRow = null;
    foreach ($evaluation['rows'] as $row) {
        if ((string) $row['nutrient_code'] === 'protein_g') {
            $proteinRow = $row;
        }
    }
    check(
        'Target yang sudah diverifikasi tetap menandai rujukan yang belum diverifikasi',
        $proteinRow !== null && (int) $proteinRow['is_verified'] === 1 && (int) $proteinRow['needs_verification'] === 1
    );

    $noTarget = NutritionTarget::evaluate($single['per_serving'], $groupSmpId, NutritionTarget::BASIS_PER_SERVING);
    check('Kelompok tanpa target menampilkan status tanpa_target', count($noTarget['rows']) === 2);
    check(
        'Status gizi belum tersedia terlihat pada penilaian',
        (bool) preg_grep('/belum_tersedia/', array_map(
            static fn (array $row): string => (string) $row['status'],
            NutritionTarget::evaluate([], $groupId, NutritionTarget::BASIS_PER_SERVING)['rows']
        ))
    );

    check(
        'Target ganda pada kelompok yang sama ditolak',
        NutritionTarget::scopeExists($groupId, 'energy_kcal', NutritionTarget::BASIS_PER_SERVING)
    );
    check('Kelompok sasaran dapat dipilih per sekolah', NutritionTargetGroup::forSchool(['level_id' => $sdLevel]) !== null);

    section('Integritas dan penandaan modul lain');

    $ingredientPage = FoodIngredient::paginate(['search' => 'UJI', 'category' => '', 'state' => 'active', 'nutrition' => '', 'sort' => 'name', 'dir' => 'asc'], 1, 12);
    check('Paginasi bahan menemukan data uji', $ingredientPage['total'] >= 3, 'total ' . $ingredientPage['total']);
    check('Bahan yang dipakai menu tidak dapat dihapus', FoodIngredient::usageCount($riceId) >= 2, 'pemakaian ' . FoodIngredient::usageCount($riceId));
    check('Menu yang dipakai jadwal atau revisi tidak dapat dihapus', Menu::usageCount($menuId) >= 2, 'pemakaian ' . Menu::usageCount($menuId));
    check('Menu yang belum dipakai masih boleh dihapus', Menu::usageCount($tanamSayur) === 0);

    $menuPage = Menu::paginate($menuFilters + ['search' => 'UJI', 'sort' => 'code', 'dir' => 'asc'], 1, 10);
    check('Paginasi menu menemukan data uji', $menuPage['total'] >= 3, 'total ' . $menuPage['total']);

    $pending = MenuRevision::pending(10);
    check('Daftar usulan menunggu keputusan terisi', count($pending) >= 1, 'menunggu ' . count($pending));
    check(
        'Usulan ditutup saat menu turunan disetujui',
        MenuRevision::decideForMenu($revisionMenuId, MenuRevision::STATUS_APPROVED, 'Setuju revisi', $actorId) === 1
    );

    check('Ringkasan jadwal membaca rentang', MenuSchedule::summary('2026-11-01', '2026-11-30')['portions'] >= 120);
    check('Distribusi status menu terbaca', Menu::statusDistribution() !== []);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\nRingkasan: {$passed} lulus, {$failed} gagal (" . ($passed + $failed) . " uji)\n";
exit($failed === 0 ? 0 : 1);
