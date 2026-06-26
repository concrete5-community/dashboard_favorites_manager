(function () {
    function createElement(tag, className, text) {
        var element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (text) {
            element.textContent = text;
        }

        return element;
    }

    function removeOverlayMessage(message) {
        if (message && message.parentNode) {
            var container = message.parentNode;
            container.removeChild(message);
            if (!container.children.length && container.parentNode) {
                container.parentNode.removeChild(container);
            }
        }
    }

    function hideOverlayMessage(message) {
        if (!message || message.classList.contains('is-hiding')) {
            return;
        }

        message.classList.add('is-hiding');
        window.setTimeout(function () {
            removeOverlayMessage(message);
        }, 300);
    }

    function getOverlayAlertClass(type) {
        if (type === 'error') {
            return 'danger';
        }
        if (type === 'success' || type === 'warning' || type === 'info') {
            return type;
        }

        return 'info';
    }

    function renderOverlayMessages(config) {
        var messages = config && config.overlayMessages;
        if (!messages || !messages.length || document.querySelector('[data-dashboard-favorites-overlay-messages]')) {
            return;
        }

        var container = createElement('div', 'dashboard-favorites-overlay-messages');
        container.setAttribute('data-dashboard-favorites-overlay-messages', '1');
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-atomic', 'false');

        messages.forEach(function (item) {
            if (!item || !item.message) {
                return;
            }

            var alertClass = getOverlayAlertClass(item.type);
            var message = createElement('div', 'dashboard-favorites-overlay-toast alert alert-' + alertClass + ' alert-dismissible');
            var close = createElement('button', 'btn-close');
            close.type = 'button';
            close.setAttribute('aria-label', config.dismissText || 'Dismiss message');
            close.setAttribute('data-dashboard-favorites-overlay-dismiss', '1');
            close.addEventListener('click', function () {
                hideOverlayMessage(message);
            });

            message.setAttribute('role', alertClass === 'danger' ? 'alert' : 'status');
            message.setAttribute('data-dashboard-favorites-overlay-message', '1');
            message.appendChild(close);
            message.appendChild(document.createTextNode(item.message));
            container.appendChild(message);

            window.setTimeout(function () {
                hideOverlayMessage(message);
            }, 3000);
        });

        if (container.children.length) {
            document.body.appendChild(container);
        }
    }

    function closeMenu(wrapper) {
        wrapper.classList.remove('is-open');
        wrapper.classList.remove('is-viewport-positioned');
        wrapper.style.removeProperty('--dashboard-favorites-toolbar-menu-left');
        var button = wrapper.querySelector('[data-dashboard-favorites-toolbar-button]');
        if (button) {
            button.setAttribute('aria-expanded', 'false');
        }
    }

    function getViewportWidth() {
        var widths = [];
        if (window.innerWidth) {
            widths.push(window.innerWidth);
        }
        if (document.documentElement && document.documentElement.clientWidth) {
            widths.push(document.documentElement.clientWidth);
        }
        if (window.visualViewport && window.visualViewport.width) {
            widths.push(window.visualViewport.width);
        }

        return widths.length ? Math.min.apply(Math, widths) : 0;
    }

    function getViewportCenterLeft() {
        if (window.visualViewport && window.visualViewport.width) {
            return window.visualViewport.offsetLeft + (window.visualViewport.width / 2);
        }

        return getViewportWidth() / 2;
    }

    function positionMenuInViewport(wrapper) {
        var menu = wrapper.querySelector('.dashboard-favorites-toolbar-menu');
        if (!menu || !wrapper.classList.contains('is-open')) {
            return;
        }

        wrapper.classList.remove('is-viewport-positioned');
        wrapper.style.removeProperty('--dashboard-favorites-toolbar-menu-left');

        var viewportWidth = getViewportWidth();
        if (!viewportWidth) {
            return;
        }

        var rect = menu.getBoundingClientRect();
        if (rect.left < 8 || rect.right > viewportWidth - 8) {
            wrapper.style.setProperty('--dashboard-favorites-toolbar-menu-left', getViewportCenterLeft() + 'px');
            wrapper.classList.add('is-viewport-positioned');
        }
    }

    function isSafeUrl(url) {
        var trimmed = (url || '').trim();
        if (!trimmed || /[\x00-\x1F\x7F]/.test(trimmed)) {
            return false;
        }

        if (/^(?:javascript|data|vbscript):/i.test(trimmed)) {
            return false;
        }

        var scheme = trimmed.match(/^([a-z][a-z0-9+.-]*):/i);

        return !scheme || /^(?:http|https)$/i.test(scheme[1]);
    }

    function showToolbarNotice(menu, type, message, config) {
        var notice = menu.querySelector('[data-dashboard-favorites-toolbar-notice]');
        if (!notice) {
            notice = createElement('div', 'dashboard-favorites-toolbar-notice');
            notice.setAttribute('data-dashboard-favorites-toolbar-notice', '1');
            notice.setAttribute('role', 'status');
            notice.setAttribute('aria-live', 'polite');
        }
        menu.appendChild(notice);

        while (notice.firstChild) {
            notice.removeChild(notice.firstChild);
        }

        var noticeText = createElement('span', 'dashboard-favorites-toolbar-notice-text', message || '');
        var dismissButton = createElement('button', 'dashboard-favorites-toolbar-notice-dismiss', 'x');
        dismissButton.type = 'button';
        dismissButton.setAttribute('aria-label', (config && config.dismissText) || 'Dismiss message');
        dismissButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (notice._dashboardFavoritesNoticeTimer) {
                window.clearTimeout(notice._dashboardFavoritesNoticeTimer);
            }
            if (notice.parentNode) {
                notice.parentNode.removeChild(notice);
            }
        });

        notice.className = 'dashboard-favorites-toolbar-notice dashboard-favorites-toolbar-notice-' + type;
        notice.appendChild(noticeText);
        notice.appendChild(dismissButton);
        notice.style.animation = 'none';
        notice.offsetHeight;
        notice.style.animation = '';

        if (notice._dashboardFavoritesNoticeTimer) {
            window.clearTimeout(notice._dashboardFavoritesNoticeTimer);
        }
        notice._dashboardFavoritesNoticeTimer = window.setTimeout(function () {
            if (notice.parentNode) {
                notice.parentNode.removeChild(notice);
            }
        }, 6000);
    }

    function getAjaxErrorMessage(response, fallback) {
        if (response && response.message) {
            return response.message;
        }

        return fallback || 'Unable to clear cache.';
    }

    function isSessionExpiredResponse(response) {
        if (!response) {
            return false;
        }

        var url = String(response.url || '').toLowerCase();

        return response.status === 401
            || response.status === 403
            || response.redirected
            || url.indexOf('/login') !== -1
            || url.indexOf('session_invalidated') !== -1;
    }

    function getSessionExpiredMessage(config) {
        return (config && config.sessionExpiredText) || 'Session expired. Please sign in again.';
    }

    function parseAjaxJsonResponse(response, config) {
        if (isSessionExpiredResponse(response)) {
            return window.Promise.reject({
                message: getSessionExpiredMessage(config)
            });
        }

        var contentType = response.headers && response.headers.get ? String(response.headers.get('Content-Type') || '') : '';
        if (contentType && contentType.toLowerCase().indexOf('application/json') === -1) {
            return response.text().catch(function () {
                return '';
            }).then(function (text) {
                var lowerText = String(text || '').toLowerCase();
                if (lowerText.indexOf('/login') !== -1 || lowerText.indexOf('session_invalidated') !== -1) {
                    return window.Promise.reject({
                        message: getSessionExpiredMessage(config)
                    });
                }

                return window.Promise.reject({});
            });
        }

        return response.json().catch(function () {
            return window.Promise.reject({});
        });
    }

    function submitClearCacheForm(event, menu, config, button) {
        if (!window.fetch || !window.FormData) {
            return;
        }

        event.preventDefault();

        var form = event.currentTarget;
        var originalText = button.textContent;
        button.disabled = true;

        window.fetch(form.action, {
            method: 'POST',
            body: new window.FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return parseAjaxJsonResponse(response, config).then(function (json) {
                if (!response.ok || !json.success) {
                    throw json;
                }

                showToolbarNotice(menu, 'success', json.message || originalText, config);
            });
        }).catch(function (json) {
            showToolbarNotice(menu, 'error', getAjaxErrorMessage(json, config.clearCache.errorText), config);
        }).then(function () {
            button.disabled = false;
            button.textContent = originalText;
        });
    }

    function getFavoriteDisplayPath(favorite) {
        var path = favorite && favorite.path ? String(favorite.path) : '';
        if (!path && favorite && favorite.url) {
            path = String(favorite.url);
        }

        try {
            path = new URL(path, window.location.href).pathname || path;
        } catch (e) {
            // Keep the original string when URL parsing is not possible.
        }

        path = path.replace(/\/index\.php(?=\/|$)/, '').trim();

        return path;
    }

    function getFavoritePageLookup(favorites) {
        var lookup = {
            pageIDs: {},
            paths: {}
        };

        for (var i = 0; i < favorites.length; i++) {
            var favorite = favorites[i] || {};
            var pageID = parseInt(favorite.pageID, 10);
            var path = getFavoriteDisplayPath(favorite);
            if (pageID > 0) {
                lookup.pageIDs[String(pageID)] = true;
            }
            if (path) {
                lookup.paths[path] = true;
            }
        }

        return lookup;
    }

    function isToolbarSearchPageFavorite(page, lookup) {
        var pageID = parseInt(page && page.id, 10);
        if (pageID > 0 && lookup.pageIDs[String(pageID)]) {
            return true;
        }

        var path = getFavoriteDisplayPath(page);

        return !!(path && lookup.paths[path]);
    }

    function isToolbarSearchResultFavorite(item, lookup) {
        var pageID = parseInt(item.getAttribute('data-dashboard-favorites-toolbar-search-page-id'), 10);
        if (pageID > 0 && lookup.pageIDs[String(pageID)]) {
            return true;
        }

        var path = item.getAttribute('data-dashboard-favorites-toolbar-search-page-path') || '';

        return !!(path && lookup.paths[path]);
    }

    function updateToolbarSearchResultFavorites(results, favorites, searchConfig) {
        var lookup = getFavoritePageLookup(favorites || []);
        var items = results.querySelectorAll('[data-dashboard-favorites-toolbar-search-result]');

        for (var i = 0; i < items.length; i++) {
            updateToolbarSearchStar(items[i], isToolbarSearchResultFavorite(items[i], lookup), searchConfig);
        }

        return lookup;
    }

    function getFavoriteNameCounts(favorites) {
        var counts = {};
        for (var i = 0; i < favorites.length; i++) {
            var name = favorites[i] && favorites[i].name ? String(favorites[i].name) : '';
            if (name) {
                counts[name] = (counts[name] || 0) + 1;
            }
        }

        return counts;
    }

    function renderFavoriteMenuLink(link, favorite, nameCounts) {
        var name = favorite.name || favorite.url || '';
        var displayPath = getFavoriteDisplayPath(favorite);
        if (!name || !displayPath || nameCounts[name] <= 1) {
            link.textContent = name;
            return;
        }

        link.classList.add('dashboard-favorites-toolbar-link-has-path');
        link.appendChild(createElement('span', 'dashboard-favorites-toolbar-link-name', name));
        link.appendChild(createElement('span', 'dashboard-favorites-toolbar-link-path', displayPath));
    }

    function renderFavoritesList(container, config) {
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
        var favorites = config.favorites || [];
        var nameCounts = getFavoriteNameCounts(favorites);
        if (!favorites.length) {
            container.appendChild(createElement('div', 'dashboard-favorites-toolbar-empty', config.emptyText || ''));
        } else {
            var addedFavorites = 0;
            for (var i = 0; i < favorites.length; i++) {
                var favorite = favorites[i];
                if (!isSafeUrl(favorite.url || '')) {
                    continue;
                }

                var link = createElement('a', 'dashboard-favorites-toolbar-link');
                link.href = favorite.url || '#';
                renderFavoriteMenuLink(link, favorite, nameCounts);
                container.appendChild(link);
                addedFavorites++;
            }

            if (addedFavorites === 0) {
                container.appendChild(createElement('div', 'dashboard-favorites-toolbar-empty', config.emptyText || ''));
            }
        }
    }

    function updateToolbarSearchStar(item, isFavorite, searchConfig) {
        var value = item.querySelector('[data-dashboard-favorites-toolbar-search-favorite]');
        var button = item.querySelector('[data-dashboard-favorites-toolbar-search-toggle]');
        var icon = button ? button.querySelector('i') : null;
        var text = button ? button.querySelector('.ccm-toolbar-accessibility-title') : null;
        var label = isFavorite ? searchConfig.removeText : searchConfig.addText;

        item.classList.toggle('is-favorite', isFavorite);
        if (value) {
            value.value = isFavorite ? '0' : '1';
        }
        if (button) {
            button.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
            button.title = label || '';
        }
        if (icon) {
            icon.className = (isFavorite ? 'fas' : 'far') + ' fa-star';
        }
        if (text) {
            text.textContent = label || '';
        }
    }

    function submitToolbarSearchToggle(event, menu, config, searchConfig) {
        if (!window.fetch || !window.FormData) {
            return;
        }

        event.preventDefault();

        var form = event.currentTarget;
        var button = form.querySelector('[data-dashboard-favorites-toolbar-search-toggle]');
        if (button) {
            button.disabled = true;
        }

        window.fetch(searchConfig.toggleUrl, {
            method: 'POST',
            body: new window.FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return parseAjaxJsonResponse(response, config).then(function (json) {
                if (!response.ok || !json.success) {
                    throw json;
                }

                if (json.favorites) {
                    updateToolbarFavorites(json.favorites);
                }
                if (typeof window.CustomEvent === 'function') {
                    window.dispatchEvent(new window.CustomEvent('dashboardFavoritesManager:favoritesChanged', {
                        detail: json
                    }));
                }
                if (json.message) {
                    showToolbarNotice(menu, 'success', json.message, config);
                }
            });
        }).catch(function (json) {
            showToolbarNotice(menu, 'error', getAjaxErrorMessage(json, searchConfig.errorText), config);
        }).then(function () {
            if (button) {
                button.disabled = false;
            }
        });
    }

    function appendHighlightedToolbarSearchText(element, text, query) {
        var value = text || '';
        if (!query) {
            element.appendChild(document.createTextNode(value));
            return;
        }

        var lowerValue = value.toLowerCase();
        var index = lowerValue.indexOf(query);
        if (index === -1) {
            element.appendChild(document.createTextNode(value));
            return;
        }

        element.appendChild(document.createTextNode(value.slice(0, index)));
        var mark = document.createElement('mark');
        mark.className = 'dashboard-favorites-toolbar-search-highlight';
        mark.textContent = value.slice(index, index + query.length);
        element.appendChild(mark);
        element.appendChild(document.createTextNode(value.slice(index + query.length)));
    }

    function renderToolbarSearchResult(page, menu, config, searchConfig, query) {
        var item = createElement('div', 'dashboard-favorites-toolbar-search-result');
        item.setAttribute('data-dashboard-favorites-toolbar-search-result', '1');
        item.setAttribute('data-dashboard-favorites-toolbar-search-page-id', page.id || '');
        item.setAttribute('data-dashboard-favorites-toolbar-search-page-path', getFavoriteDisplayPath(page));

        var form = createElement('form', 'dashboard-favorites-toolbar-search-toggle-form');
        form.method = 'post';
        form.action = searchConfig.toggleUrl;

        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = 'ccm_token';
        token.value = searchConfig.token || '';

        var pageID = document.createElement('input');
        pageID.type = 'hidden';
        pageID.name = 'page_id';
        pageID.value = page.id || '';

        var favorite = document.createElement('input');
        favorite.type = 'hidden';
        favorite.name = 'favorite';
        favorite.setAttribute('data-dashboard-favorites-toolbar-search-favorite', '1');

        var button = createElement('button', 'dashboard-favorites-toolbar-search-star');
        button.type = 'submit';
        button.setAttribute('data-dashboard-favorites-toolbar-search-toggle', '1');
        var icon = createElement('i');
        icon.setAttribute('aria-hidden', 'true');
        var hiddenText = createElement('span', 'ccm-toolbar-accessibility-title');
        button.appendChild(icon);
        button.appendChild(hiddenText);

        form.appendChild(token);
        form.appendChild(pageID);
        form.appendChild(favorite);
        form.appendChild(button);
        form.addEventListener('submit', function (event) {
            submitToolbarSearchToggle(event, menu, config, searchConfig);
        });

        var main = createElement('div', 'dashboard-favorites-toolbar-search-result-main');
        var name = createElement('div', 'dashboard-favorites-toolbar-search-result-name');
        var path = createElement('div', 'dashboard-favorites-toolbar-search-result-path');
        appendHighlightedToolbarSearchText(name, page.name || '', query);
        appendHighlightedToolbarSearchText(path, page.path || '', query);
        main.appendChild(name);
        main.appendChild(path);

        var link = createElement('a', 'dashboard-favorites-toolbar-search-result-link');
        link.href = isSafeUrl(page.url || '') ? page.url : '#';
        link.title = searchConfig.openText || '';
        link.setAttribute('aria-label', searchConfig.openText || '');
        var arrow = createElement('i', 'fas fa-arrow-right');
        arrow.setAttribute('aria-hidden', 'true');
        link.appendChild(arrow);

        item.appendChild(form);
        item.appendChild(main);
        item.appendChild(link);
        updateToolbarSearchStar(item, page.isFavorite === true, searchConfig);

        return item;
    }

    function normalizeToolbarSearchText(value) {
        return (value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function compareToolbarSearchPages(a, b, orderBy, query) {
        var firstProperty = orderBy === 'path' ? 'searchPath' : 'searchName';
        var secondProperty = orderBy === 'path' ? 'searchName' : 'searchPath';
        var aMatchesPrimary = query.length > 0 && (a[firstProperty] || '').indexOf(query) !== -1;
        var bMatchesPrimary = query.length > 0 && (b[firstProperty] || '').indexOf(query) !== -1;
        if (aMatchesPrimary !== bMatchesPrimary) {
            return aMatchesPrimary ? -1 : 1;
        }

        var firstComparison = (a[firstProperty] || '').localeCompare(b[firstProperty] || '', undefined, { numeric: true });
        if (firstComparison !== 0) {
            return firstComparison;
        }

        return (a[secondProperty] || '').localeCompare(b[secondProperty] || '', undefined, { numeric: true });
    }

    function filterToolbarSearchPages(pages, query, orderBy, maxResults) {
        var normalizedQuery = normalizeToolbarSearchText(query);
        var matches = [];
        var limit = parseInt(maxResults, 10);
        if (normalizedQuery.length < 2) {
            return {
                pages: [],
                total: 0
            };
        }

        for (var i = 0; i < pages.length; i++) {
            if (
                (pages[i].searchName || '').indexOf(normalizedQuery) === -1
                && (pages[i].searchPath || '').indexOf(normalizedQuery) === -1
            ) {
                continue;
            }

            matches.push(pages[i]);
        }

        matches.sort(function (a, b) {
            return compareToolbarSearchPages(a, b, orderBy, normalizedQuery);
        });

        return {
            pages: matches.slice(0, limit > 0 ? limit : 15),
            total: matches.length
        };
    }

    function renderToolbarSearchStatus(results, text, type) {
        while (results.firstChild) {
            results.removeChild(results.firstChild);
        }
        if (text) {
            var status = createElement('div', 'dashboard-favorites-toolbar-search-empty', text);
            if (type) {
                status.classList.add('dashboard-favorites-toolbar-search-empty-' + type);
            }
            results.appendChild(status);
        }
        results.hidden = !text;
    }

    function renderToolbarSearchResults(results, searchResult, menu, config, searchConfig, query) {
        while (results.firstChild) {
            results.removeChild(results.firstChild);
        }

        var pages = searchResult.pages || [];
        if (!pages.length) {
            renderToolbarSearchStatus(results, searchConfig.emptyText || '');
            return;
        }

        var total = searchResult.total || pages.length;
        searchConfig.order.count.textContent = 'results: ' + pages.length + '/' + total;
        results.appendChild(searchConfig.order.element);
        for (var i = 0; i < pages.length; i++) {
            results.appendChild(renderToolbarSearchResult(pages[i], menu, config, searchConfig, query));
        }
        results.hidden = false;
    }

    function createToolbarSearchOrderOption(orderName, value, text, checked) {
        var label = createElement('label', 'dashboard-favorites-toolbar-search-order-option');
        var input = document.createElement('input');
        input.type = 'radio';
        input.name = orderName;
        input.value = value;
        input.checked = checked === true;
        input.setAttribute('data-dashboard-favorites-toolbar-search-order', '1');
        label.appendChild(input);
        label.appendChild(createElement('span', '', text));

        return {
            input: input,
            label: label
        };
    }

    function renderToolbarSearchOrder(searchConfig) {
        var order = createElement('div', 'dashboard-favorites-toolbar-search-order');
        var controls = createElement('div', 'dashboard-favorites-toolbar-search-order-controls');
        var count = createElement('span', 'dashboard-favorites-toolbar-search-result-count');
        var orderName = 'dashboard_favorites_toolbar_search_order_' + Math.random().toString(36).slice(2);
        var nameOrder = createToolbarSearchOrderOption(orderName, 'name', searchConfig.nameText || 'Name', true);
        var pathOrder = createToolbarSearchOrderOption(orderName, 'path', searchConfig.pathText || 'Path', false);

        controls.setAttribute('role', 'radiogroup');
        controls.setAttribute('aria-label', searchConfig.orderByText || 'Order by');
        controls.appendChild(createElement('span', 'dashboard-favorites-toolbar-search-order-label', searchConfig.orderByLabelText || 'order by: '));
        controls.appendChild(nameOrder.label);
        controls.appendChild(pathOrder.label);
        order.appendChild(controls);
        order.appendChild(count);

        return {
            element: order,
            nameOrder: nameOrder.input,
            pathOrder: pathOrder.input,
            count: count
        };
    }

    function createToolbarSearchInput(searchConfig) {
        var input = createElement('input', 'dashboard-favorites-toolbar-search-input');
        input.type = 'search';
        input.placeholder = searchConfig.placeholder || '';
        input.autocomplete = 'off';

        return input;
    }

    function createToolbarSearchClearButton(searchConfig) {
        var clearButton = createElement('button', 'dashboard-favorites-toolbar-search-clear', 'x');
        clearButton.type = 'button';
        clearButton.hidden = true;
        clearButton.setAttribute('aria-label', searchConfig.clearText || 'Clear search');

        return clearButton;
    }

    function renderToolbarSearch(menu, config) {
        var searchConfig = config.search || null;
        if (!searchConfig || !searchConfig.url || !searchConfig.toggleUrl || !searchConfig.token) {
            return null;
        }
        if (!window.fetch || !window.Promise) {
            return null;
        }

        var wrapper = createElement('div', 'dashboard-favorites-toolbar-search');
        var order = renderToolbarSearchOrder(searchConfig);
        searchConfig.order = order;
        var control = createElement('div', 'dashboard-favorites-toolbar-search-control');
        var input = createToolbarSearchInput(searchConfig);
        var clearButton = createToolbarSearchClearButton(searchConfig);
        var results = createElement('div', 'dashboard-favorites-toolbar-search-results');
        results.hidden = true;

        var timer = null;
        var requestID = 0;
        var cachedPages = null;
        var pendingPages = null;

        function fetchToolbarSearchPages() {
            if (cachedPages) {
                return window.Promise.resolve(cachedPages);
            }
            if (pendingPages) {
                return pendingPages;
            }

            var separator = searchConfig.url.indexOf('?') === -1 ? '?' : '&';
            pendingPages = window.fetch(searchConfig.url + separator + 'all=1', {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                return parseAjaxJsonResponse(response, config).then(function (json) {
                    if (!response.ok || !json.success) {
                        throw json;
                    }

                    cachedPages = (json.pages || []).map(function (page) {
                        page.searchName = normalizeToolbarSearchText(page.name || '');
                        page.searchPath = normalizeToolbarSearchText(page.path || '');

                        return page;
                    });

                    return cachedPages;
                });
            }).catch(function (json) {
                pendingPages = null;
                throw json;
            });

            return pendingPages;
        }

        function updateToolbarSearchClear() {
            clearButton.hidden = input.value.length === 0;
        }

        function clearToolbarSearch() {
            if (!input.value) {
                return;
            }

            input.value = '';
            updateToolbarSearchClear();
            window.clearTimeout(timer);
            requestID++;
            renderToolbarSearchStatus(results, '');
            input.focus();
        }

        function updateToolbarSearchResults() {
            var query = input.value.replace(/\s+/g, ' ').trim();
            var orderBy = order.pathOrder.checked ? 'path' : 'name';
            updateToolbarSearchClear();
            window.clearTimeout(timer);
            if (query.length < 2) {
                requestID++;
                renderToolbarSearchStatus(results, '');
                return;
            }

            timer = window.setTimeout(function () {
                var currentRequestID = ++requestID;
                fetchToolbarSearchPages().then(function (pages) {
                    if (currentRequestID !== requestID) {
                        return;
                    }

                    renderToolbarSearchResults(results, filterToolbarSearchPages(pages, query, orderBy, searchConfig.maxResults), menu, config, searchConfig, normalizeToolbarSearchText(query));
                }).catch(function (json) {
                    if (currentRequestID === requestID) {
                        renderToolbarSearchStatus(results, getAjaxErrorMessage(json, searchConfig.errorText), 'error');
                    }
                });
            }, 120);
        }

        function refreshToolbarSearchFavorites(favorites) {
            var lookup = updateToolbarSearchResultFavorites(results, favorites || [], searchConfig);

            if (!cachedPages) {
                return;
            }

            for (var i = 0; i < cachedPages.length; i++) {
                cachedPages[i].isFavorite = isToolbarSearchPageFavorite(cachedPages[i], lookup);
            }
        }

        input.addEventListener('focus', function () {
            fetchToolbarSearchPages().catch(function () {
                // Search errors are shown when rendering results; focus should stay usable.
            });
        });

        input.addEventListener('input', updateToolbarSearchResults);
        order.nameOrder.addEventListener('change', updateToolbarSearchResults);
        order.pathOrder.addEventListener('change', updateToolbarSearchResults);

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                clearToolbarSearch();
            }
        });

        clearButton.addEventListener('click', function (event) {
            event.preventDefault();
            clearToolbarSearch();
        });

        control.appendChild(input);
        control.appendChild(clearButton);
        wrapper.appendChild(control);
        wrapper.appendChild(results);
        wrapper._dashboardFavoritesRefreshSearchFavorites = refreshToolbarSearchFavorites;

        return wrapper;
    }

    function renderMenuItems(menu, config) {
        while (menu.firstChild) {
            menu.removeChild(menu.firstChild);
        }

        if (config.concreteVersion && (config.concreteVersion.name || config.concreteVersion.version)) {
            var menuVersion = createElement('div', 'dashboard-favorites-toolbar-menu-version');
            menuVersion.appendChild(createElement('span', 'dashboard-favorites-toolbar-menu-version-name', config.concreteVersion.name || 'ConcreteCMS'));
            menuVersion.appendChild(createElement('span', 'dashboard-favorites-toolbar-menu-version-number', config.concreteVersion.version || ''));
            menu.appendChild(menuVersion);
        }

        var search = renderToolbarSearch(menu, config);
        if (search) {
            menu.appendChild(search);
        }

        var favoritesList = createElement('div', 'dashboard-favorites-toolbar-list');
        favoritesList.setAttribute('data-dashboard-favorites-toolbar-list', '1');
        renderFavoritesList(favoritesList, config);
        menu.appendChild(favoritesList);

        if (config.clearCache && config.clearCache.url && config.clearCache.token) {
            var clearCacheForm = createElement('form', 'dashboard-favorites-toolbar-action');
            clearCacheForm.method = 'post';
            clearCacheForm.action = config.clearCache.url;

            var token = document.createElement('input');
            token.type = 'hidden';
            token.name = 'ccm_token';
            token.value = config.clearCache.token;

            var clearCacheButton = createElement('button', 'dashboard-favorites-toolbar-action-button', config.clearCache.label || '');
            clearCacheButton.type = 'submit';

            clearCacheForm.appendChild(token);
            clearCacheForm.appendChild(clearCacheButton);
            clearCacheForm.addEventListener('submit', function (event) {
                submitClearCacheForm(event, menu, config, clearCacheButton);
            });
            menu.appendChild(clearCacheForm);
        }

        if (config.logout && config.logout.url && isSafeUrl(config.logout.url)) {
            var logoutLink = createElement('a', 'dashboard-favorites-toolbar-link dashboard-favorites-toolbar-action-link', config.logout.label || '');
            logoutLink.href = config.logout.url;
            menu.appendChild(logoutLink);
        }
    }

    function buildMenu(config, wrapperTag, wrapperClassName) {
        var wrapper = createElement(wrapperTag || 'li', wrapperClassName || 'dashboard-favorites-toolbar float-end');
        if (config.concreteVersion && (config.concreteVersion.name || config.concreteVersion.version)) {
            var version = createElement('span', 'dashboard-favorites-toolbar-version');
            version.appendChild(createElement('span', 'dashboard-favorites-toolbar-version-name', config.concreteVersion.name || 'ConcreteCMS'));
            version.appendChild(createElement('span', 'dashboard-favorites-toolbar-version-number', config.concreteVersion.version || ''));
            wrapper.appendChild(version);
        }

        if (config.favoritesEnabled === true) {
            var button = createElement('button', 'dashboard-favorites-toolbar-button');
            button.type = 'button';
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('aria-haspopup', 'true');
            button.setAttribute('data-dashboard-favorites-toolbar-button', '1');
            button.title = config.title || '';

            var icon = createElement('i', 'fas fa-star');
            icon.setAttribute('aria-hidden', 'true');
            var title = createElement('span', 'ccm-toolbar-accessibility-title', config.title || '');
            button.appendChild(icon);
            button.appendChild(title);

            var menu = createElement('div', 'dashboard-favorites-toolbar-menu');
            renderMenuItems(menu, config);

            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var isOpen = wrapper.classList.toggle('is-open');
                button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (isOpen) {
                    positionMenuInViewport(wrapper);
                } else {
                    wrapper.classList.remove('is-viewport-positioned');
                    wrapper.style.removeProperty('--dashboard-favorites-toolbar-menu-left');
                }
            });

            wrapper.appendChild(button);
            wrapper.appendChild(menu);
        }

        return wrapper;
    }

    function updateToolbarFavorites(favorites) {
        var config = getToolbarConfig();
        var menu = document.querySelector('.dashboard-favorites-toolbar-menu');
        if (!config || !menu) {
            return;
        }

        config.favorites = favorites || [];
        var favoritesList = menu.querySelector('[data-dashboard-favorites-toolbar-list]');
        if (favoritesList) {
            renderFavoritesList(favoritesList, config);
            var search = menu.querySelector('.dashboard-favorites-toolbar-search');
            if (search && typeof search._dashboardFavoritesRefreshSearchFavorites === 'function') {
                search._dashboardFavoritesRefreshSearchFavorites(config.favorites);
            }
        } else {
            renderMenuItems(menu, config);
        }
    }

    function getToolbarConfig() {
        var config = window.DashboardFavoritesManagerToolbar;
        if (!config || config.enabled !== true) {
            return null;
        }

        return config;
    }

    function initToolbarFavorites() {
        var config = getToolbarConfig();
        if (!config) {
            return;
        }

        renderOverlayMessages(config);

        if (document.querySelector('.dashboard-favorites-toolbar')) {
            return;
        }

        var toolbarList = document.querySelector('#ccm-toolbar .ccm-toolbar-item-list');
        var dashboardHeaderMenu = document.querySelector('.ccm-dashboard-header-menu');
        var dashboardPageHeader = document.querySelector('header.ccm-dashboard-page-header');
        if (!toolbarList && !dashboardHeaderMenu && !dashboardPageHeader) {
            return;
        }

        var menu;
        if (toolbarList) {
            var search = toolbarList.querySelector('.ccm-toolbar-search');
            menu = buildMenu(config);
            if (search && search.parentNode === toolbarList) {
                toolbarList.insertBefore(menu, search.nextSibling);
            } else {
                toolbarList.appendChild(menu);
            }
        } else if (dashboardHeaderMenu) {
            menu = buildMenu(config, 'div', 'dashboard-favorites-toolbar dashboard-favorites-toolbar-dashboard');
            dashboardHeaderMenu.appendChild(menu);
        } else {
            menu = buildMenu(config, 'div', 'dashboard-favorites-toolbar dashboard-favorites-toolbar-dashboard');
            dashboardPageHeader.appendChild(menu);
        }

        if (config.favoritesEnabled === true) {
            document.addEventListener('click', function (event) {
                if (!event.target.closest('.dashboard-favorites-toolbar')) {
                    closeMenu(menu);
                }
            });

            window.addEventListener('resize', function () {
                positionMenuInViewport(menu);
            });
        }
    }

    function isCoreDashboardFavoriteControl(target) {
        if (target.closest('.dashboard-favorites-toolbar')) {
            return false;
        }

        var control = target.closest('a[data-bookmark-action]');
        if (!control) {
            return false;
        }

        var action = control.getAttribute('data-bookmark-action');

        return action === 'add-favorite' || action === 'remove-favorite';
    }

    function reloadAfterCoreFavoriteAjax() {
        var reloaded = false;
        var fallback = window.setTimeout(function () {
            if (!reloaded) {
                reloaded = true;
                window.location.reload();
            }
        }, 1200);

        if (!window.jQuery) {
            return;
        }

        window.jQuery(document).one('ajaxComplete', function () {
            if (reloaded) {
                return;
            }

            reloaded = true;
            window.clearTimeout(fallback);
            window.setTimeout(function () {
                window.location.reload();
            }, 150);
        });
    }

    document.addEventListener('click', function (event) {
        if (getToolbarConfig() && isCoreDashboardFavoriteControl(event.target)) {
            reloadAfterCoreFavoriteAjax();
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToolbarFavorites);
    } else {
        initToolbarFavorites();
    }

    window.DashboardFavoritesManagerToolbarUpdate = updateToolbarFavorites;
}());
