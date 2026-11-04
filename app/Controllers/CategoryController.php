<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BudgetCategory;

/**
 * Kategori pekerjaan RAB. Dua belas kategori bawaan modul pembangunan dapat ditambah,
 * diubah, atau dinonaktifkan tanpa mengubah kode aplikasi.
 */
final class CategoryController extends Controller
{
    public function index(Request $request): void
    {
        $term = mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60);
        $state = $request->string('status');
        $sunting = $request->integer('sunting');

        $this->view('kategori/index', [
            'title' => 'Kategori Pekerjaan',
            'pageTitle' => 'Kategori Pekerjaan RAB',
            'categories' => BudgetCategory::search($term, in_array($state, ['active', 'inactive'], true) ? $state : ''),
            'editing' => $sunting > 0 ? BudgetCategory::find($sunting) : null,
            'search' => $term,
            'state' => $state,
            'old' => $this->pullOld(),
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/kategori', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = BudgetCategory::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.category.create',
            'budgets',
            'Menambah kategori pekerjaan "' . $data['category_code'] . ' ' . $data['category_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Kategori "' . $data['category_name'] . '" berhasil ditambahkan.');
        $this->redirect('/kategori');
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $category = $this->findCategory($id);
        $input = $this->readForm($request);

        [$data, $errors] = $this->validate($input, (int) $category['id']);

        if ($errors !== []) {
            $this->reject('/kategori?sunting=' . $category['id'], $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        BudgetCategory::update((int) $category['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.category.update',
            'budgets',
            'Memperbarui kategori "' . $category['category_code'] . '" menjadi "'
            . $data['category_code'] . ' ' . $data['category_name'] . '" ('
            . ($data['is_active'] ? 'aktif' : 'nonaktif') . ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Kategori "' . $data['category_name'] . '" berhasil diperbarui.');
        $this->redirect('/kategori');
    }

    /**
     * Kategori yang sudah dipakai item RAB atau transaksi tidak boleh dihapus.
     * Penonaktifan tetap menjaga riwayat lama dapat terbaca.
     */
    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $category = $this->findCategory($id);
        $usage = BudgetCategory::usageCount((int) $category['id']);

        if ($usage > 0) {
            Session::flash(
                'danger',
                'Kategori "' . $category['category_name'] . '" masih dipakai ' . $usage
                . ' catatan. Nonaktifkan saja agar riwayat lama tetap terbaca.'
            );
            $this->redirect('/kategori');
        }

        BudgetCategory::delete((int) $category['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.category.delete',
            'budgets',
            'Menghapus kategori "' . $category['category_code'] . ' ' . $category['category_name'] . '" yang belum dipakai.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Kategori "' . $category['category_name'] . '" telah dihapus.');
        $this->redirect('/kategori');
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'category_code' => (string) $request->input('category_code', ''),
            'category_name' => (string) $request->input('category_name', ''),
            'description' => (string) $request->input('description', ''),
            'sort_order' => (string) $request->input('sort_order', ''),
            'is_active' => (string) $request->input('is_active', ''),
        ];
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', '', trim($input['category_code'])) ?? '', 'UTF-8');
        $name = preg_replace('/\s+/', ' ', trim($input['category_name'])) ?? '';
        $description = trim($input['description']);
        $sort = trim($input['sort_order']);
        $active = $input['is_active'] === '1' || $input['is_active'] === 'on';

        if (!preg_match('/^[A-Z][A-Z0-9._-]{1,19}$/', $code)) {
            $errors[] = 'Kode kategori harus 2-20 karakter, diawali huruf kapital, tanpa spasi.';
        } elseif (BudgetCategory::codeExists($code, $exceptId)) {
            $errors[] = 'Kode kategori sudah dipakai.';
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            $errors[] = 'Nama kategori harus terdiri dari 3 sampai 120 karakter.';
        } elseif (BudgetCategory::nameExists($name, $exceptId)) {
            $errors[] = 'Nama kategori sudah dipakai kategori lain.';
        }

        if (mb_strlen($description) > 255) {
            $errors[] = 'Deskripsi kategori maksimal 255 karakter.';
        }

        $sortOrder = 0;
        if ($sort !== '') {
            if (preg_match('/^\d{1,6}$/', $sort) !== 1) {
                $errors[] = 'Urutan tampil harus angka antara 0 sampai 999999.';
            } else {
                $sortOrder = (int) $sort;
            }
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'category_code' => $code,
            'category_name' => $name,
            'description' => $description === '' ? null : $description,
            'sort_order' => $sortOrder,
            'is_active' => $active,
        ], []];
    }

    /** @return array<string, mixed> */
    private function findCategory(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $category = BudgetCategory::find((int) $id);
        if ($category === null) {
            throw new NotFoundHttpException();
        }

        return $category;
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
    }

    /**
     * @param array<string, string> $input
     */
    private function reject(string $path, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect($path);
    }
}
