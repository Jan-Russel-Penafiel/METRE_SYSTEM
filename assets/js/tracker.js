(function () {
    var config = window.TRACKER_CONFIG;
    var graphql = window.MetreGraphQL;

    if (!config || !graphql) {
        return;
    }

    var trackingStatusQuery = [
        'query TrackingStatus($token: String!) {',
        '  trackingStatus(token: $token) {',
        '    status',
        '    driver_name',
        '    vehicle_type',
        '    meters',
        '    current_fare',
        '    waiting_seconds',
        '    started_at',
        '    updated_at',
        '    ended_at',
        '    last_lat',
        '    last_lng',
        '    route_points {',
        '      lat',
        '      lng',
        '      speedKph',
        '      timestamp',
        '    }',
        '  }',
        '}'
    ].join('\n');

    var currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });

    var elements = {
        pageMessage: document.getElementById('tracking-page-message'),
        mapStatus: document.getElementById('tracking-map-status'),
        routeCount: document.getElementById('tracking-route-count'),
        statusBadge: document.getElementById('tracking-status-badge'),
        statusCopy: document.getElementById('tracking-status-copy'),
        syncSummary: document.getElementById('tracking-sync-summary'),
        statusDot: document.getElementById('tracking-status-dot'),
        driverName: document.getElementById('tracking-driver-name'),
        vehicleType: document.getElementById('tracking-vehicle-type'),
        distance: document.getElementById('tracking-distance'),
        fare: document.getElementById('tracking-fare'),
        waitingTime: document.getElementById('tracking-waiting-time'),
        startedAt: document.getElementById('tracking-started-at'),
        updatedAt: document.getElementById('tracking-updated-at'),
        endedAt: document.getElementById('tracking-ended-at'),
        coordinates: document.getElementById('tracking-coordinates'),
        lastLocation: document.getElementById('tracking-last-location'),
        mapContainer: document.getElementById('tracking-map')
    };

    var map = null;
    var mapLoaded = false;
    var marker = null;
    var locationNames = window.MetreLocationNames || null;
    var loadingTracking = false;
    var latestMapState = null;
    var lastViewportSignature = '';

    initMap();

    if (!config.token) {
        setText(elements.pageMessage, '');
        setText(elements.syncSummary, 'Waiting for trip data.');
        setText(elements.statusCopy, 'No trip selected yet.');
        updateStatusBadge('idle');
        return;
    }

    setText(elements.syncSummary, 'Connecting to the trip feed...');
    setText(elements.statusCopy, 'Checking for the latest public trip snapshot.');
    updateStatusBadge('waiting');

    loadTracking();
    window.setInterval(function () {
        if (document.hidden) {
            return;
        }

        loadTracking();
    }, 8000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            loadTracking();
        }
    });

    function initMap() {
        if (!elements.mapContainer) {
            return;
        }

        if (!window.maplibregl) {
            setText(elements.mapStatus, 'MapLibre could not be loaded in this browser.');
            return;
        }

        map = new window.maplibregl.Map({
            container: elements.mapContainer,
            style: config.mapStyle || config.mapStyleUrl,
            center: [121.0, 14.5995],
            zoom: 12,
            attributionControl: true
        });

        installStyleFallbacks();
        map.addControl(new window.maplibregl.NavigationControl(), 'top-right');
        map.on('load', function () {
            mapLoaded = true;

            if (!map.getSource('tracking-route')) {
                map.addSource('tracking-route', {
                    type: 'geojson',
                    data: emptyRouteData()
                });
                map.addLayer({
                    id: 'tracking-route-line',
                    type: 'line',
                    source: 'tracking-route',
                    layout: {
                        'line-cap': 'round',
                        'line-join': 'round'
                    },
                    paint: {
                        'line-color': '#0ea5e9',
                        'line-width': 5,
                        'line-opacity': 0.9
                    }
                });
            }

            if (latestMapState) {
                updateMap(latestMapState.routePoints, latestMapState.lng, latestMapState.lat, true);
            }
        });
    }

    function installStyleFallbacks() {
        if (!map) {
            return;
        }

        map.on('styleimagemissing', function (event) {
            if (map.hasImage(event.id)) {
                return;
            }

            map.addImage(event.id, {
                width: 1,
                height: 1,
                data: new Uint8Array([0, 0, 0, 0])
            });
        });
    }

    function loadTracking() {
        if (loadingTracking) {
            return;
        }

        loadingTracking = true;

        graphql.request(config.graphqlUrl, {
            method: 'GET',
            operationName: 'TrackingStatus',
            query: trackingStatusQuery,
            variables: {
                token: config.token
            }
        })
            .then(function (data) {
                renderTracking(data.trackingStatus || {});
            })
            .catch(function (error) {
                renderError(error);
            })
            .finally(function () {
                loadingTracking = false;
            });
    }

    function renderTracking(data) {
        var routePoints = sanitizeRoutePoints(data.route_points || []);
        var lastLat = nullableNumber(data.last_lat);
        var lastLng = nullableNumber(data.last_lng);
        var waitingSeconds = Math.max(0, Number(data.waiting_seconds || 0));

        latestMapState = {
            routePoints: routePoints,
            lat: lastLat,
            lng: lastLng
        };

        setText(elements.pageMessage, getPageMessage(data.status, routePoints.length));
        setText(elements.mapStatus, getMapStatusCopy(data.status, routePoints.length, lastLat !== null && lastLng !== null));
        setText(elements.routeCount, String(routePoints.length));
        setText(elements.driverName, data.driver_name || 'Unknown driver');
        setText(elements.vehicleType, data.vehicle_type || 'Unknown vehicle');
        setText(elements.distance, formatDistance(data.meters || 0));
        setText(elements.fare, formatCurrency(data.current_fare || 0));
        setText(elements.waitingTime, formatDuration(waitingSeconds));
        setText(elements.startedAt, data.started_at ? formatDateTime(data.started_at) : 'Not started');
        setText(elements.updatedAt, data.updated_at ? formatDateTime(data.updated_at) : 'Waiting...');
        setText(
            elements.endedAt,
            data.ended_at
                ? formatDateTime(data.ended_at)
                : (data.status === 'completed' ? 'Completed time unavailable' : 'Trip still active')
        );
        setText(elements.coordinates, formatCoordinates(lastLat, lastLng));
        setText(elements.syncSummary, formatSyncSummary(data.updated_at, data.status));
        setText(elements.statusCopy, getStatusCopy(data.status, routePoints.length));

        renderLastLocation(lastLat, lastLng);
        updateStatusBadge(data.status);
        updateMap(routePoints, lastLng, lastLat, false);
    }

    function renderError(error) {
        setText(elements.pageMessage, error.message || 'Unable to load tracking data.');
        setText(elements.mapStatus, 'Tracking data is unavailable right now.');
        setText(elements.syncSummary, 'Connection failed');
        setText(elements.statusCopy, 'We could not load a public trip feed for this code.');
        updateStatusBadge('unavailable');
    }

    function updateStatusBadge(status) {
        var meta = getStatusMeta(status);

        if (elements.statusBadge) {
            elements.statusBadge.textContent = meta.label;
            elements.statusBadge.className = 'tracker-status-badge ' + meta.badgeClass;
        }

        if (elements.statusDot) {
            elements.statusDot.className = 'tracker-dot ' + meta.dotClass;
        }
    }

    function getStatusMeta(status) {
        if (status === 'in_trip') {
            return {
                label: 'In trip',
                badgeClass: 'is-live',
                dotClass: 'is-live'
            };
        }

        if (status === 'completed') {
            return {
                label: 'Completed',
                badgeClass: 'is-completed',
                dotClass: 'is-completed'
            };
        }

        if (status === 'waiting') {
            return {
                label: 'Waiting',
                badgeClass: 'is-waiting',
                dotClass: 'is-waiting'
            };
        }

        if (status === 'unavailable') {
            return {
                label: 'Unavailable',
                badgeClass: 'is-error',
                dotClass: 'is-error'
            };
        }

        return {
            label: 'Idle',
            badgeClass: 'is-idle',
            dotClass: 'is-idle'
        };
    }

    function updateMap(routePoints, lng, lat, forceViewport) {
        if (!mapLoaded || !map) {
            return;
        }

        var source = map.getSource('tracking-route');
        if (source) {
            source.setData(routeData(routePoints));
        }

        if (lat !== null && lng !== null) {
            if (!marker) {
                marker = new window.maplibregl.Marker({ color: '#0f172a' })
                    .setLngLat([lng, lat])
                    .addTo(map);
            } else {
                marker.setLngLat([lng, lat]);
            }
        } else if (marker) {
            marker.remove();
            marker = null;
        }

        var viewportSignature = buildViewportSignature(routePoints, lng, lat);
        if (!forceViewport && viewportSignature === lastViewportSignature) {
            return;
        }

        lastViewportSignature = viewportSignature;

        if (routePoints.length > 1) {
            var bounds = new window.maplibregl.LngLatBounds();
            routePoints.forEach(function (point) {
                bounds.extend([point.lng, point.lat]);
            });

            map.fitBounds(bounds, {
                padding: 40,
                maxZoom: 17,
                duration: forceViewport ? 0 : 700
            });
            return;
        }

        if (lat !== null && lng !== null) {
            map.easeTo({
                center: [lng, lat],
                zoom: 16,
                duration: forceViewport ? 0 : 700
            });
        }
    }

    function routeData(routePoints) {
        if (!routePoints.length) {
            return emptyRouteData();
        }

        return {
            type: 'Feature',
            properties: {},
            geometry: {
                type: 'LineString',
                coordinates: routePoints.map(function (point) {
                    return [point.lng, point.lat];
                })
            }
        };
    }

    function emptyRouteData() {
        return {
            type: 'Feature',
            properties: {},
            geometry: {
                type: 'LineString',
                coordinates: []
            }
        };
    }

    function sanitizeRoutePoints(routePoints) {
        if (!Array.isArray(routePoints)) {
            return [];
        }

        return routePoints.map(function (point) {
            if (!point) {
                return null;
            }

            var lat = nullableNumber(point.lat);
            var lng = nullableNumber(point.lng);
            if (lat === null || lng === null) {
                return null;
            }

            return {
                lat: lat,
                lng: lng,
                speedKph: Number(point.speedKph || 0),
                timestamp: point.timestamp || ''
            };
        }).filter(function (point) {
            return !!point;
        });
    }

    function nullableNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        var parsed = Number(value);
        return isFinite(parsed) ? parsed : null;
    }

    function renderLastLocation(lat, lng) {
        if (lat === null || lng === null) {
            setText(elements.lastLocation, 'No live location yet.');
            return;
        }

        if (!locationNames) {
            setText(elements.lastLocation, 'Location unavailable.');
            return;
        }

        locationNames.applyToElement(elements.lastLocation, lat, lng, {
            emptyText: 'No live location yet.',
            loadingText: 'Resolving current location...',
            fallbackText: 'Location unavailable.'
        });
    }

    function getPageMessage(status, routePointCount) {
        if (status === 'completed') {
            return 'This trip has ended. The final public snapshot is shown below.';
        }

        if (status === 'in_trip') {
            return routePointCount
                ? 'Live trip data refreshed successfully.'
                : 'Trip is live. Waiting for published route points.';
        }

        if (status === 'waiting') {
            return 'Trip found. Waiting for the driver to begin moving.';
        }

        return 'Trip snapshot loaded.';
    }

    function getMapStatusCopy(status, routePointCount, hasPosition) {
        if (status === 'completed' && routePointCount) {
            return 'Showing the final published route from the completed trip.';
        }

        if (routePointCount) {
            return 'Showing the latest published route line and driver position.';
        }

        if (hasPosition) {
            return 'Showing the latest published driver position. Waiting for route points.';
        }

        if (status === 'waiting') {
            return 'Trip is ready. Live route points will appear after movement starts.';
        }

        return 'Waiting for live trip data.';
    }

    function getStatusCopy(status, routePointCount) {
        if (status === 'in_trip') {
            return routePointCount
                ? 'Driver is on the road and route points are streaming to this page.'
                : 'Driver is on the road. Waiting for the first published route points.';
        }

        if (status === 'completed') {
            return 'Trip has ended. The screen now shows the final public snapshot.';
        }

        if (status === 'waiting') {
            return 'Driver has opened the trip feed. Movement will appear once the ride starts.';
        }

        if (status === 'unavailable') {
            return 'This code could not be matched to an available public trip feed.';
        }

        return 'Enter a valid code to load trip data.';
    }

    function formatCurrency(value) {
        return currencyFormatter.format(Number(value || 0));
    }

    function formatDistance(value) {
        var meters = Number(value || 0);

        if (meters >= 1000) {
            return (meters / 1000).toFixed(2) + ' km';
        }

        return Math.round(meters) + ' m';
    }

    function formatDuration(value) {
        var totalSeconds = Math.max(0, Math.round(Number(value || 0)));
        var hours = Math.floor(totalSeconds / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;

        if (hours > 0) {
            return pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        }

        return pad(minutes) + ':' + pad(seconds);
    }

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function formatCoordinates(lat, lng) {
        if (lat === null || lng === null) {
            return 'Waiting for live coordinates';
        }

        return lat.toFixed(5) + ', ' + lng.toFixed(5);
    }

    function formatSyncSummary(value, status) {
        var date = parseDate(value);

        if (!date) {
            return status === 'completed'
                ? 'Final snapshot time unavailable'
                : 'Waiting for the first live update.';
        }

        if (status === 'completed') {
            return 'Final snapshot ' + formatRelativeTime(date);
        }

        return 'Updated ' + formatRelativeTime(date);
    }

    function formatRelativeTime(date) {
        var diffSeconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));

        if (diffSeconds < 45) {
            return 'just now';
        }

        if (diffSeconds < 3600) {
            var minutes = Math.max(1, Math.round(diffSeconds / 60));
            return minutes + ' min ago';
        }

        if (diffSeconds < 86400) {
            var hours = Math.max(1, Math.round(diffSeconds / 3600));
            return hours + ' hr ago';
        }

        return 'on ' + formatDateTime(date);
    }

    function formatDateTime(value) {
        var date = value instanceof Date ? value : parseDate(value);

        if (!date) {
            return 'Unknown';
        }

        return date.toLocaleString('en-PH', {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }

        if (value instanceof Date) {
            return isNaN(value.getTime()) ? null : value;
        }

        var rawValue = String(value).trim();
        if (!rawValue) {
            return null;
        }

        var parsed = new Date(rawValue.indexOf('T') === -1 ? rawValue.replace(' ', 'T') : rawValue);
        if (isNaN(parsed.getTime())) {
            parsed = new Date(rawValue);
        }

        return isNaN(parsed.getTime()) ? null : parsed;
    }

    function buildViewportSignature(routePoints, lng, lat) {
        if (routePoints.length > 1) {
            var firstPoint = routePoints[0];
            var lastPoint = routePoints[routePoints.length - 1];

            return [
                routePoints.length,
                firstPoint.lat.toFixed(5),
                firstPoint.lng.toFixed(5),
                lastPoint.lat.toFixed(5),
                lastPoint.lng.toFixed(5)
            ].join('|');
        }

        if (lat !== null && lng !== null) {
            return 'point|' + lat.toFixed(5) + '|' + lng.toFixed(5);
        }

        return 'empty';
    }

    function setText(element, value) {
        if (!element) {
            return;
        }

        element.textContent = value;
    }
})();
