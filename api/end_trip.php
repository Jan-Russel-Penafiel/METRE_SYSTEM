<?php

require_once __DIR__ . '/../includes/auth.php';

$user = require_login(['driver', 'admin']);

if (!ensure_tracking_schema()) {
    json_response(['success' => false, 'message' => 'Unable to prepare live tracking storage.'], 500);
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
    $liveTracking = find_live_tracking_by_trip_token($tripToken);
    $existingTrackingCode = $liveTracking['public_tracking_token'] ?? '';
    $publicTrackingToken = preg_match('/^\d{4}$/', $existingTrackingCode) ? $existingTrackingCode : generate_public_token();
}

$existingTrip = find_trip_by_token($tripToken);
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

$trip = insert_trip_record([
    'trip_token' => $tripToken,
    'public_tracking_token' => $publicTrackingToken,
    'driver_id' => (int) $user['id'],
    'vehicle_type' => $breakdown['vehicle_type'],
    'started_at' => $startedAt,
    'ended_at' => $endedAt,
    'total_meters' => $meters,
    'waiting_seconds' => $waitingSeconds,
    'final_fare' => $breakdown['final_fare'],
    'fare_breakdown_json' => json_encode($breakdown, JSON_UNESCAPED_SLASHES),
    'route_points_json' => json_encode($sanitizedRoute, JSON_UNESCAPED_SLASHES),
    'start_lat' => $startLat,
    'start_lng' => $startLng,
    'end_lat' => $endLat,
    'end_lng' => $endLng,
    'trip_status' => 'completed',
]);

if (!$trip) {
    json_response(['success' => false, 'message' => 'Unable to save trip.'], 500);
}

$tripId = (int) $trip['id'];

if ($endLat !== null && $endLng !== null) {
    insert_trip_point($tripToken, $user['id'], 'end', $endLat, $endLng, $meters);
}

$trackingSaved = upsert_live_tracking_record($tripToken, [
    'public_tracking_token' => $publicTrackingToken,
    'driver_id' => (int) $user['id'],
    'vehicle_type' => $breakdown['vehicle_type'],
    'status' => 'completed',
    'started_at' => $startedAt,
    'ended_at' => $endedAt,
    'last_lat' => $endLat,
    'last_lng' => $endLng,
    'meters' => $meters,
    'waiting_seconds' => $waitingSeconds,
    'current_fare' => $breakdown['final_fare'],
    'route_points_json' => json_encode($sanitizedRoute, JSON_UNESCAPED_SLASHES),
]);

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
    'trip_id' => $tripId,
    'final_fare' => $breakdown['final_fare'],
    'receipt_url' => url('receipt.php?id=' . $tripId),
    'tracking_url' => absolute_url('index.php?token=' . $publicTrackingToken),
]);
