<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['admin']);

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$trackingReady = ensure_tracking_schema();

$todayStats = summarize_trips([
    'date_from' => $today,
    'date_to' => $today,
]);

$weekStats = summarize_trips([
    'date_from' => $weekStart,
    'date_to' => $weekEnd,
]);

$driverCount = count_active_drivers();
$fareCount = count_fare_settings_records();
$activeTracking = $trackingReady ? list_active_live_tracking(8) : [];
$recentTrips = list_trips_filtered([
    'limit' => 6,
]);

render_page_start('Admin Dashboard');
?>
<div class="space-y-6 sm:space-y-8">
    <section class="grid gap-4 sm:gap-6 lg:grid-cols-[1.25fr_0.75fr]">
        <div class="rounded-xl border border-zinc-800 bg-zinc-950 px-4 py-6 text-white sm:px-6 sm:py-8 md:px-8">
            <p class="text-xs uppercase tracking-wide text-zinc-400 sm:text-sm">Operations overview</p>
            <h1 class="mt-2 text-2xl font-black tracking-tight sm:mt-3 sm:text-3xl md:text-4xl">Dispatch and fare controls in one place.</h1>
            <div class="mt-5 flex flex-col gap-2 sm:mt-6 sm:flex-row sm:flex-wrap sm:gap-3">
                <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="rounded-md bg-zinc-50 px-4 py-2 text-center text-sm font-medium text-zinc-900 hover:bg-zinc-200">Manage Fare Settings</a>
                <a href="<?php echo h(url('admin/reports.php')); ?>" class="rounded-md border border-zinc-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-zinc-800">Open Reports</a>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-1">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Active drivers</div>
                <div class="mt-1.5 text-2xl font-bold sm:mt-2 sm:text-3xl"><?php echo (int) $driverCount; ?></div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Vehicle fares</div>
                <div class="mt-1.5 text-2xl font-bold sm:mt-2 sm:text-3xl"><?php echo (int) $fareCount; ?></div>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Trips today</div>
            <div class="mt-1.5 text-2xl font-bold sm:mt-2 sm:text-3xl"><?php echo (int) ($todayStats['trip_count'] ?? 0); ?></div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Revenue today</div>
            <div class="mt-1.5 text-xl font-bold sm:mt-2 sm:text-3xl"><?php echo h(format_currency($todayStats['revenue'] ?? 0)); ?></div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Weekly trips</div>
            <div class="mt-1.5 text-2xl font-bold sm:mt-2 sm:text-3xl"><?php echo (int) ($weekStats['trip_count'] ?? 0); ?></div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Avg fare today</div>
            <div class="mt-1.5 text-xl font-bold sm:mt-2 sm:text-3xl"><?php echo h(format_currency($todayStats['average_fare'] ?? 0)); ?></div>
        </div>
    </section>

    <section class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 border-b border-zinc-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-6 sm:py-5 dark:border-zinc-800">
            <div>
                <h2 class="text-lg font-bold tracking-tight sm:text-xl">4-digit tracking codes</h2>
                <p class="mt-0.5 text-xs text-zinc-500 sm:mt-1 sm:text-sm dark:text-zinc-400">Share these live trip codes instead of full tracking links.</p>
            </div>
            <a href="<?php echo h(url('index.php')); ?>" class="self-start rounded-md border border-zinc-300 px-3 py-2 text-xs font-medium hover:bg-zinc-100 sm:px-4 sm:text-sm dark:border-zinc-700 dark:hover:bg-zinc-800">Open tracker</a>
        </div>

        <!-- Mobile card view -->
        <div class="space-y-3 p-4 lg:hidden">
            <?php if (!$activeTracking): ?>
                <div class="py-6 text-center text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">No live tracking codes available yet.</div>
            <?php endif; ?>
            <?php foreach ($activeTracking as $tracking): ?>
                <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium text-sm"><?php echo h($tracking['full_name']); ?></div>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"><?php echo h($tracking['vehicle_type']); ?></div>
                        </div>
                        <code class="rounded-md bg-zinc-200 px-2 py-1 text-xs font-bold dark:bg-zinc-700"><?php echo h($tracking['public_tracking_token']); ?></code>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                        <span class="text-zinc-500 dark:text-zinc-400"><?php echo h(str_replace('_', ' ', $tracking['status'])); ?></span>
                        <a href="<?php echo h(url('index.php?token=' . $tracking['public_tracking_token'])); ?>" class="text-zinc-900 hover:text-zinc-700 dark:text-zinc-100">Track</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop table view -->
        <div class="hidden overflow-x-auto lg:block">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Driver</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Vehicle</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Status</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Updated</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Code</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Track</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <?php if (!$activeTracking): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-zinc-500 dark:text-zinc-400">No live 4-digit tracking codes are available yet. A code appears after a driver starts syncing a trip.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($activeTracking as $tracking): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium"><?php echo h($tracking['full_name']); ?></td>
                            <td class="px-6 py-4"><?php echo h($tracking['vehicle_type']); ?></td>
                            <td class="px-6 py-4"><?php echo h(str_replace('_', ' ', $tracking['status'])); ?></td>
                            <td class="px-6 py-4"><?php echo h(date('M d, Y h:i A', strtotime($tracking['updated_at']))); ?></td>
                            <td class="px-6 py-4"><code class="rounded-md bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800"><?php echo h($tracking['public_tracking_token']); ?></code></td>
                            <td class="px-6 py-4">
                                <a href="<?php echo h(url('index.php?token=' . $tracking['public_tracking_token'])); ?>" class="text-zinc-900 hover:text-zinc-700 dark:text-zinc-100">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 border-b border-zinc-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-6 sm:py-5 dark:border-zinc-800">
            <div>
                <h2 class="text-lg font-bold tracking-tight sm:text-xl">Recent trips</h2>
                <p class="mt-0.5 text-xs text-zinc-500 sm:mt-1 sm:text-sm dark:text-zinc-400">Latest completed fares across all drivers.</p>
            </div>
            <a href="<?php echo h(url('admin/reports.php')); ?>" class="self-start rounded-md border border-zinc-300 px-3 py-2 text-xs font-medium hover:bg-zinc-100 sm:px-4 sm:text-sm dark:border-zinc-700 dark:hover:bg-zinc-800">View full report</a>
        </div>

        <!-- Mobile card view -->
        <div class="space-y-3 p-4 lg:hidden">
            <?php if (!$recentTrips): ?>
                <div class="py-6 text-center text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">No trips recorded yet.</div>
            <?php endif; ?>
            <?php foreach ($recentTrips as $trip): ?>
                <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 sm:p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium text-sm"><?php echo h($trip['full_name']); ?></div>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"><?php echo h(date('M d, h:i A', strtotime($trip['started_at']))); ?></div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-sm"><?php echo h(format_currency($trip['final_fare'])); ?></div>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"><?php echo h(format_distance_with_km($trip['total_meters'])); ?></div>
                        </div>
                    </div>
                    <div class="mt-2 flex justify-end">
                        <a href="<?php echo h(url('receipt.php?id=' . (int) $trip['id'])); ?>" class="text-xs text-zinc-900 hover:text-zinc-700 dark:text-zinc-100">View Receipt</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop table view -->
        <div class="hidden overflow-x-auto lg:block">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Driver</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Started</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Distance</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Fare</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-zinc-500">Receipt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <?php if (!$recentTrips): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-zinc-500 dark:text-zinc-400">No trips recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($recentTrips as $trip): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium"><?php echo h($trip['full_name']); ?></td>
                            <td class="px-6 py-4"><?php echo h(date('M d, Y h:i A', strtotime($trip['started_at']))); ?></td>
                            <td class="px-6 py-4"><?php echo h(format_distance_with_km($trip['total_meters'])); ?></td>
                            <td class="px-6 py-4 font-medium"><?php echo h(format_currency($trip['final_fare'])); ?></td>
                            <td class="px-6 py-4">
                                <a href="<?php echo h(url('receipt.php?id=' . (int) $trip['id'])); ?>" class="text-zinc-900 hover:text-zinc-700 dark:text-zinc-100">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_page_end(); ?>
