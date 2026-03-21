<?php

require_once __DIR__ . '/../includes/auth.php';

require_login(['driver', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$payload = json_input();
$originLat = isset($payload['origin_lat']) ? (float) $payload['origin_lat'] : null;
$originLng = isset($payload['origin_lng']) ? (float) $payload['origin_lng'] : null;
$destinationLat = isset($payload['destination_lat']) ? (float) $payload['destination_lat'] : null;
$destinationLng = isset($payload['destination_lng']) ? (float) $payload['destination_lng'] : null;

if ($originLat === null || $originLng === null || $destinationLat === null || $destinationLng === null) {
    json_response(['success' => false, 'message' => 'Origin and destination coordinates are required.'], 422);
}

$fallbackDistance = round(haversine_meters($originLat, $originLng, $destinationLat, $destinationLng), 2);

if (GOOGLE_MAPS_API_KEY === '') {
    json_response([
        'success' => true,
        'meters' => $fallbackDistance,
        'source' => 'haversine',
        'message' => 'Google Maps key not configured; returned straight-line distance.',
    ]);
}

$query = http_build_query([
    'origins' => $originLat . ',' . $originLng,
    'destinations' => $destinationLat . ',' . $destinationLng,
    'key' => GOOGLE_MAPS_API_KEY,
]);

$url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . $query;
$context = stream_context_create([
    'http' => [
        'timeout' => 6,
    ],
]);

$response = @file_get_contents($url, false, $context);
$data = $response ? json_decode($response, true) : null;
$distanceValue = $data['rows'][0]['elements'][0]['distance']['value'] ?? null;

if ($distanceValue !== null) {
    json_response([
        'success' => true,
        'meters' => (float) $distanceValue,
        'source' => 'google_distance_matrix',
    ]);
}

json_response([
    'success' => true,
    'meters' => $fallbackDistance,
    'source' => 'haversine',
    'message' => 'Google Maps request failed; returned straight-line distance.',
]);
