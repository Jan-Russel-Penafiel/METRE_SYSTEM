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
    'graphqlUrl' => api_url('api/graphql.php'),
    'updateFareUrl' => api_url('api/update_fare.php'),
    'endTripUrl' => api_url('api/end_trip.php'),
    'distanceUrl' => api_url('api/calculate_distance.php'),
    'trackingBaseUrl' => absolute_url('index.php'),
    'mapStyle' => map_style_config(),
    'idleTimeoutSeconds' => TRIP_IDLE_TIMEOUT_SECONDS,
    'hasFareSettings' => !empty($fareSettings),
];

$meterPageHead = <<<'HTML'
<style>
body[data-page="meter"] {
    background:
        radial-gradient(circle at top left, rgba(14, 165, 233, 0.14), transparent 28%),
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.1), transparent 24%),
        linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
}

html.dark body[data-page="meter"] {
    background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 28%),
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.12), transparent 22%),
        linear-gradient(180deg, #09090b 0%, #111827 100%);
}

body[data-page="meter"] main {
    position: relative;
}

.meter-panel {
    border: 1px solid rgba(228, 228, 231, 0.75);
    background: rgba(255, 255, 255, 0.78);
    box-shadow: 0 30px 80px rgba(15, 23, 42, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(18px);
}

.dark .meter-panel {
    border-color: rgba(63, 63, 70, 0.88);
    background: rgba(9, 9, 11, 0.76);
    box-shadow: 0 30px 90px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.meter-kpi {
    border: 1px solid rgba(228, 228, 231, 0.72);
    background: rgba(255, 255, 255, 0.68);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.78);
}

.dark .meter-kpi {
    border-color: rgba(63, 63, 70, 0.88);
    background: rgba(24, 24, 27, 0.72);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
}

.meter-dot {
    height: 0.7rem;
    width: 0.7rem;
    flex-shrink: 0;
    border-radius: 9999px;
    background: rgb(148 163 184);
    box-shadow: 0 0 0 0.35rem rgba(148, 163, 184, 0.14);
    transition: background-color 150ms ease, box-shadow 150ms ease;
}

.meter-dot.is-live {
    background: rgb(16 185 129);
    box-shadow: 0 0 0 0.4rem rgba(16, 185, 129, 0.12);
}

.meter-dot.is-ready {
    background: rgb(14 165 233);
    box-shadow: 0 0 0 0.4rem rgba(14, 165, 233, 0.12);
}

.meter-dot.is-waiting {
    background: rgb(245 158 11);
    box-shadow: 0 0 0 0.4rem rgba(245, 158, 11, 0.12);
}

.meter-dot.is-error {
    background: rgb(244 63 94);
    box-shadow: 0 0 0 0.4rem rgba(244, 63, 94, 0.12);
}

.meter-dot.is-ending {
    background: rgb(59 130 246);
    box-shadow: 0 0 0 0.4rem rgba(59, 130, 246, 0.12);
}

.meter-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    padding: 0.25rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 500;
    letter-spacing: 0.025em;
    text-transform: uppercase;
}

.meter-pill.is-idle {
    background: rgb(241 245 249);
    color: rgb(71 85 105);
}

.dark .meter-pill.is-idle {
    background: rgb(30 41 59);
    color: rgb(203 213 225);
}

.meter-pill.is-live {
    background: rgb(209 250 229);
    color: rgb(4 120 87);
}

.dark .meter-pill.is-live {
    background: rgba(6, 78, 59, 0.75);
    color: rgb(110 231 183);
}

.meter-pill.is-ready {
    background: rgb(224 242 254);
    color: rgb(3 105 161);
}

.dark .meter-pill.is-ready {
    background: rgba(7, 89, 133, 0.72);
    color: rgb(125 211 252);
}

.meter-pill.is-ending {
    background: rgb(219 234 254);
    color: rgb(29 78 216);
}

.dark .meter-pill.is-ending {
    background: rgba(30, 58, 138, 0.75);
    color: rgb(191 219 254);
}

