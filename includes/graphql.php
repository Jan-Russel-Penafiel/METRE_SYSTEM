<?php

require_once __DIR__ . '/api_services.php';

function graphql_read_request()
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $variables = $_GET['variables'] ?? [];
        if (is_string($variables) && $variables !== '') {
            $decodedVariables = json_decode($variables, true);
            $variables = is_array($decodedVariables) ? $decodedVariables : [];
        }

        return [
            'method' => 'GET',
            'query' => (string) ($_GET['query'] ?? ''),
            'variables' => is_array($variables) ? $variables : [],
            'operationName' => (string) ($_GET['operationName'] ?? ''),
        ];
    }

    if ($method === 'POST') {
        $payload = json_input();

        return [
            'method' => 'POST',
            'query' => (string) ($payload['query'] ?? ''),
            'variables' => is_array($payload['variables'] ?? null) ? $payload['variables'] : [],
            'operationName' => (string) ($payload['operationName'] ?? ''),
        ];
    }

    api_throw('Method not allowed.', 405, ['code' => 'METHOD_NOT_ALLOWED']);
}

function graphql_execute_request(array $request)
{
    $query = trim((string) ($request['query'] ?? ''));
    $variables = is_array($request['variables'] ?? null) ? $request['variables'] : [];
    $operationName = trim((string) ($request['operationName'] ?? ''));

    if ($query === '') {
        api_throw('GraphQL query is required.', 400, ['code' => 'BAD_REQUEST']);
    }

    $document = graphql_parse_document($query);
    if ($operationName !== '' && !empty($document['name']) && $document['name'] !== $operationName) {
        api_throw('Operation name does not match the GraphQL document.', 400, ['code' => 'BAD_REQUEST']);
    }

    $response = [
        'data' => [],
        'errors' => [],
        'cacheable' => false,
    ];

    foreach ($document['selection'] as $field) {
        try {
            $resolved = graphql_resolve_root_field($document['type'], $field, $variables);
            $response['data'][$field['response_key']] = graphql_project_value($resolved, $field['selection']);
        } catch (MetreApiException $exception) {
            $response['data'][$field['response_key']] = null;
            $response['errors'][] = graphql_error_payload($exception, [$field['response_key']]);
        }
    }

    $response['cacheable'] = graphql_document_is_cacheable($document, $response['errors']);

    return $response;
}

function graphql_document_is_cacheable(array $document, array $errors)
{
    if ($errors) {
        return false;
    }

    if (($document['type'] ?? 'query') !== 'query') {
        return false;
    }

    if (count($document['selection'] ?? []) !== 1) {
        return false;
    }

    $field = $document['selection'][0];
    return ($field['name'] ?? '') === 'trackingStatus';
}

function graphql_error_payload(MetreApiException $exception, array $path = [])
{
    $extensions = array_merge([
        'httpStatus' => $exception->getStatusCode(),
    ], $exception->getExtensions());

    $payload = [
        'message' => $exception->getMessage(),
        'extensions' => $extensions,
    ];

    if ($path) {
        $payload['path'] = $path;
    }

    return $payload;
}

function graphql_resolve_root_field($operationType, array $field, array $variables)
{
    $fieldName = (string) ($field['name'] ?? '');

    if ($fieldName === 'trackingStatus') {
        if ($operationType !== 'query') {
            api_throw('trackingStatus is only available on Query.', 400, ['code' => 'BAD_REQUEST']);
        }

        $token = graphql_field_argument($field, 'token', $variables, true);
        return get_tracking_status_payload($token);
    }

    if ($fieldName === 'updateFare') {
        if ($operationType !== 'mutation') {
            api_throw('updateFare is only available on Mutation.', 400, ['code' => 'BAD_REQUEST']);
        }

        $input = graphql_field_argument($field, 'input', $variables, true);
        if (!is_array($input)) {
            api_throw('updateFare input must be an object.', 422, ['code' => 'BAD_USER_INPUT']);
        }

        return sync_fare_payload($input, require_api_user(['driver', 'admin']));
    }

    if ($fieldName === 'endTrip') {
        if ($operationType !== 'mutation') {
            api_throw('endTrip is only available on Mutation.', 400, ['code' => 'BAD_REQUEST']);
        }

        $input = graphql_field_argument($field, 'input', $variables, true);
        if (!is_array($input)) {
            api_throw('endTrip input must be an object.', 422, ['code' => 'BAD_USER_INPUT']);
        }

        return finalize_trip_payload($input, require_api_user(['driver', 'admin']));
    }

    api_throw('Unknown GraphQL field "' . $fieldName . '".', 400, ['code' => 'BAD_REQUEST']);
}

