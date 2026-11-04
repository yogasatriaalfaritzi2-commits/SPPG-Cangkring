# SPPG Cangkring

Sistem Informasi Manajemen Pembangunan dan Operasional Makan Bergizi Gratis (MBG) untuk SPPG Cangkring.

Tahap 1 membangun **fondasi aplikasi**: struktur MVC, konfigurasi, routing, koneksi database, tata letak antarmuka, serta halaman Login, Dashboard, Profil, Pengaturan, dan halaman galat. Tahap 2 menambahkan **skema database penuh, autentikasi, dan Role-Based Access Control (RBAC)**: manajemen pengguna, pengelolaan peran dan hak akses, halaman log aktivitas, serta penegakan izin di sisi server. Tahap 3 membangun **dashboard utama dan manajemen proyek pembangunan**: daftar dan detail proyek, monitoring progres beserta riwayatnya, dokumentasi dan unggahan berkas, tim proyek, notifikasi internal, grafik Chart.js dari data MySQL yang sebenarnya, serta perluasan matriks izin menjadi 33 izin. Tahap 4 membangun **modul RAB, manajemen anggaran, transaksi keuangan, dan jadwal pembangunan**: RAB bertingkat dengan perhitungan di sisi server dan alur persetujuan beralasan, anggaran per kategori dengan ambang peringatan yang dapat diatur, transaksi empat jenis berbukti unggah dan tak dapat dihapus setelah disetujui, kalender FullCalendar serta bagan Gantt dengan riwayat perubahan, dan dashboard keuangan yang menggabungkan semuanya.

Versi `0.4.0` melanjutkan codebase tahap pertama sampai ketiga — struktur folder, konfigurasi, desain, dan skema yang lama tidak dibangun ulang; tabel baru ditambahkan lewat migration bernomor `011`–`014` tanpa menduplikasi tabel yang sudah ada, dan modul proyek/notifikasi/audit yang lama tetap dipakai kembali.

- Versi: `0.4.0`
- Lingkungan pengembangan: XAMPP (Apache, PHP 8.x, MySQL/MariaDB 10.4+)
- Tanpa Composer dan tanpa framework PHP; Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Chart.js 4.4.1, dan FullCalendar 6.1.18 disimpan lokal di `public/assets/vendor/`.

---

## 1. Persyaratan

| Komponen | Keterangan |
| --- | --- |
| PHP | 8.1 atau lebih baru |
| Ekstensi PHP | `pdo_mysql`, `mbstring`, `json`, `fileinfo` (deteksi MIME unggahan; tanpa ekstensi ini seluruh unggahan ditolak) |
| Database | MySQL 5.7+ atau MariaDB 10.4+ |
| Web server | Apache dengan `mod_rewrite` dan `AllowOverride All`, atau PHP built-in server untuk pengembangan |

Aplikasi tidak membutuhkan Composer, Node.js, atau koneksi internet saat dijalankan. Seluruh aset CSS, JavaScript, ikon, logo, Chart.js 4.4.1 (`public/assets/vendor/chartjs/chart.umd.min.js`), dan bundel kalender FullCalendar 6.1.18 (`public/assets/vendor/fullcalendar/index.global.min.js`) tersedia lokal.

Logo berada di `public/assets/images/` dan semuanya memuat nama **SPPG Cangkring**:

| Berkas | Isi | Dipakai di |
| --- | --- | --- |
| `logo-sppg-lockup-inverted.svg` | Lambang + tulisan `SPPG` / `CANGKRING`, teks terang | Sidebar aplikasi, panel kiri halaman masuk |
| `logo-sppg-lockup.svg` | Lockup yang sama, teks gelap `#0B2545` / `#177A55` | Kepala halaman galat 4xx/5xx (latar terang) |
| `logo-sppg.svg` | Lambang saja, 64×64 | Ikon logo pada kartu masuk layar sempit |
| `favicon.svg` | Lambang saja | Tab peramban |

Kedua lockup berukuran intrinsik 244×72 (`width`/`height` + `viewBox` pada elemen akar), tulisan memakai `<text>` dengan rangkaian font `Segoe UI, system-ui, -apple-system, 'Helvetica Neue', Arial` sehingga mengikuti font aplikasi. Nama tercetak di dalam berkas gambar, jadi mengganti `APP_NAME` tidak mengubah logo; `APP_NAME` dan `institution_name()` tetap mengendalikan `<title>`, meta deskripsi, dan catatan kaki.

---

## 2. Struktur folder

```
SPPG-Cangkring/
├── app/
│   ├── Controllers/        19 controller: Auth, Dashboard, Project, Progress, Document, Team, Notification,
│   │                       Activity, User, Role, Profile, Settings,
│   │                       Rab, RabItem, Category, Budget, Transaction, Schedule, FinanceDashboard
│   ├── Core/               App, Router, Request, Session, Csrf, Database, Model, View, Auth, FileUpload, ErrorHandler
│   │   └── Exceptions/     HttpException, NotFoundHttpException
│   ├── Helpers/            Helper URL, format, validasi (password/telepon/slug/nominal), dan tampilan
│   ├── Middleware/         AuthMiddleware, GuestMiddleware, PermissionMiddleware
│   ├── Models/             User, Role, Permission, ActivityLog, LoginAttempt, Setting,
│   │                       Project, ProjectProgress, ProjectDocument, ProjectTeam, Notification,
│   │                       BudgetPlan, BudgetItem, BudgetCategory, BudgetRevision, BudgetSummary,
│   │                       FinancialTransaction, ProjectSchedule
│   └── Views/              Layout, partial, halaman, dan halaman galat
├── bootstrap/app.php       Titik awal aplikasi: autoloader, env, config, error handler
├── config/                 Konfigurasi aplikasi dan database
├── database/
│   ├── migrations/         Migration SQL bernomor (001–014)
│   ├── install.php         Installer CLI (idempoten)
│   └── schema.sql          Referensi skema fondasi (ringkasan migration 001–005)
├── public/                 Satu-satunya folder yang boleh diakses publik
│   ├── assets/             CSS, JS, gambar, vendor lokal (Bootstrap, Icons, Chart.js, FullCalendar)
│   ├── index.php           Front controller
│   └── .htaccess           Rewrite dan header keamanan
├── routes/web.php          Definisi rute dan penerapan middleware izin
├── storage/
│   ├── logs/               Log aplikasi harian
│   └── uploads/projects/   Berkas proyek & bukti transaksi (nama acak, hanya diakses lewat controller)
├── tests/run.php            Uji fondasi (database, routing, autentikasi, keamanan header)
├── tests/rbac.php           Uji RBAC, manajemen pengguna, hak akses, dan audit log
├── tests/projects.php       Uji dashboard, modul proyek, unggahan, tim, dan notifikasi
├── tests/finance_models.php Uji perhitungan model RAB/anggaran/transaksi/jadwal (CLI, rollback)
├── tests/finance_http.php   Uji HTTP menyeluruh modul keuangan & jadwal, termasuk konkurensi
├── tests/routes.php         Periksa setiap rute menunjuk metode yang ada dan tidak menutupi
├── tests/controller_calls.php  Periksa pemanggilan statis di dalam controller
├── .env.example            Contoh konfigurasi lingkungan
└── README.md
```

Seluruh berkas sensitif (`.env`, `app/`, `bootstrap/`, `config/`, `database/`, `storage/`, `tests/`) berada di luar `public/` dan ditolak oleh `.htaccess` bila diakses melalui web.

---

## 3. Konfigurasi database

1. Salin contoh konfigurasi:

   ```bash
   cp .env.example .env
   ```

   Di Windows, salin `.env.example` lalu ubah nama menjadi `.env`.

2. Sesuaikan bagian database pada `.env`:

   ```dotenv
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=sppg_cangkring
   DB_USERNAME=root
   DB_PASSWORD=
   DB_CHARSET=utf8mb4
   ```

   Nilai bawaan mengikuti XAMPP (`root` tanpa kata sandi). Untuk server lain, gunakan kredensial yang sesuai.

3. Jalankan installer dari terminal proyek:

   ```bash
   D:\PROGRAM\XAMPP\php\php.exe database/install.php
   ```

   Installer akan:
   - membuat database `sppg_cangkring` bila belum ada (nama database divalidasi),
   - membuat tabel `migrations` bila belum ada,
   - menerapkan seluruh migration yang belum tercatat,
   - mengisi 11 peran bawaan, 33 izin, dan matriks `role_permissions`,
   - membuat akun Super Admin awal bila belum ada pengguna dengan peran tersebut,
   - mengisi pengaturan bawaan.

   Installer aman dijalankan berulang: migration yang sudah terpasang dilewati, peran dan izin yang sudah ada tidak ditimpa, dan kata sandi administrator yang sudah ada **tidak** diubah.

4. Akun Super Admin awal:

   | Kolom | Nilai |
   | --- | --- |
   | Nama pengguna | `admin` |
   | Kata sandi | `Admin#Sppg2026` |
   | Peran | Super Admin (`super_admin`) |

   Kata sandi bawaan dapat diganti dengan mengisi `SEED_ADMIN_PASSWORD` pada `.env` sebelum instalasi pertama. **Segera ubah kata sandi melalui menu Profil setelah masuk pertama kali.**

   Super Admin adalah satu-satunya peran yang memegang izin `permissions.manage`, sehingga hanya akun ini yang dapat menambah peran atau mengubah hak akses. Simpan minimal satu akun Super Admin aktif sebelum akun lain dibuat; sistem menolak upaya menonaktifkan atau menghapus akun Super Admin terakhir yang masih aktif, dan menolak perubahan peran/status pada akun Super Admin oleh peran lain.

5. Logika koneksi berada di `config/database.php` dan dibaca dari `.env`, sehingga perubahan kredensial tidak memerlukan penyuntingan banyak berkas.

### Migration yang tersedia

| Berkas | Isi |
| --- | --- |
| `001_create_users_table.sql` | Tabel `users` awal (nama pengguna, nama, surel, kata sandi, peran kolom lama) |
| `002_create_settings_table.sql` | Tabel `settings` dan pengaturan bawaan |
| `003_create_activity_logs_table.sql` | Tabel `activity_logs` lama |
| `004_create_login_attempts_table.sql` | Tabel `login_attempts` untuk pembatasan percobaan masuk |
| `005_create_rbac_and_extend_users.sql` | Tabel `roles`, `permissions`, `role_permissions`, `user_activity_logs`, `password_resets`; perluasan `users` (`full_name`, `password`, `phone`, `photo`, `role_id`, `status`, `last_login`); kunci asing, indeks, dan seeded 11 peran + izin awal |
| `006_create_projects_table.sql` | Tabel `projects` (kode unik, lokasi + koordinat, penanggung jawab, kontraktor, tanggal, anggaran, progres 0–100, status, arsip) dan `project_status_logs` (riwayat perubahan status beserta alasan dan pengubahnya) |
| `007_create_project_progress_reports.sql` | Tabel `project_progress_reports`: setiap laporan menyimpan progres sebelum dan sesudah, deskripsi pekerjaan, pekerjaan selesai, kendala, rencana berikutnya, penanggung jawab, dan pelapor |
| `008_create_project_documents_table.sql` | Tabel `project_documents`: metadata berkas (nama asli, nama penyimpanan acak, MIME hasil deteksi, ukuran, kategori, pengunggah) tanpa menyimpan isi berkas |
| `009_create_project_team_and_notifications.sql` | Tabel `project_team_assignments` (anggota tim, jabatan, tugas, penanda penanggung jawab, riwayat pelepasan) dan `notifications` (notifikasi internal dengan penanda sudah dibaca dan kunci deduplikasi) |
| `010_extend_permissions_for_projects.sql` | Menambah izin modul proyek (`projects.archive`, `projects.status.update`, `projects.progress.update`, `projects.documents.view/upload/delete`, `projects.team.manage`) beserta grants per peran sehingga totalnya 33 izin |
| `011_create_budget_rab_tables.sql` | Tabel `budget_categories` (12 kategori pekerjaan bawaan, kode unik, penanda aktif), `budget_plans` (RAB: kode unik `RAB-YYYY-NNN`, periode, status `draft/pending/approved/rejected/revised`, versi dan `revision_of`, pencatat pengaju/pemeriksa, CHECK total ≥ 0 dan periode logis), `budget_items` (kode item unik per RAB, volume/harga/total dengan CHECK ≥ 0, kunci RESTRICT ke kategori), `budget_approvals` (jejak submit/approve/reject/revise beserta catatan), dan `budget_revisions` (perubahan anggaran beralasan beserta keputusan) |
| `012_create_financial_transactions.sql` | Tabel `financial_transactions` (kode `TRX-YYYY-NNN`, jenis `expense/income/refund/adjustment`, `cost_center` pembangunan atau operasional, kunci ke proyek/RAB/item/kategori, `reversal_of` untuk pembalikan, pembatalan beralasan, CHECK nominal > 0) dan `transaction_approvals` (riwayat submit/approve/reject/cancel per transaksi) |
| `013_create_project_schedules.sql` | Tabel `project_schedules` (kode `KGT-NNN`, tanggal mulai/selesai dengan CHECK urutan tanggal, durasi, penanggung jawab, prioritas, status, progres, penghapusan lunak lewat `is_deleted`/`deleted_at`), `schedule_dependencies` (ketergantungan FS/SS/FF/SF anti-diri-sendiri dan anti-melingkar), dan `schedule_histories` (nilai sebelum, nilai sesudah, alasan, pengubah) |
| `014_extend_permissions_for_finance.sql` | Menambah 18 izin modul keuangan (`budgets.*`, `transactions.*`, `schedules.*`) beserta grants per peran sehingga totalnya 49 izin, plus tiga kunci pengaturan: ambang peringatan anggaran, kebijakan pemisahan tugas, dan jangka pengingat jadwal |

