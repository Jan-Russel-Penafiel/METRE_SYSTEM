<?php

function data_now()
{
    return date('Y-m-d H:i:s');
}

function data_seed_timestamp()
{
    return '2026-01-01 00:00:00';
}

function data_is_list_array($value)
{
    if (!is_array($value)) {
        return false;
    }

    $expectedKey = 0;

    foreach ($value as $key => $_) {
        if ($key !== $expectedKey) {
            return false;
        }

        $expectedKey++;
    }

    return true;
}

function data_collection_names()
{
    return [
        'users',
        'fare_settings',
        'trips',
        'trip_points',
        'live_trip_tracking',
    ];
}

function data_directory()
{
    return DATA_STORAGE_PATH;
}

function data_collection_path($collection)
{
    if (!in_array($collection, data_collection_names(), true)) {
        throw new RuntimeException('Unknown data collection: ' . $collection);
    }

    return data_directory() . DIRECTORY_SEPARATOR . $collection . '.json';
}

function data_seed_records($collection)
{
    $seededAt = data_seed_timestamp();

    switch ($collection) {
        case 'users':
            return [
                [
                    'id' => 1,
                    'full_name' => 'System Administrator',
                    'username' => 'admin',
                    'password_hash' => '$2y$10$H0q/VXMippvhysojslvbSeTUOaYlYHFbQqSb3Zp1I2O36y0D/xpp6',
                    'user_type' => 'admin',
                    'vehicle_type' => 'Standard Taxi',
                    'is_active' => 1,
                    'created_at' => $seededAt,
                ],
                [
                    'id' => 2,
                    'full_name' => 'Driver One',
                    'username' => 'driver1',
                    'password_hash' => '$2y$10$0jQXcaJWkahaJJ7oeOv3DeSCTXtrU4mQ3WN/e9v2gATyz0lyClypq',
                    'user_type' => 'driver',
                    'vehicle_type' => 'Standard Taxi',
                    'is_active' => 1,
                    'created_at' => $seededAt,
                ],
            ];
        case 'fare_settings':
            return [
                [
                    'id' => 1,
                    'vehicle_type' => 'Standard Taxi',
                    'rate_per_meter' => 0.15,
                    'minimum_fare' => 40,
                    'night_surcharge_percent' => 20,
                    'waiting_rate_per_minute' => 2,
                    'updated_at' => $seededAt,
                ],
                [
                    'id' => 2,
                    'vehicle_type' => 'Premium Sedan',
                    'rate_per_meter' => 0.18,
                    'minimum_fare' => 60,
                    'night_surcharge_percent' => 20,
                    'waiting_rate_per_minute' => 2.5,
                    'updated_at' => $seededAt,
                ],
                [
                    'id' => 3,
                    'vehicle_type' => 'SUV',
                    'rate_per_meter' => 0.22,
                    'minimum_fare' => 75,
                    'night_surcharge_percent' => 20,
                    'waiting_rate_per_minute' => 3,
                    'updated_at' => $seededAt,
                ],
                [
                    'id' => 4,
                    'vehicle_type' => 'Van',
                    'rate_per_meter' => 0.25,
                    'minimum_fare' => 85,
                    'night_surcharge_percent' => 20,
                    'waiting_rate_per_minute' => 3.5,
                    'updated_at' => $seededAt,
                ],
            ];
        case 'trips':
        case 'trip_points':
        case 'live_trip_tracking':
            return [];
    }

    return [];
}

function data_default_payload($collection)
{
    $records = array_values(data_seed_records($collection));
    $lastId = 0;

    foreach ($records as $record) {
        $lastId = max($lastId, (int) ($record['id'] ?? 0));
    }

    return [
        'last_id' => $lastId,
        'records' => $records,
    ];
}

