<?php

require_once __DIR__ . '/auth.php';

class MetreApiException extends RuntimeException
{
    protected $statusCode;
    protected $extensions;

    public function __construct($message, $statusCode = 400, array $extensions = [])
    {
        parent::__construct((string) $message);
        $this->statusCode = max(100, (int) $statusCode);
        $this->extensions = $extensions;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getExtensions()
    {
        return $this->extensions;
    }
}

function api_throw($message, $statusCode = 400, array $extensions = [])
{
    throw new MetreApiException($message, $statusCode, $extensions);
}

function api_json_error_payload(MetreApiException $exception)
{
    return [
        'success' => false,
        'message' => $exception->getMessage(),
    ];
}

function require_api_method($method)
{
    $expectedMethod = strtoupper((string) $method);
    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($requestMethod !== $expectedMethod) {
        api_throw('Method not allowed.', 405, ['code' => 'METHOD_NOT_ALLOWED']);
    }
}

function require_api_user($roles = [])
{
    $user = current_user();

    if (!$user) {
        api_throw('Authentication required.', 401, ['code' => 'UNAUTHENTICATED']);
    }

    $roles = array_values(array_filter((array) $roles, 'strlen'));
    if ($roles && !in_array((string) $user['user_type'], $roles, true)) {
        api_throw('Forbidden.', 403, ['code' => 'FORBIDDEN']);
    }

    return $user;
}

function sanitize_trip_token($value)
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value);
}

function sanitize_public_tracking_token($value)
{
    $token = preg_replace('/[^a-zA-Z0-9]/', '', (string) $value);

    if (!preg_match('/^\d{4}$/', $token)) {
        return '';
    }

    return $token;
}

