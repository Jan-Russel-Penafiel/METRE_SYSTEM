<?php

require_once __DIR__ . '/includes/functions.php';

$token = normalize_tracking_token($_GET['token'] ?? '');

$trackerConfig = [
    'token' => $token,
    'trackingStatusUrl' => api_url('api/tracking_status.php'),
    'mapStyle' => map_style_config(),
];

render_page_start('Track Your Ride', [
    'hide_nav' => true,
    'page_id' => 'tracker',
    'page_scripts' => ['assets/js/tracker.js'],
    'needs_maplibre' => true,
    'preconnect_origins' => [
        'https://unpkg.com',
        'https://tile.openstreetmap.org',
        'https://nominatim.openstreetmap.org',
    ],
]);
?>
<div class="mx-auto max-w-6xl space-y-8">
    <section class="rounded-xl border border-zinc-800 bg-zinc-950 px-6 py-10 text-white sm:px-8">
        <div class="grid gap-8 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
            <div>
                <p class="text-sm uppercase tracking-wide text-zinc-400">Passenger tracking</p>
                <h1 class="mt-3 text-4xl font-black tracking-tight sm:text-5xl">Track your ride in real time.</h1>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-zinc-400 sm:text-base">
                    Open this page from any device to see the driver's current position, route line, trip status, and the latest fare snapshot.
                </p>
            </div>
            <form method="get" class="rounded-lg border border-zinc-700 bg-zinc-900 p-5">
                <label for="token" class="mb-2 block text-sm font-medium uppercase tracking-wide text-zinc-300">Tracking code</label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <input id="token" name="token" type="text" value="<?php echo h($token); ?>" placeholder="Paste the 4-digit code or shared link" class="block w-full rounded-md border border-zinc-600 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-300 focus:ring-zinc-300">
                    <button type="submit" class="rounded-md bg-zinc-50 px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-200">Track Trip</button>
                </div>
                <p id="tracking-page-message" class="mt-3 text-sm text-zinc-400">
                    <?php echo $token ? 'Loading trip status...' : 'Paste a 4-digit code or shared link to load the live map.'; ?>
                </p>
            </form>
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold tracking-tight">Live map</h2>
                    <p id="tracking-map-status" class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Waiting for live trip data.</p>
                </div>
                <span class="rounded-md bg-zinc-100 px-2.5 py-1 text-xs font-medium uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <span id="tracking-route-count">0</span> points
                </span>
            </div>
            <div id="tracking-map" class="mt-5 h-[28rem] overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800"></div>
        </div>

        <div class="space-y-6">
            <section class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold tracking-tight">Trip summary</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Public snapshot for the current or most recently completed trip.</p>
                    </div>
                    <span id="tracking-status-badge" class="rounded-md bg-zinc-100 px-2.5 py-1 text-xs font-medium uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Idle</span>
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2 text-sm">
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <dt class="text-zinc-500 dark:text-zinc-400">Driver</dt>
                        <dd id="tracking-driver-name" class="mt-2 text-lg font-bold">Waiting...</dd>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <dt class="text-zinc-500 dark:text-zinc-400">Vehicle</dt>
                        <dd id="tracking-vehicle-type" class="mt-2 text-lg font-bold">Waiting...</dd>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <dt class="text-zinc-500 dark:text-zinc-400">Distance</dt>
                        <dd id="tracking-distance" class="mt-2 text-lg font-bold">0 m</dd>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <dt class="text-zinc-500 dark:text-zinc-400">Fare</dt>
                        <dd id="tracking-fare" class="mt-2 text-lg font-bold text-zinc-900 dark:text-zinc-50">PHP 0.00</dd>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <dt class="text-zinc-500 dark:text-zinc-400">Started</dt>
                        <dd id="tracking-started-at" class="mt-2 font-medium">Not started</dd>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900">
                        <dt class="text-zinc-500 dark:text-zinc-400">Last update</dt>
                        <dd id="tracking-updated-at" class="mt-2 font-medium">Waiting...</dd>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-4 dark:bg-zinc-900 sm:col-span-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">Current location</dt>
                        <dd id="tracking-last-location" class="mt-2 font-medium">No live location yet.</dd>
                    </div>
                </dl>
            </section>
        </div>
    </section>
</div>
<script>
    window.TRACKER_CONFIG = <?php echo json_encode($trackerConfig); ?>;
</script>

<?php render_page_end(); ?>