function graphql_field_argument(array $field, $name, array $variables, $required = false)
{
    if (array_key_exists($name, $field['arguments'])) {
        return graphql_resolve_value($field['arguments'][$name], $variables);
    }

    if (array_key_exists($name, $variables)) {
        return $variables[$name];
    }

    if ($required) {
        api_throw('Missing GraphQL argument "' . $name . '".', 422, ['code' => 'BAD_USER_INPUT']);
    }

    return null;
}

function graphql_project_value($value, array $selection)
{
    if (!$selection) {
        return $value;
    }

    if (!is_array($value)) {
        return $value;
    }

    if (data_is_list_array($value)) {
        $items = [];

        foreach ($value as $item) {
            $items[] = graphql_project_value($item, $selection);
        }

        return $items;
    }

    $projected = [];

    foreach ($selection as $field) {
        $responseKey = $field['response_key'];
        $sourceKey = $field['name'];

        if ($sourceKey === '__typename') {
            $projected[$responseKey] = 'Object';
            continue;
        }

        if (!array_key_exists($sourceKey, $value)) {
            $projected[$responseKey] = null;
            continue;
        }

        $projected[$responseKey] = graphql_project_value($value[$sourceKey], $field['selection']);
    }

    return $projected;
}

function graphql_resolve_value($value, array $variables)
{
    if (!is_array($value) || !isset($value['__graphql_kind'])) {
        return $value;
    }

    if ($value['__graphql_kind'] === 'variable') {
        $variableName = (string) ($value['name'] ?? '');

        if (!array_key_exists($variableName, $variables)) {
            api_throw('Missing GraphQL variable "$' . $variableName . '".', 422, ['code' => 'BAD_USER_INPUT']);
        }

        return $variables[$variableName];
    }

    if ($value['__graphql_kind'] === 'object') {
        $resolved = [];

        foreach ((array) ($value['fields'] ?? []) as $fieldName => $fieldValue) {
            $resolved[$fieldName] = graphql_resolve_value($fieldValue, $variables);
        }

        return $resolved;
    }

    if ($value['__graphql_kind'] === 'list') {
        $resolved = [];

        foreach ((array) ($value['items'] ?? []) as $item) {
            $resolved[] = graphql_resolve_value($item, $variables);
        }

        return $resolved;
    }

    return $value;
}

function graphql_parse_document($source)
{
    $state = [
        'source' => (string) $source,
        'length' => strlen((string) $source),
        'index' => 0,
    ];

    graphql_skip_ignored($state);

    if (graphql_peek($state) === '{') {
        return [
            'type' => 'query',
            'name' => '',
            'selection' => graphql_parse_selection_set($state),
        ];
    }

    $operationType = graphql_parse_name($state);
    if (!in_array($operationType, ['query', 'mutation'], true)) {
        api_throw('Unsupported GraphQL operation type.', 400, ['code' => 'BAD_REQUEST']);
    }

    graphql_skip_ignored($state);
    $operationName = '';
    $next = graphql_peek($state);

    if ($next !== null && preg_match('/[A-Za-z_]/', $next) === 1) {
        $operationName = graphql_parse_name($state);
        graphql_skip_ignored($state);
    }

    if (graphql_peek($state) === '(') {
        graphql_skip_balanced_block($state, '(', ')');
        graphql_skip_ignored($state);
    }

    while (graphql_peek($state) === '@') {
        graphql_skip_directive($state);
        graphql_skip_ignored($state);
    }

    $selection = graphql_parse_selection_set($state);
    graphql_skip_ignored($state);

    if (!graphql_is_eof($state)) {
        api_throw('Unexpected trailing GraphQL content.', 400, ['code' => 'BAD_REQUEST']);
    }

    return [
        'type' => $operationType,
        'name' => $operationName,
        'selection' => $selection,
    ];
}