function sanitize_trip_route_points($routePoints, $limit = 300)
{
    $sanitizedRoute = [];

    foreach ((array) $routePoints as $point) {
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

    if ($limit > 0 && count($sanitizedRoute) > $limit) {
        $sanitizedRoute = array_slice($sanitizedRoute, -1 * $limit);
    }

    return array_values($sanitizedRoute);
}

function decode_trip_route_points_json($routePointsJson, $limit = 300)
{
    $routePoints = json_decode((string) $routePointsJson, true);
    return sanitize_trip_route_points(is_array($routePoints) ? $routePoints : [], $limit);
}

function merge_trip_route_points(array $existingRoutePoints, array $incomingRoutePoints, $limit = 120)
{
    $merged = [];
    $seen = [];

    foreach (array_merge($existingRoutePoints, $incomingRoutePoints) as $point) {
        $identity = implode('|', [
            (string) ($point['timestamp'] ?? ''),
            sprintf('%.7F', (float) ($point['lat'] ?? 0)),
            sprintf('%.7F', (float) ($point['lng'] ?? 0)),
        ]);

        if (isset($seen[$identity])) {
            continue;
        }

        $seen[$identity] = true;
        $merged[] = $point;
    }

    if ($limit > 0 && count($merged) > $limit) {
        $merged = array_slice($merged, -1 * $limit);
    }

    return array_values($merged);
}

function tracking_status_cache_headers()
{
    header('Cache-Control: public, max-age=3, s-maxage=5, stale-while-revalidate=10');
}

function build_tracking_status_payload(array $tracking)
{
    return [
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
        'route_points' => decode_trip_route_points_json($tracking['route_points_json'] ?? '[]', 300),
    ];
}

function get_tracking_status_payload($token)
{
    $token = normalize_tracking_token($token);

    if ($token === '') {
        api_throw('Tracking code is required.', 422, ['code' => 'BAD_USER_INPUT']);
    }

    if (!ensure_tracking_schema()) {
        api_throw('Tracking service is unavailable.', 500, ['code' => 'INTERNAL_ERROR']);
    }

    $tracking = find_live_tracking_by_public_token($token);
    if (!$tracking) {
        api_throw('Tracking code not found.', 404, ['code' => 'NOT_FOUND']);
    }

    return build_tracking_status_payload($tracking);
}

function sync_fare_payload(array $payload, array $user)
{
    if (!ensure_tracking_schema()) {
        api_throw('Unable to prepare live tracking storage.', 500, ['code' => 'INTERNAL_ERROR']);
    }

    $tripToken = sanitize_trip_token($payload['trip_token'] ?? '');
    $vehicleType = trim((string) ($payload['vehicle_type'] ?? $user['vehicle_type']));
    $meters = max(0, (float) ($payload['meters'] ?? 0));
    $waitingSeconds = max(0, (int) ($payload['waiting_seconds'] ?? 0));
    $startedAt = (string) ($payload['started_at'] ?? '');
    $status = trim((string) ($payload['status'] ?? 'in_trip'));
    $latitude = isset($payload['latitude']) && $payload['latitude'] !== '' ? (float) $payload['latitude'] : null;
    $longitude = isset($payload['longitude']) && $payload['longitude'] !== '' ? (float) $payload['longitude'] : null;
    $incomingRoute = sanitize_trip_route_points($payload['route_points'] ?? [], 300);

    if ($tripToken === '') {
        api_throw('Trip token is required.', 422, ['code' => 'BAD_USER_INPUT']);
    }

    $existingTracking = find_live_tracking_by_trip_token($tripToken);
    $publicTrackingToken = sanitize_public_tracking_token($payload['public_tracking_token'] ?? '');

    if ($publicTrackingToken === '') {
        $existingTrackingCode = $existingTracking['public_tracking_token'] ?? '';
        $publicTrackingToken = preg_match('/^\d{4}$/', (string) $existingTrackingCode)
            ? (string) $existingTrackingCode
            : generate_public_token();
    }

    $rateLimitKey = 'fare_update_' . $tripToken;
    $now = time();
    $lastUpdateAt = $_SESSION[$rateLimitKey] ?? 0;

    if ($lastUpdateAt && ($now - $lastUpdateAt) < GPS_UPDATE_MIN_INTERVAL_SECONDS) {
        api_throw('GPS sync is cooling down.', 429, ['code' => 'RATE_LIMITED']);
    }

    $_SESSION[$rateLimitKey] = $now;
    $breakdown = calculate_trip_fare($meters, $waitingSeconds, $vehicleType, $startedAt);

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

    $existingRoute = $existingTracking
        ? decode_trip_route_points_json($existingTracking['route_points_json'] ?? '[]', 120)
        : [];
    $mergedRoute = merge_trip_route_points($existingRoute, $incomingRoute, 120);

    $trackingSaved = upsert_live_tracking_record($tripToken, [
        'public_tracking_token' => $publicTrackingToken,
        'driver_id' => (int) $user['id'],
        'vehicle_type' => $breakdown['vehicle_type'],
        'status' => $status,
        'started_at' => to_mysql_datetime($startedAt),
        'last_lat' => $latitude,
        'last_lng' => $longitude,
        'meters' => $meters,
        'waiting_seconds' => $waitingSeconds,
        'current_fare' => $breakdown['final_fare'],
        'route_points_json' => json_encode($mergedRoute, JSON_UNESCAPED_SLASHES),
    ]);

    if (!$trackingSaved) {
        api_throw('Unable to update live tracking state.', 500, ['code' => 'INTERNAL_ERROR']);
    }

    return [
        'success' => true,
        'message' => 'Fare updated.',
        'final_fare' => $breakdown['final_fare'],
        'breakdown' => $breakdown,
        'server_time' => date(DATE_ATOM),
        'public_tracking_token' => $publicTrackingToken,
        'tracking_url' => absolute_url('index.php?token=' . $publicTrackingToken),
    ];
}

function finalize_trip_payload(array $payload, array $user)
{
    if (!ensure_tracking_schema()) {
        api_throw('Unable to prepare live tracking storage.', 500, ['code' => 'INTERNAL_ERROR']);
    }

    $tripToken = sanitize_trip_token($payload['trip_token'] ?? '');
    $vehicleType = trim((string) ($payload['vehicle_type'] ?? $user['vehicle_type']));
    $meters = max(0, (float) ($payload['meters'] ?? 0));
    $waitingSeconds = max(0, (int) ($payload['waiting_seconds'] ?? 0));
    $startedAt = to_mysql_datetime($payload['started_at'] ?? '');
    $endedAt = to_mysql_datetime($payload['ended_at'] ?? '');

    if ($tripToken === '') {
        api_throw('Trip token is required.', 422, ['code' => 'BAD_USER_INPUT']);
    }

    $existingTracking = find_live_tracking_by_trip_token($tripToken);
    $publicTrackingToken = sanitize_public_tracking_token($payload['public_tracking_token'] ?? '');

    if ($publicTrackingToken === '') {
        $existingTrackingCode = $existingTracking['public_tracking_token'] ?? '';
        $publicTrackingToken = preg_match('/^\d{4}$/', (string) $existingTrackingCode)
            ? (string) $existingTrackingCode
            : generate_public_token();
    }

    $existingTrip = find_trip_by_token($tripToken);
    if ($existingTrip) {
        return [
            'success' => true,
            'message' => 'Trip already finalized.',
            'trip_id' => (int) $existingTrip['id'],
            'receipt_url' => url('receipt.php?id=' . (int) $existingTrip['id']),
            'tracking_url' => absolute_url('index.php?token=' . $publicTrackingToken),
        ];
    }

    $sanitizedRoute = sanitize_trip_route_points($payload['route_points'] ?? [], 300);
    if (!$sanitizedRoute && $existingTracking) {
        $sanitizedRoute = decode_trip_route_points_json($existingTracking['route_points_json'] ?? '[]', 300);
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
        api_throw('Unable to save trip.', 500, ['code' => 'INTERNAL_ERROR']);
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
        api_throw('Trip saved but live tracking could not be updated.', 500, ['code' => 'INTERNAL_ERROR']);
    }

    if (isset($_SESSION['trip_point_meta'][$tripToken])) {
        unset($_SESSION['trip_point_meta'][$tripToken]);
    }

    if (isset($_SESSION['fare_update_' . $tripToken])) {
        unset($_SESSION['fare_update_' . $tripToken]);
    }

    return [
        'success' => true,
        'message' => 'Trip finalized.',
        'trip_id' => $tripId,
        'final_fare' => $breakdown['final_fare'],
        'receipt_url' => url('receipt.php?id=' . $tripId),
        'tracking_url' => absolute_url('index.php?token=' . $publicTrackingToken),
    ];
}