function data_normalize_payload($collection, $payload)
{
    if (!is_array($payload)) {
        return data_default_payload($collection);
    }

    if (isset($payload['records']) && is_array($payload['records'])) {
        $records = array_values($payload['records']);
    } elseif (data_is_list_array($payload)) {
        $records = array_values($payload);
    } else {
        $records = data_seed_records($collection);
    }

    $lastId = isset($payload['last_id']) ? (int) $payload['last_id'] : 0;

    foreach ($records as $record) {
        $lastId = max($lastId, (int) ($record['id'] ?? 0));
    }

    return [
        'last_id' => $lastId,
        'records' => $records,
    ];
}

function data_encode_payload($payload)
{
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function data_initialize_collection($collection)
{
    $directory = data_directory();

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    $path = data_collection_path($collection);

    if (is_file($path) && filesize($path) > 0) {
        return true;
    }

    return file_put_contents($path, data_encode_payload(data_default_payload($collection)), LOCK_EX) !== false;
}

function ensure_data_store()
{
    foreach (data_collection_names() as $collection) {
        if (!data_initialize_collection($collection)) {
            return false;
        }
    }

    return true;
}

function data_read_payload_from_handle($handle, $collection)
{
    rewind($handle);
    $contents = stream_get_contents($handle);

    if (!is_string($contents) || trim($contents) === '') {
        return data_default_payload($collection);
    }

    return data_normalize_payload($collection, json_decode($contents, true));
}

function data_write_payload_to_handle($handle, $collection, $payload)
{
    $normalized = data_normalize_payload($collection, $payload);
    rewind($handle);
    ftruncate($handle, 0);
    $written = fwrite($handle, data_encode_payload($normalized));
    fflush($handle);

    return $written !== false;
}

function data_read_collection($collection)
{
    if (!data_initialize_collection($collection)) {
        return data_default_payload($collection);
    }

    $handle = fopen(data_collection_path($collection), 'c+');

    if (!$handle) {
        return data_default_payload($collection);
    }

    flock($handle, LOCK_SH);
    $payload = data_read_payload_from_handle($handle, $collection);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $payload;
}

function data_mutate_collection($collection, callable $callback)
{
    if (!data_initialize_collection($collection)) {
        return false;
    }

    $handle = fopen(data_collection_path($collection), 'c+');

    if (!$handle) {
        return false;
    }

    flock($handle, LOCK_EX);
    $payload = data_read_payload_from_handle($handle, $collection);
    $result = $callback($payload);
    $writeOk = data_write_payload_to_handle($handle, $collection, $payload);
    flock($handle, LOCK_UN);
    fclose($handle);

    if (!$writeOk) {
        return false;
    }

    return $result;
}

function data_records($collection)
{
    $payload = data_read_collection($collection);
    return $payload['records'];
}

function find_user_by_id($id)
{
    foreach (data_records('users') as $user) {
        if ((int) ($user['id'] ?? 0) === (int) $id) {
            return $user;
        }
    }

    return null;
}

function find_user_by_username($username, $activeOnly = false)
{
    $username = trim((string) $username);

    foreach (data_records('users') as $user) {
        if (strcasecmp((string) ($user['username'] ?? ''), $username) !== 0) {
            continue;
        }

        if ($activeOnly && empty($user['is_active'])) {
            continue;
        }

        return $user;
    }

    return null;
}

function username_exists($username, $excludeId = 0)
{
    $username = trim((string) $username);

    foreach (data_records('users') as $user) {
        if ((int) ($user['id'] ?? 0) === (int) $excludeId) {
            continue;
        }

        if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
            return true;
        }
    }

    return false;
}

function list_users()
{
    $users = data_records('users');

    usort($users, function ($left, $right) {
        $createdCompare = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));

        if ($createdCompare !== 0) {
            return $createdCompare;
        }

        return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
    });

    return $users;
}

function count_active_drivers()
{
    $total = 0;

    foreach (data_records('users') as $user) {
        if (($user['user_type'] ?? '') === 'driver' && !empty($user['is_active'])) {
            $total++;
        }
    }

    return $total;
}

