<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\BudgetPlan;

/**
 * Rincian item RAB. Setiap perubahan mengunci baris RAB lalu menghitung ulang
 * totalnya di dalam transaksi database, sehingga total yang tersimpan selalu sama
 * dengan jumlah item meskipun dua pengguna menyimpan bersamaan.
 */
final class RabItemController extends Controller
{
    public function store(Request $request, string $planId): void
    {
        $actor = $this->actor();
        $plan = $this->findEditablePlan($planId);
        $input = $this->readForm($request);

        [$data, $errors] = $this->validate($input, (int) $plan['id'], 0);

        if ($errors !== []) {
            $this->reject('/rab/' . $plan['id'], $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['budget_plan_id'] = (int) $plan['id'];
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];

        $itemId = Database::transaction(function () use ($plan, $data, $actor): int {
            BudgetPlan::lock((int) $plan['id']);
            $id = BudgetItem::create($data);
            BudgetPlan::recalculateTotal((int) $plan['id']);

            return $id;
        });

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.item.create',
            'budgets',
            'Menambah item "' . $data['item_code'] . ' ' . $data['item_name'] . '" pada RAB '
            . $plan['rab_code'] . ' (' . rupiah(BudgetItem::computeTotal((string) $data['volume'], (string) $data['unit_price'])) . ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Item "' . $data['item_name'] . '" ditambahkan.');
        $this->redirect('/rab/' . $plan['id'] . '#item-' . $itemId);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $item = $this->findItem($id);
        $plan = $this->findEditablePlan((string) $item['budget_plan_id']);
        $input = $this->readForm($request);

        [$data, $errors] = $this->validate($input, (int) $plan['id'], (int) $item['id']);

        if ($errors !== []) {
            $this->reject('/rab/' . $plan['id'], $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];

        Database::transaction(function () use ($plan, $item, $data): void {
            BudgetPlan::lock((int) $plan['id']);
            BudgetItem::update((int) $item['id'], $data);
            BudgetPlan::recalculateTotal((int) $plan['id']);
        });

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.item.update',
            'budgets',
            'Memperbarui item "' . $data['item_code'] . '" pada RAB ' . $plan['rab_code'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Item "' . $data['item_name'] . '" diperbarui.');
        $this->redirect('/rab/' . $plan['id'] . '#item-' . $item['id']);
    }

    public function duplicate(Request $request, string $id): void
    {
        $actor = $this->actor();
        $item = $this->findItem($id);
        $plan = $this->findEditablePlan((string) $item['budget_plan_id']);

        $newId = Database::transaction(function () use ($plan, $actor, $item): ?int {
            BudgetPlan::lock((int) $plan['id']);
            $copy = BudgetItem::duplicate((int) $item['id'], (int) $actor['id']);
            BudgetPlan::recalculateTotal((int) $plan['id']);

            return $copy;
        });

        if ($newId === null) {
            $this->fail('Item yang akan disalin tidak ditemukan.', (string) $plan['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.item.duplicate',
            'budgets',
            'Menyalin item "' . $item['item_code'] . '" pada RAB ' . $plan['rab_code'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Item disalin dengan kode baru.');
        $this->redirect('/rab/' . $plan['id'] . '#item-' . $newId);
    }

    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $item = $this->findItem($id);
        $plan = $this->findEditablePlan((string) $item['budget_plan_id']);

        Database::transaction(function () use ($plan, $item): void {
            BudgetPlan::lock((int) $plan['id']);
            BudgetItem::delete((int) $item['id']);
            BudgetPlan::recalculateTotal((int) $plan['id']);
        });

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.item.delete',
            'budgets',
            'Menghapus item "' . $item['item_code'] . ' ' . $item['item_name'] . '" dari RAB '
            . $plan['rab_code'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Item "' . $item['item_name'] . '" dihapus dan total RAB dihitung ulang.');
        $this->redirect('/rab/' . $plan['id']);
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'category_id' => (string) $request->input('category_id', ''),
            'item_code' => (string) $request->input('item_code', ''),
            'item_name' => (string) $request->input('item_name', ''),
            'volume' => (string) $request->input('volume', ''),
            'unit' => (string) $request->input('unit', ''),
            'unit_price' => (string) $request->input('unit_price', ''),
            'notes' => (string) $request->input('notes', ''),
            'sort_order' => (string) $request->input('sort_order', ''),
        ];
    }

    /**
     * Volume dan harga satuan diterima apa adanya dari formulir, total tidak pernah
     * dibaca dari permintaan. Nilai uang dinormalkan dulu agar tulisan Indonesia
     * seperti "1.500.000,50" terbaca sama dengan "1500000.50".
     *
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, int $planId, int $exceptId): array
    {
        $errors = [];

        $categoryId = trim($input['category_id']);
        $code = mb_strtoupper(preg_replace('/\s+/', '', trim($input['item_code'])) ?? '', 'UTF-8');
        $name = trim($input['item_name']);
        $volume = volume_normalize(trim($input['volume']));
        $unit = trim($input['unit']);
        $price = amount_normalize(trim($input['unit_price']));
        $notes = trim($input['notes']);
        $sort = trim($input['sort_order']);

        $category = null;
        if ($categoryId === '' || !ctype_digit($categoryId) || (int) $categoryId <= 0) {
            $errors[] = 'Kategori pekerjaan wajib dipilih.';
        } else {
            $category = BudgetCategory::find((int) $categoryId);
            if ($category === null) {
                $errors[] = 'Kategori pekerjaan tidak ditemukan.';
            } elseif ((int) $category['is_active'] !== 1) {
                $errors[] = 'Kategori "' . $category['category_name'] . '" sedang tidak aktif. Aktifkan dulu atau pilih kategori lain.';
            }
        }

        if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{1,29}$/', $code)) {
            $errors[] = 'Kode item harus 2-30 karakter, diawali huruf atau angka, tanpa spasi.';
        } elseif ($code !== '' && BudgetItem::codeExists($planId, $code, $exceptId === 0 ? null : $exceptId)) {
            $errors[] = 'Kode item sudah dipakai pada RAB ini.';
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 180) {
            $errors[] = 'Uraian pekerjaan harus terdiri dari 3 sampai 180 karakter.';
        }

        if ($volume === null) {
            $errors[] = 'Volume harus angka, maksimal sembilan digit dengan tiga angka desimal.';
        } elseif ((float) $volume <= 0) {
            $errors[] = 'Volume harus lebih dari nol.';
        }

        if ($unit === '' || mb_strlen($unit) > 30) {
            $errors[] = 'Satuan wajib diisi dan maksimal 30 karakter, contoh m2, m3, unit, lot.';
        }

        if ($price === null) {
            $errors[] = 'Harga satuan harus angka, contoh 150000 atau 150.000,50.';
        } elseif ((float) $price < 0) {
            $errors[] = 'Harga satuan tidak boleh negatif.';
        } elseif ((float) $price > 99999999999999.0) {
            $errors[] = 'Harga satuan terlalu besar untuk disimpan.';
        }

        if (mb_strlen($notes) > 255) {
            $errors[] = 'Keterangan item maksimal 255 karakter.';
        }

        $sortOrder = 0;
        if ($sort !== '') {
            if (!ctype_digit($sort)) {
                $errors[] = 'Urutan tampil harus angka bulat.';
            } else {
                $sortOrder = (int) $sort;
            }
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        /** @var array<string, mixed> $category */
        $finalCode = $code !== ''
            ? $code
            : BudgetItem::nextItemCode($planId, (string) $category['category_code']);

        return [[
            'category_id' => (int) $categoryId,
            'item_code' => $finalCode,
            'item_name' => $name,
            'volume' => (string) $volume,
            'unit' => $unit,
            'unit_price' => (string) $price,
            'notes' => $notes === '' ? null : $notes,
            'sort_order' => $sortOrder,
        ], []];
    }

    /** @return array<string, mixed> */
    private function findItem(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $item = BudgetItem::findWithCategory((int) $id);
        if ($item === null) {
            throw new NotFoundHttpException();
        }

        return $item;
    }

    /**
     * Item hanya dapat diubah selama RAB-nya berstatus draft atau ditolak.
     *
     * @return array<string, mixed>
     */
    private function findEditablePlan(string $planId): array
    {
        if (!ctype_digit($planId)) {
            throw new NotFoundHttpException();
        }

        $plan = BudgetPlan::findWithNames((int) $planId);
        if ($plan === null) {
            throw new NotFoundHttpException();
        }

        if (!in_array((string) $plan['status'], BudgetPlan::EDITABLE_STATUSES, true)) {
            $this->fail(
                'RAB ' . $plan['rab_code'] . ' berstatus ' . budget_status_label((string) $plan['status'])
                . ' sehingga itemnya terkunci. Buat revisi untuk mengubah rincian.',
                (string) $plan['id']
            );
        }

        if ((int) $plan['is_project_archived'] === 1) {
            $this->fail('Proyek sudah diarsipkan, item RAB tidak dapat diubah.', (string) $plan['id']);
        }

        return $plan;
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

    private function fail(string $message, string $planId): never
    {
        Session::flash('danger', $message);
        $this->redirect('/rab/' . $planId);
    }
}
