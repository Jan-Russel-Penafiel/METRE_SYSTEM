(function () {
    var config = window.METRE_CONFIG;

    if (!config) {
        return;
    }

    var currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });

    var elements = {
        status: document.getElementById('meter-status'),
        lastSync: document.getElementById('meter-last-sync'),
        distance: document.getElementById('meter-distance'),
        fare: document.getElementById('meter-fare'),
        waiting: document.getElementById('meter-waiting'),
        speed: document.getElementById('meter-speed'),
        permission: document.getElementById('permission-state'),
        syncState: document.getElementById('sync-state'),
        vehicleType: document.getElementById('meter-vehicle-type'),
        startButton: document.getElementById('force-start-btn'),
        endButton: document.getElementById('end-trip-btn'),
        resetButton: document.getElementById('reset-trip-btn'),
        requestLocationButton: document.getElementById('request-location-btn'),
        startedAt: document.getElementById('trip-started-at'),
        latLng: document.getElementById('meter-latlng'),
        fareDistance: document.getElementById('fare-distance'),
        fareWaiting: document.getElementById('fare-waiting'),
        fareSurcharge: document.getElementById('fare-surcharge'),
        fareMinimum: document.getElementById('fare-minimum'),
        tripTag: document.getElementById('meter-trip-tag'),
        routeCount: document.getElementById('route-count'),
        mapStatus: document.getElementById('map-status'),
        recentPoints: document.getElementById('recent-points'),
        toast: document.getElementById('meter-toast'),
        passengerLinkInput: document.getElementById('passenger-link-input'),
        copyLinkButton: document.getElementById('copy-link-btn'),
        trackingLinkStatus: document.getElementById('tracking-link-status'),
        mapContainer: document.getElementById('meter-live-map')
    };

    var watchId = null;
    var map = null;
    var mapLoaded = false;
    var driverMarker = null;
    var locationNames = window.MetreLocationNames || null;

    var defaultBreakdown = {
        distance_fare: 0,
        waiting_fare: 0,
        night_surcharge: 0,
        minimum_adjustment: 0,
        final_fare: 0
    };

    var state = loadState();

    bindEvents();
    initMap();
    render();

    if (config.hasFareSettings) {
        requestLocationAccess();
    }

    window.setInterval(function () {
        if (document.hidden) {
            return;
        }

        syncFare();
    }, 8000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            syncFare();
            render();
        }
    });
    window.setInterval(function () {
        if (state.tripToken && state.status === 'in_trip' && state.idleSince) {
            var idleSeconds = Math.floor((Date.now() - state.idleSince) / 1000);
            if (idleSeconds >= config.idleTimeoutSeconds && !state.endPrompted) {
                promptTripEnd();
            }
        }
        render();
    }, 1000);

    function createFreshState() {
        return {
            tripToken: null,
            publicTrackingToken: null,
            publicTrackingUrl: '',
            startedAt: null,
            status: 'waiting',
            meters: 0,
            waitingSeconds: 0,
            currentFare: 0,
            currentSpeedKph: 0,
            lastPosition: null,
            routePoints: [],
            breakdown: Object.assign({}, defaultBreakdown),
            lastSyncAt: null,
            syncMessage: 'Not synced yet',
            syncError: false,
            syncing: false,
            idleSince: null,
            endPrompted: false,
            ending: false,
            mapFocused: false
        };
    }

    function loadState() {
        try {
            var raw = localStorage.getItem(config.storageKey);
            if (!raw) {
                return createFreshState();
            }

            var saved = JSON.parse(raw);
            var merged = createFreshState();
            Object.assign(merged, saved);
            merged.routePoints = sanitizeRoutePoints(saved.routePoints || []);
            merged.lastPosition = sanitizePoint(saved.lastPosition);
            merged.breakdown = Object.assign({}, defaultBreakdown, saved.breakdown || {});
            merged.meters = safeNumber(saved.meters);
            merged.waitingSeconds = Math.max(0, Math.round(safeNumber(saved.waitingSeconds)));
            merged.currentFare = safeNumber(saved.currentFare);
            merged.currentSpeedKph = safeNumber(saved.currentSpeedKph);
            return merged;
        } catch (error) {
            return createFreshState();
        }
    }

    function saveState() {
        localStorage.setItem(config.storageKey, JSON.stringify(state));
    }

    function bindEvents() {
        if (elements.requestLocationButton) {
            elements.requestLocationButton.addEventListener('click', requestLocationAccess);
        }

        if (elements.startButton) {
            elements.startButton.addEventListener('click', function () {
                if (!config.hasFareSettings) {
                    showToast('Configure fare settings before starting a trip.', 'amber');
                    return;
                }

                if (!state.lastPosition) {
                    showToast('Waiting for the first GPS location.', 'rose');
                    requestLocationAccess();
                    return;
                }

                activateTrip('manual start', state.lastPosition);
            });
        }

        if (elements.endButton) {
            elements.endButton.addEventListener('click', function () {
                promptTripEnd(true);
            });
        }

        if (elements.resetButton) {
            elements.resetButton.addEventListener('click', function () {
                if (!window.confirm('Reset the current meter state on this device?')) {
                    return;
                }

                state = createFreshState();
                saveState();
                render();
                updateMap();
                showToast('Meter state reset.', 'sky');
            });
        }

        if (elements.vehicleType) {
            elements.vehicleType.addEventListener('change', function () {
                updateClientFare();
                saveState();
                render();
            });
        }

        if (elements.copyLinkButton) {
            elements.copyLinkButton.addEventListener('click', function () {
                if (!state.publicTrackingToken) {
                    showToast('4-digit tracking code will appear after trip start.', 'amber');
                    return;
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(state.publicTrackingToken).then(function () {
                        showToast('Passenger tracking code copied.', 'emerald');
                    }).catch(function () {
                        showToast('Unable to copy code. Copy it manually.', 'rose');
                    });
                    return;
                }

                elements.passengerLinkInput.select();
                document.execCommand('copy');
                showToast('Passenger tracking code copied.', 'emerald');
            });
        }
    }

    function initMap() {
        if (!elements.mapContainer || !window.maplibregl) {
            if (elements.mapStatus) {
                elements.mapStatus.textContent = 'MapLibre could not be loaded in this browser.';
            }
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
            ensureRouteSource();
            updateMap();
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

    function requestLocationAccess() {
        if (!navigator.geolocation) {
            setPermissionMessage('Geolocation is not available in this browser.', true);
            return;
        }

        setPermissionMessage('Requesting live location access...', false);

        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
        }

        watchId = navigator.geolocation.watchPosition(
            handlePosition,
            function (error) {
                var message = 'Unable to read GPS location.';

                if (error && error.code === error.PERMISSION_DENIED) {
                    message = 'Location access was denied. Allow GPS permission in the browser.';
                }
                if (error && error.code === error.POSITION_UNAVAILABLE) {
                    message = 'GPS position is unavailable. Move to an open area and try again.';
                }
                if (error && error.code === error.TIMEOUT) {
                    message = 'GPS request timed out. Retry when signal is stronger.';
                }

                setPermissionMessage(message, true);
            },
            {
                enableHighAccuracy: true,
                maximumAge: 2000,
                timeout: 10000
            }
        );
    }

    function handlePosition(position) {
        var point = sanitizePoint({
            lat: round(position.coords.latitude, 7),
            lng: round(position.coords.longitude, 7),
            accuracy: round(position.coords.accuracy || 0, 1),
            speedKph: position.coords.speed !== null && !isNaN(position.coords.speed)
                ? round(Math.max(position.coords.speed * 3.6, 0), 2)
                : 0,
            timestamp: new Date(position.timestamp || Date.now()).toISOString()
        });

        if (!point) {
            return;
        }

        setPermissionMessage('GPS connected. Accuracy ' + point.accuracy + ' m.', false);
        state.currentSpeedKph = point.speedKph;

        if (!state.lastPosition) {
            state.lastPosition = point;
            appendRoutePoint(point, true);
            updateClientFare();
            saveState();
            render();
            updateMap();
            return;
        }

        var segmentMeters = haversineMeters(state.lastPosition, point);
        var elapsedSeconds = Math.max(0, Math.round((Date.parse(point.timestamp) - Date.parse(state.lastPosition.timestamp)) / 1000));


        if (state.tripToken && state.status === 'in_trip') {
            if (segmentMeters >= 1) {
                state.meters += segmentMeters;
                appendRoutePoint(point);
            }

            if (elapsedSeconds > 0 && (point.speedKph < 1.5 || segmentMeters < 2)) {
                state.waitingSeconds += elapsedSeconds;
            }

            if (point.speedKph < 0.8 && segmentMeters < 1.5) {
                if (!state.idleSince) {
                    state.idleSince = Date.now();
                }
            } else {
                state.idleSince = null;
                state.endPrompted = false;
            }
        } else {
            appendRoutePoint(point);
        }

        state.lastPosition = point;
        updateClientFare();
        saveState();
        render();
        updateMap();
    }

    function activateTrip(reason, point) {
        if (state.tripToken) {
            return;
        }

        state.tripToken = 'trip_' + config.driverId + '_' + Date.now();
        state.startedAt = new Date().toISOString();
        state.status = 'in_trip';
        state.meters = 0;
        state.waitingSeconds = 0;
        state.idleSince = null;
        state.endPrompted = false;
        state.breakdown = Object.assign({}, defaultBreakdown);
        state.currentFare = 0;
        state.routePoints = [];
        state.publicTrackingToken = null;
        state.publicTrackingUrl = '';
        state.mapFocused = false;

        if (point) {
            appendRoutePoint(point, true);
            state.lastPosition = point;
        }

        updateClientFare();
        saveState();
        render();
        updateMap();
        syncFare();
        showToast('Trip started: ' + reason + '.', 'emerald');
    }

    function appendRoutePoint(point, force) {
        point = sanitizePoint(point);
        if (!point) {
            return;
        }

        var previous = state.routePoints.length ? state.routePoints[state.routePoints.length - 1] : null;
        if (!force && previous) {
            var distanceGap = haversineMeters(previous, point);
            var timeGap = Math.abs(Date.parse(point.timestamp) - Date.parse(previous.timestamp)) / 1000;
            if (distanceGap < 10 && timeGap < 15) {
                return;
            }
        }

        state.routePoints.push(point);
        state.routePoints = sanitizeRoutePoints(state.routePoints).slice(-300);
    }

    function selectedSetting() {
        var selectedType = elements.vehicleType ? elements.vehicleType.value : config.defaultVehicleType;
        var matching = (config.fareSettings || []).find(function (setting) {
            return setting.vehicle_type === selectedType;
        });
        return matching || (config.fareSettings || [])[0] || null;
    }

    function updateClientFare() {
        var setting = selectedSetting();
        if (!setting) {
            state.breakdown = Object.assign({}, defaultBreakdown);
            state.currentFare = 0;
            return;
        }

        var distanceFare = state.meters * Number(setting.rate_per_meter || 0);
        var waitingFare = (state.waitingSeconds / 60) * Number(setting.waiting_rate_per_minute || 0);
        var subtotal = distanceFare + waitingFare;
        var startedAt = state.startedAt ? new Date(state.startedAt) : new Date();
        var hour = startedAt.getHours();
        var isNight = hour >= 22 || hour < 5;
        var surcharge = isNight ? subtotal * (Number(setting.night_surcharge_percent || 0) / 100) : 0;
        var beforeMinimum = subtotal + surcharge;
        var minimumAdjustment = Math.max(0, Number(setting.minimum_fare || 0) - beforeMinimum);
        var finalFare = beforeMinimum + minimumAdjustment;

        state.breakdown = {
            distance_fare: round(distanceFare, 2),
            waiting_fare: round(waitingFare, 2),
            night_surcharge: round(surcharge, 2),
            minimum_adjustment: round(minimumAdjustment, 2),
            final_fare: round(finalFare, 2)
        };
        state.currentFare = state.breakdown.final_fare;
    }

    function promptTripEnd(manual) {
        if (!state.tripToken || state.ending) {
            return;
        }

        state.endPrompted = true;
        var confirmed = window.confirm(manual ? 'End this trip now?' : 'Trip seems idle. End and finalize this trip?');

        if (!confirmed) {
            state.idleSince = Date.now();
            state.endPrompted = false;
            saveState();
            render();
            return;
        }

        finalizeTrip();
    }

    function finalizeTrip() {
        if (!state.tripToken || state.ending) {
            return;
        }

        state.ending = true;
        state.syncMessage = 'Finalizing trip...';
        render();

        var routePayload = sanitizeRoutePoints(state.routePoints);
        var payload = {
            trip_token: state.tripToken,
            public_tracking_token: state.publicTrackingToken,
            vehicle_type: elements.vehicleType ? elements.vehicleType.value : config.defaultVehicleType,
            meters: round(state.meters, 2),
            waiting_seconds: Math.max(0, Math.round(state.waitingSeconds)),
            started_at: state.startedAt,
            ended_at: new Date().toISOString(),
            route_points: routePayload,
            start_lat: routePayload[0] ? routePayload[0].lat : null,
            start_lng: routePayload[0] ? routePayload[0].lng : null,
            end_lat: state.lastPosition ? state.lastPosition.lat : null,
            end_lng: state.lastPosition ? state.lastPosition.lng : null
        };

        fetch(config.endTripUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
            .then(handleJsonResponse)
            .then(function (data) {
                localStorage.removeItem(config.storageKey);
                window.location.href = data.receipt_url;
            })
            .catch(function (error) {
                state.ending = false;
                state.syncMessage = error.message || 'Trip finalization failed.';
                state.syncError = true;
                render();
                showToast(state.syncMessage, 'rose');
            });
    }

    function syncFare() {
        if (!state.tripToken || state.status !== 'in_trip' || state.syncing || state.ending) {
            return;
        }

        state.syncing = true;
        state.syncMessage = 'Syncing fare...';
        state.syncError = false;
        render();

        var payload = {
            trip_token: state.tripToken,
            public_tracking_token: state.publicTrackingToken,
            vehicle_type: elements.vehicleType ? elements.vehicleType.value : config.defaultVehicleType,
            meters: round(state.meters, 2),
            waiting_seconds: Math.max(0, Math.round(state.waitingSeconds)),
            started_at: state.startedAt,
            status: 'in_trip',
            latitude: state.lastPosition ? state.lastPosition.lat : null,
            longitude: state.lastPosition ? state.lastPosition.lng : null,
            route_points: sanitizeRoutePoints(state.routePoints).slice(-120)
        };

        fetch(config.updateFareUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
            .then(handleJsonResponse)
            .then(function (data) {
                state.breakdown = Object.assign({}, state.breakdown, data.breakdown || {});
                state.currentFare = Number(data.final_fare || state.currentFare);
                state.lastSyncAt = data.server_time || new Date().toISOString();
                state.syncMessage = 'Fare synced';
                state.syncError = false;
                state.syncing = false;
                state.publicTrackingToken = data.public_tracking_token || state.publicTrackingToken;
                state.publicTrackingUrl = data.tracking_url || state.publicTrackingUrl;
                saveState();
                render();
            })
            .catch(function (error) {
                state.syncMessage = error.message || 'Sync failed.';
                state.syncError = true;
                state.syncing = false;
                render();
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

    function render() {
        var statusLabel = state.lastPosition ? 'Waiting to start...' : 'Waiting for GPS...';
        var tripTag = 'Standby';
        var trackingLabel = 'Inactive';

        if (state.tripToken && state.status === 'in_trip') {
            statusLabel = 'In trip';
            tripTag = 'Active';
            trackingLabel = state.publicTrackingToken ? 'Code Ready' : 'Preparing';
        }
        if (state.ending) {
            statusLabel = 'Ending trip...';
            tripTag = 'Finalizing';
        }

        elements.status.textContent = statusLabel;
        elements.tripTag.textContent = tripTag;
        elements.distance.textContent = formatDistance(state.meters);
        elements.fare.textContent = formatCurrency(state.currentFare);
        elements.waiting.textContent = formatDuration(state.waitingSeconds);
        elements.speed.textContent = Number(state.currentSpeedKph || 0).toFixed(1);
        elements.lastSync.textContent = state.lastSyncAt ? formatDateTime(state.lastSyncAt) : 'Not synced yet';
        elements.startedAt.textContent = state.startedAt ? formatDateTime(state.startedAt) : 'Not started';
        renderLocationText(elements.latLng, state.lastPosition, 'No location yet', 'Resolving current location...', 'Current location unavailable.');
        elements.fareDistance.textContent = formatCurrency(state.breakdown.distance_fare || 0);
        elements.fareWaiting.textContent = formatCurrency(state.breakdown.waiting_fare || 0);
        elements.fareSurcharge.textContent = formatCurrency(state.breakdown.night_surcharge || 0);
        elements.fareMinimum.textContent = formatCurrency(state.breakdown.minimum_adjustment || 0);
        elements.routeCount.textContent = String(state.routePoints.length);
        elements.syncState.textContent = state.syncMessage;
        elements.syncState.className = 'inline-flex rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] ' + (state.syncError
            ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/70 dark:text-rose-300'
            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300');
        elements.trackingLinkStatus.textContent = trackingLabel;
        elements.trackingLinkStatus.className = 'rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] ' + (state.publicTrackingToken
            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300'
            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300');
        elements.passengerLinkInput.value = state.publicTrackingToken || '4-digit tracking code becomes available after trip start.';
        elements.endButton.disabled = !state.tripToken || state.ending;
        elements.startButton.disabled = !config.hasFareSettings || !!state.tripToken || !state.lastPosition;
        renderPoints();
        updateMap();
    }

    function renderPoints() {
        var routePoints = sanitizeRoutePoints(state.routePoints);
        if (!routePoints.length) {
            elements.recentPoints.innerHTML = '<li>No locations recorded yet.</li>';
            elements.mapStatus.textContent = 'Map centers on the latest GPS point and draws the current route line.';
            return;
        }

        var points = routePoints.slice(-5).reverse();
        elements.recentPoints.innerHTML = points.map(function (point) {
            return '<li class="rounded-2xl bg-white/70 px-3 py-2 dark:bg-slate-900/70">'
                + '<div class="font-semibold" data-location-lat="' + point.lat + '" data-location-lng="' + point.lng + '">Resolving location...</div>'
                + '<div class="mt-1 text-xs text-slate-500 dark:text-slate-400">' + formatDateTime(point.timestamp) + ' - ' + Number(point.speedKph || 0).toFixed(1) + ' km/h</div>'
                + '</li>';
        }).join('');
        hydrateLocationNodes(elements.recentPoints, 'Resolving location...', 'Location unavailable.');

        elements.mapStatus.textContent = state.tripToken
            ? 'The route line and passenger code update while the trip is active.'
            : 'GPS preview is active. The route line will reset when the next trip starts.';
    }

    function renderLocationText(element, point, emptyText, loadingText, fallbackText) {
        if (!element) {
            return;
        }

        point = sanitizePoint(point);
        if (!point) {
            element.textContent = emptyText;
            return;
        }

        if (!locationNames) {
            element.textContent = fallbackText;
            return;
        }

        locationNames.applyToElement(element, point.lat, point.lng, {
            emptyText: emptyText,
            loadingText: loadingText,
            fallbackText: fallbackText
        });
    }

    function hydrateLocationNodes(root, loadingText, fallbackText) {
        if (!root) {
            return;
        }

        var nodes = root.querySelectorAll('[data-location-lat][data-location-lng]');
        Array.prototype.forEach.call(nodes, function (node) {
            if (!locationNames) {
                node.textContent = fallbackText;
                return;
            }

            locationNames.applyToElement(node, node.getAttribute('data-location-lat'), node.getAttribute('data-location-lng'), {
                loadingText: loadingText,
                fallbackText: fallbackText
            });
        });
    }

    function ensureRouteSource() {
        if (!mapLoaded || !map || map.getSource('trip-route')) {
            return;
        }

        map.addSource('trip-route', {
            type: 'geojson',
            data: emptyRouteData()
        });

        map.addLayer({
            id: 'trip-route-line',
            type: 'line',
            source: 'trip-route',
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

    function updateMap() {
        if (!mapLoaded || !map) {
            return;
        }

        var routePoints = sanitizeRoutePoints(state.routePoints);
        var source = map.getSource('trip-route');
        if (source) {
            source.setData(routeData(routePoints));
        }

        if (state.lastPosition) {
            if (!driverMarker) {
                driverMarker = new window.maplibregl.Marker({ color: '#0f172a' })
                    .setLngLat([state.lastPosition.lng, state.lastPosition.lat])
                    .addTo(map);
            } else {
                driverMarker.setLngLat([state.lastPosition.lng, state.lastPosition.lat]);
            }

            if (!state.mapFocused) {
                focusMap(routePoints);
                state.mapFocused = true;
            } else if (state.tripToken && routePoints.length <= 2) {
                map.easeTo({
                    center: [state.lastPosition.lng, state.lastPosition.lat],
                    zoom: 16,
                    duration: 700
                });
            }
        }
    }

    function focusMap(routePoints) {
        if (!map || !routePoints.length) {
            return;
        }

        if (routePoints.length === 1) {
            map.easeTo({
                center: [routePoints[0].lng, routePoints[0].lat],
                zoom: 16,
                duration: 700
            });
            return;
        }

        var bounds = new window.maplibregl.LngLatBounds();
        routePoints.forEach(function (point) {
            bounds.extend([point.lng, point.lat]);
        });
        map.fitBounds(bounds, {
            padding: 40,
            maxZoom: 17,
            duration: 700
        });
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

    function sanitizeRoutePoints(points) {
        if (!Array.isArray(points)) {
            return [];
        }

        return points.map(sanitizePoint).filter(function (point) {
            return !!point;
        });
    }

    function sanitizePoint(point) {
        if (!point) {
            return null;
        }

        var lat = safeNumber(point.lat);
        var lng = safeNumber(point.lng);
        if (!isFinite(lat) || !isFinite(lng)) {
            return null;
        }

        return {
            lat: round(lat, 7),
            lng: round(lng, 7),
            accuracy: safeNumber(point.accuracy),
            speedKph: safeNumber(point.speedKph),
            timestamp: point.timestamp || new Date().toISOString()
        };
    }

    function safeNumber(value) {
        var parsed = Number(value);
        return isFinite(parsed) ? parsed : 0;
    }

    function setPermissionMessage(message, isError) {
        elements.permission.textContent = message;
        elements.permission.className = 'rounded-2xl border border-dashed px-4 py-3 text-sm ' + (isError
            ? 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/50 dark:text-rose-200'
            : 'border-slate-300 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300');
    }

    function showToast(message, tone) {
        var classes = {
            sky: 'bg-sky-600',
            emerald: 'bg-emerald-600',
            rose: 'bg-rose-600',
            amber: 'bg-amber-600'
        };
        elements.toast.textContent = message;
        elements.toast.className = 'pointer-events-none fixed bottom-4 right-4 max-w-sm rounded-2xl px-4 py-3 text-sm font-semibold text-white shadow-lg ' + (classes[tone] || classes.sky);
        elements.toast.classList.remove('hidden');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(function () {
            elements.toast.classList.add('hidden');
        }, 2600);
    }

    function haversineMeters(a, b) {
        var earthRadius = 6371000;
        var dLat = toRadians((b.lat || 0) - (a.lat || 0));
        var dLng = toRadians((b.lng || 0) - (a.lng || 0));
        var lat1 = toRadians(a.lat || 0);
        var lat2 = toRadians(b.lat || 0);
        var sinLat = Math.sin(dLat / 2);
        var sinLng = Math.sin(dLng / 2);
        var calculation = sinLat * sinLat + Math.cos(lat1) * Math.cos(lat2) * sinLng * sinLng;
        return 2 * earthRadius * Math.atan2(Math.sqrt(calculation), Math.sqrt(1 - calculation));
    }

    function toRadians(value) {
        return value * (Math.PI / 180);
    }

    function round(value, precision) {
        var factor = Math.pow(10, precision || 0);
        return Math.round(Number(value || 0) * factor) / factor;
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
        var seconds = Math.max(0, Math.round(Number(value || 0)));
        var minutes = Math.floor(seconds / 60);
        var remainder = seconds % 60;
        var hours = Math.floor(minutes / 60);
        if (hours > 0) {
            return String(hours).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
        }
        return String(minutes).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
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