Migration 005 dan 014 sengaja **memperpanjang** skema tahap sebelumnya (bukan mengganti atau menduplikasi tabel). Migration lama tetap tercatat pada tabel `migrations` agar berkas bernomor baru cukup ditambahkan tanpa menulis ulang riwayat. Tabel modul proyek (006–009) memakai `users` dan `projects` yang sudah ada, dan tabel keuangan/jadwal (011–013) memakai kembali `projects`, `users`, `project_documents`, `notifications`, serta `user_activity_logs` — tidak ada tabel pengguna, audit, dokumen, atau notifikasi yang diduplikasi. Bukti transaksi disimpan pada tabel `project_documents` yang sudah ada melalui kolom tambahan `financial_transaction_id`.

### Migration tambahan

Jangan membuat tabel duplikat untuk kebutuhan baru. Tambahkan berkas bernomor pada `database/migrations/`, misalnya `015_create_schools_table.sql`, lalu jalankan kembali `database/install.php`. Perubahan skema tercatat pada tabel `migrations`.

`database/schema.sql` hanya merangkum fondasi (migration 001–005) supaya struktur awalnya mudah dibaca. Tabel modul proyek (006–010) dan tabel keuangan/jadwal (011–014) tidak disalin ke sana — sumber kebenaran penerapan tetap berkas migration bernomor yang dijalankan `database/install.php`, dan daftar tabel akhirnya dapat dibaca langsung dari basis data lewat `SHOW TABLES` atau dari berkas migration masing-masing.

---

## 4. Menjalankan aplikasi di localhost

### Opsi A — XAMPP Apache

1. Tempatkan folder proyek di `htdocs`, misalnya `D:\PROGRAM\XAMPP\htdocs\SPPG-Cangkring`.
2. Pastikan Apache dan MySQL berjalan dari XAMPP Control Panel.
3. Pastikan konfigurasi Apache mengizinkan `.htaccess`:

   ```apache
   <Directory "D:/PROGRAM/XAMPP/htdocs">
       AllowOverride All
       Require all granted
   </Directory>
   ```

   Karena folder proyek diakses di bawah `htdocs`, baris `RewriteBase /SPPG-Cangkring/` pada `.htaccess` di root proyek harus sama dengan nama folder proyek. Sesuaikan nilainya bila folder diganti namanya atau dipindah.

4. Buka salah satu alamat berikut:

   - `http://localhost/SPPG-Cangkring/` — diarahkan otomatis ke folder `public/`
   - `http://localhost/SPPG-Cangkring/public/`

   Basis URL terdeteksi otomatis dari lokasi skrip. Bila memakai virtual host atau subdomain, isi `APP_URL` pada `.env`, misalnya `APP_URL=http://sppg-cangkring.test`.

### Opsi B — PHP built-in server

Dari folder proyek:

```bash
D:\PROGRAM\XAMPP\php\php.exe -S 127.0.0.1:8099 -t public public/router.php
```

Buka `http://127.0.0.1:8099`. Opsi ini hanya untuk pengembangan lokal; gunakan Apache untuk lingkungan kerja sesungguhnya.

### Konfigurasi lain

| Variabel | Fungsi |
| --- | --- |
| `APP_NAME` | Nama aplikasi awal (dapat diubah dari halaman Pengaturan) |
| `APP_ENV` | `local` atau `production` |
| `APP_DEBUG` | Tampilkan detail galat. Gunakan `false` di produksi |
| `APP_URL` | Basis URL eksplisit; kosongkan untuk deteksi otomatis |
| `APP_TIMEZONE` | Zona waktu awal; dapat diganti dari Pengaturan (WIB/WITA/WIT) |
| `SESSION_NAME` | Nama cookie sesi |
| `SESSION_LIFETIME` | Batas idle sesi dalam menit |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_WINDOW_MINUTES` | Batas percobaan masuk gagal per akun + IP dalam jendela waktu tertentu |
| `UPLOAD_MAX_MB` | Batas ukuran unggahan dokumen proyek dalam MB (bawaan 10, dikunci maksimal 25). Batas PHP `upload_max_filesize`/`post_max_size` tetap berlaku di atasnya |

---

## 5. Autentikasi, peran, dan hak akses

### 5.1 Cara masuk

1. Buka `http://localhost/SPPG-Cangkring/public/` (atau `http://127.0.0.1:8099` bila memakai PHP built-in server). Halaman `/` otomatis diarahkan ke `/login` bila belum ada sesi.
2. Isi **Nama pengguna atau surel** dengan salah satu keduanya, lalu kata sandi. Kolom tersebut menerima `admin` maupun alamat surel akun yang sama.
3. Setelah kredensial benar, sesi dibuat ulang (`session_regenerate_id(true)`), kolom `last_login` dan `last_login_ip` diperbarui, dan pengguna diarahkan ke halaman awal sesuai haknya:
   - peran **Admin** yang memegang `users.view` → `/pengguna`;
   - seluruh peran lain → `/dashboard`.
4. Keluar dilakukan melalui tombol **Keluar** di navbar (POST `/logout` dengan token CSRF), bukan tautan GET.
5. Akun dengan `status = inactive`, atau akun yang perannya sedang dinonaktifkan, **tidak dapat masuk**. Pesan galatnya tetap generik: "Nama pengguna atau kata sandi tidak sesuai." agar halaman login tidak membocorkan mana surel/username yang terdaftar.
6. Percobaan masuk gagal dicatat pada tabel `login_attempts` dan dibatasi per kombinasi akun + IP dalam jendela waktu `LOGIN_WINDOW_MINUTES` (`LOGIN_MAX_ATTEMPTS`). Saat melewati batas, permintaan berikutnya tetap menolak dengan pesan generik sampai jendela waktu berlalu.

Kata sandi disimpan memakai `password_hash()` (bcrypt) dan diperiksa dengan `password_verify()`; tidak ada kolom yang menyimpan kata sandi teks asli.

### 5.2 Peran bawaan

Sebelas peran diisi otomatis oleh installer. Halaman **Hak Akses** mengatur izin lewat tabel `role_permissions`, sehingga menambah atau mencabut akses cukup dengan mencentang izin — tanpa mengubah kode. Pengecualian yang memang disengaja dan ditegakkan server: peran `super_admin` selalu memegang seluruh izin, dan hanya `super_admin` yang boleh memegang `permissions.manage`; kedua pengecualian itu memeriksa `role_slug`, bukan daftar izin.

| Slug peran | Nama tampilan | Izin yang aktif sekarang (dibaca dari `role_permissions`) |
| --- | --- | --- |
| `super_admin` | Super Admin | Seluruh 49 izin; satu-satunya peran yang boleh memegang `permissions.manage` |
| `kepala_sppg` | Kepala SPPG | `dashboard.view`, `projects.view`, `projects.documents.view`, `projects.team.manage`, `budgets.view/approve/export/revision`, `transactions.view/approve/cancel/export`, `schedules.view/export`, `schools.view`, `menus.view`, `inventory.view`, `production.view`, `reports.view/export`, `users.view`, `audit.view` — **pemeriksa**: menyetujui RAB, transaksi, dan perubahan anggaran, tetapi tidak mencatatnya |
| `manajer_koordinator` | Manajer/Koordinator | `dashboard.view`, delapan izin `projects.*` (view, update, status.update, progress.update, archive, team.manage, documents.view/upload), `budgets.view/create/update/manage/revision/export`, `transactions.view/create/update/export`, `schedules.view/create/update/export`, `schools.view`, `menus.view`, `inventory.view`, `production.view`, `quality_control.manage`, `distribution.manage`, `reports.view` — pencatat RAB, transaksi, dan jadwal; tidak dapat menyetujui miliknya sendiri maupun milik orang lain |
| `staf_keuangan` | Staf Keuangan | `dashboard.view`, `projects.view`, `budgets.view/create/update/manage/revision/export`, `transactions.view/create/update/cancel/export`, `schedules.view`, `reports.view/export` — mengelola RAB dan kategori, mencatat serta membatalkan transaksi, mengekspor; keputusan akhir tetap di tangan pemeriksa |
| `pengawas_pembangunan` | Pengawas Pembangunan | `dashboard.view`, `projects.view/create/update/progress.update/documents.view/upload`, `budgets.view`, `transactions.view`, `schedules.view/update/export`, `reports.view` — mencatat progres & mengoreksi jadwal, tidak mengubah status resmi, mengarsipkan, atau mengelola tim |
| `staf_gudang` | Staf Gudang | `dashboard.view`, `inventory.view/manage`, `reports.view` |
| `ahli_gizi` | Ahli Gizi | `dashboard.view`, `menus.view/manage`, `reports.view` |
| `staf_produksi` | Staf Produksi | `dashboard.view`, `menus.view`, `inventory.view`, `production.view/manage` |
| `quality_control` | Petugas Quality Control | `dashboard.view`, `production.view`, `quality_control.manage`, `reports.view` |
| `petugas_distribusi` | Petugas Distribusi | `dashboard.view`, `distribution.manage`, `reports.view` |
| `admin` | Admin | `dashboard.view`, `users.view/create/update`, `projects.view`, `projects.documents.view`, `budgets.view`, `transactions.view`, `schedules.view`, `schools.manage`, `settings.manage`, `reports.view`, `audit.view` |

Daftar di atas dibaca langsung dari tabel `role_permissions`, bukan dari kode, dan dapat diubah sendiri oleh Super Admin lewat halaman **Hak Akses**. `dashboard.view` dipegang seluruh peran supaya setiap akun punya halaman awal; kartu dan grafik proyek di dashboard tetap disembunyikan bila izin `projects.view` tidak ada. Struktur grants keuangan inilah yang menegakkan pemisahan tugas secara diam-diam: peran pencatat (`manajer_koordinator`, `staf_keuangan`) memegang `*.create`/`*.update` sementara keputusan (`budgets.approve`, `transactions.approve`) hanya di `kepala_sppg` — sehingga tidak ada satu peran pun yang mengajukan dan menyetujui pada saat yang sama.

Peran tanpa izin apa pun tetap boleh ada (misalnya peran baru yang belum ditandai izin); pemegangnya hanya dapat masuk ke `/dashboard` dan `/profil`.

### 5.3 Daftar izin

Empat puluh sembilan izin dikelompokkan menurut modul. Kolom "Rute yang memakai izin ini" menandai izin yang sudah punya titik masuk nyata; sisanya sengaja disiapkan agar modul Operasional dan Laporan tinggal menempelkan rutenya pada tahap berikutnya.

| Modul | Izin | Rute yang memakai izin ini |
| --- | --- | --- |
| `dashboard` | `dashboard.view` | `/dashboard` |
| `users` | `users.view`, `users.create`, `users.update`, `users.delete` | `/pengguna*` |
| `permissions` | `permissions.manage` | `/hak-akses*` |
| `settings` | `settings.manage` | `/pengaturan` |
| `audit` | `audit.view` | `/aktivitas` |
| `projects` | `projects.view` | `/proyek`, `/proyek/{id}` |
| `projects` | `projects.create` | `/proyek/tambah` (GET + POST) |
| `projects` | `projects.update` | `/proyek/{id}/ubah` (GET + POST) |
| `projects` | `projects.status.update` | `POST /proyek/{id}/status` |
| `projects` | `projects.progress.update` | `POST /proyek/{id}/progres` |
| `projects` | `projects.archive` | `POST /proyek/{id}/arsip` (arsipkan dan pulihkan) |
| `projects` | `projects.documents.view` | `/proyek/{id}` tab dokumen, `/dokumen/{id}/unduh`, `/dokumen/{id}/tampilan` |
| `projects` | `projects.documents.upload` | `POST /proyek/{id}/dokumen` |
| `projects` | `projects.documents.delete` | `POST /dokumen/{id}/hapus` |
| `projects` | `projects.team.manage` | `POST /proyek/{id}/tim*` (tambah, ubah penugasan, lepas anggota) |
| `projects` | `projects.delete` | **Belum dipakai** — proyek sengaja tidak dapat dihapus; riwayat pembangunan dijaga dengan pengarsipan. Izin ini tetap terdaftar untuk tahap berikutnya |
| `budgets` | `budgets.view` | `/rab`, `/rab/{id}`, `/kategori`, `/anggaran`, `/anggaran/{id}`, `/anggaran/perubahan/{id}`, `/keuangan` |
| `budgets` | `budgets.create` | `/rab/tambah` (GET + POST), `POST /rab/{id}/impor` |
| `budgets` | `budgets.update` | `/rab/{id}/ubah` (GET + POST), `POST /rab/{id}/hapus`, `POST /rab/{plan}/item`, `POST /item/{id}/ubah`, `POST /item/{id}/duplikat`, `POST /item/{id}/hapus` |
| `budgets` | `budgets.approve` | `POST /rab/{id}/keputusan`, `POST /anggaran/perubahan/{id}/keputusan` |
| `budgets` | `budgets.revision` | `POST /rab/{id}/ajukan`, `POST /rab/{id}/revisi`, `POST /anggaran/{project}/perubahan` |
| `budgets` | `budgets.export` | `/rab/{id}/ekspor`, `/anggaran/ekspor` |
| `budgets` | `budgets.manage` | `POST /kategori/tambah`, `POST /kategori/{id}/ubah`, `POST /kategori/{id}/hapus` (kelola kategori pekerjaan) |
| `transactions` | `transactions.view` | `/transaksi`, `/transaksi/{id}`, `/bukti/{id}` (tampilan bukti), `/keuangan` |
| `transactions` | `transactions.create` | `/transaksi/tambah` (GET + POST), `POST /transaksi/{id}/bukti`, `POST /transaksi/{id}/ajukan` |
| `transactions` | `transactions.update` | `/transaksi/{id}/ubah` (GET + POST), `POST /transaksi/{id}/hapus` (hanya draf), `POST /bukti/{id}/hapus` |
| `transactions` | `transactions.approve` | `POST /transaksi/{id}/keputusan` |
| `transactions` | `transactions.cancel` | `POST /transaksi/{id}/batalkan` (pembalikan beralasan, bukan hapus) |
| `transactions` | `transactions.export` | `/transaksi/ekspor`, `/transaksi/{id}/cetak` |
| `schedules` | `schedules.view` | `/jadwal`, `/jadwal/{id}`, `/jadwal/kalender`, `/jadwal/gantt`, `/keuangan` |
| `schedules` | `schedules.create` | `/jadwal/tambah` (GET + POST), `POST /jadwal/{id}/ketergantungan` |
| `schedules` | `schedules.update` | `/jadwal/{id}/ubah` (GET + POST) |
| `schedules` | `schedules.delete` | `POST /jadwal/{id}/hapus` (bawaan hanya Super Admin; penghapusan lunak) |
| `schedules` | `schedules.export` | `/jadwal/ekspor` |
| `schools` | `schools.view`, `schools.manage` | Belum (data sekolah penerima manfaat) |
| `menus` | `menus.view`, `menus.manage` | Belum (perencanaan menu & gizi) |
| `inventory` | `inventory.view`, `inventory.manage` | Belum (stok & bahan pangan) |
| `production` | `production.view`, `production.manage` | Belum (proses produksi) |
| `quality_control` | `quality_control.manage` | Belum (pemeriksaan mutu) |
| `distribution` | `distribution.manage` | Belum (pengiriman & konfirmasi) |
| `reports` | `reports.view`, `reports.export` | Belum (laporan & ekspor) |

