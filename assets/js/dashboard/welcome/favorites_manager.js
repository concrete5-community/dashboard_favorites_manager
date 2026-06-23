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
            // Storage can be unavailable; keep the panel visible in that case.
            return true;
        }

        return true;
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

        var selectedFavorites = document.querySelectorAll('.dashboard-favorites-manager-checkbox:checked');
        button.disabled = selectedFavorites.length === 0;
        button.setAttribute('aria-disabled', selectedFavorites.length === 0 ? 'true' : 'false');
        if (selectedFavorites.length === 0 && confirm) {
            confirm.hidden = true;
        } else if (confirm && !confirm.hidden) {
            updateRemoveConfirmText();
        }
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

    function updateDashboardPageSearch() {
        var input = document.getElementById('dashboard-favorites-manager-page-search');
        var empty = document.querySelector('[data-dashboard-page-search-empty]');
        var items = document.querySelectorAll('[data-dashboard-page-search-text]');
        var clearButton = document.querySelector('[data-dashboard-page-search-clear]');
        if (!input || !empty || !items.length) {
            return;
        }

        var query = normalizeSearchText(input.value);
        var shown = 0;

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var text = item.getAttribute('data-dashboard-page-search-text') || '';
            var matches = query.length > 0 && text.indexOf(query) !== -1;
            item.classList.toggle('is-visible', matches);
            if (matches) {
                shown++;
            }
        }

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

        if (event.target.id === 'dashboard-favorites-manager-select-all') {
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
        setupOptionsPanelToggle();
        setupFavoritesSortableWhenReady(0);
        updateDashboardPageSearch();
        updateRemoveState();
        updateMoveButtonState();
        setupFavoritesMoveModeSync();
        syncFavoritesMoveMode();

        var pageSearch = document.getElementById('dashboard-favorites-manager-page-search');
        if (pageSearch) {
            pageSearch.focus();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboardFavoritesManager);
    } else {
        initDashboardFavoritesManager();
    }
}());
