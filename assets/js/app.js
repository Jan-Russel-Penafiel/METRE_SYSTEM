(function () {
    var config = window.METRE_CONFIG;
    var graphql = window.MetreGraphQL;

    if (!config || !graphql) {
        return;
    }

    var updateFareMutation = [
        'mutation UpdateFare($input: UpdateFareInput!) {',
        '  updateFare(input: $input) {',
        '    final_fare',
        '    server_time',
        '    public_tracking_token',
        '    tracking_url',
        '    breakdown {',
        '      distance_fare',
        '      waiting_fare',
        '      night_surcharge',
        '      minimum_adjustment',
        '      final_fare',
        '    }',
        '  }',
        '}'
    ].join('\n');

    var endTripMutation = [
        'mutation EndTrip($input: EndTripInput!) {',
        '  endTrip(input: $input) {',
        '    receipt_url',
        '  }',
        '}'
    ].join('\n');

    var currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });

    var elements = {
        status: document.getElementById('meter-status'),
        statusDot: document.getElementById('meter-status-dot'),
        statusCopy: document.getElementById('meter-status-copy'),
        lastSync: document.getElementById('meter-last-sync'),
        accuracy: document.getElementById('meter-accuracy'),
        idleTimer: document.getElementById('meter-idle-timer'),
        idleTimerSecondary: document.getElementById('meter-idle-timer-secondary'),
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
        lastPointAt: document.getElementById('trip-last-point-at'),
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
        passengerUrlInput: document.getElementById('passenger-url-input'),
        copyLinkButton: document.getElementById('copy-link-btn'),
        copyTrackingUrlButton: document.getElementById('copy-tracking-url-btn'),
        trackingLinkStatus: document.getElementById('tracking-link-status'),
        mapContainer: document.getElementById('meter-live-map')
    };

    var watchId = null;
    var map = null;
    var mapLoaded = false;
    var driverMarker = null;
    var locationNames = window.MetreLocationNames || null;
    var lastViewportSignature = '';

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
            pendingRoutePoints: [],
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
            merged.pendingRoutePoints = sanitizeRoutePoints(saved.pendingRoutePoints || []);
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
                lastViewportSignature = '';
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

                copyText(state.publicTrackingToken, 'Passenger tracking code copied.');
            });
        }

        if (elements.copyTrackingUrlButton) {
            elements.copyTrackingUrlButton.addEventListener('click', function () {
                var trackingUrl = buildTrackingUrl();
                if (!trackingUrl) {
                    showToast('Passenger share link becomes available after the first sync.', 'amber');
                    return;
                }

                copyText(trackingUrl, 'Passenger share link copied.');
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
            setPermissionMessage('Location access is not available in this browser.', true);
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
        state.pendingRoutePoints = [];
        state.publicTrackingToken = null;
        state.publicTrackingUrl = '';
        state.mapFocused = false;
        lastViewportSignature = '';

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

        if (state.tripToken && state.status === 'in_trip') {
            state.pendingRoutePoints.push(point);
            state.pendingRoutePoints = sanitizeRoutePoints(state.pendingRoutePoints).slice(-160);
        }
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

        graphql.request(config.graphqlUrl, {
            method: 'POST',
            operationName: 'EndTrip',
            query: endTripMutation,
            variables: {
                input: payload
            }
        })
            .then(function (data) {
                var result = data.endTrip || {};
                if (!result.receipt_url) {
                    throw new Error('Trip finalization did not return a receipt URL.');
                }

                localStorage.removeItem(config.storageKey);
                window.location.href = result.receipt_url;
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

        var pendingRoutePayload = sanitizeRoutePoints(state.pendingRoutePoints);
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
            route_points: pendingRoutePayload
        };

        graphql.request(config.graphqlUrl, {
            method: 'POST',
            operationName: 'UpdateFare',
            query: updateFareMutation,
            variables: {
                input: payload
            }
        })
            .then(function (data) {
                var result = data.updateFare || {};
                state.breakdown = Object.assign({}, state.breakdown, result.breakdown || {});
                state.currentFare = Number(result.final_fare || state.currentFare);
                state.lastSyncAt = result.server_time || new Date().toISOString();
                state.syncMessage = 'Fare synced';
                state.syncError = false;
                state.syncing = false;
                state.publicTrackingToken = result.public_tracking_token || state.publicTrackingToken;
                state.publicTrackingUrl = result.tracking_url || state.publicTrackingUrl;
                state.pendingRoutePoints = sanitizeRoutePoints(state.pendingRoutePoints).slice(pendingRoutePayload.length);
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

    function render() {
        var statusMeta = getPrimaryStatusMeta();
        var tripMeta = getTripTagMeta();
        var syncMeta = getSyncMeta();
        var trackingMeta = getTrackingMeta();
        var trackingUrl = buildTrackingUrl();
        var idleTimerValue = getIdleTimerValue();

        setText(elements.status, statusMeta.label);
        setText(elements.statusCopy, statusMeta.copy);
        setText(elements.distance, formatDistance(state.meters));
        setText(elements.fare, formatCurrency(state.currentFare));
        setText(elements.waiting, formatDuration(state.waitingSeconds));
        setText(elements.speed, Number(state.currentSpeedKph || 0).toFixed(1));
        setText(elements.lastSync, getLastSyncLabel());
        setText(elements.accuracy, state.lastPosition ? formatAccuracyValue(state.lastPosition.accuracy) + ' accuracy' : 'No GPS fix');
        setText(elements.startedAt, state.startedAt ? formatDateTime(state.startedAt) : 'Not started');
        setText(elements.lastPointAt, state.lastPosition ? formatDateTime(state.lastPosition.timestamp) : 'No route points yet');
        setText(elements.idleTimer, idleTimerValue);
        setText(elements.idleTimerSecondary, idleTimerValue);
        renderLocationText(elements.latLng, state.lastPosition, 'No location yet', 'Resolving current location...', 'Current location unavailable.');
        setText(elements.fareDistance, formatCurrency(state.breakdown.distance_fare || 0));
        setText(elements.fareWaiting, formatCurrency(state.breakdown.waiting_fare || 0));
        setText(elements.fareSurcharge, formatCurrency(state.breakdown.night_surcharge || 0));
        setText(elements.fareMinimum, formatCurrency(state.breakdown.minimum_adjustment || 0));
        setText(elements.routeCount, String(state.routePoints.length));

        if (elements.statusDot) {
            elements.statusDot.className = 'meter-dot ' + statusMeta.dotClass;
        }

        setBadge(elements.syncState, syncMeta.label, syncMeta.className);
        setBadge(elements.tripTag, tripMeta.label, tripMeta.className);
        setBadge(elements.trackingLinkStatus, trackingMeta.label, trackingMeta.className);
        setValue(elements.passengerLinkInput, state.publicTrackingToken || '4-digit tracking code becomes available after trip start.');
        setValue(elements.passengerUrlInput, trackingUrl || 'Passenger share URL appears after the first fare sync.');

        if (elements.endButton) {
            elements.endButton.disabled = !state.tripToken || state.ending;
        }

        if (elements.startButton) {
            elements.startButton.disabled = !config.hasFareSettings || !!state.tripToken || !state.lastPosition;
        }

        if (elements.copyLinkButton) {
            elements.copyLinkButton.disabled = !state.publicTrackingToken;
        }

        if (elements.copyTrackingUrlButton) {
            elements.copyTrackingUrlButton.disabled = !trackingUrl;
        }

        renderPoints();
        updateMap();
    }

    function renderPoints() {
        var routePoints = sanitizeRoutePoints(state.routePoints);
        if (!routePoints.length) {
            if (elements.recentPoints) {
                elements.recentPoints.innerHTML = '<li class="meter-point-item is-empty">No locations recorded yet.</li>';
            }
            setText(elements.mapStatus, 'Map centers on the latest GPS point and draws the current route line.');
            return;
        }

        var points = routePoints.slice(-5).reverse();
        if (elements.recentPoints) {
            elements.recentPoints.innerHTML = points.map(function (point, index) {
                return '<li class="meter-point-item">'
                    + '<p class="meter-point-label">Point ' + String(routePoints.length - index).padStart(2, '0') + '</p>'
                    + '<p class="meter-point-label" data-location-lat="' + point.lat + '" data-location-lng="' + point.lng + '">Resolving location...</p>'
                    + '<p class="meter-point-meta">' + formatDateTime(point.timestamp) + ' • ' + Number(point.speedKph || 0).toFixed(1) + ' km/h</p>'
                    + '</li>';
            }).join('');
        }

        hydrateLocationNodes(elements.recentPoints, 'Resolving location...', 'Location unavailable.');

        if (state.ending) {
            setText(elements.mapStatus, 'Finalizing the trip. The latest route is ready for the final passenger snapshot.');
            return;
        }

        if (state.tripToken) {
            setText(elements.mapStatus, 'The published route line and passenger share feed update while the trip is active.');
            return;
        }

        setText(elements.mapStatus, 'GPS preview is active. Starting a trip opens a new passenger share session.');
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
                'line-color': '#0ea5e9',
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
        } else if (driverMarker) {
            driverMarker.remove();
            driverMarker = null;
        }

        var viewportSignature = buildViewportSignature(routePoints, state.lastPosition);
        if (viewportSignature === lastViewportSignature) {
            return;
        }

        lastViewportSignature = viewportSignature;
        focusMap(routePoints, state.lastPosition);
    }

    function focusMap(routePoints, lastPosition) {
        if (!map || !lastPosition) {
            return;
        }

        if (routePoints.length <= 1) {
            map.easeTo({
                center: [lastPosition.lng, lastPosition.lat],
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
        if (!elements.permission) {
            return;
        }

        elements.permission.textContent = message;
        elements.permission.className = 'meter-permission px-4 py-3 text-sm ' + (isError ? 'is-error' : 'is-ready');
    }

    function showToast(message, tone) {
        if (!elements.toast) {
            return;
        }

        elements.toast.textContent = message;
        elements.toast.className = 'pointer-events-none fixed bottom-3 left-3 right-3 max-w-sm meter-toast sm:bottom-4 sm:left-auto sm:right-4 is-' + (tone || 'sky');
        elements.toast.classList.remove('hidden');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(function () {
            elements.toast.classList.add('hidden');
        }, 2600);
    }

    function copyText(value, successMessage) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                showToast(successMessage, 'emerald');
            }).catch(function () {
                showToast('Unable to copy. Copy it manually.', 'rose');
            });
            return;
        }

        var input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'absolute';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.select();

        try {
            document.execCommand('copy');
            showToast(successMessage, 'emerald');
        } catch (error) {
            showToast('Unable to copy. Copy it manually.', 'rose');
        }

        document.body.removeChild(input);
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
        var date = parseDate(value);
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

    function buildTrackingUrl() {
        if (state.publicTrackingUrl) {
            return state.publicTrackingUrl;
        }

        if (!state.publicTrackingToken) {
            return '';
        }

        return config.trackingBaseUrl + (config.trackingBaseUrl.indexOf('?') === -1 ? '?token=' : '&token=') + encodeURIComponent(state.publicTrackingToken);
    }

    function getPrimaryStatusMeta() {
        if (state.ending) {
            return {
                label: 'Finalizing trip',
                copy: 'Finalizing the trip and writing the final receipt. Keep this tab open until the redirect completes.',
                dotClass: 'is-ending'
            };
        }

        if (state.tripToken && state.syncError) {
            return {
                label: 'In trip',
                copy: 'Trip is active, but the last server sync failed. Passenger tracking may be stale until the next successful sync.',
                dotClass: 'is-error'
            };
        }

        if (state.tripToken && state.status === 'in_trip') {
            return {
                label: 'In trip',
                copy: state.publicTrackingToken
                    ? 'Trip is active. The passenger share code is ready and the public route feed is live.'
                    : 'Trip is active. The passenger share code will appear after the next successful sync.',
                dotClass: 'is-live'
            };
        }

        if (state.lastPosition) {
            return {
                label: 'Ready to start',
                copy: 'GPS fix acquired. Confirm the vehicle type, then start the trip when the rider is on board.',
                dotClass: 'is-ready'
            };
        }

        return {
            label: 'Waiting for GPS',
            copy: 'Waiting for the first GPS fix. Keep location services enabled and allow browser access when prompted.',
            dotClass: 'is-waiting'
        };
    }

    function getTripTagMeta() {
        if (state.ending) {
            return {
                label: 'Finalizing',
                className: 'is-ending'
            };
        }

        if (state.tripToken && state.status === 'in_trip') {
            return {
                label: 'Active',
                className: 'is-live'
            };
        }

        if (state.lastPosition) {
            return {
                label: 'Ready',
                className: 'is-ready'
            };
        }

        return {
            label: 'Standby',
            className: 'is-idle'
        };
    }

    function getSyncMeta() {
        if (state.syncError) {
            return {
                label: 'Sync issue',
                className: 'is-error'
            };
        }

        if (state.syncing) {
            return {
                label: 'Syncing',
                className: 'is-live'
            };
        }

        if (state.tripToken && state.lastSyncAt) {
            return {
                label: 'Fare synced',
                className: 'is-ready'
            };
        }

        if (state.tripToken) {
            return {
                label: 'Pending sync',
                className: 'is-ready'
            };
        }

        if (state.lastPosition) {
            return {
                label: 'GPS ready',
                className: 'is-ready'
            };
        }

        return {
            label: 'Idle',
            className: 'is-idle'
        };
    }

    function getTrackingMeta() {
        if (state.publicTrackingToken) {
            return {
                label: 'Code ready',
                className: 'is-live'
            };
        }

        if (state.tripToken) {
            return {
                label: 'Preparing',
                className: 'is-ready'
            };
        }

        return {
            label: 'Inactive',
            className: 'is-idle'
        };
    }

    function getLastSyncLabel() {
        if (state.lastSyncAt) {
            return formatDateTime(state.lastSyncAt);
        }

        if (state.tripToken) {
            return 'Awaiting first sync';
        }

        if (state.lastPosition) {
            return 'GPS preview only';
        }

        return 'Not synced yet';
    }

    function getIdleTimerValue() {
        if (!state.tripToken) {
            return 'Not tracking yet';
        }

        if (!state.idleSince) {
            return '00:00';
        }

        return formatDuration(Math.floor((Date.now() - state.idleSince) / 1000));
    }

    function formatAccuracyValue(value) {
        var accuracy = Math.max(0, Number(value || 0));
        if (!accuracy) {
            return '0 m';
        }

        return accuracy >= 10 ? Math.round(accuracy) + ' m' : accuracy.toFixed(1) + ' m';
    }

    function buildViewportSignature(routePoints, lastPosition) {
        var tripKey = state.tripToken || 'preview';

        if (routePoints.length > 1) {
            var firstPoint = routePoints[0];
            var lastPoint = routePoints[routePoints.length - 1];
            return [
                tripKey,
                routePoints.length,
                firstPoint.lat.toFixed(5),
                firstPoint.lng.toFixed(5),
                lastPoint.lat.toFixed(5),
                lastPoint.lng.toFixed(5)
            ].join('|');
        }

        if (lastPosition) {
            return tripKey + '|point|' + lastPosition.lat.toFixed(5) + '|' + lastPosition.lng.toFixed(5);
        }

        return tripKey + '|empty';
    }

    function setBadge(element, text, stateClass) {
        if (!element) {
            return;
        }

        element.textContent = text;
        element.className = 'meter-pill ' + stateClass;
    }

    function setText(element, value) {
        if (!element) {
            return;
        }

        element.textContent = value;
    }

    function setValue(element, value) {
        if (!element) {
            return;
        }

        element.value = value;
    }
})();