function graphql_parse_selection_set(array &$state)
{
    graphql_expect($state, '{');
    $selection = [];

    while (true) {
        graphql_skip_ignored($state);
        $next = graphql_peek($state);

        if ($next === null) {
            api_throw('Unterminated GraphQL selection set.', 400, ['code' => 'BAD_REQUEST']);
        }

        if ($next === '}') {
            $state['index']++;
            return $selection;
        }

        $selection[] = graphql_parse_field($state);
    }
}

function graphql_parse_field(array &$state)
{
    $firstName = graphql_parse_name($state);
    $responseKey = $firstName;
    $fieldName = $firstName;

    graphql_skip_ignored($state);
    if (graphql_peek($state) === ':') {
        $state['index']++;
        $fieldName = graphql_parse_name($state);
    }

    $arguments = graphql_parse_arguments($state);

    while (graphql_peek($state) === '@') {
        graphql_skip_directive($state);
    }

    graphql_skip_ignored($state);
    $selection = [];

    if (graphql_peek($state) === '{') {
        $selection = graphql_parse_selection_set($state);
    }

    return [
        'response_key' => $responseKey,
        'name' => $fieldName,
        'arguments' => $arguments,
        'selection' => $selection,
    ];
}

function graphql_parse_arguments(array &$state)
{
    graphql_skip_ignored($state);

    if (graphql_peek($state) !== '(') {
        return [];
    }

    $state['index']++;
    $arguments = [];

    while (true) {
        graphql_skip_ignored($state);
        $next = graphql_peek($state);

        if ($next === null) {
            api_throw('Unterminated GraphQL argument list.', 400, ['code' => 'BAD_REQUEST']);
        }

        if ($next === ')') {
            $state['index']++;
            return $arguments;
        }

        $argumentName = graphql_parse_name($state);
        graphql_skip_ignored($state);
        graphql_expect($state, ':');
        $arguments[$argumentName] = graphql_parse_value($state);
    }
}

function graphql_parse_value(array &$state)
{
    graphql_skip_ignored($state);
    $next = graphql_peek($state);

    if ($next === null) {
        api_throw('Unexpected end of GraphQL value.', 400, ['code' => 'BAD_REQUEST']);
    }

    if ($next === '$') {
        $state['index']++;

        return [
            '__graphql_kind' => 'variable',
            'name' => graphql_parse_name($state),
        ];
    }

    if ($next === '"') {
        return graphql_parse_string($state);
    }

    if ($next === '{') {
        $state['index']++;
        $fields = [];

        while (true) {
            graphql_skip_ignored($state);
            $peek = graphql_peek($state);

            if ($peek === null) {
                api_throw('Unterminated GraphQL input object.', 400, ['code' => 'BAD_REQUEST']);
            }

            if ($peek === '}') {
                $state['index']++;

                return [
                    '__graphql_kind' => 'object',
                    'fields' => $fields,
                ];
            }

            $fieldName = graphql_parse_name($state);
            graphql_skip_ignored($state);
            graphql_expect($state, ':');
            $fields[$fieldName] = graphql_parse_value($state);
        }
    }

    if ($next === '[') {
        $state['index']++;
        $items = [];

        while (true) {
            graphql_skip_ignored($state);
            $peek = graphql_peek($state);

            if ($peek === null) {
                api_throw('Unterminated GraphQL list.', 400, ['code' => 'BAD_REQUEST']);
            }

            if ($peek === ']') {
                $state['index']++;

                return [
                    '__graphql_kind' => 'list',
                    'items' => $items,
                ];
            }

            $items[] = graphql_parse_value($state);
        }
    }

    if ($next === '-' || ctype_digit($next)) {
        return graphql_parse_number($state);
    }

    $name = graphql_parse_name($state);

    if ($name === 'true') {
        return true;
    }

    if ($name === 'false') {
        return false;
    }

    if ($name === 'null') {
        return null;
    }

    return $name;
}

