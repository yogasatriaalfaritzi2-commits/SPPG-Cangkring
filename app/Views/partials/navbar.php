<?php
$navbarUser = auth_user();
?>
<header class="app-navbar">
    <button class="icon-btn d-lg-none" type="button" data-sidebar-toggle aria-label="Buka menu navigasi" aria-controls="appSidebar" aria-expanded="false">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <div class="navbar-heading">
        <span class="navbar-title"><?= e($pageTitle ?? $title ?? 'Dashboard') ?></span>
        <span class="navbar-sub d-none d-md-inline"><?= e(institution_name()) ?></span>
    </div>

    <?php if ($navbarUser !== null): ?>
        <?php $unreadCount = \App\Models\Notification::unreadCount((int)$navbarUser['id']); ?>
        <div class="navbar-actions d-flex align-items-center gap-2">
            <!-- Indikator Status Sistem Real-time -->
            <a href="<?= url('/pengaturan') ?>" class="badge rounded-pill bg-success-subtle text-success border border-success-subtle d-none d-md-inline-flex align-items-center gap-1 text-decoration-none py-1 px-2" title="Sistem & Basis Data Online (Prima)">
                <span class="spinner-grow spinner-grow-sm text-success" style="width: 0.45rem; height: 0.45rem;" role="status"></span>
                <span style="font-size: 0.75rem;">Sistem Online</span>
            </a>

            <a href="<?= url('/notifikasi') ?>" class="icon-btn position-relative" title="Notifikasi Sistem" aria-label="Notifikasi Sistem">
                <i class="bi bi-bell fs-5" aria-hidden="true"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;">
                        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                        <span class="visually-hidden">notifikasi belum dibaca</span>
                    </span>
                <?php endif; ?>
            </a>

            <div class="dropdown">
                <button class="user-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Buka menu akun">
                    <span class="avatar"><?= e(initials((string) $navbarUser['full_name'])) ?></span>
                    <span class="user-meta d-none d-sm-flex">
                        <span class="user-name"><?= e($navbarUser['full_name']) ?></span>
                        <span class="user-role"><?= e(role_nama($navbarUser)) ?></span>
                    </span>
                    <i class="bi bi-chevron-down user-caret d-none d-sm-inline" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end app-dropdown">
                    <li class="dropdown-header"><?= e($navbarUser['email']) ?></li>
                    <li>
                        <a class="dropdown-item" href="<?= url('/profil') ?>">
                            <i class="bi bi-person me-2" aria-hidden="true"></i>Profil saya
                        </a>
                    </li>
                    <?php if (can('settings.manage')): ?>
                        <li>
                            <a class="dropdown-item" href="<?= url('/pengaturan') ?>">
                                <i class="bi bi-sliders2 me-2" aria-hidden="true"></i>Pengaturan
                            </a>
                        </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="<?= url('/logout') ?>" method="post">
                            <?= csrf_field() ?>
                            <button class="dropdown-item text-danger" type="submit">
                                <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Keluar
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</header>