function save_user_record($id, array $attributes)
{
    return data_mutate_collection('users', function (&$payload) use ($id, $attributes) {
        $now = data_now();

        if ((int) $id > 0) {
            foreach ($payload['records'] as $index => $record) {
                if ((int) ($record['id'] ?? 0) !== (int) $id) {
                    continue;
                }

                $payload['records'][$index] = array_merge($record, [
                    'full_name' => (string) ($attributes['full_name'] ?? $record['full_name'] ?? ''),
                    'username' => (string) ($attributes['username'] ?? $record['username'] ?? ''),
                    'user_type' => (string) ($attributes['user_type'] ?? $record['user_type'] ?? 'driver'),
                    'vehicle_type' => (string) ($attributes['vehicle_type'] ?? $record['vehicle_type'] ?? 'Standard Taxi'),
                    'is_active' => (int) ($attributes['is_active'] ?? $record['is_active'] ?? 1),
                    'password_hash' => (string) ($attributes['password_hash'] ?? $record['password_hash'] ?? ''),
                    'created_at' => (string) ($record['created_at'] ?? $now),
                ]);

                return $payload['records'][$index];
            }
        }

        $payload['last_id']++;
        $newRecord = [
            'id' => (int) $payload['last_id'],
            'full_name' => (string) ($attributes['full_name'] ?? ''),
            'username' => (string) ($attributes['username'] ?? ''),
            'password_hash' => (string) ($attributes['password_hash'] ?? ''),
            'user_type' => (string) ($attributes['user_type'] ?? 'driver'),
            'vehicle_type' => (string) ($attributes['vehicle_type'] ?? 'Standard Taxi'),
            'is_active' => (int) ($attributes['is_active'] ?? 1),
            'created_at' => (string) ($attributes['created_at'] ?? $now),
        ];

        $payload['records'][] = $newRecord;

        return $newRecord;
    });
}

function find_fare_setting_by_id($id)
{
    foreach (data_records('fare_settings') as $setting) {
        if ((int) ($setting['id'] ?? 0) === (int) $id) {
            return $setting;
        }
    }

    return null;
}

function fare_setting_exists_for_vehicle($vehicleType, $excludeId = 0)
{
    $vehicleType = trim((string) $vehicleType);

    foreach (data_records('fare_settings') as $setting) {
        if ((int) ($setting['id'] ?? 0) === (int) $excludeId) {
            continue;
        }

        if (strcasecmp((string) ($setting['vehicle_type'] ?? ''), $vehicleType) === 0) {
            return true;
        }
    }

    return false;
}

function list_fare_settings_records()
{
    $settings = data_records('fare_settings');

    usort($settings, function ($left, $right) {
        $vehicleCompare = strcasecmp((string) ($left['vehicle_type'] ?? ''), (string) ($right['vehicle_type'] ?? ''));

        if ($vehicleCompare !== 0) {
            return $vehicleCompare;
        }

        return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
    });

    return $settings;
}

function save_fare_setting_record($id, array $attributes)
{
    return data_mutate_collection('fare_settings', function (&$payload) use ($id, $attributes) {
        $now = data_now();

        if ((int) $id > 0) {
            foreach ($payload['records'] as $index => $record) {
                if ((int) ($record['id'] ?? 0) !== (int) $id) {
                    continue;
                }

                $payload['records'][$index] = array_merge($record, [
                    'vehicle_type' => (string) ($attributes['vehicle_type'] ?? $record['vehicle_type'] ?? ''),
                    'rate_per_meter' => (float) ($attributes['rate_per_meter'] ?? $record['rate_per_meter'] ?? 0),
                    'minimum_fare' => (float) ($attributes['minimum_fare'] ?? $record['minimum_fare'] ?? 0),
                    'night_surcharge_percent' => (float) ($attributes['night_surcharge_percent'] ?? $record['night_surcharge_percent'] ?? 0),
                    'waiting_rate_per_minute' => (float) ($attributes['waiting_rate_per_minute'] ?? $record['waiting_rate_per_minute'] ?? 0),
                    'updated_at' => $now,
                ]);

                return $payload['records'][$index];
            }
        }

        $payload['last_id']++;
        $newRecord = [
            'id' => (int) $payload['last_id'],
            'vehicle_type' => (string) ($attributes['vehicle_type'] ?? ''),
            'rate_per_meter' => (float) ($attributes['rate_per_meter'] ?? 0),
            'minimum_fare' => (float) ($attributes['minimum_fare'] ?? 0),
            'night_surcharge_percent' => (float) ($attributes['night_surcharge_percent'] ?? 0),
            'waiting_rate_per_minute' => (float) ($attributes['waiting_rate_per_minute'] ?? 0),
            'updated_at' => $now,
        ];

        $payload['records'][] = $newRecord;

        return $newRecord;
    });
}

