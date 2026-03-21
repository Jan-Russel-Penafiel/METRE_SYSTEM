<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['admin']);

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$trackingReady = ensure_tracking_schema();

$todayStats = db_select_one(
    'SELECT COUNT(*) AS trip_count, COALESCE(SUM(final_fare), 0) AS revenue, COALESCE(AVG(final_fare), 0) AS average_fare FROM trips WHERE DATE(started_at) = ?',
    's',
    [$today]
);

$weekStats = db_select_one(
    'SELECT COUNT(*) AS trip_count, COALESCE(SUM(final_fare), 0) AS revenue FROM trips WHERE DATE(started_at) BETWEEN ? AND ?',
    'ss',
    [$weekStart, $weekEnd]
);

$driverCount = db_select_one("SELECT COUNT(*) AS total FROM users WHERE user_type = 'driver' AND is_active = 1");
$fareCount = db_select_one('SELECT COUNT(*) AS total FROM fare_settings');
$activeTracking = $trackingReady
    ? db_select_all(
        "SELECT ltt.public_tracking_token, ltt.vehicle_type, ltt.status, ltt.updated_at, u.full_name
         FROM live_trip_tracking ltt
         INNER JOIN users u ON u.id = ltt.driver_id
         WHERE ltt.status IN ('waiting', 'in_trip')
         ORDER BY ltt.updated_at DESC
         LIMIT 8"
    )
    : [];
$recentTrips = db_select_all(
    'SELECT t.id, t.started_at, t.total_meters, t.final_fare, u.full_name
     FROM trips t
     INNER JOIN users u ON u.id = t.driver_id
     ORDER BY t.id DESC
     LIMIT 6'
);

render_page_start('Admin Dashboard');
?>
<div class="space-y-8">
    <section class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr]">
        <div class="rounded-3xl bg-slate-900 px-6 py-8 text-white shadow-xl sm:px-8">
            <p class="text-sm uppercase tracking-[0.2em] text-sky-200">Operations overview</p>
            <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Dispatch and fare controls in one place.</h1>
            <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">
                Update fare rules, monitor completed trips, and export period reports from a mobile-friendly admin console.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="rounded-2xl bg-sky-500 px-4 py-3 text-sm font-semibold text-white hover:bg-sky-400">Manage Fare Settings</a>
                <a href="<?php echo h(url('admin/reports.php')); ?>" class="rounded-2xl border border-white/15 px-4 py-3 text-sm font-semibold text-white hover:bg-white/10">Open Reports</a>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="text-sm text-slate-500 dark:text-slate-400">Active drivers</div>
                <div class="mt-2 text-3xl font-black"><?php echo (int) ($driverCount['total'] ?? 0); ?></div>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="text-sm text-slate-500 dark:text-slate-400">Configured vehicle fares</div>
                <div class="mt-2 text-3xl font-black"><?php echo (int) ($fareCount['total'] ?? 0); ?></div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Trips today</div>
            <div class="mt-2 text-3xl font-black"><?php echo (int) ($todayStats['trip_count'] ?? 0); ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Revenue today</div>
            <div class="mt-2 text-3xl font-black"><?php echo h(format_currency($todayStats['revenue'] ?? 0)); ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Weekly trips</div>
            <div class="mt-2 text-3xl font-black"><?php echo (int) ($weekStats['trip_count'] ?? 0); ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Average fare today</div>
            <div class="mt-2 text-3xl font-black"><?php echo h(format_currency($todayStats['average_fare'] ?? 0)); ?></div>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-5 dark:border-slate-800">
            <div>
                <h2 class="text-xl font-bold tracking-tight">Generated 4-digit tracking codes</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Admins can share these 4-digit live trip codes instead of the full tracking link.</p>
            </div>
            <a href="<?php echo h(url('index.php')); ?>" class="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Open tracker</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950/50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Driver</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Vehicle</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Status</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Updated</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Code</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Track</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php if (!$activeTracking): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">No live 4-digit tracking codes are available yet. A code appears after a driver starts syncing a trip.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($activeTracking as $tracking): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium"><?php echo h($tracking['full_name']); ?></td>
                            <td class="px-6 py-4"><?php echo h($tracking['vehicle_type']); ?></td>
                            <td class="px-6 py-4"><?php echo h(str_replace('_', ' ', $tracking['status'])); ?></td>
                            <td class="px-6 py-4"><?php echo h(date('M d, Y h:i A', strtotime($tracking['updated_at']))); ?></td>
                            <td class="px-6 py-4"><code class="rounded-lg bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800"><?php echo h($tracking['public_tracking_token']); ?></code></td>
                            <td class="px-6 py-4">
                                <a href="<?php echo h(url('index.php?token=' . $tracking['public_tracking_token'])); ?>" class="text-sky-600 hover:text-sky-700 dark:text-sky-400">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-5 dark:border-slate-800">
            <div>
                <h2 class="text-xl font-bold tracking-tight">Recent trips</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Latest completed fares across all drivers.</p>
            </div>
            <a href="<?php echo h(url('admin/reports.php')); ?>" class="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">View full report</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 dark:bg-slate-950/50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Driver</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Started</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Distance</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Fare</th>
                        <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Receipt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php if (!$recentTrips): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">No trips recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($recentTrips as $trip): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium"><?php echo h($trip['full_name']); ?></td>
                            <td class="px-6 py-4"><?php echo h(date('M d, Y h:i A', strtotime($trip['started_at']))); ?></td>
                            <td class="px-6 py-4"><?php echo h(format_distance_with_km($trip['total_meters'])); ?></td>
                            <td class="px-6 py-4 font-semibold"><?php echo h(format_currency($trip['final_fare'])); ?></td>
                            <td class="px-6 py-4">
                                <a href="<?php echo h(url('receipt.php?id=' . (int) $trip['id'])); ?>" class="text-sky-600 hover:text-sky-700 dark:text-sky-400">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_page_end(); ?>