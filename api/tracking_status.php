<?php

require_once __DIR__ . '/../includes/api_services.php';

try {
    $payload = get_tracking_status_payload($_GET['token'] ?? '');
    tracking_status_cache_headers();
    json_response($payload);
} catch (MetreApiException $exception) {
    json_response(api_json_error_payload($exception), $exception->getStatusCode());
}
