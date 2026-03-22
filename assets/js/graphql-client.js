(function () {
    function parseGraphQLResponse(response) {
        return response.text().then(function (text) {
            var payload = null;

            try {
                payload = text ? JSON.parse(text) : null;
            } catch (error) {
                throw new Error('Server returned invalid JSON. Check PHP warnings or session redirects.');
            }

            var errors = payload && Array.isArray(payload.errors) ? payload.errors : [];
            if (!response.ok || errors.length) {
                var firstError = errors[0] || null;
                throw new Error((firstError && firstError.message) || 'GraphQL request failed.');
            }

            if (!payload || typeof payload !== 'object' || !payload.data) {
                throw new Error('GraphQL response was empty.');
            }

            return payload.data;
        });
    }

    function request(url, options) {
        var method = (options && options.method ? String(options.method) : 'POST').toUpperCase();
        var query = options && options.query ? String(options.query) : '';
        var variables = options && options.variables ? options.variables : {};
        var operationName = options && options.operationName ? String(options.operationName) : '';

        if (method === 'GET') {
            var queryUrl = new URL(url, window.location.href);
            queryUrl.searchParams.set('query', query);

            if (operationName) {
                queryUrl.searchParams.set('operationName', operationName);
            }

            if (variables && Object.keys(variables).length) {
                queryUrl.searchParams.set('variables', JSON.stringify(variables));
            }

            return fetch(queryUrl.toString(), {
                method: 'GET',
                credentials: 'same-origin'
            }).then(parseGraphQLResponse);
        }

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                query: query,
                variables: variables,
                operationName: operationName
            })
        }).then(parseGraphQLResponse);
    }

    window.MetreGraphQL = {
        request: request
    };
})();
