<?php

require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect_to(current_user()['user_type'] === 'admin' ? 'admin/index.php' : 'meter.php');
}

$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_login($username, $password)) {
        $user = current_user();
        set_flash('Welcome back, ' . $user['full_name'] . '.', 'success');
        redirect_to($user['user_type'] === 'admin' ? 'admin/index.php' : 'meter.php');
    }

    set_flash('Invalid username or password.', 'error');
}

render_page_start('Login', ['hide_nav' => true]);
?>
<div class="grid min-h-[calc(100vh-8rem)] gap-8 lg:grid-cols-[1.1fr_0.9fr]">
    <section class="flex flex-col justify-center rounded-xl border border-zinc-800 bg-zinc-950 px-6 py-10 text-white sm:px-10">
        <span class="inline-flex w-fit rounded-md bg-white/10 px-3 py-1 text-xs font-medium uppercase tracking-wide text-zinc-300">
            Browser-Based Fare Meter
        </span>
        <h1 class="mt-6 max-w-xl text-4xl font-black tracking-tight sm:text-5xl">
            Procedural PHP taxi meter for drivers and dispatch admins.
        </h1>
        <p class="mt-4 max-w-2xl text-base leading-7 text-zinc-400">
            Live GPS-driven metering, configurable fare rules, printable receipts, and daily reporting in one mobile-ready web app.
        </p>
        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4">
                <div class="text-sm font-medium text-zinc-200">Geolocation</div>
                <div class="mt-2 text-sm text-zinc-400">Manual trip start with live GPS and browser-based idle tracking.</div>
            </div>
            <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4">
                <div class="text-sm font-medium text-zinc-200">Server Sync</div>
                <div class="mt-2 text-sm text-zinc-400">Fare logic stays enforced on PHP with JSON-backed storage.</div>
            </div>
            <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4">
                <div class="text-sm font-medium text-zinc-200">Responsive UI</div>
                <div class="mt-2 text-sm text-zinc-400">Built for phone, tablet, and desktop browsers.</div>
            </div>
        </div>
    </section>

    <section class="flex items-center">
        <div class="w-full rounded-lg border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
            <div class="mb-6">
                <h2 class="text-2xl font-bold tracking-tight">Sign in</h2>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Default prototype accounts are seeded in <code>data/users.json</code>.
                </p>
            </div>

            <form method="post" class="space-y-5">
                <div>
                    <label for="username" class="mb-1.5 block text-sm font-medium">Username</label>
                    <input
                        id="username"
                        name="username"
                        type="text"
                        value="<?php echo h($username); ?>"
                        class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950"
                        required
                    >
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950"
                        required
                    >
                </div>

                <button type="submit" class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200">
                    Login
                </button>
            </form>

            <div class="mt-6 rounded-lg border border-dashed border-zinc-300 bg-zinc-50 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <div class="font-medium">Prototype logins</div>
                <div class="mt-2">Admin: <code>admin</code> / <code>admin123</code></div>
                <div class="mt-1">Driver: <code>driver1</code> / <code>driver123</code></div>
            </div>
        </div>
    </section>
</div>
<?php render_page_end(); ?>
