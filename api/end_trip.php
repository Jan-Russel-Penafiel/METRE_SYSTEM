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
$startedAt = to_mysql_datetime($payload['started_at'] ?? '');
$endedAt = to_mysql_datetime($payload['ended_at'] ?? '');
$routePoints = is_array($payload['route_points'] ?? null) ? array_slice($payload['route_points'], -300) : [];

if ($tripToken === '') {
    json_response(['success' => false, 'message' => 'Trip token is required.'], 422);
}

if ($publicTrackingToken === '') {
    $liveTracking = db_select_one('SELECT public_tracking_token FROM live_trip_tracking WHERE trip_token = ? LIMIT 1', 's', [$tripToken]);
    $existingTrackingCode = $liveTracking['public_tracking_token'] ?? '';
    $publicTrackingToken = preg_match('/^\d{4}$/', $existingTrackingCode) ? $existingTrackingCode : generate_public_token();
}

$existingTrip = db_select_one('SELECT id FROM trips WHERE trip_token = ? LIMIT 1', 's', [$tripToken]);
if ($existingTrip) {
    json_response([
        'success' => true,
        'message' => 'Trip already finalized.',
        'trip_id' => (int) $existingTrip['id'],
        'receipt_url' => url('receipt.php?id=' . (int) $existingTrip['id']),
        'tracking_url' => absolute_url('index.php?token=' . $publicTrackingToken),
    ]);
}

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

$firstPoint = $sanitizedRoute ? $sanitizedRoute[0] : null;
$lastPoint = $sanitizedRoute ? $sanitizedRoute[count($sanitizedRoute) - 1] : null;

$startLat = $firstPoint['lat'] ?? (isset($payload['start_lat']) ? (float) $payload['start_lat'] : null);
$startLng = $firstPoint['lng'] ?? (isset($payload['start_lng']) ? (float) $payload['start_lng'] : null);
$endLat = $lastPoint['lat'] ?? (isset($payload['end_lat']) ? (float) $payload['end_lat'] : null);
$endLng = $lastPoint['lng'] ?? (isset($payload['end_lng']) ? (float) $payload['end_lng'] : null);

$breakdown = calculate_trip_fare($meters, $waitingSeconds, $vehicleType, $startedAt);

$tripId = db_execute(
    'INSERT INTO trips (trip_token, public_tracking_token, driver_id, vehicle_type, started_at, ended_at, total_meters, waiting_seconds, final_fare, fare_breakdown_json, route_points_json, start_lat, start_lng, end_lat, end_lng, trip_status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
    'ssisssdidssdddds',
    [
        $tripToken,
        $publicTrackingToken,
        (int) $user['id'],
        $breakdown['vehicle_type'],
        $startedAt,
        $endedAt,
        $meters,
        $waitingSeconds,
        $breakdown['final_fare'],
        json_encode($breakdown),
        json_encode($sanitizedRoute),
        $startLat,
        $startLng,
        $endLat,
        $endLng,
        'completed',
    ]
);

if (!$tripId) {
    json_response(['success' => false, 'message' => 'Unable to save trip.'], 500);
}

if ($endLat !== null && $endLng !== null) {
    insert_trip_point($tripToken, $user['id'], 'end', $endLat, $endLng, $meters);
}

$trackingSaved = db_execute(
    'INSERT INTO live_trip_tracking (trip_token, public_tracking_token, driver_id, vehicle_type, status, started_at, ended_at, last_lat, last_lng, meters, waiting_seconds, current_fare, route_points_json, updated_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
        public_tracking_token = VALUES(public_tracking_token),
        vehicle_type = VALUES(vehicle_type),
        status = VALUES(status),
        started_at = VALUES(started_at),
        ended_at = VALUES(ended_at),
        last_lat = VALUES(last_lat),
        last_lng = VALUES(last_lng),
        meters = VALUES(meters),
        waiting_seconds = VALUES(waiting_seconds),
        current_fare = VALUES(current_fare),
        route_points_json = VALUES(route_points_json),
        updated_at = NOW()',
    'ssissssdddids',
    [
        $tripToken,
        $publicTrackingToken,
        (int) $user['id'],
        $breakdown['vehicle_type'],
        'completed',
        $startedAt,
        $endedAt,
        $endLat,
        $endLng,
        $meters,
        $waitingSeconds,
        $breakdown['final_fare'],
        json_encode($sanitizedRoute),
    ]
);

if (!$trackingSaved) {
    json_response(['success' => false, 'message' => 'Trip saved but live tracking could not be updated.'], 500);
}

if (isset($_SESSION['trip_point_meta'][$tripToken])) {
    unset($_SESSION['trip_point_meta'][$tripToken]);
}

if (isset($_SESSION['fare_update_' . $tripToken])) {
    unset($_SESSION['fare_update_' . $tripToken]);
}

json_response([
    'success' => true,
    'message' => 'Trip finalized.',
    'trip_id' => (int) $tripId,
    'final_fare' => $breakdown['final_fare'],
    'receipt_url' => url('receipt.php?id=' . (int) $tripId),
    'tracking_url' => absolute_url('index.php?token=' . $publicTrackingToken),
]);


