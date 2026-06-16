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
        var button = wrapper.querySelector('[data-dashboard-favorites-toolbar-button]');
        if (button) {
            button.setAttribute('aria-expanded', 'false');
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

    function showToolbarNotice(menu, type, message) {
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
        dismissButton.setAttribute('aria-label', 'Dismiss message');
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

                showToolbarNotice(menu, 'success', json.message || originalText);
            });
        }).catch(function (json) {
            showToolbarNotice(menu, 'error', getAjaxErrorMessage(json, config.clearCache.errorText));
        }).then(function () {
            button.disabled = false;
            button.textContent = originalText;
        });
    }

    function renderMenuItems(menu, config) {
        while (menu.firstChild) {
            menu.removeChild(menu.firstChild);
        }

        var favorites = config.favorites || [];
        if (!favorites.length) {
            menu.appendChild(createElement('div', 'dashboard-favorites-toolbar-empty', config.emptyText || ''));
        } else {
            var addedFavorites = 0;
            for (var i = 0; i < favorites.length; i++) {
                var favorite = favorites[i];
                if (!isSafeUrl(favorite.url || '')) {
                    continue;
                }

                var link = createElement('a', 'dashboard-favorites-toolbar-link', favorite.name || favorite.url || '');
                link.href = favorite.url || '#';
                menu.appendChild(link);
                addedFavorites++;
            }

            if (addedFavorites === 0) {
                menu.appendChild(createElement('div', 'dashboard-favorites-toolbar-empty', config.emptyText || ''));
            }
        }

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
        });

        wrapper.appendChild(button);
        wrapper.appendChild(menu);

        return wrapper;
    }

    function updateToolbarFavorites(favorites) {
        var config = getToolbarConfig();
        var menu = document.querySelector('.dashboard-favorites-toolbar-menu');
        if (!config || !menu) {
            return;
        }

        config.favorites = favorites || [];
        renderMenuItems(menu, config);
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

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.dashboard-favorites-toolbar')) {
                closeMenu(menu);
            }
        });
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
