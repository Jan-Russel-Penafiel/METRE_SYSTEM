<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['admin']);

$editingId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editingUser = $editingId > 0 ? find_user_by_id($editingId) : null;

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

    if (username_exists($username, $id)) {
        set_flash('That username is already in use.', 'error');
        redirect_to('admin/users.php' . ($id ? '?edit=' . $id : ''));
    }

    if ($id > 0) {
        $attributes = [
            'full_name' => $fullName,
            'username' => $username,
            'user_type' => $userType,
            'vehicle_type' => $vehicleType,
            'is_active' => $isActive,
        ];

        if ($password !== '') {
            $attributes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        save_user_record($id, $attributes);
        set_flash('User account updated.', 'success');
    } else {
        if ($password === '') {
            set_flash('Password is required for new accounts.', 'error');
            redirect_to('admin/users.php');
        }

        save_user_record(0, [
            'full_name' => $fullName,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'user_type' => $userType,
            'vehicle_type' => $vehicleType,
            'is_active' => $isActive,
        ]);
        set_flash('User account created.', 'success');
    }

    redirect_to('admin/users.php');
}

$vehicleTypeOptions = get_vehicle_type_options();
$users = list_users();

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
    <section class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
        <div class="mb-6">
            <h1 class="text-3xl font-bold tracking-tight">User accounts</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Create admin and driver accounts with per-driver vehicle defaults.</p>
        </div>

        <form method="post" class="space-y-5">
            <input type="hidden" name="id" value="<?php echo (int) $editingUser['id']; ?>">

            <div>
                <label for="full_name" class="mb-1.5 block text-sm font-medium">Full name</label>
                <input id="full_name" name="full_name" type="text" value="<?php echo h($editingUser['full_name']); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" required>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="username" class="mb-1.5 block text-sm font-medium">Username</label>
                    <input id="username" name="username" type="text" value="<?php echo h($editingUser['username']); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" required>
                </div>
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium">Password <?php echo $editingUser['id'] ? '(leave blank to keep current)' : ''; ?></label>
                    <input id="password" name="password" type="password" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" <?php echo $editingUser['id'] ? '' : 'required'; ?>>
                </div>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="user_type" class="mb-1.5 block text-sm font-medium">Role</label>
                    <select id="user_type" name="user_type" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950">
                        <option value="driver" <?php echo $editingUser['user_type'] === 'driver' ? 'selected' : ''; ?>>Driver</option>
                        <option value="admin" <?php echo $editingUser['user_type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div>
                    <label for="vehicle_type" class="mb-1.5 block text-sm font-medium">Default vehicle type</label>
                    <select id="vehicle_type" name="vehicle_type" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950">
                        <?php foreach ($vehicleTypeOptions as $option): ?>
                            <option value="<?php echo h($option); ?>" <?php echo $editingUser['vehicle_type'] === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="flex items-center gap-3 rounded-md border border-zinc-200 px-4 py-3 text-sm dark:border-zinc-800">
                <input type="checkbox" name="is_active" value="1" class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900" <?php echo !empty($editingUser['is_active']) ? 'checked' : ''; ?>>
                <span>Account active</span>
            </label>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200">
                    <?php echo $editingUser['id'] ? 'Update User' : 'Create User'; ?>
                </button>
                <?php if ($editingUser['id']): ?>
                    <a href="<?php echo h(url('admin/users.php')); ?>" class="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-5 dark:border-zinc-800">
            <h2 class="text-xl font-bold tracking-tight">Account list</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Name</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Username</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Role</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Vehicle</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Status</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <?php foreach ($users as $listedUser): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium"><?php echo h($listedUser['full_name']); ?></td>
                            <td class="px-6 py-4"><?php echo h($listedUser['username']); ?></td>
                            <td class="px-6 py-4"><?php echo h(ucfirst($listedUser['user_type'])); ?></td>
                            <td class="px-6 py-4"><?php echo h($listedUser['vehicle_type']); ?></td>
                            <td class="px-6 py-4">
                                <span class="rounded-md px-2.5 py-1 text-xs font-medium <?php echo $listedUser['is_active'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-300'; ?>">
                                    <?php echo $listedUser['is_active'] ? 'Active' : 'Disabled'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <a href="<?php echo h(url('admin/users.php?edit=' . (int) $listedUser['id'])); ?>" class="text-zinc-900 hover:text-zinc-700 dark:text-zinc-100">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_page_end(); ?>
