<?php

require_once __DIR__ . '/includes/functions.php';

$token = normalize_tracking_token($_GET['token'] ?? '');

$trackerConfig = [
    'token' => $token,
    'graphqlUrl' => api_url('api/graphql.php'),
    'trackingStatusUrl' => api_url('api/tracking_status.php'),
    'mapStyle' => map_style_config(),
];

$trackerPageHead = <<<'HTML'
<style>
body[data-page="tracker"] {
    background:
        radial-gradient(circle at top left, rgba(14, 165, 233, 0.14), transparent 28%),
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.1), transparent 24%),
        linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
}

html.dark body[data-page="tracker"] {
    background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 28%),
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.12), transparent 22%),
        linear-gradient(180deg, #09090b 0%, #111827 100%);
}

body[data-page="tracker"] main {
    position: relative;
}

.tracker-panel {
    border: 1px solid rgba(228, 228, 231, 0.75);
    background: rgba(255, 255, 255, 0.78);
    box-shadow: 0 30px 80px rgba(15, 23, 42, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(18px);
}

.dark .tracker-panel {
    border-color: rgba(63, 63, 70, 0.88);
    background: rgba(9, 9, 11, 0.76);
    box-shadow: 0 30px 90px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.tracker-kpi {
    border: 1px solid rgba(228, 228, 231, 0.72);
    background: rgba(255, 255, 255, 0.68);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.78);
}

.dark .tracker-kpi {
    border-color: rgba(63, 63, 70, 0.88);
    background: rgba(24, 24, 27, 0.72);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
}

.tracker-dot {
    height: 0.7rem;
    width: 0.7rem;
    flex-shrink: 0;
    border-radius: 9999px;
    background: rgb(148 163 184);
    box-shadow: 0 0 0 0.35rem rgba(148, 163, 184, 0.14);
    transition: background-color 150ms ease, box-shadow 150ms ease;
}

.tracker-dot.is-live {
    background: rgb(16 185 129);
    box-shadow: 0 0 0 0.4rem rgba(16, 185, 129, 0.12);
}

.tracker-dot.is-completed {
    background: rgb(14 165 233);
    box-shadow: 0 0 0 0.4rem rgba(14, 165, 233, 0.12);
}

.tracker-dot.is-waiting {
    background: rgb(245 158 11);
    box-shadow: 0 0 0 0.4rem rgba(245, 158, 11, 0.12);
}

.tracker-dot.is-error {
    background: rgb(244 63 94);
    box-shadow: 0 0 0 0.4rem rgba(244, 63, 94, 0.12);
}

.tracker-status-badge {
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

.tracker-status-badge.is-idle {
    background: rgb(241 245 249);
    color: rgb(71 85 105);
}

.dark .tracker-status-badge.is-idle {
    background: rgb(30 41 59);
    color: rgb(203 213 225);
}

.tracker-status-badge.is-live {
    background: rgb(209 250 229);
    color: rgb(4 120 87);
}

.dark .tracker-status-badge.is-live {
    background: rgba(6, 78, 59, 0.75);
    color: rgb(110 231 183);
}

.tracker-status-badge.is-completed {
    background: rgb(224 242 254);
    color: rgb(3 105 161);
}

.dark .tracker-status-badge.is-completed {
    background: rgba(7, 89, 133, 0.72);
    color: rgb(125 211 252);
}

.tracker-status-badge.is-waiting {
    background: rgb(254 243 199);
    color: rgb(180 83 9);
}

.dark .tracker-status-badge.is-waiting {
    background: rgba(120, 53, 15, 0.72);
    color: rgb(253 230 138);
}

.tracker-status-badge.is-error {
    background: rgb(255 228 230);
    color: rgb(190 24 93);
}

.dark .tracker-status-badge.is-error {
    background: rgba(136, 19, 55, 0.72);
    color: rgb(254 205 211);
}

.tracker-map-frame {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.24), transparent 18%), rgba(255, 255, 255, 0.5);
}