.meter-pill.is-error {
    background: rgb(255 228 230);
    color: rgb(190 24 93);
}

.dark .meter-pill.is-error {
    background: rgba(136, 19, 55, 0.72);
    color: rgb(254 205 211);
}

.meter-permission {
    border-radius: 1rem;
    border: 1px dashed rgba(161, 161, 170, 0.55);
    background: rgba(248, 250, 252, 0.78);
    color: rgb(82 82 91);
}

.dark .meter-permission {
    border-color: rgba(82, 82, 91, 0.9);
    background: rgba(9, 9, 11, 0.62);
    color: rgb(212 212 216);
}

.meter-permission.is-ready {
    border-style: solid;
    border-color: rgba(14, 165, 233, 0.24);
    background: rgba(224, 242, 254, 0.78);
    color: rgb(3 105 161);
}

.dark .meter-permission.is-ready {
    border-color: rgba(14, 165, 233, 0.32);
    background: rgba(7, 89, 133, 0.26);
    color: rgb(125 211 252);
}

.meter-permission.is-error {
    border-style: solid;
    border-color: rgba(244, 63, 94, 0.28);
    background: rgba(255, 228, 230, 0.72);
    color: rgb(190 24 93);
}

.dark .meter-permission.is-error {
    border-color: rgba(244, 63, 94, 0.34);
    background: rgba(136, 19, 55, 0.24);
    color: rgb(254 205 211);
}

.meter-map-frame {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.24), transparent 18%), rgba(255, 255, 255, 0.5);
}

.dark .meter-map-frame {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), transparent 18%), rgba(9, 9, 11, 0.38);
}

#meter-live-map {
    min-height: 24rem;
}

#meter-live-map .maplibregl-ctrl-top-right {
    top: 1rem;
    right: 1rem;
}

#meter-live-map .maplibregl-ctrl-group {
    overflow: hidden;
    border: 1px solid rgba(228, 228, 231, 0.92);
    border-radius: 1rem;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
}

.dark #meter-live-map .maplibregl-ctrl-group {
    border-color: rgba(63, 63, 70, 0.92);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
}

#meter-live-map .maplibregl-ctrl button {
    height: 2.25rem;
    width: 2.25rem;
}

#meter-live-map .maplibregl-ctrl-attrib {
    border-radius: 0.75rem 0 0 0;
    background: rgba(255, 255, 255, 0.82);
}

.dark #meter-live-map .maplibregl-ctrl-attrib {
    background: rgba(9, 9, 11, 0.82);
    color: rgb(212 212 216);
}

.meter-point-item {
    border: 1px solid rgba(228, 228, 231, 0.72);
    background: rgba(255, 255, 255, 0.68);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.78);
    border-radius: 1rem;
    padding: 0.9rem 1rem;
}

.dark .meter-point-item {
    border-color: rgba(63, 63, 70, 0.88);
    background: rgba(24, 24, 27, 0.72);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
}

.meter-point-item.is-empty {
    border-style: dashed;
}

.meter-point-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: rgb(24 24 27);
}

.dark .meter-point-label {
    color: rgb(244 244 245);
}

.meter-point-item .meter-point-label + .meter-point-label {
    margin-top: 0.35rem;
    font-weight: 500;
    color: rgb(82 82 91);
}

.dark .meter-point-item .meter-point-label + .meter-point-label {
    color: rgb(212 212 216);
}

.meter-point-meta {
    margin-top: 0.45rem;
    font-size: 0.75rem;
    line-height: 1.25rem;
    color: rgb(113 113 122);
}

.dark .meter-point-meta {
    color: rgb(161 161 170);
}

.meter-toast {
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 1rem;
    padding: 0.875rem 1rem;
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.35;
    color: #fff;
    box-shadow: 0 22px 50px rgba(15, 23, 42, 0.22);
    backdrop-filter: blur(18px);
}

.meter-toast.is-sky {
    background: rgba(14, 165, 233, 0.92);
}

