<?php
declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\BeneficiaryController;
use App\Controllers\BudgetController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\FinanceDashboardController;
use App\Controllers\IngredientController;
use App\Controllers\MbgCatalogController;
use App\Controllers\MbgDashboardController;
use App\Controllers\MenuController;
use App\Controllers\MenuRuleController;
use App\Controllers\MenuScheduleController;
use App\Controllers\NotificationController;
use App\Controllers\NutritionController;
use App\Controllers\ProfileController;
use App\Controllers\ProgressController;
use App\Controllers\ProjectController;
use App\Controllers\RabController;
use App\Controllers\RabItemController;
use App\Controllers\RoleController;
use App\Controllers\ScheduleController;
use App\Controllers\SchoolController;
use App\Controllers\SettingsController;
use App\Controllers\TeamController;
use App\Controllers\TransactionController;
use App\Controllers\UserController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PermissionMiddleware;

return static function (Router $router): void {
    $auth = [AuthMiddleware::class];
    $permission = static fn (string $permissions): array => [
        AuthMiddleware::class,
        PermissionMiddleware::class . ':' . $permissions,
    ];

    $router->get('/', [DashboardController::class, 'root']);

    $router->get('/login', [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
    $router->post('/login', [AuthController::class, 'login'], [GuestMiddleware::class]);
    $router->post('/logout', [AuthController::class, 'logout'], $auth);

    $router->get('/dashboard', [DashboardController::class, 'index'], $permission('dashboard.view'));

    $router->get('/notifikasi', [NotificationController::class, 'index'], $auth);
    $router->post('/notifikasi/baca/{id}', [NotificationController::class, 'read'], $auth);
    $router->post('/notifikasi/baca-semua', [NotificationController::class, 'readAll'], $auth);

    $router->get('/proyek', [ProjectController::class, 'index'], $permission('projects.view'));
    $router->get('/proyek/tambah', [ProjectController::class, 'create'], $permission('projects.create'));
    $router->post('/proyek/tambah', [ProjectController::class, 'store'], $permission('projects.create'));
    $router->get('/proyek/{id}', [ProjectController::class, 'show'], $permission('projects.view'));
    $router->get('/proyek/{id}/ubah', [ProjectController::class, 'edit'], $permission('projects.update'));
    $router->post('/proyek/{id}/ubah', [ProjectController::class, 'update'], $permission('projects.update'));
    $router->post('/proyek/{id}/status', [ProjectController::class, 'status'], $permission('projects.status.update'));
    $router->post('/proyek/{id}/arsip', [ProjectController::class, 'archive'], $permission('projects.archive'));
    $router->post('/proyek/{id}/progres', [ProgressController::class, 'store'], $permission('projects.progress.update'));
    $router->post('/proyek/{id}/dokumen', [DocumentController::class, 'store'], $permission('projects.documents.upload'));
    $router->post('/proyek/{id}/tim', [TeamController::class, 'store'], $permission('projects.team.manage'));
    $router->post('/proyek/{id}/tim/{assignment}', [TeamController::class, 'update'], $permission('projects.team.manage'));
    $router->post('/proyek/{id}/tim/{assignment}/hapus', [TeamController::class, 'unassign'], $permission('projects.team.manage'));

    $router->get('/dokumen/{id}/tampilan', [DocumentController::class, 'display'], $permission('projects.documents.view'));
    $router->get('/dokumen/{id}/unduh', [DocumentController::class, 'download'], $permission('projects.documents.view'));
    $router->post('/dokumen/{id}/hapus', [DocumentController::class, 'destroy'], $permission('projects.documents.delete'));

    // Modul keuangan. Rute dengan segmen tetap didaftarkan lebih dahulu karena
    // pencocokan mengikuti urutan dan {id} dapat menelan nama aksi.
    $router->get('/keuangan', [FinanceDashboardController::class, 'index'], $permission(
        'budgets.view,transactions.view,schedules.view'
    ));

    $router->get('/rab', [RabController::class, 'index'], $permission('budgets.view'));
    $router->get('/rab/tambah', [RabController::class, 'create'], $permission('budgets.create'));
    $router->post('/rab/tambah', [RabController::class, 'store'], $permission('budgets.create'));
    $router->post('/rab/{plan}/item', [RabItemController::class, 'store'], $permission('budgets.update'));
    $router->get('/rab/{id}/ekspor', [RabController::class, 'export'], $permission('budgets.export'));
    $router->post('/rab/{id}/impor', [RabController::class, 'import'], $permission('budgets.create'));
    $router->get('/rab/{id}/ubah', [RabController::class, 'edit'], $permission('budgets.update'));
    $router->post('/rab/{id}/ubah', [RabController::class, 'update'], $permission('budgets.update'));
    $router->post('/rab/{id}/ajukan', [RabController::class, 'submit'], $permission('budgets.revision'));
    $router->post('/rab/{id}/revisi', [RabController::class, 'revise'], $permission('budgets.revision'));
    $router->post('/rab/{id}/keputusan', [RabController::class, 'decide'], $permission('budgets.approve'));
    $router->post('/rab/{id}/hapus', [RabController::class, 'destroy'], $permission('budgets.update'));
    $router->get('/rab/{id}', [RabController::class, 'show'], $permission('budgets.view'));

    $router->post('/item/{id}/ubah', [RabItemController::class, 'update'], $permission('budgets.update'));
    $router->post('/item/{id}/duplikat', [RabItemController::class, 'duplicate'], $permission('budgets.update'));
    $router->post('/item/{id}/hapus', [RabItemController::class, 'destroy'], $permission('budgets.update'));

    $router->get('/kategori', [CategoryController::class, 'index'], $permission('budgets.view'));
    $router->post('/kategori/tambah', [CategoryController::class, 'store'], $permission('budgets.manage'));
    $router->post('/kategori/{id}/ubah', [CategoryController::class, 'update'], $permission('budgets.manage'));
    $router->post('/kategori/{id}/hapus', [CategoryController::class, 'destroy'], $permission('budgets.manage'));

    $router->get('/anggaran', [BudgetController::class, 'index'], $permission('budgets.view'));
    $router->get('/anggaran/ekspor', [BudgetController::class, 'export'], $permission('budgets.export'));
    $router->post('/anggaran/{project}/perubahan', [BudgetController::class, 'storeRevision'], $permission('budgets.revision'));
    $router->get('/anggaran/perubahan/{id}', [BudgetController::class, 'showRevision'], $permission('budgets.view,budgets.approve'));
    $router->post('/anggaran/perubahan/{id}/keputusan', [BudgetController::class, 'decideRevision'], $permission('budgets.approve'));
    $router->get('/anggaran/{id}', [BudgetController::class, 'show'], $permission('budgets.view'));

    $router->get('/transaksi', [TransactionController::class, 'index'], $permission('transactions.view'));
    $router->get('/transaksi/tambah', [TransactionController::class, 'create'], $permission('transactions.create'));
    $router->post('/transaksi/tambah', [TransactionController::class, 'store'], $permission('transactions.create'));
    $router->get('/transaksi/ekspor', [TransactionController::class, 'export'], $permission('transactions.export'));
    $router->get('/transaksi/{id}/ubah', [TransactionController::class, 'edit'], $permission('transactions.update'));
    $router->post('/transaksi/{id}/ubah', [TransactionController::class, 'update'], $permission('transactions.update'));
    $router->post('/transaksi/{id}/bukti', [TransactionController::class, 'storeAttachment'], $permission('transactions.create'));
    $router->post('/transaksi/{id}/ajukan', [TransactionController::class, 'submit'], $permission('transactions.create'));
    $router->post('/transaksi/{id}/keputusan', [TransactionController::class, 'decide'], $permission('transactions.approve'));
    $router->post('/transaksi/{id}/batalkan', [TransactionController::class, 'cancel'], $permission('transactions.cancel'));
    $router->post('/transaksi/{id}/hapus', [TransactionController::class, 'destroy'], $permission('transactions.update'));
    $router->get('/transaksi/{id}/cetak', [TransactionController::class, 'print'], $permission('transactions.export'));
    $router->get('/transaksi/{id}', [TransactionController::class, 'show'], $permission('transactions.view'));

    $router->get('/bukti/{id}', [TransactionController::class, 'attachment'], $permission('transactions.view'));
    $router->post('/bukti/{id}/hapus', [TransactionController::class, 'destroyAttachment'], $permission('transactions.update'));

    $router->get('/jadwal', [ScheduleController::class, 'index'], $permission('schedules.view'));
    $router->get('/jadwal/kalender', [ScheduleController::class, 'calendar'], $permission('schedules.view'));
    $router->get('/jadwal/gantt', [ScheduleController::class, 'gantt'], $permission('schedules.view'));
    $router->get('/jadwal/ekspor', [ScheduleController::class, 'export'], $permission('schedules.export'));
    $router->get('/jadwal/tambah', [ScheduleController::class, 'create'], $permission('schedules.create'));
    $router->post('/jadwal/tambah', [ScheduleController::class, 'store'], $permission('schedules.create'));
    $router->get('/jadwal/{id}/ubah', [ScheduleController::class, 'edit'], $permission('schedules.update'));
    $router->post('/jadwal/{id}/ubah', [ScheduleController::class, 'update'], $permission('schedules.update'));
    $router->post('/jadwal/{id}/ketergantungan', [ScheduleController::class, 'storeDependency'], $permission('schedules.create'));
    $router->post('/jadwal/{id}/hapus', [ScheduleController::class, 'destroy'], $permission('schedules.delete'));
    $router->get('/jadwal/{id}', [ScheduleController::class, 'show'], $permission('schedules.view'));

    $router->post('/ketergantungan/{id}/hapus', [ScheduleController::class, 'destroyDependency'], $permission('schedules.update'));

    // Modul MBG. Sama seperti modul keuangan, segmen tetap (impor, ekspor, catat, individu,
    // riwayat, tambah) didaftarkan sebelum {id} karena {id} dapat menelan nama aksi.
    $router->get('/mbg', [MbgDashboardController::class, 'index'], $permission('dashboard.view'));

    $router->get('/sekolah', [SchoolController::class, 'index'], $permission('schools.view'));
    $router->get('/sekolah/ekspor', [SchoolController::class, 'export'], $permission('schools.export'));
    $router->get('/sekolah/impor', [SchoolController::class, 'importForm'], $permission('schools.import'));
    $router->post('/sekolah/impor', [SchoolController::class, 'import'], $permission('schools.import'));
    $router->get('/sekolah/tambah', [SchoolController::class, 'create'], $permission('schools.create'));
    $router->post('/sekolah/tambah', [SchoolController::class, 'store'], $permission('schools.create'));
    $router->post('/sekolah/{id}/pelayanan', [SchoolController::class, 'storeDay'], $permission('schools.update'));
    $router->post('/sekolah/{id}/pelayanan/{day}/ubah', [SchoolController::class, 'updateDay'], $permission('schools.update'));
    $router->post('/sekolah/{id}/pelayanan/{day}/hapus', [SchoolController::class, 'destroyDay'], $permission('schools.update'));
    $router->get('/sekolah/{id}/ubah', [SchoolController::class, 'edit'], $permission('schools.update'));
    $router->post('/sekolah/{id}/ubah', [SchoolController::class, 'update'], $permission('schools.update'));
    $router->post('/sekolah/{id}/status', [SchoolController::class, 'status'], $permission('schools.archive'));
    $router->post('/sekolah/{id}/hapus', [SchoolController::class, 'destroy'], $permission('schools.manage'));
    $router->get('/sekolah/{id}', [SchoolController::class, 'show'], $permission('schools.view'));

    $router->get('/penerima-manfaat', [BeneficiaryController::class, 'index'], $permission('beneficiaries.view'));
    $router->get('/penerima-manfaat/riwayat', [BeneficiaryController::class, 'histories'], $permission('beneficiaries.view'));
    $router->get('/penerima-manfaat/ekspor', [BeneficiaryController::class, 'export'], $permission('beneficiaries.export'));
    $router->get('/penerima-manfaat/catat', [BeneficiaryController::class, 'create'], $permission('beneficiaries.manage'));
    $router->post('/penerima-manfaat/catat', [BeneficiaryController::class, 'store'], $permission('beneficiaries.manage'));
    // Mode individu hanya rutenya gated oleh individual.view; hak ubahnya diperiksa ulang di
    // controller karena keduanya (lihat dan tulis) harus dipegang sekaligus.
    $router->get('/penerima-manfaat/individu', [BeneficiaryController::class, 'individual'], $permission('beneficiaries.individual.view'));
    $router->post('/penerima-manfaat/individu', [BeneficiaryController::class, 'storeIndividual'], $permission('beneficiaries.individual.view'));
    $router->post('/penerima-manfaat/individu/{id}/ubah', [BeneficiaryController::class, 'updateIndividual'], $permission('beneficiaries.individual.view'));
    $router->post('/penerima-manfaat/individu/{id}/status', [BeneficiaryController::class, 'statusIndividual'], $permission('beneficiaries.individual.view'));
    $router->post('/penerima-manfaat/individu/{id}/hapus', [BeneficiaryController::class, 'destroyIndividual'], $permission('beneficiaries.individual.view'));
    $router->get('/penerima-manfaat/{id}/ubah', [BeneficiaryController::class, 'edit'], $permission('beneficiaries.manage'));
    $router->post('/penerima-manfaat/{id}', [BeneficiaryController::class, 'update'], $permission('beneficiaries.manage'));
    $router->post('/penerima-manfaat/{id}/status', [BeneficiaryController::class, 'status'], $permission('beneficiaries.manage'));
    $router->post('/penerima-manfaat/{id}/hapus', [BeneficiaryController::class, 'destroy'], $permission('beneficiaries.manage'));
    $router->get('/penerima-manfaat/{id}', [BeneficiaryController::class, 'show'], $permission('beneficiaries.view'));

    // Data master MBG (jenjang, kategori penerima, kategori bahan, kategori menu). Rute ini
    // terbuka untuk salah satu dari empat hak; controller memeriksa hak per jenis data.
    $master = $permission('schools.manage,beneficiaries.manage,nutrition.manage,menus.manage');
    $router->get('/mbg/master/{kind}', [MbgCatalogController::class, 'index'], $master);
    $router->post('/mbg/master/{kind}', [MbgCatalogController::class, 'store'], $master);
    $router->post('/mbg/master/{kind}/{id}/ubah', [MbgCatalogController::class, 'update'], $master);
    $router->post('/mbg/master/{kind}/{id}/hapus', [MbgCatalogController::class, 'destroy'], $master);

    $router->get('/bahan', [IngredientController::class, 'index'], $permission('nutrition.view'));
    $router->get('/bahan/ekspor', [IngredientController::class, 'export'], $permission('nutrition.export'));
    $router->get('/bahan/tambah', [IngredientController::class, 'create'], $permission('nutrition.manage'));
    $router->post('/bahan/tambah', [IngredientController::class, 'store'], $permission('nutrition.manage'));
    $router->post('/bahan/gizi/{id}/ubah', [IngredientController::class, 'updateNutrient'], $permission('nutrition.manage'));
    $router->post('/bahan/gizi/{id}/hapus', [IngredientController::class, 'destroyNutrient'], $permission('nutrition.manage'));
    $router->get('/referensi-gizi', [IngredientController::class, 'references'], $permission('nutrition.view'));
    $router->post('/referensi-gizi', [IngredientController::class, 'storeReference'], $permission('nutrition.manage'));
    $router->post('/referensi-gizi/{id}/ubah', [IngredientController::class, 'updateReference'], $permission('nutrition.manage'));
    $router->post('/referensi-gizi/{id}/hapus', [IngredientController::class, 'destroyReference'], $permission('nutrition.manage'));
    $router->get('/bahan/{id}/ubah', [IngredientController::class, 'edit'], $permission('nutrition.manage'));
    $router->post('/bahan/{id}/ubah', [IngredientController::class, 'update'], $permission('nutrition.manage'));
    $router->post('/bahan/{id}/gizi', [IngredientController::class, 'storeNutrient'], $permission('nutrition.manage'));
    $router->post('/bahan/{id}/hapus', [IngredientController::class, 'destroy'], $permission('nutrition.manage'));
    $router->get('/bahan/{id}', [IngredientController::class, 'show'], $permission('nutrition.view'));

    $router->get('/menu', [MenuController::class, 'index'], $permission('menus.view'));
    $router->get('/menu/revisi', [MenuController::class, 'revisions'], $permission('menus.view'));
    $router->get('/menu/rekomendasi', [MenuController::class, 'suggest'], $permission('menus.view'));
    $router->get('/menu/tambah', [MenuController::class, 'create'], $permission('menus.create,menus.manage'));
    $router->post('/menu/tambah', [MenuController::class, 'store'], $permission('menus.create,menus.manage'));
    $router->get('/menu/{id}/foto', [MenuController::class, 'photo'], $permission('menus.view'));
    $router->post('/menu/{id}/foto/hapus', [MenuController::class, 'removePhoto'], $permission('menus.update,menus.manage'));
    $router->get('/menu/{id}/ubah', [MenuController::class, 'edit'], $permission('menus.update,menus.manage'));
    $router->post('/menu/{id}/ubah', [MenuController::class, 'update'], $permission('menus.update,menus.manage'));
    $router->get('/menu/{id}/revisi', [MenuController::class, 'reviseForm'], $permission('menus.revision,menus.manage'));
    $router->post('/menu/{id}/revisi', [MenuController::class, 'revise'], $permission('menus.revision,menus.manage'));
    $router->post('/menu/{id}/kirim', [MenuController::class, 'submit'], $permission('menus.create,menus.update,menus.manage'));
    $router->post('/menu/{id}/keputusan', [MenuController::class, 'decide'], $permission('menus.approve'));
    $router->post('/menu/{id}/batalkan', [MenuController::class, 'withdraw'], $permission('menus.create,menus.update,menus.manage'));
    $router->post('/menu/{id}/hapus', [MenuController::class, 'destroy'], $permission('menus.manage'));
    $router->get('/menu/{id}', [MenuController::class, 'show'], $permission('menus.view'));

    $router->get('/jadwal-menu', [MenuScheduleController::class, 'index'], $permission('menu_schedules.view'));
    $router->get('/jadwal-menu/kebutuhan-bahan/ekspor', [MenuScheduleController::class, 'exportMaterials'], $permission('nutrition.export'));
    $router->get('/jadwal-menu/kebutuhan-bahan', [MenuScheduleController::class, 'materials'], $permission('menu_schedules.view'));
    $router->get('/jadwal-menu/tambah', [MenuScheduleController::class, 'create'], $permission('menu_schedules.manage'));
    $router->post('/jadwal-menu/tambah', [MenuScheduleController::class, 'store'], $permission('menu_schedules.manage'));
    $router->post('/jadwal-menu/salin', [MenuScheduleController::class, 'copyDay'], $permission('menu_schedules.manage'));
    $router->get('/jadwal-menu/{id}/ubah', [MenuScheduleController::class, 'edit'], $permission('menu_schedules.manage'));
    $router->post('/jadwal-menu/{id}/ubah', [MenuScheduleController::class, 'update'], $permission('menu_schedules.manage'));
    $router->post('/jadwal-menu/{id}/kirim', [MenuScheduleController::class, 'submit'], $permission('menu_schedules.manage'));
    $router->post('/jadwal-menu/{id}/keputusan', [MenuScheduleController::class, 'decide'], $permission('menus.approve,menu_schedules.manage'));
    $router->post('/jadwal-menu/{id}/selesai', [MenuScheduleController::class, 'complete'], $permission('menu_schedules.manage'));
    $router->post('/jadwal-menu/{id}/hapus', [MenuScheduleController::class, 'destroy'], $permission('menu_schedules.manage'));
    $router->get('/jadwal-menu/{id}', [MenuScheduleController::class, 'show'], $permission('menu_schedules.view'));

    $router->get('/analisis-gizi', [NutritionController::class, 'index'], $permission('nutrition.view'));
    $router->get('/analisis-gizi/harian', [NutritionController::class, 'daily'], $permission('nutrition.view'));
    $router->get('/analisis-gizi/ekspor', [NutritionController::class, 'export'], $permission('nutrition.export'));
    $router->get('/analisis-gizi/{id}', [NutritionController::class, 'show'], $permission('nutrition.view'));
    $router->get('/target-gizi', [NutritionController::class, 'targets'], $permission('nutrition.view'));
    $router->post('/target-gizi/kelompok', [NutritionController::class, 'storeGroup'], $permission('nutrition.manage'));
    $router->post('/target-gizi/kelompok/{id}', [NutritionController::class, 'updateGroup'], $permission('nutrition.manage'));
    $router->post('/target-gizi/kelompok/{id}/hapus', [NutritionController::class, 'destroyGroup'], $permission('nutrition.manage'));
    $router->post('/target-gizi/nilai', [NutritionController::class, 'storeTarget'], $permission('nutrition.manage'));
    $router->post('/target-gizi/nilai/{id}', [NutritionController::class, 'updateTarget'], $permission('nutrition.manage'));
    $router->post('/target-gizi/nilai/{id}/hapus', [NutritionController::class, 'destroyTarget'], $permission('nutrition.manage'));

    $router->get('/aturan-menu', [MenuRuleController::class, 'index'], $permission('menus.view'));
    $router->post('/aturan-menu', [MenuRuleController::class, 'store'], $permission('menus.manage'));
    $router->get('/aturan-menu/uji/{id}', [MenuRuleController::class, 'check'], $permission('menus.view'));
    $router->post('/aturan-menu/{id}', [MenuRuleController::class, 'update'], $permission('menus.manage'));
    $router->post('/aturan-menu/{id}/hapus', [MenuRuleController::class, 'destroy'], $permission('menus.manage'));

    $router->get('/profil', [ProfileController::class, 'show'], $auth);
    $router->post('/profil/kata-sandi', [ProfileController::class, 'updatePassword'], $auth);

    $router->get('/pengaturan', [SettingsController::class, 'index'], $permission('settings.manage'));
    $router->post('/pengaturan', [SettingsController::class, 'update'], $permission('settings.manage'));
    $router->post('/pengaturan/keuangan', [SettingsController::class, 'updateFinance'], $permission('settings.manage'));

    $router->get('/pengguna', [UserController::class, 'index'], $permission('users.view'));
    $router->get('/pengguna/tambah', [UserController::class, 'create'], $permission('users.create'));
    $router->post('/pengguna/tambah', [UserController::class, 'store'], $permission('users.create'));
    $router->get('/pengguna/{id}', [UserController::class, 'edit'], $permission('users.update'));
    $router->post('/pengguna/{id}', [UserController::class, 'update'], $permission('users.update'));
    $router->post('/pengguna/{id}/status', [UserController::class, 'status'], $permission('users.update'));
    $router->post('/pengguna/{id}/kata-sandi', [UserController::class, 'password'], $permission('users.update'));
    $router->post('/pengguna/{id}/hapus', [UserController::class, 'destroy'], $permission('users.delete'));

    $router->get('/hak-akses', [RoleController::class, 'index'], $permission('permissions.manage'));
    $router->post('/hak-akses/peran', [RoleController::class, 'store'], $permission('permissions.manage'));
    $router->post('/hak-akses/peran/{id}', [RoleController::class, 'update'], $permission('permissions.manage'));
    $router->post('/hak-akses/peran/{id}/izin', [RoleController::class, 'syncPermissions'], $permission('permissions.manage'));

    $router->get('/aktivitas', [ActivityController::class, 'index'], $permission('audit.view'));
};