### 5.4 Cara pemeriksaan izin bekerja

- Rute diberi middleware `PermissionMiddleware:slug[,slug...]`; bila pengguna tidak memiliki salah satu izin yang diminta, server membalas **403** sebelum controller dijalankan. Rute tanpa middleware izin hanya memakai `AuthMiddleware`.
- Daftar izin dibaca **setiap permintaan** dari tabel `role_permissions` (`Auth::permissions()`). Perubahan hak akses dari halaman Hak Akses langsung berlaku pada permintaan berikutnya, tanpa perlu mengubah kode atau menyusun ulang aplikasi.
- Peran `super_admin` memang menyimpan seluruh baris izin di `role_permissions`, dan `Auth::permissions()` mengembalikannya dari tabel `permissions` (bukan dari daftar panjang yang di-hardcode). Izin `permissions.manage` ditolak server bila ditempelkan ke peran lain, sehingga kendali hak akses tidak dapat direbut dari halaman Hak Akses.
- Menu sidebar hanya menampilkan tautan yang izinnya dimiliki pengguna, tetapi penyembunyian menu **bukan** mekanisme keamanan — pemeriksaan wajib terjadi di server. Uji RBAC membuktikan halaman, tombol tambah/ubah/hapus, dan titik AJAX/POST tetap ditolak lewat permintaan langsung meskipun token CSRF valid.
- Tambah izin baru untuk modul berikutnya: buat migration bernomor yang menyisipkan baris ke `permissions`, tambahkan middleware pada rute modul itu, lalu centang izinnya di halaman Hak Akses. Tidak ada enum atau `switch` nama peran yang perlu disunting.

---

## 6. Manajemen pengguna

Halaman **Pengguna** (`/pengguna`) terbuka untuk peran yang memiliki `users.view` (bawaannya Super Admin, Admin, dan Kepala SPPG).

| Kebutuhan | Cara |
| --- | --- |
| Cari | Kolom pencarian memotong nama lengkap, nama pengguna, atau surel (maks. 60 karakter, pencocokan `LOCATE`) dan diproses lewat prepared statement. |
| Saring | Dropdown **Peran** dan **Status** (aktif/nonaktif), dapat digabung dengan pencarian. |
| Bagi halaman | 10 baris per halaman dengan navigasi halaman sebelumnya/berikutnya; parameter `page` dinormalisasi ke bilangan bulat ≥ 1. |
| Tambah | `/pengguna/tambah` (`users.create`): nama lengkap, username, surel, telepon, peran, status, kata sandi + konfirmasi. Setelah berhasil, pengguna diarahkan ke halaman detail akun barunya. |
| Ubah | `/pengguna/{id}` (`users.update`): data profil dan peran. |
| Aktif/nonaktif | Tombol status pada daftar atau detail (`users.update`). Perubahan status nonaktif langsung memutus sesi akun tersebut saat permintaan berikutnya. |
| Reset kata sandi | Form **Kata sandi baru** di halaman detail (`users.update`). Hanya pemegang izin `users.update` yang dapat melakukan reset; kata sandi lama tidak ditanya dan tidak perlu diketahui, dan nilai kata sandi tidak pernah ditulis ke log. |
| Hapus | Tombol hapus dengan modal konfirmasi (`users.delete`). Baris `password_resets` milik akun ikut terhapus (FK `ON DELETE CASCADE`), sedangkan `user_activity_logs` dipertahankan dengan `user_id` bernilai NULL (`ON DELETE SET NULL`) sehingga riwayat audit tetap terbaca. |

### Aturan pengamanan akun

Seluruh aturan ini ditegakkan di server, bukan hanya disembunyikan pada tombol:

- Username unik (case-insensitive, 3–40 karakter, pola huruf kecil/angka/titik/garis bawah/tanda hubung) dan surel unik serta valid.
- Kata sandi mengikuti kebijakan profil: 8–72 karakter, wajib memuat huruf dan angka, harus ada konfirmasi.
- Nomor telepon divalidasi bentuk wajar (`0812…` atau `+62812…`) dan dinormalisasi.
- Peran wajib ada di tabel `roles` dan masih aktif; memilih peran yang tidak dikenal ditolak.
- Pengguna tidak dapat mengubah peran sendiri, mengubah status sendiri dari halaman pengguna, menonaktifkan akun sendiri, atau menghapus akun yang sedang dipakai.
- Peran Super Admin hanya dapat diberikan oleh Super Admin, dan akun Super Admin hanya dapat dikelola oleh Super Admin (peran lain menerima 403).
- **Akun Super Admin aktif terakhir tidak boleh dinonaktifkan atau dihapus.** Aturan ini memeriksa jumlah akun `super_admin` yang masih aktif (`role_permissions` + `users.status`) sebelum perubahan diterapkan.
- Tabel `password_resets` sudah tersedia, tetapi alur "lupa kata sandi" belum diaktifkan karena aplikasi tidak punya pengantar surel. Reset kata sandi resmi dilakukan lewat halaman pengguna sesuai aturan di atas.

---

## 7. Log aktivitas

Halaman **Log Aktivitas** (`/aktivitas`) terbuka untuk izin `audit.view` (bawaannya Super Admin, Admin, Kepala SPPG). Tersedia pencarian bebas, penyaring modul, dan paginasi 15 baris per halaman.

| Aktivitas | Modul | Pencatat |
| --- | --- | --- |
| `auth.login` | auth | Masuk berhasil (menyertakan identitas + peran) |
| `auth.login.gagal` | auth | Masuk gagal — nama kredensial disimpan sebagai teks yang dibersihkan, **tanpa** kata sandi |
| `auth.logout` | auth | Keluar dari sesi |
| `users.create`, `users.update`, `users.role` | users | Tambah akun, ubah profil, ubah peran |
| `users.activate`, `users.deactivate` | users | Perubahan status akun |
| `users.password` | users | Reset kata sandi oleh pemegang izin (nilai sandi tidak dicatat) |
| `users.delete` | users | Penghapusan akun beserta peran sebelumnya |
| `roles.create`, `roles.update` | roles | Tambah peran, ubah nama/deskripsi/status peran |
| `roles.permissions` | roles | Penyimpanan matriks hak akses (jumlah izin yang disimpan) |
| `settings.update` | settings | Perubahan pengaturan aplikasi |
| `profile.password` | profile | Pengguna mengganti kata sandinya sendiri |
| `projects.create`, `projects.update` | projects | Tambah proyek dan ubah data proyek (nama yang diubah dicatat, tanpa nilai tersensor) |
| `projects.status` | projects | Perubahan status resmi beserta status asal dan alasan |
| `projects.archive`, `projects.unarchive` | projects | Arsipkan dan pulihkan proyek |
| `progress.create` | progress | Laporan progres: progres sebelum → sesudah, tanggal, dan pelapor |
| `documents.upload`, `documents.delete` | documents | Unggah dan hapus dokumen; yang dicatat judul serta proyeknya, bukan nama berkas acak di penyimpanan |
| `team.assign`, `team.update`, `team.unassign` | team | Penugasan, perubahan jabatan, dan pelepasan anggota tim |
| `budgets.create`, `budgets.update`, `budgets.delete` | budgets | Tambah, ubah, dan hapus RAB (hapus hanya untuk RAB draft/ditolak) |
| `budgets.submit`, `budgets.approve`, `budgets.reject`, `budgets.revise` | budgets | Pengajuan, keputusan beserta catatan alasannya, dan pembuatan versi revisi dari RAB disetujui |
| `budgets.item.create`, `budgets.item.update`, `budgets.item.duplicate`, `budgets.item.delete` | budgets | Perubahan rincian item; uraian menyebut kode item, volume, dan harga satuan |
| `budgets.category.create`, `budgets.category.update`, `budgets.category.delete` | budgets | Pengelolaan kategori pekerjaan |
| `budgets.revision.create`, `budgets.revision.approve`, `budgets.revision.reject` | budgets | Pengajuan perubahan anggaran dan keputusannya |
| `budgets.import`, `budgets.export` | budgets | Impor CSV item (jumlah baris masuk dicatat) dan unduhan ekspor |
| `transactions.create`, `transactions.update`, `transactions.delete` | transactions | Pencatatan, penyuntingan (hanya draf/ditolak), dan penghapusan draf |
| `transactions.submit`, `transactions.approve`, `transactions.reject`, `transactions.cancel` | transactions | Pengajuan, keputusan beralasan, dan pembatalan beralasan atas transaksi disetujui |
| `transactions.attachment`, `transactions.attachment.delete` | transactions | Unggah dan hapus bukti; yang dicatat nama asli berkas, bukan nama acak di penyimpanan |
| `transactions.print`, `transactions.export` | transactions | Cetak bukti dan unduhan ekspor |
| `schedules.create`, `schedules.update`, `schedules.delete` | schedules | Kegiatan baru, perubahan (uraian menyebut kolom yang berubah beserta alasannya), dan penghapusan lunak |
| `schedules.dependency.create`, `schedules.dependency.delete` | schedules | Penambahan dan pelepasan ketergantungan antar kegiatan |
| `schedules.export` | schedules | Unduhan ekspor jadwal |

Setiap baris mencatat waktu, pengguna (atau "pengguna terhapus" karena `ON DELETE SET NULL`), modul, aktivitas, uraian, alamat IP, dan user agent. Kolom `password`, token, dan cookie tidak pernah masuk ke deskripsi log; pengujian RBAC memeriksa langsung bahwa isi tabel `user_activity_logs` tidak memuat kata sandi uji.

---

## 8. Dashboard dan manajemen proyek pembangunan

Seluruh angka pada modul ini dibaca dari MySQL melalui model, bukan dari data tempelan. Metrik yang modul sumbernya memang belum dibangun — enam metrik operasional MBG — ditampilkan apa adanya sebagai **Belum tersedia**, sedangkan realisasi, sisa, dan jadwal sudah terisi oleh modul Prompt 4.

### 8.1 Dashboard (`/dashboard`, izin `dashboard.view`)

**Kartu ringkasan pembangunan:** Total proyek, Sedang berjalan (dengan keterangan jumlah proyek tahap persiapan), Selesai (dengan keterangan jumlah proyek dibatalkan), Tertunda (gabungan status `delayed` dan proyek yang melewati `target_date`), Rata-rata progres, dan Total anggaran pembangunan. Dua kartu berikutnya, **Realisasi anggaran** dan **Sisa anggaran**, dibaca dari `BudgetSummary::globalOverview('construction')` — angka transaksi pembangunan yang benar-benar disetujui, dengan keterangan acuan ("total RAB disetujui" atau "anggaran awal proyek") dan jumlah transaksi yang mendasarinya. Tanpa `budgets.view` kedua kartu itu, termasuk Total anggaran pembangunan, menampilkan **Dibatasi izin**. Tiga kartu sistem selalu tampil: Pengguna aktif, Aktivitas hari ini, dan Notifikasi belum dibaca.

Proyek terarsip tidak dihitung pada kartu, grafik, maupun daftar — sama seperti pada halaman proyek.

**Rentang waktu grafik:** penyaring Hari ini / Minggu ini / Bulan ini / Tahun ini / rentang tanggal custom (`?period=…&from=…&to=…`), bawaan Bulan ini. Periode tidak dikenal dinormalisasi di server, tanggal custom divalidasi formatnya, dan `from > to` dibalik, sehingga rentang yang dipakai grafik selalu sah.

**Grafik Chart.js** (5 grafik, seluruh datanya berasal dari query):

| Grafik | Sumber data |
| --- | --- |
| Tren rata-rata progres per bulan | `project_progress_reports.report_date` + `progress_after` |
| Progres tertinggi antar proyek | `projects.progress` (8 proyek teratas) |
| Status proyek pembangunan | distribusi `projects.status` |
| Anggaran proyek terpilih | `projects.budget_amount` untuk proyek dengan target selesai pada rentang |
| Aktivitas pembangunan per hari | `user_activity_logs` modul `projects`, `progress`, `documents`, `team` |

Grafik pembangunan hanya dirender bila pengguna memegang `projects.view`, dan grafik anggaran hanya disisipkan bila memegang `budgets.view`. Data dikirim ke peramban melalui atribut `data-chart-*` yang dikodekan dengan `json_attr()` (JSON lalu `htmlspecialchars`), bukan skrip inline, sehingga CSP `script-src 'self'` tetap berlaku.

**Jadwal terdekat dan proyek terlambat** — lima proyek dengan target selesai terdekat dan lima proyek yang lewat target namun belum Selesai/Dibatalkan, keduanya ditaut ke halaman detail.

**Metrik operasional MBG** — Sekolah terdaftar, Jumlah menu, Stok bahan pangan, Status produksi, dan Status distribusi bertanda **Belum tersedia**, Total penerima manfaat bertanda **Belum ada data**, dan **Pengeluaran operasional** sudah berupa rupiah hasil `BudgetSummary::globalOverview('operations')` (pemegang tanpa `budgets.view` melihat **Dibatasi izin**). Keenam nilai selain pengeluaran baru dapat diisi pada Prompt 5.

**Aktivitas terbaru** — 10 baris terakhir `user_activity_logs`, khusus pemegang `audit.view`; paginasi penuh (15 baris per halaman, pencarian, penyaring modul) tetap berada di `/aktivitas`.