.meter-toast.is-emerald {
    background: rgba(16, 185, 129, 0.92);
}

.meter-toast.is-rose {
    background: rgba(244, 63, 94, 0.92);
}

.meter-toast.is-amber {
    background: rgba(245, 158, 11, 0.92);
    color: rgb(24 24 27);
}

@media (max-width: 640px) {
    #meter-live-map {
        min-height: 20rem;
    }
}
</style>
HTML;

render_page_start('Live Meter', [
    'page_id' => 'meter',
    'body_class' => 'overflow-x-hidden',
    'extra_head' => $meterPageHead,
    'page_scripts' => ['assets/js/graphql-client.js', 'assets/js/app.js'],
    'needs_maplibre' => true,
    'preconnect_origins' => [
        'https://unpkg.com',
        'https://tile.openstreetmap.org',
        'https://nominatim.openstreetmap.org',
    ],
]);
?>
<div class="relative space-y-6 pb-8">
    <div class="pointer-events-none absolute -left-20 top-0 h-56 w-56 rounded-full bg-sky-300/25 blur-3xl dark:bg-sky-500/10"></div>
    <div class="pointer-events-none absolute -right-16 top-24 h-64 w-64 rounded-full bg-cyan-300/25 blur-3xl dark:bg-cyan-400/10"></div>

    <section class="meter-panel relative overflow-hidden rounded-[32px] px-6 py-6 sm:px-8 sm:py-8">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-sky-400/70 to-transparent"></div>
        <div class="grid gap-8 xl:grid-cols-[1.12fr_0.88fr] xl:items-end">
            <div class="space-y-6">
                <div class="space-y-4">
                    <span class="inline-flex w-fit rounded-md bg-zinc-950 px-3 py-1 text-xs font-medium uppercase tracking-wide text-white shadow-sm dark:bg-white/10 dark:text-zinc-200">
                        SakayMeter
                    </span>
                    <h1 class="max-w-3xl text-4xl font-black tracking-tight text-zinc-950 sm:text-5xl dark:text-white">
                        Driver meter
                    </h1>
                    <p class="max-w-2xl text-base leading-7 text-zinc-600 dark:text-zinc-300">
                        Keep the trip, fare sync, and passenger sharing workflow in one operating view while the route stays live on the map.
                    </p>
                </div>

                <?php if (!$fareSettings): ?>
                    <div class="rounded-[24px] border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-sm leading-6 text-amber-900 shadow-sm dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-200">
                        No fare settings are configured yet.
                        <?php if ($user['user_type'] === 'admin'): ?>
                            <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="font-semibold underline">Create a fare rule first.</a>
                        <?php else: ?>
                            Ask an admin to configure fare settings before starting trips.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <section class="rounded-[28px] border border-zinc-900/5 bg-zinc-950 p-6 text-white shadow-[0_24px_60px_rgba(15,23,42,0.2)] dark:border-zinc-800 dark:bg-zinc-950/90">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <div>
                            <p id="meter-status" class="mt-2 text-2xl font-bold tracking-tight text-white">Waiting to start...</p>
                        </div>
                    </div>
                    <span id="sync-state" class="meter-pill is-idle">
                        Idle
                    </span>
                </div>

                <p id="meter-status-copy" class="mt-4 text-sm leading-6 text-zinc-300">
                    Waiting for the first GPS fix. Keep location services enabled and allow browser access when prompted.
                </p>

              
                </div>
            </section>
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="meter-kpi rounded-[24px] p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Distance</p>
            <p id="meter-distance" class="mt-3 text-4xl font-black tracking-tight text-zinc-950 dark:text-white">0 m</p>
        </article>
        <article class="meter-kpi rounded-[24px] p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Current fare</p>
            <p id="meter-fare" class="mt-3 text-4xl font-black tracking-tight text-sky-600 dark:text-sky-300">PHP 0.00</p>
        </article>
        <article class="meter-kpi rounded-[24px] p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Waiting time</p>
            <p id="meter-waiting" class="mt-3 text-4xl font-black tracking-tight text-zinc-950 dark:text-white">00:00</p>
        </article>
        <article class="meter-kpi rounded-[24px] p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Speed</p>
            <div class="mt-3 flex items-end gap-2">
                <p id="meter-speed" class="text-4xl font-black tracking-tight text-zinc-950 dark:text-white">0.0</p>
                <span class="pb-1 text-xs font-medium uppercase tracking-wide text-zinc-400 dark:text-zinc-500">km/h</span>
            </div>
        </article>
    </section>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_360px]">
        <div>
            <section class="meter-panel overflow-hidden rounded-[32px]">
                <div class="flex flex-col gap-5 border-b border-zinc-200/80 px-6 py-5 dark:border-zinc-800/80 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <div class="space-y-1">
                        <h2 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Trip controls</h2>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            Enable GPS, confirm the vehicle type, then start the trip once the rider is on board.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:min-w-[18rem]">
                        <div class="meter-kpi rounded-[22px] p-3.5">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Route points</p>
                            <p id="route-count" class="mt-2 text-2xl font-bold text-zinc-950 dark:text-white">0</p>
                        </div>
                        <div class="meter-kpi rounded-[22px] p-3.5">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Idle timer</p>
                            <p id="meter-idle-timer-secondary" class="mt-2 text-lg font-bold text-zinc-950 dark:text-white">Not tracking yet</p>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-6 sm:px-8 sm:py-8">
                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label for="meter-vehicle-type" class="mb-2 block text-sm font-medium text-zinc-950 dark:text-white">Vehicle type</label>
                            <select id="meter-vehicle-type" class="block w-full rounded-2xl border-zinc-300 bg-white/80 px-4 py-3 focus:border-sky-300 focus:ring-sky-300 dark:border-zinc-700 dark:bg-zinc-950/70" <?php echo !$fareSettings ? 'disabled' : ''; ?>>
                                <?php foreach ($vehicleTypeOptions as $option): ?>
                                    <option value="<?php echo h($option); ?>" <?php echo $defaultVehicleType === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <div class="mb-2 block text-sm font-medium text-zinc-950 dark:text-white">Location status</div>
                            <div id="permission-state" class="meter-permission px-4 py-3 text-sm">Awaiting geolocation permission.</div>
                        </div>
                    </div>

                    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <button id="request-location-btn" type="button" class="inline-flex items-center justify-center rounded-2xl bg-zinc-950 px-4 py-3 text-base font-medium text-white transition hover:bg-zinc-800 dark:bg-white/10 dark:text-zinc-100 dark:hover:bg-white/15">
                            Enable GPS
                        </button>
                        <button id="force-start-btn" type="button" class="inline-flex items-center justify-center rounded-2xl bg-sky-400 px-4 py-3 text-base font-medium text-slate-950 transition hover:bg-sky-300" <?php echo !$fareSettings ? 'disabled' : ''; ?>>
                            Start Trip
                        </button>
                        <button id="end-trip-btn" type="button" class="inline-flex items-center justify-center rounded-2xl bg-rose-500 px-4 py-3 text-base font-medium text-white transition hover:bg-rose-400" disabled>
                            End Trip
                        </button>
                        <button id="reset-trip-btn" type="button" class="inline-flex items-center justify-center rounded-2xl border border-zinc-300 bg-white/70 px-4 py-3 text-base font-medium text-zinc-900 transition hover:bg-white dark:border-zinc-700 dark:bg-zinc-950/55 dark:text-zinc-100 dark:hover:bg-zinc-900">
                            Reset
                        </button>
                    </div>

                    <div class="mt-8 border-t border-zinc-200/80 pt-8 dark:border-zinc-800/80">
                        <div class="space-y-1">
                            <h3 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Live route</h3>
                            <p id="map-status" class="text-sm text-zinc-600 dark:text-zinc-400">
                                Map centers on the latest GPS point and draws the current route line.
                            </p>
                        </div>

                        <div class="meter-map-frame mt-6 p-3 sm:p-4">
                            <div class="relative overflow-hidden rounded-[24px] border border-white/65 bg-white/60 shadow-inner shadow-white/30 dark:border-zinc-800/80 dark:bg-zinc-950/60">
                                <div id="meter-live-map" class="h-[24rem] sm:h-[30rem] lg:h-[34rem]"></div>
                            </div>
                        </div>

                        <div class="mt-5 rounded-[24px] border border-zinc-200/80 bg-white/60 p-4 dark:border-zinc-800/80 dark:bg-zinc-950/60">
                            <ol id="recent-points" class="space-y-3">
                                <li class="meter-point-item is-empty">No locations recorded yet.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="space-y-6">
            <section class="meter-panel rounded-[32px] px-6 py-6 sm:px-7">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Fare summary</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Live fare snapshot</h2>
                    </div>
                    <span id="meter-trip-tag" class="meter-pill is-idle">
                        Standby
                    </span>
                </div>

                <dl class="mt-6 divide-y divide-zinc-200/80 dark:divide-zinc-800/80">
                    <div class="flex items-start justify-between gap-4 py-3 first:pt-0">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Distance fare</dt>
                        <dd id="fare-distance" class="text-right text-base font-bold text-zinc-950 dark:text-white">PHP 0.00</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Waiting fare</dt>
                        <dd id="fare-waiting" class="text-right text-base font-bold text-zinc-950 dark:text-white">PHP 0.00</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Night surcharge</dt>
                        <dd id="fare-surcharge" class="text-right text-base font-bold text-zinc-950 dark:text-white">PHP 0.00</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3 pb-0">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Minimum fare adjustment</dt>
                        <dd id="fare-minimum" class="text-right text-base font-bold text-zinc-950 dark:text-white">PHP 0.00</dd>
                    </div>
                </dl>
            </section>

        

            <section class="meter-panel rounded-[32px] px-6 py-6 sm:px-7">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Passenger tracking</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Share trip access</h2>
                        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                            Share the 4-digit code or the public link once the trip feed is live.
                        </p>
                    </div>
                    <span id="tracking-link-status" class="meter-pill is-idle">
                        Inactive
                    </span>
                </div>

                <div class="mt-6 space-y-4">
                    <div class="space-y-2">
                        <label for="passenger-link-input" class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">4-digit code</label>
                        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <input id="passenger-link-input" type="text" readonly value="Tracking code becomes available after trip start." class="block w-full rounded-2xl border-zinc-300 bg-white/75 px-4 py-3 text-sm text-zinc-700 focus:border-sky-300 focus:ring-sky-300 dark:border-zinc-700 dark:bg-zinc-950/70 dark:text-zinc-200">
                            <button id="copy-link-btn" type="button" class="inline-flex items-center justify-center rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-white/10 dark:text-zinc-100 dark:hover:bg-white/15">
                                Copy Code
                            </button>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="passenger-url-input" class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Passenger link</label>
                        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <input id="passenger-url-input" type="text" readonly value="Passenger share URL appears after the first fare sync." class="block w-full rounded-2xl border-zinc-300 bg-white/75 px-4 py-3 text-sm text-zinc-700 focus:border-sky-300 focus:ring-sky-300 dark:border-zinc-700 dark:bg-zinc-950/70 dark:text-zinc-200">
                            <button id="copy-tracking-url-btn" type="button" class="inline-flex items-center justify-center rounded-2xl bg-sky-400 px-4 py-3 text-sm font-medium text-slate-950 transition hover:bg-sky-300">
                                Copy Link
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </section>
</div>

<div id="meter-toast" class="pointer-events-none fixed bottom-4 right-4 hidden max-w-sm"></div>

<script>
    window.METRE_CONFIG = <?php echo json_encode($meterConfig); ?>;
</script>

<?php render_page_end(); ?>