function count_fare_settings_records()
{
    return count(data_records('fare_settings'));
}

function hydrate_trip_record(array $trip)
{
    $driver = find_user_by_id((int) ($trip['driver_id'] ?? 0));
    $trip['full_name'] = $driver['full_name'] ?? 'Unknown driver';
    $trip['username'] = $driver['username'] ?? '';

    return $trip;
}

function find_trip_by_id($id)
{
    foreach (data_records('trips') as $trip) {
        if ((int) ($trip['id'] ?? 0) === (int) $id) {
            return hydrate_trip_record($trip);
        }
    }

    return null;
}

function find_trip_by_token($tripToken)
{
    foreach (data_records('trips') as $trip) {
        if ((string) ($trip['trip_token'] ?? '') === (string) $tripToken) {
            return $trip;
        }
    }

    return null;
}

function insert_trip_record(array $attributes)
{
    return data_mutate_collection('trips', function (&$payload) use ($attributes) {
        $payload['last_id']++;
        $newRecord = [
            'id' => (int) $payload['last_id'],
            'trip_token' => (string) ($attributes['trip_token'] ?? ''),
            'public_tracking_token' => (string) ($attributes['public_tracking_token'] ?? ''),
            'driver_id' => (int) ($attributes['driver_id'] ?? 0),
            'vehicle_type' => (string) ($attributes['vehicle_type'] ?? ''),
            'started_at' => (string) ($attributes['started_at'] ?? data_now()),
            'ended_at' => (string) ($attributes['ended_at'] ?? data_now()),
            'total_meters' => (float) ($attributes['total_meters'] ?? 0),
            'waiting_seconds' => (int) ($attributes['waiting_seconds'] ?? 0),
            'final_fare' => (float) ($attributes['final_fare'] ?? 0),
            'fare_breakdown_json' => (string) ($attributes['fare_breakdown_json'] ?? '{}'),
            'route_points_json' => (string) ($attributes['route_points_json'] ?? '[]'),
            'start_lat' => array_key_exists('start_lat', $attributes) ? ($attributes['start_lat'] === null ? null : (float) $attributes['start_lat']) : null,
            'start_lng' => array_key_exists('start_lng', $attributes) ? ($attributes['start_lng'] === null ? null : (float) $attributes['start_lng']) : null,
            'end_lat' => array_key_exists('end_lat', $attributes) ? ($attributes['end_lat'] === null ? null : (float) $attributes['end_lat']) : null,
            'end_lng' => array_key_exists('end_lng', $attributes) ? ($attributes['end_lng'] === null ? null : (float) $attributes['end_lng']) : null,
            'trip_status' => (string) ($attributes['trip_status'] ?? 'completed'),
            'created_at' => (string) ($attributes['created_at'] ?? data_now()),
        ];

        $payload['records'][] = $newRecord;

        return $newRecord;
    });
}