**Status modul** — Autentikasi & RBAC, Pembangunan, Keuangan, dan Jadwal bertanda Aktif; Operasional dan Laporan bertanda Belum tersedia.

### 8.2 Daftar, formulir, dan status proyek

Halaman **Proyek Pembangunan** (`/proyek`, izin `projects.view`) menampilkan kolom Kode, Nama, Lokasi, Penanggung jawab, Tanggal mulai, Target selesai, Anggaran, Progres, Status, dan Aksi.

- **Penyaring:** pencarian bebas (kode, nama, lokasi, penanggung jawab, kontraktor), status, rentang tanggal, dan mode arsip (`archived=1` hanya arsip, `archived=all` termasuk arsip).
- **Penyortetan:** `code`, `name`, `location`, `pic`, `start`, `target`, `budget`, `progress`, `status` dengan arah `asc`/`desc` (kunci tidak dikenal jatuh ke target selesai). Kolom Anggaran hanya muncul bagi pemegang `budgets.view`.
- **Paginasi:** 10 baris per halaman; nilai `page`, `sort`, `dir`, dan status disanitasi sebelum masuk SQL, dan seluruh filter dieksekusi sebagai prepared statement.

**Kode proyek otomatis** mengikuti format `PRK-{tahun}-{urut}` (misalnya `PRK-2026-001`) dan masih dapat diganti manual. Aturan validasi yang berjalan di server:

| Aturan | Perlakuan |
| --- | --- |
| Kode 3–30 karakter, diawali huruf kapital, hanya `A-Z 0-9 . _ -` | Ditolak dengan pesan penjelasan; kode juga dilindungi `UNIQUE` di database |
| Kode duplikat | "Kode proyek sudah dipakai proyek lain." |
| Nama 3–150, lokasi 3–150, penanggung jawab 3–120 karakter | Ditolak di luar rentang |
| Lintang `-90…90`, bujur `-180…180` | Opsional; nilai di luar rentang ditolak |
| Target selesai lebih awal dari tanggal mulai | "Target selesai tidak boleh lebih awal dari tanggal mulai." |
| Anggaran | Angka tanpa pemisah, maksimal 13 digit + 2 desimal |
| `pic_user_id` | Harus akun aktif yang terdaftar pada `users` |
| Status | Harus salah satu dari enam status bawaan |
| Deskripsi & catatan | Maksimal 2000 karakter; alamat 1000; kontraktor 150 |

**Enam status:** Perencanaan (`planning`), Persiapan (`preparation`), Sedang Berjalan (`running`), Tertunda (`delayed`), Selesai (`completed`), Dibatalkan (`cancelled`). Perubahan status lewat tab Status (izin `projects.status.update`) maupun lewat formulir sunting (izin `projects.update`) selalu: menolak status yang sama dengan status saat ini, **wajib alasan** bila berpindah ke Tertunda atau Dibatalkan, menulis baris `project_status_logs` (status asal, status baru, alasan, pengguna, waktu), dan mencatat `projects.status` pada log aktivitas. Tab **Riwayat perubahan** menampilkan jejak tersebut.

**Pengarsipan** memakai izin `projects.archive` (`POST /proyek/{id}/arsip`). Proyek terarsip hilang dari daftar dan ringkasan dashboard serta terkunci dari perubahan data, perubahan status, laporan progres, unggah dokumen, dan perubahan tim sampai dipulihkan. Modul ini sengaja tidak menyediakan hapus permanen — tidak ada rute penghapusan proyek — sehingga riwayat progres, dokumen, dan status tidak hilang karena satu klik.

### 8.3 Halaman detail (`/proyek/{id}`)

Sebelas tab dengan anchor yang bisa ditaut langsung: Informasi umum, Lokasi, Penanggung jawab, Anggaran, Progres, Status, Jadwal kegiatan, Riwayat perubahan, Dokumentasi, Catatan lapangan, dan Tim. Tab **Anggaran** tetap ada bagi semua pemegang `projects.view`, tetapi isi angkanya tidak dikirim ke keluaran HTML bagi peran tanpa `budgets.view` — kartu ringkas dan tab menampilkan **Dibatasi izin** beserta penjelasan izin yang kurang. Ketika terbuka, tab memuat anggaran awal, total RAB disetujui, realisasi pembangunan, pemasukan, sisa beserta indikator warnanya, penyerapan terhadap ambang peringatan, dan **realisasi operasional MBG yang dilaporkan terpisah** dengan catatan bahwa biaya operasional tidak mengurangi anggaran pembangunan; ada tautan ke halaman perubahan anggaran per proyek. Tab **Jadwal kegiatan** menampilkan kegiatan proyek ini beserta kemajuannya dan menautkan langsung ke kalender serta bagan Gantt yang sudah tersaring ke proyek yang sama; peran tanpa `schedules.view` hanya melihat keterangan pembatasnya, dan proyek tanpa kegiatan menampilkan keadaan kosong "Belum ada kegiatan".

### 8.4 Monitoring progres (`POST /proyek/{id}/progres`, izin `projects.progress.update`)

Satu laporan progres memuat persentase, tanggal pelaporan, deskripsi pekerjaan, pekerjaan selesai, kendala lapangan, rencana berikutnya, penanggung jawab, dan opsional satu foto. Aturan yang ditegakkan server:

- Persentase harus bilangan bulat **0–100**; angka 105 atau `45%` ditolak.
- Tanggal pelaporan wajib sah dan **tidak boleh di masa depan**.
- Deskripsi pekerjaan **wajib** 5–2000 karakter — karena itu tidak ada perubahan progres tanpa catatan aktivitas.
- Fields keterangan lain maksimal 2000 karakter; penanggung jawab 3–120 karakter (bawaan nama penanggung jawab proyek).
- Laporan menyimpan `progress_before` dan `progress_after`, lalu kolom `projects.progress` hanya ikut diperbarui bila nilainya benar-benar berubah.
- Setiap laporan menulis `progress.create` pada log aktivitas (progres sebelum → sesudah, tanggal, pelapor) dan memicu notifikasi ke pemegang `projects.view`, penanggung jawab, pembuat proyek, dan tim aktif.
- **Progres 100% tidak mengubah status menjadi Selesai.** Sistem hanya menampilkan toast informasi bahwa status harus diubah terpisah oleh pemegang `projects.status.update`.

Tab Progres menampilkan progress bar, grafik progres terhadap waktu (satu titik per tanggal laporan), kartu **Target dan aktual** yang membandingkan progres fisik dengan persen waktu berjalan — lengkap dengan selisih poin, atau tanda `—` bila tanggal proyek belum lengkap — dan riwayat 30 laporan terakhir beserta jumlah seluruhnya.

### 8.5 Dokumentasi dan unggah berkas

Kategori dokumen: `initial` (kondisi awal), `construction` (proses pembangunan), `result` (hasil), `supporting` (dokumen pendukung), `minutes` (berita acara), `contract` (kontrak).

- **Validasi ganda:** ekstensi harus salah satu `jpg`, `jpeg`, `png`, `webp`, `pdf`, **dan** MIME hasil `finfo` harus cocok dengan daftar putih, **dan** MIME harus cocok dengan ekstensinya. Berkas gambar juga diverifikasi dengan `getimagesize()` sehingga file berpura-pura gambar tetap tertolak.
- **Ukuran:** dibatasi `UPLOAD_MAX_MB` (bawaan 10 MB, dipaksa 1–25 MB oleh kode) dan tetap diperiksa terhadap `upload_max_filesize`/`post_max_size` server.
- **Nama berkas** diganti `bin2hex(random_bytes(20)) + .ekstensi`; nama asli hanya disimpan sebagai metadata, sehingga tidak ada path atau skrip yang bisa diselundupkan lewat nama berkas.
- **Penyimpanan non-public:** berkas ditulis di `storage/uploads/projects/` (`STORAGE_PATH`), di luar `public/`. Akses hanya lewat `GET /dokumen/{id}/tampilan` dan `GET /dokumen/{id}/unduh` (izin `projects.documents.view`) yang memvalidasi nama berkas dengan pola heksadesimal ketat, mengurung `realpath` ke folder unggahan, lalu mengirim `Content-Type` sesuai MIME tersimpan dengan header `nosniff` dan `Cache-Control: private, no-store`.
- **Izin:** unggah `projects.documents.upload`, lihat `projects.documents.view`, hapus `projects.documents.delete`. Penghapusan berkas fisik dilakukan setelah baris datanya dihapus, dan keduanya tercatat sebagai `documents.upload` / `documents.delete` pada log aktivitas.

Metadata yang tersimpan: proyek, kategori, judul, nama asli, nama tersimpan, MIME, ukuran, keterangan, pengunggah, dan waktu unggah. Foto laporan progres otomatis masuk kategori `construction` dan ditautkan ke baris progresnya melalui `progress_report_id`.

### 8.6 Tim proyek (`projects.team.manage`)

Anggota tim selalu merujuk `user_id` ke tabel `users` yang sudah ada — tidak ada pembuatan akun duplikat atau kolom nama bebas sebagai identitas anggota. Yang bisa dilakukan: menjadikan salah satu pengguna terdaftar aktif sebagai penanggung jawab proyek, menambah anggota dengan jabatan/tugas, mengubah jabatan dan uraian tugas, menandai pemimpin tim, serta melepas penugasan.

- Penugasan memakai model **riwayat**, bukan timbang: melepas anggota tidak menghapus barisnya, sehingga tab Riwayat penugasan tetap menunjukkan siapa bertugas kapan.
- Anggota yang sama tidak bisa ditugaskan dua kali sebagai tim aktif pada satu proyek.
- Penanggung jawab proyek tidak boleh dilepas atau kehilangan tanda pemimpin sebelum penanggung jawab baru ditetapkan, agar setiap proyek selalu punya penanggung jawab jelas.
- Pilihan anggota dibatasi akun berstatus aktif dengan peran aktif; nilai `user_id` palsu atau akun nonaktif ditolak server.
- Setiap aksi menulis `team.assign`, `team.update`, atau `team.unassign` pada log aktivitas.

### 8.7 Notifikasi internal (`/notifikasi`)

Notifikasi dibangkitkan oleh kejadian nyata di aplikasi: proyek baru, progres diperbarui, status berubah, dokumen baru, proyek mendekati batas waktu, dan proyek terlambat. Dua jenis terakhir dibuat saat dashboard dimuat (`Notification::generateDeadlineReminders()`) dan diberi kunci deduplikasi per minggu/per target, sehingga membuka dashboard berulang tidak membanjiri kotak masuk.

Penerimanya adalah gabungan pemegang `projects.view`, penanggung jawab proyek, pembuat proyek, dan anggota tim aktif, dikurangi pengguna yang melakukan aksi. Kombinasi `(user_id, type, dedup_key)` unik di database sehingga satu kejadian tidak menghasilkan duplikat. Setiap baris memuat judul, isi, waktu, tautan ke halaman detail proyek, dan penanda sudah dibaca; tersedia tombol tandai dibaca per notifikasi dan tandai semua. Tidak ada pengiriman email, webhook, atau layanan eksternal — belum ada konfigurasi layanan untuk itu.

---

## 9. Modul RAB, anggaran, transaksi, dan jadwal pembangunan

Keempat modul ini memakai kembali `projects`, `users`, `project_documents`, `notifications`, `user_activity_logs`, serta `Auth`/`PermissionMiddleware` yang sudah ada. Seluruh nilai uang dihitung di sisi server; JavaScript hanya menampilkan perkalian sementara saat formulir diisi.

### 9.1 Alur lengkap

```
proyek  →  RAB (draft)  →  ajukan  →  keputusan (setujui / tolak beralasan)
        →  anggaran acuan berubah mengikuti RAB disetujui
        →  transaksi (draft)  →  ajukan  →  persetujuan  →  realisasi & sisa
        →  dashboard pembangunan, dashboard keuangan, laporan proyek
        →  jadwal kegiatan (kalender, Gantt, ketergantungan, riwayat)
```

Notifikasi dibangkitkan pada setiap panah di atas dan masuk ke `/notifikasi` milik peran yang berhak.

### 9.2 RAB (`/rab`, izin `budgets.*`)

| Langkah | Cara |
| --- | --- |
| Buat RAB | **RAB → Tambah RAB** (`/rab/tambah`). Kode `RAB-YYYY-NNN` dikosongkan saja agar terisi otomatis dan unik; pilih proyek, isi nama, periode anggaran, dan catatan. |
| Tambah item | Pada halaman RAB, isi kode item (otomatis mengikuti kategori bila dibiarkan), uraian pekerjaan, kategori, volume, satuan, harga satuan, dan keterangan. **Total item = Volume × Harga Satuan** dihitung server; kolom **Total** hasil ekspor hanya salinan, nilai resmi tetap dari database. |
| Ubah / duplikat / hapus item | Tombol per baris (`POST /item/{id}/ubah`, `/item/{id}/duplikat`, `/item/{id}/hapus`, izin `budgets.update`). Hasil duplikasi memakai kode item baru dengan nilai sumber tersalin. |
| Cari & saring | Pencarian uraian/kode, penyaring kategori, proyek, status, dan tahun; kolom dapat disortir; daftar dipaginasi. |
| Impor / ekspor | **Ekspor CSV** mengunduh sembilan kolom (`Kode Kategori, Kategori, Kode Item, Uraian Pekerjaan, Volume, Satuan, Harga Satuan, Total, Keterangan`). Berkas hasil ekspor dapat diimpor kembali (hanya `.csv`/`.txt`); baris judul dikenali otomatis dan total dihitung ulang oleh server. |
| Ajukan & putuskan | `POST /rab/{id}/ajukan` (izin `budgets.revision`) memindahkan status *Draft → Menunggu Persetujuan*. Pemeriksa dengan `budgets.approve` memberi keputusan **Setujui** atau **Tolak** beserta catatan; pengaju, pemeriksa, dan waktu tercatat pada `budget_approvals`. |
| Revisi | RAB berstatus **Disetujui tidak dapat diubah langsung**; server menolak dengan pesan "Buat revisi lalu ajukan untuk persetujuan ulang". `POST /rab/{id}/revisi` menghasilkan versi baru (`version`, `revision_of`) lengkap dengan itemnya, dan versi lama ditandai *Direvisi*. |
| Pemisahan tugas | Selama `finance_segregation_of_duties = 1`, pengguna tidak dapat menyetujui RAB yang ia ajukan sendiri. Kebijakan dapat dimatikan di **Pengaturan → Keuangan** oleh pemegang `settings.manage`; perubahan langsung berlaku pada permintaan berikutnya. |
| Kategori pekerjaan | `/kategori` (melihat dengan `budgets.view`; tambah/ubah/nonaktifkan dengan `budgets.manage`). Dua belas kategori bawaan ikut ter-seed lewat migration 011. Kategori yang sudah dipakai item tidak dapat dihapus — penonaktifan yang ditawarkan agar riwayat tetap terbaca. |

