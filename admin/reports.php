<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['admin']);

$dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-6 days'))));
$dateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-d')));
$search = trim((string) ($_GET['search'] ?? ''));
$export = trim((string) ($_GET['export'] ?? ''));

$where = ['DATE(t.started_at) BETWEEN ? AND ?'];
$types = 'ss';
$params = [$dateFrom, $dateTo];

if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR t.vehicle_type LIKE ?)';
    $types .= 'sss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sqlBase = ' FROM trips t INNER JOIN users u ON u.id = t.driver_id WHERE ' . implode(' AND ', $where);
$summary = db_select_one(
    'SELECT COUNT(*) AS trip_count, COALESCE(SUM(t.final_fare), 0) AS revenue, COALESCE(AVG(t.final_fare), 0) AS average_fare, COALESCE(SUM(t.total_meters), 0) AS total_meters' . $sqlBase,
    $types,
    $params
);

$trips = db_select_all(
    'SELECT t.id, t.started_at, t.ended_at, t.vehicle_type, t.total_meters, t.final_fare, t.waiting_seconds, u.full_name, u.username' . $sqlBase . ' ORDER BY t.started_at DESC LIMIT 300',
    $types,
    $params
);

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=metre-report-' . $dateFrom . '-to-' . $dateTo . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Trip ID', 'Driver', 'Username', 'Vehicle', 'Started At', 'Ended At', 'Meters', 'Waiting Seconds', 'Fare']);

    foreach ($trips as $trip) {
        fputcsv($output, [
            $trip['id'],
            $trip['full_name'],
            $trip['username'],
            $trip['vehicle_type'],
            $trip['started_at'],
            $trip['ended_at'],
            $trip['total_meters'],
            $trip['waiting_seconds'],
            $trip['final_fare'],
        ]);
    }

    fclose($output);
    exit;
}

$exportUrl = url('admin/reports.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'search' => $search,
    'export' => 'csv',
]));

render_page_start('Reports');
?>
<div class="space-y-8">
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-3xl font-black tracking-tight">Reports</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Filter completed trips by date range, search by driver, and export the result as CSV.</p>
            </div>
            <a href="<?php echo h($exportUrl); ?>" class="inline-flex rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">Export CSV</a>
        </div>

        <form method="get" class="mt-6 grid gap-4 md:grid-cols-4">
            <div>
                <label for="date_from" class="mb-2 block text-sm font-medium">From</label>
                <input id="date_from" name="date_from" type="date" value="<?php echo h($dateFrom); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950">
            </div>
            <div>
                <label for="date_to" class="mb-2 block text-sm font-medium">To</label>
                <input id="date_to" name="date_to" type="date" value="<?php echo h($dateTo); ?>" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950">
            </div>
            <div>
                <label for="search" class="mb-2 block text-sm font-medium">Search</label>
                <input id="search" name="search" type="text" value="<?php echo h($search); ?>" placeholder="Driver, username, vehicle" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-2xl bg-sky-600 px-4 py-3 text-sm font-semibold text-white hover:bg-sky-700">Apply filters</button>
            </div>
        </form>
    </section>

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Trips</div>
            <div class="mt-2 text-3xl font-black"><?php echo (int) ($summary['trip_count'] ?? 0); ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Revenue</div>
            <div class="mt-2 text-3xl font-black"><?php echo h(format_currency($summary['revenue'] ?? 0)); ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Average fare</div>
            <div class="mt-2 text-3xl font-black"><?php echo h(format_currency($summary['average_fare'] ?? 0)); ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="text-sm text-slate-500 dark:text-slate-400">Distance</div>
            <div class="mt-2 text-3xl font-black"><?php echo h(format_distance_with_km($summary['total_meters'] ?? 0)); ?></div>
        </div>
    </section>

    <section class="space-y-4 lg:hidden">
        <?php if (!$trips): ?>
            <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">No trips matched the selected filters.</div>
        <?php endif; ?>
        <?php foreach ($trips as $trip): ?>
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold"><?php echo h($trip['full_name']); ?></h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?php echo h($trip['vehicle_type']); ?></p>
                    </div>
                    <div class="text-right text-sm font-semibold"><?php echo h(format_currency($trip['final_fare'])); ?></div>
                </div>
                <div class="mt-4 grid gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <div>Started: <?php echo h(date('M d, Y h:i A', strtotime($trip['started_at']))); ?></div>
                    <div>Distance: <?php echo h(format_distance_with_km($trip['total_meters'])); ?></div>
                    <div>Waiting: <?php echo h(format_duration($trip['waiting_seconds'])); ?></div>
                    <a href="<?php echo h(url('receipt.php?id=' . (int) $trip['id'])); ?>" class="mt-2 text-sky-600 hover:text-sky-700 dark:text-sky-400">Open receipt</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="hidden overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 lg:block">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 dark:bg-slate-950/50">
                <tr>
                    <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Driver</th>
                    <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Vehicle</th>
                    <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Started</th>
                    <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Distance</th>
                    <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Waiting</th>
                    <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Fare</th>
                    <th class="px-6 py-3 text-left font-semibold uppercase tracking-wide text-slate-500">Receipt</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                <?php if (!$trips): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">No trips matched the selected filters.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($trips as $trip): ?>
                    <tr>
                        <td class="px-6 py-4">
                            <div class="font-medium"><?php echo h($trip['full_name']); ?></div>
                            <div class="text-slate-500 dark:text-slate-400"><?php echo h($trip['username']); ?></div>
                        </td>
                        <td class="px-6 py-4"><?php echo h($trip['vehicle_type']); ?></td>
                        <td class="px-6 py-4"><?php echo h(date('M d, Y h:i A', strtotime($trip['started_at']))); ?></td>
                        <td class="px-6 py-4"><?php echo h(format_distance_with_km($trip['total_meters'])); ?></td>
                        <td class="px-6 py-4"><?php echo h(format_duration($trip['waiting_seconds'])); ?></td>
                        <td class="px-6 py-4 font-semibold"><?php echo h(format_currency($trip['final_fare'])); ?></td>
                        <td class="px-6 py-4"><a href="<?php echo h(url('receipt.php?id=' . (int) $trip['id'])); ?>" class="text-sky-600 hover:text-sky-700 dark:text-sky-400">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
<?php render_page_end(); ?>