function trip_matches_filters(array $trip, array $options)
{
    $dateFrom = trim((string) ($options['date_from'] ?? ''));
    $dateTo = trim((string) ($options['date_to'] ?? ''));
    $driverId = isset($options['driver_id']) ? (int) $options['driver_id'] : 0;
    $search = trim((string) ($options['search'] ?? ''));
    $startedDate = substr((string) ($trip['started_at'] ?? ''), 0, 10);

    if ($dateFrom !== '' && $startedDate < $dateFrom) {
        return false;
    }

    if ($dateTo !== '' && $startedDate > $dateTo) {
        return false;
    }

    if ($driverId > 0 && (int) ($trip['driver_id'] ?? 0) !== $driverId) {
        return false;
    }

    if ($search !== '') {
        $haystacks = [
            (string) ($trip['vehicle_type'] ?? ''),
            (string) ($trip['full_name'] ?? ''),
            (string) ($trip['username'] ?? ''),
        ];

        $matched = false;

        foreach ($haystacks as $haystack) {
            if (stripos($haystack, $search) !== false) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return false;
        }
    }

    return true;
}

function list_trips_filtered(array $options = [])
{
    $trips = array_map('hydrate_trip_record', data_records('trips'));

    $trips = array_values(array_filter($trips, function ($trip) use ($options) {
        return trip_matches_filters($trip, $options);
    }));

    usort($trips, function ($left, $right) {
        $startedCompare = strcmp((string) ($right['started_at'] ?? ''), (string) ($left['started_at'] ?? ''));

        if ($startedCompare !== 0) {
            return $startedCompare;
        }

        return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
    });

    if (isset($options['limit']) && $options['limit'] !== null) {
        $trips = array_slice($trips, 0, max(0, (int) $options['limit']));
    }

    return $trips;
}

function summarize_trips(array $options = [])
{
    $trips = list_trips_filtered($options);
    $tripCount = count($trips);
    $revenue = 0;
    $totalMeters = 0;

    foreach ($trips as $trip) {
        $revenue += (float) ($trip['final_fare'] ?? 0);
        $totalMeters += (float) ($trip['total_meters'] ?? 0);
    }

    return [
        'trip_count' => $tripCount,
        'revenue' => $revenue,
        'average_fare' => $tripCount > 0 ? $revenue / $tripCount : 0,
        'total_meters' => $totalMeters,
    ];
}

function hydrate_live_tracking_record(array $tracking)
{
    $driver = find_user_by_id((int) ($tracking['driver_id'] ?? 0));
    $tracking['full_name'] = $driver['full_name'] ?? 'Unknown driver';

    return $tracking;
}

function live_tracking_status_rank($status)
{
    $map = [
        'in_trip' => 3,
        'waiting' => 2,
        'completed' => 1,
    ];

    return $map[$status] ?? 0;
}

function find_live_tracking_by_trip_token($tripToken)
{
    foreach (data_records('live_trip_tracking') as $tracking) {
        if ((string) ($tracking['trip_token'] ?? '') === (string) $tripToken) {
            return $tracking;
        }
    }

    return null;
}

function find_live_tracking_by_public_token($token)
{
    $matches = [];

    foreach (data_records('live_trip_tracking') as $tracking) {
        if ((string) ($tracking['public_tracking_token'] ?? '') === (string) $token) {
            $matches[] = hydrate_live_tracking_record($tracking);
        }
    }

    if (!$matches) {
        return null;
    }

    usort($matches, function ($left, $right) {
        $rankCompare = live_tracking_status_rank((string) ($right['status'] ?? '')) <=> live_tracking_status_rank((string) ($left['status'] ?? ''));

        if ($rankCompare !== 0) {
            return $rankCompare;
        }

        $updatedCompare = strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));

        if ($updatedCompare !== 0) {
            return $updatedCompare;
        }

        return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
    });

    return $matches[0];
}

function list_active_live_tracking($limit = 8)
{
    $records = [];

    foreach (data_records('live_trip_tracking') as $tracking) {
        if (!in_array((string) ($tracking['status'] ?? ''), ['waiting', 'in_trip'], true)) {
            continue;
        }

        $records[] = hydrate_live_tracking_record($tracking);
    }

    usort($records, function ($left, $right) {
        $updatedCompare = strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));

        if ($updatedCompare !== 0) {
            return $updatedCompare;
        }

        return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
    });

    return array_slice($records, 0, max(0, (int) $limit));
}

