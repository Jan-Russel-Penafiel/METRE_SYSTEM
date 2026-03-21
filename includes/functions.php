<?php

require_once __DIR__ . '/config.php';

function db_connect()
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $connection = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if (!$connection) {
        http_response_code(500);
        echo '<h1>Database connection failed.</h1>';
        echo '<p>Update the database settings in includes/config.php and import db.sql first.</p>';
        exit;
    }

    mysqli_set_charset($connection, 'utf8mb4');

    return $connection;
}

function db_bind_params($statement, $types, $params)
{
    if ($types === '' || empty($params)) {
        return true;
    }

    $bindArguments = [$statement, $types];

    foreach ($params as $key => $value) {
        $bindArguments[] = &$params[$key];
    }

    return call_user_func_array('mysqli_stmt_bind_param', $bindArguments);
}

function db_select_all($sql, $types = '', $params = [])
{
    $connection = db_connect();
    $statement = mysqli_prepare($connection, $sql);

    if (!$statement) {
        return [];
    }

    if (!db_bind_params($statement, $types, $params)) {
        mysqli_stmt_close($statement);
        return [];
    }

    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $rows = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($statement);

    return $rows;
}

function db_select_one($sql, $types = '', $params = [])
{
    $rows = db_select_all($sql, $types, $params);
    return $rows ? $rows[0] : null;
}

function db_execute($sql, $types = '', $params = [])
{
    $connection = db_connect();
    $statement = mysqli_prepare($connection, $sql);

    if (!$statement) {
        return false;
    }

    if (!db_bind_params($statement, $types, $params)) {
        mysqli_stmt_close($statement);
        return false;
    }

    $success = mysqli_stmt_execute($statement);
    $insertId = mysqli_insert_id($connection);

    mysqli_stmt_close($statement);

    if (!$success) {
        return false;
    }

    return $insertId > 0 ? $insertId : true;
}

function db_query($sql)
{
    return mysqli_query(db_connect(), $sql);
}

