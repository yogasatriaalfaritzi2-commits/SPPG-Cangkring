<?php
$navbarUser = auth_user();
if ($navbarUser === null) {
    return;
}

$searchItems = [
    // Menu Utama & Dashboard
    ['title' => 'Dashboard Utama', 'category' => 'Menu Utama', 'url' => url('/dashboard'), 'icon' => 'bi-grid-1x2', 'perm' => 'dashboard.view'],
    ['title' => 'Dashboard Operasional MBG', 'category' => 'Operasional MBG', 'url' => url('/mbg'), 'icon' => 'bi-activity', 'perm' => 'schools.view'],
    ['title' => 'Sekolah Penerima Manfaat', 'category' => 'Operasional MBG', 'url' => url('/sekolah'), 'icon' => 'bi-mortarboard', 'perm' => 'schools.view'],
    ['title' => 'Tambah Sekolah Baru', 'category' => 'Operasional MBG', 'url' => url('/sekolah/tambah'), 'icon' => 'bi-plus-circle', 'perm' => 'schools.create'],
    ['title' => 'Data Penerima Manfaat MBG', 'category' => 'Operasional MBG', 'url' => url('/penerima-manfaat'), 'icon' => 'bi-people', 'perm' => 'beneficiaries.view'],
    ['title' => 'Katalog Menu MBG', 'category' => 'Operasional MBG', 'url' => url('/menu'), 'icon' => 'bi-card-checklist', 'perm' => 'menus.view'],
    ['title' => 'Susun Menu MBG Baru', 'category' => 'Operasional MBG', 'url' => url('/menu/tambah'), 'icon' => 'bi-plus-circle', 'perm' => 'menus.create'],
    ['title' => 'Rekomendasi Menu MBG', 'category' => 'Operasional MBG', 'url' => url('/menu/rekomendasi'), 'icon' => 'bi-stars', 'perm' => 'menus.view'],
    ['title' => 'Jadwal Menu Harian', 'category' => 'Operasional MBG', 'url' => url('/jadwal-menu'), 'icon' => 'bi-calendar-check', 'perm' => 'menu_schedules.view'],
    ['title' => 'Katalog Bahan Pangan', 'category' => 'Bahan & Gizi', 'url' => url('/bahan'), 'icon' => 'bi-basket', 'perm' => 'nutrition.view'],
    ['title' => 'Rujukan & Target Gizi MBG', 'category' => 'Bahan & Gizi', 'url' => url('/gizi'), 'icon' => 'bi-heart-pulse', 'perm' => 'nutrition.view'],
    ['title' => 'Aturan Menu MBG', 'category' => 'Bahan & Gizi', 'url' => url('/aturan-menu'), 'icon' => 'bi-sliders', 'perm' => 'nutrition.view'],
    
    // Keuangan & Proyek
    ['title' => 'RAB dan Anggaran', 'category' => 'Keuangan', 'url' => url('/rab'), 'icon' => 'bi-file-earmark-text', 'perm' => 'budgets.view'],
    ['title' => 'Buat RAB Baru', 'category' => 'Keuangan', 'url' => url('/rab/tambah'), 'icon' => 'bi-plus-circle', 'perm' => 'budgets.create'],
    ['title' => 'Transaksi Keuangan', 'category' => 'Keuangan', 'url' => url('/transaksi'), 'icon' => 'bi-cash-stack', 'perm' => 'transactions.view'],
    ['title' => 'Catat Transaksi Baru', 'category' => 'Keuangan', 'url' => url('/transaksi/tambah'), 'icon' => 'bi-plus-circle', 'perm' => 'transactions.create'],
    ['title' => 'Proyek Pembangunan', 'category' => 'Proyek', 'url' => url('/proyek'), 'icon' => 'bi-hammer', 'perm' => 'projects.view'],
    ['title' => 'Jadwal Pembangunan', 'category' => 'Proyek', 'url' => url('/jadwal'), 'icon' => 'bi-calendar3', 'perm' => 'schedules.view'],
    
    // Akun & Administrasi
    ['title' => 'Profil Saya', 'category' => 'Akun', 'url' => url('/profil'), 'icon' => 'bi-person', 'perm' => null],
    ['title' => 'Notifikasi Sistem', 'category' => 'Akun', 'url' => url('/notifikasi'), 'icon' => 'bi-bell', 'perm' => null],
    ['title' => 'Kelola Pengguna', 'category' => 'Administrasi', 'url' => url('/pengguna'), 'icon' => 'bi-person-lines-fill', 'perm' => 'users.view'],
    ['title' => 'Hak Akses & Peran', 'category' => 'Administrasi', 'url' => url('/hak-akses'), 'icon' => 'bi-shield-lock', 'perm' => 'permissions.manage'],
    ['title' => 'Log Aktivitas & Audit', 'category' => 'Administrasi', 'url' => url('/aktivitas'), 'icon' => 'bi-journal-text', 'perm' => 'audit.view'],
    ['title' => 'Pengaturan Sistem', 'category' => 'Administrasi', 'url' => url('/pengaturan'), 'icon' => 'bi-sliders2', 'perm' => 'settings.manage'],
];

$accessibleItems = array_values(array_filter($searchItems, static function (array $item): bool {
    return $item['perm'] === null || can($item['perm']);
}));
?>
<div class="modal fade" id="commandPaletteModal" tabindex="-1" aria-labelledby="commandPaletteLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0" style="border-radius: 0.75rem; overflow: hidden;">
            <div class="p-3 border-bottom bg-light d-flex align-items-center gap-2">
                <i class="bi bi-search text-muted fs-5"></i>
                <input type="search" class="form-control form-control-lg border-0 bg-transparent shadow-none px-0" 
                       id="commandPaletteInput" 
                       placeholder="Ketik untuk mencari menu, sekolah, anggaran, atau halaman..." 
                       autocomplete="off" aria-label="Cari Cepat">
                <kbd class="bg-white text-muted border px-2 py-1 small rounded shadow-sm">ESC</kbd>
            </div>
            <div class="modal-body p-2" style="max-height: 400px; overflow-y: auto;" id="commandPaletteResults">
                <!-- Hasil pencarian dinamis disuntikkan via JavaScript -->
            </div>
            <div class="p-2 px-3 border-top bg-light d-flex justify-content-between align-items-center small text-muted">
                <span>Navigasi <kbd class="bg-white border px-1">↑</kbd> <kbd class="bg-white border px-1">↓</kbd> untuk memilih</span>
                <span>Tekan <kbd class="bg-white border px-1">ENTER</kbd> untuk membuka</span>
            </div>
        </div>
    </div>
</div>

<script>
window.__APP_SEARCH_ITEMS = <?= json_encode($accessibleItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
