<?php

require_once __DIR__ . '/includes/auth.php';

$user = require_login(['driver', 'admin']);
$fareSettings = get_fare_settings();
$vehicleTypeOptions = get_vehicle_type_options();
$defaultVehicleType = $user['vehicle_type'] ?: ($vehicleTypeOptions[0] ?? 'Standard Taxi');
$mapHead = <<<'HTML'
<link href="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.css" rel="stylesheet">
<script src="https://unpkg.com/maplibre-gl@5.16.0/dist/maplibre-gl.js"></script>
HTML;

$meterConfig = [
    'driverId' => (int) $user['id'],
    'driverName' => $user['full_name'],
    'storageKey' => 'metre_trip_' . (int) $user['id'],
    'defaultVehicleType' => $defaultVehicleType,
    'fareSettings' => array_values(array_map(function ($setting) {
        return [
            'vehicle_type' => $setting['vehicle_type'],
            'rate_per_meter' => (float) $setting['rate_per_meter'],
            'minimum_fare' => (float) $setting['minimum_fare'],
            'night_surcharge_percent' => (float) $setting['night_surcharge_percent'],
            'waiting_rate_per_minute' => (float) $setting['waiting_rate_per_minute'],
        ];
    }, $fareSettings)),
    'updateFareUrl' => url('api/update_fare.php'),
    'endTripUrl' => url('api/end_trip.php'),
    'distanceUrl' => url('api/calculate_distance.php'),
    'trackingBaseUrl' => absolute_url('index.php'),
    'mapStyle' => map_style_config(),
    'idleTimeoutSeconds' => TRIP_IDLE_TIMEOUT_SECONDS,
    'hasFareSettings' => !empty($fareSettings),
];

