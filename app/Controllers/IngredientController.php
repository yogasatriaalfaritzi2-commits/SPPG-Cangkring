<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csv;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\FoodIngredient;
use App\Models\IngredientCategory;
use App\Models\IngredientNutrient;
use App\Models\Menu;
use App\Models\NutritionReference;

/**
 * Katalog bahan makanan MBG beserta angka gizinya dan daftar sumber referensi gizi.
 *
 * Angka gizi tidak boleh dikarang: nilai bersumber referensi wajib menunjuk satu
 * entri referensi, sedangkan nilai perkiraan harus diberi catatan dan ditandai sebagai
 * estimasi supaya tidak pernah terbaca sebagai hasil laboratorium. Satuan dasar,
 * satuan beli, dan faktor konversi ikut diperiksa agar perhitungan kebutuhan bahan
 * tidak salah akibat ketidaksesuaian satuan.
 */
final class IngredientController extends Controller
{
    private const PER_PAGE = 15;

    /** @var array<string, string> satuan yang dikenali katalog dan calculator */
    public const UNITS = [
        'gram' => 'gram',
        'kilogram' => 'kilogram',
        'mililiter' => 'mililiter',
        'liter' => 'liter',
        'butir' => 'butir',
        'sachet' => 'sachet',
        'pack' => 'pak',
        'pcs' => 'pcs',
    ];

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $result = FoodIngredient::paginate($filters, max(1, $request->integer('page', 1)), self::PER_PAGE);

