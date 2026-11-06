<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BeneficiaryCategory;
use App\Models\CatalogCategory;
use App\Models\IngredientCategory;
use App\Models\MenuCategory;
use App\Models\SchoolLevel;

/**
 * Data master MBG yang berformat kode dan nama: jenjang pendidikan, kategori penerima
 * manfaat, kategori bahan, dan kategori menu. Keempat tabel memakai basis model yang
 * sama, jadi satu controller ini dipakai bersama supaya tidak ada formulir duplikat.
 *
 * Rutenya hanya bisa dibuka bila pengguna punya salah satu hak kelola data master.
 * Hak spesifik per jenis data diperiksa lagi di sini, karena PermissionMiddleware
 * membaca daftar hak yang dipisahkan koma sebagai "salah satu".
 */
final class MbgCatalogController extends Controller
{
    /** @var array<string, array<string, mixed>> */
    private const KINDS = [
        'jenjang' => [
            'model' => SchoolLevel::class,
            'code' => 'level_code',
            'name' => 'level_name',
            'permission' => 'schools.manage',
            'title' => 'Jenjang Pendidikan',
            'usage' => 'school_count',
            'usage_label' => 'sekolah memakai jenjang ini',
            'slots' => false,
        ],
        'kategori-penerima' => [
            'model' => BeneficiaryCategory::class,
            'code' => 'category_code',
            'name' => 'category_name',
            'permission' => 'beneficiaries.manage',
            'title' => 'Kategori Penerima Manfaat',
            'usage' => 'record_count',
            'usage_label' => 'baris data memakai kategori ini',
            'slots' => false,
        ],
        'kategori-bahan' => [
            'model' => IngredientCategory::class,
            'code' => 'category_code',
            'name' => 'category_name',
            'permission' => 'nutrition.manage',
            'title' => 'Kategori Bahan Makanan',
            'usage' => 'ingredient_count',
            'usage_label' => 'bahan memakai kategori ini',
            'slots' => false,
        ],
        'kategori-menu' => [
            'model' => MenuCategory::class,
            'code' => 'category_code',
            'name' => 'category_name',
            'permission' => 'menus.manage',
            'title' => 'Kategori Menu',
            'usage' => 'menu_count',
            'usage_label' => 'menu memakai kategori ini',
            'slots' => true,
        ],
    ];

    public function index(Request $request, string $kind): void
    {
        $type = $this->kind($kind);
        $this->guard($type);

        /** @var class-string<CatalogCategory> $model */
        $model = $type['model'];
        $term = mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60);
        $state = in_array($request->string('status'), ['active', 'inactive'], true) ? $request->string('status') : '';

