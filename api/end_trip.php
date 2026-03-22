<?php

require_once __DIR__ . '/../includes/api_services.php';

try {
    require_api_method('POST');
    $user = require_api_user(['driver', 'admin']);
    $payload = finalize_trip_payload(json_input(), $user);
    json_response($payload);
} catch (MetreApiException $exception) {
    json_response(api_json_error_payload($exception), $exception->getStatusCode());
}
