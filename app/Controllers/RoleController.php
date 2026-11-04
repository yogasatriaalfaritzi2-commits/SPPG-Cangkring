<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

final class RoleController extends Controller
{
    public function index(Request $request): void
    {
        $selectedId = max(0, $request->integer('peran'));
        $selected = $selectedId > 0 ? Role::find($selectedId) : null;

        if ($selectedId > 0 && $selected === null) {
            throw new NotFoundHttpException();
        }

        $granted = $selected !== null ? Role::permissionIds((int) $selected['id']) : [];
        $grouped = Permission::groupedByModule();
        $mappings = Role::allPermissionMappings();

        $matrix = [];
        foreach ($mappings as $mapping) {
            $matrix[$mapping['role_id']][$mapping['permission_id']] = true;
        }

        $old = Session::pull('_old', []);

        $this->view('roles.index', [
            'title' => 'Hak Akses',
            'pageTitle' => 'Peran & Hak Akses',
            'roles' => Role::allWithCounts(),
            'grouped' => $grouped,
            'permissions' => Permission::allOrdered(),
            'matrix' => $matrix,
            'selected' => $selected,
            'granted' => $granted,
            'managePermissionId' => Permission::idBySlug('permissions.manage'),
            'old' => is_array($old) ? $old : [],
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();

        $name = trim($request->string('role_name'));
        $slug = slugify_text($request->string('role_slug') !== '' ? $request->string('role_slug') : $name);
        $description = trim($request->string('description'));

        $errors = [];

        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $errors[] = 'Nama peran harus terdiri dari 3 sampai 80 karakter.';
        }

        if (!slug_is_valid($slug)) {
            $errors[] = 'Slug peran harus diawali huruf dan hanya memuat huruf kecil, angka, serta garis bawah (3-40 karakter).';
        }

        if (mb_strlen($description) > 255) {
            $errors[] = 'Deskripsi peran maksimal 255 karakter.';
        }

        if ($errors === [] && Role::nameExists($name)) {
            $errors[] = 'Nama peran sudah digunakan.';
        }

        if ($errors === [] && Role::slugExists($slug)) {
            $errors[] = 'Slug peran sudah digunakan.';
        }

        if ($errors !== []) {
            $this->reject($errors[0], ['role_name' => $name, 'role_slug' => $slug, 'description' => $description]);
        }

        $roleId = Role::create($name, $slug, $description);

        ActivityLog::record(
            (int) $actor['id'],
            'roles.create',
            'roles',
            'Menambah peran "' . $name . '" (slug: ' . $slug . ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Peran "' . $name . '" berhasil dibuat. Segera tetapkan izinnya.');
        $this->redirect('/hak-akses?peran=' . $roleId);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $role = $this->findRole($id);
        $roleId = (int) $role['id'];
        $isSuperAdminRole = (string) $role['role_slug'] === Role::SUPER_ADMIN_SLUG;

        $name = trim($request->string('edit_role_name'));
        $description = trim($request->string('edit_description'));
        $active = $request->boolean('edit_is_active');

        $errors = [];

        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $errors[] = 'Nama peran harus terdiri dari 3 sampai 80 karakter.';
        }

        if (mb_strlen($description) > 255) {
            $errors[] = 'Deskripsi peran maksimal 255 karakter.';
        }

        if ($errors === [] && Role::nameExists($name, $roleId)) {
            $errors[] = 'Nama peran sudah digunakan peran lain.';
        }

        if ($active !== ((int) $role['is_active'] === 1)) {
            if ($isSuperAdminRole) {
                $errors[] = 'Peran Super Admin tidak dapat dinonaktifkan.';
            } elseif (!$active && User::countActiveByRole($roleId) > 0) {
                $errors[] = 'Peran masih dipakai akun aktif. Nonaktifkan atau pindahkan akun tersebut terlebih dahulu.';
            }
        }

        if ($errors !== []) {
            $this->reject(
                $errors[0],
                ['edit_role_name' => $name, 'edit_description' => $description],
                $roleId
            );
        }

        Role::update($roleId, $name, $description, $active);

        ActivityLog::record(
            (int) $actor['id'],
            'roles.update',
            'roles',
            'Memperbarui peran "' . (string) $role['role_name'] . '" menjadi "' . $name . '"'
            . ' (status: ' . ($active ? 'aktif' : 'nonaktif') . ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Peran berhasil diperbarui.');
        $this->redirect('/hak-akses?peran=' . $roleId);
    }

    public function syncPermissions(Request $request, string $id): void
    {
        $actor = $this->actor();
        $role = $this->findRole($id);
        $roleId = (int) $role['id'];
        $isSuperAdminRole = (string) $role['role_slug'] === Role::SUPER_ADMIN_SLUG;

        if ($isSuperAdminRole) {
            Role::syncPermissions($roleId, Permission::ids());
            ActivityLog::record(
                (int) $actor['id'],
                'roles.permissions',
                'roles',
                'Menyinkronkan seluruh izin pada peran Super Admin.',
                $request->ip(),
                $request->userAgent()
            );
            Session::flash('info', 'Peran Super Admin selalu memiliki seluruh izin sistem.');
            $this->redirect('/hak-akses?peran=' . $roleId);
        }

        $requested = $request->input('permissions', []);
        if (!is_array($requested)) {
            throw new HttpException(400, 'Daftar izin yang dikirim tidak valid.');
        }

        $all = Permission::ids();
        $manageId = Permission::idBySlug('permissions.manage');

        $selected = [];
        foreach ($requested as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $permissionId = (int) $value;
            if ($permissionId > 0 && in_array($permissionId, $all, true)) {
                $selected[] = $permissionId;
            }
        }

        if ($manageId !== null && in_array($manageId, $selected, true)) {
            throw new HttpException(
                403,
                'Izin permissions.manage hanya boleh melekat pada peran Super Admin.'
            );
        }

        Role::syncPermissions($roleId, array_values(array_unique($selected)));

        ActivityLog::record(
            (int) $actor['id'],
            'roles.permissions',
            'roles',
            'Menyimpan ' . count($selected) . ' izin untuk peran "' . (string) $role['role_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Hak akses peran "' . $role['role_name'] . '" disimpan dan langsung berlaku.');
        $this->redirect('/hak-akses?peran=' . $roleId);
    }

    /**
     * @param array<string, string> $old
     */
    private function reject(string $message, array $old, int $focusRoleId = 0): never
    {
        Session::flash('danger', $message);
        Session::putOld($old);
        $path = '/hak-akses' . ($focusRoleId > 0 ? '?peran=' . $focusRoleId : '');
        $this->redirect($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function findRole(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $role = Role::find((int) $id);
        if ($role === null) {
            throw new NotFoundHttpException();
        }

        return $role;
    }
}
