(function () {
    var config = window.TRACKER_CONFIG;

    if (!config) {
        return;
    }

    var currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });

    var elements = {
        pageMessage: document.getElementById('tracking-page-message'),
        mapStatus: document.getElementById('tracking-map-status'),
        routeCount: document.getElementById('tracking-route-count'),
        statusBadge: document.getElementById('tracking-status-badge'),
        driverName: document.getElementById('tracking-driver-name'),
        vehicleType: document.getElementById('tracking-vehicle-type'),
        distance: document.getElementById('tracking-distance'),
        fare: document.getElementById('tracking-fare'),
        startedAt: document.getElementById('tracking-started-at'),
        updatedAt: document.getElementById('tracking-updated-at'),
        lastLocation: document.getElementById('tracking-last-location'),
        mapContainer: document.getElementById('tracking-map')
    };

    var map = null;
    var mapLoaded = false;
    var marker = null;
    var locationNames = window.MetreLocationNames || null;

    var loadingTracking = false;

    initMap();

    if (!config.token) {
        elements.pageMessage.textContent = 'Paste a 4-digit code or shared link to load the live map.';
        return;
    }

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
        if (!elements.mapContainer || !window.maplibregl) {
            elements.mapStatus.textContent = 'MapLibre could not be loaded in this browser.';
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
                        'line-color': '#0284c7',
                        'line-width': 5,
                        'line-opacity': 0.9
                    }
                });
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

        fetch(config.trackingStatusUrl + '?token=' + encodeURIComponent(config.token))
            .then(handleJsonResponse)
            .then(function (data) {
                renderTracking(data);
            })
            .catch(function (error) {
                elements.pageMessage.textContent = error.message || 'Unable to load tracking data.';
                elements.mapStatus.textContent = 'Tracking data is unavailable.';
                elements.statusBadge.textContent = 'Unavailable';
                elements.statusBadge.className = 'rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-rose-700 dark:bg-rose-950/70 dark:text-rose-300';
            })
            .finally(function () {
                loadingTracking = false;
            });
    }

    function renderTracking(data) {
        var routePoints = sanitizeRoutePoints(data.route_points || []);
        var lastLat = nullableNumber(data.last_lat);
        var lastLng = nullableNumber(data.last_lng);

        elements.pageMessage.textContent = data.status === 'completed'
            ? 'This trip has ended. Final snapshot shown below.'
            : 'Live trip data refreshed successfully.';
        elements.mapStatus.textContent = routePoints.length
            ? 'OpenFreeMap is showing the latest published route line.'
            : 'Waiting for route updates from the driver.';
        elements.routeCount.textContent = String(routePoints.length);
        elements.driverName.textContent = data.driver_name || 'Unknown driver';
        elements.vehicleType.textContent = data.vehicle_type || 'Unknown vehicle';
        elements.distance.textContent = formatDistance(data.meters || 0);
        elements.fare.textContent = formatCurrency(data.current_fare || 0);
        elements.startedAt.textContent = data.started_at ? formatDateTime(data.started_at) : 'Not started';
        elements.updatedAt.textContent = data.updated_at ? formatDateTime(data.updated_at) : 'Waiting...';
        renderLastLocation(lastLat, lastLng);
        updateStatusBadge(data.status);
        updateMap(routePoints, lastLng, lastLat);
    }

    function updateStatusBadge(status) {
        var statusText = status || 'idle';
        var className = 'rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] ';

        if (statusText === 'in_trip') {
            className += 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300';
        } else if (statusText === 'completed') {
            className += 'bg-sky-100 text-sky-700 dark:bg-sky-950/70 dark:text-sky-300';
        } else {
            className += 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
        }

        elements.statusBadge.textContent = statusText.replace('_', ' ');
        elements.statusBadge.className = className;
    }

    function updateMap(routePoints, lng, lat) {
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
        }

        if (routePoints.length > 1) {
            var bounds = new window.maplibregl.LngLatBounds();
            routePoints.forEach(function (point) {
                bounds.extend([point.lng, point.lat]);
            });
            map.fitBounds(bounds, {
                padding: 40,
                maxZoom: 17,
                duration: 700
            });
        } else if (lat !== null && lng !== null) {
            map.easeTo({
                center: [lng, lat],
                zoom: 16,
                duration: 700
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
            elements.lastLocation.textContent = 'No live location yet.';
            return;
        }

        if (!locationNames) {
            elements.lastLocation.textContent = 'Location unavailable.';
            return;
        }

        locationNames.applyToElement(elements.lastLocation, lat, lng, {
            emptyText: 'No live location yet.',
            loadingText: 'Resolving current location...',
            fallbackText: 'Location unavailable.'
        });
    }

    function handleJsonResponse(response) {
        return response.text().then(function (text) {
            var data = null;

            try {
                data = text ? JSON.parse(text) : null;
            } catch (error) {
                throw new Error('Server returned invalid JSON. Check PHP warnings or session redirects.');
            }

            if (!response.ok || !data || !data.success) {
                throw new Error((data && data.message) || 'Request failed.');
            }

            return data;
        });
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

    function formatDateTime(value) {
        var date = new Date(value);
        if (isNaN(date.getTime())) {
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
})();
