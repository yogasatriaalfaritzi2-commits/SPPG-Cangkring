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

final class UserController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): void
    {
        $search = $this->normalizeSearch($request->string('q'));
        $roleId = max(0, $request->integer('role'));
        $status = $this->normalizeStatus($request->string('status'));
        $page = max(1, $request->integer('page', 1));

        $result = User::paginate($search, $roleId, $status, $page, self::PER_PAGE);

        $this->view('users.index', [
            'title' => 'Pengguna',
            'pageTitle' => 'Manajemen Pengguna',
            'filters' => ['q' => $search, 'role' => $roleId, 'status' => $status],
            'result' => $result,
            'users' => $result['rows'],
            'roles' => Role::allWithCounts(),
        ]);
    }

    public function create(Request $request): void
    {
        $old = Session::pull('_old', []);

        $this->view('users.form', [
            'title' => 'Tambah Pengguna',
            'pageTitle' => 'Tambah Pengguna',
            'mode' => 'create',
            'form' => $this->blankForm(is_array($old) ? $old : []),
            'roles' => $this->assignableRoles(),
            'target' => null,
            'isSelf' => false,
            'activityCount' => 0,
            'permissionCount' => 0,
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);

        [$data, $errors] = $this->validate($input, null, true);

        if ($data !== null && $this->isSuperAdminRole((int) $data['role_id']) && !Auth::isSuperAdmin()) {
            $errors[] = 'Hanya Super Admin yang dapat membuat akun dengan peran Super Admin.';
        }

        if ($errors !== []) {
            $this->reject('/pengguna/tambah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $userId = User::create([
            'full_name' => (string) $data['full_name'],
            'username' => (string) $data['username'],
            'email' => (string) $data['email'],
            'phone' => $data['phone'],
            'password' => (string) $input['password'],
            'role_id' => (int) $data['role_id'],
            'status' => (string) $data['status'],
        ]);

        $role = Role::find((int) $data['role_id']);

        ActivityLog::record(
            (int) $actor['id'],
            'users.create',
            'users',
            'Menambah pengguna "' . $data['username'] . '" dengan peran ' . (string) ($role['role_name'] ?? '-') . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Pengguna berhasil ditambahkan.');
        $this->redirect('/pengguna/' . $userId);
    }

    public function edit(Request $request, string $id): void
    {
        $actor = $this->actor();
        $target = $this->findUser($id);
        $this->guardTarget($target, 'Melihat');

        $old = Session::pull('_old', []);
        $old = is_array($old) ? $old : [];

        $roles = $this->assignableRoles();
        $roleIds = array_map(static fn (array $role): int => (int) $role['id'], $roles);
        if (!in_array((int) $target['role_id'], $roleIds, true)) {
            $currentRole = Role::find((int) $target['role_id']);
            if ($currentRole !== null) {
                $roles[] = $currentRole;
            }
        }

        $form = [
            'full_name' => (string) ($old['full_name'] ?? $target['full_name']),
            'username' => (string) ($old['username'] ?? $target['username']),
            'email' => (string) ($old['email'] ?? $target['email']),
            'phone' => (string) ($old['phone'] ?? ($target['phone'] ?? '')),
            'role_id' => (string) ($old['role_id'] ?? $target['role_id']),
            'status' => (string) ($old['status'] ?? $target['status']),
            'password' => '',
            'password_confirmation' => '',
        ];

        $this->view('users.form', [
            'title' => 'Sunting Pengguna',
            'pageTitle' => 'Sunting Pengguna',
            'mode' => 'edit',
            'form' => $form,
            'roles' => $roles,
            'target' => $target,
            'isSelf' => (int) $actor['id'] === (int) $target['id'],
            'activityCount' => ActivityLog::countForUser((int) $target['id']),
            'permissionCount' => (string) $target['role_slug'] === Role::SUPER_ADMIN_SLUG
                ? Permission::count()
                : count(Role::permissionIds((int) $target['role_id'])),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $target = $this->findUser($id);
        $this->guardTarget($target, 'Mengubah');

        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, (int) $target['id'], false);

        $isSelf = (int) $actor['id'] === (int) $target['id'];

        if ($data !== null) {
            if ((int) $data['role_id'] !== (int) $target['role_id']) {
                if ($isSelf) {
                    $errors[] = 'Anda tidak dapat mengubah peran akun sendiri.';
                }
                if ($this->isSuperAdminRole((int) $data['role_id']) && !Auth::isSuperAdmin()) {
                    $errors[] = 'Hanya Super Admin yang dapat memberikan peran Super Admin.';
                }
            }

            if ((string) $data['status'] !== (string) $target['status']) {
                if ($isSelf) {
                    $errors[] = 'Anda tidak dapat mengubah status akun sendiri dari halaman ini.';
                } elseif (
                    (string) $data['status'] === User::STATUS_INACTIVE
                    && $this->isSuperAdminTarget($target)
                    && User::countActiveSuperAdmins() <= 1
                ) {
                    $errors[] = 'Akun Super Admin aktif terakhir tidak boleh dinonaktifkan.';
                }
            }
        }

        if ($errors !== []) {
            $this->reject('/pengguna/' . $target['id'], $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        User::updateProfile((int) $target['id'], [
            'full_name' => (string) $data['full_name'],
            'username' => (string) $data['username'],
            'email' => (string) $data['email'],
            'phone' => $data['phone'],
            'role_id' => (int) $data['role_id'],
        ]);

        if ((string) $data['status'] !== (string) $target['status']) {
            $this->applyStatus($target, (string) $data['status'], $actor, $request);
        }

        if ((int) $data['role_id'] !== (int) $target['role_id']) {
            $newRole = Role::find((int) $data['role_id']);
            ActivityLog::record(
                (int) $actor['id'],
                'users.role',
                'users',
                'Mengubah peran "' . $target['username'] . '" dari ' . (string) $target['role_name']
                . ' menjadi ' . (string) ($newRole['role_name'] ?? '-') . '.',
                $request->ip(),
                $request->userAgent()
            );
        }

        ActivityLog::record(
            (int) $actor['id'],
            'users.update',
            'users',
            'Memperbarui data pengguna "' . $data['username'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Data pengguna berhasil diperbarui.');
        $this->redirect('/pengguna/' . $target['id']);
    }

    public function status(Request $request, string $id): void
    {
        $actor = $this->actor();
        $target = $this->findUser($id);
        $this->guardTarget($target, 'Mengubah status');

        $status = $this->normalizeStatus($request->string('status'));
        if ($status !== User::STATUS_ACTIVE && $status !== User::STATUS_INACTIVE) {
            $this->fail('Status pengguna tidak dikenali.', (string) $target['id']);
        }

        if ((int) $actor['id'] === (int) $target['id']) {
            $this->fail('Anda tidak dapat menonaktifkan atau mengaktifkan akun sendiri.', (string) $target['id']);
        }

        $this->applyStatus($target, $status, $actor, $request);

        Session::flash('success', $status === User::STATUS_ACTIVE
            ? 'Akun "' . $target['username'] . '" diaktifkan.'
            : 'Akun "' . $target['username'] . '" dinonaktifkan.');
        $this->redirect('/pengguna');
    }

    public function password(Request $request, string $id): void
    {
        $actor = $this->actor();
        $target = $this->findUser($id);
        $this->guardTarget($target, 'Mengatur ulang kata sandi');

        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');

        if ($password === '' || $confirmation === '') {
            $this->fail('Kata sandi baru dan konfirmasinya wajib diisi.', (string) $target['id']);
        }

        if ($password !== $confirmation) {
            $this->fail('Konfirmasi kata sandi tidak cocok.', (string) $target['id']);
        }

        $errors = password_policy_errors($password);
        if ($errors !== []) {
            $this->fail($errors[0], (string) $target['id']);
        }

        User::updatePassword((int) $target['id'], $password);

        ActivityLog::record(
            (int) $actor['id'],
            'users.password',
            'users',
            'Mengatur ulang kata sandi akun "' . $target['username'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Kata sandi akun "' . $target['username'] . '" berhasil diganti.');
        $this->redirect('/pengguna/' . $target['id']);
    }

    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $target = $this->findUser($id);
        $this->guardTarget($target, 'Menghapus');

        if ((int) $actor['id'] === (int) $target['id']) {
            $this->fail('Anda tidak dapat menghapus akun yang sedang Anda gunakan.', '');
        }

        if ($this->isSuperAdminTarget($target) && User::countActiveSuperAdmins() <= 1) {
            $this->fail('Akun Super Admin aktif terakhir tidak boleh dihapus.', '');
        }

        User::delete((int) $target['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'users.delete',
            'users',
            'Menghapus akun "' . $target['username'] . '" (peran ' . (string) $target['role_name'] . ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Akun "' . $target['username'] . '" telah dihapus.');
        $this->redirect('/pengguna');
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $actor
     */
    private function applyStatus(array $target, string $status, array $actor, Request $request): void
    {
        if ($status === (string) $target['status']) {
            return;
        }

        if (
            $status === User::STATUS_INACTIVE
            && $this->isSuperAdminTarget($target)
            && User::countActiveSuperAdmins() <= 1
        ) {
            throw new HttpException(
                403,
                'Akun Super Admin aktif terakhir tidak boleh dinonaktifkan.'
            );
        }

        User::updateStatus((int) $target['id'], $status);

        ActivityLog::record(
            (int) $actor['id'],
            $status === User::STATUS_ACTIVE ? 'users.activate' : 'users.deactivate',
            'users',
            ($status === User::STATUS_ACTIVE ? 'Mengaktifkan' : 'Menonaktifkan')
            . ' akun "' . $target['username'] . '".',
            $request->ip(),
            $request->userAgent()
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId, bool $withPassword): array
    {
        $errors = [];
        $data = [];

        $fullName = trim((string) $input['full_name']);
        $username = mb_strtolower(trim((string) $input['username']), 'UTF-8');
        $email = mb_strtolower(trim((string) $input['email']), 'UTF-8');
        $phone = trim((string) $input['phone']);
        $roleRaw = (string) $input['role_id'];
        $status = $this->normalizeStatus((string) $input['status']);

        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
            $errors[] = 'Nama lengkap harus terdiri dari 3 sampai 120 karakter.';
        }

        if (!username_is_valid($username)) {
            $errors[] = 'Nama pengguna harus 3-40 karakter, diawali huruf atau angka, dan hanya memuat huruf kecil, angka, titik, garis bawah, atau tanda hubung.';
        } elseif (User::usernameExists($username, $exceptId)) {
            $errors[] = 'Nama pengguna sudah dipakai akun lain.';
        }

        if (mb_strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Alamat surel tidak valid.';
        } elseif (User::emailExists($email, $exceptId)) {
            $errors[] = 'Alamat surel sudah dipakai akun lain.';
        }

        if ($phone !== '' && !phone_is_valid($phone)) {
            $errors[] = 'Format nomor telepon tidak wajar. Gunakan contoh 081234567890 atau +6281234567890.';
        }

        $role = null;
        if ($roleRaw === '' || !ctype_digit($roleRaw) || (int) $roleRaw <= 0) {
            $errors[] = 'Peran wajib dipilih.';
        } else {
            $role = Role::find((int) $roleRaw);
            if ($role === null) {
                $errors[] = 'Peran yang dipilih tidak terdaftar.';
            } elseif ((int) $role['is_active'] !== 1) {
                $errors[] = 'Peran tersebut sedang dinonaktifkan dan tidak dapat diberikan.';
            }
        }

        if ($status !== User::STATUS_ACTIVE && $status !== User::STATUS_INACTIVE) {
            $errors[] = 'Status akun tidak dikenali.';
        }

        if ($withPassword) {
            $password = (string) $input['password'];
            $confirmation = (string) $input['password_confirmation'];

            if ($password === '' || $confirmation === '') {
                $errors[] = 'Kata sandi dan konfirmasinya wajib diisi.';
            } elseif ($password !== $confirmation) {
                $errors[] = 'Konfirmasi kata sandi tidak cocok.';
            } else {
                $policyErrors = password_policy_errors($password);
                foreach ($policyErrors as $policyError) {
                    $errors[] = $policyError;
                }
            }
        }

        if ($errors === [] && $role !== null) {
            $data = [
                'full_name' => $fullName,
                'username' => $username,
                'email' => $email,
                'phone' => $phone === '' ? null : phone_normalize($phone),
                'role_id' => (int) $role['id'],
                'status' => $status,
            ];
        }

        return [$data === [] ? null : $data, $errors];
    }

    /**
     * @return array<string, string>
     */
    private function readForm(Request $request): array
    {
        return [
            'full_name' => (string) $request->input('full_name', ''),
            'username' => (string) $request->input('username', ''),
            'email' => (string) $request->input('email', ''),
            'phone' => (string) $request->input('phone', ''),
            'role_id' => (string) $request->input('role_id', ''),
            'status' => (string) $request->input('status', ''),
            'password' => (string) $request->input('password', ''),
            'password_confirmation' => (string) $request->input('password_confirmation', ''),
        ];
    }

    /**
     * @param array<string, mixed> $old
     * @return array<string, string>
     */
    private function blankForm(array $old): array
    {
        return [
            'full_name' => (string) ($old['full_name'] ?? ''),
            'username' => (string) ($old['username'] ?? ''),
            'email' => (string) ($old['email'] ?? ''),
            'phone' => (string) ($old['phone'] ?? ''),
            'role_id' => (string) ($old['role_id'] ?? ''),
            'status' => (string) ($old['status'] ?? User::STATUS_ACTIVE),
            'password' => '',
            'password_confirmation' => '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reject(string $path, string $message, array $input): never
    {
        $safe = $input;
        unset($safe['password'], $safe['password_confirmation']);
        $safe['password'] = '';
        $safe['password_confirmation'] = '';

        Session::flash('danger', $message);
        Session::putOld($safe);
        $this->redirect($path);
    }

    private function fail(string $message, string $suffix): never
    {
        Session::flash('danger', $message);
        $this->redirect($suffix === '' ? '/pengguna' : '/pengguna/' . $suffix);
    }

    private function normalizeSearch(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_substr($value, 0, 60);
    }

    private function normalizeStatus(string $value): string
    {
        return in_array($value, [User::STATUS_ACTIVE, User::STATUS_INACTIVE, ''], true) ? $value : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assignableRoles(): array
    {
        $roles = Role::active();

        if (Auth::isSuperAdmin()) {
            return $roles;
        }

        return array_values(array_filter(
            $roles,
            static fn (array $role): bool => (string) $role['role_slug'] !== Role::SUPER_ADMIN_SLUG
        ));
    }

    private function isSuperAdminRole(int $roleId): bool
    {
        $role = Role::find($roleId);

        return $role !== null && (string) $role['role_slug'] === Role::SUPER_ADMIN_SLUG;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function isSuperAdminTarget(array $target): bool
    {
        return (string) ($target['role_slug'] ?? '') === Role::SUPER_ADMIN_SLUG;
    }

    /**
     * @return array<string, mixed>
     */
    private function findUser(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $user = User::find((int) $id);
        if ($user === null) {
            throw new NotFoundHttpException();
        }

        return $user;
    }

    /**
     * Akun Super Admin hanya dapat dikelola oleh Super Admin.
     *
     * @param array<string, mixed> $target
     */
    private function guardTarget(array $target, string $action): void
    {
        if ($this->isSuperAdminTarget($target) && !Auth::isSuperAdmin()) {
            throw new HttpException(
                403,
                $action . ' akun Super Admin hanya dapat dilakukan oleh Super Admin.'
            );
        }
    }
}
