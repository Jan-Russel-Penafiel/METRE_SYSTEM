<?php

require_once __DIR__ . '/../includes/graphql.php';

try {
    $request = graphql_read_request();
    $response = graphql_execute_request($request);

    if (!empty($response['cacheable'])) {
        tracking_status_cache_headers();
    }

    $payload = [
        'data' => $response['data'],
    ];

    if (!empty($response['errors'])) {
        $payload['errors'] = $response['errors'];
    }

    json_response($payload);
} catch (MetreApiException $exception) {
    $payload = [
        'data' => null,
        'errors' => [
            graphql_error_payload($exception),
        ],
    ];

    json_response($payload, $exception->getStatusCode());
}
