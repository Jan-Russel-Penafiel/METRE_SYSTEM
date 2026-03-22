<?php

require_once __DIR__ . '/includes/auth.php';

$user = require_login(['driver', 'admin']);

$dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'))));
$dateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-d')));

$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'limit' => 200,
];

if ($user['user_type'] === 'driver') {
    $filters['driver_id'] = (int) $user['id'];
}

$trips = list_trips_filtered($filters);

render_page_start('Trip History');
?>
<div class="space-y-8">
    <section class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-3xl font-black tracking-tight">Trip history</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    <?php echo $user['user_type'] === 'driver' ? 'Review your completed trips and reopen printable receipts.' : 'Review completed trips across all drivers.'; ?>
                </p>
            </div>
            <?php if ($user['user_type'] === 'admin'): ?>
                <a href="<?php echo h(url('admin/reports.php')); ?>" class="inline-flex rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">Open reports</a>
            <?php endif; ?>
        </div>

        <form method="get" class="mt-6 grid gap-4 md:grid-cols-3">
            <div>
                <label for="date_from" class="mb-1.5 block text-sm font-medium">From</label>
                <input id="date_from" name="date_from" type="date" value="<?php echo h($dateFrom); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950">
            </div>
            <div>
                <label for="date_to" class="mb-1.5 block text-sm font-medium">To</label>
                <input id="date_to" name="date_to" type="date" value="<?php echo h($dateTo); ?>" class="block w-full rounded-md border border-zinc-200 px-3 py-2 focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200">Apply filter</button>
            </div>
        </form>
    </section>

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php if (!$trips): ?>
            <div class="col-span-full rounded-lg border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                No trip records were found for the selected date range.
            </div>
        <?php endif; ?>
        <?php foreach ($trips as $trip): ?>
            <article class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold"><?php echo h($trip['vehicle_type']); ?></h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"><?php echo h(date('M d, Y h:i A', strtotime($trip['started_at']))); ?></p>
                    </div>
                    <div class="text-right text-lg font-bold"><?php echo h(format_currency($trip['final_fare'])); ?></div>
                </div>
                <?php if ($user['user_type'] === 'admin'): ?>
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">Driver: <?php echo h($trip['full_name']); ?></p>
                <?php endif; ?>
                <div class="mt-4 grid gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <div>Distance: <?php echo h(format_distance_with_km($trip['total_meters'])); ?></div>
                    <div>Waiting time: <?php echo h(format_duration($trip['waiting_seconds'])); ?></div>
                    <div>Ended: <?php echo h(date('M d, Y h:i A', strtotime($trip['ended_at']))); ?></div>
                </div>
                <a href="<?php echo h(url('receipt.php?id=' . (int) $trip['id'])); ?>" class="mt-5 inline-flex rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200">Open receipt</a>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<?php render_page_end(); ?>
