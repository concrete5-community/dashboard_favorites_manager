<?php defined('C5_EXECUTE') or die('Access Denied.');

/** @var array $favoriteLinks */
/** @var array $dashboardPageTree */
/** @var string $packageVersion */
/** @var bool $toolbarFavoritesEnabled */
/** @var bool $toolbarSearchEnabled */
/** @var bool $toolbarClearCacheEnabled */
/** @var bool $toolbarLogoutEnabled */
/** @var bool $toolbarConcreteVersionEnabled */
/** @var bool $canUseToolbarClearCache */
/** @var string $toolbarSettingsToken */
/** @var string $toggleDashboardPageToken */
/** @var string $removeFavoritesToken */
/** @var string $reorderFavoritesToken */
/** @var string $importExportToken */
/** @var array|null $importReport */
/** @var array|null $pendingPackageUpdate */
?>

<div class="dashboard-favorites-manager"
    data-dashboard-favorites-order-error="<?php echo h(t('Unable to save favorite order.')); ?>"
    data-dashboard-page-search-empty-text="<?php echo h(t('No dashboard pages found.')); ?>"
    data-dashboard-favorites-file-large-error="<?php echo h(t('The selected file is too large.')); ?>"
>
    <div class="text-muted small dashboard-favorites-manager-version">
        <?php if (empty($pendingPackageUpdate)) { ?>
            <?php echo t('Version'); ?> <strong><?php echo h($packageVersion); ?></strong> -
        <?php } ?>
        <?php echo t('Author:'); ?> <strong><?php echo h('DigitMaster'); ?></strong> -
        <?php echo h(t('Dedicated to mlocati, my Concrete CMS mentor')); ?>
    </div>
    <?php if (!empty($pendingPackageUpdate)) { ?>
        <div class="alert alert-warning dashboard-favorites-manager-pending-update">
            <?php echo t(
                'Warning: execute package update! The uploaded files are v. %2$s, upgrade is pending. Previous version registered is v. %1$s',
                h((string) ($pendingPackageUpdate['installedVersion'] ?? '')),
                h((string) ($pendingPackageUpdate['availableVersion'] ?? ''))
            ); ?>
            <?php if (!empty($pendingPackageUpdate['canInstallPackages']) && !empty($pendingPackageUpdate['updateUrl'])) { ?>
                <a href="<?php echo h((string) $pendingPackageUpdate['updateUrl']); ?>">
                    <?php echo t('Complete the package update from Concrete Dashboard.'); ?>
                </a>
            <?php } else { ?>
                <?php echo t('Ask an administrator to complete the package update from Concrete Dashboard.'); ?>
            <?php } ?>
        </div>
    <?php } ?>
    <div class="dashboard-favorites-manager-current-user-notice">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <strong><?php echo t('Info: Favorites and settings on this page affect only the current user.'); ?></strong>
    </div>

    <form method="post" action="<?php echo h($view->action('remove_favorites')); ?>" id="dashboard-favorites-manager-form">
        <input type="hidden" name="ccm_token" value="<?php echo h($removeFavoritesToken); ?>">
    </form>

    <form method="post" action="<?php echo h($view->action('save_toolbar_settings')); ?>" id="dashboard-favorites-manager-toolbar-settings" class="dashboard-favorites-manager-toolbar-settings-form">
        <input type="hidden" name="ccm_token" value="<?php echo h($toolbarSettingsToken); ?>">
        <input type="hidden" name="toolbar_favorites_enabled" value="0">
        <input type="hidden" name="toolbar_search_enabled" value="0">
        <input type="hidden" name="toolbar_clear_cache_enabled" value="0">
        <input type="hidden" name="toolbar_logout_enabled" value="0">
        <input type="hidden" name="toolbar_concrete_version_enabled" value="0">
    </form>

    <div class="form-check form-switch dashboard-favorites-manager-options-panel-control">
        <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-options-panel-toggle" data-dashboard-favorites-options-panel-toggle aria-controls="dashboard-favorites-manager-options-panel" aria-expanded="true" checked>
        <label class="form-check-label" for="dashboard-favorites-manager-options-panel-toggle">
            <strong><?php echo t('Show options panel'); ?></strong>
        </label>
    </div>

    <div class="dashboard-favorites-manager-tools mb-3" id="dashboard-favorites-manager-options-panel" data-dashboard-favorites-options-panel>
        <div class="dashboard-favorites-manager-toolbar-toggle">
            <div class="dashboard-favorites-manager-toolbar-options">
                <div class="dashboard-favorites-manager-options-heading">
                    <?php echo t('Toolbar options'); ?>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-toolbar-enabled" name="toolbar_favorites_enabled" value="1" aria-label="<?php echo h(t('Show blue star button in toolbar')); ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?php echo $toolbarFavoritesEnabled ? 'checked' : ''; ?>>
                    <span class="form-check-label">
                        <?php echo t('Show blue star %s button in toolbar', '<span class="dashboard-favorites-manager-label-star">★</span>'); ?>
                    </span>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-concrete-version-enabled" name="toolbar_concrete_version_enabled" value="1" aria-label="<?php echo h(t('Show Concrete CMS version in toolbar')); ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?php echo $toolbarConcreteVersionEnabled ? 'checked' : ''; ?>>
                    <span class="form-check-label">
                        <?php echo t('Show Concrete CMS version in toolbar'); ?>
                    </span>
                </div>
            </div>
            <div class="dashboard-favorites-manager-import-export-actions">
                <form method="post" action="<?php echo h($view->action('export_favorites')); ?>" class="dashboard-favorites-manager-import-export-row">
                    <input type="hidden" name="ccm_token" value="<?php echo h($importExportToken); ?>">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload" aria-hidden="true"></i>
                        <?php echo t('Export favorites'); ?>
                    </button>
                </form>
                <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-import-button" data-dashboard-favorites-import-open>
                    <i class="fas fa-download" aria-hidden="true"></i>
                    <?php echo t('Import favorites'); ?>
                </button>
            </div>
            <form method="post" action="<?php echo h($view->action('import_favorites')); ?>" enctype="multipart/form-data" class="dashboard-favorites-manager-import-form dashboard-favorites-manager-import-controls-form">
                <input type="hidden" name="ccm_token" value="<?php echo h($importExportToken); ?>">
                <div class="dashboard-favorites-manager-import-controls" data-dashboard-favorites-import-controls hidden>
                    <input type="file" name="favorites_file" class="dashboard-favorites-manager-file-input" accept="application/json,.json" required data-dashboard-favorites-import-file data-dashboard-favorites-import-max-size="65536" data-dashboard-favorites-import-size-error="<?php echo h(t('The selected file is too large. Maximum size is 64 KB.')); ?>">
                    <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-file-button" data-dashboard-favorites-file-button>
                        <?php echo t('Select file'); ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm dashboard-favorites-manager-upload-button" data-dashboard-favorites-upload hidden>
                        <?php echo t('Upload'); ?>
                    </button>
                    <span class="dashboard-favorites-manager-file-name" data-dashboard-favorites-file-name data-dashboard-favorites-no-file-text="<?php echo h(t('No file selected')); ?>">
                        <?php echo t('No file selected'); ?>
                    </span>
                    <button type="button" class="dashboard-favorites-manager-import-cancel" title="<?php echo h(t('Cancel import')); ?>" aria-label="<?php echo h(t('Cancel import')); ?>" data-dashboard-favorites-import-cancel>
                        &times;
                    </button>
                </div>
            </form>
        </div>

        <div class="dashboard-favorites-manager-import-export">
            <div class="dashboard-favorites-manager-menu-options<?php echo $toolbarFavoritesEnabled ? '' : ' is-disabled'; ?>">
                <div class="dashboard-favorites-manager-options-heading">
                    <?php echo t('Menu options'); ?>
                </div>
                <div class="form-check form-switch dashboard-favorites-manager-dependent-switch<?php echo $toolbarFavoritesEnabled ? '' : ' is-disabled'; ?>">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-search-enabled" name="toolbar_search_enabled" value="1" aria-label="<?php echo h(t('Show dashboard page search - may be slow on low-cost or overloaded hosting')); ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?php echo $toolbarFavoritesEnabled && $toolbarSearchEnabled ? 'checked' : ''; ?> <?php echo $toolbarFavoritesEnabled ? '' : 'disabled'; ?>>
                    <span class="form-check-label">
                        <?php echo t('Show dashboard page search - may be slow on low-cost or overloaded hosting'); ?>
                    </span>
                </div>
                <?php if ($canUseToolbarClearCache) { ?>
                <div class="form-check form-switch dashboard-favorites-manager-dependent-switch<?php echo $toolbarFavoritesEnabled ? '' : ' is-disabled'; ?>">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-clear-cache-enabled" name="toolbar_clear_cache_enabled" value="1" aria-label="<?php echo h(t('Show "Clear cache now!" action')); ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?php echo $toolbarFavoritesEnabled && $toolbarClearCacheEnabled ? 'checked' : ''; ?> <?php echo $toolbarFavoritesEnabled ? '' : 'disabled'; ?>>
                    <span class="form-check-label">
                        <?php echo t('Show "Clear cache now!" action'); ?>
                    </span>
                </div>
                <?php } ?>
                <div class="form-check form-switch dashboard-favorites-manager-dependent-switch<?php echo $toolbarFavoritesEnabled ? '' : ' is-disabled'; ?>">
                    <input type="checkbox" class="form-check-input" id="dashboard-favorites-manager-logout-enabled" name="toolbar_logout_enabled" value="1" aria-label="<?php echo h(t('Show "Log out" action')); ?>" form="dashboard-favorites-manager-toolbar-settings" onchange="this.form.submit()" <?php echo $toolbarFavoritesEnabled && $toolbarLogoutEnabled ? 'checked' : ''; ?> <?php echo $toolbarFavoritesEnabled ? '' : 'disabled'; ?>>
                    <span class="form-check-label">
                        <?php echo t('Show "Log out" action'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($importReport) && isset($importReport['rows']) && is_array($importReport['rows'])) { ?>
        <div class="dashboard-favorites-manager-import-report mb-4" data-dashboard-favorites-import-report>
            <div class="dashboard-favorites-manager-import-report-heading">
                <span><?php echo t('Import results'); ?></span>
                <button type="button" class="btn btn-primary btn-sm dashboard-favorites-manager-import-report-clear" data-dashboard-favorites-import-report-clear>
                    <?php echo t('Clear'); ?>
                </button>
            </div>
            <div class="dashboard-favorites-manager-import-report-summary">
                <?php
                $importedCount = (int) ($importReport['imported'] ?? 0);
                $existingCount = (int) ($importReport['skippedExisting'] ?? 0);
                $unavailableCount = (int) ($importReport['skippedInvalid'] ?? 0);
                ?>
                <span class="<?php echo $importedCount > 0 ? 'is-imported has-events' : ''; ?>"><?php echo t('Imported: %s', $importedCount); ?></span>
                <span class="<?php echo $existingCount > 0 ? 'is-existing has-events' : ''; ?>"><?php echo t('Skipped, already existing: %s', $existingCount); ?></span>
                <span class="<?php echo $unavailableCount > 0 ? 'is-unavailable has-events' : ''; ?>"><?php echo t('Skipped, unavailable: %s', $unavailableCount); ?></span>
            </div>
            <?php
            $importMessage = trim((string) ($importReport['message'] ?? ''));
            ?>
            <?php if ($importMessage !== '') { ?>
                <div class="alert alert-info mb-0 dashboard-favorites-manager-import-report-message">
                    <?php echo h($importMessage); ?>
                </div>
            <?php } ?>
            <?php if (!empty($importReport['rows'])) { ?>
                <table class="table table-sm table-striped mb-0 dashboard-favorites-manager-import-report-table">
                    <thead>
                        <tr>
                            <th><?php echo t('Name'); ?></th>
                            <th><?php echo t('Status'); ?></th>
                            <th><?php echo t('Path'); ?></th>
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
                                <td><?php echo h((string) ($row['name'] ?? '')); ?></td>
                                <td>
                                    <span class="dashboard-favorites-manager-import-status dashboard-favorites-manager-import-status-<?php echo h($statusClass); ?>" title="<?php echo h((string) ($row['message'] ?? '')); ?>">
                                        <?php echo h($statusText); ?>
                                    </span>
                                </td>
                                <td><?php echo h((string) ($row['path'] ?? '')); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="dashboard-favorites-manager-favorites-management">
        <div class="dashboard-favorites-manager-favorites-management-heading">
            <?php echo t('Favorites Management'); ?>
        </div>
        <div class="dashboard-favorites-manager-favorites-management-body">
            <div class="dashboard-favorites-manager-page-search">
                <div class="dashboard-favorites-manager-page-search-heading">
                    <?php echo t('Search dashboard pages. Use the star ★ to add or remove favorites, and the arrow → to open the page.'); ?>
                </div>
                <?php if (empty($dashboardPageTree)) { ?>
                    <div class="alert alert-info mb-0">
                        <?php echo t('No dashboard pages found.'); ?>
                    </div>
                <?php } else { ?>
                    <div class="dashboard-favorites-manager-page-search-control">
                        <input type="text" class="form-control form-control-sm" id="dashboard-favorites-manager-page-search" placeholder="<?php echo h(t('Search dashboard pages')); ?>" autocomplete="off">
                        <button type="button" class="dashboard-favorites-manager-page-search-clear" title="<?php echo h(t('Clear search')); ?>" aria-label="<?php echo h(t('Clear search')); ?>" data-dashboard-page-search-clear hidden>
                            &times;
                        </button>
                    </div>
                    <ul class="dashboard-favorites-manager-page-results" data-dashboard-page-results>
                        <?php foreach ($dashboardPageTree as $page) {
                            $isFavorite = !empty($page['isFavorite']);
                            $searchText = strtolower($page['name']);
                            ?>
                            <li class="dashboard-favorites-manager-page-result<?php echo $isFavorite ? ' is-favorite' : ''; ?>" data-dashboard-page-id="<?php echo (int) $page['id']; ?>" data-dashboard-page-search-text="<?php echo h($searchText); ?>">
                                <form method="post" action="<?php echo h($view->action('toggle_dashboard_page')); ?>" class="dashboard-favorites-manager-toggle-form">
                                    <input type="hidden" name="ccm_token" value="<?php echo h($toggleDashboardPageToken); ?>">
                                    <input type="hidden" name="page_id" value="<?php echo (int) $page['id']; ?>">
                                    <input type="hidden" name="favorite" value="<?php echo $isFavorite ? '0' : '1'; ?>" data-dashboard-page-toggle-value>
                                    <button type="submit" class="btn btn-link dashboard-favorites-manager-star" data-dashboard-page-toggle aria-pressed="<?php echo $isFavorite ? 'true' : 'false'; ?>" title="<?php echo h($isFavorite ? t('Remove from favorites') : t('Add to favorites')); ?>">
                                        <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-star" aria-hidden="true"></i>
                                        <span class="visually-hidden"><?php echo $isFavorite ? t('Remove from favorites') : t('Add to favorites'); ?></span>
                                    </button>
                                </form>
                                <div class="dashboard-favorites-manager-page-result-main">
                                    <div class="dashboard-favorites-manager-page-result-name"><?php echo h($page['name']); ?></div>
                                    <div class="dashboard-favorites-manager-page-result-path"><?php echo h($page['path']); ?></div>
                                </div>
                                <a href="<?php echo h($page['url']); ?>" class="dashboard-favorites-manager-page-result-link" title="<?php echo h(t('Open page')); ?>" aria-label="<?php echo h(t('Open page')); ?>">
                                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </li>
                        <?php } ?>
                    </ul>
                    <div class="dashboard-favorites-manager-page-search-empty text-muted small" data-dashboard-page-search-empty></div>
                <?php } ?>
            </div>

            <?php if (empty($favoriteLinks)) { ?>
                <div class="alert alert-info dashboard-favorites-manager-empty-favorites">
                    <?php echo t('The favorites list is empty.'); ?>
                </div>
            <?php } else { ?>
                <div class="dashboard-favorites-manager-table-actions">
                    <button type="button" class="btn btn-danger btn-sm" data-dashboard-favorites-remove disabled aria-disabled="true">
                        <?php echo t('Remove selected'); ?>
                    </button>
                    <span class="dashboard-favorites-manager-remove-confirm" data-dashboard-favorites-remove-confirm data-dashboard-favorites-remove-confirm-one="<?php echo h(t('Confirm remove %s favorite?')); ?>" data-dashboard-favorites-remove-confirm-many="<?php echo h(t('Confirm remove %s favorites?')); ?>" hidden>
                        <span data-dashboard-favorites-remove-confirm-text><?php echo t('Confirm remove?'); ?></span>
                        <button type="submit" class="btn btn-danger btn-sm" form="dashboard-favorites-manager-form" data-dashboard-favorites-remove-confirm-yes>
                            <?php echo t('Yes'); ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm dashboard-favorites-manager-remove-cancel" data-dashboard-favorites-remove-confirm-no>
                            <?php echo t('No'); ?>
                        </button>
                    </span>
                </div>
                <table class="table table-sm table-striped table-hover dashboard-favorites-manager-table">
                    <colgroup>
                        <col class="dashboard-favorites-manager-select-column">
                        <col class="dashboard-favorites-manager-position-column">
                        <col class="dashboard-favorites-manager-sort-column">
                        <col class="dashboard-favorites-manager-name-column">
                        <col class="dashboard-favorites-manager-path-column">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="dashboard-favorites-manager-select-all">
                            </th>
                            <th class="dashboard-favorites-manager-position-cell"><?php echo t('#'); ?></th>
                            <th></th>
                            <th class="dashboard-favorites-manager-name-cell"><?php echo t('Name'); ?></th>
                            <th><?php echo t('Path'); ?></th>
                        </tr>
                    </thead>
                    <tbody data-dashboard-favorites-sort-url="<?php echo h($view->action('reorder_favorites')); ?>" data-dashboard-favorites-sort-token="<?php echo h($reorderFavoritesToken); ?>">
                        <?php foreach ($favoriteLinks as $position => $favorite) { ?>
                            <tr data-favorite-key="<?php echo h($favorite['selectionKey']); ?>">
                                <td>
                                    <input type="checkbox" name="selected_favorites[]" value="<?php echo h($favorite['selectionKey']); ?>" class="dashboard-favorites-manager-checkbox" form="dashboard-favorites-manager-form">
                                </td>
                                <td class="dashboard-favorites-manager-position-cell" data-dashboard-favorites-position><?php echo $position + 1; ?></td>
                                <td class="dashboard-favorites-manager-sort-cell">
                                    <i class="fas fa-arrows-alt-v dashboard-favorites-manager-sort-handle" aria-hidden="true"></i>
                                    <span class="dashboard-favorites-manager-move-buttons">
                                        <button type="button" class="dashboard-favorites-manager-move-button" data-dashboard-favorites-move="up" title="<?php echo h(t('Move up')); ?>" aria-label="<?php echo h(t('Move up')); ?>">
                                            <i class="fas fa-chevron-up" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="dashboard-favorites-manager-move-button" data-dashboard-favorites-move="down" title="<?php echo h(t('Move down')); ?>" aria-label="<?php echo h(t('Move down')); ?>">
                                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    </span>
                                </td>
                                <td class="dashboard-favorites-manager-name-cell"><?php echo h($favorite['name']); ?></td>
                                <td>
                                    <?php
                                    $favoritePath = (string) ($favorite['path'] ?? '');
                                    $favoriteUrl = (string) ($favorite['url'] ?? '');
                                    ?>
                                    <?php if ($favoriteUrl !== '') { ?>
                                        <a href="<?php echo h($favoriteUrl); ?>" class="dashboard-favorites-manager-path-link">
                                            <?php echo h($favoritePath !== '' ? $favoritePath : $favoriteUrl); ?>
                                        </a>
                                    <?php } else { ?>
                                        <?php echo h($favoritePath); ?>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    </div>
</div>
