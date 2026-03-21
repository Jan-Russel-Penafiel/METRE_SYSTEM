<?php

require_once __DIR__ . '/includes/functions.php';

$token = normalize_tracking_token($_GET['token'] ?? '');
$mapHead = <<<'HTML'
<link href="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.css" rel="stylesheet">
<script src="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.js"></script>
HTML;

$trackerConfig = [
    'token' => $token,
    'trackingStatusUrl' => url('api/tracking_status.php'),
    'mapStyle' => map_style_config(),
];

render_page_start('Track Your Ride', ['hide_nav' => true, 'extra_head' => $mapHead]);
?>
<div class="mx-auto max-w-6xl space-y-8">
    <section class="rounded-[2rem] bg-slate-900 px-6 py-10 text-white shadow-xl sm:px-8">
        <div class="grid gap-8 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-sky-200">Passenger tracking</p>
                <h1 class="mt-3 text-4xl font-black tracking-tight sm:text-5xl">Track your ride in real time.</h1>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">
                    Open this page from any device to see the driver's current position, route line, trip status, and the latest fare snapshot.
                </p>
            </div>
            <form method="get" class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <label for="token" class="mb-2 block text-sm font-semibold uppercase tracking-[0.2em] text-sky-200">Tracking code</label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <input id="token" name="token" type="text" value="<?php echo h($token); ?>" placeholder="Paste the 4-digit code or shared link" class="block w-full rounded-2xl border-white/10 bg-white/90 px-4 py-3 text-sm text-slate-900 focus:border-sky-300 focus:ring-sky-300">
                    <button type="submit" class="rounded-2xl bg-sky-500 px-4 py-3 text-sm font-semibold text-white hover:bg-sky-400">Track Trip</button>
                </div>
                <p id="tracking-page-message" class="mt-3 text-sm text-slate-300">
                    <?php echo $token ? 'Loading trip status...' : 'Paste a 4-digit code or shared link to load the live map.'; ?>
                </p>
            </form>
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold tracking-tight">Live map</h2>
                    <p id="tracking-map-status" class="mt-1 text-sm text-slate-500 dark:text-slate-400">Waiting for live trip data.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <span id="tracking-route-count">0</span> points
                </span>
            </div>
            <div id="tracking-map" class="mt-5 h-[28rem] overflow-hidden rounded-3xl border border-slate-200 dark:border-slate-800"></div>
        </div>

        <div class="space-y-6">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold tracking-tight">Trip summary</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Public snapshot for the current or most recently completed trip.</p>
                    </div>
                    <span id="tracking-status-badge" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">Idle</span>
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2 text-sm">
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <dt class="text-slate-500 dark:text-slate-400">Driver</dt>
                        <dd id="tracking-driver-name" class="mt-2 text-lg font-bold">Waiting...</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <dt class="text-slate-500 dark:text-slate-400">Vehicle</dt>
                        <dd id="tracking-vehicle-type" class="mt-2 text-lg font-bold">Waiting...</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <dt class="text-slate-500 dark:text-slate-400">Distance</dt>
                        <dd id="tracking-distance" class="mt-2 text-lg font-bold">0 m</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <dt class="text-slate-500 dark:text-slate-400">Fare</dt>
                        <dd id="tracking-fare" class="mt-2 text-lg font-bold text-sky-600 dark:text-sky-400">PHP 0.00</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <dt class="text-slate-500 dark:text-slate-400">Started</dt>
                        <dd id="tracking-started-at" class="mt-2 font-semibold">Not started</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <dt class="text-slate-500 dark:text-slate-400">Last update</dt>
                        <dd id="tracking-updated-at" class="mt-2 font-semibold">Waiting...</dd>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60 sm:col-span-2">
                        <dt class="text-slate-500 dark:text-slate-400">Current location</dt>
                        <dd id="tracking-last-location" class="mt-2 font-semibold">No live location yet.</dd>
                    </div>
                </dl>
            </section>
        </div>
    </section>
</div>
<script>
    window.TRACKER_CONFIG = <?php echo json_encode($trackerConfig); ?>;
</script>
<script src="<?php echo h(url('assets/js/location-names.js')); ?>"></script>
<script src="<?php echo h(url('assets/js/tracker.js')); ?>"></script>
<?php render_page_end(); ?>