.dark .tracker-map-frame {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), transparent 18%), rgba(9, 9, 11, 0.38);
}

#tracking-map {
    min-height: 24rem;
}

#tracking-map .maplibregl-ctrl-top-right {
    top: 1rem;
    right: 1rem;
}

#tracking-map .maplibregl-ctrl-group {
    overflow: hidden;
    border: 1px solid rgba(228, 228, 231, 0.92);
    border-radius: 1rem;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
}

.dark #tracking-map .maplibregl-ctrl-group {
    border-color: rgba(63, 63, 70, 0.92);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
}

#tracking-map .maplibregl-ctrl button {
    height: 2.25rem;
    width: 2.25rem;
}

#tracking-map .maplibregl-ctrl-attrib {
    border-radius: 0.75rem 0 0 0;
    background: rgba(255, 255, 255, 0.82);
}

.dark #tracking-map .maplibregl-ctrl-attrib {
    background: rgba(9, 9, 11, 0.82);
    color: rgb(212 212 216);
}

@media (max-width: 640px) {
    #tracking-map {
        min-height: 20rem;
    }
}
</style>
HTML;

render_page_start('Track Your Ride', [
    'hide_nav' => true,
    'page_id' => 'tracker',
    'body_class' => 'overflow-x-hidden',
    'extra_head' => $trackerPageHead,
    'page_scripts' => ['assets/js/graphql-client.js', 'assets/js/tracker.js'],
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

    <section class="tracker-panel relative overflow-hidden rounded-[32px] px-6 py-6 sm:px-8 sm:py-8">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-sky-400/70 to-transparent"></div>
        <div class="grid gap-8 xl:grid-cols-[1.12fr_0.88fr] xl:items-end">
            <div class="space-y-6">
                <div class="space-y-4">
                    <span class="inline-flex w-fit rounded-md bg-zinc-950 px-3 py-1 text-xs font-medium uppercase tracking-wide text-white shadow-sm dark:bg-white/10 dark:text-zinc-200">
                        SakayMeter
                    </span>
                    <h1 class="max-w-3xl text-4xl font-black tracking-tight text-zinc-950 sm:text-5xl dark:text-white">
                        Shared trip tracking
                    </h1>
                    <p class="max-w-2xl text-base leading-7 text-zinc-600 dark:text-zinc-300">
                        SakayMeter keeps route, fare, and trip updates in one public view for passengers.
                    </p>
                </div>
            </div>

            <form method="get" class="rounded-[28px] border border-zinc-900/5 bg-zinc-950 p-6 text-white shadow-[0_24px_60px_rgba(15,23,42,0.2)] dark:border-zinc-800 dark:bg-zinc-950/90">
                <div class="flex flex-col gap-3">
                    <input
                        id="token"
                        name="token"
                        type="text"
                        value="<?php echo h($token); ?>"
                        aria-label="4-digit code or shared link"
                        placeholder="4-digit code or shared link"
                        class="block w-full rounded-2xl border border-white/15 bg-white px-4 py-3 text-sm text-zinc-950 placeholder:text-zinc-400 focus:border-sky-300 focus:ring-sky-300"
                    >
                    <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-sky-400 px-4 py-3 text-sm font-medium text-slate-950 transition hover:bg-sky-300">
                        Track trip
                    </button>
                </div>

                <p id="tracking-page-message" aria-live="polite" class="mt-4 text-sm leading-6 text-zinc-300">
                    <?php echo $token ? 'Loading the latest published trip status...' : ''; ?>
                </p>
            </form>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_360px]">
        <div class="tracker-panel overflow-hidden rounded-[32px]">
            <div class="flex flex-col gap-5 border-b border-zinc-200/80 px-6 py-5 dark:border-zinc-800/80 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Live route</h2>
                        <span id="tracking-status-badge" class="tracker-status-badge is-idle">
                            Idle
                        </span>
                    </div>
                    <p id="tracking-map-status" aria-live="polite" class="text-sm text-zinc-600 dark:text-zinc-400">
                        Waiting for live trip data.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:min-w-[18rem]">
                    <div class="tracker-kpi rounded-[22px] p-3.5">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Route points</p>
                        <p id="tracking-route-count" class="mt-2 text-2xl font-bold text-zinc-950 dark:text-white">0</p>
                    </div>
                    <div class="tracker-kpi rounded-[22px] p-3.5">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Waiting time</p>
                        <p id="tracking-waiting-time" class="mt-2 text-2xl font-bold text-zinc-950 dark:text-white">00:00</p>
                    </div>
                </div>
            </div>

            <div class="tracker-map-frame p-3 sm:p-4">
                <div class="relative overflow-hidden rounded-[24px] border border-white/65 bg-white/60 shadow-inner shadow-white/30 dark:border-zinc-800/80 dark:bg-zinc-950/60">
                    <div id="tracking-map" class="h-[24rem] sm:h-[30rem] lg:h-[34rem]"></div>

                </div>
            </div>
        </div>

        <div class="space-y-6">
            <section class="tracker-panel rounded-[32px] px-6 py-6 sm:px-7">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Trip summary</p>
                    <h2 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Live trip snapshot</h2>
                </div>

                <dl class="mt-6 divide-y divide-zinc-200/80 dark:divide-zinc-800/80">
                    <div class="flex items-start justify-between gap-4 py-3 first:pt-0">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Driver</dt>
                        <dd id="tracking-driver-name" class="max-w-[60%] text-right text-base font-bold text-zinc-950 dark:text-white">Waiting...</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Vehicle</dt>
                        <dd id="tracking-vehicle-type" class="max-w-[60%] text-right text-base font-bold text-zinc-950 dark:text-white">Waiting...</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Current location</dt>
                        <dd id="tracking-last-location" class="max-w-[60%] text-right text-sm font-medium leading-6 text-zinc-700 dark:text-zinc-200">
                            No live location yet.
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Distance</dt>
                        <dd id="tracking-distance" class="text-right text-base font-bold text-zinc-950 dark:text-white">0 m</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Fare snapshot</dt>
                        <dd id="tracking-fare" class="text-right text-lg font-bold text-zinc-950 dark:text-white">PHP 0.00</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Trip phase</dt>
                        <dd id="tracking-status-copy" aria-live="polite" class="max-w-[62%] text-right text-sm font-medium leading-6 text-zinc-700 dark:text-zinc-200">
                            <?php echo $token ? 'Preparing trip snapshot...' : 'No trip selected yet.'; ?>
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3 pb-0">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Latest sync</dt>
                        <dd id="tracking-sync-summary" aria-live="polite" class="max-w-[62%] text-right text-sm font-medium leading-6 text-zinc-700 dark:text-zinc-200">
                            <?php echo $token ? 'Connecting to the trip feed...' : 'Waiting for trip data.'; ?>
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="tracker-panel rounded-[32px] px-6 py-6 sm:px-7">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Trip activity</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Timeline</h2>

                <div class="mt-6 space-y-4">
                    <div class="flex gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-zinc-200 bg-white text-xs font-medium text-zinc-700 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">01</div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-950 dark:text-white">Started</p>
                            <p id="tracking-started-at" class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Not started</p>
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-zinc-200 bg-white text-xs font-medium text-zinc-700 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">02</div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-950 dark:text-white">Last update</p>
                            <p id="tracking-updated-at" class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Waiting...</p>
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-zinc-200 bg-white text-xs font-medium text-zinc-700 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">03</div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-950 dark:text-white">Ended</p>
                            <p id="tracking-ended-at" class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Trip still active</p>
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-zinc-200 bg-white text-xs font-medium text-zinc-700 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">04</div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-950 dark:text-white">Coordinates</p>
                            <p id="tracking-coordinates" class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Waiting for live coordinates</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </section>
</div>
<script>
    window.TRACKER_CONFIG = <?php echo json_encode($trackerConfig); ?>;
</script>

<?php render_page_end(); ?>
