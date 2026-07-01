(function () {
    var DEFAULT_MIN_LENGTH = 2;

    function normalizeText(value) {
        return (value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function getPositiveInt(value, fallback) {
        var number = parseInt(value, 10);

        return number > 0 ? number : fallback;
    }

    function getMinLength(value) {
        return getPositiveInt(value, DEFAULT_MIN_LENGTH);
    }

    function getSearchPath(path) {
        path = String(path || '');
        if (path === '/dashboard') {
            return path;
        }

        if (path.indexOf('/dashboard/') === 0) {
            return path.slice('/dashboard'.length) || '/';
        }

        return path;
    }

    function preparePage(page) {
        page = page || {};
        page.searchName = normalizeText(page.searchName || page.name || '');
        page.searchPath = normalizeText(page.searchPath || getSearchPath(page.path || ''));

        return page;
    }

    function getSearchValue(page, property) {
        page = preparePage(page);

        return property === 'path' ? page.searchPath : page.searchName;
    }

    function matchesPage(page, query, minLength) {
        query = normalizeText(query);

        return query.length >= minLength
            && (
                getSearchValue(page, 'name').indexOf(query) !== -1
                || getSearchValue(page, 'path').indexOf(query) !== -1
            );
    }

    function comparePages(a, b, orderBy, query) {
        var firstProperty = orderBy === 'path' ? 'path' : 'name';
        var secondProperty = firstProperty === 'path' ? 'name' : 'path';
        var normalizedQuery = normalizeText(query);
        var aMatchesPrimary = normalizedQuery.length > 0 && getSearchValue(a, firstProperty).indexOf(normalizedQuery) !== -1;
        var bMatchesPrimary = normalizedQuery.length > 0 && getSearchValue(b, firstProperty).indexOf(normalizedQuery) !== -1;
        var firstComparison;

        if (aMatchesPrimary !== bMatchesPrimary) {
            return aMatchesPrimary ? -1 : 1;
        }

        firstComparison = getSearchValue(a, firstProperty).localeCompare(getSearchValue(b, firstProperty), undefined, { numeric: true });
        if (firstComparison !== 0) {
            return firstComparison;
        }

        return getSearchValue(a, secondProperty).localeCompare(getSearchValue(b, secondProperty), undefined, { numeric: true });
    }

    function filterPages(pages, query, orderBy, minLength) {
        var normalizedQuery = normalizeText(query);
        var matches = [];

        if (normalizedQuery.length < minLength) {
            return {
                pages: [],
                total: 0
            };
        }

        for (var i = 0; i < pages.length; i++) {
            if (matchesPage(pages[i], normalizedQuery, minLength)) {
                matches.push(pages[i]);
            }
        }

        matches.sort(function (a, b) {
            return comparePages(a, b, orderBy, normalizedQuery);
        });

        return {
            pages: matches,
            total: matches.length
        };
    }

    function formatText(template, values) {
        template = String(template || '');
        for (var i = 0; i < values.length; i++) {
            template = template.replace('%' + (i + 1) + '$s', values[i]);
        }

        return template.replace('%s', values[0]);
    }

    function formatResultCount(visible, total, labels) {
        visible = parseInt(visible, 10) || 0;
        total = parseInt(total, 10) || visible;
        labels = labels || {};
        if (visible <= 0) {
            return '';
        }

        return formatText(labels.count || 'results: %s', [visible]);
    }

    window.DashboardFavoritesManagerSearch = {
        normalizeText: normalizeText,
        getPositiveInt: getPositiveInt,
        getMinLength: getMinLength,
        getSearchPath: getSearchPath,
        preparePage: preparePage,
        getSearchValue: getSearchValue,
        matchesPage: matchesPage,
        comparePages: comparePages,
        filterPages: filterPages,
        formatResultCount: formatResultCount
    };
}());
