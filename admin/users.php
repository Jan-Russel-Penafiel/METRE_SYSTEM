<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['admin']);

$editingId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editingUser = $editingId > 0 ? db_select_one('SELECT * FROM users WHERE id = ? LIMIT 1', 'i', [$editingId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $userType = trim((string) ($_POST['user_type'] ?? 'driver'));
    $vehicleType = trim((string) ($_POST['vehicle_type'] ?? 'Standard Taxi'));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($fullName === '' || $username === '') {
        set_flash('Full name and username are required.', 'error');
        redirect_to('admin/users.php' . ($id ? '?edit=' . $id : ''));
    }

    if (!in_array($userType, ['admin', 'driver'], true)) {
        $userType = 'driver';
    }

    $duplicate = db_select_one(
        'SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1',
        'si',
        [$username, $id]
    );

    if ($duplicate) {
        set_flash('That username is already in use.', 'error');
        redirect_to('admin/users.php' . ($id ? '?edit=' . $id : ''));
    }

    if ($id > 0) {
        db_execute(
            'UPDATE users SET full_name = ?, username = ?, user_type = ?, vehicle_type = ?, is_active = ? WHERE id = ?',
            'ssssii',
            [$fullName, $username, $userType, $vehicleType, $isActive, $id]
        );

        if ($password !== '') {
            db_execute(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                'si',
                [password_hash($password, PASSWORD_DEFAULT), $id]
            );
        }

        set_flash('User account updated.', 'success');
    } else {
        if ($password === '') {
            set_flash('Password is required for new accounts.', 'error');
            redirect_to('admin/users.php');
        }

        db_execute(
            'INSERT INTO users (full_name, username, password_hash, user_type, vehicle_type, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            'sssssi',
            [$fullName, $username, password_hash($password, PASSWORD_DEFAULT), $userType, $vehicleType, $isActive]
        );
        set_flash('User account created.', 'success');
    }

    redirect_to('admin/users.php');
}

$vehicleTypeOptions = get_vehicle_type_options();
$users = db_select_all('SELECT id, full_name, username, user_type, vehicle_type, is_active, created_at FROM users ORDER BY created_at DESC');

if (!$editingUser) {
    $editingUser = [
        'id' => 0,
        'full_name' => '',
        'username' => '',
        'user_type' => 'driver',
        'vehicle_type' => $vehicleTypeOptions[0],
        'is_active' => 1,
    ];
}

render_page_start('Users');
?>
<div class="grid gap-8 xl:grid-cols-[0.9fr_1.1fr]">
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8">
        <div class="mb-6">
            <h1 class="text-3xl font-black tracking-tight">User accounts</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Create admin and driver accounts with per-driver vehicle defaults.</p>
        </div>

        <form method="post" class="space-y-5">
            <input type="hidden" name="id" value="<?php echo (int) $editingUser['id']; ?>">

            <div>
                <label for="full_name" class="mb-2 block text-sm font-medium">Full name</label>
                <input id="full_name" name="full_name" type="text" value="<?php echo h($editingUser['full_name']); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" required>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="username" class="mb-2 block text-sm font-medium">Username</label>
                    <input id="username" name="username" type="text" value="<?php echo h($editingUser['username']); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" required>
                </div>
                <div>
                    <label for="password" class="mb-2 block text-sm font-medium">Password <?php echo $editingUser['id'] ? '(leave blank to keep current)' : ''; ?></label>
                    <input id="password" name="password" type="password" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" <?php echo $editingUser['id'] ? '' : 'required'; ?>>
                </div>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="user_type" class="mb-2 block text-sm font-medium">Role</label>
                    <select id="user_type" name="user_type" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950">
                        <option value="driver" <?php echo $editingUser['user_type'] === 'driver' ? 'selected' : ''; ?>>Driver</option>
                        <option value="admin" <?php echo $editingUser['user_type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div>
                    <label for="vehicle_type" class="mb-2 block text-sm font-medium">Default vehicle type</label>
                    <select id="vehicle_type" name="vehicle_type" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950">
                        <?php foreach ($vehicleTypeOptions as $option): ?>
                            <option value="<?php echo h($option); ?>" <?php echo $editingUser['vehicle_type'] === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-slate-800">
                <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500" <?php echo !empty($editingUser['is_active']) ? 'checked' : ''; ?>>
                <span>Account active</span>
            </label>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-2xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-700">
                    <?php echo $editingUser['id'] ? 'Update User' : 'Create User'; ?>
                </button>
                <?php if ($editingUser['id']): ?>
                    <a href="<?php echo h(url('admin/users.php')); ?>" class="rounded-2xl border border-slate-300 px-5 py-3 text-sm font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 px-6 py-5 dark:border-slate-800">
            <h2 class="text-xl font-bold tracking-tight">Account list</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950/50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Name</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Username</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Role</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Vehicle</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Status</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php foreach ($users as $listedUser): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium"><?php echo h($listedUser['full_name']); ?></td>
                            <td class="px-6 py-4"><?php echo h($listedUser['username']); ?></td>
                            <td class="px-6 py-4"><?php echo h(ucfirst($listedUser['user_type'])); ?></td>
                            <td class="px-6 py-4"><?php echo h($listedUser['vehicle_type']); ?></td>
                            <td class="px-6 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold <?php echo $listedUser['is_active'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/70 dark:text-rose-300'; ?>">
                                    <?php echo $listedUser['is_active'] ? 'Active' : 'Disabled'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <a href="<?php echo h(url('admin/users.php?edit=' . (int) $listedUser['id'])); ?>" class="text-sky-600 hover:text-sky-700 dark:text-sky-400">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_page_end(); ?>
