<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data_store.php';

if (!ensure_data_store()) {
    http_response_code(500);
    echo '<h1>Unable to initialize the JSON data store.</h1>';
    echo '<p>Check write access for the data directory: ' . htmlspecialchars(DATA_STORAGE_PATH, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url($path = '')
{
    $base = rtrim(APP_BASE_URL, '/');
    $path = ltrim($path, '/');

    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . $path;
}

function asset_url($path = '')
{
    $normalizedPath = ltrim((string) $path, '/');
    $assetPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalizedPath);
    $version = is_file($assetPath) ? (string) filemtime($assetPath) : '';
    $resolvedUrl = url($normalizedPath);

    if ($version === '') {
        return $resolvedUrl;
    }

    return $resolvedUrl . (strpos($resolvedUrl, '?') === false ? '?' : '&') . 'v=' . $version;
}

function apisix_enabled()
{
    return defined('APISIX_ENABLED') && APISIX_ENABLED;
}

function api_url($path = '')
{
    $path = ltrim((string) $path, '/');

    if (!apisix_enabled()) {
        return url($path);
    }

    $baseUrl = rtrim((string) APISIX_GATEWAY_BASE_URL, '/');
    $routePrefix = trim((string) APISIX_ROUTE_PREFIX, '/');
    $resolvedPath = $routePrefix === '' ? $path : $routePrefix . '/' . $path;

    if ($baseUrl === '') {
        return '/' . ltrim($resolvedPath, '/');
    }

    return $baseUrl . '/' . ltrim($resolvedPath, '/');
}

function absolute_url($path = '')
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

function generate_public_token()
{
    $maxAttempts = 200;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $inUse = false;

        foreach (data_records('live_trip_tracking') as $tracking) {
            if ((string) ($tracking['public_tracking_token'] ?? '') !== $code) {
                continue;
            }

            if (in_array((string) ($tracking['status'] ?? ''), ['waiting', 'in_trip'], true)) {
                $inUse = true;
                break;
            }
        }

        if (!$inUse) {
            return $code;
        }
    }

    return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

function normalize_tracking_token($value)
{
    $raw = trim((string) $value);

    if ($raw === '') {
        return '';
    }

    if (preg_match('/(?:[?&]|%3F|%26)token(?:=|%3D)([a-zA-Z0-9]+)/i', $raw, $matches)) {
        return preg_replace('/[^a-zA-Z0-9]/', '', (string) $matches[1]);
    }

    $query = parse_url($raw, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        parse_str($query, $params);
        if (!empty($params['token'])) {
            return preg_replace('/[^a-zA-Z0-9]/', '', (string) $params['token']);
        }
    }

    $sanitized = preg_replace('/[^a-zA-Z0-9]/', '', $raw);

    if (preg_match('/token((?:\d{4})|(?:[a-fA-F0-9]{32}))$/', $sanitized, $matches)) {
        return strtolower($matches[1]);
    }

    return $sanitized;
}

function map_style_config()
{
    return [
        'version' => 8,
        'sources' => [
            'osm-raster' => [
                'type' => 'raster',
                'tiles' => [
                    'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                ],
                'tileSize' => 256,
                'maxzoom' => 19,
                'attribution' => '&copy; OpenStreetMap contributors',
            ],
        ],
        'layers' => [
            [
                'id' => 'osm-raster',
                'type' => 'raster',
                'source' => 'osm-raster',
            ],
        ],
    ];
}

function ensure_tracking_schema()
{
    return ensure_data_store();
}

function redirect_to($path)
{
    header('Location: ' . url($path));
    exit;
}

function set_flash($message, $type = 'info')
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function get_flash()
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function json_input()
{
    $raw = file_get_contents('php://input');

    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_response($data, $statusCode = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function format_currency($amount)
{
    return 'PHP ' . number_format((float) $amount, 2);
}

function format_meters($meters)
{
    return number_format((float) $meters, 0) . ' m';
}

function format_distance_with_km($meters)
{
    $meters = (float) $meters;

    if ($meters >= 1000) {
        return number_format($meters / 1000, 2) . ' km';
    }

    return format_meters($meters);
}

function format_duration($seconds)
{
    $seconds = max(0, (int) $seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remainingSeconds = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    return sprintf('%02d:%02d', $minutes, $remainingSeconds);
}

function to_mysql_datetime($value)
{
    if (!$value) {
        return date('Y-m-d H:i:s');
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
}

function is_night_time($dateTime = null)
{
    $timestamp = $dateTime ? strtotime($dateTime) : time();
    $hour = (int) date('G', $timestamp);
    return $hour >= 22 || $hour < 5;
}

function haversine_meters($lat1, $lng1, $lat2, $lng2)
{
    $earthRadius = 6371000;

    $dLat = deg2rad((float) $lat2 - (float) $lat1);
    $dLng = deg2rad((float) $lng2 - (float) $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad((float) $lat1)) * cos(deg2rad((float) $lat2))
        * sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function get_vehicle_type_options()
{
    return [
        'Standard Taxi',
        'Premium Sedan',
        'SUV',
        'Van',
    ];
}

function get_fare_settings()
{
    return list_fare_settings_records();
}

function get_fare_setting($vehicleType)
{
    foreach (list_fare_settings_records() as $setting) {
        if ((string) ($setting['vehicle_type'] ?? '') === (string) $vehicleType) {
            return $setting;
        }
    }

    $settings = list_fare_settings_records();
    return $settings ? $settings[0] : null;
}

function calculate_trip_fare($meters, $waitingSeconds, $vehicleType, $startedAt = null)
{
    $setting = get_fare_setting($vehicleType);

    if (!$setting) {
        return [
            'vehicle_type' => $vehicleType,
            'rate_per_meter' => 0,
            'minimum_fare' => 0,
            'waiting_rate_per_minute' => 0,
            'night_surcharge_percent' => 0,
            'distance_fare' => 0,
            'waiting_fare' => 0,
            'night_surcharge' => 0,
            'minimum_adjustment' => 0,
            'subtotal' => 0,
            'final_fare' => 0,
            'is_night' => false,
        ];
    }

    $meters = max(0, (float) $meters);
    $waitingSeconds = max(0, (int) $waitingSeconds);
    $distanceFare = $meters * (float) $setting['rate_per_meter'];
    $waitingFare = ($waitingSeconds / 60) * (float) $setting['waiting_rate_per_minute'];
    $subtotal = $distanceFare + $waitingFare;
    $isNight = is_night_time($startedAt);
    $nightSurcharge = $isNight
        ? $subtotal * ((float) $setting['night_surcharge_percent'] / 100)
        : 0;

    $beforeMinimum = $subtotal + $nightSurcharge;
    $minimumFare = (float) $setting['minimum_fare'];
    $finalFare = max($minimumFare, $beforeMinimum);
    $minimumAdjustment = max(0, $finalFare - $beforeMinimum);

    return [
        'vehicle_type' => $setting['vehicle_type'],
        'rate_per_meter' => (float) $setting['rate_per_meter'],
        'minimum_fare' => $minimumFare,
        'waiting_rate_per_minute' => (float) $setting['waiting_rate_per_minute'],
        'night_surcharge_percent' => (float) $setting['night_surcharge_percent'],
        'distance_fare' => round($distanceFare, 2),
        'waiting_fare' => round($waitingFare, 2),
        'night_surcharge' => round($nightSurcharge, 2),
        'minimum_adjustment' => round($minimumAdjustment, 2),
        'subtotal' => round($beforeMinimum, 2),
        'final_fare' => round($finalFare, 2),
        'is_night' => $isNight,
    ];
}

function insert_trip_point($tripToken, $driverId, $pointType, $latitude, $longitude, $meterMark)
{
    if (!$tripToken || !$driverId || $latitude === null || $longitude === null) {
        return false;
    }

    return insert_trip_point_record([
        'trip_token' => $tripToken,
        'driver_id' => (int) $driverId,
        'point_type' => $pointType,
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude,
        'meter_mark' => (float) $meterMark,
        'captured_at' => data_now(),
    ]);
}

function flash_class($type)
{
    $map = [
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200',
        'error' => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/50 dark:text-rose-200',
        'info' => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200',
    ];

    return $map[$type] ?? $map['info'];
}

function render_page_start($title, $options = [])
{
    $hideNav = !empty($options['hide_nav']);
    $user = function_exists('current_user') ? current_user() : null;
    $flash = get_flash();
    $extraHead = $options['extra_head'] ?? '';
    $pageId = trim((string) ($options['page_id'] ?? ''));
    $bodyClass = trim('min-h-full bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100 ' . (string) ($options['body_class'] ?? ''));
    $pageScripts = [];

    foreach ((array) ($options['page_scripts'] ?? []) as $scriptPath) {
        $scriptPath = trim((string) $scriptPath);
        if ($scriptPath === '') {
            continue;
        }

        $pageScripts[] = asset_url($scriptPath);
    }

    $preconnectOrigins = ['https://cdn.jsdelivr.net'];
    foreach ((array) ($options['preconnect_origins'] ?? []) as $origin) {
        $origin = rtrim(trim((string) $origin), '/');
        if ($origin === '' || in_array($origin, $preconnectOrigins, true)) {
            continue;
        }

        $preconnectOrigins[] = $origin;
    }

    if (apisix_enabled()) {
        $gatewayBaseUrl = trim((string) APISIX_GATEWAY_BASE_URL);
        if ($gatewayBaseUrl !== '') {
            $gatewayParts = parse_url($gatewayBaseUrl);
            if (!empty($gatewayParts['scheme']) && !empty($gatewayParts['host'])) {
                $gatewayOrigin = $gatewayParts['scheme'] . '://' . $gatewayParts['host'];
                if (!empty($gatewayParts['port'])) {
                    $gatewayOrigin .= ':' . $gatewayParts['port'];
                }

                if (!in_array($gatewayOrigin, $preconnectOrigins, true)) {
                    $preconnectOrigins[] = $gatewayOrigin;
                }
            }
        }
    }

    $bootConfig = [
        'page' => $pageId,
        'pageScripts' => array_values(array_unique($pageScripts)),
    ];

    if (!empty($options['needs_maplibre'])) {
        $bootConfig['maplibre'] = [
            'css' => url('assets/libs/maplibre-gl.css'),
            'js' => url('assets/libs/maplibre-gl.js'),
        ];
    }

    $GLOBALS['metre_render_context'] = [
        'boot' => $bootConfig,
    ];
    ?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title . ' | ' . APP_NAME); ?></title>
    <?php foreach ($preconnectOrigins as $origin): ?>
        <link rel="preconnect" href="<?php echo h($origin); ?>" crossorigin>
        <link rel="dns-prefetch" href="<?php echo h($origin); ?>">
    <?php endforeach; ?>
    <script>
        if (localStorage.getItem('metre-theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link rel="stylesheet" href="<?php echo h(asset_url('assets/libs/tailwind.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo h(asset_url('assets/css/custom.css')); ?>"><?php echo $extraHead; ?>
</head>
<body class="<?php echo h($bodyClass); ?>"<?php echo $pageId !== '' ? ' data-page="' . h($pageId) . '"' : ''; ?>>
<?php if (!$hideNav): ?>
    <header class="border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:gap-4 sm:py-4 sm:px-6 lg:px-8">
            <a href="<?php echo h($user && $user['user_type'] === 'admin' ? url('admin/index.php') : url('meter.php')); ?>" class="text-base font-semibold tracking-tight sm:text-lg">
                <?php echo h(APP_NAME); ?>
            </a>
            <button type="button" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium sm:px-3 sm:py-2 sm:text-sm md:hidden dark:border-slate-700" data-mobile-menu-toggle>
                Menu
            </button>
            <nav class="hidden items-center gap-2 sm:gap-3 md:flex" data-mobile-menu>
                <?php if ($user): ?>
                    <?php if ($user['user_type'] === 'driver'): ?>
                        <a href="<?php echo h(url('meter.php')); ?>" class="rounded-lg px-2.5 py-1.5 text-sm font-medium hover:bg-slate-100 sm:px-3 sm:py-2 dark:hover:bg-slate-800">Meter</a>
                        <a href="<?php echo h(url('history.php')); ?>" class="rounded-lg px-2.5 py-1.5 text-sm font-medium hover:bg-slate-100 sm:px-3 sm:py-2 dark:hover:bg-slate-800">History</a>
                    <?php endif; ?>
                    <?php if ($user['user_type'] === 'admin'): ?>
                        <a href="<?php echo h(url('admin/index.php')); ?>" class="rounded-lg px-2.5 py-1.5 text-sm font-medium hover:bg-slate-100 sm:px-3 sm:py-2 dark:hover:bg-slate-800">Dashboard</a>
                        <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="rounded-lg px-2.5 py-1.5 text-sm font-medium hover:bg-slate-100 sm:px-3 sm:py-2 dark:hover:bg-slate-800">Fares</a>
                        <a href="<?php echo h(url('admin/reports.php')); ?>" class="rounded-lg px-2.5 py-1.5 text-sm font-medium hover:bg-slate-100 sm:px-3 sm:py-2 dark:hover:bg-slate-800">Reports</a>
                        <a href="<?php echo h(url('admin/users.php')); ?>" class="rounded-lg px-2.5 py-1.5 text-sm font-medium hover:bg-slate-100 sm:px-3 sm:py-2 dark:hover:bg-slate-800">Users</a>
                    <?php endif; ?>
                    <button type="button" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm font-medium sm:px-3 sm:py-2 dark:border-slate-700" data-theme-toggle>
                        Theme
                    </button>
                    <span class="hidden text-sm text-slate-500 lg:inline dark:text-slate-400">
                        <?php echo h($user['full_name']); ?>
                    </span>
                    <a href="<?php echo h(url('logout.php')); ?>" class="rounded-lg bg-slate-900 px-2.5 py-1.5 text-sm font-medium text-white hover:bg-slate-700 sm:px-3 sm:py-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">Logout</a>
                <?php else: ?>
                    <a href="<?php echo h(url('login.php')); ?>" class="rounded-lg bg-slate-900 px-2.5 py-1.5 text-sm font-medium text-white sm:px-3 sm:py-2 dark:bg-slate-100 dark:text-slate-900">Login</a>
                <?php endif; ?>
            </nav>
        </div>
        <div class="hidden border-t border-slate-200 px-4 py-3 md:hidden dark:border-slate-800" data-mobile-menu-panel>
            <div class="flex flex-col gap-1.5 sm:gap-2">
                <?php if ($user && $user['user_type'] === 'driver'): ?>
                    <a href="<?php echo h(url('meter.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Meter</a>
                    <a href="<?php echo h(url('history.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">History</a>
                <?php endif; ?>
                <?php if ($user && $user['user_type'] === 'admin'): ?>
                    <a href="<?php echo h(url('admin/index.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Dashboard</a>
                    <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Fare Settings</a>
                    <a href="<?php echo h(url('admin/reports.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Reports</a>
                    <a href="<?php echo h(url('admin/users.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Users</a>
                <?php endif; ?>
                <?php if ($user): ?>
                    <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-left dark:border-slate-700" data-theme-toggle>
                        Toggle Theme
                    </button>
                    <a href="<?php echo h(url('logout.php')); ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900">Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
<?php endif; ?>
    <main class="mx-auto max-w-7xl px-3 py-4 sm:px-4 sm:py-6 md:px-6 lg:px-8">
        <?php if ($flash): ?>
            <div class="mb-6 rounded-2xl border px-4 py-3 text-sm <?php echo h(flash_class($flash['type'])); ?>">
                <?php echo h($flash['message']); ?>
            </div>
        <?php endif; ?>
    <?php
}

function render_page_end()
{
    $context = $GLOBALS['metre_render_context'] ?? ['boot' => []];
    $bootConfig = $context['boot'] ?? [];
    unset($GLOBALS['metre_render_context']);
    ?>
    </main>
    <script>
        window.METRE_BOOT = <?php echo json_encode($bootConfig, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="<?php echo h(asset_url('assets/js/metre-optimized.js')); ?>" defer></script>
</body>
</html>
    <?php
}
