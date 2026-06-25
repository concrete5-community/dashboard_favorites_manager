(function () {
    var OPTIONS_PANEL_STORAGE_KEY = 'dashboardFavoritesManager.optionsPanelVisible';

    function getJsonErrorMessage(json, fallback) {
        if (json && json.error) {
            return json.error.message || json.error;
        }

        return fallback;
    }

    function getAjaxErrorMessage(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
            return getJsonErrorMessage(xhr.responseJSON, fallback);
        }

        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }

        if (xhr && xhr.responseText && xhr.responseText.indexOf('<') !== 0) {
            return xhr.responseText;
        }

        return fallback || 'Unable to save favorite order.';
    }

    function showConcreteError(message) {
        if (window.ConcreteAlert && typeof window.ConcreteAlert.error === 'function') {
            window.ConcreteAlert.error({message: message});
            return;
        }

        window.alert(message);
    }

    function getManagerText(name) {
        var wrapper = document.querySelector('.dashboard-favorites-manager');

        return wrapper ? (wrapper.getAttribute(name) || '') : '';
    }

    function getFavoritesTableContainer() {
        return document.querySelector('[data-dashboard-favorites-table-container]');
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function removeOverlayMessage(message) {
        if (!message) {
            return;
        }
        if (message.parentNode) {
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

    function getOverlayMessagesContainer() {
        var container = document.querySelector('[data-dashboard-favorites-overlay-messages]');
        var wrapper = document.querySelector('.dashboard-favorites-manager');
        if (container || !wrapper) {
            return container;
        }

        container = document.createElement('div');
        container.className = 'dashboard-favorites-overlay-messages';
        container.setAttribute('data-dashboard-favorites-overlay-messages', '');
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-atomic', 'false');
        wrapper.insertBefore(container, wrapper.firstChild);

        return container;
    }

    function showOverlayMessage(type, message) {
        if (!message) {
            return;
        }

        var container = getOverlayMessagesContainer();
        if (!container) {
            showConcreteError(message);
            return;
        }

        var messageClass = {
            success: 'success',
            warning: 'warning',
            error: 'danger',
            info: 'info'
        }[type] || 'info';
        var toast = document.createElement('div');
        var button = document.createElement('button');
        toast.className = 'dashboard-favorites-overlay-toast alert alert-' + messageClass + ' alert-dismissible';
        toast.setAttribute('role', messageClass === 'danger' ? 'alert' : 'status');
        toast.setAttribute('data-dashboard-favorites-overlay-message', '');
        button.type = 'button';
        button.className = 'btn-close';
        button.setAttribute('aria-label', getManagerText('data-dashboard-favorite-dismiss-text') || 'Dismiss message');
        button.setAttribute('data-dashboard-favorites-overlay-dismiss', '');
        toast.appendChild(button);
        toast.appendChild(document.createTextNode(message));
        container.appendChild(toast);

        window.setTimeout(function () {
            hideOverlayMessage(toast);
        }, 3000);
    }

    function setupOverlayMessages() {
        var messages = document.querySelectorAll('[data-dashboard-favorites-overlay-message]');
        Array.prototype.forEach.call(messages, function (message) {
            window.setTimeout(function () {
                hideOverlayMessage(message);
            }, 3000);
        });
    }

    function getStoredOptionsPanelVisible() {
        try {
            var storedValue = window.localStorage.getItem(OPTIONS_PANEL_STORAGE_KEY);
            if (storedValue === '0') {
                return false;
            }
            if (storedValue === '1') {
                return true;
            }
        } catch (e) {
            // Storage can be unavailable; keep the panel closed in that case.
            return false;
        }

        return false;
    }

    function storeOptionsPanelVisible(isVisible) {
        try {
            window.localStorage.setItem(OPTIONS_PANEL_STORAGE_KEY, isVisible ? '1' : '0');
        } catch (e) {
            // Storage is only for UI preference, so failing silently is acceptable.
        }
    }

    function setOptionsPanelVisible(toggle, panel, isVisible) {
        toggle.checked = isVisible;
        toggle.setAttribute('aria-expanded', isVisible ? 'true' : 'false');
        document.documentElement.classList.toggle('dashboard-favorites-manager-options-panel-hidden', !isVisible);

        if (panel) {
            panel.hidden = !isVisible;
        }
    }

    function setupOptionsPanelToggle() {
        var toggle = document.querySelector('[data-dashboard-favorites-options-panel-toggle]');
        var panel = document.querySelector('[data-dashboard-favorites-options-panel]');

        if (!toggle || !panel) {
            return;
        }

        setOptionsPanelVisible(toggle, panel, getStoredOptionsPanelVisible());
        document.documentElement.classList.remove('dashboard-favorites-manager-options-panel-initializing');

        toggle.addEventListener('change', function () {
            setOptionsPanelVisible(toggle, panel, toggle.checked);
            storeOptionsPanelVisible(toggle.checked);
        });
    }

    function getFavoriteRows(body) {
        return Array.prototype.slice.call(body.children).filter(function (row) {
            return row.matches && row.matches('tr[data-favorite-key]')
                && !row.classList.contains('ui-sortable-helper')
                && !row.classList.contains('dashboard-favorites-manager-sort-placeholder');
        });
    }

    function getFavoriteKeys(body) {
        return getFavoriteRows(body).map(function (row) {
            return row.getAttribute('data-favorite-key');
        });
    }

    function setFavoritePosition(row, position) {
        var positionCell = row ? row.querySelector('[data-dashboard-favorites-position]') : null;
        if (positionCell) {
            positionCell.textContent = position;
        }
    }

    function updateFavoritePositions(body) {
        var rows = getFavoriteRows(body);
        for (var i = 0; i < rows.length; i++) {
            setFavoritePosition(rows[i], i + 1);
        }
    }

    function updateFavoritePositionsDuringSort(body, ui) {
        var activeRow = ui && ui.item ? ui.item[0] : null;
        var activePosition = null;
        var placeholderRow = ui && ui.placeholder ? ui.placeholder[0] : null;
        var rows = Array.prototype.slice.call(body.children).filter(function (row) {
            return row.matches && row.matches('tr');
        });
        var position = 1;

        for (var i = 0; i < rows.length; i++) {
            if (rows[i].classList.contains('ui-sortable-helper') || rows[i] === activeRow) {
                continue;
            }

            if (rows[i] === placeholderRow || rows[i].classList.contains('dashboard-favorites-manager-sort-placeholder')) {
                activePosition = position;
                setFavoritePosition(rows[i], position);
                position++;
                continue;
            }

            if (rows[i].matches('tr[data-favorite-key]')) {
                setFavoritePosition(rows[i], position);
                position++;
            }
        }

        if (activePosition !== null) {
            setFavoritePosition(activeRow, activePosition);
            if (ui && ui.helper && ui.helper[0]) {
                setFavoritePosition(ui.helper[0], activePosition);
            }
        }
    }

    function updateToolbarFavorites(json) {
        if (json && json.favorites && typeof window.DashboardFavoritesManagerToolbarUpdate === 'function') {
            window.DashboardFavoritesManagerToolbarUpdate(json.favorites);
        }
    }

    function handleToolbarFavoritesChanged(event) {
        var json = event && event.detail ? event.detail : {};
        if (json.pageID) {
            updateDashboardPageFavoriteState(json.pageID, !!json.favorite);
        }
        if (json.favorites) {
            renderFavoritesTable(json.favorites);
        }
    }

    function getFavoritesReorderUrl() {
        var container = getFavoritesTableContainer();

        return container ? (container.getAttribute('data-dashboard-favorites-reorder-url') || '') : '';
    }

    function getFavoritesReorderToken() {
        var container = getFavoritesTableContainer();

        return container ? (container.getAttribute('data-dashboard-favorites-reorder-token') || '') : '';
    }

    function destroyFavoritesSortable(body) {
        if (!body || body.getAttribute('data-dashboard-favorites-sort-ready') !== '1') {
            return;
        }

        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.sortable) {
            return;
        }

        try {
            window.jQuery(body).sortable('destroy');
        } catch (e) {
            // Re-rendering the table should not fail because sortable was already detached.
        }
    }

    function buildFavoriteTableActionsHtml() {
        return ''
            + '<div class="dashboard-favorites-manager-table-actions">'
            + '<label class="dashboard-favorites-manager-mobile-select-all">'
            + '<input type="checkbox" data-dashboard-favorites-select-all-mobile>'
            + '<span>' + escapeHtml(getManagerText('data-dashboard-favorites-select-all-text')) + '</span>'
            + '</label>'
            + '<button type="button" class="btn btn-danger btn-sm" data-dashboard-favorites-remove disabled aria-disabled="true">'
            + escapeHtml(getManagerText('data-dashboard-favorites-remove-selected-text'))
            + '</button>'
            + '<span class="dashboard-favorites-manager-remove-confirm" data-dashboard-favorites-remove-confirm'
            + ' data-dashboard-favorites-remove-confirm-one="' + escapeHtml(getManagerText('data-dashboard-favorites-confirm-remove-one')) + '"'
            + ' data-dashboard-favorites-remove-confirm-many="' + escapeHtml(getManagerText('data-dashboard-favorites-confirm-remove-many')) + '" hidden>'
            + '<span data-dashboard-favorites-remove-confirm-text>' + escapeHtml(getManagerText('data-dashboard-favorites-confirm-remove-text')) + '</span>'
            + '<button type="submit" class="btn btn-danger btn-sm" form="dashboard-favorites-manager-form" data-dashboard-favorites-remove-confirm-yes>'
            + escapeHtml(getManagerText('data-dashboard-favorites-yes-text'))
            + '</button>'
            + '<button type="button" class="btn btn-secondary btn-sm dashboard-favorites-manager-remove-cancel" data-dashboard-favorites-remove-confirm-no>'
            + escapeHtml(getManagerText('data-dashboard-favorites-no-text'))
            + '</button>'
            + '</span>'
            + '</div>';
    }

    function buildFavoriteRowHtml(favorite, position) {
        var selectionKey = favorite && favorite.selectionKey ? String(favorite.selectionKey) : '';
        var name = favorite && favorite.name ? String(favorite.name) : '';
        var path = favorite && favorite.path ? String(favorite.path) : '';
        var url = favorite && favorite.url ? String(favorite.url) : '';
        var displayPath = path || url;
        var moveUpText = getManagerText('data-dashboard-favorites-move-up-text');
        var moveDownText = getManagerText('data-dashboard-favorites-move-down-text');
        var pathCell = url
            ? '<a href="' + escapeHtml(url) + '" class="dashboard-favorites-manager-path-link">' + escapeHtml(displayPath) + '</a>'
            : escapeHtml(displayPath);

        return ''
            + '<tr data-favorite-key="' + escapeHtml(selectionKey) + '">'
            + '<td>'
            + '<input type="checkbox" name="selected_favorites[]" value="' + escapeHtml(selectionKey) + '" class="dashboard-favorites-manager-checkbox" form="dashboard-favorites-manager-form">'
            + '</td>'
            + '<td class="dashboard-favorites-manager-position-cell" data-dashboard-favorites-position>' + position + '</td>'
            + '<td class="dashboard-favorites-manager-sort-cell">'
            + '<i class="fas fa-arrows-alt-v dashboard-favorites-manager-sort-handle" aria-hidden="true"></i>'
            + '<span class="dashboard-favorites-manager-move-buttons">'
            + '<button type="button" class="dashboard-favorites-manager-move-button" data-dashboard-favorites-move="up" title="' + escapeHtml(moveUpText) + '" aria-label="' + escapeHtml(moveUpText) + '">'
            + '<i class="fas fa-chevron-up" aria-hidden="true"></i>'
            + '</button>'
            + '<button type="button" class="dashboard-favorites-manager-move-button" data-dashboard-favorites-move="down" title="' + escapeHtml(moveDownText) + '" aria-label="' + escapeHtml(moveDownText) + '">'
            + '<i class="fas fa-chevron-down" aria-hidden="true"></i>'
            + '</button>'
            + '</span>'
            + '</td>'
            + '<td class="dashboard-favorites-manager-name-cell">' + escapeHtml(name) + '</td>'
            + '<td class="dashboard-favorites-manager-path-cell">' + pathCell + '</td>'
            + '</tr>';
    }

    function buildFavoritesTableHtml(favorites) {
        var rows = '';
        for (var i = 0; i < favorites.length; i++) {
            rows += buildFavoriteRowHtml(favorites[i], i + 1);
        }

        return buildFavoriteTableActionsHtml()
            + '<table class="table table-sm table-striped table-hover dashboard-favorites-manager-table">'
            + '<colgroup>'
            + '<col class="dashboard-favorites-manager-select-column">'
            + '<col class="dashboard-favorites-manager-position-column">'
            + '<col class="dashboard-favorites-manager-sort-column">'
            + '<col class="dashboard-favorites-manager-name-column">'
            + '<col class="dashboard-favorites-manager-path-column">'
            + '</colgroup>'
            + '<thead>'
            + '<tr>'
            + '<th><input type="checkbox" id="dashboard-favorites-manager-select-all"></th>'
            + '<th class="dashboard-favorites-manager-position-cell">' + escapeHtml(getManagerText('data-dashboard-favorites-position-heading')) + '</th>'
            + '<th></th>'
            + '<th class="dashboard-favorites-manager-name-cell">' + escapeHtml(getManagerText('data-dashboard-favorites-name-heading')) + '</th>'
            + '<th>' + escapeHtml(getManagerText('data-dashboard-favorites-path-heading')) + '</th>'
            + '</tr>'
            + '</thead>'
            + '<tbody data-dashboard-favorites-sort-url="' + escapeHtml(getFavoritesReorderUrl()) + '" data-dashboard-favorites-sort-token="' + escapeHtml(getFavoritesReorderToken()) + '">'
            + rows
            + '</tbody>'
            + '</table>';
    }

    function renderFavoritesTable(favorites) {
        var container = getFavoritesTableContainer();
        if (!container) {
            return;
        }

        favorites = Array.isArray(favorites) ? favorites : [];
        destroyFavoritesSortable(container.querySelector('tbody[data-dashboard-favorites-sort-url]'));

        var existing = container.querySelectorAll('.dashboard-favorites-manager-empty-favorites, .dashboard-favorites-manager-table-actions, .dashboard-favorites-manager-table');
        Array.prototype.forEach.call(existing, function (element) {
            if (element.parentNode) {
                element.parentNode.removeChild(element);
            }
        });

        if (!favorites.length) {
            container.insertAdjacentHTML(
                'beforeend',
                '<div class="alert alert-info dashboard-favorites-manager-empty-favorites">'
                    + escapeHtml(getManagerText('data-dashboard-favorites-empty-text'))
                    + '</div>'
            );
        } else {
            container.insertAdjacentHTML('beforeend', buildFavoritesTableHtml(favorites));
        }

        updateRemoveState();
        setupFavoritesSortableWhenReady(0);
        syncFavoritesMoveMode();
        updateMoveButtonState();
    }

    function setDashboardPageResultFavoriteState(item, isFavorite) {
        var form = item.querySelector('.dashboard-favorites-manager-toggle-form');
        var toggleValue = form ? form.querySelector('[data-dashboard-page-toggle-value]') : null;
        var button = form ? form.querySelector('[data-dashboard-page-toggle]') : null;
        var icon = button ? button.querySelector('i') : null;
        var hiddenText = button ? button.querySelector('.visually-hidden') : null;
        var label = getManagerText(isFavorite ? 'data-dashboard-favorite-remove-text' : 'data-dashboard-favorite-add-text');

        item.classList.toggle('is-favorite', isFavorite);
        if (toggleValue) {
            toggleValue.value = isFavorite ? '0' : '1';
        }
        if (button) {
            button.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
            button.setAttribute('title', label);
        }
        if (icon) {
            icon.classList.toggle('fas', isFavorite);
            icon.classList.toggle('far', !isFavorite);
        }
        if (hiddenText) {
            hiddenText.textContent = label;
        }
    }

    function updateDashboardPageFavoriteState(pageID, isFavorite) {
        pageID = parseInt(pageID, 10);
        if (!pageID) {
            return;
        }

        var items = document.querySelectorAll('[data-dashboard-page-id="' + pageID + '"]');
        Array.prototype.forEach.call(items, function (item) {
            setDashboardPageResultFavoriteState(item, isFavorite);
        });
    }

    function setDashboardPageToggleBusy(form, isBusy) {
        var button = form ? form.querySelector('[data-dashboard-page-toggle]') : null;
        if (button) {
            button.disabled = isBusy;
            button.setAttribute('aria-disabled', isBusy ? 'true' : 'false');
        }
        if (form) {
            form.classList.toggle('is-saving', isBusy);
        }
    }

    function submitDashboardPageToggle(form) {
        var data = new FormData(form);
        var button = form.querySelector('[data-dashboard-page-toggle]');
        setDashboardPageToggleBusy(form, true);

        window.jQuery.ajax({
            contentType: false,
            data: data,
            dataType: 'json',
            processData: false,
            type: 'POST',
            url: form.getAttribute('action'),
            success: function (json) {
                if (!json || !json.success) {
                    showOverlayMessage('warning', json && json.message ? json.message : getManagerText('data-dashboard-favorite-toggle-error'));
                    return;
                }

                updateToolbarFavorites(json);
                updateDashboardPageFavoriteState(json.pageID, !!json.favorite);
                renderFavoritesTable(json.favorites || []);
                showOverlayMessage('success', json.message);
            },
            error: function (xhr) {
                var json = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                if (json && json.pageID) {
                    updateDashboardPageFavoriteState(json.pageID, !!json.favorite);
                }
                if (json && json.favorites) {
                    updateToolbarFavorites(json);
                    renderFavoritesTable(json.favorites);
                }
                showOverlayMessage('error', getAjaxErrorMessage(xhr, getManagerText('data-dashboard-favorite-toggle-error')));
            },
            complete: function () {
                setDashboardPageToggleBusy(form, false);
                if (button) {
                    button.focus();
                }
            }
        });
    }

    function postFavoriteRequest(body, data, onSuccess, onError) {
        if (!window.jQuery) {
            showConcreteError(getManagerText('data-dashboard-favorites-order-error'));
            if (typeof onError === 'function') {
                onError();
            }
            return;
        }

        data.append('ccm_token', body.getAttribute('data-dashboard-favorites-sort-token'));

        window.jQuery.ajax({
            contentType: false,
            data: data,
            dataType: 'json',
            processData: false,
            type: 'POST',
            url: body.getAttribute('data-dashboard-favorites-sort-url'),
            success: function (json) {
                updateToolbarFavorites(json);
                if (typeof onSuccess === 'function') {
                    onSuccess(json);
                }
            },
            error: function (xhr) {
                showConcreteError(getAjaxErrorMessage(xhr, getManagerText('data-dashboard-favorites-order-error')));
                if (typeof onError === 'function') {
                    onError(xhr);
                }
            }
        });
    }

    function postFavoriteOrder(body, favoriteKeys, onSuccess, onError) {
        var data = new FormData();
        for (var i = 0; i < favoriteKeys.length; i++) {
            data.append('favorite_keys[]', favoriteKeys[i]);
        }

        postFavoriteRequest(body, data, onSuccess, onError);
    }

    function postFavoriteMove(body, favoriteKey, direction, onSuccess, onError) {
        var data = new FormData();
        data.append('favorite_key', favoriteKey);
        data.append('direction', direction);

        postFavoriteRequest(body, data, onSuccess, onError);
    }

    function showMovedRow(row) {
        row.classList.add('dashboard-favorites-manager-row-moved');
        window.setTimeout(function () {
            row.classList.remove('dashboard-favorites-manager-row-moved');
        }, 260);
    }

    function moveFavoriteRow(button) {
        var body = document.querySelector('[data-dashboard-favorites-sort-url]');
        var row = button.closest('tr[data-favorite-key]');
        var direction = button.getAttribute('data-dashboard-favorites-move');
        if (!body || !row || (direction !== 'up' && direction !== 'down')) {
            return;
        }

        var oldRows = getFavoriteRows(body);
        var sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling || !sibling.matches('tr[data-favorite-key]')) {
            return;
        }

        if (direction === 'up') {
            body.insertBefore(row, sibling);
        } else {
            body.insertBefore(sibling, row);
        }

        updateFavoritePositions(body);
        button.disabled = true;
        postFavoriteMove(body, row.getAttribute('data-favorite-key'), direction, function () {
            showMovedRow(row);
            button.disabled = false;
            updateMoveButtonState();
            row.querySelector('[data-dashboard-favorites-move="' + direction + '"]').focus();
        }, function () {
            for (var i = 0; i < oldRows.length; i++) {
                body.appendChild(oldRows[i]);
            }
            updateFavoritePositions(body);
            button.disabled = false;
            updateMoveButtonState();
        });
    }

    function updateMoveButtonState() {
        var body = document.querySelector('[data-dashboard-favorites-sort-url]');
        if (!body) {
            return;
        }

        var rows = getFavoriteRows(body);
        for (var i = 0; i < rows.length; i++) {
            var up = rows[i].querySelector('[data-dashboard-favorites-move="up"]');
            var down = rows[i].querySelector('[data-dashboard-favorites-move="down"]');
            if (up) {
                up.disabled = i === 0;
            }
            if (down) {
                down.disabled = i === rows.length - 1;
            }
        }
    }

    function isCompactFavoritesLayout() {
        return window.matchMedia && window.matchMedia('(max-width: 1199.98px)').matches;
    }

    function syncFavoritesMoveMode() {
        var body = document.querySelector('[data-dashboard-favorites-sort-url]');
        if (!body || !window.jQuery || !window.jQuery.fn || !window.jQuery.fn.sortable) {
            updateMoveButtonState();
            return;
        }

        var $body = window.jQuery(body);
        if (isCompactFavoritesLayout()) {
            if (body.getAttribute('data-dashboard-favorites-sort-ready') === '1') {
                $body.sortable('destroy');
                body.removeAttribute('data-dashboard-favorites-sort-ready');
            }

            updateMoveButtonState();
            return;
        }

        if (body.getAttribute('data-dashboard-favorites-sort-ready') !== '1') {
            setupFavoritesSortable();
        }

        updateMoveButtonState();
    }

    function setupFavoritesMoveModeSync() {
        var resizeTimer;
        window.addEventListener('resize', function () {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(syncFavoritesMoveMode, 120);
        });
    }

    function setupFavoritesSortable() {
        var body = document.querySelector('[data-dashboard-favorites-sort-url]');
        if (!body) {
            return false;
        }

        if (isCompactFavoritesLayout()) {
            return true;
        }

        if (body.getAttribute('data-dashboard-favorites-sort-ready') === '1') {
            return true;
        }

        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.sortable) {
            return false;
        }

        body.setAttribute('data-dashboard-favorites-sort-ready', '1');
        var $body = window.jQuery(body);
        $body.sortable({
            axis: 'y',
            cursor: 'move',
            forceHelperSize: true,
            forcePlaceholderSize: true,
            handle: '.dashboard-favorites-manager-sort-handle',
            helper: function (event, row) {
                var $originals = row.children();
                var $helper = row.clone();
                $helper.children().each(function (index) {
                    window.jQuery(this).width($originals.eq(index).width());
                });

                return $helper;
            },
            items: 'tr',
            placeholder: 'dashboard-favorites-manager-sort-placeholder',
            tolerance: 'pointer',
            start: function (event, ui) {
                updateFavoritePositionsDuringSort(body, ui);
            },
            sort: function (event, ui) {
                updateFavoritePositionsDuringSort(body, ui);
            },
            change: function (event, ui) {
                updateFavoritePositionsDuringSort(body, ui);
            },
            stop: function (event, ui) {
                $body.sortable('disable');
                window.jQuery(ui.item).css({left: '', top: '', position: ''});
                updateFavoritePositions(body);

                postFavoriteOrder(body, getFavoriteKeys(body), function () {
                    showMovedRow(ui.item[0]);
                    $body.sortable('enable');
                }, function () {
                    $body.sortable('cancel');
                    updateFavoritePositions(body);
                    $body.sortable('enable');
                });
            }
        });

        return true;
    }

    function setupFavoritesSortableWhenReady(attempt) {
        if (setupFavoritesSortable() || attempt >= 20) {
            return;
        }

        window.setTimeout(function () {
            setupFavoritesSortableWhenReady(attempt + 1);
        }, 100);
    }

    function updateRemoveState() {
        var button = document.querySelector('[data-dashboard-favorites-remove]');
        var confirm = document.querySelector('[data-dashboard-favorites-remove-confirm]');
        if (!button) {
            return;
        }

        var favoriteCheckboxes = document.querySelectorAll('.dashboard-favorites-manager-checkbox');
        var selectedFavorites = document.querySelectorAll('.dashboard-favorites-manager-checkbox:checked');
        button.disabled = selectedFavorites.length === 0;
        button.setAttribute('aria-disabled', selectedFavorites.length === 0 ? 'true' : 'false');
        updateSelectAllControls(favoriteCheckboxes.length, selectedFavorites.length);
        if (selectedFavorites.length === 0 && confirm) {
            confirm.hidden = true;
        } else if (confirm && !confirm.hidden) {
            updateRemoveConfirmText();
        }
    }

    function updateSelectAllControls(totalCount, selectedCount) {
        var selectAllControls = document.querySelectorAll('#dashboard-favorites-manager-select-all, [data-dashboard-favorites-select-all-mobile]');
        Array.prototype.forEach.call(selectAllControls, function (control) {
            control.checked = totalCount > 0 && selectedCount === totalCount;
            control.indeterminate = selectedCount > 0 && selectedCount < totalCount;
        });
    }

    function updateRemoveConfirmText() {
        var confirm = document.querySelector('[data-dashboard-favorites-remove-confirm]');
        var text = document.querySelector('[data-dashboard-favorites-remove-confirm-text]');
        if (!confirm || !text) {
            return;
        }

        var selectedCount = document.querySelectorAll('.dashboard-favorites-manager-checkbox:checked').length;
        var template = selectedCount === 1
            ? confirm.getAttribute('data-dashboard-favorites-remove-confirm-one')
            : confirm.getAttribute('data-dashboard-favorites-remove-confirm-many');

        text.textContent = (template || '').replace('%s', selectedCount);
    }

    function normalizeSearchText(value) {
        return (value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function compareDashboardPageSearchItems(a, b, mode) {
        var firstAttribute = mode === 'path' ? 'data-dashboard-page-search-path' : 'data-dashboard-page-search-name';
        var secondAttribute = mode === 'path' ? 'data-dashboard-page-search-name' : 'data-dashboard-page-search-path';
        var firstComparison = (a.getAttribute(firstAttribute) || '').localeCompare(b.getAttribute(firstAttribute) || '', undefined, { numeric: true });
        if (firstComparison !== 0) {
            return firstComparison;
        }

        return (a.getAttribute(secondAttribute) || '').localeCompare(b.getAttribute(secondAttribute) || '', undefined, { numeric: true });
    }

    function sortDashboardPageSearchItems(items, mode) {
        if (!items.length || !items[0].parentNode) {
            return;
        }

        var parent = items[0].parentNode;
        items.sort(function (a, b) {
            return compareDashboardPageSearchItems(a, b, mode);
        });

        for (var i = 0; i < items.length; i++) {
            parent.appendChild(items[i]);
        }
    }

    function updateDashboardPageSearch() {
        var input = document.getElementById('dashboard-favorites-manager-page-search');
        var empty = document.querySelector('[data-dashboard-page-search-empty]');
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-dashboard-page-search-name]'));
        var clearButton = document.querySelector('[data-dashboard-page-search-clear]');
        if (!input || !empty || !items.length) {
            return;
        }

        var query = normalizeSearchText(input.value);
        var modeInput = document.querySelector('[data-dashboard-page-search-mode]:checked');
        var mode = modeInput && modeInput.value === 'path' ? 'path' : 'name';
        var shown = 0;

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var text = item.getAttribute('data-dashboard-page-search-' + mode) || '';
            var matches = query.length > 0 && text.indexOf(query) !== -1;
            item.classList.toggle('is-visible', matches);
            if (matches) {
                shown++;
            }
        }

        sortDashboardPageSearchItems(items, mode);

        if (query.length === 0) {
            empty.textContent = '';
        } else if (shown === 0) {
            empty.textContent = getManagerText('data-dashboard-page-search-empty-text');
        } else {
            empty.textContent = '';
        }

        empty.style.display = empty.textContent ? '' : 'none';
        if (clearButton) {
            clearButton.hidden = input.value.length === 0;
        }
    }

    function getCurrentImportFileInput() {
        var inputs = document.querySelectorAll('[data-dashboard-favorites-import-file]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].getAttribute('data-dashboard-favorites-import-pending') !== '1') {
                return inputs[i];
            }
        }

        return inputs.length ? inputs[0] : null;
    }

    function clearPendingImportFileInputs() {
        var inputs = document.querySelectorAll('[data-dashboard-favorites-import-pending="1"]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].parentNode) {
                inputs[i].parentNode.removeChild(inputs[i]);
            }
        }
    }

    function getImportFileInputForPicker() {
        var fileInput = getCurrentImportFileInput();
        if (!fileInput) {
            return null;
        }

        clearPendingImportFileInputs();
        if (!fileInput.files || !fileInput.files.length) {
            return fileInput;
        }

        var replacementInput = fileInput.cloneNode(false);
        replacementInput.value = '';
        replacementInput.removeAttribute('name');
        replacementInput.removeAttribute('required');
        replacementInput.setAttribute('data-dashboard-favorites-import-pending', '1');
        fileInput.parentNode.insertBefore(replacementInput, fileInput.nextSibling);

        return replacementInput;
    }

    function keepImportFileInput(eventTarget) {
        var previousInput = getCurrentImportFileInput();
        if (!previousInput || previousInput === eventTarget || !previousInput.parentNode) {
            eventTarget.removeAttribute('data-dashboard-favorites-import-pending');
            return;
        }

        eventTarget.setAttribute('name', previousInput.getAttribute('name') || 'favorites_file');
        eventTarget.setAttribute('required', 'required');
        eventTarget.removeAttribute('data-dashboard-favorites-import-pending');
        previousInput.parentNode.replaceChild(eventTarget, previousInput);
    }

    function openImportControls() {
        var openButton = document.querySelector('[data-dashboard-favorites-import-open]');
        var controls = document.querySelector('[data-dashboard-favorites-import-controls]');
        var fileInput = getCurrentImportFileInput();
        if (!openButton || !controls) {
            return;
        }

        openButton.setAttribute('data-dashboard-favorites-import-opened', '1');
        openButton.disabled = true;
        openButton.setAttribute('aria-disabled', 'true');
        controls.hidden = false;

        if (fileInput) {
            fileInput.focus();
        }
    }

    function closeImportControls() {
        var openButton = document.querySelector('[data-dashboard-favorites-import-open]');
        var controls = document.querySelector('[data-dashboard-favorites-import-controls]');
        var fileInput = getCurrentImportFileInput();
        var fileName = document.querySelector('[data-dashboard-favorites-file-name]');
        var uploadButton = document.querySelector('[data-dashboard-favorites-upload]');
        if (!openButton || !controls) {
            return;
        }

        clearPendingImportFileInputs();
        if (fileInput) {
            fileInput.value = '';
        }

        if (fileName) {
            fileName.textContent = fileName.getAttribute('data-dashboard-favorites-no-file-text') || '';
        }

        if (uploadButton) {
            uploadButton.hidden = true;
        }

        controls.hidden = true;
        openButton.removeAttribute('data-dashboard-favorites-import-opened');
        openButton.disabled = false;
        openButton.setAttribute('aria-disabled', 'false');
        openButton.focus();
    }

    function clearImportReport() {
        var report = document.querySelector('[data-dashboard-favorites-import-report]');
        if (!report) {
            return;
        }

        report.hidden = true;
        var importButton = document.querySelector('[data-dashboard-favorites-import-open]');
        if (importButton) {
            importButton.focus();
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-dashboard-favorites-import-file]')) {
            var fileName = document.querySelector('[data-dashboard-favorites-file-name]');
            var uploadButton = document.querySelector('[data-dashboard-favorites-upload]');
            var maxSize = parseInt(event.target.getAttribute('data-dashboard-favorites-import-max-size') || '0', 10);
            var isReplacementInput = event.target.getAttribute('data-dashboard-favorites-import-pending') === '1';
            var file = event.target.files && event.target.files.length ? event.target.files[0] : null;
            if (isReplacementInput && !file) {
                event.target.parentNode.removeChild(event.target);
                return;
            }

            if (file && maxSize > 0 && file.size > maxSize) {
                event.target.value = '';
                showConcreteError(event.target.getAttribute('data-dashboard-favorites-import-size-error') || getManagerText('data-dashboard-favorites-file-large-error'));
                if (isReplacementInput) {
                    event.target.parentNode.removeChild(event.target);
                    return;
                }

                file = null;
            }

            if (isReplacementInput) {
                keepImportFileInput(event.target);
            }

            if (fileName) {
                fileName.textContent = file ? file.name : (fileName.getAttribute('data-dashboard-favorites-no-file-text') || '');
            }

            if (uploadButton) {
                uploadButton.hidden = !file;
            }
            return;
        }

        if (event.target.id === 'dashboard-favorites-manager-select-all' || event.target.matches('[data-dashboard-favorites-select-all-mobile]')) {
            var favoriteCheckboxes = document.querySelectorAll('.dashboard-favorites-manager-checkbox');
            for (var i = 0; i < favoriteCheckboxes.length; i++) {
                favoriteCheckboxes[i].checked = event.target.checked;
            }

            updateRemoveState();
            return;
        }

        if (event.target.classList.contains('dashboard-favorites-manager-checkbox')) {
            updateRemoveState();
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target.id === 'dashboard-favorites-manager-page-search') {
            updateDashboardPageSearch();
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-dashboard-page-search-mode]')) {
            updateDashboardPageSearch();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target && event.target.matches('.dashboard-favorites-manager-toggle-form') ? event.target : null;
        if (!form) {
            return;
        }

        if (!window.jQuery || !window.FormData) {
            return;
        }

        event.preventDefault();
        submitDashboardPageToggle(form);
    });

    function isCoreDashboardFavoriteControl(target) {
        if (target.closest('.dashboard-favorites-manager')) {
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
        if (event.target.closest('[data-dashboard-favorites-import-open]')) {
            event.preventDefault();
            openImportControls();
            return;
        }

        if (event.target.closest('[data-dashboard-favorites-import-cancel]')) {
            event.preventDefault();
            closeImportControls();
            return;
        }

        if (event.target.closest('[data-dashboard-favorites-file-button]')) {
            event.preventDefault();
            var fileInput = getImportFileInputForPicker();
            if (fileInput) {
                fileInput.click();
            }
            return;
        }

        if (event.target.closest('[data-dashboard-favorites-import-report-clear]')) {
            event.preventDefault();
            clearImportReport();
            return;
        }

        if (event.target.closest('[data-dashboard-page-search-clear]')) {
            event.preventDefault();
            var searchInput = document.getElementById('dashboard-favorites-manager-page-search');
            if (searchInput) {
                searchInput.value = '';
                updateDashboardPageSearch();
                searchInput.focus();
            }
            return;
        }

        if (event.target.closest('[data-dashboard-favorites-remove]')) {
            event.preventDefault();
            var removeConfirm = document.querySelector('[data-dashboard-favorites-remove-confirm]');
            if (removeConfirm) {
                updateRemoveConfirmText();
                removeConfirm.hidden = false;
                var yesButton = removeConfirm.querySelector('[data-dashboard-favorites-remove-confirm-yes]');
                if (yesButton) {
                    yesButton.focus();
                }
            }
            return;
        }

        if (event.target.closest('[data-dashboard-favorites-remove-confirm-no]')) {
            event.preventDefault();
            var confirm = document.querySelector('[data-dashboard-favorites-remove-confirm]');
            if (confirm) {
                confirm.hidden = true;
            }
            var removeButton = document.querySelector('[data-dashboard-favorites-remove]');
            if (removeButton) {
                removeButton.focus();
            }
            return;
        }

        var overlayDismiss = event.target.closest('[data-dashboard-favorites-overlay-dismiss]');
        if (overlayDismiss) {
            event.preventDefault();
            hideOverlayMessage(overlayDismiss.closest('[data-dashboard-favorites-overlay-message]'));
            return;
        }

        var moveButton = event.target.closest('[data-dashboard-favorites-move]');
        if (moveButton) {
            event.preventDefault();
            syncFavoritesMoveMode();
            moveFavoriteRow(moveButton);
            return;
        }

        if (isCoreDashboardFavoriteControl(event.target)) {
            reloadAfterCoreFavoriteAjax();
        }
    }, true);

    function initDashboardFavoritesManager() {
        setupOverlayMessages();
        setupOptionsPanelToggle();
        setupFavoritesSortableWhenReady(0);
        updateDashboardPageSearch();
        updateRemoveState();
        updateMoveButtonState();
        setupFavoritesMoveModeSync();
        syncFavoritesMoveMode();
        window.addEventListener('dashboardFavoritesManager:favoritesChanged', handleToolbarFavoritesChanged);

        var pageSearch = document.getElementById('dashboard-favorites-manager-page-search');
        var importReport = document.querySelector('[data-dashboard-favorites-import-report]');
        var shouldFocusPageSearch = !importReport || !window.matchMedia || window.matchMedia('(max-width: 767px)').matches;
        if (pageSearch && shouldFocusPageSearch) {
            pageSearch.focus();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboardFavoritesManager);
    } else {
        initDashboardFavoritesManager();
    }
}());