function graphql_parse_string(array &$state)
{
    graphql_expect($state, '"');
    $value = '';

    while (true) {
        if (graphql_is_eof($state)) {
            api_throw('Unterminated GraphQL string.', 400, ['code' => 'BAD_REQUEST']);
        }

        $char = $state['source'][$state['index']];
        $state['index']++;

        if ($char === '"') {
            return $value;
        }

        if ($char !== '\\') {
            $value .= $char;
            continue;
        }

        if (graphql_is_eof($state)) {
            api_throw('Invalid GraphQL string escape.', 400, ['code' => 'BAD_REQUEST']);
        }

        $escaped = $state['source'][$state['index']];
        $state['index']++;

        $map = [
            '"' => '"',
            '\\' => '\\',
            '/' => '/',
            'b' => "\x08",
            'f' => "\x0C",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
        ];

        if (isset($map[$escaped])) {
            $value .= $map[$escaped];
            continue;
        }

        if ($escaped === 'u') {
            $hex = substr($state['source'], $state['index'], 4);
            if (strlen($hex) !== 4 || !ctype_xdigit($hex)) {
                api_throw('Invalid GraphQL unicode escape.', 400, ['code' => 'BAD_REQUEST']);
            }

            $value .= html_entity_decode('&#x' . $hex . ';', ENT_QUOTES, 'UTF-8');
            $state['index'] += 4;
            continue;
        }

        api_throw('Unsupported GraphQL string escape.', 400, ['code' => 'BAD_REQUEST']);
    }
}

function graphql_parse_number(array &$state)
{
    $start = $state['index'];

    if (graphql_peek($state) === '-') {
        $state['index']++;
    }

    while (!graphql_is_eof($state) && ctype_digit($state['source'][$state['index']])) {
        $state['index']++;
    }

    if (!graphql_is_eof($state) && $state['source'][$state['index']] === '.') {
        $state['index']++;

        while (!graphql_is_eof($state) && ctype_digit($state['source'][$state['index']])) {
            $state['index']++;
        }
    }

    $raw = substr($state['source'], $start, $state['index'] - $start);

    if ($raw === '' || $raw === '-') {
        api_throw('Invalid GraphQL number.', 400, ['code' => 'BAD_REQUEST']);
    }

    return strpos($raw, '.') !== false ? (float) $raw : (int) $raw;
}

function graphql_skip_directive(array &$state)
{
    graphql_expect($state, '@');
    graphql_parse_name($state);
    graphql_skip_ignored($state);

    if (graphql_peek($state) === '(') {
        graphql_skip_balanced_block($state, '(', ')');
    }
}

function graphql_skip_balanced_block(array &$state, $open, $close)
{
    graphql_expect($state, $open);
    $depth = 1;

    while ($depth > 0) {
        if (graphql_is_eof($state)) {
            api_throw('Unterminated GraphQL block.', 400, ['code' => 'BAD_REQUEST']);
        }

        $char = $state['source'][$state['index']];

        if ($char === '"') {
            graphql_parse_string($state);
            continue;
        }

        $state['index']++;

        if ($char === $open) {
            $depth++;
            continue;
        }

        if ($char === $close) {
            $depth--;
        }
    }
}

function graphql_parse_name(array &$state)
{
    graphql_skip_ignored($state);

    if (graphql_is_eof($state)) {
        api_throw('Unexpected end of GraphQL document.', 400, ['code' => 'BAD_REQUEST']);
    }

    $char = $state['source'][$state['index']];
    if (preg_match('/[A-Za-z_]/', $char) !== 1) {
        api_throw('Expected a GraphQL name.', 400, ['code' => 'BAD_REQUEST']);
    }

    $start = $state['index'];
    $state['index']++;

    while (!graphql_is_eof($state) && preg_match('/[A-Za-z0-9_]/', $state['source'][$state['index']]) === 1) {
        $state['index']++;
    }

    return substr($state['source'], $start, $state['index'] - $start);
}

function graphql_skip_ignored(array &$state)
{
    while (!graphql_is_eof($state)) {
        $char = $state['source'][$state['index']];

        if ($char === ',' || ctype_space($char)) {
            $state['index']++;
            continue;
        }

        if ($char === '#') {
            while (!graphql_is_eof($state)) {
                $commentChar = $state['source'][$state['index']];
                $state['index']++;

                if ($commentChar === "\n" || $commentChar === "\r") {
                    break;
                }
            }

            continue;
        }

        break;
    }
}

function graphql_expect(array &$state, $expected)
{
    graphql_skip_ignored($state);

    if (graphql_peek($state) !== $expected) {
        api_throw('Malformed GraphQL document.', 400, ['code' => 'BAD_REQUEST']);
    }

    $state['index']++;
}

function graphql_peek(array $state)
{
    if ($state['index'] >= $state['length']) {
        return null;
    }

    return $state['source'][$state['index']];
}

function graphql_is_eof(array $state)
{
    return $state['index'] >= $state['length'];
}