function db_escape($value)
{
    return mysqli_real_escape_string(db_connect(), (string) $value);
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

        if (!db_table_exists('live_trip_tracking')) {
            return $code;
        }

        $activeTrip = db_select_one(
            "SELECT 1
             FROM live_trip_tracking
             WHERE public_tracking_token = ?
             AND status IN ('waiting', 'in_trip')
             LIMIT 1",
            's',
            [$code]
        );

        if (!$activeTrip) {
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

function db_table_exists($tableName)
{
    $row = db_select_one(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = ? AND table_name = ?
         LIMIT 1',
        'ss',
        [DB_NAME, $tableName]
    );

    return $row !== null;
}

function db_column_exists($tableName, $columnName)
{
    $row = db_select_one(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?
         LIMIT 1',
        'sss',
        [DB_NAME, $tableName, $columnName]
    );

    return $row !== null;
}

function db_index_exists($tableName, $indexName)
{
    $row = db_select_one(
        'SELECT 1
         FROM information_schema.statistics
         WHERE table_schema = ? AND table_name = ? AND index_name = ?
         LIMIT 1',
        'sss',
        [DB_NAME, $tableName, $indexName]
    );

    return $row !== null;
}

function ensure_tracking_schema()
{
    static $ensured = false;

    if ($ensured) {
        return true;
    }

    if (!db_table_exists('live_trip_tracking')) {
        $created = db_query(
            "CREATE TABLE IF NOT EXISTS live_trip_tracking (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                trip_token VARCHAR(80) NOT NULL UNIQUE,
                public_tracking_token VARCHAR(80) NOT NULL,
                driver_id INT UNSIGNED NOT NULL,
                vehicle_type VARCHAR(80) NOT NULL,
                status ENUM('waiting', 'in_trip', 'completed') NOT NULL DEFAULT 'waiting',
                started_at DATETIME NULL,
                ended_at DATETIME NULL,
                last_lat DECIMAL(10,7) NULL,
                last_lng DECIMAL(10,7) NULL,
                meters DECIMAL(12,2) NOT NULL DEFAULT 0,
                waiting_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                current_fare DECIMAL(10,2) NOT NULL DEFAULT 0,
                route_points_json LONGTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_live_trip_tracking_driver FOREIGN KEY (driver_id) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                INDEX idx_live_trip_tracking_driver_id (driver_id),
                INDEX idx_live_trip_tracking_status (status),
                INDEX idx_live_trip_tracking_token (public_tracking_token)
            ) ENGINE=InnoDB"
        );

        if (!$created) {
            return false;
        }
    }

    if (db_table_exists('live_trip_tracking') && db_index_exists('live_trip_tracking', 'public_tracking_token')) {
        $droppedTrackingUnique = db_query(
            "ALTER TABLE live_trip_tracking
             DROP INDEX public_tracking_token"
        );

        if (!$droppedTrackingUnique) {
            return false;
        }
    }

    if (db_table_exists('live_trip_tracking') && !db_index_exists('live_trip_tracking', 'idx_live_trip_tracking_token')) {
        $addedTrackingIndex = db_query(
            "ALTER TABLE live_trip_tracking
             ADD INDEX idx_live_trip_tracking_token (public_tracking_token)"
        );

        if (!$addedTrackingIndex) {
            return false;
        }
    }

    if (db_table_exists('trips') && !db_column_exists('trips', 'public_tracking_token')) {
        $added = db_query(
            "ALTER TABLE trips
             ADD COLUMN public_tracking_token VARCHAR(80) NULL AFTER trip_token"
        );

        if (!$added) {
            return false;
        }
    }

    if (db_table_exists('trips') && db_index_exists('trips', 'public_tracking_token')) {
        $droppedTripsUnique = db_query(
            "ALTER TABLE trips
             DROP INDEX public_tracking_token"
        );

        if (!$droppedTripsUnique) {
            return false;
        }
    }

    if (db_table_exists('trips') && !db_index_exists('trips', 'idx_trips_public_tracking_token')) {
        $addedTripsIndex = db_query(
            "ALTER TABLE trips
             ADD INDEX idx_trips_public_tracking_token (public_tracking_token)"
        );

        if (!$addedTripsIndex) {
            return false;
        }
    }

    $ensured = true;

    return true;
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
    return db_select_all('SELECT * FROM fare_settings ORDER BY vehicle_type ASC');
}

function get_fare_setting($vehicleType)
{
    $row = db_select_one(
        'SELECT * FROM fare_settings WHERE vehicle_type = ? LIMIT 1',
        's',
        [$vehicleType]
    );

    if ($row) {
        return $row;
    }

    return db_select_one('SELECT * FROM fare_settings ORDER BY id ASC LIMIT 1');
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

    return db_execute(
        'INSERT INTO trip_points (trip_token, driver_id, point_type, latitude, longitude, meter_mark, captured_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())',
        'sissdd',
        [
            $tripToken,
            (int) $driverId,
            $pointType,
            (float) $latitude,
            (float) $longitude,
            (float) $meterMark,
        ]
    );
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
    ?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title . ' | ' . APP_NAME); ?></title>
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {
            darkMode: 'class'
        };
        if (localStorage.getItem('metre-theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <?php echo $extraHead; ?>
</head>
<body class="min-h-full bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<?php if (!$hideNav): ?>
    <header class="border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="<?php echo h($user && $user['user_type'] === 'admin' ? url('admin/index.php') : url('meter.php')); ?>" class="text-lg font-semibold tracking-tight">
                <?php echo h(APP_NAME); ?>
            </a>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium md:hidden dark:border-slate-700" data-mobile-menu-toggle>
                Menu
            </button>
            <nav class="hidden items-center gap-3 md:flex" data-mobile-menu>
                <?php if ($user): ?>
                    <?php if ($user['user_type'] === 'driver'): ?>
                        <a href="<?php echo h(url('meter.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Meter</a>
                        <a href="<?php echo h(url('history.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">History</a>
                    <?php endif; ?>
                    <?php if ($user['user_type'] === 'admin'): ?>
                        <a href="<?php echo h(url('admin/index.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Dashboard</a>
                        <a href="<?php echo h(url('admin/fare_settings.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Fare Settings</a>
                        <a href="<?php echo h(url('admin/reports.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Reports</a>
                        <a href="<?php echo h(url('admin/users.php')); ?>" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800">Users</a>
                    <?php endif; ?>
                    <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700" data-theme-toggle>
                        Theme
                    </button>
                    <span class="hidden text-sm text-slate-500 lg:inline dark:text-slate-400">
                        <?php echo h($user['full_name']); ?>
                    </span>
                    <a href="<?php echo h(url('logout.php')); ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">Logout</a>
                <?php else: ?>
                    <a href="<?php echo h(url('login.php')); ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900">Login</a>
                <?php endif; ?>
            </nav>
        </div>
        <div class="hidden border-t border-slate-200 px-4 py-3 md:hidden dark:border-slate-800" data-mobile-menu-panel>
            <div class="flex flex-col gap-2">
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
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <?php if ($flash): ?>
            <div class="mb-6 rounded-2xl border px-4 py-3 text-sm <?php echo h(flash_class($flash['type'])); ?>">
                <?php echo h($flash['message']); ?>
            </div>
        <?php endif; ?>
    <?php
}

function render_page_end()
{
    ?>
    </main>
    <script>
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.documentElement.classList.toggle('dark');
                var isDark = document.documentElement.classList.contains('dark');
                localStorage.setItem('metre-theme', isDark ? 'dark' : 'light');
            });
        });

        var mobileToggle = document.querySelector('[data-mobile-menu-toggle]');
        var mobilePanel = document.querySelector('[data-mobile-menu-panel]');
        if (mobileToggle && mobilePanel) {
            mobileToggle.addEventListener('click', function () {
                mobilePanel.classList.toggle('hidden');
            });
        }
    </script>
</body>
</html>
    <?php
}






