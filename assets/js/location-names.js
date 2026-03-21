(function () {
    var memoryCache = {};
    var pendingRequests = {};
    var storageKey = 'metre_location_cache_v1';

    function normalizeCoordinate(value) {
        var parsed = Number(value);
        return isFinite(parsed) ? parsed.toFixed(5) : null;
    }

    function cacheKey(lat, lng) {
        var normalizedLat = normalizeCoordinate(lat);
        var normalizedLng = normalizeCoordinate(lng);

        if (normalizedLat === null || normalizedLng === null) {
            return '';
        }

        return normalizedLat + ',' + normalizedLng;
    }

    function readStoredCache() {
        if (readStoredCache.loaded) {
            return readStoredCache.value;
        }

        var parsed = {};

        try {
            var raw = window.sessionStorage.getItem(storageKey);
            if (raw) {
                parsed = JSON.parse(raw) || {};
            }
        } catch (error) {
            parsed = {};
        }

        readStoredCache.loaded = true;
        readStoredCache.value = parsed;

        return parsed;
    }

    function writeStoredCache(cache) {
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(cache));
        } catch (error) {
            return;
        }
    }

    function getCachedName(lat, lng) {
        var key = cacheKey(lat, lng);
        if (!key) {
            return '';
        }

        if (Object.prototype.hasOwnProperty.call(memoryCache, key)) {
            return memoryCache[key];
        }

        var stored = readStoredCache();
        if (!Object.prototype.hasOwnProperty.call(stored, key)) {
            return '';
        }

        memoryCache[key] = stored[key];
        return stored[key];
    }

    function setCachedName(key, value) {
        if (!key || !value) {
            return;
        }

        memoryCache[key] = value;

        var stored = readStoredCache();
        stored[key] = value;
        writeStoredCache(stored);
    }

    function firstDefined(values) {
        for (var index = 0; index < values.length; index += 1) {
            if (values[index]) {
                return values[index];
            }
        }

        return '';
    }

    function pushUnique(target, value) {
        var normalized = String(value || '').trim();
        if (!normalized) {
            return;
        }

        var alreadyIncluded = target.some(function (existing) {
            return existing.toLowerCase() === normalized.toLowerCase();
        });

        if (!alreadyIncluded) {
            target.push(normalized);
        }
    }

    function buildLocationName(payload) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }

        var address = payload.address || {};
        var parts = [];

        pushUnique(parts, firstDefined([
            payload.name,
            address.amenity,
            address.shop,
            address.tourism,
            address.leisure,
            address.building
        ]));
        pushUnique(parts, firstDefined([
            address.road,
            address.pedestrian,
            address.footway,
            address.path,
            address.cycleway,
            address.highway
        ]));
        pushUnique(parts, firstDefined([
            address.neighbourhood,
            address.suburb,
            address.quarter,
            address.hamlet,
            address.village,
            address.town,
            address.city,
            address.municipality
        ]));
        pushUnique(parts, firstDefined([
            address.city,
            address.town,
            address.municipality,
            address.county,
            address.state_district
        ]));
        pushUnique(parts, firstDefined([
            address.state,
            address.region,
            address.province
        ]));

        if (parts.length) {
            return parts.slice(0, 4).join(', ');
        }

        if (!payload.display_name) {
            return '';
        }

        return String(payload.display_name)
            .split(',')
            .map(function (part) {
                return part.trim();
            })
            .filter(function (part) {
                return part !== '';
            })
            .slice(0, 4)
            .join(', ');
    }

    function resolveName(lat, lng) {
        var key = cacheKey(lat, lng);
        if (!key) {
            return Promise.resolve('');
        }

        var cached = getCachedName(lat, lng);
        if (cached) {
            return Promise.resolve(cached);
        }

        if (pendingRequests[key]) {
            return pendingRequests[key];
        }

        var params = new URLSearchParams({
            format: 'jsonv2',
            lat: String(lat),
            lon: String(lng),
            zoom: '18',
            addressdetails: '1',
            'accept-language': document.documentElement.lang || 'en'
        });

        pendingRequests[key] = fetch('https://nominatim.openstreetmap.org/reverse?' + params.toString(), {
            headers: {
                Accept: 'application/json'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Location lookup failed.');
                }

                return response.json();
            })
            .then(function (payload) {
                var name = buildLocationName(payload);

                if (name) {
                    setCachedName(key, name);
                }

                delete pendingRequests[key];
                return name;
            }, function () {
                delete pendingRequests[key];
                return '';
            });

        return pendingRequests[key];
    }

    function applyToElement(element, lat, lng, options) {
        if (!element) {
            return Promise.resolve('');
        }

        var key = cacheKey(lat, lng);
        var emptyText = options && options.emptyText ? options.emptyText : 'No location available.';
        var loadingText = options && options.loadingText ? options.loadingText : 'Resolving location...';
        var fallbackText = options && options.fallbackText ? options.fallbackText : 'Location unavailable.';

        if (!key) {
            element.textContent = emptyText;
            return Promise.resolve('');
        }

        element.dataset.locationKey = key;

        var cached = getCachedName(lat, lng);
        element.textContent = cached || loadingText;

        return resolveName(lat, lng).then(function (name) {
            if (element.dataset.locationKey === key) {
                element.textContent = name || fallbackText;
            }

            return name;
        });
    }

    window.MetreLocationNames = {
        applyToElement: applyToElement,
        cacheKey: cacheKey,
        getCachedName: getCachedName,
        resolveName: resolveName
    };
})();