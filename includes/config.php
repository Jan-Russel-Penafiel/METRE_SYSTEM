<?php

if (ob_get_level() === 0) {
    ob_start();
}

mysqli_report(MYSQLI_REPORT_OFF);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'Metre');
define('APP_BASE_URL', '/metre');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'metre_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('GOOGLE_MAPS_API_KEY', '');

define('TRIP_START_SPEED_KPH', 3);
define('TRIP_START_DISTANCE_METERS', 12);
define('TRIP_IDLE_TIMEOUT_SECONDS', 30);
define('GPS_UPDATE_MIN_INTERVAL_SECONDS', 3);
