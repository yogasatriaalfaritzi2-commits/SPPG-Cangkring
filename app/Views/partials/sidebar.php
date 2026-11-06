<?php
$sidebarUser = auth_user();
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Navigasi utama">
    <div class="sidebar-brand">
        <a href="<?= url(home_path()) ?>" class="brand-link">
            <?php $brandTone = 'inverted'; $brandEmblem = 40; require BASE_PATH . '/app/Views/partials/brand.php'; ?>
            <span class="brand-sub">Sistem Informasi MBG</span>
        </a>
    </div>

    <nav class="sidebar-nav">
        <p class="sidebar-label">Menu Utama</p>
        <?php if (can('dashboard.view')): ?>
            <a class="sidebar-link <?= nav_active('/dashboard') ?>" href="<?= url('/dashboard') ?>">
                <i class="bi bi-grid-1x2" aria-hidden="true"></i><span>Dashboard</span>
            </a>
        <?php endif; ?>
        <?php if (can('projects.view')): ?>
            <a class="sidebar-link <?= nav_active('/proyek') ?>" href="<?= url('/proyek') ?>">
                <i class="bi bi-hammer" aria-hidden="true"></i><span>Proyek Pembangunan</span>
            </a>
        <?php endif; ?>

        <?php if (can('schools.view') || can('beneficiaries.view') || can('menus.view')
            || can('menu_schedules.view') || can('nutrition.view')): ?>
            <p class="sidebar-label">Operasional MBG</p>

            <a class="sidebar-link <?= \App\Core\App::currentPath() === '/mbg' ? 'active' : '' ?>" href="<?= url('/mbg') ?>">
                <i class="bi bi-activity" aria-hidden="true"></i><span>Dashboard MBG</span>
            </a>

            <?php if (can('schools.view')): ?>
                <a class="sidebar-link <?= nav_active('/sekolah') ?>" href="<?= url('/sekolah') ?>">
                    <i class="bi bi-mortarboard" aria-hidden="true"></i><span>Sekolah Penerima</span>
                </a>
            <?php endif; ?>

            <?php if (can('beneficiaries.view')): ?>
                <a class="sidebar-link <?= nav_active('/penerima-manfaat') ?>" href="<?= url('/penerima-manfaat') ?>">
                    <i class="bi bi-people" aria-hidden="true"></i><span>Penerima Manfaat</span>
                </a>
            <?php endif; ?>

            <?php if (can('menus.view')): ?>
                <a class="sidebar-link <?= nav_active('/menu') ?>" href="<?= url('/menu') ?>">
                    <i class="bi bi-card-checklist" aria-hidden="true"></i><span>Menu MBG</span>
                </a>
            <?php endif; ?>

            <?php if (can('menu_schedules.view')): ?>
                <a class="sidebar-link <?= nav_active('/jadwal-menu') ?>" href="<?= url('/jadwal-menu') ?>">
                    <i class="bi bi-calendar-week" aria-hidden="true"></i><span>Jadwal Menu</span>
                </a>
            <?php endif; ?>

            <?php if (can('nutrition.view')): ?>
                <a class="sidebar-link <?= nav_active('/bahan', '/referensi-gizi') ?>" href="<?= url('/bahan') ?>">
                    <i class="bi bi-egg-fried" aria-hidden="true"></i><span>Bahan dan Gizi</span>
                </a>

                <a class="sidebar-link <?= nav_active('/analisis-gizi') ?>" href="<?= url('/analisis-gizi') ?>">
                    <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i><span>Analisis Gizi</span>
                </a>

                <a class="sidebar-link <?= nav_active('/target-gizi') ?>" href="<?= url('/target-gizi') ?>">
                    <i class="bi bi-bullseye" aria-hidden="true"></i><span>Target Gizi</span>
                </a>
            <?php endif; ?>

            <?php if (can('menus.view')): ?>
                <a class="sidebar-link <?= nav_active('/aturan-menu') ?>" href="<?= url('/aturan-menu') ?>">
                    <i class="bi bi-list-check" aria-hidden="true"></i><span>Aturan Menu</span>
                </a>
            <?php endif; ?>

            <?php if (can('schools.manage') || can('beneficiaries.manage') || can('nutrition.manage') || can('menus.manage')): ?>
                <a class="sidebar-link <?= nav_active('/mbg/master') ?>" href="<?= url('/mbg/master/jenjang') ?>">
                    <i class="bi bi-database" aria-hidden="true"></i><span>Data Master MBG</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (can('budgets.view') || can('transactions.view') || can('schedules.view')): ?>
            <p class="sidebar-label">Keuangan dan Jadwal</p>

            <a class="sidebar-link <?= nav_active('/keuangan') ?>" href="<?= url('/keuangan') ?>">
                <i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span>Ringkasan Keuangan</span>
            </a>

            <?php if (can('budgets.view')): ?>
                <a class="sidebar-link <?= nav_active('/rab', '/anggaran', '/kategori') ?>" href="<?= url('/rab') ?>">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>RAB dan Anggaran</span>
                </a>
            <?php endif; ?>

            <?php if (can('transactions.view')): ?>
                <a class="sidebar-link <?= nav_active('/transaksi') ?>" href="<?= url('/transaksi') ?>">
                    <i class="bi bi-cash-stack" aria-hidden="true"></i><span>Transaksi</span>
                </a>
            <?php endif; ?>

            <?php if (can('schedules.view')): ?>
                <a class="sidebar-link <?= nav_active('/jadwal') ?>" href="<?= url('/jadwal') ?>">
                    <i class="bi bi-calendar3" aria-hidden="true"></i><span>Jadwal Pembangunan</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <a class="sidebar-link <?= nav_active('/notifikasi') ?>" href="<?= url('/notifikasi') ?>">
            <i class="bi bi-bell" aria-hidden="true"></i><span>Notifikasi</span>
        </a>
        <a class="sidebar-link <?= nav_active('/profil') ?>" href="<?= url('/profil') ?>">
            <i class="bi bi-person" aria-hidden="true"></i><span>Profil Saya</span>
        </a>

        <?php if (can('users.view') || can('permissions.manage') || can('audit.view') || can('settings.manage')): ?>
            <p class="sidebar-label">Administrasi</p>

            <?php if (can('users.view')): ?>
                <a class="sidebar-link <?= nav_active('/pengguna') ?>" href="<?= url('/pengguna') ?>">
                    <i class="bi bi-person-lines-fill" aria-hidden="true"></i><span>Pengguna</span>
                </a>
            <?php endif; ?>

            <?php if (can('permissions.manage')): ?>
                <a class="sidebar-link <?= nav_active('/hak-akses') ?>" href="<?= url('/hak-akses') ?>">
                    <i class="bi bi-shield-lock" aria-hidden="true"></i><span>Hak Akses</span>
                </a>
            <?php endif; ?>

            <?php if (can('audit.view')): ?>
                <a class="sidebar-link <?= nav_active('/aktivitas') ?>" href="<?= url('/aktivitas') ?>">
                    <i class="bi bi-journal-text" aria-hidden="true"></i><span>Aktivitas</span>
                </a>
            <?php endif; ?>

            <?php if (can('settings.manage')): ?>
                <a class="sidebar-link <?= nav_active('/pengaturan') ?>" href="<?= url('/pengaturan') ?>">
                    <i class="bi bi-sliders2" aria-hidden="true"></i><span>Pengaturan</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-foot">
        <span>Versi <?= e(\App\Core\App::config('app.version', '0.1.0')) ?></span>
        <span><?= e($sidebarUser !== null ? role_nama($sidebarUser) : 'Sistem informasi') ?></span>
    </div>
</aside>
