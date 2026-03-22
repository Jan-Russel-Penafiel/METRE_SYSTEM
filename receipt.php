<?php

require_once __DIR__ . '/includes/auth.php';

$user = require_login(['driver', 'admin']);
$tripId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($tripId <= 0) {
    set_flash('Trip receipt not found.', 'error');
    redirect_to($user['user_type'] === 'admin' ? 'admin/reports.php' : 'history.php');
}

$trip = find_trip_by_id($tripId);

if (!$trip) {
    set_flash('Trip receipt not found.', 'error');
    redirect_to($user['user_type'] === 'admin' ? 'admin/reports.php' : 'history.php');
}

if ($user['user_type'] === 'driver' && (int) $trip['driver_id'] !== (int) $user['id']) {
    set_flash('You do not have permission to open that receipt.', 'error');
    redirect_to('history.php');
}

$breakdown = json_decode($trip['fare_breakdown_json'] ?? '{}', true);
$routePoints = json_decode($trip['route_points_json'] ?? '[]', true);
$routeCount = is_array($routePoints) ? count($routePoints) : 0;

render_page_start('Receipt', [
    'page_id' => 'receipt',
    'preconnect_origins' => [
        'https://nominatim.openstreetmap.org',
    ],
]);
?>
<div class="mx-auto max-w-4xl space-y-6">
    <section class="rounded-xl border border-zinc-800 bg-zinc-950 px-6 py-8 text-white sm:px-8 print:bg-white print:text-zinc-900 print:shadow-none">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm uppercase tracking-wide text-zinc-400 print:text-zinc-500">Trip receipt</p>
                <h1 class="mt-3 text-3xl font-black tracking-tight">Fare summary</h1>
                <p class="mt-2 text-sm text-zinc-400 print:text-zinc-600">Trip #<?php echo (int) $trip['id']; ?> for <?php echo h($trip['full_name']); ?></p>
            </div>
            <button type="button" onclick="window.print()" class="rounded-md bg-white px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-200 print:hidden">
                Print receipt
            </button>
        </div>
    </section>

    <section class="grid gap-6 md:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-lg font-bold tracking-tight">Trip details</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Driver</dt>
                    <dd class="font-medium"><?php echo h($trip['full_name']); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Vehicle type</dt>
                    <dd class="font-medium"><?php echo h($trip['vehicle_type']); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Started</dt>
                    <dd class="font-medium"><?php echo h(date('M d, Y h:i A', strtotime($trip['started_at']))); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Ended</dt>
                    <dd class="font-medium"><?php echo h(date('M d, Y h:i A', strtotime($trip['ended_at']))); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Distance</dt>
                    <dd class="font-medium"><?php echo h(format_distance_with_km($trip['total_meters'])); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Waiting time</dt>
                    <dd class="font-medium"><?php echo h(format_duration($trip['waiting_seconds'])); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Stored route points</dt>
                    <dd class="font-medium"><?php echo (int) $routeCount; ?></dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-lg font-bold tracking-tight">Fare breakdown</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Distance fare</dt>
                    <dd class="font-medium"><?php echo h(format_currency($breakdown['distance_fare'] ?? 0)); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Waiting fare</dt>
                    <dd class="font-medium"><?php echo h(format_currency($breakdown['waiting_fare'] ?? 0)); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Night surcharge</dt>
                    <dd class="font-medium"><?php echo h(format_currency($breakdown['night_surcharge'] ?? 0)); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">Minimum fare adjustment</dt>
                    <dd class="font-medium"><?php echo h(format_currency($breakdown['minimum_adjustment'] ?? 0)); ?></dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-t border-zinc-200 pt-4 text-base dark:border-zinc-800">
                    <dt class="font-medium">Final fare</dt>
                    <dd class="text-2xl font-bold text-zinc-900 dark:text-zinc-50"><?php echo h(format_currency($trip['final_fare'])); ?></dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="text-lg font-bold tracking-tight">Route notes</h2>
        <?php if ($trip['start_lat'] !== null || $trip['end_lat'] !== null): ?>
            <div class="mt-4 grid gap-3 text-sm text-zinc-600 dark:text-zinc-300 md:grid-cols-2">
                <?php if ($trip['start_lat'] !== null && $trip['start_lng'] !== null): ?>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Start location</div>
                        <div
                            class="mt-2 font-medium"
                            data-location-output
                            data-lat="<?php echo h((string) $trip['start_lat']); ?>"
                            data-lng="<?php echo h((string) $trip['start_lng']); ?>"
                        >
                            Resolving start location...
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($trip['end_lat'] !== null && $trip['end_lng'] !== null): ?>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">End location</div>
                        <div
                            class="mt-2 font-medium"
                            data-location-output
                            data-lat="<?php echo h((string) $trip['end_lat']); ?>"
                            data-lng="<?php echo h((string) $trip['end_lng']); ?>"
                        >
                            Resolving end location...
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">No route location summary was stored for this trip.</p>
        <?php endif; ?>
    </section>
</div>

<?php render_page_end(); ?>