        $this->view('bahan/index', [
            'title' => 'Katalog Bahan Makanan',
            'pageTitle' => 'Katalog Bahan Makanan MBG',
            'filters' => $filters,
            'result' => $result,
            'ingredients' => $result['rows'],
            'categories' => IngredientCategory::activeList(),
            'nutritionStates' => ['lengkap', 'sebagian', 'belum_tersedia'],
            'basePath' => '/bahan',
            'canManage' => can('nutrition.manage'),
        ]);
    }

    public function create(Request $request): void
    {
        $old = $this->pullOld();
        $form = $this->emptyForm();
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        if ((string) $form['ingredient_code'] === '') {
            $form['ingredient_code'] = FoodIngredient::nextCode();
        }

        $this->view('bahan/form', [
            'title' => 'Tambah Bahan',
            'pageTitle' => 'Tambah Bahan Makanan',
            'mode' => 'create',
            'form' => $form,
            'ingredient' => null,
            'categories' => IngredientCategory::activeList(),
            'units' => self::UNITS,
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/bahan/tambah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = FoodIngredient::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'ingredients.create',
            'nutrition',
            'Menambah bahan "' . $data['ingredient_code'] . ' ' . $data['ingredient_name'] . '"'
            . ' dengan satuan dasar ' . $data['base_unit'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Bahan "' . $data['ingredient_name'] . '" ditambahkan. Isi angka gizinya bila sudah ada sumbernya.');
        $this->redirect('/bahan/' . $id);
    }

    public function show(Request $request, string $id): void
    {
        $ingredient = $this->findIngredient($id);
        $nutrients = IngredientNutrient::forIngredient((int) $ingredient['id']);

        $this->view('bahan/show', [
            'title' => (string) $ingredient['ingredient_name'],
            'pageTitle' => 'Detail Bahan Makanan',
            'ingredient' => $ingredient,
            'nutrients' => $nutrients,
            'nutritionStatus' => $this->nutritionStatus($nutrients),
            'references' => NutritionReference::activeList(),
            'codes' => IngredientNutrient::usedCodes(),
            'coreCodes' => IngredientNutrient::CORE_CODES,
            'coreLabels' => IngredientNutrient::CORE_LABELS,
            'units' => self::UNITS,
            'form' => $this->emptyNutrientForm(),
            'canManage' => can('nutrition.manage'),
            'menuUsage' => FoodIngredient::usageCount((int) $ingredient['id']),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $ingredient = $this->findIngredient($id);
        $old = $this->pullOld();

        $form = [];
        foreach ($this->emptyForm() as $key => $default) {
            $value = $ingredient[$key] ?? $default;
            $form[$key] = (string) ($value === null ? '' : $value);
        }
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        $this->view('bahan/form', [
            'title' => 'Ubah Bahan',
            'pageTitle' => 'Ubah Bahan Makanan',
            'mode' => 'update',
            'form' => $form,
            'ingredient' => $ingredient,
            'categories' => IngredientCategory::activeList(),
            'units' => self::UNITS,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $ingredient = $this->findIngredient($id);
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, (int) $ingredient['id']);

        if ($errors !== []) {
            $this->reject('/bahan/' . (int) $ingredient['id'] . '/ubah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        FoodIngredient::update((int) $ingredient['id'], $data);

        $affected = $this->recalculateMenus([(int) $ingredient['id']], (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'ingredients.update',
            'nutrition',
            'Memperbarui bahan "' . $data['ingredient_code'] . ' ' . $data['ingredient_name'] . '.'
            . ($data['purchase_unit'] !== null ? ' Faktor konversi 1 ' . $data['purchase_unit'] . ' = '
                . rtrim(rtrim(number_format((float) $data['conversion_factor'], 4, ',', ''), '0'), '.')
                . ' ' . $data['base_unit'] : '') . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Data bahan diperbarui. ' . $this->recalcNotice($affected));
        $this->redirect('/bahan/' . (int) $ingredient['id']);
    }

    /** Bahan yang sudah dipakai menu tidak dihapus; gunakan nonaktif agar riwayat tetap terbaca. */
    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $ingredient = $this->findIngredient($id);
        $ingredientId = (int) $ingredient['id'];

        $usage = FoodIngredient::usageCount($ingredientId);
        if ($usage > 0) {
            Session::flash(
                'danger',
                'Bahan "' . $ingredient['ingredient_name'] . '" masih dipakai pada ' . $usage
                . ' baris menu. Nonaktifkan bahan agar riwayat menu tidak berubah.'
            );
            $this->redirect('/bahan/' . $ingredientId);
        }

        // Nilai gizi ikut hilang bersama bahannya, jadi jumlah baris gizinya dilaporkan.
        $nutrientCount = count(IngredientNutrient::forIngredient($ingredientId));
        FoodIngredient::delete($ingredientId);

        Session::flash('success', 'Bahan "' . $ingredient['ingredient_name'] . '" dan ' . $nutrientCount . ' baris gizinya dihapus.');
        ActivityLog::record(
            (int) $actor['id'],
            'ingredients.delete',
            'nutrition',
            'Menghapus bahan "' . $ingredient['ingredient_code'] . ' ' . $ingredient['ingredient_name']
            . '" yang belum dipakai menu beserta ' . $nutrientCount . ' baris nilai gizinya.',
            $request->ip(),
            $request->userAgent()
        );

        $this->redirect('/bahan');
    }

    public function storeNutrient(Request $request, string $id): void
    {
        $actor = $this->actor();
        $ingredient = $this->findIngredient($id);
        $input = $this->readNutrient($request);
        [$data, $errors] = $this->validateNutrient($input, (int) $ingredient['id'], null);

        if ($errors !== []) {
            $this->failIngredient((int) $ingredient['id'], $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['ingredient_id'] = (int) $ingredient['id'];
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        IngredientNutrient::create($data);

        $affected = $this->recalculateMenus([(int) $ingredient['id']], (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'ingredients.nutrient.create',
            'nutrition',
            'Menambah nilai gizi ' . nutrient_label((string) $data['nutrient_code']) . ' pada bahan "'
            . $ingredient['ingredient_name'] . '" dari sumber ' . $this->sourceLabel($data) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Nilai gizi tersimpan. ' . $this->recalcNotice($affected));
        $this->redirect('/bahan/' . (int) $ingredient['id']);
    }

    public function updateNutrient(Request $request, string $nutrient): void
    {
        $actor = $this->actor();
        $row = $this->findNutrient($nutrient);
        $ingredient = $this->findIngredient((string) $row['ingredient_id']);
        $input = $this->readNutrient($request);
        [$data, $errors] = $this->validateNutrient($input, (int) $ingredient['id'], (int) $row['id']);

        if ($errors !== []) {
            $this->failIngredient((int) $ingredient['id'], $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        IngredientNutrient::update((int) $row['id'], $data);

        $affected = $this->recalculateMenus([(int) $ingredient['id']], (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'ingredients.nutrient.update',
            'nutrition',
            'Memperbarui nilai gizi ' . nutrient_label((string) $data['nutrient_code']) . ' pada bahan "'
            . $ingredient['ingredient_name'] . '" dari ' . quantity_display($row['nutrient_value'], (string) $row['nutrient_unit'])
            . ' menjadi ' . quantity_display($data['nutrient_value'], (string) $data['nutrient_unit']) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Nilai gizi diperbarui. ' . $this->recalcNotice($affected));
        $this->redirect('/bahan/' . (int) $ingredient['id']);
    }

    public function destroyNutrient(Request $request, string $nutrient): void
    {
        $actor = $this->actor();
        $row = $this->findNutrient($nutrient);
        $ingredient = $this->findIngredient((string) $row['ingredient_id']);

        IngredientNutrient::delete((int) $row['id']);
        $affected = $this->recalculateMenus([(int) $ingredient['id']], (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'ingredients.nutrient.delete',
            'nutrition',
            'Menghapus nilai gizi ' . nutrient_label((string) $row['nutrient_code']) . ' pada bahan "'
            . $ingredient['ingredient_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Nilai gizi dihapus. ' . $this->recalcNotice($affected));
        $this->redirect('/bahan/' . (int) $ingredient['id']);
    }

    public function references(Request $request): void
    {
        $term = mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60);
        $old = $this->pullOld();

        $form = [
            'reference_code' => '',
            'reference_name' => '',
            'publisher' => '',
            'published_year' => '',
            'reference_url' => '',
            'needs_verification' => '1',
            'is_active' => '1',
        ];
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        $rows = [];
        foreach (NutritionReference::allOrdered() as $row) {
            if ($term !== ''
                && stripos((string) $row['reference_code'] . ' ' . $row['reference_name'], $term) === false) {
                continue;
            }

            $rows[] = $row;
        }

        $this->view('bahan/referensi', [
            'title' => 'Sumber Data Gizi',
            'pageTitle' => 'Sumber Referensi Data Gizi',
            'references' => $rows,
            'form' => $form,
            'term' => $term,
            'canManage' => can('nutrition.manage'),
        ]);
    }

    public function storeReference(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readReference($request);
        [$data, $errors] = $this->validateReference($input, null);

        if ($errors !== []) {
            $this->reject('/referensi-gizi', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = NutritionReference::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition.references.create',
            'nutrition',
            'Menambah sumber referensi gizi "' . $data['reference_code'] . ' ' . $data['reference_name'] . '"'
            . ($data['needs_verification'] ? ' dengan tanda belum diverifikasi.' : '.'),
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Sumber referensi "' . $data['reference_name'] . '" tersimpan.');
        $this->redirect('/referensi-gizi');
    }

    public function updateReference(Request $request, string $id): void
    {
        $actor = $this->actor();
        $reference = $this->findReference($id);
        $input = $this->readReference($request);
        [$data, $errors] = $this->validateReference($input, (int) $reference['id']);

        if ($errors !== []) {
            $this->reject('/referensi-gizi', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        NutritionReference::update((int) $reference['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition.references.update',
            'nutrition',
            'Memperbarui sumber referensi gizi "' . $data['reference_code'] . ' ' . $data['reference_name'] . '."',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Sumber referensi diperbarui.');
        $this->redirect('/referensi-gizi');
    }

    public function destroyReference(Request $request, string $id): void
    {
        $actor = $this->actor();
        $reference = $this->findReference($id);
        $referenceId = (int) $reference['id'];

        $usage = NutritionReference::usageCount($referenceId);
        if ($usage > 0) {
            Session::flash('danger', 'Referensi masih dipakai ' . $usage . ' baris angka gizi atau target. Nonaktifkan saja.');
            $this->redirect('/referensi-gizi');
        }

        NutritionReference::delete($referenceId);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition.references.delete',
            'nutrition',
            'Menghapus sumber referensi "' . $reference['reference_code'] . ' ' . $reference['reference_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Sumber referensi dihapus.');
        $this->redirect('/referensi-gizi');
    }

    public function export(Request $request): void
    {
        $filters = $this->filters($request);

        $rows = [];
        $page = 1;
        do {
            $result = FoodIngredient::paginate($filters, $page, 50);
            $map = FoodIngredient::nutrientMap(array_map(
                static fn (array $row): int => (int) $row['id'],
                $result['rows']
            ));

            foreach ($result['rows'] as $row) {
                $nutrients = $map[(int) $row['id']] ?? [];
                $rows[] = [
                    $row['ingredient_code'],
                    $row['ingredient_name'],
                    $row['category_name'],
                    $row['base_unit'],
                    (string) ($row['purchase_unit'] ?? ''),
                    (string) ($row['conversion_factor'] ?? ''),
                    (string) ($row['edible_factor'] ?? ''),
                    (string) ($row['allergen_info'] ?? ''),
                    (string) ($row['reference_price'] ?? ''),
                    (string) ($row['price_basis'] ?? ''),
                    (string) ($row['price_reference'] ?? ''),
                    (string) ($row['price_updated_at'] ?? ''),
                    status_label($row['is_active']),
                    $nutrients === [] ? 'Data gizi belum tersedia' : (string) count($nutrients) . ' zat gizi',
                ];
            }

            $page++;
        } while ($page <= $result['pages']);

        Csv::send(
            'katalog-bahan-' . date('Y-m-d') . '.csv',
            [
                'Kode', 'Nama Bahan', 'Kategori', 'Satuan Dasar', 'Satuan Beli', 'Faktor Konversi',
                'Bagian Dapat Dimakan', 'Alergen', 'Harga Rujukan', 'Dasar Harga', 'Sumber Harga',
                'Tanggal Harga', 'Status', 'Kelengkapan Gizi',
            ],
            $rows
        );
    }

    /** Status kelengkapan gizi berdasarkan baris nilai yang tersimpan. @param array<int, array<string, mixed>> $nutrients */
    private function nutritionStatus(array $nutrients): string
    {
        if ($nutrients === []) {
            return 'belum_tersedia';
        }

        $codes = array_column($nutrients, 'nutrient_code');
        $core = array_intersect($codes, IngredientNutrient::CORE_CODES);

        return count($core) >= count(IngredientNutrient::CORE_CODES) ? 'lengkap' : 'sebagian';
    }

    /** Ulangi perhitungan menu draf yang memakai bahan ini agar nilainya ikut terbaru. */
    private function recalculateMenus(array $ingredientIds, int $actorId): int
    {
        $menus = Menu::recalculableByIngredients($ingredientIds);
        foreach ($menus as $menu) {
            Menu::recalculate((int) $menu['id'], $actorId);
        }

        return count($menus);
    }

    /** Pesan yang menyatakan berapa menu draf ikut dihitung ulang, tanpa melebihkan. */
    private function recalcNotice(int $affected): string
    {
        if ($affected === 0) {
            return 'Menu yang sudah disetujui tidak diubah; ajukan revisi bila angkanya perlu diperbarui.';
        }

        return $affected . ' menu draf yang memakai bahan ini dihitung ulang.';
    }

    /** @param array<string, mixed> $data */
    private function sourceLabel(array $data): string
    {
        if ((string) ($data['value_source'] ?? '') === 'estimasi') {
            return 'perkiraan internal';
        }

        $reference = NutritionReference::find((int) ($data['reference_id'] ?? 0));

        return $reference === null ? 'tanpa sumber' : (string) $reference['reference_name'];
    }

    /** @return array{search: string, category: string, state: string, nutrition: string, sort: string, dir: string} */
    private function filters(Request $request): array
    {
        $sort = $request->string('sort');
        $nutrition = $request->string('gizi');

        return [
            'search' => mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60),
            'category' => (string) max(0, $request->integer('kategori')),
            'state' => in_array($request->string('status'), ['active', 'inactive'], true) ? $request->string('status') : '',
            'nutrition' => in_array($nutrition, ['lengkap', 'sebagian', 'belum_tersedia'], true) ? $nutrition : '',
            'sort' => isset(FoodIngredient::SORTABLE[$sort]) ? $sort : 'name',
            'dir' => strtolower($request->string('dir')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @return array<string, string> */
    private function emptyForm(): array
    {
        return [
            'ingredient_code' => '',
            'ingredient_name' => '',
            'category_id' => '',
            'base_unit' => 'gram',
            'purchase_unit' => '',
            'conversion_factor' => '',
            'edible_factor' => '1',
            'allergen_info' => '',
            'reference_price' => '',
            'price_basis' => '',
            'price_reference' => '',
            'price_updated_at' => '',
            'is_active' => '1',
            'notes' => '',
        ];
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        $input = [];
        foreach (array_keys($this->emptyForm()) as $field) {
            $input[$field] = trim((string) $request->input($field, ''));
        }

        return $input;
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', '', $input['ingredient_code']) ?? '');
        $name = preg_replace('/\s+/', ' ', trim($input['ingredient_name'])) ?? '';
        $categoryId = (int) $input['category_id'];

        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,29}$/', $code)) {
            $errors[] = 'Kode bahan harus 3-30 karakter tanpa spasi, diawali huruf atau angka.';
        } elseif (FoodIngredient::codeExists($code, $exceptId)) {
            $errors[] = 'Kode bahan "' . $code . '" sudah dipakai.';
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors[] = 'Nama bahan harus terdiri dari 2 sampai 120 karakter.';
        }

        $category = $categoryId > 0 ? IngredientCategory::find($categoryId) : null;
        if ($category === null) {
            $errors[] = 'Kategori bahan wajib dipilih.';
        }

        $baseUnit = mb_strtolower($input['base_unit']);
        if (!isset(self::UNITS[$baseUnit])) {
            $errors[] = 'Satuan dasar bahan tidak dikenali.';
        }

        $purchaseUnit = mb_strtolower($input['purchase_unit']);
        $factor = str_replace(',', '.', trim($input['conversion_factor']));

        if ($purchaseUnit !== '') {
            if (!isset(self::UNITS[$purchaseUnit])) {
                $errors[] = 'Satuan beli tidak dikenali, kosongkan bila bahan dibeli dengan satuan dasar.';
            } elseif ($purchaseUnit === $baseUnit) {
                $errors[] = 'Satuan beli sama dengan satuan dasar, jadi faktor konversi tidak diperlukan.';
            } elseif ($factor === '') {
                $errors[] = 'Faktor konversi wajib diisi karena satuan beli berbeda dari satuan dasar.';
            }
        }

        if ($factor !== '' && (preg_match('/^\d{1,7}(\.\d{1,4})?$/', $factor) !== 1 || (float) $factor <= 0)) {
            $errors[] = 'Faktor konversi harus angka positif, misalnya 500 untuk 1 pak = 500 gram.';
        }

        $edible = str_replace(',', '.', trim($input['edible_factor']));
        if ($edible !== '' && (preg_match('/^\d{1,2}(\.\d{1,4})?$/', $edible) !== 1 || (float) $edible <= 0 || (float) $edible > 1)) {
            $errors[] = 'Bagian yang dapat dimakan diisi angka antara 0 sampai 1, misalnya 0.85.';
        }

        $price = amount_normalize($input['reference_price']);
        if ($input['reference_price'] !== '' && $price === null) {
            $errors[] = 'Harga rujukan harus angka rupiah, misalnya 14.000.';
        }

        $priceBasis = mb_strtolower($input['price_basis']);
        if ($priceBasis !== '' && !isset(self::UNITS[$priceBasis])) {
            $errors[] = 'Dasar harga tidak dikenali, pilih salah satu satuan yang tersedia.';
        }

        if ($price !== null && (float) $price > 0 && $priceBasis === '') {
            $errors[] = 'Dasar harga wajib diisi ketika harga rujukan diisi, misalnya per kilogram atau per butir.';
        }

        $priceDate = str_replace('/', '-', $input['price_updated_at']);
        if ($priceDate !== '' && !date_is_valid($priceDate)) {
            $errors[] = 'Tanggal pembaruan harga memakai format YYYY-MM-DD.';
        }

        if ($input['price_updated_at'] !== '' && $priceDate > date('Y-m-d')) {
            $errors[] = 'Tanggal pembaruan harga belum boleh di masa depan.';
        }

        if (mb_strlen($input['allergen_info']) > 255) {
            $errors[] = 'Informasi alergen maksimal 255 karakter.';
        }
        if (mb_strlen($input['price_reference']) > 150) {
            $errors[] = 'Sumber harga maksimal 150 karakter.';
        }
        if (mb_strlen($input['notes']) > 500) {
            $errors[] = 'Catatan maksimal 500 karakter.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'ingredient_code' => $code,
            'ingredient_name' => $name,
            'category_id' => $categoryId,
            'base_unit' => $baseUnit,
            'purchase_unit' => $purchaseUnit === '' ? null : $purchaseUnit,
            'conversion_factor' => $factor === '' ? null : (float) $factor,
            'edible_factor' => $edible === '' ? null : (float) $edible,
            'allergen_info' => $input['allergen_info'] === '' ? null : $input['allergen_info'],
            'reference_price' => $price === null ? null : (float) $price,
            'price_basis' => $priceBasis === '' ? null : $priceBasis,
            'price_reference' => $input['price_reference'] === '' ? null : $input['price_reference'],
            'price_updated_at' => $priceDate === '' ? null : $priceDate,
            'is_active' => $input['is_active'] === '1',
            'notes' => $input['notes'] === '' ? null : $input['notes'],
        ], []];
    }

    /** @return array<string, string> */
    private function emptyNutrientForm(): array
    {
        return [
            'nutrient_code' => '',
            'nutrient_value' => '',
            'nutrient_unit' => 'gram',
            'reference_amount' => '100',
            'reference_unit' => 'gram',
            'value_source' => 'referensi',
            'reference_id' => '',
            'data_updated_at' => date('Y-m-d'),
            'notes' => '',
        ];
    }

    /** @return array<string, string> */
    private function readNutrient(Request $request): array
    {
        $input = [];
        foreach (array_keys($this->emptyNutrientForm()) as $field) {
            $input[$field] = trim((string) $request->input($field, ''));
        }

        return $input;
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validateNutrient(array $input, int $ingredientId, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtolower(preg_replace('/\s+/', '_', $input['nutrient_code']) ?? '');
        $value = str_replace(',', '.', $input['nutrient_value']);
        $unit = mb_strtolower($input['nutrient_unit']);
        $amount = str_replace(',', '.', $input['reference_amount']);
        $refUnit = mb_strtolower($input['reference_unit']);
        $source = $input['value_source'] === 'estimasi' ? 'estimasi' : 'referensi';
        $referenceId = (int) $input['reference_id'];
        $updated = str_replace('/', '-', $input['data_updated_at']);

        if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', $code)) {
            $errors[] = 'Kode zat gizi harus 2-30 karakter huruf kecil, angka, atau garis bawah, misalnya protein_g.';
        }

        if (preg_match('/^\d{1,9}(\.\d{1,4})?$/', $value) !== 1 || (float) $value < 0) {
            $errors[] = 'Nilai gizi harus angka positif, misalnya 21.5.';
        }

        if (!in_array($unit, ['kkal', 'gram', 'mg', 'mcg', 'ug', 'persen'], true)) {
            $errors[] = 'Satuan nilai gizi tidak dikenali. Gunakan kkal, gram, mg, ug, atau persen.';
        }

        if (preg_match('/^\d{1,7}(\.\d{1,3})?$/', $amount) !== 1 || (float) $amount <= 0) {
            $errors[] = 'Dasar penyajian angka gizi harus angka positif, umumnya 100.';
        }

        if (!isset(self::UNITS[$refUnit])) {
            $errors[] = 'Satuan dasar penyajian tidak dikenali.';
        }

        if ($code !== '' && isset(IngredientNutrient::CORE_UNITS[$code])
            && $unit !== '' && $unit !== IngredientNutrient::CORE_UNITS[$code]) {
            $errors[] = 'Zat gizi ' . nutrient_label($code) . ' dipakai dengan satuan '
                . IngredientNutrient::CORE_UNITS[$code] . ', jadi satuannya jangan dicampur.';
        }

        if ($source === 'referensi' && $referenceId <= 0) {
            $errors[] = 'Nilai gizi bersumber referensi wajib menunjuk satu sumber referensi. Isi perkiraan bila belum ada sumbernya.';
        } elseif ($source === 'estimasi' && mb_strlen($input['notes']) < 5) {
            $errors[] = 'Nilai perkiraan wajib diberi catatan asal angkanya agar tidak terbaca sebagai hasil laboratorium.';
        }

        if ($referenceId > 0 && NutritionReference::find($referenceId) === null) {
            $errors[] = 'Sumber referensi yang dipilih tidak ditemukan.';
        }

        if ($updated !== '' && (!date_is_valid($updated) || $updated > date('Y-m-d'))) {
            $errors[] = 'Tanggal pembaruan data gizi tidak valid, gunakan YYYY-MM-DD sampai hari ini.';
        }

        if (mb_strlen($input['notes']) > 255) {
            $errors[] = 'Catatan maksimal 255 karakter.';
        }

        if ($errors === [] && IngredientNutrient::scopeExists($ingredientId, $code, $exceptId)) {
            $errors[] = 'Bahan ini sudah punya angka untuk zat gizi "' . nutrient_label($code)
                . '". Perbarui baris yang ada, jangan membuat duplikat.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'nutrient_code' => $code,
            'nutrient_value' => (float) $value,
            'nutrient_unit' => $unit,
            'reference_amount' => (float) $amount,
            'reference_unit' => $refUnit,
            'value_source' => $source,
            'reference_id' => $referenceId > 0 ? $referenceId : null,
            'data_updated_at' => $updated === '' ? null : $updated,
            'notes' => $input['notes'] === '' ? null : $input['notes'],
        ], []];
    }

    /** @return array<string, string> */
    private function readReference(Request $request): array
    {
        $fields = ['reference_code', 'reference_name', 'publisher', 'published_year', 'reference_url', 'needs_verification', 'is_active'];
        $input = [];
        foreach ($fields as $field) {
            $input[$field] = trim((string) $request->input($field, ''));
        }

        return $input;
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validateReference(array $input, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', '', $input['reference_code']) ?? '');
        $name = preg_replace('/\s+/', ' ', trim($input['reference_name'])) ?? '';
        $year = trim($input['published_year']);
        $url = trim($input['reference_url']);

        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,29}$/', $code)) {
            $errors[] = 'Kode referensi harus 2-30 karakter tanpa spasi, misalnya TKPI-2017.';
        } elseif (NutritionReference::codeExists($code, $exceptId)) {
            $errors[] = 'Kode referensi sudah dipakai.';
        }

        if (mb_strlen($name) < 4 || mb_strlen($name) > 200) {
            $errors[] = 'Nama referensi harus terdiri dari 4 sampai 200 karakter.';
        }

        if (mb_strlen($input['publisher']) > 150) {
            $errors[] = 'Penerbit maksimal 150 karakter.';
        }

        if ($year !== '' && (preg_match('/^(19|20)\d{2}$/', $year) !== 1 || (int) $year > (int) date('Y'))) {
            $errors[] = 'Tahun terbit diisi 4 digit antara 1900 sampai ' . date('Y') . ' atau dikosongkan.';
        }

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Tautan referensi harus URL lengkap yang valid, misalnya https://Contoh.com/data-gizi.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'reference_code' => $code,
            'reference_name' => $name,
            'publisher' => $input['publisher'] === '' ? null : $input['publisher'],
            'published_year' => $year === '' ? null : (int) $year,
            'reference_url' => $url === '' ? null : $url,
            'needs_verification' => $input['needs_verification'] === '1',
            'is_active' => $input['is_active'] === '1',
        ], []];
    }

    /** @return array<string, mixed> */
    private function findIngredient(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $row = FoodIngredient::findWithNames((int) $id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function findNutrient(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $row = IngredientNutrient::find((int) $id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function findReference(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $row = NutritionReference::find((int) $id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    /** @param array<string, string> $input */
    private function failIngredient(int $id, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect('/bahan/' . $id);
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
    }

    /** @param array<string, string> $input */
    private function reject(string $path, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect($path);
    }
}
