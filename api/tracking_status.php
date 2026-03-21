<?php

require_once __DIR__ . '/../includes/functions.php';

$token = normalize_tracking_token($_GET['token'] ?? '');

if ($token === '') {
    json_response(['success' => false, 'message' => 'Tracking code is required.'], 422);
}

if (!ensure_tracking_schema()) {
    json_response(['success' => false, 'message' => 'Tracking service is unavailable.'], 500);
}

$tracking = db_select_one(
    "SELECT ltt.*, u.full_name
     FROM live_trip_tracking ltt
     INNER JOIN users u ON u.id = ltt.driver_id
     WHERE ltt.public_tracking_token = ?
     ORDER BY FIELD(ltt.status, 'in_trip', 'waiting', 'completed'), ltt.updated_at DESC, ltt.id DESC
     LIMIT 1",
    's',
    [$token]
);

if (!$tracking) {
    json_response(['success' => false, 'message' => 'Tracking code not found.'], 404);
}

$routePoints = json_decode($tracking['route_points_json'] ?? '[]', true);
$routePoints = is_array($routePoints) ? $routePoints : [];
$sanitizedRoute = [];

foreach ($routePoints as $point) {
    if (!is_array($point) || !isset($point['lat'], $point['lng']) || !is_numeric($point['lat']) || !is_numeric($point['lng'])) {
        continue;
    }

    $sanitizedRoute[] = [
        'lat' => round((float) $point['lat'], 7),
        'lng' => round((float) $point['lng'], 7),
        'speedKph' => round((float) ($point['speedKph'] ?? 0), 2),
        'timestamp' => (string) ($point['timestamp'] ?? ''),
    ];
}

json_response([
    'success' => true,
    'trip_token' => $tracking['trip_token'],
    'public_tracking_token' => $tracking['public_tracking_token'],
    'driver_name' => $tracking['full_name'],
    'vehicle_type' => $tracking['vehicle_type'],
    'status' => $tracking['status'],
    'started_at' => $tracking['started_at'],
    'ended_at' => $tracking['ended_at'],
    'last_lat' => $tracking['last_lat'] !== null && is_numeric($tracking['last_lat']) ? (float) $tracking['last_lat'] : null,
    'last_lng' => $tracking['last_lng'] !== null && is_numeric($tracking['last_lng']) ? (float) $tracking['last_lng'] : null,
    'meters' => (float) $tracking['meters'],
    'waiting_seconds' => (int) $tracking['waiting_seconds'],
    'current_fare' => (float) $tracking['current_fare'],
    'updated_at' => $tracking['updated_at'],
    'route_points' => $sanitizedRoute,
]);