### 9.3 Anggaran (`/anggaran`, `/anggaran/{projectId}`, izin `budgets.*`)

Setiap baris menampilkan **anggaran awal**, **anggaran disetujui** (jumlah RAB berstatus disetujui), **realisasi**, **sisa**, **persentase penyerapan**, dan **indikator** warna. Aturan bakunya:

```
Sisa          = Anggaran disetujui − Realisasi
Realisasi %   = (Realisasi ÷ Anggaran disetujui) × 100%
```

- **Acuan** memakai total RAB disetujui bila sudah ada; bila belum, anggaran awal proyek dipakai sebagai acuan sementara agar penyerapan tetap dapat dibaca sejak tahap perencanaan. Sumber acuan selalu ditampilkan (`baseline_source`), dan anggaran awal tidak pernah diubah diam-diam.
- **Indikator**: `aman` di bawah ambang, `mendekati batas` sejak ambang peringatan (bawaan 85 %, diatur lewat Pengaturan), `terlampaui` di atas 100 %, dan `tidak dapat dihitung` bila pembaginya nol.
- **Perubahan anggaran**: ajukan lewat `POST /anggaran/{project}/perubahan` dengan nominal baru dan **alasan wajib** (`budgets.revision`); pemeriksa memutuskan di `/anggaran/perubahan/{id}` (`budgets.approve`). Riwayat tersimpan pada `budget_revisions`, dan setiap persetujuan pemegang `budgets.approve` atau `transactions.approve` memicu notifikasi `budget.warning` / `budget.exceeded` saat ambang atau 100 % terlampaui.
- **Rekap per kategori** tersedia pada halaman detail proyek dan halaman anggaran; ekspor CSV memakai penyaring yang sama dengan daftar.

### 9.4 Transaksi keuangan (`/transaksi`, izin `transactions.*`)

| Aspek | Perlakuan |
| --- | --- |
| Jenis | **Pengeluaran**, **Pemasukan**, **Pengembalian dana**, dan **Penyesuaian/koreksi**. Koreksi dicatat sebagai baris penyesuaian tersendiri, bukan dengan menghapus baris lama. |
| Cost center | **Pembangunan** (`construction`) dan **Operasional MBG** (`operations`) dipisah sejak formulir; rekap, grafik, ekspor, dan kartu dashboard menghitung keduanya secara terpisah. |
| Nominal | Wajib lebih besar nol dan dibulatkan sampai dua desimal di server. Format ketikan (`1.500.000`, `1500000`) diterima; nilai tampilan hanya bantuan, angka resmi dari database. |
| Bukti | Unggah `.pdf`/`.jpg`/`.jpeg`/`.png` (`.webp` ikut diterima) lewat `POST /transaksi/{id}/bukti`. Validasi berlapis: galat PHP, ukuran (`UPLOAD_MAX_MB`, bawaan 10 MB), ekstensi, MIME hasil `finfo`, kecocokan MIME–ekstensi, dan `getimagesize()` untuk gambar. Berkas disimpan di `storage/uploads/projects/` dengan nama acak 40 karakter heksadesimal **di luar `public/`**; hanya dapat dibuka lewat `/bukti/{id}` setelah pemeriksaan `transactions.view`. |
| Status | `Draft → Menunggu → Disetujui / Ditolak`, dan `Dibatalkan` untuk pembatalan beralasan. Pengajuan memakai `transactions.create`, keputusan memakai `transactions.approve`, pembatalan memakai `transactions.cancel`. |
| Penghapusan | Hanya baris **Draft** dan **Ditolak** yang boleh dihapus. Transaksi **Disetujui tidak dapat dihapus permanen**: gunakan pembatalan (alasan wajib) supaya jejak audit dan riwayat persetujuan tetap tersimpan. |
| Realisasi | Hanya transaksi **Disetujui** yang dihitung: `pengeluaran − pengembalian + penyesuaian`. Pemasukan tidak mengurangi realisasi; ia dilaporkan sebagai sumber dana. |
| Pencegahan ganda | Kombinasi proyek + tanggal + jenis + nominal + uraian (dengan nomor referensi kosong atau sama) ditolak; nomor referensi juga tidak boleh dipakai ulang dalam satu proyek. |
| Cetak & ekspor | `/transaksi/{id}/cetak` menghasilkan bukti bertanggal untuk arsip (`transactions.export`); `/transaksi/ekspor` mengunduh daftar sesuai penyaring aktif. |

### 9.5 Jadwal pembangunan (`/jadwal`, izin `schedules.*`)

- **Formulir** (`/jadwal/tambah`, `/jadwal/{id}/ubah`): kode `KGT-NNN` otomatis per proyek bila dikosongkan, nama kegiatan, proyek, deskripsi, kategori, tanggal mulai dan selesai, durasi (inklusi: satu hari untuk kegiatan mulai = selesai), penanggung jawab (dipilih dari `users` atau ditulis), prioritas, status, persentase progres, dan catatan.
- **Kalender** (`/jadwal/kalender`, FullCalendar lokal): tampilan **Bulan / Minggu / Hari**, navigasi panah berbahasa Indonesia dengan `aria-label`, penyaring proyek dan rentang periode, klik kegiatan membuka rincian dan tautan ke halaman kegiatan.
- **Gantt** (`/jadwal/gantt`): bagan sederhana per kegiatan dengan skala waktu, batang durasi, progres, dan warna status; dapat digulir horizontal pada layar sempit tanpa melebarkan dokumen.
- **Ketergantungan** (`POST /jadwal/{id}/ketergantungan`): jenis FS, SS, FF, SF. Server menolak ketergantungan pada diri sendiri, melingkar, dan pasangan tanggal yang tidak memenuhi aturan jenisnya.
- **Riwayat** (`schedule_histories`): setiap perubahan menyimpan kolom, nilai sebelum, nilai sesudah, alasan, pengubah, dan waktu. Alasan **wajib** pada setiap suntingan yang benar-benar mengubah nilai (5–500 huruf); bila tidak ada nilai yang berubah, perubahan bukan alasan yang diminta.
- **Penghapusan**: bersifat lunak (`is_deleted`, `deleted_by`, `deleted_at`) dan hanya untuk pemegang `schedules.delete` (bawaan Super Admin); barisnya tetap ada sebagai jejak.
- **Pengingat**: `schedule.due` dan `schedule.late` dibangkitkan saat dashboard dibaca dengan jangka `schedule_due_days` (bawaan 7 hari) dan kunci deduplikasi sehingga tidak menumpuk.

### 9.6 Dashboard keuangan (`/keuangan`)

Dibuka untuk pemegang salah satu dari `budgets.view`, `transactions.view`, atau `schedules.view` (`routes/web.php:67`). Penyaringnya `?proyek=`, `?pusat=` (kosong = kedua pusat biaya, nilai bawaan), `?dari=`, dan `?sampai=`. Empat kartu teratas adalah **Anggaran disetujui**, **Realisasi**, **Sisa anggaran**, dan **Penyerapan**; lima grafik berikut dibaca dari query MySQL: *Anggaran dibandingkan realisasi*, *Pengeluaran per kategori pekerjaan*, *Tren belanja bulanan*, *Progres kegiatan per kategori*, dan *Target dibandingkan realisasi jadwal*. Di bawahnya ada daftar kerja (RAB menunggu persetujuan, transaksi menunggu persetujuan, usulan perubahan anggaran, tenggat terdekat, kegiatan lewat tenggat) serta dua tabel: *Pembangunan dan operasional MBG* dan *Status transaksi*. Bagian akhir halaman menuliskan ulang rumus yang dipakai, sehingga angka di layar dapat diperiksa sendiri.

Ketika halaman disaring ke **satu** pusat biaya (`?pusat=construction` maupun `?pusat=operations`), kartu sisa dan penyerapan **sengaja ditampilkan sebagai `—`** dengan pill "Tidak sebanding" dan kotak keterangan: nomor RAB serta anggaran awal proyek belum dipisah per pusat biaya, sehingga realisasi satu pusat tidak boleh dibandingkan terhadap total anggaran. Pemisahan angka dibaca dari tabel *Pembangunan dan operasional MBG*; tidak ada angka nol yang disajikan seolah-olah hasil perhitungan.

### 9.7 Pengaturan keuangan (`POST /pengaturan/keuangan`, izin `settings.manage`)

| Kunci | Bawaan | Fungsi |
| --- | --- | --- |
| `budget_warning_percent` | 85 | Ambang indikator "mendekati batas" pada penyerapan anggaran |
| `schedule_due_days` | 7 | Jangka hari pengingat "mendekati tenggat" kegiatan |
| `finance_segregation_of_duties` | 1 | Mewajibkan pemisah pengaju dan pemeriksa pada RAB, revisi anggaran, dan transaksi |

---

## 10. Fitur yang sudah berjalan

- **Login** dengan validasi server-side, menerima nama pengguna **atau** surel, pesan galat generik, pembatasan percobaan masuk, dan pencatatan waktu/IP masuk terakhir.
- **Logout** melalui POST dengan token CSRF.
- **Sesi** dengan regenerasi ID saat login/keluar, cookie HttpOnly + SameSite=Lax, `use_strict_mode`, dan batas menganggur sesuai `SESSION_LIFETIME`.
- **Dashboard pembangunan** dengan statistik nyata dari database: kartu ringkasan proyek (total, berjalan, selesai, tertunda, rata-rata progres, total anggaran), kartu **realisasi**, **sisa**, dan jumlah transaksi pembangunan disetujui yang dihitung dari modul keuangan, tujuh metrik operasional MBG (enam masih bertanda **Belum tersedia**, pengeluaran operasional sudah berupa rupiah), jadwal terdekat, daftar proyek terlambat, lima grafik Chart.js dari query sungguhan dengan penyaring periode, 10 aktivitas terakhir, dan status modul. Tidak ada angka fiktif.
- **Manajemen proyek** (izin `projects.view/create/update/status.update/archive`): daftar berkolom lengkap dengan pencarian, penyaring status/periode/arsip, penyortetan sembilan kolom, paginasi, formulir tambah & ubah dengan validasi server (kode unik, target ≥ mulai, koordinat, anggaran), pengarsipan/pemulihan, dan halaman detail sebelas tab.
- **Monitoring progres** (izin `projects.progress.update`): laporan berisi persentase 0–100, tanggal, deskripsi pekerjaan, pekerjaan selesai, kendala, rencana berikutnya, penanggung jawab, dan foto; disertai progress bar, grafik progres terhadap waktu, perbandingan target vs aktual, serta riwayat yang menyimpan progres sebelum dan sesudah.
- **Dokumentasi & unggah berkas** (izin `projects.documents.*`): enam kategori berkas, validasi ekstensi **dan** MIME, batas ukuran, nama berkas acak, penyimpanan non-public di `storage/uploads/projects/`, serta pratinjau/unduh lewat controller dengan izin dan header `nosniff`.
- **Tim proyek** (izin `projects.team.manage`): penugasan yang terhubung ke tabel `users`, jabatan dan uraian tugas, penanggung jawab, pelepasan penugasan, dan riwayat penugasan yang tidak hilang.
- **Notifikasi internal** (`/notifikasi`): proyek baru, progres diperbarui, status berubah, dokumen baru, mendekati batas waktu, dan terlambat — plus pengajuan RAB/revisi anggaran, keputusan RAB/revisi, pengajuan & persetujuan & pembatalan transaksi, pembuatan/perubahan kegiatan, kegiatan mendekati tenggat, dan peringatan ambang anggaran — dengan penanda sudah dibaca, paginasi, dan tautan ke halaman rinciannya.
- **RAB** (`/rab`, izin `budgets.*`): nomor otomatis per tahun (`RAB-YYYY-NNN`), formulir berisi kode, nama, proyek, periode anggaran (mulai dan selesai), dan catatan — pembuat, status, dan total dihitung server, bukan dikirim dari formulir. Rincian item memuat kode, uraian pekerjaan, kategori, volume, satuan, harga satuan, dan keterangan. Total item dan total RAB dihitung ulang di server setiap kali item berubah; JavaScript hanya menampilkan perkalian sementara saat kolom diisi. Ada pencarian, penyaring status/kategori/proyek/periode, duplikasi item, duplikasi RAB (versi turunan ikut menyalin item), impor serta ekspor CSV sembilan kolom, dan alur status Draft → Menunggu Persetujuan → Disetujui/Ditolak → Direvisi dengan jejak pengaju, pemeriksa, waktu, catatan revisi, dan alasan penolakan.
- **Kategori pekerjaan** (`/kategori`, izin `budgets.manage`): dua belas kategori bawaan dapat ditambah, diubah, dan dinonaktifkan; kategori yang sudah dipakai item tidak dapat dihapus.
- **Anggaran** (`/anggaran`, izin `budgets.*`): rekap per proyek berisi anggaran awal, total per kategori, jumlah RAB disetujui, realisasi, sisa, dan persentase penyerapan dengan indikator Aman/Mendekati batas/Terlampaui. Perubahan anggaran diajukan lewat formulir beralasan lalu diputuskan oleh pemegang `budgets.approve`; nilai awal tidak pernah diubah diam-diam, seluruhnya tercatat di riwayat.
- **Transaksi keuangan** (`/transaksi`, izin `transactions.*`): empat jenis (Pengeluaran, Pemasukan, Pengembalian dana, Koreksi/penyesuaian) terpisah per pusat biaya pembangunan dan operasional MBG, dengan validasi nominal, pencegahan pencatatan ganda, bukti PDF/JPG/JPEG/PNG, alur Draft → Menunggu → Disetujui/Ditolak, pembatalan beralasan untuk transaksi yang sudah disetujui, dan hapus yang hanya berlaku untuk draft/ditolak. Realisasi dihitung ulang di dalam transaksi database yang sama.
- **Jadwal pembangunan** (`/jadwal`, izin `schedules.*`): kegiatan berkode otomatis `KGT-NNN` dengan proyek, deskripsi, tanggal mulai/selesai, durasi, penanggung jawab, prioritas, status, persentase progres, dan catatan; ditampilkan sebagai kalender bulanan/mingguan/harian dan bagan Gantt, dapat disaring per proyek, dan mencatat riwayat setiap perubahan beserta alasan. Ketergantungan FS/SS/FF/SF ditolak bila mengarah ke diri sendiri, melingkar, atau memindahkan tanggal ke luar nilai yang sah.
- **Dashboard keuangan & jadwal** (`/keuangan`): empat kartu ringkas (anggaran disetujui, realisasi, sisa, penyerapan), lima grafik (anggaran vs realisasi, pengeluaran per kategori, tren belanja bulanan, progres kegiatan per kategori, target vs realisasi jadwal), daftar kerja yang menunggu keputusan, dan tabel pemisah pembangunan dengan operasional MBG — semuanya query MySQL dengan penyaring proyek, pusat biaya, dan periode.
- **Profil** pengguna: informasi akun dan perubahan kata sandi (verifikasi kata sandi lama, panjang 8–72 karakter, wajib huruf dan angka, konfirmasi).
- **Pengaturan** (izin `settings.manage`): nama aplikasi, nama instansi, zona waktu, surel dukungan; plus ambang peringatan anggaran, batas hari pengingat tenggat jadwal, dan sakelar pemisahan tugas keuangan — semuanya divalidasi server dan dicatat sebagai aktivitas.
- **Manajemen pengguna** (izin `users.*`): daftar, cari, saring peran/status, paginasi, tambah, ubah, ganti peran, aktif/nonaktif, reset kata sandi, hapus dengan modal konfirmasi, serta halaman detail berisi ringkasan akun dan aktivitasnya.
- **Peran & hak akses** (khusus `permissions.manage`): buat peran, ubah nama/deskripsi, aktif/nonaktifkan peran, matriks peran × izin, dan penandai izin per peran yang langsung berlaku pada permintaan berikutnya.
- **Log aktivitas** (izin `audit.view`): riwayat masuk/gagal masuk/keluar, perubahan akun, perubahan peran dan izin, perubahan pengaturan, kejadian modul proyek (proyek, progres, dokumen, tim), dan kejadian modul keuangan (RAB, item, kategori, revisi anggaran, transaksi, bukti, pembatalan, jadwal, ketergantungan) dengan pencarian, penyaring modul, dan paginasi.
- **Otorisasi berbasis izin** di server: `AuthMiddleware` dan `PermissionMiddleware` dipasang per rute. Peran tanpa izin yang membuka URL langsung menerima 403 — menu yang tersembunyi hanyalah kenyamanan tampilan, bukan penegakan izin.
- **Halaman galat** 400, 403, 404, 405, dan 500 dengan tata letak aplikasi; galat 500 menampilkan referensi kejadian dan dicatat ke `storage/logs/`. Kode 400 dipakai untuk permintaan yang tidak dapat diproses, termasuk token CSRF yang kosong, salah, atau kedaluwarsa. Kode galat lain tetap mengirim status HTTP yang benar dengan pesan berbahasa Indonesia, memakai tata letak halaman 404.
- **Keamanan**: prepared statement native (`PDO::ATTR_EMULATE_PREPARES = false`), CSRF untuk semua POST, `password_hash`/`password_verify`, `password_needs_rehash` otomatis, escaping XSS pada seluruh keluaran, header keamanan dan CSP, proteksi berkas internal.
- **Sistem tampilan** responsif: sidebar tetap di desktop, off-canvas di tablet/ponsel dengan tombol menu, backdrop, tombol Escape, dan penutupan otomatis saat lebar melewati breakpoint; notifikasi toast, modal konfirmasi, keadaan kosong, dan status muat.
- **Migration dan installer idempoten**, dengan pencatatan versi migration.

