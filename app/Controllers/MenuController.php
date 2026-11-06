<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Env;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\FileUpload;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\FoodIngredient;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuRevision;
use App\Models\MenuRule;
use App\Models\Notification;

/**
 * Perencanaan menu harian MBG beserta alur persetujuannya.
 *
 * Komposisi bahan dihitung ulang di sisi server setiap kali menu disimpan, jadi nilai
 * gizi dan biaya yang tampil bukan hasil perhitungan browser. Menu yang sudah disetujui
 * tidak dapat diedit: perubahannya harus lewat usulan revisi yang membuat menu turunan
 * dan menunggu persetujuan ulang. Aturan gizi diperiksa sebelum menu boleh diajukan,
 * dan temuan aturan ditampilkan apa adanya agar alasannya transparan.
 */
final class MenuController extends Controller
{
    private const PER_PAGE = 10;

    /** @var array<string, string> ekstensi gambar yang boleh jadi foto menu */
    private const PHOTO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $result = Menu::paginate($filters, max(1, $request->integer('page', 1)), self::PER_PAGE);

        $this->view('menu/index', [
            'title' => 'Menu Harian',
            'pageTitle' => 'Menu Harian MBG',
            'filters' => $filters,
            'result' => $result,
            'menus' => $result['rows'],
            'categories' => MenuCategory::activeList(),
            'statuses' => Menu::STATUSES,
            'counts' => $this->counts(),
            'basePath' => '/menu',
            'canCreate' => can('menus.create') || can('menus.manage'),
            'canApprove' => can('menus.approve'),
        ]);
    }

    public function create(Request $request): void
    {
        $old = $this->pullOld();
        $items = $this->oldItems($old);

        $this->view('menu/form', [
            'title' => 'Susun Menu',
            'pageTitle' => 'Susun Menu Baru',
            'mode' => 'create',
            'form' => $this->fillForm($this->emptyForm(), $old),
            'items' => $items,
            'menu' => null,
            'categories' => MenuCategory::activeList(),
            'ingredients' => FoodIngredient::optionList(),
            'preview' => $this->preview($items),
            'suggestions' => [],
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        $items = $this->readItems($request);
        [$data, $errors] = $this->validate($input, $items, null);

        $upload = null;
        if ($errors === []) {
            $upload = $this->storePhoto($request, $errors);
        }

        if ($errors !== []) {
            $this->reject('/menu/tambah', $errors[0], $input, $items);
        }

        /** @var array<string, mixed> $data */
        $menuId = Database::transaction(function () use ($data, $items, $actor, $upload): int {
            $id = Menu::create($data, $items, (int) $actor['id']);

            if ($upload !== null) {
                $this->savePhoto($upload, $id, (int) $actor['id']);
            }

            return $id;
        });

        ActivityLog::record(
            (int) $actor['id'],
            'menus.create',
            'menus',
            'Menyusun menu draf "' . $data['menu_name'] . '" dengan ' . count($items) . ' bahan.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Menu draf tersimpan. Ajukan ke ahli gizi setelah komponennya lengkap.');
        $this->redirect('/menu/' . $menuId);
    }

    public function show(Request $request, string $id): void
    {
        $menu = $this->findMenu($id);
        $menuId = (int) $menu['id'];
        $computation = $menu['computation'];

        $this->view('menu/show', [
            'title' => (string) $menu['menu_name'],
            'pageTitle' => 'Detail Menu',
            'menu' => $menu,
            'computation' => $computation,
            'lines' => $menu['lines'],
            'findings' => MenuRule::assess(MenuRule::active(MenuRule::SCOPE_MENU), $computation),
            'approvals' => Menu::approvals($menuId),
            'lineage' => Menu::lineage($menuId),
            'revisions' => MenuRevision::forMenu($menuId),
            'photoUrl' => $this->photoUrl($menu),
            'canEdit' => $this->canEdit($menu),
            'canApprove' => can('menus.approve'),
            'canRevise' => can('menus.revision') || can('menus.manage'),
            'maxUploadMb' => (int) App::config('app.uploads.max_mb', 10),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $menu = $this->findMenu($id);

        if (!$this->canEdit($menu)) {
            $this->fail(
                (int) $menu['id'],
                'Menu berstatus ' . menu_status_label($menu['menu_status'])
                . ' tidak dapat diubah langsung. Ajukan revisi supaya perubahannya tercatat.'
            );
        }

        $old = $this->pullOld();
        $items = $old === [] ? $this->itemsFromLines($menu['lines']) : $this->oldItems($old);

        $form = $this->fillForm([
            'menu_code' => (string) $menu['menu_code'],
            'menu_name' => (string) $menu['menu_name'],
            'category_id' => (string) $menu['category_id'],
            'description' => (string) ($menu['description'] ?? ''),
            'allergen_info' => (string) ($menu['allergen_info'] ?? ''),
            'nutrition_notes' => (string) ($menu['nutrition_notes'] ?? ''),
        ], $old);

        $this->view('menu/form', [
            'title' => 'Ubah Menu',
            'pageTitle' => 'Ubah Menu Draf',
            'mode' => 'update',
            'form' => $form,
            'items' => $items,
            'menu' => $menu,
            'categories' => MenuCategory::activeList(),
            'ingredients' => FoodIngredient::optionList(),
            'preview' => $this->preview($items),
            'photoUrl' => $this->photoUrl($menu),
            'suggestions' => [],
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $menu = $this->findMenu($id);

        if (!$this->canEdit($menu)) {
            $this->fail(
                (int) $menu['id'],
                'Menu berstatus ' . menu_status_label($menu['menu_status'])
                . ' tidak dapat diubah langsung. Ajukan revisi supaya perubahannya tercatat.'
            );
        }

        $input = $this->readForm($request);
        $items = $this->readItems($request);
        [$data, $errors] = $this->validate($input, $items, (int) $menu['id']);

        $upload = null;
        if ($errors === []) {
            $upload = $this->storePhoto($request, $errors);
        }

        if ($errors !== []) {
            $this->reject('/menu/' . (int) $menu['id'] . '/ubah', $errors[0], $input, $items);
        }

        /** @var array<string, mixed> $data */
        Database::transaction(function () use ($menu, $data, $items, $actor, $upload): void {
            Menu::update((int) $menu['id'], $data, $items, (int) $actor['id']);

            if ($upload !== null) {
                $this->savePhoto($upload, (int) $menu['id'], (int) $actor['id']);
            }
        });

        ActivityLog::record(
            (int) $actor['id'],
            'menus.update',
            'menus',
            'Mengubah menu draf "' . $data['menu_name'] . '" menjadi ' . count($items) . ' bahan.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Menu diperbarui dan nilai gizinya dihitung ulang di server.');
        $this->redirect('/menu/' . (int) $menu['id']);
    }

    /**
     * Ajukan menu ke pemegang hak menus.approve. Aturan berperingkat "harus" yang gagal
     * menahan pengajuan, sedangkan peringatan hanya dilaporkan supaya perencana dapat
     * memutuskan sendiri apakah komposisinya perlu diperbaiki.
     */
    public function submit(Request $request, string $id): void
    {
        $actor = $this->actor();
        $menu = $this->findMenu($id);
        $menuId = (int) $menu['id'];

        if (!$this->canEdit($menu)) {
            $this->fail($menuId, 'Hanya menu berstatus draf atau ditolak yang dapat diajukan.');
        }

        $computation = Menu::compute($menuId);
        if ($computation['lines'] === []) {
            $this->fail($menuId, 'Menu belum punya bahan. Isi komponennya lebih dulu sebelum diajukan.');
        }

        $findings = MenuRule::assess(MenuRule::active(MenuRule::SCOPE_MENU), $computation);
        $blocking = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === 'harus' && $f['passed'] === false
        ));

        if ($blocking !== []) {
            $this->fail(
                $menuId,
                'Pengajuan ditahan oleh aturan wajib: ' . (string) $blocking[0]['message']
            );
        }

        $changed = Menu::submit($menuId, (int) $actor['id']);
        if ($changed === 0) {
            $this->fail($menuId, 'Status menu berubah sejak halaman ini dibuka, muat ulang lalu coba lagi.');
        }

        $fresh = Menu::findWithNames($menuId);
        if ($fresh !== null) {
            Menu::log($menuId, 'submit', (string) $menu['menu_status'], Menu::STATUS_PENDING, null, (int) $actor['id']);
            Notification::menuSubmitted($fresh, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menus.submit',
            'menus',
            'Mengajukan menu "' . $menu['menu_code'] . ' ' . $menu['menu_name'] . '" untuk diperiksa ahli gizi.',
            $request->ip(),
            $request->userAgent()
        );

        $warnings = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['passed'] === false
        ));

        Session::flash('success', 'Menu diajukan untuk diperiksa.'
            . ($warnings !== [] ? ' Catatan: ' . count($warnings) . ' aturan memberi peringatan.' : ''));
        $this->redirect('/menu/' . $menuId);
    }

    /**
     * Putuskan menu yang menunggu pemeriksaan. Hanya pemegang menus.approve yang boleh,
     * dan pemutus tidak boleh orang yang sama dengan pengaju agar pemeriksaan tetap terpisah.
     */
    public function decide(Request $request, string $id): void
    {
        $actor = $this->actor();
        $menu = $this->findMenu($id);
        $menuId = (int) $menu['id'];

        if ((string) $menu['menu_status'] !== Menu::STATUS_PENDING) {
            $this->fail($menuId, 'Hanya menu berstatus menunggu persetujuan yang dapat diputus.');
        }

        if (!can('menus.approve')) {
            $this->fail($menuId, 'Keputusan menu memerlukan hak menus.approve yang diperiksa di server.');
        }

        if ((int) ($menu['submitted_by'] ?? 0) === (int) $actor['id']) {
            $this->fail($menuId, 'Menu yang Anda ajukan sendiri tidak dapat Anda setujui. Minta pemegang hak lain.');
        }

        $to = $request->string('keputusan');
        if (!in_array($to, [Menu::STATUS_APPROVED, Menu::STATUS_REJECTED], true)) {
            $this->fail($menuId, 'Pilih keputusan: setujui atau tolak.');
        }

        $note = preg_replace('/\s+/', ' ', trim($request->string('catatan'))) ?? '';
        if (mb_strlen($note) > 500) {
            $this->fail($menuId, 'Catatan keputusan maksimal 500 karakter.');
        }
        if ($to === Menu::STATUS_REJECTED && mb_strlen($note) < 5) {
            $this->fail($menuId, 'Penolakan wajib diberi alasan agar revisinya jelas arahnya.');
        }

        $computation = Menu::compute($menuId);
        $findings = MenuRule::assess(MenuRule::active(MenuRule::SCOPE_MENU), $computation);
        $blocking = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === 'harus' && $f['passed'] === false
        ));

        if ($to === Menu::STATUS_APPROVED && $blocking !== []) {
            $this->fail($menuId, 'Menu tidak dapat disetujui selama aturan wajib belum lolos: '
                . (string) $blocking[0]['message']);
        }

        $changed = Database::transaction(function () use ($menuId, $to, $note, $actor): int {
            Menu::lock($menuId);
            $count = Menu::decide($menuId, $to, $note, (int) $actor['id']);

            if ($count > 0) {
                Menu::log(
                    $menuId,
                    $to === Menu::STATUS_APPROVED ? 'approve' : 'reject',
                    Menu::STATUS_PENDING,
                    $to,
                    $note,
                    (int) $actor['id']
                );
                // Usulan revisi yang menggantung pada menu ini ikut tertutup.
                MenuRevision::decideForMenu(
                    $menuId,
                    $to === Menu::STATUS_APPROVED ? MenuRevision::STATUS_APPROVED : MenuRevision::STATUS_REJECTED,
                    $note === '' ? null : $note,
                    (int) $actor['id']
                );
            }

            return $count;
        });

        if ($changed === 0) {
            $this->fail($menuId, 'Status menu berubah sejak halaman ini dibuka, muat ulang lalu coba lagi.');
        }

        $fresh = Menu::findWithNames($menuId);
        if ($fresh !== null) {
            Notification::menuDecided($fresh, $to, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menus.decide',
            'menus',
            ($to === Menu::STATUS_APPROVED ? 'Menyetujui' : 'Menolak') . ' menu "'
            . $menu['menu_code'] . ' ' . $menu['menu_name'] . '".'
            . ($note !== '' ? ' Catatan: ' . $note : ''),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $to === Menu::STATUS_APPROVED
            ? 'Menu disetujui dan siap dijadwalkan.'
            : 'Menu ditolak. Perencana menerima notifikasi beserta alasannya.');
        $this->redirect('/menu/' . $menuId);
    }

    /**
     * Usulkan perubahan atas menu yang sudah disetujui atau terjadwal. Menu asal tidak
     * diubah; usulan membuat menu turunan berversi dan tercatat menunggu persetujuan ulang.
     */
    public function revise(Request $request, string $id): void
    {
        $actor = $this->actor();
        $menu = $this->findMenu($id);
        $menuId = (int) $menu['id'];

        if (!in_array((string) $menu['menu_status'], [Menu::STATUS_APPROVED, Menu::STATUS_SCHEDULED], true)) {
            $this->fail($menuId, 'Revisi hanya diajukan untuk menu yang sudah disetujui atau terjadwal.');
        }

        $input = $this->readForm($request);
        // Kode turunan dibuat oleh MenuRevision, bukan dikirim formulir.
        $input['menu_code'] = '';
        $items = $this->readItems($request);
        [$data, $errors] = $this->validate($input, $items, null);

        $reason = preg_replace('/\s+/', ' ', trim($request->string('reason'))) ?? '';
        if (mb_strlen($reason) < 5) {
            $errors[] = 'Alasan revisi wajib diisi minimal 5 karakter agar riwayatnya jelas.';
        } elseif (mb_strlen($reason) > 500) {
            $errors[] = 'Alasan revisi maksimal 500 karakter.';
        }

        if ($errors !== []) {
            $this->reject('/menu/' . $menuId . '/revisi', $errors[0], $input, $items);
        }

        /** @var array<string, mixed> $data */
        [$newMenuId, $revisionId, $code] = MenuRevision::create($menu, $data, $items, $reason, (int) $actor['id']);

        $revision = MenuRevision::findWithMenu($revisionId);
        if ($revision !== null) {
            Notification::menuRevisionSubmitted($revision, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menus.revision',
            'menus',
            'Mengusulkan revisi "' . $code . '" atas menu ' . $menu['menu_code'] . '. Alasan: ' . $reason,
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Usulan revisi "' . $code . '" tercatat. Menu asal '
            . $menu['menu_code'] . ' belum berubah sampai usulannya disetujui.');
        $this->redirect('/menu/' . $newMenuId);
    }

    public function reviseForm(Request $request, string $id): void
    {
        $menu = $this->findMenu($id);

        if (!in_array((string) $menu['menu_status'], [Menu::STATUS_APPROVED, Menu::STATUS_SCHEDULED], true)) {
            $this->fail((int) $menu['id'], 'Revisi hanya diajukan untuk menu yang sudah disetujui atau terjadwal.');
        }

        $old = $this->pullOld();
        $items = $old === [] ? $this->itemsFromLines($menu['lines']) : $this->oldItems($old);

        $form = $this->fillForm([
            'menu_code' => (string) $menu['menu_code'],
            'menu_name' => (string) $menu['menu_name'],
            'category_id' => (string) $menu['category_id'],
            'description' => (string) ($menu['description'] ?? ''),
            'allergen_info' => (string) ($menu['allergen_info'] ?? ''),
            'nutrition_notes' => (string) ($menu['nutrition_notes'] ?? ''),
        ], $old);

        // Kode usulan dibuat sendiri oleh jalur revisi (turunan dari kode menu asal),
        // jadi formulir tidak mengirim kode agar tidak bertabrakan dengan menu asal.
        $form['menu_code'] = '';

        $this->view('menu/form', [
            'title' => 'Usulkan Revisi',
            'pageTitle' => 'Usulkan Revisi Menu',
            'mode' => 'revise',
            'form' => $form,
            'items' => $items,
            'menu' => $menu,
            'categories' => MenuCategory::activeList(),
            'ingredients' => FoodIngredient::optionList(),
            'preview' => $this->preview($items),
            'reason' => (string) ($old['reason'] ?? ''),
            'photoUrl' => $this->photoUrl($menu),
            'suggestions' => [],
        ]);
    }

    /** Batalkan pengajuan kembali ke draf selama belum diputus. */
    public function withdraw(Request $request, string $id): void
    {
        $actor = $this->actor();
        $menu = $this->findMenu($id);
        $menuId = (int) $menu['id'];

        if ((string) $menu['menu_status'] !== Menu::STATUS_PENDING) {
            $this->fail($menuId, 'Hanya pengajuan yang masih menunggu persetujuan yang dapat ditarik.');
        }

        $changed = Menu::changeStatus($menuId, Menu::STATUS_PENDING, Menu::STATUS_DRAFT, (int) $actor['id']);
        if ($changed === 0) {
            $this->fail($menuId, 'Status menu sudah berubah, muat ulang halaman ini.');
        }

        Menu::log($menuId, 'cancel', Menu::STATUS_PENDING, Menu::STATUS_DRAFT, 'Pengajuan ditarik perencana.', (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'menus.withdraw',
            'menus',
            'Menarik kembali pengajuan menu "' . $menu['menu_code'] . '" ke status draf.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Pengajuan ditarik. Menu kembali berstatus draf.');
        $this->redirect('/menu/' . $menuId);
    }

    public function removePhoto(Request $request, string $id): void
    {
        $actor = $this->actor();
        $menu = $this->findMenu($id);
        $stored = $menu['photo_path'] === null ? '' : (string) $menu['photo_path'];

        if ($stored === '') {
            $this->fail((int) $menu['id'], 'Menu ini belum punya foto.');
        }

        Menu::setPhoto((int) $menu['id'], null, (int) $actor['id']);
        FileUpload::remove($stored, FileUpload::MENU_SUBDIR);

        ActivityLog::record(
            (int) $actor['id'],
            'menus.photo.delete',
            'menus',
            'Menghapus foto menu "' . $menu['menu_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Foto menu dihapus.');
        $this->redirect('/menu/' . (int) $menu['id']);
    }

    /** Foto dikirim dari folder penyimpanan di luar public, setelah hak baca diperiksa rute. */
    public function photo(Request $request, string $id): void
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $menu = Menu::findWithNames((int) $id);
        $stored = $menu === null ? '' : (string) ($menu['photo_path'] ?? '');
        $extension = mb_strtolower((string) pathinfo($stored, PATHINFO_EXTENSION));

        if ($stored === '' || !isset(self::PHOTO_MIME[$extension])) {
            throw new NotFoundHttpException();
        }

        FileUpload::respond(
            $stored,
            self::PHOTO_MIME[$extension],
            (string) $menu['menu_name'],
            true,
            FileUpload::MENU_SUBDIR
        );
    }

    /** Menu hanya boleh dihapus selama belum dijadwalkan dan belum punya turunan. */
    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $menu = $this->findMenu($id);
        $menuId = (int) $menu['id'];

        if (in_array((string) $menu['menu_status'], [Menu::STATUS_APPROVED, Menu::STATUS_SCHEDULED, Menu::STATUS_DONE], true)) {
            $this->fail($menuId, 'Menu yang sudah disetujui atau berjalan tidak dihapus, batalkan atau arsipkan lewat jadwal.');
        }

        $usage = Menu::usageCount($menuId);
        if ($usage > 0) {
            $this->fail($menuId, 'Menu masih dipakai pada ' . $usage . ' baris jadwal atau turunan revisi.');
        }

        $stored = $menu['photo_path'] === null ? '' : (string) $menu['photo_path'];
        Menu::delete($menuId);
        if ($stored !== '') {
            FileUpload::remove($stored, FileUpload::MENU_SUBDIR);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menus.delete',
            'menus',
            'Menghapus menu draf "' . $menu['menu_code'] . ' ' . $menu['menu_name'] . '" yang belum dijadwalkan.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Menu "' . $menu['menu_name'] . '" dihapus.');
        $this->redirect('/menu');
    }

    /** Daftar usulan revisi yang menunggu keputusan. */
    public function revisions(Request $request): void
    {
        $this->view('menu/revisi', [
            'title' => 'Usulan Revisi Menu',
            'pageTitle' => 'Usulan Revisi Menu',
            'revisions' => MenuRevision::pending(50),
            'countPending' => MenuRevision::countPending(),
            'canApprove' => can('menus.approve'),
        ]);
    }

    /** Halaman rekomendasi berbasis aturan; AI opsional dan tidak pernah menjadi keputusan akhir. */
    public function suggest(Request $request): void
    {
        $dates = $this->dates($request);
        $candidates = Menu::schedulableList();
        $rules = MenuRule::active(MenuRule::SCOPE_MENU);

        $this->view('menu/rekomendasi', [
            'title' => 'Rekomendasi Menu',
            'pageTitle' => 'Rekomendasi Menu Berbasis Aturan',
            'dates' => $dates,
            'picks' => MenuRule::recommend($candidates, $rules, $dates),
            'rules' => $rules,
            'ceiling' => MenuRule::costCeiling(),
            'candidates' => count($candidates),
            // Kunci API hanya dibaca dari environment; tanpa kunci, rekomendasi tetap rule-based.
            'aiReady' => trim((string) Env::get('MBG_AI_API_KEY', '')) !== '',
        ]);
    }

    /** @return array<int, string> Rentang tanggal usulan, maksimal 7 hari. */
    private function dates(Request $request): array
    {
        $start = trim($request->string('dari'));
        if (!date_is_valid($start)) {
            $start = date('Y-m-d', (int) strtotime('next monday'));
        }

        $days = max(1, min(7, $request->integer('hari', 5)));

        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = date('Y-m-d', (int) strtotime($start . ' +' . $i . ' day'));
        }

        return $dates;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (Menu::STATUSES as $status) {
            $counts[$status] = Menu::countByStatus($status);
        }

        $counts['revision'] = MenuRevision::countPending();

        return $counts;
    }

    /** Pratinjau perhitungan server atas komposisi yang sedang diketik. */
    private function preview(array $items): array
    {
        if ($items === []) {
            return [];
        }

        [$clean, $errors] = $this->validateItems($items);
        if ($errors !== []) {
            return [];
        }

        return Menu::computeItems($clean);
    }

    /** @return array<string, string> */
    private function emptyForm(): array
    {
        return [
            'menu_code' => '',
            'menu_name' => '',
            'category_id' => '',
            'description' => '',
            'allergen_info' => '',
            'nutrition_notes' => '',
        ];
    }

    /**
     * @param array<string, string> $form
     * @param array<string, mixed> $old
     * @return array<string, string>
     */
    private function fillForm(array $form, array $old): array
    {
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form) && !is_array($value)) {
                $form[$key] = (string) $value;
            }
        }

        if ($form['menu_code'] === '') {
            $form['menu_code'] = Menu::nextCode();
        }

        return $form;
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
     * Bahan dibaca dari array formulir. Baris kosong diabaikan, baris yang punya jumlah
     * ikut diperiksa supaya tidak ada bahan ganda pada satu menu.
     *
     * @return array<int, array{ingredient_id: string, quantity: string, unit: string, notes: string}>
     */
    private function readItems(Request $request): array
    {
        $ids = (array) $request->input('ingredient_id', []);
        $quantities = (array) $request->input('quantity', []);
        $units = (array) $request->input('quantity_unit', []);
        $notes = (array) $request->input('line_notes', []);

        $items = [];
        foreach ($ids as $index => $id) {
            $quantity = (string) ($quantities[$index] ?? '');
            if ((string) $id === '' || trim($quantity) === '') {
                continue;
            }

            $items[] = [
                'ingredient_id' => (string) $id,
                'quantity' => trim($quantity),
                'unit' => mb_strtolower(trim((string) ($units[$index] ?? ''))),
                'notes' => trim((string) ($notes[$index] ?? '')),
            ];
        }

        return $items;
    }

    /** @param array<int, array<string, mixed>> $lines @return array<int, array<string, string>> */
    private function itemsFromLines(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'ingredient_id' => (string) $line['ingredient_id'],
                'quantity' => rtrim(rtrim(number_format((float) $line['quantity_per_serving'], 4, '.', ''), '0'), '.'),
                'unit' => (string) $line['quantity_unit'],
                'notes' => (string) ($line['notes'] ?? ''),
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $old @return array<int, array<string, string>> */
    private function oldItems(array $old): array
    {
        $items = $old['items'] ?? null;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * @param array<string, string> $input
     * @param array<int, array<string, string>> $items
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, array $items, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', '', $input['menu_code']) ?? '');
        $name = preg_replace('/\s+/', ' ', trim($input['menu_name'])) ?? '';
        $categoryId = (int) $input['category_id'];

        if ($code !== '') {
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,29}$/', $code)) {
                $errors[] = 'Kode menu harus 3-30 karakter tanpa spasi, diawali huruf atau angka.';
            } elseif (Menu::codeExists($code, $exceptId)) {
                $errors[] = 'Kode menu "' . $code . '" sudah dipakai.';
            }
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors[] = 'Nama menu harus terdiri dari 3 sampai 150 karakter.';
        }

        $category = $categoryId > 0 ? MenuCategory::find($categoryId) : null;
        if ($category === null) {
            $errors[] = 'Kategori menu wajib dipilih.';
        }

        if (mb_strlen($input['description']) > 500) {
            $errors[] = 'Deskripsi maksimal 500 karakter.';
        }
        if (mb_strlen($input['allergen_info']) > 500) {
            $errors[] = 'Informasi alergen maksimal 500 karakter dan wajib diisi bila menu mengandung alergen.';
        }
        if (mb_strlen($input['nutrition_notes']) > 1000) {
            $errors[] = 'Catatan gizi maksimal 1000 karakter.';
        }

        [$clean, $itemErrors] = $this->validateItems($items);
        foreach ($itemErrors as $error) {
            $errors[] = $error;
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'menu_code' => $code,
            'menu_name' => $name,
            'category_id' => $categoryId,
            'description' => $input['description'] === '' ? null : $input['description'],
            'allergen_info' => $input['allergen_info'] === '' ? null : $input['allergen_info'],
            'nutrition_notes' => $input['nutrition_notes'] === '' ? null : $input['nutrition_notes'],
        ], []];
    }

    /**
     * @param array<int, array<string, string>> $items
     * @return array{0: array<int, array{ingredient_id: int, quantity: float, unit: string, notes: string|null}>, 1: array<int, string>}
     */
    private function validateItems(array $items): array
    {
        $errors = [];
        $clean = [];
        $seen = [];

        if ($items === []) {
            return [[], ['Minimal satu bahan diperlukan agar gizinya dapat dihitung.']];
        }

        foreach ($items as $index => $item) {
            $position = $index + 1;
            $id = (int) ($item['ingredient_id'] ?? 0);
            $quantity = str_replace(',', '.', trim((string) ($item['quantity'] ?? '')));
            $unit = mb_strtolower(trim((string) ($item['unit'] ?? '')));

            if ($id <= 0 || FoodIngredient::find($id) === null) {
                $errors[] = 'Baris ' . $position . ': bahan tidak ditemukan di katalog.';
                continue;
            }

            if (isset($seen[$id])) {
                $errors[] = 'Baris ' . $position . ': bahan yang sama ditulis dua kali, jumlah bahan ini menjadi '
                    . rtrim(rtrim(number_format((float) $seen[$id] + (float) $quantity, 4, ',', ''), '0'), '.')
                    . ' bila digabung. Satukan dalam satu baris.';
                continue;
            }

            if (preg_match('/^\d{1,7}(\.\d{1,4})?$/', $quantity) !== 1 || (float) $quantity <= 0) {
                $errors[] = 'Baris ' . $position . ': jumlah per porsi harus angka positif, misalnya 40 atau 0.5.';
                continue;
            }

            if ($unit === '') {
                $errors[] = 'Baris ' . $position . ': satuan bahan wajib dipilih agar setara dengan satuan dasar.';
                continue;
            }

            $seen[$id] = (float) $quantity;
            $clean[] = [
                'ingredient_id' => $id,
                'quantity' => (float) $quantity,
                'unit' => $unit,
                'notes' => trim((string) ($item['notes'] ?? '')) === ''
                    ? null
                    : mb_substr(trim((string) $item['notes']), 0, 255),
            ];
        }

        return $errors === [] ? [$clean, []] : [[], $errors];
    }

    /** @param array<int, string> $errors */
    private function storePhoto(Request $request, array &$errors): ?array
    {
        $file = $request->file('photo');
        if ($file === null) {
            return null;
        }

        $extension = mb_strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::PHOTO_MIME[$extension])) {
            $errors[] = 'Foto menu harus berupa gambar JPG, PNG, atau WEBP.';

            return null;
        }

        $upload = FileUpload::store($file, FileUpload::MENU_SUBDIR);
        if (!$upload['ok']) {
            $errors[] = 'Foto menu: ' . (string) $upload['error'];

            return null;
        }

        return $upload;
    }

    /** @param array{stored: string, original: string} $upload */
    private function savePhoto(array $upload, int $menuId, int $actorId): void
    {
        Menu::setPhoto($menuId, (string) $upload['stored'], $actorId);
    }

    /** @param array<string, mixed> $menu */
    private function photoUrl(array $menu): ?string
    {
        $stored = $menu['photo_path'] ?? null;
        if ($stored === null || $stored === '') {
            return null;
        }

        $path = FileUpload::path((string) $stored, FileUpload::MENU_SUBDIR);

        return $path === null ? null : url('/menu/' . (int) $menu['id'] . '/foto');
    }

    /** @param array<string, mixed> $menu */
    private function canEdit(array $menu): bool
    {
        if (!in_array((string) $menu['menu_status'], Menu::EDITABLE_STATUSES, true)) {
            return false;
        }

        return can('menus.update') || can('menus.manage');
    }

    /** @return array{search: string, category: string, status: string, nutrition: string, sort: string, dir: string} */
    private function filters(Request $request): array
    {
        $sort = $request->string('sort');
        $status = $request->string('status');
        $nutrition = $request->string('gizi');

        return [
            'search' => mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60),
            'category' => (string) max(0, $request->integer('kategori')),
            'status' => in_array($status, Menu::STATUSES, true) ? $status : '',
            'nutrition' => in_array($nutrition, ['lengkap', 'sebagian', 'belum_tersedia'], true) ? $nutrition : '',
            'sort' => isset(Menu::SORTABLE[$sort]) ? $sort : 'updated',
            'dir' => strtolower($request->string('dir')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @return array<string, mixed> */
    private function findMenu(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $menu = Menu::findComputed((int) $id);
        if ($menu === null) {
            throw new NotFoundHttpException();
        }

        return $menu;
    }

    private function fail(int $id, string $message): never
    {
        Session::flash('danger', $message);
        $this->redirect('/menu/' . $id);
    }

    /**
     * @param array<string, string> $input
     * @param array<int, array<string, string>> $items
     */
    private function reject(string $path, string $message, array $input, array $items): never
    {
        Session::flash('danger', $message);
        Session::putOld($input + ['items' => $items]);
        $this->redirect($path);
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
    }
}
