<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\IngredientCategory;
use App\Models\IngredientNutrient;
use App\Models\Menu;
use App\Models\MenuRule;
use App\Models\NutritionTarget;

/**
 * Aturan rekomendasi dan penilaian menu. Aturan disimpan sebagai data sehingga ahli gizi
 * atau pemegang hak menus.manage dapat mengubah ambang, pesan, dan tingkatannya tanpa
 * menyentuh kode aplikasi. Halaman ini juga menampilkan hasil uji aturan terhadap satu
 * menu supaya alasan di balik sebuah temuan dapat dibaca, bukan hanya statusnya.
 *
 * Keputusan akhir tetap pemeriksaan manusia: aturan hanya memberi tahu menu mana yang
 * belum memenuhi batas yang dikonfigurasi.
 */
final class MenuRuleController extends Controller
{
    /** @var array<string, array{scope: string, codes: string}> bentuk aturan yang sah */
    private const SHAPE = [
        'kategori_min' => ['scope' => MenuRule::SCOPE_MENU, 'codes' => 'category'],
        'kategori_max' => ['scope' => MenuRule::SCOPE_MENU, 'codes' => 'category'],
        'gizi_min' => ['scope' => MenuRule::SCOPE_MENU, 'codes' => 'nutrient'],
        'gizi_maks' => ['scope' => MenuRule::SCOPE_MENU, 'codes' => 'nutrient'],
        'biaya_maks' => ['scope' => MenuRule::SCOPE_MENU, 'codes' => 'none'],
        'variasi_min' => ['scope' => MenuRule::SCOPE_WEEK, 'codes' => 'none'],
    ];

    public function index(Request $request): void
    {
        $scope = $request->string('lingkup');
        $rules = MenuRule::allOrdered();

        if (in_array($scope, [MenuRule::SCOPE_MENU, MenuRule::SCOPE_WEEK], true)) {
            $rules = array_values(array_filter(
                $rules,
                static fn (array $row): bool => (string) $row['applies_to'] === $scope
            ));
        } else {
            $scope = '';
        }

        $this->view('aturan/index', [
            'title' => 'Aturan Rekomendasi Menu',
            'pageTitle' => 'Aturan Rekomendasi dan Penilaian Menu',
            'rules' => $rules,
            'scope' => $scope,
            'types' => MenuRule::TYPES,
            'severities' => MenuRule::SEVERITIES,
            'scopes' => [MenuRule::SCOPE_MENU => 'Satu menu', MenuRule::SCOPE_WEEK => 'Rencana mingguan'],
            'categories' => IngredientCategory::activeList(),
            'nutrients' => $this->nutrientOptions(),
            'shape' => self::SHAPE,
            'activeMenu' => MenuRule::active(MenuRule::SCOPE_MENU),
            'activeWeek' => MenuRule::active(MenuRule::SCOPE_WEEK),
            'canManage' => can('menus.manage'),
            'canViewMenus' => can('menus.view'),
        ]);
    }