### Belum tersedia pada tahap ini

- **Modul Operasional MBG** (sekolah, penerima manfaat, menu, gizi, stok, produksi, distribusi) dan **modul Laporan**. Izin `schools.*`, `menus.*`, `inventory.*`, `production.*`, `quality_control.*`, `distribution.*`, `reports.*` sudah terdaftar tetapi belum punya rute. Transaksi pusat biaya operasional sudah dapat dicatat dan dilaporkan terpisah; yang belum tersedia adalah data sekolah, menu, stok, dan gizinya — enam metrik operasional di dashboard pembangunan karena itu masih bertanda **Belum tersedia**.
- **Anggaran awal per pusat biaya.** Tabel `projects.budget_amount` hanya menyimpan satu angka untuk seluruh proyek, dan RAB tidak membawa pemisah pembangunan/operasional. Karena itu pada `/keuangan` kartu **sisa** dan **penyerapan** menampilkan `—` setiap kali halaman disaring ke satu pusat biaya, dengan keterangan yang menjelaskan alasannya, bukan ditampilkan sebagai nol.
- **Hapus proyek permanen.** Modul ini hanya menyediakan arsip/pulih; izin `projects.delete` sudah terdaftar agar tahap berikutnya bisa menambahkannya tanpa mengubah struktur perizinan. (Transaksi dan jadwal sudah punya penghapusan sendiri: transaksi hanya untuk draft/ditolak, jadwal memakai hapus lunak.)
- **Penjadwalan otomatis pengingat batas waktu.** Pengingat proyek dibuat saat `/dashboard` dibuka, pengingat tenggat jadwal dan peringatan ambang anggaran dibuat saat `/keuangan` dibuka. Belum ada cron karena aplikasi ini berjalan di XAMPP tanpa penjadwal sistem; kunci deduplikasi membuatnya aman dijalankan berulang.
- Fitur "ingat saya" (*remember me*) — sengaja belum dibuat karena membutuhkan token persisten di peramban; instruksi tahap dua menandainya sebagai opsional.
- Pendaftaran mandiri, lupa kata sandi lewat surel, verifikasi surel, dan autentikasi dua faktor. Tabel `password_resets` sudah ada dan terisi hanya bila alur surel dibangun nanti.
- Unggah foto profil (kolom `users.photo` tersedia; infrastruktur unggahan yang sekarang dipakai khusus untuk berkas dokumen proyek dan bukti transaksi).
- Ekspor laporan gabungan ke PDF/Excel dan titik AJAX berizin khusus. Yang sudah ada baru ekspor CSV per modul (RAB, anggaran, transaksi, jadwal) dan halaman cetak satu transaksi di `/transaksi/{id}/cetak`.
- Notifikasi eksternal (email, WhatsApp, webhook) — belum ada konfigurasi layanan, jadi tidak ada satu pun pengiriman keluar.

---

## 11. Pengujian

Tujuh rangkaian uji berjalan dari baris perintah — empat menyentuh HTTP dan karenanya memerlukan server web, tiga murni CLI. Nyalakan server lokal lebih dulu (Apache atau PHP built-in server pada `127.0.0.1:8099`), lalu:

```bash
D:\PROGRAM\XAMPP\php\php.exe tests/run.php            # fondasi: database, routing, header, aset
D:\PROGRAM\XAMPP\php\php.exe tests/rbac.php           # RBAC: login, izin, akun, hak akses, audit
D:\PROGRAM\XAMPP\php\php.exe tests/projects.php       # dashboard, proyek, progres, unggahan, tim, notifikasi
D:\PROGRAM\XAMPP\php\php.exe tests/finance_models.php # model RAB/anggaran/transaksi/jadwal (tanpa server)
D:\PROGRAM\XAMPP\php\php.exe tests/finance_http.php   # seluruh alur HTTP modul keuangan & jadwal
D:\PROGRAM\XAMPP\php\php.exe tests/routes.php         # setiap rute menunjuk metode yang benar-benar ada
D:\PROGRAM\XAMPP\php\php.exe tests/controller_calls.php # pemanggilan statis di dalam controller
```

Bila pengujian diarahkan ke Apache, beritahu prefix URL-nya lebih dulu; tanpa variabel ini rangkaian uji memakai `http://127.0.0.1:8099`.

```bash
set TEST_BASE_URL=http://localhost/SPPG-Cangkring/public
```

Keempat rangkaian berbasis HTTP sudah dijalankan pada dua bentuk server: built-in PHP dan Apache XAMPP dengan dokumen akar `public/`. Hasilnya identik — 52 + 126 + 266 + 236 pemeriksaan lulus tanpa kegagalan. Rangkaian uji hanya memeriksa teks tautan sesuai prefix yang dikirim, sehingga halaman yang sama dinilai identik di kedua server.

Variabel lingkungan yang dibaca: `TEST_BASE_URL`, `ADMIN_USERNAME`, `ADMIN_PASSWORD`, dan `KEEP_TEST_DATA=1` (meninggalkan data uji agar bisa dibuka di peramban; tanpa variabel ini seluruh data uji dibersihkan di awal dan di akhir, termasuk berkas unggahannya).

`tests/run.php` (52 pemeriksaan) mencakup koneksi database, keberadaan seluruh tabel dan migration, routing dan halaman utama, header keamanan, CSRF, login/logout, otorisasi peran terbatas, pembatasan percobaan masuk, aset statis, dan halaman 404/405.

`tests/rbac.php` (126 pemeriksaan) dibagi tujuh bagian (A–G) dan memakai akun uji berawalan `rbac_` yang selalu dibersihkan: struktur database, autentikasi, penegakan izin sisi server (403 pada URL langsung dan POST), CSRF/validasi/escaping, siklus hidup akun, peran & hak akses termasuk pembuktian izin berlaku pada permintaan berikutnya tanpa mengubah kode, dan halaman audit.

`tests/projects.php` (266 pemeriksaan) dibagi sembilan bagian:

| Bagian | Isi |
| --- | --- |
| A | Skema modul proyek (tabel, kunci asing, indeks unik kode, 33 izin) dan penegakan izin dasar tiap rute proyek |
| B | CRUD proyek: tambah, daftar, cari, saring status/periode/arsip, sortasi, paginasi, validasi server (kode duplikat, target < mulai, koordinat, anggaran, akun nonaktif), penolakan POST tanpa CSRF/token salah |
| C | Monitoring progres: rentang 0–100, tanggal masa depan ditolak, deskripsi wajib, progres sebelum/sesudah tersimpan, perubahan progres tanpa laporan ditolak, 100% tidak mengubah status, unggah foto laporan tersimpan sebagai dokumen tertaut |
| D | Perubahan status: transisi sah, tolak status sama, wajib alasan untuk Tertunda/Dibatalkan, riwayat `project_status_logs` terisi, proyek terarsip terkunci |
| E | Dokumentasi & unggahan: ekstensi + MIME + kecocokan keduanya, MIME palsu ditolak, berkas non-gambar ber-ekstensi gambar ditolak, batas ukuran, nama berkas acak, akses hanya lewat controller (403 tanpa izin, 404 untuk nama di luar pola), unduh/pra-tayang benar, hapus menghapus berkas fisik |
| F | Tim proyek: penugasan dari `users` (tanpa akun duplikat), tolak akun nonaktif & penugasan ganda, ubah jabatan, pemimpin = penanggung jawab, penanggung jawab tidak bisa dilepas sebelum diganti, riwayat penugasan |
| G | Notifikasi: jenis yang muncul untuk tiap kejadian, penerima sesuai hak & keterlibatan, tidak ada notifikasi untuk pelaku sendiri, deduplikasi kunci, daftar/paginasi, tandai dibaca & baca semua (hanya punya milik sendiri), tautan ke detail proyek |
| H | Dashboard: kartu ringkasan dari data nyata, kartu realisasi & sisa dibandingkan langsung dengan angka `BudgetSummary` (bukan lagi "Belum tersedia"), enam metrik operasional MBG tetap jujur, lima grafik + data atribut yang dapat diurai JSON, lima periode (termasuk regresi "Hari ini" menghasilkan deret aktivitas), penyaringan konten tanpa `projects.view`/`budgets.view`/`audit.view` |
| I | Integrasi & keamanan keluaran: halaman Prompt 1–2 tetap normal, audit mencatat slug modul proyek, token/kata sandi tidak masuk log, hash tersimpan, CSP terkirim, aset lokal ada, tidak ada skrip inline |

`tests/finance_models.php` (130 pemeriksaan) menguji perhitungan sisi server tanpa menyentuh web server, dalam sepuluh bagian: normalisasi angka (`amount_normalize`, `volume_normalize`), item & total RAB, alur persetujuan RAB, transaksi & realisasi, pemisahan pusat biaya pembangunan/operasional, pembatalan beralasan & pencegahan duplikasi, sisa & penyerapan, rekap kategori & agregasi lintas proyek, perubahan anggaran melalui revisi, dan jadwal. Keseluruhan suite berjalan di dalam satu transaksi database yang dibatalkan pada akhir pelaksanaan, jadi aman dijalankan berulang pada basis data berisi. Nilai agregat nasional dibandingkan terhadap selisih dari keadaan awal suite, bukan terhadap angka mutlak, sehingga data nyata pengguna tidak membuat pengujian ini gagal.

`tests/finance_http.php` (236 pemeriksaan) memakai proyek uji dan **lima akun baru yang dibuat lewat formulir `/pengguna/tambah`** (bukan sisipan SQL): pencatat `manajer_koordinator`, pemeriksa `kepala_sppg`, pengamat `admin`, pengawas `pengawas_pembangunan`, bendahara `staf_keuangan` — ditambah sesi Super Admin. Sembilan belas bagiannya:

