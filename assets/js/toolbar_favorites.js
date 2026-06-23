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
    }

    function getAjaxErrorMessage(response, fallback) {
        if (response && response.message) {
            return response.message;
        }

        return fallback || 'Unable to clear cache.';
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
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
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

    function renderFavoritesList(container, config) {
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
        var favorites = config.favorites || [];
        if (!favorites.length) {
            container.appendChild(createElement('div', 'dashboard-favorites-toolbar-empty', config.emptyText || ''));
        } else {
            var addedFavorites = 0;
            for (var i = 0; i < favorites.length; i++) {
                var favorite = favorites[i];
                if (!isSafeUrl(favorite.url || '')) {
                    continue;
                }

                var link = createElement('a', 'dashboard-favorites-toolbar-link', favorite.name || favorite.url || '');
                link.href = favorite.url || '#';
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

    function submitToolbarSearchToggle(event, item, page, menu, config, searchConfig) {
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
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                if (!response.ok || !json.success) {
                    throw json;
                }

                updateToolbarSearchStar(item, json.favorite === true, searchConfig);
                if (page) {
                    page.isFavorite = json.favorite === true;
                }
                if (json.favorites) {
                    config.favorites = json.favorites;
                    var favoritesList = menu.querySelector('[data-dashboard-favorites-toolbar-list]');
                    if (favoritesList) {
                        renderFavoritesList(favoritesList, config);
                    }
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

    function renderToolbarSearchResult(page, menu, config, searchConfig) {
        var item = createElement('div', 'dashboard-favorites-toolbar-search-result');
        item.setAttribute('data-dashboard-favorites-toolbar-search-result', '1');

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
            submitToolbarSearchToggle(event, item, page, menu, config, searchConfig);
        });

        var main = createElement('div', 'dashboard-favorites-toolbar-search-result-main');
        main.appendChild(createElement('div', 'dashboard-favorites-toolbar-search-result-name', page.name || ''));
        main.appendChild(createElement('div', 'dashboard-favorites-toolbar-search-result-path', page.path || ''));

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

    function filterToolbarSearchPages(pages, query) {
        var normalizedQuery = normalizeToolbarSearchText(query);
        var matches = [];
        if (normalizedQuery.length < 2) {
            return matches;
        }

        for (var i = 0; i < pages.length; i++) {
            if ((pages[i].searchText || '').indexOf(normalizedQuery) === -1) {
                continue;
            }

            matches.push(pages[i]);
            if (matches.length >= 12) {
                break;
            }
        }

        return matches;
    }

    function renderToolbarSearchStatus(results, text) {
        while (results.firstChild) {
            results.removeChild(results.firstChild);
        }
        if (text) {
            results.appendChild(createElement('div', 'dashboard-favorites-toolbar-search-empty', text));
        }
        results.hidden = !text;
    }

    function renderToolbarSearchResults(results, pages, menu, config, searchConfig) {
        while (results.firstChild) {
            results.removeChild(results.firstChild);
        }

        if (!pages.length) {
            renderToolbarSearchStatus(results, searchConfig.emptyText || '');
            return;
        }

        for (var i = 0; i < pages.length; i++) {
            results.appendChild(renderToolbarSearchResult(pages[i], menu, config, searchConfig));
        }
        results.hidden = false;
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
        var control = createElement('div', 'dashboard-favorites-toolbar-search-control');
        var input = createElement('input', 'dashboard-favorites-toolbar-search-input');
        input.type = 'search';
        input.placeholder = searchConfig.placeholder || '';
        input.autocomplete = 'off';

        var clearButton = createElement('button', 'dashboard-favorites-toolbar-search-clear', 'x');
        clearButton.type = 'button';
        clearButton.hidden = true;
        clearButton.setAttribute('aria-label', searchConfig.clearText || 'Clear search');

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
                return response.json().catch(function () {
                    return {};
                }).then(function (json) {
                    if (!response.ok || !json.success) {
                        throw json;
                    }

                    cachedPages = (json.pages || []).map(function (page) {
                        page.searchText = normalizeToolbarSearchText(page.name || '');

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

                    renderToolbarSearchResults(results, filterToolbarSearchPages(pages, query), menu, config, searchConfig);
                }).catch(function (json) {
                    if (currentRequestID === requestID) {
                        renderToolbarSearchStatus(results, getAjaxErrorMessage(json, searchConfig.errorText));
                    }
                });
            }, 120);
        }

        input.addEventListener('focus', function () {
            fetchToolbarSearchPages().catch(function () {});
        });

        input.addEventListener('input', updateToolbarSearchResults);

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
        if (document.querySelector('.dashboard-favorites-toolbar')) {
            return;
        }

        var config = getToolbarConfig();
        if (!config) {
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
