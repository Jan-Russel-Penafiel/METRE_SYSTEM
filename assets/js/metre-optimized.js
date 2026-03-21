(function () {
    var memoryCache = {};
    var pendingRequests = {};
    var storageKey = 'metre_location_cache_v1';
    var prefetchedUrls = Object.create(null);
    var loadedScripts = Object.create(null);
    var loadedStyles = Object.create(null);
    var boot = window.METRE_BOOT || {};

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

    function onDomReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    }

    function afterFirstPaint(callback) {
        window.requestAnimationFrame(function () {
            window.setTimeout(callback, 0);
        });
    }

    function onIdle(callback, timeout) {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(callback, {
                timeout: timeout || 1200
            });
            return;
        }

        window.setTimeout(callback, 16);
    }

    function loadStyle(href) {
        if (!href) {
            return Promise.resolve();
        }

        if (loadedStyles[href]) {
            return loadedStyles[href];
        }

        loadedStyles[href] = new Promise(function (resolve) {
            var existing = document.querySelector('link[rel="stylesheet"][href="' + href + '"]');
            if (existing) {
                resolve();
                return;
            }

            var link = document.createElement('link');
            var resolved = false;

            function finish() {
                if (resolved) {
                    return;
                }

                resolved = true;
                resolve();
            }

            link.rel = 'stylesheet';
            link.href = href;
            link.onload = finish;
            link.onerror = finish;
            document.head.appendChild(link);
            window.setTimeout(finish, 3000);
        });

        return loadedStyles[href];
    }

    function loadScript(src) {
        if (!src) {
            return Promise.resolve();
        }

        if (loadedScripts[src]) {
            return loadedScripts[src];
        }

        loadedScripts[src] = new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[src="' + src + '"]');
            if (existing) {
                resolve();
                return;
            }

            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = function () {
                resolve();
            };
            script.onerror = function () {
                reject(new Error('Unable to load script: ' + src));
            };
            document.body.appendChild(script);
        });

        return loadedScripts[src];
    }

    function loadScriptsSequentially(sources) {
        var queue = Promise.resolve();

        sources.forEach(function (source) {
            queue = queue.then(function () {
                return loadScript(source);
            });
        });

        return queue;
    }

    function toggleTheme() {
        document.documentElement.classList.toggle('dark');

        try {
            var isDark = document.documentElement.classList.contains('dark');
            localStorage.setItem('metre-theme', isDark ? 'dark' : 'light');
        } catch (error) {
            return;
        }
    }

    function initThemeToggles() {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', toggleTheme, { passive: true });
        });
    }

    function initMobileMenu() {
        var mobileToggle = document.querySelector('[data-mobile-menu-toggle]');
        var mobilePanel = document.querySelector('[data-mobile-menu-panel]');

        if (!mobileToggle || !mobilePanel) {
            return;
        }

        mobileToggle.addEventListener('click', function () {
            mobilePanel.classList.toggle('hidden');
        });
    }

    function isInternalLink(anchor) {
        if (!anchor || !anchor.href || anchor.target || anchor.hasAttribute('download')) {
            return false;
        }

        if (anchor.origin !== window.location.origin) {
            return false;
        }

        if (anchor.hash && anchor.pathname === window.location.pathname) {
            return false;
        }

        return true;
    }

    function prefetchUrl(href) {
        if (!href || prefetchedUrls[href]) {
            return;
        }

        prefetchedUrls[href] = true;

        var link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = href;
        link.as = 'document';
        document.head.appendChild(link);
    }

    function initLinkPrefetch() {
        function queuePrefetch(event) {
            var anchor = event.target.closest ? event.target.closest('a[href]') : null;
            if (!isInternalLink(anchor)) {
                return;
            }

            prefetchUrl(anchor.href);
        }

        document.addEventListener('mouseover', queuePrefetch, { passive: true });
        document.addEventListener('focusin', queuePrefetch);
        document.addEventListener('touchstart', queuePrefetch, { passive: true });
    }

    function initReceiptLocationOutputs() {
        var nodes = document.querySelectorAll('[data-location-output]');
        if (!nodes.length) {
            return;
        }

        onIdle(function () {
            Array.prototype.forEach.call(nodes, function (node) {
                window.MetreLocationNames.applyToElement(node, node.getAttribute('data-lat'), node.getAttribute('data-lng'), {
                    loadingText: 'Resolving location...',
                    fallbackText: 'Location unavailable.'
                });
            });
        }, 800);
    }

    function loadMapPrerequisites() {
        if (!boot.maplibre) {
            return Promise.resolve();
        }

        var cssPromise = loadStyle(boot.maplibre.css);
        var jsPromise = loadScript(boot.maplibre.js);

        return Promise.all([cssPromise, jsPromise]).then(function () {
            return null;
        }, function () {
            return null;
        });
    }

    function bootPageScripts() {
        var pageScripts = Array.isArray(boot.pageScripts) ? boot.pageScripts.filter(Boolean) : [];
        if (!pageScripts.length) {
            return;
        }

        loadMapPrerequisites().then(function () {
            return loadScriptsSequentially(pageScripts);
        }).catch(function () {
            return;
        });
    }

    window.MetreRuntime = {
        afterFirstPaint: afterFirstPaint,
        loadScript: loadScript,
        loadStyle: loadStyle,
        onIdle: onIdle
    };

    onDomReady(function () {
        initThemeToggles();
        initMobileMenu();
        initLinkPrefetch();
        afterFirstPaint(function () {
            initReceiptLocationOutputs();
            bootPageScripts();
        });
    });
})();
