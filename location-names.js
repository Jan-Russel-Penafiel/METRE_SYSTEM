(function (document) {
    var source = '/metre/assets/js/location-names.js';

    if (document.readyState === 'loading') {
        document.write('<script src="' + source + '"><\\/script>');
        return;
    }

    var script = document.createElement('script');
    script.src = source;
    script.async = false;
    document.head.appendChild(script);
})(document);