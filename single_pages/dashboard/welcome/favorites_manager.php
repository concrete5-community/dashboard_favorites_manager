<?php defined('C5_EXECUTE') or die('Access Denied.');

/** @var array $favoriteLinks */
/** @var string $packageVersion */
/** @var bool $toolbarFavoritesEnabled */
/** @var bool $toolbarSearchEnabled */
/** @var bool $toolbarClearCacheEnabled */
/** @var bool $toolbarLogoutEnabled */
/** @var bool $toolbarConcreteVersionEnabled */
/** @var bool $canUseToolbarClearCache */
/** @var int $toolbarSearchMaxResults */
/** @var int $toolbarSearchMaxResultsMin */
/** @var int $toolbarSearchMaxResultsMax */
/** @var int $dashboardPageSearchMinLength */
/** @var string $toolbarSettingsToken */
/** @var string $toggleDashboardPageToken */
/** @var string $removeFavoritesToken */
/** @var string $reorderFavoritesToken */
/** @var string $importExportToken */
/** @var array|null $importReport */
/** @var array|null $pendingPackageUpdate */
/** @var array $overlayMessages */

$initialFavoriteLinksJson = json_encode($favoriteLinks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($initialFavoriteLinksJson === false) {
    $initialFavoriteLinksJson = '[]';
}
?>

<script>
(function () {
    function hideOptionsPanelEarly() {
        document.documentElement.classList.add('dashboard-favorites-manager-options-panel-hidden', 'dashboard-favorites-manager-options-panel-initializing');
        document.write('<style id="dashboard-favorites-manager-options-panel-early-style">html.dashboard-favorites-manager-options-panel-hidden [data-dashboard-favorites-options-panel]{display:none!important}html.dashboard-favorites-manager-options-panel-initializing [data-dashboard-favorites-options-panel-control]{visibility:hidden!important}</style>');
        document.addEventListener('DOMContentLoaded', function () {
            var toggle = document.querySelector('[data-dashboard-favorites-options-panel-toggle]');
            if (toggle) {
                toggle.checked = false;
                toggle.setAttribute('aria-expanded', 'false');
            }
        }, {once: true});
    }

    try {
        if (window.localStorage.getItem('dashboardFavoritesManager.optionsPanelVisible') !== '1') {
            hideOptionsPanelEarly();
        }
    } catch (e) {
        // Storage can be unavailable; keep the default closed state.
        hideOptionsPanelEarly();
    }
}());
</script>

<div class="dashboard-favorites-manager"
    data-dashboard-favorites-order-error="<?= h(t('Unable to save favorite order.')) ?>"
    data-dashboard-page-search-url="<?= h($view->action('search_dashboard_pages')) ?>"
    data-dashboard-page-toggle-url="<?= h($view->action('toggle_dashboard_page')) ?>"
    data-dashboard-page-toggle-token="<?= h($toggleDashboardPageToken) ?>"
    data-dashboard-page-search-empty-text="<?= h(t('No dashboard pages found.')) ?>"
    data-dashboard-page-search-loading-text="<?= h(t('Loading dashboard pages.')) ?>"
    data-dashboard-page-search-error-text="<?= h(t('Unable to load dashboard pages.')) ?>"
    data-dashboard-page-search-min-length="<?= (int) $dashboardPageSearchMinLength ?>"
    data-dashboard-page-search-result-count-text="<?= h(t('results: %s')) ?>"
    data-dashboard-page-search-result-count-limited-text="<?= h(t('results: %1$s/%2$s')) ?>"
    data-dashboard-favorites-file-large-error="<?= h(t('The selected file is too large.')) ?>"
    data-dashboard-favorite-toggle-error="<?= h(t('Unable to update dashboard favorite.')) ?>"
    data-dashboard-favorite-add-text="<?= h(t('Add to favorites')) ?>"
    data-dashboard-favorite-remove-text="<?= h(t('Remove from favorites')) ?>"
    data-dashboard-page-open-text="<?= h(t('Open page')) ?>"
    data-dashboard-favorite-dismiss-text="<?= h(t('Dismiss message')) ?>"
    data-dashboard-favorites-empty-text="<?= h(t('The favorites list is empty.')) ?>"
    data-dashboard-favorites-remove-error="<?= h(t('Unable to remove dashboard favorites.')) ?>"
    data-dashboard-favorites-select-all-text="<?= h(t('Select all')) ?>"
    data-dashboard-favorites-remove-selected-text="<?= h(t('Remove selected')) ?>"
    data-dashboard-favorites-confirm-remove-text="<?= h(t('Confirm remove?')) ?>"
    data-dashboard-favorites-confirm-remove-one="<?= h(t('Confirm remove %s favorite?')) ?>"
    data-dashboard-favorites-confirm-remove-many="<?= h(t('Confirm remove %s favorites?')) ?>"
    data-dashboard-favorites-yes-text="<?= h(t('Yes')) ?>"
    data-dashboard-favorites-no-text="<?= h(t('No')) ?>"
    data-dashboard-favorites-position-heading="<?= h(t('#')) ?>"
    data-dashboard-favorites-name-heading="<?= h(t('Name')) ?>"
    data-dashboard-favorites-path-heading="<?= h(t('Path')) ?>"
    data-dashboard-favorites-move-up-text="<?= h(t('Move up')) ?>"
    data-dashboard-favorites-move-down-text="<?= h(t('Move down')) ?>"
>
    <?php if (!empty($overlayMessages) && is_array($overlayMessages)) { ?>
        <div class="dashboard-favorites-overlay-messages" data-dashboard-favorites-overlay-messages aria-live="polite" aria-atomic="false">
            <?php foreach ($overlayMessages as $overlayMessage) {
                $messageType = (string) ($overlayMessage['type'] ?? 'info');
                $messageText = (string) ($overlayMessage['message'] ?? '');
                if ($messageText === '') {
                    continue;
                }
                $messageClass = [
                    'success' => 'success',
                    'warning' => 'warning',
                    'error' => 'danger',
                    'info' => 'info',
                ][$messageType] ?? 'info';
                ?>
                <div class="dashboard-favorites-overlay-toast alert alert-<?= h($messageClass) ?> alert-dismissible" role="<?= $messageClass === 'danger' ? 'alert' : 'status' ?>" data-dashboard-favorites-overlay-message>
                    <button type="button" class="btn-close" aria-label="<?= h(t('Dismiss message')) ?>" data-dashboard-favorites-overlay-dismiss></button>
                    <?= nl2br(h($messageText)) ?>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="text-muted small dashboard-favorites-manager-version">
        <?php if (empty($pendingPackageUpdate)) { ?>
            <?= t('Version') ?> <strong><?= h($packageVersion) ?></strong> -
        <?php } ?>
        <?= t('Author:') ?> <strong><?= h('DigitMaster') ?></strong> -
        <?= h(t('Dedicated to mlocati, my Concrete CMS mentor')) ?>
    </div>
    <?php if (!empty($pendingPackageUpdate)) { ?>
        <div class="alert alert-warning dashboard-favorites-manager-pending-update">
            <?= t(
                'Warning: execute package update! The uploaded files are v. %2$s, upgrade is pending. Previous version registered is v. %1$s',
                h((string) ($pendingPackageUpdate['installedVersion'] ?? '')),
                h((string) ($pendingPackageUpdate['availableVersion'] ?? ''))
            ) ?>
            <?php if (!empty($pendingPackageUpdate['canInstallPackages']) && !empty($pendingPackageUpdate['updateUrl'])) { ?>
                <a href="<?= h((string) $pendingPackageUpdate['updateUrl']) ?>">
                    <?= t('Complete the package update from Concrete Dashboard.') ?>
                </a>
            <?php } else { ?>
                <?= t('Ask an administrator to complete the package update from Concrete Dashboard.') ?>
            <?php } ?>
        </div>
    <?php } ?>
    <div class="dashboard-favorites-manager-current-user-notice">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <strong><?= t('Info: Favorites and settings on this page affect only the current user.') ?></strong>
    </div>

    <form method="post" action="<?= h($view->action('remove_favorites')) ?>" id="dashboard-favorites-manager-form">
        <input type="hidden" name="ccm_token" value="<?= h($removeFavoritesToken) ?>">
    </form>

    <form method="post" action="<?= h($view->action('save_toolbar_settings')) ?>" id="dashboard-favorites-manager-toolbar-settings" class="dashboard-favorites-manager-toolbar-settings-form">
        <input type="hidden" name="ccm_token" value="<?= h($toolbarSettingsToken) ?>">
        <input type="hidden" name="toolbar_favorites_enabled" value="0">
        <input type="hidden" name="toolbar_search_enabled" value="0">
        <input type="hidden" name="toolbar_clear_cache_enabled" value="0">
        <input type="hidden" name="toolbar_logout_enabled" value="0">
        <input type="hidden" name="toolbar_concrete_version_enabled" value="0">
    </form>

    <div class="form-check form-switch dashboard-favorites-manager-options-panel-control" data-dashboard-favorites-options-panel-control>
        <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-options-panel-toggle" data-dashboard-favorites-options-panel-toggle aria-controls="dashboard-favorites-manager-options-panel" aria-expanded="false">
        <label class="form-check-label" for="dashboard-favorites-manager-options-panel-toggle">
            <strong><?= t('Show options panel') ?></strong>
        </label>
    </div>

    <div class="dashboard-favorites-manager-tools mb-3" id="dashboard-favorites-manager-options-panel" data-dashboard-favorites-options-panel hidden>
        <div class="dashboard-favorites-manager-toolbar-toggle">
            <div class="dashboard-favorites-manager-toolbar-options">
                <div class="dashboard-favorites-manager-options-heading">
                    <?= t('Toolbar options') ?>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-toolbar-enabled" name="toolbar_favorites_enabled" value="1" aria-label="<?= h(t('Show blue star button in toolbar')) ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?= $toolbarFavoritesEnabled ? 'checked' : '' ?>>
                    <span class="form-check-label">
                        <?= t('Show blue star %s button in toolbar', '<span class="dashboard-favorites-manager-label-star">★</span>') ?>
                    </span>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-concrete-version-enabled" name="toolbar_concrete_version_enabled" value="1" aria-label="<?= h(t('Show Concrete CMS version in toolbar')) ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?= $toolbarConcreteVersionEnabled ? 'checked' : '' ?>>
                    <span class="form-check-label">
                        <?= t('Show Concrete CMS version in toolbar') ?>
                    </span>
                </div>
            </div>
            <div class="dashboard-favorites-manager-import-export-actions">
                <form method="post" action="<?= h($view->action('export_favorites')) ?>" class="dashboard-favorites-manager-import-export-row">
                    <input type="hidden" name="ccm_token" value="<?= h($importExportToken) ?>">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload" aria-hidden="true"></i>
                        <?= t('Export favorites') ?>
                    </button>
                </form>
                <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-import-button" data-dashboard-favorites-import-open>
                    <i class="fas fa-download" aria-hidden="true"></i>
                    <?= t('Import favorites') ?>
                </button>
            </div>
            <form method="post" action="<?= h($view->action('import_favorites')) ?>" enctype="multipart/form-data" class="dashboard-favorites-manager-import-form dashboard-favorites-manager-import-controls-form">
                <input type="hidden" name="ccm_token" value="<?= h($importExportToken) ?>">
                <div class="dashboard-favorites-manager-import-controls" data-dashboard-favorites-import-controls hidden>
                    <input type="file" name="favorites_file" class="dashboard-favorites-manager-file-input" accept="application/json,.json" required data-dashboard-favorites-import-file data-dashboard-favorites-import-max-size="65536" data-dashboard-favorites-import-size-error="<?= h(t('The selected file is too large. Maximum size is 64 KB.')) ?>">
                    <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-file-button" data-dashboard-favorites-file-button>
                        <?= t('Select file') ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm dashboard-favorites-manager-upload-button" data-dashboard-favorites-upload hidden>
                        <?= t('Upload') ?>
                    </button>
                    <span class="dashboard-favorites-manager-file-name" data-dashboard-favorites-file-name data-dashboard-favorites-no-file-text="<?= h(t('No file selected')) ?>">
                        <?= t('No file selected') ?>
                    </span>
                    <button type="button" class="dashboard-favorites-manager-import-cancel" title="<?= h(t('Cancel import')) ?>" aria-label="<?= h(t('Cancel import')) ?>" data-dashboard-favorites-import-cancel>
                        &times;
                    </button>
                </div>
            </form>
        </div>

        <div class="dashboard-favorites-manager-import-export">
            <div class="dashboard-favorites-manager-menu-options<?= $toolbarFavoritesEnabled ? '' : ' is-disabled' ?>">
                <div class="dashboard-favorites-manager-options-heading">
                    <?= t('Menu options') ?>
                </div>
                <div class="form-check form-switch dashboard-favorites-manager-dependent-switch<?= $toolbarFavoritesEnabled ? '' : ' is-disabled' ?>">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-search-enabled" name="toolbar_search_enabled" value="1" aria-label="<?= h(t('Show dashboard page search')) ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?= $toolbarFavoritesEnabled && $toolbarSearchEnabled ? 'checked' : '' ?> <?= $toolbarFavoritesEnabled ? '' : 'disabled' ?>>
                    <span class="form-check-label">
                        <?= t('Show dashboard page search') ?> <strong aria-hidden="true">*</strong>
                    </span>
                </div>
                <div class="dashboard-favorites-manager-search-limit<?= $toolbarFavoritesEnabled ? '' : ' is-disabled' ?>">
                    <label for="dashboard-favorites-manager-search-max-results"><?= t('Max menu search results (%1$s-%2$s)', (int) $toolbarSearchMaxResultsMin, (int) $toolbarSearchMaxResultsMax) ?></label>
                    <div class="dashboard-favorites-manager-search-limit-control">
                        <input type="text" class="form-control form-control-sm" id="dashboard-favorites-manager-search-max-results" name="toolbar_search_max_results" value="<?= (int) $toolbarSearchMaxResults ?>" inputmode="numeric" pattern="[0-9]*" maxlength="<?= strlen((string) (int) $toolbarSearchMaxResultsMax) ?>" autocomplete="off" form="dashboard-favorites-manager-toolbar-settings" data-dashboard-favorites-search-max-results data-dashboard-favorites-search-max-results-min="<?= (int) $toolbarSearchMaxResultsMin ?>" data-dashboard-favorites-search-max-results-max="<?= (int) $toolbarSearchMaxResultsMax ?>" <?= $toolbarFavoritesEnabled ? '' : 'disabled' ?>>
                        <div class="dashboard-favorites-manager-search-limit-stepper">
                            <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-search-limit-step" title="<?= h(t('Increase max menu search results')) ?>" aria-label="<?= h(t('Increase max menu search results')) ?>" data-dashboard-favorites-search-max-results-step="1" <?= $toolbarFavoritesEnabled ? '' : 'disabled' ?>>
                                <i class="fas fa-chevron-up" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-search-limit-step" title="<?= h(t('Decrease max menu search results')) ?>" aria-label="<?= h(t('Decrease max menu search results')) ?>" data-dashboard-favorites-search-max-results-step="-1" <?= $toolbarFavoritesEnabled ? '' : 'disabled' ?>>
                                <i class="fas fa-chevron-down" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm dashboard-favorites-manager-search-limit-save" form="dashboard-favorites-manager-toolbar-settings" <?= $toolbarFavoritesEnabled ? '' : 'disabled' ?>>
                        <?= t('Save') ?>
                    </button>
                </div>
                <?php if ($canUseToolbarClearCache) { ?>
                <div class="form-check form-switch dashboard-favorites-manager-dependent-switch<?= $toolbarFavoritesEnabled ? '' : ' is-disabled' ?>">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-clear-cache-enabled" name="toolbar_clear_cache_enabled" value="1" aria-label="<?= h(t('Show "Clear cache now!" action')) ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?= $toolbarFavoritesEnabled && $toolbarClearCacheEnabled ? 'checked' : '' ?> <?= $toolbarFavoritesEnabled ? '' : 'disabled' ?>>
                    <span class="form-check-label">
                        <?= t('Show "Clear cache now!" action') ?>
                    </span>
                </div>
                <?php } ?>
                <div class="form-check form-switch dashboard-favorites-manager-dependent-switch<?= $toolbarFavoritesEnabled ? '' : ' is-disabled' ?>">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-logout-enabled" name="toolbar_logout_enabled" value="1" aria-label="<?= h(t('Show "Log out" action')) ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?= $toolbarFavoritesEnabled && $toolbarLogoutEnabled ? 'checked' : '' ?> <?= $toolbarFavoritesEnabled ? '' : 'disabled' ?>>
                    <span class="form-check-label">
                        <?= t('Show "Log out" action') ?>
                    </span>
                </div>
                <div class="dashboard-favorites-manager-search-note">
                    <?= t('* If the maximum number of menu search results is set too high, the menu may become slower on low-cost or overloaded hosting.') ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($importReport) && isset($importReport['rows']) && is_array($importReport['rows'])) { ?>
        <div class="card dashboard-favorites-manager-import-report mb-4" data-dashboard-favorites-import-report>
            <div class="card-header dashboard-favorites-manager-import-report-heading">
                <span><?= t('Import results') ?></span>
                <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-import-report-clear" data-dashboard-favorites-import-report-clear>
                    <?= t('Clear') ?>
                </button>
            </div>
            <div class="dashboard-favorites-manager-import-report-summary">
                <?php
                $importedCount = (int) ($importReport['imported'] ?? 0);
                $existingCount = (int) ($importReport['skippedExisting'] ?? 0);
                $unavailableCount = (int) ($importReport['skippedInvalid'] ?? 0);
                ?>
                <span class="<?= $importedCount > 0 ? 'is-imported has-events' : '' ?>"><?= t('Imported: %s', $importedCount) ?></span>
                <span class="<?= $existingCount > 0 ? 'is-existing has-events' : '' ?>"><?= t('Skipped, already existing: %s', $existingCount) ?></span>
                <span class="<?= $unavailableCount > 0 ? 'is-unavailable has-events' : '' ?>"><?= t('Skipped, unavailable: %s', $unavailableCount) ?></span>
            </div>
            <?php
            $importMessage = trim((string) ($importReport['message'] ?? ''));
            ?>
            <?php if ($importMessage !== '') { ?>
                <div class="alert alert-info mb-0 dashboard-favorites-manager-import-report-message">
                    <?= h($importMessage) ?>
                </div>
            <?php } ?>
            <?php if (!empty($importReport['rows'])) { ?>
                <table class="table table-sm table-striped mb-0 dashboard-favorites-manager-import-report-table">
                    <thead>
                        <tr>
                            <th><?= t('Name') ?></th>
                            <th><?= t('Status') ?></th>
                            <th><?= t('Path') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($importReport['rows'] as $row) {
                            $status = (string) ($row['status'] ?? '');
                            $statusClass = in_array($status, ['imported', 'existing', 'unavailable'], true) ? $status : 'unavailable';
                            $statusText = (string) ($row['message'] ?? '');
                            if ($status === 'imported') {
                                $statusText = t('Imported');
                            }
                            if ($status === 'existing') {
                                $statusText = t('Skipped, already existing');
                            }
                            if ($status === 'unavailable') {
                                $statusText = t('Skipped, unavailable');
                            }
                            ?>
                            <tr>
                                <td><?= h((string) ($row['name'] ?? '')) ?></td>
                                <td>
                                    <span class="dashboard-favorites-manager-import-status dashboard-favorites-manager-import-status-<?= h($statusClass) ?>" title="<?= h((string) ($row['message'] ?? '')) ?>">
                                        <?= h($statusText) ?>
                                    </span>
                                </td>
                                <td><?= h((string) ($row['path'] ?? '')) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="card dashboard-favorites-manager-favorites-management">
        <div class="card-header dashboard-favorites-manager-favorites-management-heading">
            <?= t('Favorites Management') ?>
        </div>
        <div class="card-body dashboard-favorites-manager-favorites-management-body"
            data-dashboard-favorites-table-container
            data-dashboard-favorites-reorder-url="<?= h($view->action('reorder_favorites')) ?>"
            data-dashboard-favorites-reorder-token="<?= h($reorderFavoritesToken) ?>"
            data-dashboard-favorites-initial="<?= h($initialFavoriteLinksJson) ?>">
            <div class="dashboard-favorites-manager-page-search">
                <div class="dashboard-favorites-manager-page-search-heading">
                    <?= t('Use ★ to add or remove favorites, and → to open the page.') ?>
                </div>
                <div class="dashboard-favorites-manager-page-search-control">
                    <input type="text" class="form-control form-control-sm" id="dashboard-favorites-manager-page-search" placeholder="<?= h(t('Search dashboard pages by name or path')) ?>" autocomplete="off">
                    <button type="button" class="dashboard-favorites-manager-page-search-clear" title="<?= h(t('Clear search')) ?>" aria-label="<?= h(t('Clear search')) ?>" data-dashboard-page-search-clear hidden>
                        &times;
                    </button>
                </div>
                <ul class="dashboard-favorites-manager-page-results" data-dashboard-page-results>
                    <li class="dashboard-favorites-manager-page-search-order-row" data-dashboard-page-search-order-row hidden>
                        <div class="dashboard-favorites-manager-page-search-order" role="radiogroup" aria-label="<?= h(t('Order by')) ?>">
                            <span class="dashboard-favorites-manager-page-search-order-label"><?= t('order by: ') ?></span>
                            <label class="dashboard-favorites-manager-page-search-order-option">
                                <input type="radio" name="dashboard_favorites_manager_page_search_order" value="name" checked data-dashboard-page-search-order>
                                <span><?= t('name') ?></span>
                            </label>
                            <label class="dashboard-favorites-manager-page-search-order-option">
                                <input type="radio" name="dashboard_favorites_manager_page_search_order" value="path" data-dashboard-page-search-order>
                                <span><?= t('path') ?></span>
                            </label>
                        </div>
                        <span class="dashboard-favorites-manager-page-search-result-count" data-dashboard-page-search-result-count></span>
                    </li>
                </ul>
                <div class="dashboard-favorites-manager-page-search-empty text-muted small" data-dashboard-page-search-empty></div>
            </div>
        </div>
    </div>
</div>