| Bagian | Isi |
| --- | --- |
| 1–2 | Proyek uji dibuat lewat formulir; akun penguji & kebijakan pemisahan tugas |
| 3 | Penegakan izin sisi server per rute keuangan & jadwal untuk tiap peran (403 pada URL langsung, POST ditolak, GET hapus ditolak, token CSRF salah/kosong ditolak) |
| 4–6 | RAB + item + perhitungan total di server (volume × harga satuan), duplikasi item, ekspor & impor CSV, alur Draft → Menunggu → Disetujui/Ditolak, penolakan menyetujui milik sendiri, RAB disetujui terkunci sampai ada revisi |
| 7–8 | Ambang peringatan diubah lewat `/pengaturan/keuangan` lalu indikator ikut berubah; pengajuan perubahan anggaran wajib alasan, keputusan tercatat, nilai awal tidak pernah berubah diam-diam |
| 9–11 | Transaksi: validasi nominal (0, negatif, bukan angka, format ribuan), pemisahan `cost_center`, pencegahan duplikasi, berkas bukti (ekstensi + MIME + ukuran + nama acak + akses lewat `/bukti/{id}`), persetujuan, pembatalan beralasan, dan penolakan hapus pada transaksi disetujui |
| 12 | Pencarian, penyaringan, dan paginasi daftar transaksi |
| 13–14 | Jadwal: kode `KGT-NNN` otomatis, kalender tiga tampilan, Gantt, validasi tanggal, ketergantungan ditolak bila mengarah diri sendiri/melingkar, riwayat perubahan beralasan |
| 15–16 | Dashboard keuangan `/keuangan` (kartu + grafik + kegiatan jatuh tempo), integrasi `/dashboard` Prompt 3, notifikasi tiap kejadian tanpa duplikat saat halaman dibuka ulang |
| 17 | **Dua pengguna bertransaksi bersamaan**: dua POST `/transaksi/tambah` paralel sama-sama tersimpan dengan nomor unik, dua pemeriksa yang menyetujui transaksi sama hanya menghasilkan satu jejak persetujuan, dan realisasi akhir persis sama dengan jumlah nominal di basis data |
| 18–19 | Penanganan galat & kerentanan dasar (404, escaping, token/kata sandi tidak masuk log) dan pembuktian izin baru langsung berlaku pada permintaan berikutnya tanpa mengubah kode |

Dua pemeriksaan pada bagian 15 mengunci integrasi Prompt 4 ke dashboard lama: kartu realisasi & sisa `/dashboard` harus sama persis dengan hasil `BudgetSummary::globalOverview()`, tidak boleh ada lagi `<p class="stat-value">Belum tersedia</p>` pada kartu keuangan, dan baris "Pengeluaran operasional" harus memuat angka pusat biaya operasional — bukan angka pembangunan.

Bagian 17 mengirim permintaannya dengan `curl_multi` sehingga benar-benar paralel di sisi klien. Catatan jujunya: `php -S` melayani permintaan satu per satu, jadi di server built-in rangkaian ini membuktikan jalur kodenya benar tetapi tidak menguji penguncian secara nyata; karena itu keempat rangkaian HTTP selalu diulang pada Apache XAMPP, yang memang melayani permintaan bersamaan, dengan hasil yang sama.

Peta dua puluh butir pengujian wajib ke tempat pelaksanaannya:

| # | Butir | Dijalankan di |
| --- | --- | --- |
| 1 | Membuat RAB | `finance_http` bag. 4 (`POST /rab/tambah` lewat formulir) |
| 2 | Menambahkan item RAB | `finance_http` bag. 4 (`POST /rab/{id}/item`) + `finance_models` "Item dan total RAB" |
| 3 | Perhitungan total RAB | `finance_http` bag. 4 & 6 (total tidak berubah saat disetujui) + `finance_models` |
| 4 | Mengajukan dan menyetujui RAB | `finance_http` bag. 6 |
| 5 | Mengajukan revisi anggaran | `finance_http` bag. 8 + `finance_models` "Perubahan anggaran melalui revisi" |
| 6 | Membuat transaksi | `finance_http` bag. 9 |
| 7 | Persetujuan transaksi | `finance_http` bag. 11 |
| 8 | Pembatalan transaksi | `finance_http` bag. 11 (wajib alasan, hapus ditolak) |
| 9 | Perhitungan realisasi | `finance_http` bag. 9, 11, 17 + `finance_models` "Transaksi keuangan dan realisasi" |
| 10 | Perhitungan sisa anggaran | `finance_http` bag. 15 baris `Sisa anggaran = anggaran disetujui dikurangi realisasi` + `finance_models` |
| 11 | Unggah bukti transaksi | `finance_http` bag. 10 |
| 12 | Membuat jadwal kegiatan | `finance_http` bag. 13 |
| 13 | Tampilan kalender | `finance_http` bag. 13 + pemeriksaan peramban (9 event, tujuh hari berbahasa Indonesia) |
| 14 | Gantt | `finance_http` bag. 13 + pemeriksaan peramban (5 batang, lima bulan) |
| 15 | Ketergantungan pekerjaan | `finance_http` bag. 14 |
| 16 | Notifikasi | `finance_http` bag. 16 |
| 17 | Hak akses tiap peran | `finance_http` bag. 3 (matriks lima peran × delapan URL + lima URL Super Admin, lengkap dengan penolakan POST) & bag. 19 |
| 18 | Audit log | `finance_http` bag. 16 (entri `budgets.*`/`transactions.*`/`schedules.*`, tanpa rahasia) + halaman `/aktivitas` pada `rbac.php` bagian G |
| 19 | Integrasi dashboard Prompt 3 | `finance_http` bag. 15 + `projects.php` bagian H |
| 20 | Responsivitas desktop & mobile | Pemeriksaan peramban Chrome headless 360/768/1366 px, 36 kombinasi halaman × viewport |

Butir tambahan "dua pengguna bertransaksi bersamaan" ada di `finance_http` bag. 17 dan dijalankan pada kedua server.

Hasil terakhir (4 November 2026) pada `http://127.0.0.1:8099` dengan MariaDB lokal:

- `tests/run.php`: **52 lulus, 0 gagal**
- `tests/rbac.php`: **126 lulus, 0 gagal**
- `tests/projects.php`: **266 lulus, 0 gagal**
- `tests/finance_models.php`: **130 lulus, 0 gagal**
- `tests/finance_http.php`: **236 lulus, 0 gagal**
- `tests/routes.php`: 96 rute terdaftar, semuanya menunjuk metode controller yang benar-benar ada dan tidak saling menutupi
- `tests/controller_calls.php`: **0 masalah**

Keempat rangkaian HTTP dijalankan ulang dengan `TEST_BASE_URL=http://localhost/SPPG-Cangkring/public` pada Apache XAMPP hari yang sama: **52 / 126 / 266 / 236 lulus, 0 gagal** — identik dengan hasil server built-in.

Pemeriksaan visual modul keuangan & jadwal dengan Chrome headless (data uji ditinggalkan lewat `KEEP_TEST_DATA=1`; RAB 16, transaksi 83, proyek 95):

- **36 kombinasi halaman × viewport, semuanya bersih** — dua belas halaman (`/keuangan`, `/keuangan?pusat=operations`, `/rab`, `/rab/16`, `/rab/16/ubah`, `/transaksi`, `/transaksi/83`, `/anggaran/95`, `/jadwal`, `/jadwal/kalender`, `/jadwal/gantt`, `/proyek/95`) dibuka pada lebar 360, 768, dan 1366 px; setiap halaman melaporkan `lebih 0px` dan nol elemen yang menembus tepi kanan.
- **Lima kanvas grafik `/keuangan` benar-benar melukis piksel** di ketiga viewport (298×230 pada ponsel, 683×270 pada tablet, 471×270 pada desktop) — dihitung dari alpha channel `getImageData`, bukan sekadar keberadaan `Chart.instances`. Ukuran yang sama berlaku untuk pusat biaya operasional.
- **Kalender terverifikasi di peramban**: `FullCalendar` aktif, 9 event muncul pada bulan berjalan, tujuh nama hari berbahasa Indonesia (Sen…Min), tombol mundur/ maju memakai `aria-label` "Ke periode sebelumnya/berikutnya" dengan panah teks (`::after content: none`), dan mengklik event membuka panel detail berisi sembilan bidang (KGT-003 — Struktur beton). **Gantt menampilkan 5 batang sepanjang lima bulan** tanpa peluapan horizontal.
- **Drawer ponsel**: sidebar berangkat dari x=−264, rata kiri (x=0) setelah tombol menu ditekan, dokumen tetap tidak meluap saat terbuka, lalu kembali ke −264 setelah backdrop ditekan. Toast pada `/rab/16/ubah` menempati 12..348 px dari 360 px (lebar penuh dikurangi margin), tidak lagi menembus layar.
- **Nol galat**: `Runtime.exceptionThrown`, `consoleAPICalled(type=error)`, dan `Network.loadingFailed` semuanya kosong pada seluruh halaman, termasuk halaman kalender yang memuat bundel FullCalendar.

Empat temuan lahir dari pemeriksaan visual ini dan sudah diperbaiki, semuanya kini dijaga regresi: kartu statistik bernilai rupiah panjang melebarkan dokumen hingga 426 px pada layar 390 px (kini `overflow-wrap: anywhere` pada `.stat-value` dan `min-width: 0` pada kolom grid di bawah 576 px); grafik "Aktivitas pembangunan per hari" selalu kosong untuk periode Hari ini karena batas atas rentang dihitung `to 00:00:00` (kini `DATE_ADD(:date_to, INTERVAL 1 DAY)`); toast notifikasi melewat tepi kanan layar sempit karena `.toast-container` Bootstrap memakai `width: max-content` (kini `width:auto` pada aturan ponsel); dan bundel FullCalendar menarik font ikon `fcicons` sebagai `data:` URI yang diblokir `font-src 'self'` sehingga setiap halaman kalender memunculkan galat konsol (diperbaiki dengan teks panah `buttonIcons:false` + `font-src 'self' data:` yang penjelasannya ada di `App::sendSecurityHeaders()`).

Satu temuan lain muncul saat suite HTTP dijalankan ulang setelah logo diganti, dan kali ini terjadi pada Apache, bukan `php -S`: bagian 17 `tests/finance_http.php` memberi HTTP 500 dengan `Duplicate entry 'TRX-2026-012' for key 'uq_transactions_code'`, hanya satu baris tersimpan, dan sisanya runtuh (`Undefined array key 1`). Penyebabnya tabrakan alokasi nomor otomatis antar dua proses (penjelasan lengkap di butir *Alokasi nomor otomatis saat bersamaan* pada bagian 12); perbaikan dilakukan di `Database::transaction()`, bukan di ujiannya. `php -S` menyatukan permintaan secara berurutan sehingga tidak pernah memperlihatkan cacat ini — itulah sebabnya butir konkurensi selalu diulang di Apache.

Angka pemeriksaan visual tahap tiga (1 November 2026) masih tercatat sebagai dasar: **47 lulus, 0 gagal** untuk delapan halaman fondasi/RBAC dan **44 lulus, 0 gagal** untuk halaman modul proyek. Satu butirnya sudah kedaluwarsa oleh Prompt 4 — "tab Jadwal kegiatan dinyatakan Belum tersedia" — karena tab itu kini terisi data; gantinya, nilai kartu keuangan `/dashboard` sekarang dinilai lewat perbandingan langsung dengan `BudgetSummary` di `tests/projects.php` bagian H.

---

## 12. Keamanan dan operasional

