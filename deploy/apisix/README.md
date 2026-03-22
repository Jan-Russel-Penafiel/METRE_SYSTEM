# APISIX Gateway Setup

This app can route browser API traffic through Apache APISIX instead of calling the PHP endpoints directly.

## 1. Enable the gateway in PHP

Update `includes/config.php`:

```php
define('APISIX_ENABLED', true);
define('APISIX_GATEWAY_BASE_URL', 'http://127.0.0.1:9080');
define('APISIX_ROUTE_PREFIX', '/metre-gateway');
```

Use an empty `APISIX_GATEWAY_BASE_URL` when APISIX is exposed on the same origin and only the route prefix changes.

## 2. Create APISIX routes

Replace the admin key and upstream host if your PHP app is not served from `127.0.0.1:80/metre`.

### GraphQL route for driver mutations and cached public tracking queries

```bash
curl http://127.0.0.1:9180/apisix/admin/routes/metre-graphql \
  -H "X-API-KEY: $admin_key" \
  -X PUT \
  -d '{
    "uri": "/metre-gateway/api/graphql.php",
    "methods": ["GET", "POST", "OPTIONS"],
    "plugins": {
      "proxy-rewrite": {
        "uri": "/metre/api/graphql.php"
      },
      "proxy-cache": {
        "cache_strategy": "memory",
        "cache_zone": "memory_cache",
        "cache_ttl": 5,
        "cache_method": ["GET"],
        "cache_http_status": [200]
      }
    },
    "upstream": {
      "type": "roundrobin",
      "nodes": {
        "127.0.0.1:80": 1
      }
    }
  }'
```

The UI now uses GraphQL for the live meter and public tracker. `GET` requests cache the public `trackingStatus` query, while `POST` is used for the authenticated `updateFare` and `endTrip` mutations.

### Legacy live tracking route with short memory cache

```bash
curl http://127.0.0.1:9180/apisix/admin/routes/metre-tracking \
  -H "X-API-KEY: $admin_key" \
  -X PUT \
  -d '{
    "uri": "/metre-gateway/api/tracking_status.php",
    "methods": ["GET", "OPTIONS"],
    "plugins": {
      "proxy-rewrite": {
        "uri": "/metre/api/tracking_status.php"
      },
      "proxy-cache": {
        "cache_strategy": "memory",
        "cache_zone": "memory_cache",
        "cache_ttl": 5,
        "cache_method": ["GET"],
        "cache_http_status": [200]
      }
    },
    "upstream": {
      "type": "roundrobin",
      "nodes": {
        "127.0.0.1:80": 1
      }
    }
  }'
```

### Driver fare sync route

```bash
curl http://127.0.0.1:9180/apisix/admin/routes/metre-update-fare \
  -H "X-API-KEY: $admin_key" \
  -X PUT \
  -d '{
    "uri": "/metre-gateway/api/update_fare.php",
    "methods": ["POST", "OPTIONS"],
    "plugins": {
      "proxy-rewrite": {
        "uri": "/metre/api/update_fare.php"
      }
    },
    "upstream": {
      "type": "roundrobin",
      "nodes": {
        "127.0.0.1:80": 1
      }
    }
  }'
```

### Driver trip finalization route

```bash
curl http://127.0.0.1:9180/apisix/admin/routes/metre-end-trip \
  -H "X-API-KEY: $admin_key" \
  -X PUT \
  -d '{
    "uri": "/metre-gateway/api/end_trip.php",
    "methods": ["POST", "OPTIONS"],
    "plugins": {
      "proxy-rewrite": {
        "uri": "/metre/api/end_trip.php"
      }
    },
    "upstream": {
      "type": "roundrobin",
      "nodes": {
        "127.0.0.1:80": 1
      }
    }
  }'
```

### Distance helper route

```bash
curl http://127.0.0.1:9180/apisix/admin/routes/metre-distance \
  -H "X-API-KEY: $admin_key" \
  -X PUT \
  -d '{
    "uri": "/metre-gateway/api/calculate_distance.php",
    "methods": ["POST", "OPTIONS"],
    "plugins": {
      "proxy-rewrite": {
        "uri": "/metre/api/calculate_distance.php"
      }
    },
    "upstream": {
      "type": "roundrobin",
      "nodes": {
        "127.0.0.1:80": 1
      }
    }
  }'
```

## 3. Optional CORS

If APISIX is served from a different origin than the web UI, add the `cors` plugin to each route and explicitly allow your front-end origin.
