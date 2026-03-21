<?php

require_once __DIR__ . '/../includes/auth.php';

$user = require_login(['driver', 'admin']);

if (!ensure_tracking_schema()) {
    json_response(['success' => false, 'message' => 'Unable to prepare live tracking tables.'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$payload = json_input();
$tripToken = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($payload['trip_token'] ?? ''));
$publicTrackingToken = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($payload['public_tracking_token'] ?? ''));
if (!preg_match('/^\d{4}$/', $publicTrackingToken)) {
    $publicTrackingToken = '';
}
$vehicleType = trim((string) ($payload['vehicle_type'] ?? $user['vehicle_type']));
$meters = max(0, (float) ($payload['meters'] ?? 0));
$waitingSeconds = max(0, (int) ($payload['waiting_seconds'] ?? 0));
$startedAt = (string) ($payload['started_at'] ?? '');
$status = trim((string) ($payload['status'] ?? 'in_trip'));
$latitude = isset($payload['latitude']) && $payload['latitude'] !== '' ? (float) $payload['latitude'] : null;
$longitude = isset($payload['longitude']) && $payload['longitude'] !== '' ? (float) $payload['longitude'] : null;
$routePoints = is_array($payload['route_points'] ?? null) ? array_slice($payload['route_points'], -120) : [];

if ($tripToken === '') {
    json_response(['success' => false, 'message' => 'Trip token is required.'], 422);
}

if ($publicTrackingToken === '') {
    $publicTrackingToken = generate_public_token();
}

$rateLimitKey = 'fare_update_' . $tripToken;
$now = time();
$lastUpdateAt = $_SESSION[$rateLimitKey] ?? 0;

if ($lastUpdateAt && ($now - $lastUpdateAt) < GPS_UPDATE_MIN_INTERVAL_SECONDS) {
    json_response(['success' => false, 'message' => 'GPS sync is cooling down.'], 429);
}

$_SESSION[$rateLimitKey] = $now;
$breakdown = calculate_trip_fare($meters, $waitingSeconds, $vehicleType, $startedAt);

$sanitizedRoute = [];
foreach ($routePoints as $point) {
    if (!is_array($point) || !isset($point['lat'], $point['lng'])) {
        continue;
    }

    $sanitizedRoute[] = [
        'lat' => round((float) $point['lat'], 7),
        'lng' => round((float) $point['lng'], 7),
        'speedKph' => round((float) ($point['speedKph'] ?? 0), 2),
        'timestamp' => (string) ($point['timestamp'] ?? ''),
    ];
}

if (!isset($_SESSION['trip_point_meta'])) {
    $_SESSION['trip_point_meta'] = [];
}

$tripMeta = $_SESSION['trip_point_meta'][$tripToken] ?? [
    'start_saved' => false,
    'last_meter_mark' => 0,
];

if ($latitude !== null && $longitude !== null) {
    if (!$tripMeta['start_saved']) {
        insert_trip_point($tripToken, $user['id'], 'start', $latitude, $longitude, 0);
        $tripMeta['start_saved'] = true;
    }

    if ($meters >= ((float) $tripMeta['last_meter_mark'] + 100)) {
        insert_trip_point($tripToken, $user['id'], 'checkpoint', $latitude, $longitude, $meters);
        $tripMeta['last_meter_mark'] = $meters;
    }
}

$_SESSION['trip_point_meta'][$tripToken] = $tripMeta;

$trackingSaved = db_execute(
    'INSERT INTO live_trip_tracking (trip_token, public_tracking_token, driver_id, vehicle_type, status, started_at, last_lat, last_lng, meters, waiting_seconds, current_fare, route_points_json, updated_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
        public_tracking_token = VALUES(public_tracking_token),
        vehicle_type = VALUES(vehicle_type),
        status = VALUES(status),
        started_at = VALUES(started_at),
        last_lat = VALUES(last_lat),
        last_lng = VALUES(last_lng),
        meters = VALUES(meters),
        waiting_seconds = VALUES(waiting_seconds),
        current_fare = VALUES(current_fare),
        route_points_json = VALUES(route_points_json),
        updated_at = NOW()',
    'ssisssdddids',
    [
        $tripToken,
        $publicTrackingToken,
        (int) $user['id'],
        $breakdown['vehicle_type'],
        $status,
        to_mysql_datetime($startedAt),
        $latitude,
        $longitude,
        $meters,
        $waitingSeconds,
        $breakdown['final_fare'],
        json_encode($sanitizedRoute),
    ]
);

if (!$trackingSaved) {
    json_response(['success' => false, 'message' => 'Unable to update live tracking state.'], 500);
}

json_response([
    'success' => true,
    'message' => 'Fare updated.',
    'final_fare' => $breakdown['final_fare'],
    'breakdown' => $breakdown,
    'server_time' => date(DATE_ATOM),
    'public_tracking_token' => $publicTrackingToken,
    'tracking_url' => absolute_url('index.php?token=' . $publicTrackingToken),
]);