- Jangan membagikan atau meng-commit `.env`.
- Atur `APP_DEBUG=false` dan `APP_ENV=production` di server produksi.
- Ganti kata sandi administrator bawaan segera setelah instalasi.
- Simpan minimal satu akun Super Admin aktif; sistem menolak menonaktifkan atau menghapus akun Super Admin aktif terakhir.
- SQL selalu memakai prepared statement dengan parameter terikat, termasuk nilai pencarian dan paginasi.
- Seluruh titik perubahan data (POST) diverifikasi token CSRF-nya; token dirotasi saat login dan logout.
- Pemeriksaan izin berjalan di middleware sebelum controller dipanggil, jadi URL langsung, tombol, formulir, dan titik AJAX sama-sama dijaga server.
- Nilai kata sandi, token reset, dan cookie tidak pernah ditulis ke `user_activity_logs` maupun `storage/logs/`; log berisi aktivitas, modul, IP, dan user agent saja.
- Halaman login mengembalikan satu pesan generik untuk akun tidak dikenal, kata sandi salah, akun nonaktif, dan peran nonaktif.
- Berkas unggahan disimpan di luar `public/` (`storage/uploads/projects/`) dengan nama acak 40 karakter heksadesimal; hanya controller yang berhak membukanya, dan nama berkas divalidasi dengan pola ketat lalu dikurung `realpath` agar `../` tidak dapat keluar dari folder unggahan.
- Unggahan diverifikasi berlapis: galat PHP (`UPLOAD_ERR_*`), ukuran, `is_uploaded_file()`, ekstensi, MIME hasil `finfo`, kecocokan MIME dengan ekstensi, dan `getimagesize()` untuk berkas gambar. Berkas yang lolos akan ditolak bila salah satu lapisan tidak konsisten.
- Respons berkas mengirim `Content-Type` sesuai MIME tersimpan + `X-Content-Type-Options: nosniff` + `Cache-Control: private, no-store`; nama unduhan disaring dari karakter kontrol dan tanda kutip.
- Bukti transaksi tidak punya jalur langsung ke berkas: hanya `GET /bukti/{id}` (izin `transactions.view`) dan `POST /bukti/{id}/hapus` (izin `transactions.update`) yang membukanya, dan keduanya memuat catatan dari basis data lebih dulu sebelum menyentuh disk.
- **Integritas keuangan**: setiap proses yang menyentuh lebih dari satu tabel berjalan di dalam `Database::transaction()` (BEGIN → COMMIT, dengan `rollBack` pada `Throwable` apa pun). Cakupannya pencatatan & perubahan transaksi, persetujuan/penolakan, pembatalan, keputusan revisi anggaran, perubahan status RAB, tambah/ubah/duplikasi/hapus item RAB, impor CSV RAB, penugasan izin, dan penyimpanan pengaturan.
- **Alokasi nomor otomatis saat bersamaan**: `TRX-YYYY-NNN`, `RAB-YYYY-NNN`, kode revisi, `PRJ-*`, dan `KGT-NNN` dicari dengan memindai baris yang ada, sementara InnoDB memakai satu snapshot untuk seluruh transaksi. Bila dua pengguna menyimpan bersamaan, keduanya dapat memilih nomor yang sama dan index UNIQUE (`uq_transactions_code`, `uq_budget_plans_code`, `uq_projects_code`, `uq_schedules_code`, `uq_budget_revisions_code`, `uq_budget_items_code`) menolak salah satunya. `Database::transaction()` kini mengenali tumbukan itu (`SQLSTATE 23000` dengan pesan `for key 'uq_..._code'`), mengulang seluruh pekerjaan dengan transaksi baru (maksimal empat percobaan) sehingga snapshot berikutnya melihat baris yang baru berkommit. Galat UNIQUE yang bukan tabrakan nomor tetap diteruskan apa adanya. Bukti: `tests/finance_http.php` bagian 17 — dua permintaan paralel sama-sama 302, nomor berbeda (`TRX-2026-012`, `TRX-2026-013`), realisasi cocok Rp57.900.000.
- **Penguncian saat dua pengguna bekerja bersamaan**: di dalam transaksi tersebut baris proyek dikunci lebih dulu dengan `Project::lockForUpdate()` (`SELECT id FROM projects WHERE id = ? FOR UPDATE`), sehingga pemeriksaan batas anggaran dan perubahan status berjalan berurutan, bukan berselang-seling. Perubahan status itu sendiri memakai kunci optimistis — `UPDATE ... WHERE id = ? AND status = ?` — dan bila nol baris berubah, pengguna mendapat pesan "Status ... baru saja diubah pengguna lain" tanpa jejak apa pun. Dua persetujuan serentak untuk satu transaksi karenanya hanya meninggalkan satu baris `transaction_approvals`.
- **Nomor otomatis anti-bentrok**: `TRX-YYYY-NNN`, `RAB-YYYY-NNN`, dan `KGT-NNN` dihitung server dengan mencari urutan pertama yang belum dipakai (pola unik dikunci indeks `UNIQUE`), bukan dikirim dari browser.
- **Nilai resmi ada di server**: `amount_normalize()` dan `volume_normalize()` memvalidasi format ribuan Indonesia (dan menolak `1.5000` atau angka lebih dari tiga desimal), lalu total item dihitung ulang dari volume × harga satuan yang tersimpan di basis data setiap kali item berubah. Angka tampilan berjalan di JavaScript hanya membantu mengisi kolom; tidak satu pun total, realisasi, sisa, atau persentase diterima dari masukan klien.
- **Transaksi tidak dapat dihapus setelah disetujui**: `FinancialTransaction::delete()` hanya menghapus baris berstatus `draft` atau `rejected`; pembatalan dilakukan lewat `POST /transaksi/{id}/batalkan` yang mewajibkan alasan, mengunci status asal, dan mencatat jejak di `transaction_approvals` serta `user_activity_logs`. Realisasi hanya menghitung transaksi `approved` per pusat biaya.
- **Pencegahan pencatatan ganda**: saat transaksi **baru** dicatat, kembar pada kombinasi proyek + tanggal + jenis + nominal + uraian (+ `reference_number` bila diisi) selama baris yang sudah ada masih `draft`/`pending`/`approved` ditolak dengan pesan "Transaksi serupa sudah tercatat pada tanggal, proyek, dan nominal yang sama."
- **Pemisahan tugas sebagai aturan server**: ketika `finance_segregation_of_duties = 1`, penolakan terjadi sebelum status berubah — pengaju RAB, pengaju revisi anggaran, dan pencatat transaksi tidak dapat memutus barisnya sendiri, meskipun URL keputusan dibuka langsung dengan token CSRF yang sah.
- **Anggaran tidak digeser diam-diam**: modul anggaran tidak pernah mengubah `projects.budget_amount`. Nilai itu hanya berubah lewat formulir proyek (`POST /proyek/{id}/ubah`, izin `projects.update`) dan setiap suntingan tercatat sebagai aktivitas; sedangkan penyesuaian anggaran berjalan lewat `budget_revisions` yang menyimpan jumlah lama, jumlah baru, alasan, pengaju + waktu, dan pemeriksa + waktu keputusan (selisih dihitung dari dua jumlah itu, bukan disimpan sebagai angka bebas).
- **Pembatasan dokumen keuangan**: berkas bukti dan dokumen proyek berada di `storage/uploads/projects/` di luar `public/`, dengan nama acak 40 heksadesimal, dan penamaan dibaca ulang lewat pola ketat sebelum `realpath` — jadi `../` tidak dapat keluar dari folder unggahan.
- Pelonggaran CSP yang disengaja: `font-src 'self' data:` (sebelumnya hanya `'self'`) karena bundel FullCalendar menyertakan font ikonnya sendiri sebagai URI base64. Font tidak dapat menjalankan skrip dan sumbernya tetap lokal, sedangkan `script-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'`, dan `base-uri 'self'` tidak berubah; ikon navigasi kalender tetap ditulis sebagai teks agar tidak bergantung pada font itu.
- Keluaran view memakai `e()` (escape HTML), termasuk nama pengguna yang dapat disunting, sehingga penyisipan markup tidak tereksekusi. Data grafik dikirim sebagai atribut `data-chart-*` hasil `json_attr()`, bukan skrip inline, sehingga `script-src 'self'` tetap berlaku.
- Log aplikasi tersimpan di `storage/logs/app-YYYY-MM-DD.log` dan tidak dapat diakses dari web; hanya folder `public/` yang boleh di-expose.
- Sesi berakhir otomatis setelah tidak aktif sesuai `SESSION_LIFETIME`; akun yang dinonaktifkan atau peran yang dimatikan kehilangan aksesnya pada permintaan berikutnya.
- Token CSRF berlaku per sesi dan diperbarui setelah login.

---

## 13. Pemecahan masalah

| Gejala | Penyebab umum dan solusi |
| --- | --- |
| Halaman 404 dari Apache | `mod_rewrite` nonaktif atau `AllowOverride` belum `All`. Aktifkan keduanya lalu restart Apache. |
| "Forbidden — You don't have permission to access this resource" dari Apache saat membuka `http://localhost/SPPG-Cangkring/` | Halaman galat ini berasal dari Apache, bukan aplikasi. Periksa `apache/logs/error.log`; bila isinya `Cannot map GET D:/PROGRAM/... to file`, nilai `RewriteBase` pada `.htaccess` root tidak sama dengan nama folder proyek sehingga pengalihan ke `public/` memakai path filesystem. Selaraskan nilainya, lalu muat ulang halaman. |
| `SQLSTATE[HY000] [2002]` | MySQL/MariaDB belum berjalan atau `DB_HOST`/`DB_PORT` salah. Periksa XAMPP Control Panel dan `.env`. |
| `Access denied for user` | `DB_USERNAME`/`DB_PASSWORD` tidak sesuai. |
| Perubahan CSS/JS tidak terlihat | Aset memakai cache-busting berbasis waktu ubah berkas; muat ulang penuh (Ctrl+F5). |
| Login gagal berulang lalu diblokir sementara | Pembatasan percobaan masuk aktif. Tunggu sesuai `LOGIN_WINDOW_MINUTES` atau periksa tabel `login_attempts`. |
| Muncul 403 padahal menu terlihat | Akun tidak memiliki izin modul tersebut. Minta Super Admin mencentang izinnya di **Hak Akses**; perubahan langsung berlaku tanpa kode diubah. |
| Peran tidak muncul di dropdown tambah pengguna | Peran tersebut dinonaktifkan (`roles.is_active = 0`), atau peran Super Admin sedang diakses oleh pengelola non-Super Admin. |
| Perubahan hak akses terasa tidak berpengaruh | Buka kembali halaman tujuan pada permintaan berikutnya — izin dibaca dari database tiap permintaan. Sesi lama tidak menyimpan izin. |
| "Akun Super Admin aktif terakhir tidak boleh..." | Hanya ada satu akun Super Admin aktif. Buat/aktifkan akun Super Admin lain sebelum menonaktifkan atau menghapus yang sekarang. |
| Halaman 500 | Periksa `storage/logs/app-YYYY-MM-DD.log` dan cocokkan dengan referensi kejadian yang tampil. |
| Folder `storage/logs` tidak dapat ditulis | Berikan izin tulis kepada pengguna web server. |
| Realisasi proyek tidak bertambah padahal transaksi sudah tersimpan | Transaksi masih `draft` atau `pending`. Realisasi hanya menghitung status `approved` — ajukan lewat `POST /transaksi/{id}/ajukan` lalu setujui dengan akun pemeriksa. |
| "Transaksi yang Anda catat sendiri tidak dapat Anda setujui." | `finance_segregation_of_duties = 1` (bawaan). Buka transaksi itu dengan akun pemeriksa yang memegang `transactions.approve`, atau matikan sakelarnya di **Pengaturan** bila kebijakan lembaga memang mengizinkan satu orang untuk kedua peran. |
| "Status transaksi baru saja diubah pengguna lain." | Dua pemeriksa membuka halaman yang sama. Muat ulang halaman; nilai dan status yang tampil sekarang adalah hasil dari transaksi database yang sudah COMMIT, jadi tidak ada data yang rusak. |
| "Total RAB tidak berubah setelah item disunting" atau item RAB ditolak | RAB sudah berstatus Disetujui. Sunting tidak diizinkan pada RAB disetujui — gunakan `POST /rab/{id}/revisi` untuk membuat versi turunan, lalu ajukan dan setujui versi tersebut. |
| Transaksi ditolak dengan "Transaksi serupa sudah tercatat..." | Kunci anti-ganda memicu proyek + tanggal + jenis + nominal + uraian yang sama. Ubah salah satu nilai itu, atau memang barisnya ganda dan tidak perlu dicatat dua kali. |
| Kartu **Sisa anggaran** dan **Penyerapan** menampilkan `—` | Bukan galat. Keduanya hanya bernilai bila halaman menampilkan kedua pusat biaya (`?pusat=` dikosongkan). Pada `?pusat=construction` atau `?pusat=operations` nilainya sengaja dikosongkan karena anggaran acuan belum dipisah per pusat biaya; realisasi, jumlah transaksi, dan tabel pemisah tetap terisi. |
| Kalender atau Gantt kosong | Periksa penyaring `?proyek=`, `?dari=`, dan `?sampai=` pada URL — kegiatan di luar rentang tanggal tidak dikirim ke grid, dan Gantt selalu menampilkan satu proyek (proyek pertama bila `?proyek=` kosong). Kegiatan yang dihapus memakai hapus lunak (`is_deleted = 1`) sehingga hilang dari kedua tampilan. |
| Panah navigasi kalender tidak muncul atau muncul sebagai kotak | Bundel FullCalendar dimuat lokal (`public/assets/vendor/fullcalendar/`). Bila `schedules.js` diganti, peramban mengambil versi baru otomatis karena `asset()` memakai `?v=filemtime`; lakukan muat penuh (Ctrl+F5). |

---

## 14. Rencana tahap berikutnya

**Tahap selanjutnya adalah Prompt 5: Data Sekolah, Penerima Manfaat, Perencanaan Menu, dan Informasi Gizi MBG.** Tahap itu melanjutkan codebase ini tanpa membangun ulang struktur, konfigurasi, desain, atau skema yang sudah ada; modul baru masuk lewat controller, model, view, rute, dan migration bernomor lanjut (mulai `015`).

Yang sudah tersedia dan dapat langsung dipakai Prompt 5:

- **Pusat biaya operasional MBG sudah ada.** `financial_transactions.cost_center` membedakan `construction` dan `operations`, dan `BudgetSummary::globalOverview()` menerima keduanya. Belanja bahan pangan dan biaya operasional lain dapat dicatat, disetujui, dan direkap sejak hari pertama tanpa menunggu modul baru — realisasi operasional sudah tampil di `/dashboard` dan tabel *Pembangunan dan operasional MBG* di `/keuangan`.
- **Enam kartu operasional di `/dashboard`** (sekolah terdaftar, penerima manfaat, jumlah menu, stok bahan pangan, status produksi, status distribusi) sudah berbaris dengan penanda **Belum tersedia**; `DashboardController::operasional()` tinggal diisi nilainya, strukturnya tidak perlu diubah.
- **Izin sudah terdaftar lebih dulu** untuk `schools.view/manage`, `menus.view/manage`, `inventory.view/manage`, `production.view/manage`, `quality_control.manage`, `distribution.manage`, dan `reports.view/export`. Rute baru tinggal dipasang lewat `PermissionMiddleware` — pola yang sama dipakai modul keuangan. Izin untuk **penerima manfaat** dan **informasi gizi** belum ada dan perlu ditambahkan lewat migration `015`, bukan dengan memakai nama izin yang sudah ada.
- **`App\Core\FileUpload`** memvalidasi ekstensi + MIME + kecocokan keduanya + ukuran dan menyimpan di luar `public/`; poster menu, sertifikat laik higiene, dan dokumen gizi dapat memakai pola yang sama.
- **`Notification::push()` / `pushToMany()`** menerima jenis baru dengan kunci deduplikasi, jadi pengingat pergantian menu, stok menipis, atau surat izin dapur yang mendekati kedaluwarsa tidak memerlukan perubahan tabel.
- **`user_activity_logs.module`** menampung slug modul apa pun, dan `ActivityLog::record()` sudah dipanggil dari 60 titik di controller; `Project::logStatus()` serta `schedule_histories` menjadi contoh pola riwayat berpengguna + waktu + alasan yang dapat ditiru untuk perubahan menu dan gizi.
- **Kolom `users.photo`** masih menganggur untuk foto profil, dan `password_resets` siap dipakai begitu pengantar surel tersedia.

Dua hal yang sengaja tidak dikerjakan lebih dulu agar tidak mendahului spesifikasi: tabel sumber data sekolah/penerima manfaat (struktur kolomnya belum diminta) dan pemisahan anggaran awal per pusat biaya — angka acuan saat ini satu nilai per proyek, sehingga kartu sisa dan penyerapan pada `/keuangan` baru tampil ketika kedua pusat biaya digabung.