render_page_start('Live Meter', ['extra_head' => $mapHead]);
?>
<div class="space-y-8">
    <section class="rounded-[2rem] bg-slate-900 px-6 py-8 text-white shadow-xl sm:px-8">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-sm uppercase tracking-[0.25em] text-sky-200">Driver meter</p>
                <h1 class="mt-3 text-4xl font-black tracking-tight sm:text-5xl">Live fare tracking</h1>
                <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-300 sm:text-base">
                    MapLibre drives the live map, and each active trip can publish a 4-digit passenger tracking code.
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:w-[24rem]">
                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-200">Trip status</div>
                    <div id="meter-status" class="mt-2 text-lg font-bold">Waiting to start...</div>
                </div>
                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-200">Last sync</div>
                    <div id="meter-last-sync" class="mt-2 text-sm font-medium text-slate-200">Not synced yet</div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!$fareSettings): ?>
        <div class="rounded-3xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
            No fare settings are configured yet. <?php if ($user['user_type'] === 'admin'): ?><a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="font-semibold underline">Create a fare rule first.</a><?php else: ?>Ask an admin to configure fare settings before starting trips.<?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="text-sm text-slate-500 dark:text-slate-400">Distance</div>
                    <div id="meter-distance" class="mt-3 text-4xl font-black tracking-tight">0 m</div>
                </article>
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="text-sm text-slate-500 dark:text-slate-400">Current fare</div>
                    <div id="meter-fare" class="mt-3 text-4xl font-black tracking-tight text-sky-600 dark:text-sky-400">PHP 0.00</div>
                </article>
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="text-sm text-slate-500 dark:text-slate-400">Waiting time</div>
                    <div id="meter-waiting" class="mt-3 text-4xl font-black tracking-tight">00:00</div>
                </article>
                <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="text-sm text-slate-500 dark:text-slate-400">Speed</div>
                    <div id="meter-speed" class="mt-3 text-4xl font-black tracking-tight">0.0</div>
                    <div class="mt-1 text-xs uppercase tracking-[0.2em] text-slate-400">km/h</div>
                </article>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-2xl font-black tracking-tight">Trip controls</h2>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Enable GPS, then start the trip manually once the current location is ready.</p>
                    </div>
                    <div id="sync-state" class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        Idle
                    </div>
                </div>

                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="meter-vehicle-type" class="mb-2 block text-sm font-medium">Vehicle type</label>
                        <select id="meter-vehicle-type" class="block w-full rounded-2xl border-slate-300 px-4 py-3 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950" <?php echo !$fareSettings ? 'disabled' : ''; ?>>
                            <?php foreach ($vehicleTypeOptions as $option): ?>
                                <option value="<?php echo h($option); ?>" <?php echo $defaultVehicleType === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <div class="mb-2 block text-sm font-medium">Location status</div>
                        <div id="permission-state" class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">Awaiting geolocation permission.</div>
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <button id="request-location-btn" type="button" class="w-full rounded-2xl bg-sky-600 px-4 py-3 text-base font-semibold text-white transition hover:bg-sky-700 sm:w-auto">
                        Enable GPS
                    </button>
                    <button id="force-start-btn" type="button" class="w-full rounded-2xl bg-emerald-600 px-4 py-3 text-base font-semibold text-white transition hover:bg-emerald-700 sm:w-auto" <?php echo !$fareSettings ? 'disabled' : ''; ?>>
                        Start Trip
                    </button>
                    <button id="end-trip-btn" type="button" class="w-full rounded-2xl bg-rose-600 px-4 py-3 text-base font-semibold text-white transition hover:bg-rose-700 sm:w-auto" disabled>
                        End Trip
                    </button>
                    <button id="reset-trip-btn" type="button" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-base font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800 sm:w-auto">
                        Reset
                    </button>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Started at</div>
                        <div id="trip-started-at" class="mt-2 text-sm font-medium">Not started</div>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Current location</div>
                        <div id="meter-latlng" class="mt-2 text-sm font-medium">No location yet</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-bold tracking-tight">Fare breakdown</h2>
                    <div id="meter-trip-tag" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        Standby
                    </div>
                </div>
                <dl class="mt-5 space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Distance fare</dt>
                        <dd id="fare-distance" class="font-semibold">PHP 0.00</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Waiting fare</dt>
                        <dd id="fare-waiting" class="font-semibold">PHP 0.00</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Night surcharge</dt>
                        <dd id="fare-surcharge" class="font-semibold">PHP 0.00</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Minimum fare adjustment</dt>
                        <dd id="fare-minimum" class="font-semibold">PHP 0.00</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold tracking-tight">Passenger tracking</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Share this 4-digit tracking code with the admin or rider so they can open the live trip page.</p>
                    </div>
                    <span id="tracking-link-status" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        Inactive
                    </span>
                </div>
                <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                    <input id="passenger-link-input" type="text" readonly value="Tracking code becomes available after trip start." class="block w-full rounded-2xl border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">
                    <button id="copy-link-btn" type="button" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">
                        Copy Code
                    </button>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold tracking-tight">Live route map</h2>
                        <p id="map-status" class="mt-1 text-sm text-slate-500 dark:text-slate-400">Map centers on the latest GPS point and draws the current route line.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <span id="route-count">0</span> points
                    </span>
                </div>
                <div id="meter-live-map" class="mt-5 h-72 overflow-hidden rounded-3xl border border-slate-200 dark:border-slate-800"></div>
                <div class="mt-5 rounded-2xl bg-slate-50 p-4 dark:bg-slate-950/60">
                    <ol id="recent-points" class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li>No locations recorded yet.</li>
                    </ol>
                </div>
            </section>
        </div>
    </section>
</div>

<div id="meter-toast" class="pointer-events-none fixed bottom-4 right-4 hidden max-w-sm rounded-2xl px-4 py-3 text-sm font-semibold text-white shadow-lg"></div>

<script>
    window.METRE_CONFIG = <?php echo json_encode($meterConfig); ?>;
</script>
<script src="<?php echo h(url('assets/js/location-names.js')); ?>"></script>
<script src="<?php echo h(url('assets/js/app.js')); ?>"></script>
<?php render_page_end(); ?>


