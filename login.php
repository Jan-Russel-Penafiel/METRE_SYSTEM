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

$loginPageHead = <<<'HTML'
<style>
body[data-page="login"] {
    background:
        radial-gradient(circle at top left, rgba(14, 165, 233, 0.14), transparent 30%),
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.1), transparent 24%),
        linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
}

html.dark body[data-page="login"] {
    background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 30%),
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.12), transparent 24%),
        linear-gradient(180deg, #09090b 0%, #111827 100%);
}

.login-panel {
    border: 1px solid rgba(228, 228, 231, 0.75);
    background: rgba(255, 255, 255, 0.78);
    box-shadow: 0 30px 80px rgba(15, 23, 42, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(18px);
}

.dark .login-panel {
    border-color: rgba(63, 63, 70, 0.88);
    background: rgba(9, 9, 11, 0.76);
    box-shadow: 0 30px 90px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.04);
}
</style>
HTML;

render_page_start('Login', [
    'hide_nav' => true,
    'page_id' => 'login',
    'body_class' => 'overflow-x-hidden',
    'extra_head' => $loginPageHead,
]);
?>
<div class="relative grid min-h-[calc(100vh-8rem)] gap-6 pb-8 lg:grid-cols-[1.08fr_0.92fr] lg:items-center">
    <div class="pointer-events-none absolute -left-20 top-0 h-56 w-56 rounded-full bg-sky-300/25 blur-3xl dark:bg-sky-500/10"></div>
    <div class="pointer-events-none absolute -right-16 top-24 h-64 w-64 rounded-full bg-cyan-300/25 blur-3xl dark:bg-cyan-400/10"></div>

    <section class="login-panel relative overflow-hidden rounded-[32px] px-6 py-8 text-zinc-950 sm:px-8 sm:py-10 dark:text-white">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-sky-400/70 to-transparent"></div>
        <span class="inline-flex w-fit rounded-md bg-zinc-950 px-3 py-1 text-xs font-medium uppercase tracking-wide text-white shadow-sm dark:bg-white/10 dark:text-zinc-200">
            SakayMeter
        </span>
        <h1 class="mt-6 max-w-xl text-4xl font-black tracking-tight sm:text-5xl">
            SakayMeter for drivers and dispatch admins.
        </h1>
        <p class="mt-4 max-w-2xl text-base leading-7 text-zinc-600 dark:text-zinc-300">
            Live GPS-driven metering, configurable fare rules, printable receipts, and daily reporting in one mobile-ready web app.
        </p>
    </section>

    <section class="login-panel rounded-[32px] px-6 py-7 sm:px-8 sm:py-9">
        <div class="mb-6 space-y-1">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Access portal</p>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Sign in</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">Use your driver or admin account to continue.</p>
        </div>

        <form method="post" class="space-y-5">
            <div>
                <label for="username" class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-200">Username</label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    value="<?php echo h($username); ?>"
                    class="block w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-950 shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                    required
                >
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-200">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    class="block w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-950 shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                    required
                >
            </div>

            <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-sky-400 px-4 py-3 text-sm font-medium text-slate-950 transition hover:bg-sky-300">
                Login
            </button>
        </form>
    </section>
</div>
<?php render_page_end(); ?>