        $old = $this->pullOld();
        $form = $this->emptyForm($type);
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        $this->view('mbg/master', [
            'title' => (string) $type['title'],
            'pageTitle' => 'Data Master: ' . $type['title'],
            'kind' => $kind,
            'type' => $type,
            'rows' => $model::search($term, $state),
            'form' => $form,
            'term' => $term,
            'state' => $state,
            'slots' => MenuCategory::SLOTS,
            'canManage' => $this->allowed($type),
        ]);
    }

    public function store(Request $request, string $kind): void
    {
        $type = $this->kind($kind);
        $this->guard($type);
        $actor = $this->actor();

        /** @var class-string<CatalogCategory> $model */
        $model = $type['model'];
        $input = $this->readForm($request, $type);
        [$data, $errors] = $this->validate($model, $type, $input, null);

        if ($errors !== []) {
            $this->reject($this->path($kind), $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = $model::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'mbg.catalog.create',
            'mbg',
            'Menambah ' . strtolower((string) $type['title']) . ' "' . $data[$type['code']] . ' '
            . $data[$type['name']] . '" dengan urutan ' . (int) $data['sort_order'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', $type['title'] . ' "' . $data[$type['name']] . '" berhasil ditambahkan.');
        $this->redirect($this->path($kind));
    }

    public function update(Request $request, string $kind, string $id): void
    {
        $type = $this->kind($kind);
        $this->guard($type);
        $actor = $this->actor();

        /** @var class-string<CatalogCategory> $model */
        $model = $type['model'];
        $row = $this->findRow($model, $id);
        $input = $this->readForm($request, $type);
        [$data, $errors] = $this->validate($model, $type, $input, (int) $row['id']);

        if ($errors !== []) {
            $this->reject($this->path($kind), $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        $model::update((int) $row['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'mbg.catalog.update',
            'mbg',
            'Memperbarui ' . strtolower((string) $type['title']) . ' "' . $data[$type['code']] . ' '
            . $data[$type['name']] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', $type['title'] . ' "' . $data[$type['name']] . '" diperbarui.');
        $this->redirect($this->path($kind));
    }

    /** Data master yang sudah dipakai tidak boleh dihapus; gunakan nonaktif. */
    public function destroy(Request $request, string $kind, string $id): void
    {
        $type = $this->kind($kind);
        $this->guard($type);
        $actor = $this->actor();

        /** @var class-string<CatalogCategory> $model */
        $model = $type['model'];
        $row = $this->findRow($model, $id);
        $rowId = (int) $row['id'];

        $usage = $model::usageCount($rowId);
        if ($usage > 0) {
            Session::flash(
                'danger',
                $type['title'] . ' "' . $row[$type['name']] . '" masih dipakai pada ' . $usage . ' '
                . $type['usage_label'] . '. Nonaktifkan agar datanya tidak hilang.'
            );
            $this->redirect($this->path($kind));
        }

        $model::delete($rowId);

        ActivityLog::record(
            (int) $actor['id'],
            'mbg.catalog.delete',
            'mbg',
            'Menghapus ' . strtolower((string) $type['title']) . ' "' . $row[$type['code']] . ' '
            . $row[$type['name']] . '" yang belum dipakai data lain.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $type['title'] . ' "' . $row[$type['name']] . '" dihapus.');
        $this->redirect($this->path($kind));
    }

    private function path(string $kind): string
    {
        return '/mbg/master/' . $kind;
    }

    /** @return array<string, mixed> */
    private function kind(string $kind): array
    {
        $type = self::KINDS[$kind] ?? null;
        if ($type === null) {
            throw new NotFoundHttpException();
        }

        return $type;
    }

    /** @param array<string, mixed> $type */
    private function allowed(array $type): bool
    {
        return can((string) $type['permission']);
    }

    /** @param array<string, mixed> $type */
    private function guard(array $type): void
    {
        if ($this->allowed($type)) {
            return;
        }

        // Rute daftar ini dijaga dengan "salah satu hak", jadi hak yang tepat untuk
        // jenis data ini tetap diperiksa di sini sebelum data dibaca atau ditulis.
        throw new HttpException(403, 'Halaman ini memerlukan hak ' . $type['permission'] . '.');
    }

    /**
     * @param class-string<CatalogCategory> $model
     * @return array<string, mixed>
     */
    private function findRow(string $model, string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $row = $model::find((int) $id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    /** @return array<string, string> */
    private function emptyForm(array $type): array
    {
        $form = [
            $type['code'] => '',
            $type['name'] => '',
            'description' => '',
            'sort_order' => '0',
            'is_active' => '1',
        ];

        if ($type['slots'] === true) {
            $form['meal_slot'] = '';
            $form['requires_verification'] = '0';
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $type
     * @return array<string, string>
     */
    private function readForm(Request $request, array $type): array
    {
        $fields = [$type['code'], $type['name'], 'description', 'sort_order', 'is_active'];
        if ($type['slots'] === true) {
            $fields[] = 'meal_slot';
            $fields[] = 'requires_verification';
        }

        $input = [];
        foreach ($fields as $field) {
            $input[$field] = (string) $request->input($field, '');
        }

        return $input;
    }

    /**
     * @param class-string<CatalogCategory> $model
     * @param array<string, mixed> $type
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(string $model, array $type, array $input, ?int $exceptId): array
    {
        $errors = [];

        $codeColumn = (string) $type['code'];
        $nameColumn = (string) $type['name'];

        $code = mb_strtoupper(preg_replace('/\s+/', '', trim($input[$codeColumn])) ?? '');
        $name = preg_replace('/\s+/', ' ', trim($input[$nameColumn])) ?? '';
        $description = preg_replace('/\s+/', ' ', trim($input['description'])) ?? '';
        $sortOrder = trim($input['sort_order']);

        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,29}$/', $code)) {
            $errors[] = 'Kode harus 2-30 karakter tanpa spasi, diawali huruf atau angka.';
        } elseif ($model::codeExists($code, $exceptId)) {
            $errors[] = 'Kode "' . $code . '" sudah dipakai.';
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors[] = 'Nama harus terdiri dari 2 sampai 100 karakter.';
        } elseif ($model::nameExists($name, $exceptId)) {
            $errors[] = 'Nama "' . $name . '" sudah terdaftar.';
        }

        if (mb_strlen($description) > 255) {
            $errors[] = 'Keterangan maksimal 255 karakter.';
        }

        if (preg_match('/^-?\d{1,6}$/', $sortOrder) !== 1) {
            $errors[] = 'Urutan tampil harus angka antara -999999 sampai 999999.';
        }

        $data = [
            $codeColumn => $code,
            $nameColumn => $name,
            'description' => $description === '' ? null : $description,
            'sort_order' => (int) $sortOrder,
            'is_active' => ($input['is_active'] ?? '') === '1' || $input['is_active'] === 'on',
        ];

        if ($type['slots'] === true) {
            $slot = mb_strtolower(trim($input['meal_slot'] ?? ''));
            if (!MenuCategory::slotExists($slot)) {
                $errors[] = 'Slot waktu makan wajib dipilih dari daftar yang tersedia.';
            }

            $data['meal_slot'] = $slot;
            $data['requires_verification'] = ($input['requires_verification'] ?? '') === '1';
        }

        return $errors === [] ? [$data, []] : [null, $errors];
    }

    /** @param array<string, string> $input */
    private function reject(string $path, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect($path);
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
    }
}
