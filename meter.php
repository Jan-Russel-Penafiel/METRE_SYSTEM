<?php

require_once __DIR__ . '/includes/auth.php';

$user = require_login(['driver', 'admin']);
$fareSettings = get_fare_settings();
$vehicleTypeOptions = get_vehicle_type_options();
$defaultVehicleType = $user['vehicle_type'] ?: ($vehicleTypeOptions[0] ?? 'Standard Taxi');

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
    'updateFareUrl' => api_url('api/update_fare.php'),
    'endTripUrl' => api_url('api/end_trip.php'),
    'distanceUrl' => api_url('api/calculate_distance.php'),
    'trackingBaseUrl' => absolute_url('index.php'),
    'mapStyle' => map_style_config(),
    'idleTimeoutSeconds' => TRIP_IDLE_TIMEOUT_SECONDS,
    'hasFareSettings' => !empty($fareSettings),
];

render_page_start('Live Meter', [
    'page_id' => 'meter',
    'page_scripts' => ['assets/js/app.js'],
    'needs_maplibre' => true,
    'preconnect_origins' => [
        'https://unpkg.com',
        'https://tile.openstreetmap.org',
        'https://nominatim.openstreetmap.org',
    ],
]);
?>
<div class="space-y-8">
    <section class="rounded-xl border border-zinc-800 bg-zinc-950 px-4 py-6 text-white sm:px-6 sm:py-8 md:px-8">
        <div class="flex flex-col gap-4 sm:gap-6 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-xs uppercase tracking-wide text-zinc-400 sm:text-sm">Driver meter</p>
                <h1 class="mt-2 text-2xl font-black tracking-tight sm:mt-3 sm:text-4xl md:text-5xl">Live fare tracking</h1>
                <p class="mt-3 max-w-3xl text-xs leading-6 text-zinc-400 sm:mt-4 sm:text-sm sm:leading-7 md:text-base">
                    MapLibre drives the live map, and each active trip can publish a 4-digit passenger tracking code.
                </p>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:gap-3 xl:w-[24rem]">
                <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-3 sm:p-4">
                    <div class="text-[0.65rem] font-medium uppercase tracking-wide text-zinc-400 sm:text-xs">Trip status</div>
                    <div id="meter-status" class="mt-1 text-sm font-bold sm:mt-2 sm:text-lg">Waiting to start...</div>
                </div>
                <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-3 sm:p-4">
                    <div class="text-[0.65rem] font-medium uppercase tracking-wide text-zinc-400 sm:text-xs">Last sync</div>
                    <div id="meter-last-sync" class="mt-1 text-xs font-medium text-zinc-300 sm:mt-2 sm:text-sm">Not synced yet</div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!$fareSettings): ?>
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
            No fare settings are configured yet. <?php if ($user['user_type'] === 'admin'): ?><a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="font-medium underline">Create a fare rule first.</a><?php else: ?>Ask an admin to configure fare settings before starting trips.<?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="grid gap-4 sm:gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <div class="space-y-4 sm:space-y-6">
            <div class="grid grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-4">
                <article class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Distance</div>
                    <div id="meter-distance" class="mt-2 text-2xl font-black tracking-tight sm:mt-3 sm:text-4xl">0 m</div>
                </article>
                <article class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Current fare</div>
                    <div id="meter-fare" class="mt-2 text-2xl font-black tracking-tight text-zinc-900 sm:mt-3 sm:text-4xl dark:text-zinc-50">PHP 0.00</div>
                </article>
                <article class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Waiting time</div>
                    <div id="meter-waiting" class="mt-2 text-2xl font-black tracking-tight sm:mt-3 sm:text-4xl">00:00</div>
                </article>
                <article class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">Speed</div>
                    <div id="meter-speed" class="mt-2 text-2xl font-black tracking-tight sm:mt-3 sm:text-4xl">0.0</div>
                    <div class="mt-0.5 text-[0.65rem] uppercase tracking-wide text-zinc-400 sm:mt-1 sm:text-xs">km/h</div>
                </article>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-4 md:p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 sm:gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-xl font-bold tracking-tight sm:text-2xl">Trip controls</h2>
                        <p class="mt-1 text-xs text-zinc-500 sm:mt-2 sm:text-sm dark:text-zinc-400">Enable GPS, then start the trip manually once the current location is ready.</p>
                    </div>
                    <div id="sync-state" class="inline-flex self-start rounded-md bg-zinc-100 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-zinc-600 sm:px-2.5 sm:py-1 sm:text-xs lg:self-auto dark:bg-zinc-800 dark:text-zinc-300">
                        Idle
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:mt-6 sm:gap-5 md:grid-cols-2">
                    <div>
                        <label for="meter-vehicle-type" class="mb-1.5 block text-xs font-medium sm:text-sm">Vehicle type</label>
                        <select id="meter-vehicle-type" class="block w-full rounded-md border border-zinc-200 px-3 py-2 text-sm focus:border-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950" <?php echo !$fareSettings ? 'disabled' : ''; ?>>
                            <?php foreach ($vehicleTypeOptions as $option): ?>
                                <option value="<?php echo h($option); ?>" <?php echo $defaultVehicleType === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <div class="mb-1.5 block text-xs font-medium sm:text-sm">Location status</div>
                        <div id="permission-state" class="rounded-md border border-dashed border-zinc-300 bg-zinc-50 px-3 py-2 text-xs text-zinc-600 sm:text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">Awaiting geolocation permission.</div>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2 sm:mt-6 sm:flex sm:flex-row sm:gap-3">
                    <button id="request-location-btn" type="button" class="rounded-md bg-zinc-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200">
                        Enable GPS
                    </button>
                    <button id="force-start-btn" type="button" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700" <?php echo !$fareSettings ? 'disabled' : ''; ?>>
                        Start Trip
                    </button>
                    <button id="end-trip-btn" type="button" class="rounded-md bg-rose-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-rose-700" disabled>
                        End Trip
                    </button>
                    <button id="reset-trip-btn" type="button" class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                        Reset
                    </button>
                </div>

                <div class="mt-4 grid gap-2 sm:mt-6 sm:gap-4 md:grid-cols-2">
                    <div class="rounded-md bg-zinc-50 p-3 sm:p-4 dark:bg-zinc-900">
                        <div class="text-[0.65rem] font-medium uppercase tracking-wide text-zinc-500 sm:text-xs dark:text-zinc-400">Started at</div>
                        <div id="trip-started-at" class="mt-1 text-xs font-medium sm:mt-2 sm:text-sm">Not started</div>
                    </div>
                    <div class="rounded-md bg-zinc-50 p-3 sm:p-4 dark:bg-zinc-900">
                        <div class="text-[0.65rem] font-medium uppercase tracking-wide text-zinc-500 sm:text-xs dark:text-zinc-400">Current location</div>
                        <div id="meter-latlng" class="mt-1 text-xs font-medium sm:mt-2 sm:text-sm">No location yet</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-4 sm:space-y-6">
            <section class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3 sm:gap-4">
                    <h2 class="text-lg font-bold tracking-tight sm:text-xl">Fare breakdown</h2>
                    <div id="meter-trip-tag" class="rounded-md bg-zinc-100 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-zinc-600 sm:px-2.5 sm:py-1 sm:text-xs dark:bg-zinc-800 dark:text-zinc-300">
                        Standby
                    </div>
                </div>
                <dl class="mt-4 space-y-2 text-xs sm:mt-5 sm:space-y-3 sm:text-sm">
                    <div class="flex items-center justify-between gap-3 sm:gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Distance fare</dt>
                        <dd id="fare-distance" class="font-medium">PHP 0.00</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 sm:gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Waiting fare</dt>
                        <dd id="fare-waiting" class="font-medium">PHP 0.00</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 sm:gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Night surcharge</dt>
                        <dd id="fare-surcharge" class="font-medium">PHP 0.00</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 sm:gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Minimum fare adjustment</dt>
                        <dd id="fare-minimum" class="font-medium">PHP 0.00</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-3 sm:items-center sm:gap-4">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight sm:text-xl">Passenger tracking</h2>
                        <p class="mt-0.5 text-xs text-zinc-500 sm:mt-1 sm:text-sm dark:text-zinc-400">Share this 4-digit tracking code with the admin or rider.</p>
                    </div>
                    <span id="tracking-link-status" class="shrink-0 rounded-md bg-zinc-100 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-zinc-600 sm:px-2.5 sm:py-1 sm:text-xs dark:bg-zinc-800 dark:text-zinc-300">
                        Inactive
                    </span>
                </div>
                <div class="mt-4 flex flex-col gap-2 sm:mt-5 sm:flex-row sm:gap-3">
                    <input id="passenger-link-input" type="text" readonly value="Tracking code becomes available after trip start." class="block w-full rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600 focus:border-zinc-900 focus:ring-zinc-900 sm:text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
                    <button id="copy-link-btn" type="button" class="shrink-0 rounded-md bg-zinc-900 px-3 py-2 text-xs font-medium text-white hover:bg-zinc-800 sm:text-sm dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200">
                        Copy Code
                    </button>
                </div>
            </section>

            <section class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-3 sm:items-center sm:gap-4">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight sm:text-xl">Live route map</h2>
                        <p id="map-status" class="mt-0.5 text-xs text-zinc-500 sm:mt-1 sm:text-sm dark:text-zinc-400">Map centers on the latest GPS point and draws the current route line.</p>
                    </div>
                    <span class="shrink-0 rounded-md bg-zinc-100 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-zinc-600 sm:px-2.5 sm:py-1 sm:text-xs dark:bg-zinc-800 dark:text-zinc-300">
                        <span id="route-count">0</span> points
                    </span>
                </div>
                <div id="meter-live-map" class="mt-4 h-56 overflow-hidden rounded-lg border border-zinc-200 sm:mt-5 sm:h-72 dark:border-zinc-800"></div>
                <div class="mt-4 rounded-md bg-zinc-50 p-3 sm:mt-5 sm:p-4 dark:bg-zinc-900">
                    <ol id="recent-points" class="space-y-1.5 text-xs text-zinc-600 sm:space-y-2 sm:text-sm dark:text-zinc-300">
                        <li>No locations recorded yet.</li>
                    </ol>
                </div>
            </section>
        </div>
    </section>
</div>

<div id="meter-toast" class="pointer-events-none fixed bottom-3 left-3 right-3 hidden max-w-sm rounded-md px-3 py-2.5 text-xs font-medium text-white shadow-lg sm:bottom-4 sm:left-auto sm:right-4 sm:text-sm"></div>

<script>
    window.METRE_CONFIG = <?php echo json_encode($meterConfig); ?>;
</script>

<?php render_page_end(); ?>