function upsert_live_tracking_record($tripToken, array $attributes)
{
    return data_mutate_collection('live_trip_tracking', function (&$payload) use ($tripToken, $attributes) {
        $now = data_now();

        foreach ($payload['records'] as $index => $record) {
            if ((string) ($record['trip_token'] ?? '') !== (string) $tripToken) {
                continue;
            }

            $payload['records'][$index] = array_merge($record, [
                'trip_token' => (string) $tripToken,
                'public_tracking_token' => (string) ($attributes['public_tracking_token'] ?? $record['public_tracking_token'] ?? ''),
                'driver_id' => (int) ($attributes['driver_id'] ?? $record['driver_id'] ?? 0),
                'vehicle_type' => (string) ($attributes['vehicle_type'] ?? $record['vehicle_type'] ?? ''),
                'status' => (string) ($attributes['status'] ?? $record['status'] ?? 'waiting'),
                'started_at' => array_key_exists('started_at', $attributes) ? $attributes['started_at'] : ($record['started_at'] ?? null),
                'ended_at' => array_key_exists('ended_at', $attributes) ? $attributes['ended_at'] : ($record['ended_at'] ?? null),
                'last_lat' => array_key_exists('last_lat', $attributes) ? $attributes['last_lat'] : ($record['last_lat'] ?? null),
                'last_lng' => array_key_exists('last_lng', $attributes) ? $attributes['last_lng'] : ($record['last_lng'] ?? null),
                'meters' => (float) ($attributes['meters'] ?? $record['meters'] ?? 0),
                'waiting_seconds' => (int) ($attributes['waiting_seconds'] ?? $record['waiting_seconds'] ?? 0),
                'current_fare' => (float) ($attributes['current_fare'] ?? $record['current_fare'] ?? 0),
                'route_points_json' => (string) ($attributes['route_points_json'] ?? $record['route_points_json'] ?? '[]'),
                'updated_at' => $now,
                'created_at' => (string) ($record['created_at'] ?? $now),
            ]);

            return $payload['records'][$index];
        }

        $payload['last_id']++;
        $newRecord = [
            'id' => (int) $payload['last_id'],
            'trip_token' => (string) $tripToken,
            'public_tracking_token' => (string) ($attributes['public_tracking_token'] ?? ''),
            'driver_id' => (int) ($attributes['driver_id'] ?? 0),
            'vehicle_type' => (string) ($attributes['vehicle_type'] ?? ''),
            'status' => (string) ($attributes['status'] ?? 'waiting'),
            'started_at' => $attributes['started_at'] ?? null,
            'ended_at' => $attributes['ended_at'] ?? null,
            'last_lat' => $attributes['last_lat'] ?? null,
            'last_lng' => $attributes['last_lng'] ?? null,
            'meters' => (float) ($attributes['meters'] ?? 0),
            'waiting_seconds' => (int) ($attributes['waiting_seconds'] ?? 0),
            'current_fare' => (float) ($attributes['current_fare'] ?? 0),
            'route_points_json' => (string) ($attributes['route_points_json'] ?? '[]'),
            'updated_at' => $now,
            'created_at' => $now,
        ];

        $payload['records'][] = $newRecord;

        return $newRecord;
    });
}

function insert_trip_point_record(array $attributes)
{
    return data_mutate_collection('trip_points', function (&$payload) use ($attributes) {
        $payload['last_id']++;
        $newRecord = [
            'id' => (int) $payload['last_id'],
            'trip_token' => (string) ($attributes['trip_token'] ?? ''),
            'driver_id' => (int) ($attributes['driver_id'] ?? 0),
            'point_type' => (string) ($attributes['point_type'] ?? 'checkpoint'),
            'latitude' => (float) ($attributes['latitude'] ?? 0),
            'longitude' => (float) ($attributes['longitude'] ?? 0),
            'meter_mark' => (float) ($attributes['meter_mark'] ?? 0),
            'captured_at' => (string) ($attributes['captured_at'] ?? data_now()),
        ];

        $payload['records'][] = $newRecord;

        return $newRecord;
    });
}