    /** Uji transparan: jalankan aturan aktif pada satu menu beserta angka dasarnya. */
    public function check(Request $request, string $id): void
    {
        $menuId = ctype_digit($id) ? (int) $id : 0;
        $menu = $menuId > 0 ? Menu::findComputed($menuId) : null;
        if ($menu === null) {
            throw new NotFoundHttpException();
        }

        $computation = Menu::compute($menuId);

        $this->view('aturan/uji', [
            'title' => 'Uji Aturan ' . (string) $menu['menu_name'],
            'pageTitle' => 'Hasil Uji Aturan pada Satu Menu',
            'menu' => $menu,
            'computation' => $computation,
            'findings' => MenuRule::assess(MenuRule::active(MenuRule::SCOPE_MENU), $computation),
            'ceiling' => MenuRule::costCeiling(),
            'canManage' => can('menus.manage'),
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->read($request);
        [$data, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/aturan-menu', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = MenuRule::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'menu_rules.create',
            'menus',
            'Menambah aturan ' . $data['rule_code'] . ' "' . $input['rule_name'] . '" tingkat '
            . rule_severity_label($data['rule_severity']) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Aturan tersimpan dan langsung dipakai pada penilaian menu berikutnya.');
        $this->redirect('/aturan-menu#' . 'aturan-' . $id);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $ruleId = $this->numericId($id);
        $rule = $this->findRule($ruleId);

        $input = $this->read($request);
        [$data, $errors] = $this->validate($input, $ruleId);

        if ($errors !== []) {
            $this->reject('/aturan-menu', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        MenuRule::update($ruleId, $data);

        ActivityLog::record(
            (int) $actor['id'],
            'menu_rules.update',
            'menus',
            'Mengubah aturan ' . $rule['rule_code'] . ' menjadi "' . $input['rule_name'] . '" dengan ambang '
            . (string) ($data['threshold_value'] ?? '-') . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Aturan diperbarui. Menu yang sedang berjalan tidak diubah, hanya penilaian berikutnya.');
        $this->redirect('/aturan-menu#' . 'aturan-' . $ruleId);
    }

    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $ruleId = $this->numericId($id);
        $rule = $this->findRule($ruleId);

        if ((int) $rule['is_active'] === 1) {
            Session::flash('danger', 'Nonaktifkan dulu aturan ' . $rule['rule_code']
                . ' agar riwayat penilaiannya tidak hilang, baru hapus bila memang tidak diperlukan.');
            $this->redirect('/aturan-menu#' . 'aturan-' . $ruleId);
        }

        MenuRule::delete($ruleId);

        ActivityLog::record(
            (int) $actor['id'],
            'menu_rules.delete',
            'menus',
            'Menghapus aturan nonaktif ' . $rule['rule_code'] . ' "' . $rule['rule_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Aturan nonaktif dihapus. Aturan aktif lainnya tidak berubah.');
        $this->redirect('/aturan-menu');
    }

    /** @return array<string, mixed> */
    private function read(Request $request): array
    {
        $codes = $request->input('applies_codes', []);
        if (is_string($codes)) {
            $codes = explode(',', $codes);
        }

        $picked = [];
        foreach (is_array($codes) ? $codes : [] as $value) {
            $code = mb_strtoupper(trim((string) $value));
            if ($code !== '') {
                $picked[] = $code;
            }
        }

        return [
            'rule_code' => trim($request->string('rule_code')),
            'rule_name' => trim($request->string('rule_name')),
            'rule_type' => mb_strtolower(trim($request->string('rule_type', 'kategori_min'))),
            'applies_to' => mb_strtolower(trim($request->string('applies_to', MenuRule::SCOPE_MENU))),
            'applies_codes' => array_values(array_unique($picked)),
            'threshold_value' => str_replace(',', '.', trim($request->string('threshold_value'))),
            'message' => preg_replace('/\s+/', ' ', trim($request->string('message'))) ?? '',
            'rule_severity' => mb_strtolower(trim($request->string('rule_severity', 'peringatan'))),
            'is_active' => $request->boolean('is_active') ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];
        $type = (string) $input['rule_type'];
        $shape = self::SHAPE[$type] ?? null;

        $code = mb_strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', mb_substr(trim((string) $input['rule_code']), 0, 24)) ?? '');
        if ($code === '') {
            $code = MenuRule::nextCode();
        } elseif (MenuRule::codeExists($code, $exceptId)) {
            $errors[] = 'Kode ' . $code . ' sudah dipakai aturan lain.';
        }

        $name = preg_replace('/\s+/', ' ', trim((string) $input['rule_name'])) ?? '';
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Nama aturan wajib diisi dan maksimal 120 karakter.';
        }

        if ($shape === null) {
            $errors[] = 'Jenis aturan tidak dikenali. Pilih salah satu dari daftar jenis aturan.';

            return [null, $errors];
        }

        if ((string) $input['applies_to'] !== $shape['scope']) {
            $errors[] = rule_type_label($type) . ' hanya dinilai pada ' . $this->scopeLabel($shape['scope'])
                . ', jadi pilih lingkup tersebut agar aturan benar-benar dipakai.';
        }

        $codes = (array) $input['applies_codes'];
        if ($shape['codes'] === 'category') {
            $known = $this->categoryCodes();
            $unknown = array_values(array_diff($codes, $known));
            if ($codes === []) {
                $errors[] = 'Pilih minimal satu kode kategori bahan agar aturan dapat menghitung jumlahnya.';
            } elseif ($unknown !== []) {
                $errors[] = 'Kategori bahan "' . implode(', ', $unknown)
                    . '" tidak ada pada katalog. Perbarui kode kategori atau pilih dari daftar.';
            }
        } elseif ($shape['codes'] === 'nutrient') {
            if (count($codes) !== 1) {
                $errors[] = 'Aturan gizi hanya memuat satu kode zat gizi, misalnya ENERGY_KCAL.';
            } elseif (!$this->nutrientKnown(mb_strtolower($codes[0]))) {
                $errors[] = 'Kode zat gizi "' . $codes[0] . '" belum pernah dipakai pada katalog bahan'
                    . ' maupun target gizi, sehingga tidak akan pernah menemukan angka.';
            }
        } elseif ($codes !== []) {
            $errors[] = rule_type_label($type) . ' tidak memakai kode bahan atau zat gizi. Kosongkan daftarnya.';
        }

        if (trim((string) $input['threshold_value']) === '') {
            $errors[] = 'Ambang batas wajib diisi agar aturan punya pegangan yang dapat dibaca bersama.';
        }

        $threshold = $this->threshold((string) $input['threshold_value']);
        if ($threshold === null && trim((string) $input['threshold_value']) !== '') {
            $errors[] = 'Ambang batas harus angka non-negatif, maksimal 4 angka setelah koma.';
        }

        if ($type === 'variasi_min' && $threshold !== null && $threshold < 1) {
            $errors[] = 'Aturan variasi mingguan membutuhkan ambang minimal 1 jenis bahan.';
        }

        $message = (string) $input['message'];
        if (mb_strlen($message) < 10) {
            $errors[] = 'Pesan aturan wajib ditulis minimal 10 karakter agar alasannya transparan'
                . ' bagi penyusun menu maupun pemeriksa.';
        } elseif (mb_strlen($message) > 255) {
            $errors[] = 'Pesan aturan maksimal 255 karakter.';
        }

        if (!in_array((string) $input['rule_severity'], MenuRule::SEVERITIES, true)) {
            $errors[] = 'Tingkat aturan hanya menerima Informasi, Peringatan, atau Wajib.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'rule_code' => $code,
            'rule_name' => $name,
            'rule_type' => $type,
            'applies_codes' => implode(',', $codes),
            'threshold_value' => (string) $threshold,
            'applies_to' => $shape['scope'],
            'message' => $message,
            'rule_severity' => (string) $input['rule_severity'],
            'is_active' => $input['is_active'],
        ], []];
    }

    private function threshold(string $value): ?float
    {
        if ($value === '' || preg_match('/^\d{1,12}(\.\d{1,4})?$/', $value) !== 1) {
            return null;
        }

        return round((float) $value, 4);
    }

    /** @return array<int, string> */
    private function categoryCodes(): array
    {
        return array_map(
            static fn (array $row): string => mb_strtoupper((string) $row['category_code']),
            IngredientCategory::activeList()
        );
    }

    private function nutrientKnown(string $code): bool
    {
        if ($code === '' || isset(IngredientNutrient::CORE_LABELS[$code])) {
            return $code !== '';
        }

        return in_array($code, IngredientNutrient::usedCodes(), true)
            || in_array($code, NutritionTarget::usedCodes(), true);
    }

    private function scopeLabel(string $scope): string
    {
        return $scope === MenuRule::SCOPE_WEEK ? 'lingkup rencana mingguan' : 'lingkup satu menu';
    }

    /** @return array<string, string> kode zat gizi => label untuk pilihan pada formulir */
    private function nutrientOptions(): array
    {
        $usedIngredientCodes = array_map(
            static fn (array $item): string => (string) ($item['nutrient_code'] ?? ''),
            IngredientNutrient::usedCodes()
        );

        $codes = array_values(array_unique(array_filter(array_merge(
            IngredientNutrient::CORE_CODES,
            $usedIngredientCodes,
            NutritionTarget::usedCodes()
        ), static fn (string $code): bool => $code !== '')));

        return array_combine($codes, array_map(static fn (string $code): string => nutrient_label($code), $codes)) ?: [];
    }

    /** @return array<string, mixed> */
    private function findRule(int $id): array
    {
        $rule = MenuRule::find($id);
        if ($rule === null) {
            throw new NotFoundHttpException();
        }

        return $rule;
    }

    private function numericId(string $id): int
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $input */
    private function reject(string $path, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect($path);
    }
}
