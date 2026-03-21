<?php

if (ob_get_level() === 0) {
    ob_start();
}

$sessionDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.tmp';
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0777, true) && !is_dir($sessionDirectory)) {
    $sessionDirectory = null;
}

if ($sessionDirectory !== null) {
    session_save_path($sessionDirectory);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'Metre');
define('APP_BASE_URL', '/metre');
define('DATA_STORAGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data');

define('GOOGLE_MAPS_API_KEY', '');

define('TRIP_START_SPEED_KPH', 3);
define('TRIP_START_DISTANCE_METERS', 12);
define('TRIP_IDLE_TIMEOUT_SECONDS', 30);
define('GPS_UPDATE_MIN_INTERVAL_SECONDS', 3);
