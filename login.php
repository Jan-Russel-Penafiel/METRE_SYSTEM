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
    <section class="flex flex-col justify-center rounded-3xl bg-slate-900 px-6 py-10 text-white shadow-xl sm:px-10">
        <span class="inline-flex w-fit rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-sky-200">
            Browser-Based Fare Meter
        </span>
        <h1 class="mt-6 max-w-xl text-4xl font-black tracking-tight sm:text-5xl">
            Procedural PHP taxi meter for drivers and dispatch admins.
        </h1>
        <p class="mt-4 max-w-2xl text-base leading-7 text-slate-300">
            Live GPS-driven metering, configurable fare rules, printable receipts, and daily reporting in one mobile-ready web app.
        </p>
        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-sky-200">Geolocation</div>
                <div class="mt-2 text-sm text-slate-300">Manual trip start with live GPS and browser-based idle tracking.</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-sky-200">Server Sync</div>
                <div class="mt-2 text-sm text-slate-300">Fare logic stays enforced on PHP and MySQL.</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-sky-200">Responsive UI</div>
                <div class="mt-2 text-sm text-slate-300">Built for phone, tablet, and desktop browsers.</div>
            </div>
        </div>
    </section>

    <section class="flex items-center">
        <div class="w-full rounded-3xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:p-8">
            <div class="mb-6">
                <h2 class="text-2xl font-bold tracking-tight">Sign in</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Default prototype accounts are included in <code>db.sql</code>.
                </p>
            </div>

            <form method="post" class="space-y-5">
                <div>
                    <label for="username" class="mb-2 block text-sm font-medium">Username</label>
                    <input
                        id="username"
                        name="username"
                        type="text"
                        value="<?php echo h($username); ?>"
                        class="block w-full rounded-2xl border-slate-300 bg-white px-4 py-3 text-base shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950"
                        required
                    >
                </div>

                <div>
                    <label for="password" class="mb-2 block text-sm font-medium">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        class="block w-full rounded-2xl border-slate-300 bg-white px-4 py-3 text-base shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950"
                        required
                    >
                </div>

                <button type="submit" class="w-full rounded-2xl bg-sky-600 px-4 py-3 text-base font-semibold text-white transition hover:bg-sky-700">
                    Login
                </button>
            </form>

            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">
                <div class="font-semibold">Prototype logins</div>
                <div class="mt-2">Admin: <code>admin</code> / <code>admin123</code></div>
                <div class="mt-1">Driver: <code>driver1</code> / <code>driver123</code></div>
            </div>
        </div>
    </section>
</div>
<?php render_page_end(); ?>
